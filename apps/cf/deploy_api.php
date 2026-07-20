<?php
/**
 * Модуль «Деплой из ZIP» — AJAX-эндпоинт.
 *
 * Фаза 1: приём и валидация архива (action = validate). Здесь только чтение —
 * ни одного вызова на запись в Cloudflare. Деплой/привязка домена/правки —
 * следующие фазы.
 */

// ВАЖНО: подавляем вывод PHP-ошибок В ТЕЛО ответа ДО подключения файлов —
// иначе любое предупреждение/deprecation из include-цепочки утечёт как HTML
// и фронт получит «Unexpected token '<'». Ошибки по-прежнему идут в error_log.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
// Буфер ловит любой посторонний вывод; перед JSON мы его очищаем.
ob_start();

require_once 'config.php';
require_once 'functions.php';
require_once 'db_retry.php';
require_once 'deploy_lib.php';
require_once 'deploy_worker.php';
require_once 'deploy_edits.php';

header('Content-Type: application/json; charset=utf-8');

// На фатальной ошибке отдаём JSON вместо HTML-страницы ошибки.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        cfDeployRespond(['success' => false, 'error' => 'Внутренняя ошибка: ' . $e['message']]);
    }
});

/** Единая точка вывода JSON: сбрасывает любой посторонний вывод из буфера. */
function cfDeployRespond($data) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($data);
    exit;
}

/**
 * Трекер текущего шага операции — для детального сообщения об ошибке. Вызов с аргументом
 * ставит метку, без аргумента — читает. Так в catch мы знаем, ЧТО именно упало
 * (напр. «database is locked» на шаге «создание записи сайта»).
 */
function cfDeployPhase($label = null) {
    static $current = '';
    if ($label !== null) $current = (string)$label;
    return $current;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    cfDeployRespond(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    cfDeployRespond(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

$userId = $_SESSION['user_id'];
// Для загрузки файла запрос идёт как multipart/form-data → action в $_POST.
// Прочие (будущие) действия могут приходить JSON-ом.
$action = $_POST['action'] ?? null;
if ($action === null) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) {
        $action = $json['action'] ?? '';
    }
}

/**
 * Понятное сообщение по коду ошибки загрузки PHP.
 */
function cfDeployUploadErrorText($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Архив превысил допустимый размер загрузки сервера. '
                 . 'Проверьте upload_max_filesize / post_max_size (модуль поднимает их в .htaccess).';
        case UPLOAD_ERR_PARTIAL:
            return 'Архив загрузился не полностью — повторите загрузку.';
        case UPLOAD_ERR_NO_FILE:
            return 'Файл архива не выбран.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'На сервере нет временной директории для загрузки.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Не удалось записать архив на диск сервера.';
        default:
            return 'Ошибка загрузки архива (код ' . (int)$code . ').';
    }
}

/**
 * Загружает учётные данные CF-аккаунта по его id (cloudflare_credentials.id).
 *
 * Приоритет — scoped API-токен из раздела «Мастер-токен» (cloudflare_api_tokens):
 * у него есть право Workers Scripts:Edit, нужное для Static Assets. Если токена нет —
 * откатываемся на api_key из cloudflare_credentials (Global Key / старый ключ).
 *
 * @return array ['email'=>..,'api_key'=>..,'auth_type'=>'bearer'|null,'source'=>'token'|'legacy']
 */
function cfDeployLoadCredentials($pdo, $userId, $accountId) {
    $stmt = $pdo->prepare("SELECT id, email, api_key, status, COALESCE(auth_type,'global') AS auth_type FROM cloudflare_credentials WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new Exception('Аккаунт Cloudflare не найден.');
    }

    // Предпочитаем scoped-токен мастер-токена (самый свежий) для этого аккаунта.
    $tokens = listCloudflareApiTokens($pdo, $userId, $accountId);
    if (!empty($tokens) && !empty($tokens[0]['token'])) {
        return ['email' => $row['email'], 'api_key' => trim($tokens[0]['token']),
                'auth_type' => 'bearer', 'source' => 'token'];
    }

    // Сам кредентал — это API-токен (создан через «Мастер-токен»): используем его как Bearer.
    // Раньше такие аккаунты попадали в ветку 'legacy' и помечались как «без токена».
    if (($row['auth_type'] ?? '') === 'token' && trim((string)$row['api_key']) !== '') {
        return ['email' => $row['email'], 'api_key' => trim($row['api_key']),
                'auth_type' => 'bearer', 'source' => 'credential-token'];
    }

    return ['email' => $row['email'], 'api_key' => $row['api_key'], 'auth_type' => null, 'source' => 'legacy'];
}

/**
 * Пересобирает и переиздаёт сайт (корень + подпапки + мета) из постоянного исходника.
 * Общая точка для add_subfolder / remove_subfolder / save_page_meta.
 */
function cfDeployRebuildSite($pdo, $userId, $accId, $domain) {
    $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
    if (!$site) throw new Exception('Сайт не найден — сначала опубликуйте его.');
    if (!cfDeployHasSource($site['id'])) {
        throw new Exception('Нет сохранённого исходника сайта. Загрузите ZIP заново, затем повторите правку.');
    }
    $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
    $proxies = getProxies($pdo, $userId);
    $scriptName = $site['worker_name'] ?: cfWorkerScriptName($domain);
    $mode = $site['protection_mode'] ?: 'static-only';

    $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);
    if (!$resolve['account_cf_id']) {
        throw new Exception($resolve['error'] ?? 'Не удалось определить аккаунт Cloudflare.');
    }

    $deploy = cfDeployAssembleAndPublish($pdo, $credentials, $resolve['account_cf_id'],
        $scriptName, $site['id'], $domain, $mode, $proxies, $userId);

    if ($deploy['success']) {
        dbRetryOnLock(function () use ($pdo, $deploy, $site) {
            $pdo->prepare("UPDATE cf_deploy_sites SET workers_dev_url=?, files_count=?,
                last_deploy_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
                ->execute([$deploy['workers_dev_url'] ?? $site['workers_dev_url'], $deploy['files_count'] ?? 0, $site['id']]);
        });
    }
    return $deploy;
}

/** Нормализация и базовая проверка домена. */
function cfDeployNormalizeDomain($domain) {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = rtrim($domain, '/');
    if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
        throw new Exception('Некорректный домен: ' . htmlspecialchars($domain));
    }
    return $domain;
}

try {
    switch ($action) {
        case 'validate':
            $file = $_FILES['archive'] ?? null;
            if (!$file || !isset($file['error'])) {
                // Пустой $_FILES при большом теле обычно значит превышение post_max_size.
                throw new Exception('Файл архива не получен. Возможно, архив больше лимита сервера '
                    . '(post_max_size). Проверьте настройки PHP.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception(cfDeployUploadErrorText($file['error']));
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                throw new Exception('Некорректная загрузка файла.');
            }

            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                throw new Exception('Ожидается ZIP-архив (.zip).');
            }

            $report = cfDeployValidateArchive($file['tmp_name']);

            logAction($pdo, $userId, 'Deploy Validate',
                'file=' . basename($file['name'] ?? '?') .
                ' files=' . $report['total_files'] .
                ' valid=' . ($report['valid'] ? '1' : '0'));

            // Отдаём сводку целиком; список файлов обрезаем для UI (полный не нужен).
            $preview = array_slice($report['files'], 0, 200);
            cfDeployRespond([
                'success'      => (bool)$report['valid'],
                'error'        => $report['error'],
                'report'       => [
                    'root_prefix'  => $report['root_prefix'],
                    'total_files'  => $report['total_files'],
                    'total_size'   => $report['total_size'],
                    'pages_count'  => count($report['pages']),
                    'pages'        => array_slice($report['pages'], 0, 100),
                    'oversized'    => $report['oversized'],
                    'server_files' => $report['server_files'],
                    'has_index'    => $report['has_index'],
                    'has_htaccess' => $report['has_htaccess'],
                    'has_404'      => $report['has_404'],
                    'warnings'     => $report['warnings'],
                    'files_preview'=> $preview,
                    'limit_files'  => $report['limit_files'],
                ],
            ]);
            break;

        case 'check_domain':
            // FR-5: чтение состояния домена в выбранном аккаунте (без записи).
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');

            $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
            $proxies = getProxies($pdo, $userId);
            $scriptName = cfWorkerScriptName($domain);

            $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);
            $state = cfDeployCheckDomain($pdo, $credentials, $resolve['account_cf_id'], $domain, $scriptName, $proxies, $userId);

            cfDeployRespond([
                'success'     => true,
                'domain'      => $domain,
                'worker_name' => $scriptName,
                'account_resolved' => (bool)$resolve['account_cf_id'],
                'state'       => $state,
            ]);
            break;

        case 'deploy':
            // FR-6: публикация сайта на Workers Static Assets (workers.dev). Домен/SSL — фаза 3.
            // Публикация долгая (много сетевых вызовов CF) и конкурирует за запись с фоновыми
            // процессами — даём БД-записям ждать дольше (как в тяжёлых операциях мастер-токена).
            try { $pdo->exec('PRAGMA busy_timeout = 120000'); } catch (Throwable $e) { /* не критично */ }
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $mode   = ($_POST['mode'] ?? 'static-only') === 'worker-first' ? 'worker-first' : 'static-only';
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');

            $file = $_FILES['archive'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception($file ? cfDeployUploadErrorText($file['error']) : 'Архив не получен.');
            }

            // Не доверяем клиенту — валидируем архив заново перед деплоем.
            cfDeployPhase('проверка архива');
            $report = cfDeployValidateArchive($file['tmp_name']);
            if (!$report['valid']) {
                cfDeployRespond(['success' => false, 'error' => $report['error'],
                    'report' => ['oversized' => $report['oversized']]]);
                break;
            }

            // Pre-flight чтения тоже под ретраем: под фоновой нагрузкой даже SELECT может
            // ненадолго упереться в блокировку (чекпойнт WAL).
            cfDeployPhase('загрузка учётных данных аккаунта');
            $credentials = dbRetryOnLock(function () use ($pdo, $userId, $accId) {
                return cfDeployLoadCredentials($pdo, $userId, $accId);
            });
            cfDeployPhase('загрузка списка прокси');
            $proxies = dbRetryOnLock(function () use ($pdo, $userId) { return getProxies($pdo, $userId); });
            $scriptName = cfWorkerScriptName($domain);

            cfDeployPhase('определение аккаунта и зоны Cloudflare');
            $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);
            if (!$resolve['account_cf_id']) {
                throw new Exception($resolve['error'] ?? 'Не удалось определить аккаунт Cloudflare.');
            }
            $accountCfId = $resolve['account_cf_id'];

            // Сайт заводим/находим ДО деплоя (нужен siteId для хранилища исходника).
            // BEGIN IMMEDIATE: берём write-лок сразу, чтобы SELECT→INSERT не ловил
            // SQLITE_BUSY_SNAPSHOT под непрерывными фоновыми записями (частая причина
            // "database is locked" при публикации второго сайта).
            cfDeployPhase('создание записи сайта в БД');
            $siteId = dbImmediateTxn($pdo, function () use ($pdo, $userId, $accId, $domain, $scriptName, $resolve, $mode) {
                $stmt = $pdo->prepare("SELECT id FROM cf_deploy_sites WHERE user_id = ? AND account_id = ? AND domain = ?");
                $stmt->execute([$userId, $accId, $domain]);
                $sid = $stmt->fetchColumn();
                if ($sid) {
                    $pdo->prepare("UPDATE cf_deploy_sites SET worker_name=?, zone_id=?, protection_mode=?,
                        updated_at=datetime('now') WHERE id=?")
                        ->execute([$scriptName, $resolve['zone_id'], $mode, $sid]);
                } else {
                    $pdo->prepare("INSERT INTO cf_deploy_sites
                        (user_id, account_id, domain, worker_name, zone_id, protection_mode, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'draft')")
                        ->execute([$userId, $accId, $domain, $scriptName, $resolve['zone_id'], $mode]);
                    $sid = $pdo->lastInsertId();
                }
                $pdo->prepare("INSERT OR IGNORE INTO cf_deploy_versions (site_id, prefix) VALUES (?, '')")
                    ->execute([$sid]);
                return $sid;
            });

            // Распаковка исходника в постоянное хранилище (для правок без re-upload — FR-10).
            cfDeployPhase('сохранение исходника сайта');
            $extract = cfDeployExtractZip($file['tmp_name'], $report['root_prefix']);
            if (isset($extract['error'])) throw new Exception($extract['error']);
            try {
                cfDeploySaveRootSource($siteId, $extract['dir'], $extract['files']);
            } finally {
                cfDeployRmrf($extract['dir']);
            }

            // Сборка (корень + подпапки + мета) и публикация.
            cfDeployPhase('сборка и публикация на Cloudflare');
            $deploy = cfDeployAssembleAndPublish($pdo, $credentials, $accountCfId, $scriptName,
                $siteId, $domain, $mode, $proxies, $userId);

            if ($deploy['success']) {
                cfDeployPhase('сохранение статуса сайта');
                dbRetryOnLock(function () use ($pdo, $deploy, $siteId) {
                    $pdo->prepare("UPDATE cf_deploy_sites SET workers_dev_url=?, status='deployed',
                        files_count=?, last_deploy_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
                        ->execute([$deploy['workers_dev_url'] ?? null, $deploy['files_count'] ?? 0, $siteId]);
                });
                logActionSafe($pdo, $userId, 'Deploy Success',
                    "domain=$domain worker=$scriptName mode=$mode url=" . ($deploy['workers_dev_url'] ?? '?'));
            } else {
                logAction($pdo, $userId, 'Deploy Failed', "domain=$domain worker=$scriptName err=" . ($deploy['error'] ?? '?'));
            }

            cfDeployRespond([
                'success'         => (bool)$deploy['success'],
                'error'           => $deploy['error'] ?? null,
                'worker_name'     => $scriptName,
                'workers_dev_url' => $deploy['workers_dev_url'] ?? null,
                'zone_in_account' => $resolve['zone_in_account'],
                'steps'           => $deploy['steps'] ?? [],
            ]);
            break;

        case 'account_zones':
            // Список зон (доменов) выбранного аккаунта — для проверки наличия домена
            // и выпадающего списка (FR-4/FR-5). Ручной ввод остаётся возможен на фронте.
            $accId = (int)($_POST['account_id'] ?? 0);
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');
            $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
            $proxies = getProxies($pdo, $userId);

            $zones = [];
            for ($page = 1; $page <= 3; $page++) {
                $resp = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
                    "zones?per_page=50&page=$page", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
                if (empty($resp['success']) || empty($resp['data'])) {
                    if ($page === 1 && !empty($resp['api_errors'])) {
                        throw new Exception('Cloudflare: ' . ($resp['api_errors'][0]['message'] ?? 'нет доступа к зонам')
                            . ' (проверьте права токена: Zone:Read).');
                    }
                    break;
                }
                foreach ($resp['data'] as $z) {
                    $name = is_object($z) ? ($z->name ?? null) : ($z['name'] ?? null);
                    if ($name) $zones[] = $name;
                }
                if (count($resp['data']) < 50) break;
            }
            sort($zones);
            cfDeployRespond(['success' => true, 'zones' => array_values(array_unique($zones)),
                'auth_source' => $credentials['source'] ?? 'legacy']);
            break;

        case 'list_sites':
            // FR-9: список ОПУБЛИКОВАННЫХ сайтов. Строка сайта создаётся ('draft') ДО
            // фактической публикации (нужен siteId для хранилища исходника), поэтому при
            // неудачном деплое остаётся «черновик». Показываем только реально задеплоенные
            // (status='deployed' или уже есть workers_dev_url / привязка) — иначе в списке
            // висит домен без воркера, и «Привязать» по нему падает.
            $stmt = $pdo->prepare("SELECT s.*, c.email AS account_email
                FROM cf_deploy_sites s
                JOIN cloudflare_credentials c ON c.id = s.account_id
                WHERE s.user_id = ?
                  AND (s.status = 'deployed' OR s.workers_dev_url IS NOT NULL OR s.custom_domain_bound = 1)
                ORDER BY s.updated_at DESC");
            $stmt->execute([$userId]);
            cfDeployRespond(['success' => true, 'sites' => $stmt->fetchAll()]);
            break;

        case 'bind_domain':
            // FR-7: привязка Custom Domain + SSL. ТОЛЬКО по явному подтверждению (confirm=1).
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            if (empty($_POST['confirm'])) throw new Exception('Требуется подтверждение привязки домена.');
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');

            $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
            $proxies = getProxies($pdo, $userId);
            $scriptName = cfWorkerScriptName($domain);

            // Сайт должен быть уже задеплоен (воркер существует).
            $stmt = $pdo->prepare("SELECT id FROM cf_deploy_sites WHERE user_id=? AND account_id=? AND domain=?");
            $stmt->execute([$userId, $accId, $domain]);
            $siteId = $stmt->fetchColumn();
            if (!$siteId) throw new Exception('Сайт не найден — сначала опубликуйте его.');

            $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);
            if (!$resolve['zone_in_account'] || !$resolve['zone_id']) {
                throw new Exception('Зоны домена нет в этом аккаунте — привязка невозможна (§8). '
                    . 'Перенесите зону в аккаунт или деплойте в аккаунт-владелец зоны.');
            }

            // Второе явное подтверждение на замену DNS-записей апекса (confirm_dns_replace=1).
            $allowDnsReplace = !empty($_POST['confirm_dns_replace']);
            $bind = cfDeployBindDomain($pdo, $credentials, $resolve['account_cf_id'],
                $resolve['zone_id'], $scriptName, $domain, $proxies, $userId, $allowDnsReplace);

            // Нужно второе подтверждение: на апексе есть A/AAAA/CNAME «прежнего сервера».
            // Ничего не меняли — возвращаем список записей, фронт спросит и повторит запрос.
            if (!empty($bind['needs_dns_confirm'])) {
                cfDeployRespond([
                    'success'           => false,
                    'needs_dns_confirm' => true,
                    'domain'            => $domain,
                    'other_worker'      => $bind['other_worker'] ?? null,
                    'conflict_records'  => $bind['conflict_records'] ?? [],
                ]);
                break;
            }

            if (!$bind['success']) {
                logAction($pdo, $userId, 'Deploy Bind Failed', "domain=$domain err=" . $bind['error']);
                throw new Exception($bind['error']);
            }

            $status = cfDeployBindingStatus($pdo, $credentials, $resolve['account_cf_id'],
                $resolve['zone_id'], $scriptName, $domain, $proxies, $userId);

            // Сохраняем бэкап снятых DNS-записей (если были) — для отката при отвязке.
            // Не затираем прежний бэкап, если в этот раз ничего не снимали.
            $dnsBackup = $bind['dns_backup'] ?? [];
            dbRetryOnLock(function () use ($pdo, $status, $resolve, $dnsBackup, $siteId) {
                if (!empty($dnsBackup)) {
                    $pdo->prepare("UPDATE cf_deploy_sites SET custom_domain_bound=1, ssl_status=?, zone_id=?,
                        dns_backup=?, updated_at=datetime('now') WHERE id=?")
                        ->execute([$status['ssl_status'], $resolve['zone_id'], json_encode($dnsBackup), $siteId]);
                } else {
                    $pdo->prepare("UPDATE cf_deploy_sites SET custom_domain_bound=1, ssl_status=?, zone_id=?,
                        updated_at=datetime('now') WHERE id=?")
                        ->execute([$status['ssl_status'], $resolve['zone_id'], $siteId]);
                }
            });

            // Мост: домен ушёл на воркер CF — синхронизируем общее состояние (домены/дашборд),
            // чтобы они не показывали IP «прежнего сервера».
            cfDeployBridgeSyncDomain($pdo, $userId, $domain, $credentials, $resolve['zone_id'], $proxies, true);

            $notes = $bind['notes'] ?? [];
            logAction($pdo, $userId, 'Deploy Bind Success', "domain=$domain worker=$scriptName ssl="
                . $status['ssl_status'] . ($notes ? ' notes=' . implode('; ', $notes) : ''));
            cfDeployRespond(['success' => true, 'domain' => $domain,
                'site_url' => 'https://' . $domain, 'ssl_status' => $status['ssl_status'],
                'notes' => $notes]);
            break;

        case 'unbind_domain':
            // Отвязка Custom Domain (сайт остаётся на *.workers.dev).
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');

            $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
            $proxies = getProxies($pdo, $userId);
            $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);

            $un = cfDeployUnbindDomain($pdo, $credentials, $resolve['account_cf_id'], $domain, $proxies, $userId);
            if (!$un['success']) throw new Exception($un['error']);

            // Откат: если при привязке мы снимали DNS-записи апекса — восстанавливаем их,
            // чтобы домен вернулся на прежний сервер, а не «завис» без записи.
            $restored = 0;
            $stmt = $pdo->prepare("SELECT dns_backup FROM cf_deploy_sites WHERE user_id=? AND account_id=? AND domain=?");
            $stmt->execute([$userId, $accId, $domain]);
            $backupJson = $stmt->fetchColumn();
            if ($backupJson) {
                $backup = json_decode($backupJson, true);
                if (is_array($backup) && $backup && !empty($resolve['zone_id'])) {
                    $restored = cfDeployRecreateDnsRecords($pdo, $credentials, $resolve['zone_id'], $backup, $proxies, $userId);
                }
            }

            dbRetryOnLock(function () use ($pdo, $userId, $accId, $domain) {
                $pdo->prepare("UPDATE cf_deploy_sites SET custom_domain_bound=0, ssl_status=NULL, dns_backup=NULL,
                    updated_at=datetime('now') WHERE user_id=? AND account_id=? AND domain=?")
                    ->execute([$userId, $accId, $domain]);
            });

            // Мост: домен вернулся с воркера — перечитываем апекс и синхронизируем общее
            // состояние (домены/дашборд), чтобы показать восстановленный IP, а не маркер.
            cfDeployBridgeSyncDomain($pdo, $userId, $domain, $credentials, $resolve['zone_id'], $proxies, false);

            logAction($pdo, $userId, 'Deploy Unbind', "domain=$domain restored_dns=$restored");
            cfDeployRespond(['success' => true, 'domain' => $domain, 'restored_dns' => $restored]);
            break;

        case 'binding_status':
            // Перечитать статус привязки/SSL (кнопка «Проверить SSL»).
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');

            $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
            $proxies = getProxies($pdo, $userId);
            $scriptName = cfWorkerScriptName($domain);
            $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);

            $status = cfDeployBindingStatus($pdo, $credentials, $resolve['account_cf_id'],
                $resolve['zone_id'], $scriptName, $domain, $proxies, $userId);

            if ($status['bound']) {
                dbRetryOnLock(function () use ($pdo, $status, $userId, $accId, $domain) {
                    $pdo->prepare("UPDATE cf_deploy_sites SET ssl_status=?, custom_domain_bound=1,
                        updated_at=datetime('now') WHERE user_id=? AND account_id=? AND domain=?")
                        ->execute([$status['ssl_status'], $userId, $accId, $domain]);
                });
            }
            cfDeployRespond(['success' => true, 'domain' => $domain,
                'bound' => $status['bound'], 'bound_to' => $status['bound_to'], 'ssl_status' => $status['ssl_status']]);
            break;

        case 'list_versions':
            // FR-10: версии сайта (корень + подпапки-копии).
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден.');

            $stmt = $pdo->prepare("SELECT prefix, source_prefix, share_root_assets FROM cf_deploy_versions
                WHERE site_id = ? ORDER BY prefix");
            $stmt->execute([$site['id']]);
            $versions = $stmt->fetchAll();

            cfDeployRespond([
                'success'  => true,
                'domain'   => $domain,
                'has_source' => cfDeployHasSource($site['id']),
                'versions' => $versions,
            ]);
            break;

        case 'add_subfolder':
            // FR-10.1: копия сайта в подпапку (/en/, /es-cl/).
            $accId   = (int)($_POST['account_id'] ?? 0);
            $domain  = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $prefix  = cfDeployNormalizePrefix($_POST['prefix'] ?? '');
            $share   = !empty($_POST['share_assets']) ? 1 : 0;

            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден — сначала опубликуйте его.');
            if ($prefix === '') throw new Exception('Не указан префикс подпапки.');

            dbRetryOnLock(function () use ($pdo, $site, $prefix, $share) {
                $pdo->prepare("INSERT INTO cf_deploy_versions (site_id, prefix, source_prefix, share_root_assets)
                    VALUES (?, ?, '', ?)
                    ON CONFLICT(site_id, prefix) DO UPDATE SET share_root_assets = excluded.share_root_assets,
                        updated_at = datetime('now')")
                    ->execute([$site['id'], $prefix, $share]);
            });

            $deploy = cfDeployRebuildSite($pdo, $userId, $accId, $domain);
            logAction($pdo, $userId, 'Deploy Subfolder', "domain=$domain prefix=$prefix share=$share ok=" . ($deploy['success'] ? 1 : 0));
            cfDeployRespond(['success' => (bool)$deploy['success'], 'error' => $deploy['error'] ?? null,
                'prefix' => $prefix, 'url' => 'https://' . $domain . '/' . $prefix . '/', 'steps' => $deploy['steps'] ?? []]);
            break;

        case 'remove_subfolder':
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $prefix = cfDeployNormalizePrefix($_POST['prefix'] ?? '');

            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден.');

            dbRetryOnLock(function () use ($pdo, $site, $prefix) {
                $pdo->prepare("DELETE FROM cf_deploy_versions WHERE site_id = ? AND prefix = ?")
                    ->execute([$site['id'], $prefix]);
            });

            $deploy = cfDeployRebuildSite($pdo, $userId, $accId, $domain);
            logAction($pdo, $userId, 'Deploy Subfolder Removed', "domain=$domain prefix=$prefix");
            cfDeployRespond(['success' => (bool)$deploy['success'], 'error' => $deploy['error'] ?? null, 'steps' => $deploy['steps'] ?? []]);
            break;

        case 'list_pages':
            // FR-10.3: страницы сайта + текущие мета-значения + сохранённые переопределения.
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден.');
            if (!cfDeployHasSource($site['id'])) {
                cfDeployRespond(['success' => true, 'has_source' => false, 'pages' => []]);
                break;
            }
            $overrides = cfDeployLoadPageMeta($pdo, $site['id']);
            $srcDir = cfDeploySiteSrcDir($site['id']);
            $pages = [];
            foreach (cfDeployListSitePages($site['id']) as $rel) {
                $cur = cfDeployReadPageMetaFromHtml(@file_get_contents($srcDir . '/' . $rel));
                $ov  = $overrides[$rel] ?? [];
                $pages[] = [
                    'path'     => $rel,
                    'current'  => $cur,
                    'override' => [
                        'title'       => $ov['title'] ?? '',
                        'description' => $ov['description'] ?? '',
                        'h1'          => $ov['h1'] ?? '',
                        'canonical'   => $ov['canonical'] ?? '',
                        'robots'      => $ov['robots'] ?? '',
                        'hreflang'    => $ov['hreflang'] ?? '',
                    ],
                ];
            }
            cfDeployRespond(['success' => true, 'has_source' => true, 'pages' => $pages]);
            break;

        case 'save_page_meta':
            // FR-10.3: сохранить переопределения одной страницы и переиздать сайт.
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $path   = (string)($_POST['path'] ?? '');
            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден.');
            if (!cfDeployHasSource($site['id'])) {
                throw new Exception('Нет сохранённого исходника — загрузите ZIP заново, затем повторите правку меты.');
            }
            // Путь должен быть реальной страницей исходника (защита от произвольных путей).
            if (!in_array($path, cfDeployListSitePages($site['id']), true)) {
                throw new Exception('Неизвестная страница: ' . htmlspecialchars($path));
            }
            cfDeploySavePageMeta($pdo, $site['id'], $path, [
                'title'       => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'h1'          => $_POST['h1'] ?? '',
                'canonical'   => $_POST['canonical'] ?? '',
                'robots'      => $_POST['robots'] ?? '',
                'hreflang'    => $_POST['hreflang'] ?? '',
            ]);

            $deploy = cfDeployRebuildSite($pdo, $userId, $accId, $domain);
            logAction($pdo, $userId, 'Deploy Page Meta', "domain=$domain path=$path ok=" . ($deploy['success'] ? 1 : 0));
            cfDeployRespond(['success' => (bool)$deploy['success'], 'error' => $deploy['error'] ?? null,
                'path' => $path, 'steps' => $deploy['steps'] ?? []]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Throwable $e) {
    // Throwable, а не Exception: ловим и Error/TypeError (иначе PHP отдаёт HTML).
    http_response_code(400);
    $raw   = $e->getMessage();
    $phase = cfDeployPhase();
    $isLock = (stripos($raw, 'database is locked') !== false
            || stripos($raw, 'database is busy') !== false
            || stripos($raw, 'database table is locked') !== false);

    if ($isLock) {
        // Детально: какой шаг + человекочитаемая причина + что делать.
        $error = 'База данных заблокирована конкурентной записью'
               . ($phase ? ' на шаге «' . $phase . '»' : '')
               . ' — одновременно писали фоновые процессы (мониторинг/очередь/синхронизация). '
               . 'Публикация файлов на Cloudflare могла пройти; повторите через несколько секунд.';
        $payload = ['success' => false, 'error' => $error, 'error_kind' => 'db_locked',
                    'phase' => $phase, 'detail' => $raw];
    } else {
        $error = ($phase ? 'Шаг «' . $phase . '»: ' : '') . $raw;
        $payload = ['success' => false, 'error' => $error, 'phase' => $phase, 'detail' => $raw];
    }

    // Пишем в журнал панели (в ретрае — чтобы сам лок не помешал записи лога).
    if (function_exists('logActionSafe')) {
        logActionSafe($pdo ?? null, $userId ?? null, 'Deploy API Error',
            'action=' . ($action ?? '?') . ' phase=' . $phase . ' err=' . substr($raw, 0, 300));
    }
    cfDeployRespond($payload);
}
