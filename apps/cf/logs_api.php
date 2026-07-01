<?php
/**
 * API логов: экспорт в CSV и очистка.
 * Используется logs.php (кнопки «Экспорт» и «Очистить логи»).
 */
require_once 'config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// Для POST-запросов читаем JSON
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? ($_POST['action'] ?? '');
}

if ($action === 'export') {
    // Экспорт логов пользователя в CSV
    $stmt = $pdo->prepare("SELECT timestamp, action, details FROM logs WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);

    $filename = 'logs_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM для корректной кириллицы в Excel
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Время', 'Действие', 'Детали']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$row['timestamp'], $row['action'], $row['details']]);
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($action === 'clear_logs') {
    try {
        $stmt = $pdo->prepare("DELETE FROM logs WHERE user_id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
