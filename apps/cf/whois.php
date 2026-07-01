<?php
/**
 * WHOIS Information Page
 * View domain registration dates, expiry, registrars
 */

require_once 'config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Apply WHOIS migration if columns don't exist
try {
    $stmt = $pdo->query("SELECT whois_expiry_date FROM cloudflare_accounts LIMIT 1");
} catch (Exception $e) {
    // Columns don't exist, create them
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_registrar TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_created_date TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_expiry_date TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_updated_date TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_registrant TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_name_servers TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_status TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_last_check TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_days_until_expiry INTEGER DEFAULT NULL");
}

// Доп. колонки для RDAP-данных (DNSSEC, abuse-контакт, источник whois/rdap)
try {
    $pdo->query("SELECT whois_source FROM cloudflare_accounts LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_dnssec TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_abuse TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE cloudflare_accounts ADD COLUMN whois_source TEXT DEFAULT NULL");
}

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Get statistics
$stats = [];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ?");
$stmt->execute([$userId]);
$stats['total'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_expiry_date IS NOT NULL");
$stmt->execute([$userId]);
$stats['with_data'] = (int)$stmt->fetchColumn();

$stats['without_data'] = $stats['total'] - $stats['with_data'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 30 AND whois_days_until_expiry > 0");
$stmt->execute([$userId]);
$stats['expiring_30'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 60 AND whois_days_until_expiry > 0");
$stmt->execute([$userId]);
$stats['expiring_60'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 0 AND whois_days_until_expiry IS NOT NULL");
$stmt->execute([$userId]);
$stats['expired'] = (int)$stmt->fetchColumn();

// Build query based on filter
$where = "ca.user_id = ?";
$params = [$userId];

switch ($filter) {
    case 'expiring_30':
        $where .= " AND ca.whois_days_until_expiry <= 30 AND ca.whois_days_until_expiry > 0";
        break;
    case 'expiring_60':
        $where .= " AND ca.whois_days_until_expiry <= 60 AND ca.whois_days_until_expiry > 0";
        break;
    case 'expiring_90':
        $where .= " AND ca.whois_days_until_expiry <= 90 AND ca.whois_days_until_expiry > 0";
        break;
    case 'expired':
        $where .= " AND ca.whois_days_until_expiry <= 0 AND ca.whois_days_until_expiry IS NOT NULL";
        break;
    case 'no_data':
        $where .= " AND ca.whois_expiry_date IS NULL";
        break;
    case 'has_data':
        $where .= " AND ca.whois_expiry_date IS NOT NULL";
        break;
}

// Sorting
$sortBy = $_GET['sort'] ?? 'expiry';
$sortDir = $_GET['dir'] ?? 'asc';

$orderBy = match($sortBy) {
    'domain' => "ca.domain",
    'expiry' => "CASE WHEN ca.whois_expiry_date IS NULL THEN 1 ELSE 0 END, ca.whois_days_until_expiry",
    'created' => "ca.whois_created_date",
    'registrar' => "ca.whois_registrar",
    'days' => "ca.whois_days_until_expiry",
    'checked' => "ca.whois_last_check",
    default => "ca.whois_days_until_expiry"
};

$stmt = $pdo->prepare("
    SELECT ca.id, ca.domain, ca.whois_registrar, ca.whois_created_date, 
           ca.whois_expiry_date, ca.whois_updated_date, ca.whois_registrant,
           ca.whois_name_servers, ca.whois_status, ca.whois_last_check,
           ca.whois_dnssec, ca.whois_abuse, ca.whois_source,
           ca.whois_days_until_expiry, g.name as group_name
    FROM cloudflare_accounts ca
    LEFT JOIN groups g ON ca.group_id = g.id
    WHERE {$where}
    ORDER BY {$orderBy} " . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ", ca.domain ASC
");
$stmt->execute($params);
$domains = $stmt->fetchAll();

// Helper functions
function getExpiryBadge($days) {
    if ($days === null) {
        return '<span class="badge bg-secondary">Нет данных</span>';
    }
    if ($days <= 0) {
        return '<span class="badge bg-dark text-danger"><i class="fas fa-skull me-1"></i>Истёк</span>';
    }
    if ($days <= 7) {
        return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>' . $days . ' дн.</span>';
    }
    if ($days <= 30) {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>' . $days . ' дн.</span>';
    }
    if ($days <= 60) {
        return '<span class="badge bg-info">' . $days . ' дн.</span>';
    }
    return '<span class="badge bg-success">' . $days . ' дн.</span>';
}

function formatDate($date) {
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $date;
}

function getSortLink($column, $currentSort, $currentDir) {
    $newDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $filter = $_GET['filter'] ?? 'all';
    return "?filter={$filter}&sort={$column}&dir={$newDir}";
}

function getSortIcon($column, $currentSort, $currentDir) {
    if ($currentSort !== $column) return '<i class="fas fa-sort text-muted ms-1"></i>';
    return $currentDir === 'asc' 
        ? '<i class="fas fa-sort-up text-primary ms-1"></i>' 
        : '<i class="fas fa-sort-down text-primary ms-1"></i>';
}
?>

<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1"><i class="fas fa-id-card me-2 text-primary"></i>WHOIS Информация</h2>
            <p class="text-muted mb-0">Даты регистрации, срок действия и регистратор доменов</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Назад
        </a>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="stat-card bg-gradient-primary">
                <div class="icon"><i class="fas fa-globe"></i></div>
                <div class="info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Всего доменов</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card bg-gradient-success">
                <div class="icon"><i class="fas fa-check-circle"></i></div>
                <div class="info">
                    <h3><?php echo $stats['with_data']; ?></h3>
                    <p>С WHOIS данными</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card bg-gradient-secondary">
                <div class="icon"><i class="fas fa-question-circle"></i></div>
                <div class="info">
                    <h3><?php echo $stats['without_data']; ?></h3>
                    <p>Без данных</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card bg-gradient-warning">
                <div class="icon"><i class="fas fa-clock"></i></div>
                <div class="info">
                    <h3><?php echo $stats['expiring_30']; ?></h3>
                    <p>Истекают (30д)</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card bg-gradient-info">
                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="info">
                    <h3><?php echo $stats['expiring_60']; ?></h3>
                    <p>Истекают (60д)</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card bg-gradient-danger">
                <div class="icon"><i class="fas fa-skull"></i></div>
                <div class="info">
                    <h3><?php echo $stats['expired']; ?></h3>
                    <p>Истекли</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Actions -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="btn-group" role="group">
                        <a href="?filter=all" class="btn btn-<?php echo $filter === 'all' ? 'primary' : 'outline-primary'; ?>">
                            Все
                        </a>
                        <a href="?filter=expiring_30" class="btn btn-<?php echo $filter === 'expiring_30' ? 'warning' : 'outline-warning'; ?>">
                            <i class="fas fa-exclamation-triangle me-1"></i>30 дней
                            <?php if ($stats['expiring_30'] > 0): ?>
                                <span class="badge bg-danger ms-1"><?php echo $stats['expiring_30']; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="?filter=expiring_60" class="btn btn-<?php echo $filter === 'expiring_60' ? 'info' : 'outline-info'; ?>">
                            60 дней
                        </a>
                        <a href="?filter=expiring_90" class="btn btn-<?php echo $filter === 'expiring_90' ? 'secondary' : 'outline-secondary'; ?>">
                            90 дней
                        </a>
                        <a href="?filter=expired" class="btn btn-<?php echo $filter === 'expired' ? 'danger' : 'outline-danger'; ?>">
                            <i class="fas fa-skull me-1"></i>Истекли
                        </a>
                        <a href="?filter=no_data" class="btn btn-<?php echo $filter === 'no_data' ? 'dark' : 'outline-dark'; ?>">
                            Без данных
                        </a>
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <button id="checkAllBtn" class="btn btn-success me-2" onclick="checkAllWhois()">
                        <i class="fas fa-sync-alt me-2"></i>Проверить все
                    </button>
                    <button id="checkSelectedBtn" class="btn btn-primary" onclick="checkSelectedWhois()">
                        <i class="fas fa-search me-2"></i>Проверить выбранные
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Domains Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Домены</h5>
            <div>
                <span class="badge bg-primary"><?php echo count($domains); ?> доменов</span>
                <div class="form-check form-check-inline ms-3">
                    <input type="checkbox" id="selectAll" class="form-check-input" onchange="toggleSelectAll()">
                    <label class="form-check-label" for="selectAll">Выбрать все</label>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="40">
                                <input type="checkbox" id="selectAllHeader" class="form-check-input" onchange="toggleSelectAll()">
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('domain', $sortBy, $sortDir); ?>" class="text-decoration-none text-dark">
                                    Домен <?php echo getSortIcon('domain', $sortBy, $sortDir); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('registrar', $sortBy, $sortDir); ?>" class="text-decoration-none text-dark">
                                    Регистратор <?php echo getSortIcon('registrar', $sortBy, $sortDir); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('created', $sortBy, $sortDir); ?>" class="text-decoration-none text-dark">
                                    Регистрация <?php echo getSortIcon('created', $sortBy, $sortDir); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('expiry', $sortBy, $sortDir); ?>" class="text-decoration-none text-dark">
                                    Истекает <?php echo getSortIcon('expiry', $sortBy, $sortDir); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('days', $sortBy, $sortDir); ?>" class="text-decoration-none text-dark">
                                    Осталось <?php echo getSortIcon('days', $sortBy, $sortDir); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('checked', $sortBy, $sortDir); ?>" class="text-decoration-none text-dark">
                                    Проверено <?php echo getSortIcon('checked', $sortBy, $sortDir); ?>
                                </a>
                            </th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($domains)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-search fa-2x mb-2 d-block"></i>
                                    Домены не найдены
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($domains as $d): ?>
                                <?php 
                                $rowClass = '';
                                if ($d['whois_days_until_expiry'] !== null) {
                                    if ($d['whois_days_until_expiry'] <= 0) {
                                        $rowClass = 'table-danger';
                                    } elseif ($d['whois_days_until_expiry'] <= 7) {
                                        $rowClass = 'table-danger';
                                    } elseif ($d['whois_days_until_expiry'] <= 30) {
                                        $rowClass = 'table-warning';
                                    }
                                }
                                ?>
                                <tr class="<?php echo $rowClass; ?>" id="row-<?php echo $d['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="form-check-input domain-checkbox" 
                                               value="<?php echo $d['id']; ?>">
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($d['domain']); ?></div>
                                        <?php if ($d['group_name']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($d['group_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($d['whois_registrar'] ?? '—'); ?>
                                    </td>
                                    <td>
                                        <?php echo formatDate($d['whois_created_date']); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo formatDate($d['whois_expiry_date']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo getExpiryBadge($d['whois_days_until_expiry']); ?>
                                    </td>
                                    <td>
                                        <?php if ($d['whois_last_check']): ?>
                                            <small class="text-muted" title="<?php echo $d['whois_last_check']; ?>">
                                                <?php echo date('d.m H:i', strtotime($d['whois_last_check'])); ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="checkSingleWhois(<?php echo $d['id']; ?>)"
                                                title="Проверить WHOIS">
                                            <i class="fas fa-search"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" 
                                                onclick="showWhoisDetails(<?php echo $d['id']; ?>)"
                                                title="Подробности"
                                                <?php echo !$d['whois_expiry_date'] ? 'disabled' : ''; ?>>
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Progress Log -->
    <div class="card mt-4" id="logCard" style="display: none;">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-terminal me-2"></i>Лог проверки</h5>
        </div>
        <div class="card-body bg-dark text-light rounded-bottom p-0">
            <div class="progress rounded-0" style="height: 5px;" id="progressContainer">
                <div class="progress-bar bg-success" id="progressBar" style="width: 0%"></div>
            </div>
            <div id="operationLog" class="p-3" style="height: 250px; overflow-y: auto; font-family: monospace; font-size: 0.85rem;">
            </div>
        </div>
    </div>
</div>

<!-- WHOIS Details Modal -->
<div class="modal fade" id="whoisModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card me-2"></i>WHOIS Информация</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="whoisModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Store domains data for modal
const domainsData = <?php echo json_encode($domains); ?>;

// Toggle select all
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAllHeader');
    const checked = selectAll?.checked || document.getElementById('selectAll')?.checked;
    document.querySelectorAll('.domain-checkbox').forEach(cb => cb.checked = checked);
    
    // Sync both checkboxes
    if (document.getElementById('selectAll')) document.getElementById('selectAll').checked = checked;
    if (document.getElementById('selectAllHeader')) document.getElementById('selectAllHeader').checked = checked;
}

// Get selected domain IDs
function getSelectedDomains() {
    return Array.from(document.querySelectorAll('.domain-checkbox:checked')).map(cb => cb.value);
}

// Add log entry
function addLog(message, type = 'info') {
    const log = document.getElementById('operationLog');
    document.getElementById('logCard').style.display = 'block';
    
    const time = new Date().toLocaleTimeString();
    const colors = {
        'success': 'text-success',
        'error': 'text-danger',
        'warning': 'text-warning',
        'info': 'text-info'
    };
    
    const div = document.createElement('div');
    div.className = colors[type] || 'text-info';
    div.innerHTML = `<span class="text-secondary">[${time}]</span> ${message}`;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

// Update progress bar
function updateProgress(current, total) {
    const percent = Math.round((current / total) * 100);
    document.getElementById('progressBar').style.width = `${percent}%`;
}

// Check WHOIS for a single domain
async function checkSingleWhois(domainId) {
    const row = document.getElementById(`row-${domainId}`);
    const btn = row?.querySelector('button');
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_single');
        formData.append('domain_id', domainId);
        
        const res = await fetch('whois_api.php', { method: 'POST', body: formData });
        const json = await res.json();
        
        if (json.success) {
            // Update row data
            location.reload();
        } else {
            alert('Ошибка: ' + json.error);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-search"></i>';
            }
        }
    } catch (e) {
        alert('Ошибка сети: ' + e.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i>';
        }
    }
}

// Check WHOIS for selected domains (sequential)
async function checkSelectedWhois() {
    const domains = getSelectedDomains();
    if (!domains.length) {
        alert('Выберите домены для проверки');
        return;
    }
    
    if (!confirm(`Проверить WHOIS для ${domains.length} доменов?`)) return;
    
    document.getElementById('logCard').style.display = 'block';
    document.getElementById('operationLog').innerHTML = '';
    
    addLog(`🚀 Запуск проверки WHOIS для ${domains.length} доменов...`, 'info');
    updateProgress(0, domains.length);
    
    // Disable buttons
    document.getElementById('checkSelectedBtn').disabled = true;
    document.getElementById('checkAllBtn').disabled = true;
    
    let success = 0;
    let failed = 0;
    
    for (let i = 0; i < domains.length; i++) {
        const domainId = domains[i];
        const num = i + 1;
        
        addLog(`⏳ [${num}/${domains.length}] Проверка домена ID ${domainId}...`, 'info');
        
        try {
            const formData = new FormData();
            formData.append('action', 'check_single');
            formData.append('domain_id', domainId);
            
            const res = await fetch('whois_api.php', { method: 'POST', body: formData });
            const json = await res.json();
            
            if (json.success) {
                success++;
                const daysText = json.days_until_expiry !== null ? ` (${json.days_until_expiry} дн.)` : '';
                addLog(`✅ [${num}/${domains.length}] ${json.domain}: ${json.expiry_date || 'нет данных'}${daysText}`, 'success');
            } else {
                failed++;
                addLog(`❌ [${num}/${domains.length}] ${json.domain || 'ID ' + domainId}: ${json.error}`, 'error');
            }
        } catch (e) {
            failed++;
            addLog(`❌ [${num}/${domains.length}] Ошибка сети: ${e.message}`, 'error');
        }
        
        updateProgress(num, domains.length);
        
        // Delay between requests (1.5 seconds - WHOIS servers have rate limits)
        if (i < domains.length - 1) {
            await new Promise(resolve => setTimeout(resolve, 1500));
        }
    }
    
    addLog(`🏁 Завершено! Успешно: ${success}, Ошибок: ${failed}`, success > 0 ? 'success' : 'error');
    
    // Re-enable buttons
    document.getElementById('checkSelectedBtn').disabled = false;
    document.getElementById('checkAllBtn').disabled = false;
    
    // Reload page after 2 seconds
    if (success > 0) {
        addLog(`🔄 Страница обновится через 2 секунды...`, 'info');
        setTimeout(() => location.reload(), 2000);
    }
}

// Check WHOIS for all domains
async function checkAllWhois() {
    // Select all domains first
    document.querySelectorAll('.domain-checkbox').forEach(cb => cb.checked = true);
    await checkSelectedWhois();
}

// Show WHOIS details modal
function showWhoisDetails(domainId) {
    const domain = domainsData.find(d => d.id == domainId);
    if (!domain) return;
    
    const nameServers = domain.whois_name_servers ? JSON.parse(domain.whois_name_servers) : [];
    
    document.getElementById('whoisModalBody').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-muted mb-3">Основная информация</h6>
                <table class="table table-sm">
                    <tr><th width="40%">Домен</th><td><strong>${domain.domain}</strong></td></tr>
                    <tr><th>Регистратор</th><td>${domain.whois_registrar || '—'}</td></tr>
                    <tr><th>Владелец</th><td>${domain.whois_registrant || '—'}</td></tr>
                    <tr><th>Статус</th><td><code>${domain.whois_status || '—'}</code></td></tr>
                    <tr><th>DNSSEC</th><td>${domain.whois_dnssec === 'enabled' ? '<span class="badge bg-success">включён</span>' : (domain.whois_dnssec === 'disabled' ? '<span class="badge bg-secondary">выключен</span>' : '—')}</td></tr>
                    <tr><th>Abuse-контакт</th><td>${domain.whois_abuse ? '<code>'+domain.whois_abuse+'</code>' : '—'}</td></tr>
                    <tr><th>Источник</th><td>${domain.whois_source === 'rdap' ? '<span class="badge bg-info">RDAP</span>' : '<span class="badge bg-secondary">WHOIS</span>'}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted mb-3">Даты</h6>
                <table class="table table-sm">
                    <tr><th width="40%">Создан</th><td>${formatDateJS(domain.whois_created_date)}</td></tr>
                    <tr><th>Обновлён</th><td>${formatDateJS(domain.whois_updated_date)}</td></tr>
                    <tr><th>Истекает</th><td><strong>${formatDateJS(domain.whois_expiry_date)}</strong></td></tr>
                    <tr><th>Осталось</th><td>${domain.whois_days_until_expiry !== null ? domain.whois_days_until_expiry + ' дней' : '—'}</td></tr>
                </table>
            </div>
        </div>
        <div class="mt-3">
            <h6 class="text-muted mb-2">NS серверы</h6>
            ${nameServers.length > 0 
                ? '<ul class="mb-0">' + nameServers.map(ns => `<li><code>${ns}</code></li>`).join('') + '</ul>'
                : '<span class="text-muted">Нет данных</span>'
            }
        </div>
        <div class="mt-3 text-muted small">
            <i class="fas fa-clock me-1"></i>
            Проверено: ${domain.whois_last_check || 'никогда'}
        </div>
    `;
    
    new bootstrap.Modal(document.getElementById('whoisModal')).show();
}

function formatDateJS(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('ru-RU');
}
</script>

<?php include 'footer.php'; ?>