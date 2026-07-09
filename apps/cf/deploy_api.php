<?php
/**
 * Модуль «Деплой из ZIP» — AJAX-эндпоинт.
 *
 * Фаза 1: приём и валидация архива (action = validate). Здесь только чтение —
 * ни одного вызова на запись в Cloudflare. Деплой/привязка домена/правки —
 * следующие фазы.
 */

require_once 'config.php';
require_once 'functions.php';
require_once 'deploy_lib.php';
require_once 'deploy_worker.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
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
 * Загружает учётные данные CF-аккаунта (cloudflare_credentials) по его id.
 * Возвращает ['email'=>..,'api_key'=>..,'auth_type'=>null] (auth_type определится автоматически).
 */
function cfDeployLoadCredentials($pdo, $userId, $accountId) {
    $stmt = $pdo->prepare("SELECT id, email, api_key, status FROM cloudflare_credentials WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new Exception('Аккаунт Cloudflare не найден.');
    }
    return ['email' => $row['email'], 'api_key' => $row['api_key'], 'auth_type' => null];
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
            echo json_encode([
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

            echo json_encode([
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
                echo json_encode(['success' => false, 'error' => $report['error'],
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

            $deploy = cfDeployRun($pdo, $credentials, $accountCfId, $scriptName,
                $file['tmp_name'], $report, $mode, $proxies, $userId);

            if ($deploy['success']) {
                // Фиксируем сайт в модуле (корневая версия).
                $stmt = $pdo->prepare("SELECT id FROM cf_deploy_sites WHERE user_id = ? AND account_id = ? AND domain = ?");
                $stmt->execute([$userId, $accId, $domain]);
                $siteId = $stmt->fetchColumn();

                if ($siteId) {
                    $pdo->prepare("UPDATE cf_deploy_sites SET worker_name=?, zone_id=?, workers_dev_url=?,
                        protection_mode=?, status='deployed', files_count=?, last_deploy_at=datetime('now'),
                        updated_at=datetime('now') WHERE id=?")
                        ->execute([$scriptName, $resolve['zone_id'], $deploy['workers_dev_url'] ?? null,
                                   $mode, $deploy['files_count'] ?? 0, $siteId]);
                } else {
                    $pdo->prepare("INSERT INTO cf_deploy_sites
                        (user_id, account_id, domain, worker_name, zone_id, workers_dev_url,
                         protection_mode, status, files_count, last_deploy_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'deployed', ?, datetime('now'))")
                        ->execute([$userId, $accId, $domain, $scriptName, $resolve['zone_id'],
                                   $deploy['workers_dev_url'] ?? null, $mode, $deploy['files_count'] ?? 0]);
                    $siteId = $pdo->lastInsertId();
                }
                // Корневая версия.
                $pdo->prepare("INSERT OR IGNORE INTO cf_deploy_versions (site_id, prefix) VALUES (?, '')")
                    ->execute([$siteId]);

                logAction($pdo, $userId, 'Deploy Success',
                    "domain=$domain worker=$scriptName mode=$mode url=" . ($deploy['workers_dev_url'] ?? '?'));
            } else {
                logAction($pdo, $userId, 'Deploy Failed', "domain=$domain worker=$scriptName err=" . ($deploy['error'] ?? '?'));
            }

            echo json_encode([
                'success'         => (bool)$deploy['success'],
                'error'           => $deploy['error'] ?? null,
                'worker_name'     => $scriptName,
                'workers_dev_url' => $deploy['workers_dev_url'] ?? null,
                'zone_in_account' => $resolve['zone_in_account'],
                'steps'           => $deploy['steps'] ?? [],
            ]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
