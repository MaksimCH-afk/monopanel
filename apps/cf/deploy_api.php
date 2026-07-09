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

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
