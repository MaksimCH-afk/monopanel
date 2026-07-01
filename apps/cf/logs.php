<?php
$pageTitle = 'Логи';
require_once 'header.php';

$userId = $_SESSION['user_id'];

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Фильтр по тексту/домену/аккаунту (поиск в action+details)
$q = trim($_GET['q'] ?? '');
$where = "l.user_id = ?";
$params = [$userId];
if ($q !== '') {
    $where .= " AND (l.action LIKE ? OR l.details LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM logs l WHERE $where");
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Get logs with pagination
$stmt = $pdo->prepare("
    SELECT l.*, ca.domain
    FROM logs l
    LEFT JOIN cloudflare_accounts ca ON l.user_id = ca.user_id AND l.details LIKE '%' || ca.domain || '%'
    WHERE $where
    ORDER BY l.timestamp DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$logs = $stmt->fetchAll();

// Цвет бейджа по СМЫСЛУ результата (приоритет — над тематическими ключами):
//   success (зелёный)  — успешно применено/создано/завершено
//   danger  (красный)  — ошибка/провал
//   warning (жёлтый)   — промежуточное: начато/в очереди/в процессе
//   secondary (серый)  — пропущено/нейтрально/неизвестно
function getActionColor($action) {
    $a = mb_strtolower($action);
    // 1) Провалы — красный (проверяем первым, чтобы "Apply Failed" не стал зелёным)
    foreach (['fail', 'error', 'ошибк', 'denied', 'invalid', '429', 'не приме', 'не удал'] as $k) {
        if (mb_strpos($a, $k) !== false) return 'danger';
    }
    // 2) Промежуточное — жёлтый
    foreach (['start', 'начат', 'pending', 'ожид', 'process', 'в процесс', 'queued', 'в очеред', 'retry', 'повтор'] as $k) {
        if (mb_strpos($a, $k) !== false) return 'warning';
    }
    // 3) Пропущено/нейтрально — серый
    foreach (['skip', 'пропущ', 'logout'] as $k) {
        if (mb_strpos($a, $k) !== false) return 'secondary';
    }
    // 4) Успех — зелёный
    foreach (['applied', 'примен', 'success', 'успеш', 'created', 'создан', 'updated', 'обновл', 'added', 'добавл', 'deleted', 'удал', 'completed', 'заверш', 'login', 'removed', 'отключ'] as $k) {
        if (mb_strpos($a, $k) !== false) return 'success';
    }
    // 5) Тематические (если результат не распознан) — нейтральные акценты
    $topic = ['dns' => 'info', 'ssl' => 'info', 'cache' => 'info', 'worker' => 'dark', 'domain' => 'primary', 'security' => 'primary', 'whois' => 'info'];
    foreach ($topic as $key => $color) {
        if (mb_strpos($a, $key) !== false) return $color;
    }
    return 'secondary';
}

include 'sidebar.php';
?>

<div class="content">
    <div class="content-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="fas fa-history me-2"></i>Логи действий</h1>
                <p class="text-muted mb-0">История всех операций в системе</p>
            </div>
            <div class="d-flex gap-2">
                <form method="GET" class="d-flex gap-1">
                    <input type="text" name="q" class="form-control form-control-sm" style="width:220px" placeholder="Поиск: домен / аккаунт / действие" value="<?php echo htmlspecialchars($q); ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                    <?php if ($q !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="logs.php"><i class="fas fa-times"></i></a><?php endif; ?>
                </form>
                <button class="btn btn-outline-danger btn-sm" onclick="clearLogs()">
                    <i class="fas fa-trash me-1"></i>Очистить логи
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="exportLogs()">
                    <i class="fas fa-download me-1"></i>Экспорт
                </button>
            </div>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="quick-stats">
        <div class="card stat-card bg-gradient-primary">
            <div class="icon"><i class="fas fa-list"></i></div>
            <div class="info">
                <h3><?php echo number_format($totalLogs); ?></h3>
                <p>Всего записей</p>
            </div>
        </div>
        <div class="card stat-card bg-gradient-success">
            <div class="icon"><i class="fas fa-calendar-day"></i></div>
            <div class="info">
                <?php
                $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE user_id = ? AND date(timestamp) = date('now')");
                $todayStmt->execute([$userId]);
                $todayCount = $todayStmt->fetchColumn();
                ?>
                <h3><?php echo number_format($todayCount); ?></h3>
                <p>За сегодня</p>
            </div>
        </div>
        <div class="card stat-card bg-gradient-warning">
            <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="info">
                <?php
                $errorsStmt = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE user_id = ? AND (action LIKE '%error%' OR action LIKE '%fail%')");
                $errorsStmt->execute([$userId]);
                $errorsCount = $errorsStmt->fetchColumn();
                ?>
                <h3><?php echo number_format($errorsCount); ?></h3>
                <p>Ошибок</p>
            </div>
        </div>
    </div>
    
    <!-- Logs Table -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-list me-2"></i>История действий</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h5>Нет записей</h5>
                    <p>Логи появятся после выполнения операций в системе</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 180px;">Время</th>
                                <th style="width: 200px;">Действие</th>
                                <th class="text-end">Детали</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <span class="text-muted">
                                            <?php echo date('d.m.Y H:i:s', strtotime($log['timestamp'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo getActionColor($log['action']); ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span style="white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, monospace; font-size: 0.82rem;">
                                            <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function clearLogs() {
    if (!confirm('Вы уверены, что хотите очистить все логи? Это действие необратимо.')) {
        return;
    }
    
    fetch('logs_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'clear_logs' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Логи успешно очищены', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.error || 'Ошибка очистки логов', 'error');
        }
    })
    .catch(err => showToast('Ошибка: ' + err.message, 'error'));
}

function exportLogs() {
    window.location.href = 'logs_api.php?action=export';
}
</script>

<?php include 'footer.php'; ?>