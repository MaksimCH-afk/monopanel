<?php
$pageTitle = 'Здоровье';
require_once 'header.php';
$userId = $_SESSION['user_id'];

function hcount($pdo, $userId, $where, $params = []) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ?" . ($where ? " AND $where" : ''));
    $stmt->execute(array_merge([$userId], $params));
    return (int)$stmt->fetchColumn();
}

// Категории по СМЫСЛУ (приоритет — http_code):
//   online    — 2xx/3xx, сайт отвечает
//   protected — 401/403/429/503: сайт РАБОТАЕТ, но закрыт бот-защитой/«Только Google»
//               (для не-Googlebot проверяльщика это ожидаемо, НЕ offline)
//   offline   — реально недоступен (0/иные коды), не из-за защиты
//   unknown   — ещё не проверялся
$protectedCond = "http_code IN (401,403,429,503)";
$total     = hcount($pdo, $userId, '');
$online    = hcount($pdo, $userId, "domain_status = 'online' AND ($protectedCond) = 0");
$protected = hcount($pdo, $userId, $protectedCond);
$offline   = hcount($pdo, $userId, "domain_status IN ('offline','error') AND NOT ($protectedCond)");
$unknown   = hcount($pdo, $userId, "domain_status IS NULL AND http_code IS NULL");
$sslActive= hcount($pdo, $userId, "ssl_has_active = 1");
$sslSoon  = hcount($pdo, $userId, "ssl_expires_soon = 1");
$sslNone  = hcount($pdo, $userId, "(ssl_has_active IS NULL OR ssl_has_active = 0)");

// Очередь
$qStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM queue WHERE user_id = ? GROUP BY status");
$qStmt->execute([$userId]);
$queue = [];
foreach ($qStmt as $r) $queue[$r['status']] = (int)$r['c'];

// Оффлайн-домены (реально недоступные, БЕЗ защищённых 401/403/429/503)
$offStmt = $pdo->prepare("SELECT domain, http_code, last_check FROM cloudflare_accounts WHERE user_id = ? AND domain_status IN ('offline','error') AND NOT ($protectedCond) ORDER BY last_check DESC LIMIT 50");
$offStmt->execute([$userId]);
$offlineList = $offStmt->fetchAll();

// Защищённые домены (401/403/429/503) — работают, но закрыты бот-защитой/«Только Google»
$protStmt = $pdo->prepare("SELECT domain, http_code, last_check FROM cloudflare_accounts WHERE user_id = ? AND ($protectedCond) ORDER BY last_check DESC LIMIT 50");
$protStmt->execute([$userId]);
$protectedList = $protStmt->fetchAll();

// SSL истекает скоро
$soonStmt = $pdo->prepare("SELECT domain, ssl_nearest_expiry FROM cloudflare_accounts WHERE user_id = ? AND ssl_expires_soon = 1 ORDER BY ssl_nearest_expiry ASC LIMIT 50");
$soonStmt->execute([$userId]);
$soonList = $soonStmt->fetchAll();

// Недавние ошибки в логах. Исключаем безобидный 404 «could not find entrypoint ruleset»
// — это не сбой, а признак того, что у домена ещё нет ни одного custom-правила
// (Cloudflare так отвечает на пустой набор); выше по коду он обрабатывается штатно.
$errStmt = $pdo->prepare("SELECT action, details, timestamp FROM logs
    WHERE user_id = ?
      AND (action LIKE '%Failed%' OR action LIKE '%Error%' OR action LIKE '%429%')
      AND details NOT LIKE '%could not find entrypoint ruleset%'
    ORDER BY id DESC LIMIT 30");
$errStmt->execute([$userId]);
$errors = $errStmt->fetchAll();

include 'sidebar.php';
?>
<div class="content">
    <div class="content-header d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="fas fa-heart-pulse me-2"></i>Здоровье</h1>
            <p class="text-muted mb-0">Сводное состояние всех доменов</p>
        </div>
        <button class="btn btn-outline-secondary" onclick="location.reload()"><i class="fas fa-redo me-1"></i>Обновить</button>
    </div>

    <div class="row g-3 mb-4">
        <div class="col"><div class="card text-white" style="background:#4f46e5"><div class="card-body"><h3><?php echo $total; ?></h3><div>Всего доменов</div></div></div></div>
        <div class="col"><div class="card text-white bg-success"><div class="card-body"><h3><?php echo $online; ?></h3><div>Online</div></div></div></div>
        <div class="col"><div class="card text-white bg-warning"><div class="card-body"><h3><?php echo $protected; ?></h3><div>Защищён <small>(бот-защита)</small></div></div></div></div>
        <div class="col"><div class="card text-white bg-danger"><div class="card-body"><h3><?php echo $offline; ?></h3><div>Offline</div></div></div></div>
        <div class="col"><div class="card text-white bg-secondary"><div class="card-body"><h3><?php echo $unknown; ?></h3><div>Не проверено</div></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body"><h3 class="text-success"><?php echo $sslActive; ?></h3><div class="text-muted">SSL активен</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><h3 class="text-warning"><?php echo $sslSoon; ?></h3><div class="text-muted">SSL истекает скоро</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><h3 class="text-secondary"><?php echo $sslNone; ?></h3><div class="text-muted">Без активного SSL</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><h3 class="text-info"><?php echo ($queue['pending'] ?? 0); ?> / <?php echo ($queue['failed'] ?? 0); ?></h3><div class="text-muted">Очередь: ожидают / ошибки</div></div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header text-danger"><i class="fas fa-circle-xmark me-2"></i>Offline (<?php echo count($offlineList); ?>)</div>
                <div class="card-body p-0" style="max-height:340px;overflow-y:auto;">
                    <?php if (!$offlineList): ?><div class="p-3 text-muted">Нет</div><?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($offlineList as $d): ?>
                            <li class="list-group-item d-flex justify-content-between"><span><?php echo htmlspecialchars($d['domain']); ?></span><span class="text-muted small">HTTP <?php echo $d['http_code'] ?: '-'; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header text-warning"><i class="fas fa-shield-halved me-2"></i>Защищён (<?php echo count($protectedList); ?>)</div>
                <div class="card-body p-0" style="max-height:340px;overflow-y:auto;">
                    <?php if (!$protectedList): ?><div class="p-3 text-muted">Нет</div><?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($protectedList as $d): ?>
                            <li class="list-group-item d-flex justify-content-between"><span><?php echo htmlspecialchars($d['domain']); ?></span><span class="badge bg-warning text-dark">HTTP <?php echo $d['http_code'] ?: '-'; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <div class="card-footer small text-muted">Работают, но закрыты бот-защитой / «Только Google» — для не-Googlebot это норма.</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header text-warning"><i class="fas fa-clock me-2"></i>SSL истекает (<?php echo count($soonList); ?>)</div>
                <div class="card-body p-0" style="max-height:340px;overflow-y:auto;">
                    <?php if (!$soonList): ?><div class="p-3 text-muted">Нет</div><?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($soonList as $d): ?>
                            <li class="list-group-item d-flex justify-content-between"><span><?php echo htmlspecialchars($d['domain']); ?></span><span class="text-muted small"><?php echo htmlspecialchars($d['ssl_nearest_expiry'] ?: ''); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header text-secondary"><i class="fas fa-triangle-exclamation me-2"></i>Недавние ошибки</div>
                <div class="card-body p-0" style="max-height:340px;overflow-y:auto;">
                    <?php if (!$errors): ?><div class="p-3 text-muted">Нет</div><?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($errors as $e): ?>
                            <li class="list-group-item"><div class="small fw-bold"><?php echo htmlspecialchars($e['action']); ?></div><div class="small text-muted text-truncate"><?php echo htmlspecialchars(mb_substr($e['details'] ?? '', 0, 80)); ?></div></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
