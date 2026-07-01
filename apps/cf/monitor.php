<?php
/**
 * Фоновый мониторинг доменов + Telegram-алерты.
 * Дёргается фоновым циклом (docker-entrypoint) с auth_token.
 * Перепроверяет батч доменов (статус/NS/IP/SSL/WHOIS), сравнивает с прошлым
 * состоянием и шлёт в Telegram ТОЛЬКО изменения. Раз в сутки — дайджест по срокам.
 *
 * Категории алертов:
 *   A) offline/online   — домен ушёл/вернулся (мгновенно)
 *   B) expiry           — WHOIS/SSL истекают (суточный дайджест)
 *   C) ns/ip/token/zone — смена NS (в т.ч. на другой Cloudflare), смена origin IP,
 *                         токен перестал работать, зона не active (мгновенно)
 */
require_once 'config.php';
require_once 'functions.php';
require_once 'whois_lib.php'; // checkDomainWhois и пр. — для фонового обновления WHOIS

// Авторизация как у очереди
$token = $_GET['auth_token'] ?? $_POST['auth_token'] ?? '';
if ($token !== 'cloudflare_queue_processor_2024') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
session_write_close();
@set_time_limit(110);
header('Content-Type: application/json; charset=utf-8');

$userId = (int)($pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn() ?: 1);

$intervalH = (float) appGetSetting($pdo, 'tg_monitor_interval_hours', '12');
$batch     = max(1, (int) appGetSetting($pdo, 'tg_monitor_batch', '8'));
$whoisStaleDays = 2;

$alerts = [];          // A + C — отправим одним сообщением
$tokenBadAccounts = []; // dedup по account_id

// === Батч доменов, которым пора на проверку ===
$cut = date('Y-m-d H:i:s', time() - (int)round($intervalH * 3600));
$stmt = $pdo->prepare("SELECT * FROM cloudflare_accounts
    WHERE user_id = ? AND (last_monitor IS NULL OR last_monitor < ?)
    ORDER BY (last_monitor IS NULL) DESC, last_monitor ASC LIMIT ?");
$stmt->execute([$userId, $cut, $batch]);
$domains = $stmt->fetchAll();

foreach ($domains as $d) {
    $id  = $d['id'];
    $dom = $d['domain'];
    $oldStatus = $d['domain_status'];
    $oldNs = tgNormalizeNs($d['ns_records']);
    $oldIp = trim((string)$d['dns_ip']);

    // --- A) статус доступности (HTTP, без CF) ---
    $st = checkDomainStatus($dom, $d['server_ip'] ?: null, []);
    $code = ($st['https']['code'] ?? 0) ?: ($st['http']['code'] ?? 0);
    $newStatus = tgClassifyStatus($code);
    try {
        $pdo->prepare("UPDATE cloudflare_accounts SET http_code = ?, domain_status = ?, last_check = datetime('now') WHERE id = ?")
            ->execute([$code, $newStatus, $id]);
    } catch (Exception $e) {}
    $wasUp = in_array($oldStatus, ['online', 'protected'], true);
    $isUp  = in_array($newStatus, ['online', 'protected'], true);
    if (tgAlertOn($pdo, 'offline') && $wasUp && $newStatus === 'offline') {
        $alerts[] = "🔴 <b>{$dom}</b> ушёл OFFLINE (HTTP {$code})";
    } elseif (tgAlertOn($pdo, 'offline') && $oldStatus === 'offline' && $isUp) {
        $alerts[] = "🟢 <b>{$dom}</b> снова ONLINE";
    }

    // --- C) NS / IP / токен / зона (через Cloudflare) ---
    $dnsRes = getDNSIPFromCloudflare($pdo, $id, $userId);
    if (empty($dnsRes['success'])) {
        $err = mb_strtolower($dnsRes['error'] ?? '');
        if ((strpos($err, '403') !== false || strpos($err, '401') !== false || strpos($err, 'auth') !== false || strpos($err, 'unauthor') !== false)
            && tgAlertOn($pdo, 'token') && !in_array($d['account_id'], $tokenBadAccounts, true)) {
            $tokenBadAccounts[] = $d['account_id'];
            $alerts[] = "🔑 <b>{$dom}</b>: токен аккаунта не работает ({$dnsRes['error']})";
        } elseif (strpos($err, 'зона') !== false && tgAlertOn($pdo, 'zone')) {
            $alerts[] = "❓ <b>{$dom}</b>: зона не найдена/не active в Cloudflare";
        }
    } else {
        $new = $pdo->query("SELECT ns_records, dns_ip FROM cloudflare_accounts WHERE id = $id")->fetch();
        $newNs = tgNormalizeNs($new['ns_records']);
        $newIp = trim((string)$new['dns_ip']);
        if (tgAlertOn($pdo, 'ns') && $oldNs !== '' && $newNs !== '' && $oldNs !== $newNs) {
            $offCf = !tgAllCloudflareNs($newNs);
            $mark = $offCf ? "⚠️ NS УШЛИ С CLOUDFLARE" : "ℹ️ сменились NS (другой аккаунт Cloudflare)";
            $alerts[] = "{$mark}: <b>{$dom}</b>\n   было: {$oldNs}\n   стало: {$newNs}";
        }
        if (tgAlertOn($pdo, 'ip') && $oldIp !== '' && $newIp !== '' && $oldIp !== $newIp) {
            $alerts[] = "⚠️ <b>{$dom}</b>: сменился origin IP {$oldIp} → {$newIp}";
        }
    }

    // --- SSL (для дайджеста B): обновляем каждый цикл ---
    try { getSSLStatusFromCloudflare($pdo, $id, $userId); } catch (Exception $e) {}

    // --- WHOIS (для дайджеста B): обновляем не чаще раза в $whoisStaleDays суток ---
    $wlc = $d['whois_last_check'] ?? null;
    if (!$wlc || strtotime($wlc) < time() - $whoisStaleDays * 86400) {
        try {
            if (function_exists('checkDomainWhois')) checkDomainWhois($pdo, $userId, $id);
        } catch (Exception $e) {}
    }

    $pdo->prepare("UPDATE cloudflare_accounts SET last_monitor = datetime('now') WHERE id = ?")->execute([$id]);
}

// === Отправка A+C одним сообщением ===
if ($alerts) {
    tgSendMessage($pdo, "📡 <b>Мониторинг CloudPanel</b>\n\n" . implode("\n", $alerts));
}

// === B) Суточный дайджест по срокам (один раз в день) ===
$today = date('Y-m-d');
if (tgAlertOn($pdo, 'expiry') && appGetSetting($pdo, 'tg_last_digest', '') !== $today) {
    $lines = [];

    // Дни до истечения считаем ИЗ ДАТЫ whois_expiry_date (она фиксирована), а не из
    // сохранённого счётчика — иначе число «зависает» (считалось при последней проверке WHOIS).
    // Заодно освежаем сохранённый счётчик, чтобы и на вкладке WHOIS было актуально.
    try {
        $pdo->exec("UPDATE cloudflare_accounts
            SET whois_days_until_expiry = CAST((julianday(whois_expiry_date) - julianday('now')) AS INTEGER)
            WHERE whois_expiry_date IS NOT NULL AND whois_expiry_date != ''");
    } catch (Exception $e) {}

    $exp = $pdo->prepare("SELECT domain, whois_expiry_date FROM cloudflare_accounts
        WHERE user_id = ? AND whois_expiry_date IS NOT NULL AND whois_expiry_date != ''");
    $exp->execute([$userId]);
    $domExp = [];
    foreach ($exp as $r) {
        $t = strtotime($r['whois_expiry_date']);
        if (!$t) continue;
        $days = (int) floor(($t - time()) / 86400);
        if ($days <= 30) $domExp[] = ['domain' => $r['domain'], 'd' => $days];
    }
    usort($domExp, function ($a, $b) { return $a['d'] <=> $b['d']; });
    if ($domExp) {
        $lines[] = "🌐 <b>Домены (WHOIS) истекают:</b>";
        foreach ($domExp as $r) {
            $d = $r['d'];
            $tag = $d <= 0 ? '❌ ИСТЁК' : ($d <= 7 ? "⏰ {$d} дн" : "{$d} дн");
            $lines[] = "   • {$r['domain']} — {$tag}";
        }
    }

    $ssl = $pdo->prepare("SELECT domain, ssl_nearest_expiry FROM cloudflare_accounts
        WHERE user_id = ? AND ssl_expires_soon = 1 ORDER BY ssl_nearest_expiry ASC");
    $ssl->execute([$userId]);
    $domSsl = $ssl->fetchAll();
    if ($domSsl) {
        $lines[] = "🔒 <b>SSL истекают скоро:</b>";
        foreach ($domSsl as $r) $lines[] = "   • {$r['domain']} — до {$r['ssl_nearest_expiry']}";
    }

    $noSsl = (int)$pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND (ssl_has_active IS NULL OR ssl_has_active = 0)")->fetchColumn();
    if ($noSsl > 0) $lines[] = "⚠️ Без активного SSL: {$noSsl} доменов";

    if ($lines) {
        tgSendMessage($pdo, "🗓 <b>Сводка по срокам — {$today}</b>\n\n" . implode("\n", $lines));
    }
    appSetSetting($pdo, 'tg_last_digest', $today);
}

echo json_encode(['ok' => true, 'checked' => count($domains), 'alerts' => count($alerts)]);
