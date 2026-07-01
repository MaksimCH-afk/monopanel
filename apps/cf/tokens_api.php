<?php
require_once 'config.php';
require_once 'functions.php';

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

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$action = $data['action'] ?? '';
$userId = $_SESSION['user_id'];

try {
    switch ($action) {
        case 'list':
            $accountId = isset($data['account_id']) ? (int)$data['account_id'] : null;
            $tokens = listCloudflareApiTokens($pdo, $userId, $accountId);
            echo json_encode(['success' => true, 'tokens' => $tokens]);
            break;

        case 'create':
            $accountId = (int)($data['account_id'] ?? 0);
            $name = trim($data['name'] ?? '');
            $token = trim($data['token'] ?? '');
            $tag = trim($data['tag'] ?? '');
            if ($accountId <= 0 || !$name || !$token) {
                throw new Exception('Не указаны обязательные параметры');
            }
            $id = saveCloudflareApiToken($pdo, $userId, $accountId, $name, $token, $tag ?: null);
            echo json_encode(['success' => true, 'token_id' => $id]);
            break;

        case 'delete':
            $tokenId = (int)($data['token_id'] ?? 0);
            if ($tokenId <= 0) {
                throw new Exception('Не указан token_id');
            }
            $deleted = deleteCloudflareApiToken($pdo, $userId, $tokenId);
            if (!$deleted) {
                throw new Exception('Токен не найден');
            }
            echo json_encode(['success' => true]);
            break;

        case 'export':
            $accountId = isset($data['account_id']) ? (int)$data['account_id'] : null;
            $csv = exportCloudflareTokensCsv($pdo, $userId, $accountId);
            echo json_encode(['success' => true, 'csv' => $csv]);
            break;

        case 'probe':
            // Префлайт прав токена аккаунта (по первой его зоне)
            $accountId = (int)($data['account_id'] ?? 0);
            if ($accountId <= 0) throw new Exception('Не указан account_id');
            $stmt = $pdo->prepare("SELECT cc.email, cc.api_key, cc.auth_type, ca.zone_id, ca.domain
                FROM cloudflare_credentials cc
                JOIN cloudflare_accounts ca ON ca.account_id = cc.id
                WHERE cc.id = ? AND cc.user_id = ? AND ca.zone_id IS NOT NULL AND ca.zone_id != ''
                LIMIT 1");
            $stmt->execute([$accountId, $userId]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('У аккаунта нет доменов с zone_id для проверки');
            $proxies = getProxies($pdo, $userId);
            $cfAccountId = cfGetAccountId($pdo, ['email' => $row['email'], 'api_key' => $row['api_key'], 'auth_type' => $row['auth_type']], $row['zone_id'], $proxies, null);
            $probe = cfProbeAccountCapabilities($pdo, $row['email'], $row['api_key'], $row['zone_id'], $cfAccountId, $proxies, null, $row['auth_type']);
            // Сохраняем в БД
            $pdo->prepare("UPDATE cloudflare_credentials SET capabilities = ? WHERE id = ? AND user_id = ?")
                ->execute([json_encode($probe['ok']), $accountId, $userId]);
            echo json_encode(['success' => true, 'capabilities' => $probe['ok'], 'labels' => cfCapabilityLabels(), 'zone' => $row['domain']]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


