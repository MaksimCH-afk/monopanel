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
        case 'toggle':
            $domainId = (int)($data['domain_id'] ?? 0);
            $enable = !empty($data['enable']);
            if ($domainId <= 0) {
                throw new Exception('Не указан домен');
            }
            $stmt = $pdo->prepare("SELECT ca.*, cc.email, cc.api_key FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
            $stmt->execute([$domainId, $userId]);
            $domainRow = $stmt->fetch();
            if (!$domainRow) {
                throw new Exception('Домен не найден');
            }
            $credentials = ['email' => $domainRow['email'], 'api_key' => $domainRow['api_key']];
            $proxies = getProxies($pdo, $userId);
            $result = cloudflareToggleAnalytics($pdo, $domainRow, $credentials, $enable, $proxies, $userId);
            echo json_encode($result);
            break;

        case 'dashboard':
            // Аналитика домена через GraphQL
            $domainId = (int)($data['domain_id'] ?? 0);
            $days = max(1, min(30, (int)($data['days'] ?? 7)));
            if ($domainId <= 0) {
                throw new Exception('Не указан домен');
            }
            $stmt = $pdo->prepare("SELECT ca.domain, ca.zone_id, cc.email, cc.api_key FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
            $stmt->execute([$domainId, $userId]);
            $domainRow = $stmt->fetch();
            if (!$domainRow || !$domainRow['zone_id']) {
                throw new Exception('Домен или zone_id не найден');
            }
            $proxies = getProxies($pdo, $userId);
            $a = cfZoneAnalyticsGraphQL($pdo, $domainRow['email'], $domainRow['api_key'], $domainRow['zone_id'], $days, $proxies, $userId);
            echo json_encode($a['success']
                ? ['success' => true, 'domain' => $domainRow['domain'], 'days' => $a['days'], 'totals' => $a['totals'], 'countries' => $a['countries'] ?? []]
                : ['success' => false, 'error' => $a['error']]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


