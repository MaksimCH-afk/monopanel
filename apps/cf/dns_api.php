<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if (!$action) throw new Exception('Не указано действие');

    $userId = $_SESSION['user_id'];

    switch ($action) {
        case 'list':
            $domainId = (int)($_GET['domain_id'] ?? 0);
            if ($domainId <= 0) throw new Exception('Неверный домен');
            echo json_encode(listDnsRecords($pdo, $userId, $domainId));
            break;
        case 'create':
            if ($method !== 'POST') throw new Exception('Метод не поддерживается');
            echo json_encode(createDnsRecord($pdo, $userId, $_POST));
            break;
        case 'update':
            if ($method !== 'POST') throw new Exception('Метод не поддерживается');
            echo json_encode(updateDnsRecord($pdo, $userId, $_POST));
            break;
        case 'delete':
            if ($method !== 'POST') throw new Exception('Метод не поддерживается');
            echo json_encode(deleteDnsRecord($pdo, $userId, $_POST));
            break;
        case 'export':
            $domainId = (int)($_GET['domain_id'] ?? 0);
            if ($domainId <= 0) throw new Exception('Неверный домен');
            echo json_encode(exportZone($pdo, $userId, $domainId));
            break;
        case 'import':
            if ($method !== 'POST') throw new Exception('Метод не поддерживается');
            echo json_encode(importZone($pdo, $userId, $_POST));
            break;
        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function ensureZone($pdo, $userId, $domainId) {
    $stmt = $pdo->prepare("SELECT ca.*, cc.email, cc.api_key FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    if (!$domain) throw new Exception('Домен не найден');
    $zoneId = $domain['zone_id'];
    $proxies = getProxies($pdo, $userId);
    if (!$zoneId) {
        $z = cloudflareApiRequest($pdo, $domain['email'], $domain['api_key'], "zones?name={$domain['domain']}", 'GET', [], $proxies, $userId);
        if (!$z || empty($z->result)) throw new Exception('Zone ID не найден');
        $zoneId = $z->result[0]->id;
        $pdo->prepare("UPDATE cloudflare_accounts SET zone_id = ? WHERE id = ?")->execute([$zoneId, $domainId]);
    }
    return [$domain, $zoneId, $proxies];
}

function listDnsRecords($pdo, $userId, $domainId) {
    [$domain, $zoneId, $proxies] = ensureZone($pdo, $userId, $domainId);
    $resp = cloudflareApiRequestDetailed($pdo, $domain['email'], $domain['api_key'], "zones/$zoneId/dns_records", 'GET', [], $proxies, $userId);
    if (!$resp['success']) throw new Exception('Не удалось получить записи DNS');
    return ['success' => true, 'records' => $resp['data']];
}

function createDnsRecord($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    $type = strtoupper(trim($data['type'] ?? ''));
    $name = trim($data['name'] ?? '');
    $content = trim($data['content'] ?? '');
    $ttl = (int)($data['ttl'] ?? 1);
    $proxied = isset($data['proxied']) ? (bool)$data['proxied'] : null;
    if ($domainId <= 0 || !$type || !$name || !$content) throw new Exception('Неверные параметры');
    [$domain, $zoneId, $proxies] = ensureZone($pdo, $userId, $domainId);
    $payload = [ 'type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl ];
    // proxied доступен только для A/AAAA/CNAME
    if (!is_null($proxied) && in_array($type, ['A','AAAA','CNAME'])) $payload['proxied'] = $proxied;
    // priority для MX/SRV/URI
    if (in_array($type, ['MX','SRV','URI']) && isset($data['priority']) && $data['priority'] !== '') {
        $payload['priority'] = (int)$data['priority'];
    }
    $resp = cloudflareApiRequestDetailed($pdo, $domain['email'], $domain['api_key'], "zones/$zoneId/dns_records", 'POST', $payload, $proxies, $userId);
    if (!$resp['success']) throw new Exception('Не удалось создать запись: ' . cfReadableError($resp));
    return ['success' => true, 'record' => $resp['data']];
}

function updateDnsRecord($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    $recordId = trim($data['record_id'] ?? '');
    if ($domainId <= 0 || !$recordId) throw new Exception('Неверные параметры');
    [$domain, $zoneId, $proxies] = ensureZone($pdo, $userId, $domainId);
    $payload = [];
    foreach (['type','name','content','ttl','priority'] as $k) if (isset($data[$k]) && $data[$k] !== '') $payload[$k] = $data[$k];
    if (isset($data['proxied'])) $payload['proxied'] = (bool)$data['proxied'];
    if (isset($payload['ttl'])) $payload['ttl'] = (int)$payload['ttl'];
    if (isset($payload['priority'])) $payload['priority'] = (int)$payload['priority'];
    if (empty($payload)) throw new Exception('Нет данных для обновления');
    $resp = cloudflareApiRequestDetailed($pdo, $domain['email'], $domain['api_key'], "zones/$zoneId/dns_records/$recordId", 'PATCH', $payload, $proxies, $userId);
    if (!$resp['success']) throw new Exception('Не удалось обновить запись: ' . cfReadableError($resp));
    return ['success' => true, 'record' => $resp['data']];
}

function deleteDnsRecord($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    $recordId = trim($data['record_id'] ?? '');
    if ($domainId <= 0 || !$recordId) throw new Exception('Неверные параметры');
    [$domain, $zoneId, $proxies] = ensureZone($pdo, $userId, $domainId);
    $resp = cloudflareApiRequestDetailed($pdo, $domain['email'], $domain['api_key'], "zones/$zoneId/dns_records/$recordId", 'DELETE', [], $proxies, $userId);
    if (!$resp['success']) throw new Exception('Не удалось удалить запись: ' . cfReadableError($resp));
    return ['success' => true];
}

// Экспорт зоны в BIND-формат (endpoint отдаёт текст, не JSON — нужен прямой curl)
function exportZone($pdo, $userId, $domainId) {
    [$domain, $zoneId] = ensureZone($pdo, $userId, $domainId);
    list($headers) = cfBuildAuthHeaders($domain['email'], $domain['api_key'], $domain['auth_type'] ?? null);
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/export");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code != 200 || $body === false) throw new Exception('Не удалось экспортировать зону (HTTP ' . $code . ')');
    return ['success' => true, 'bind' => $body, 'filename' => $domain['domain'] . '.txt'];
}

// Импорт BIND-файла в зону (multipart). Параметр content = текст BIND.
function importZone($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    $content = (string)($data['content'] ?? '');
    if ($domainId <= 0 || trim($content) === '') throw new Exception('Пустой BIND-файл');
    [$domain, $zoneId] = ensureZone($pdo, $userId, $domainId);
    list($headers) = cfBuildAuthHeaders($domain['email'], $domain['api_key'], $domain['auth_type'] ?? null);
    // убираем Content-Type: application/json — multipart выставит curl сам
    $headers = array_values(array_filter($headers, fn($h) => stripos($h, 'Content-Type:') !== 0));
    $tmp = tempnam(sys_get_temp_dir(), 'bind');
    file_put_contents($tmp, $content);
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/import");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($tmp, 'text/plain', 'zone.txt'), 'proxied' => 'false']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $j = json_decode($body, true);
    if ($code != 200 || empty($j['success'])) {
        $err = $j['errors'][0]['message'] ?? ('HTTP ' . $code);
        throw new Exception('Импорт не удался: ' . $err);
    }
    return ['success' => true, 'added' => $j['result']['recs_added'] ?? null, 'total' => $j['result']['total_records_parsed'] ?? null];
} 