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
 * Общая точка для add_subfolder / remove_subfolder / save_meta.
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
        $pdo->prepare("UPDATE cf_deploy_sites SET workers_dev_url=?, files_count=?,
            last_deploy_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
            ->execute([$deploy['workers_dev_url'] ?? $site['workers_dev_url'], $deploy['files_count'] ?? 0, $site['id']]);
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
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $mode   = ($_POST['mode'] ?? 'static-only') === 'worker-first' ? 'worker-first' : 'static-only';
            if ($accId <= 0) throw new Exception('Не выбран аккаунт Cloudflare.');

            $file = $_FILES['archive'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception($file ? cfDeployUploadErrorText($file['error']) : 'Архив не получен.');
            }

            // Не доверяем клиенту — валидируем архив заново перед деплоем.
            $report = cfDeployValidateArchive($file['tmp_name']);
            if (!$report['valid']) {
                cfDeployRespond(['success' => false, 'error' => $report['error'],
                    'report' => ['oversized' => $report['oversized']]]);
                break;
            }

            $credentials = cfDeployLoadCredentials($pdo, $userId, $accId);
            $proxies = getProxies($pdo, $userId);
            $scriptName = cfWorkerScriptName($domain);

            $resolve = cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId);
            if (!$resolve['account_cf_id']) {
                throw new Exception($resolve['error'] ?? 'Не удалось определить аккаунт Cloudflare.');
            }
            $accountCfId = $resolve['account_cf_id'];

            // Сайт заводим/находим ДО деплоя (нужен siteId для хранилища исходника).
            // Всё одним ретрай-блоком: read→write под фоновой нагрузкой чувствителен к
            // "database is locked" (BUSY_SNAPSHOT).
            $siteId = dbRetryOnLock(function () use ($pdo, $userId, $accId, $domain, $scriptName, $resolve, $mode) {
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
            $extract = cfDeployExtractZip($file['tmp_name'], $report['root_prefix']);
            if (isset($extract['error'])) throw new Exception($extract['error']);
            try {
                cfDeploySaveRootSource($siteId, $extract['dir'], $extract['files']);
            } finally {
                cfDeployRmrf($extract['dir']);
            }

            // Сборка (корень + подпапки + мета) и публикация.
            $deploy = cfDeployAssembleAndPublish($pdo, $credentials, $accountCfId, $scriptName,
                $siteId, $domain, $mode, $proxies, $userId);

            if ($deploy['success']) {
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
            // FR-9: список опубликованных сайтов для управления и обновления.
            $stmt = $pdo->prepare("SELECT s.*, c.email AS account_email
                FROM cf_deploy_sites s
                JOIN cloudflare_credentials c ON c.id = s.account_id
                WHERE s.user_id = ? ORDER BY s.updated_at DESC");
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

            $bind = cfDeployBindDomain($pdo, $credentials, $resolve['account_cf_id'],
                $resolve['zone_id'], $scriptName, $domain, $proxies, $userId);
            if (!$bind['success']) {
                logAction($pdo, $userId, 'Deploy Bind Failed', "domain=$domain err=" . $bind['error']);
                throw new Exception($bind['error']);
            }

            $status = cfDeployBindingStatus($pdo, $credentials, $resolve['account_cf_id'],
                $resolve['zone_id'], $scriptName, $domain, $proxies, $userId);

            $pdo->prepare("UPDATE cf_deploy_sites SET custom_domain_bound=1, ssl_status=?, zone_id=?,
                updated_at=datetime('now') WHERE id=?")
                ->execute([$status['ssl_status'], $resolve['zone_id'], $siteId]);

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

            $pdo->prepare("UPDATE cf_deploy_sites SET custom_domain_bound=0, ssl_status=NULL,
                updated_at=datetime('now') WHERE user_id=? AND account_id=? AND domain=?")
                ->execute([$userId, $accId, $domain]);

            logAction($pdo, $userId, 'Deploy Unbind', "domain=$domain");
            cfDeployRespond(['success' => true, 'domain' => $domain]);
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
                $pdo->prepare("UPDATE cf_deploy_sites SET ssl_status=?, custom_domain_bound=1,
                    updated_at=datetime('now') WHERE user_id=? AND account_id=? AND domain=?")
                    ->execute([$status['ssl_status'], $userId, $accId, $domain]);
            }
            cfDeployRespond(['success' => true, 'domain' => $domain,
                'bound' => $status['bound'], 'bound_to' => $status['bound_to'], 'ssl_status' => $status['ssl_status']]);
            break;

        case 'list_versions':
            // FR-10: версии сайта (корень + подпапки) и текущий мета-конфиг.
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден.');

            $stmt = $pdo->prepare("SELECT prefix, source_prefix, share_root_assets FROM cf_deploy_versions
                WHERE site_id = ? ORDER BY prefix");
            $stmt->execute([$site['id']]);
            $versions = $stmt->fetchAll();
            $meta = cfDeployLoadMeta($pdo, $site['id']);

            cfDeployRespond([
                'success'  => true,
                'domain'   => $domain,
                'has_source' => cfDeployHasSource($site['id']),
                'versions' => $versions,
                'meta'     => $meta,
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

            $pdo->prepare("INSERT INTO cf_deploy_versions (site_id, prefix, source_prefix, share_root_assets)
                VALUES (?, ?, '', ?)
                ON CONFLICT(site_id, prefix) DO UPDATE SET share_root_assets = excluded.share_root_assets,
                    updated_at = datetime('now')")
                ->execute([$site['id'], $prefix, $share]);

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

            $pdo->prepare("DELETE FROM cf_deploy_versions WHERE site_id = ? AND prefix = ?")
                ->execute([$site['id'], $prefix]);
            // Убираем удалённую версию из мета-локалей.
            $meta = cfDeployLoadMeta($pdo, $site['id']);
            if (isset($meta['locales'][$prefix])) { unset($meta['locales'][$prefix]); }
            if (($meta['x_default'] ?? '') === $prefix) { $meta['x_default'] = ''; }
            cfDeploySaveMeta($pdo, $site['id'], $meta);

            $deploy = cfDeployRebuildSite($pdo, $userId, $accId, $domain);
            logAction($pdo, $userId, 'Deploy Subfolder Removed', "domain=$domain prefix=$prefix");
            cfDeployRespond(['success' => (bool)$deploy['success'], 'error' => $deploy['error'] ?? null, 'steps' => $deploy['steps'] ?? []]);
            break;

        case 'save_meta':
            // FR-10.2: canonical/hreflang/x-default. Конфиг хранится в модуле и переприменяется.
            $accId  = (int)($_POST['account_id'] ?? 0);
            $domain = cfDeployNormalizeDomain($_POST['domain'] ?? '');
            $site = cfDeployFindSite($pdo, $userId, $accId, $domain);
            if (!$site) throw new Exception('Сайт не найден.');

            $localesIn = $_POST['locales'] ?? '{}';
            $locales = is_array($localesIn) ? $localesIn : json_decode($localesIn, true);
            if (!is_array($locales)) $locales = [];
            // Санитизация: ключ — известный префикс версии, значение — код локали.
            $stmt = $pdo->prepare("SELECT prefix FROM cf_deploy_versions WHERE site_id = ?");
            $stmt->execute([$site['id']]);
            $known = array_column($stmt->fetchAll(), 'prefix');
            $clean = [];
            foreach ($locales as $pfx => $loc) {
                $pfx = trim((string)$pfx, '/');
                if (!in_array($pfx, $known, true)) continue;
                $loc = trim((string)$loc);
                if ($loc !== '' && !preg_match('/^[a-zA-Z]{2}(-[a-zA-Z0-9]{2,8})?$/', $loc)) {
                    throw new Exception('Некорректный код локали: ' . htmlspecialchars($loc));
                }
                if ($loc !== '') $clean[$pfx] = $loc;
            }
            $xDefault = trim((string)($_POST['x_default'] ?? ''), '/');
            if ($xDefault !== '' && !in_array($xDefault, $known, true)) $xDefault = '';

            $meta = [
                'enabled'   => !empty($_POST['enabled']) || !empty($clean),
                'x_default' => $xDefault,
                'locales'   => $clean,
            ];
            cfDeploySaveMeta($pdo, $site['id'], $meta);

            $deploy = cfDeployRebuildSite($pdo, $userId, $accId, $domain);
            logAction($pdo, $userId, 'Deploy Meta Saved', "domain=$domain locales=" . count($clean));
            cfDeployRespond(['success' => (bool)$deploy['success'], 'error' => $deploy['error'] ?? null,
                'meta' => $meta, 'steps' => $deploy['steps'] ?? []]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Throwable $e) {
    // Throwable, а не Exception: ловим и Error/TypeError (иначе PHP отдаёт HTML).
    http_response_code(400);
    cfDeployRespond(['success' => false, 'error' => $e->getMessage()]);
}
