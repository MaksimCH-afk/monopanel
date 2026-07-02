<?php
/**
 * [monopanel] Смена SSL-режима зоны для ОДНОГО домена (Off / Flexible / Full / Full Strict).
 * CF API: PATCH zones/{id}/settings/ssl  {"value":"off|flexible|full|strict"}.
 * "Full (Strict)" = strict, "Full" = full.
 */
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Метод не поддерживается');

    $userId = $_SESSION['user_id'];
    $domainId = (int)($_POST['domain_id'] ?? 0);
    $mode = trim($_POST['mode'] ?? '');
    $valid = ['off', 'flexible', 'full', 'strict'];
    if ($domainId <= 0) throw new Exception('Не указан домен');
    if (!in_array($mode, $valid, true)) throw new Exception('Недопустимый режим (off/flexible/full/strict)');

    $stmt = $pdo->prepare("SELECT ca.*, cc.email, cc.api_key FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    if (!$domain) throw new Exception('Домен не найден');

    $proxies = getProxies($pdo, $userId);
    $zoneId = $domain['zone_id'];
    if (!$zoneId) {
        $zoneResp = cloudflareApiRequest($pdo, $domain['email'], $domain['api_key'], "zones?name={$domain['domain']}", 'GET', [], $proxies, $userId);
        if (!$zoneResp || empty($zoneResp->result)) throw new Exception('Zone ID не найден');
        $zoneId = $zoneResp->result[0]->id;
        $pdo->prepare("UPDATE cloudflare_accounts SET zone_id = ? WHERE id = ?")->execute([$zoneId, $domainId]);
    }

    $resp = cloudflareApiRequestDetailed($pdo, $domain['email'], $domain['api_key'], "zones/$zoneId/settings/ssl", 'PATCH', ['value' => $mode], $proxies, $userId);
    if (!$resp['success']) throw new Exception('Cloudflare отклонил смену режима: ' . cfReadableError($resp));

    $pdo->prepare("UPDATE cloudflare_accounts SET ssl_mode = ?, updated_at = datetime('now') WHERE id = ? AND user_id = ?")->execute([$mode, $domainId, $userId]);
    logAction($pdo, $userId, 'Изменён SSL-режим', "{$domain['domain']}: → {$mode}");

    echo json_encode(['success' => true, 'domain' => $domain['domain'], 'mode' => $mode]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
