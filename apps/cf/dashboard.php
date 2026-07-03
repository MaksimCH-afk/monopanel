<?php
$pageTitle = 'Дашборд';
require_once 'header.php';
require_once 'handle_forms.php';


$userId = $_SESSION['user_id'];
$notification = $_GET['notification'] ?? '';
$error = $_GET['error'] ?? '';

// Получаем параметры сортировки и фильтрации
$sort_by = $_GET['sort_by'] ?? 'domain';
$sort_order = ($_GET['sort_order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$group_id = $_GET['group_id'] ?? null;
$search = trim($_GET['search'] ?? '');

// Валидация сортировки
$valid_sorts = ['domain', 'group_name', 'email', 'dns_ip', 'ssl_mode', 'domain_status', 'last_check'];
if (!in_array($sort_by, $valid_sorts)) {
    $sort_by = 'domain';
}

// Получаем группы
$groupStmt = $pdo->prepare("SELECT * FROM groups WHERE user_id = ?");
$groupStmt->execute([$userId]);
$groups = $groupStmt->fetchAll();

// Получаем аккаунты
$stmt = $pdo->prepare("SELECT * FROM cloudflare_credentials WHERE user_id = ?");
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll();

// Карта IP -> имя сервера (для подписи под DNS IP на дашборде)
$serverByIp = [];
try {
    foreach ($pdo->query("SELECT name, ip FROM servers") as $srv) {
        $serverByIp[$srv['ip']] = $srv['name'];
    }
} catch (Exception $e) { /* таблицы может ещё не быть */ }

// Пагинация
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Формируем фильтры
$filters = ["ca.user_id = ?"];
$params = [$userId];

if ($group_id === 'none') {
    $filters[] = "ca.group_id IS NULL";
} elseif ($group_id) {
    $filters[] = "ca.group_id = ?";
    $params[] = $group_id;
}

if ($search) {
    $filters[] = "ca.domain LIKE ?";
    $params[] = "%$search%";
}

// Фильтр по IP (на котором стоит сайт в Cloudflare) — своего рода «группа по IP»
$ip_filter = trim($_GET['ip'] ?? '');
if ($ip_filter !== '') {
    $filters[] = "ca.dns_ip LIKE ?";
    $params[] = "%$ip_filter%";
}

// Список уникальных IP для выпадающего фильтра
$ipListStmt = $pdo->prepare("SELECT dns_ip, COUNT(*) cnt FROM cloudflare_accounts WHERE user_id = ? AND dns_ip IS NOT NULL AND dns_ip != '' GROUP BY dns_ip ORDER BY cnt DESC, dns_ip");
$ipListStmt->execute([$userId]);
$ipList = $ipListStmt->fetchAll();

// Получаем общее количество
$countSql = "SELECT COUNT(*) FROM cloudflare_accounts ca WHERE " . implode(' AND ', $filters);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalDomains = $countStmt->fetchColumn();
$totalPages = ceil($totalDomains / $perPage);

// Получаем домены
$orderBy = match($sort_by) {
    'group_name' => 'COALESCE(g.name, "Без группы")',
    'email' => 'cc.email',
    'dns_ip' => 'ca.dns_ip',
    'ssl_mode' => 'ca.ssl_mode',
    'domain_status' => 'ca.domain_status',
    'last_check' => 'ca.last_check',
    default => 'ca.domain'
};

// Функция для генерации ссылки сортировки
function getSortLink($column, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'ASC') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort_by'] = $column;
    $params['sort_order'] = $newOrder;
    return '?' . http_build_query($params);
}

function getSortIcon($column, $currentSort, $currentOrder) {
    if ($currentSort !== $column) {
        return '<i class="fas fa-sort text-muted ms-1"></i>';
    }
    return $currentOrder === 'ASC'
        ? '<i class="fas fa-sort-up text-primary ms-1"></i>'
        : '<i class="fas fa-sort-down text-primary ms-1"></i>';
}

$sql = "
    SELECT ca.*, cc.email, g.name AS group_name,
           (SELECT COUNT(*) FROM security_rules sr WHERE sr.domain_id = ca.id AND sr.rule_type = 'only_google') AS only_google_count
    FROM cloudflare_accounts ca
    JOIN cloudflare_credentials cc ON ca.account_id = cc.id
    LEFT JOIN groups g ON ca.group_id = g.id
    WHERE " . implode(' AND ', $filters) . "
    ORDER BY $orderBy $sort_order 
    LIMIT ? OFFSET ?
";

$params[] = $perPage;
$params[] = $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$domains = $stmt->fetchAll();

// Статистика для дашборда
$stats = [
    'total' => $totalDomains,
    'active_ssl' => 0,
    'online' => 0,
    'proxied' => 0
];

// Быстрый подсчет статистики (можно оптимизировать отдельным запросом)
// Для демо используем текущую выборку или отдельный count
$stats['active_ssl'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND ssl_has_active = 1")->fetchColumn();
// Подсчитываем online - используем domain_status для совместимости со старой схемой БД
$stats['online'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND domain_status = 'online'")->fetchColumn();

// Функции для отображения (те же, что и раньше, но можно улучшить)
function getSSLModeInfo($mode) {
    $modes = [
        'off' => ['name' => 'Off', 'class' => 'danger'],
        'flexible' => ['name' => 'Flexible', 'class' => 'warning'],
        'full' => ['name' => 'Full', 'class' => 'info'],
        'strict' => ['name' => 'Full (Strict)', 'class' => 'success'],
        'full_strict' => ['name' => 'Full (Strict)', 'class' => 'success']
    ];
    // Нормализуем значение (lowercase, trim)
    $normalizedMode = strtolower(trim($mode ?? ''));
    // Пустое значение = режим ещё не синхронизирован с Cloudflare
    if ($normalizedMode === '' || $normalizedMode === 'unknown') {
        return ['name' => '— (синхр.)', 'class' => 'secondary'];
    }
    return $modes[$normalizedMode] ?? ['name' => ucfirst($normalizedMode), 'class' => 'secondary'];
}

function getDomainStatusInfo($status, $httpCode = null) {
    // Приводим httpCode к int для корректного сравнения (PDO может вернуть строку)
    $httpCodeInt = $httpCode !== null ? (int)$httpCode : null;
    
    // Проверяем HTTP код (200-399 считаются успешными)
    if ($httpCodeInt !== null && $httpCodeInt >= 200 && $httpCodeInt < 400) {
        return ['name' => 'Online', 'class' => 'success', 'icon' => 'check-circle'];
    }
    // 401/403/429/503 — домен жив, но доступ ограничен правилами («Только Google» и т.п.)
    if (($httpCodeInt !== null && in_array($httpCodeInt, [401, 403, 429, 503], true)) || $status === 'protected') {
        return ['name' => 'Защищён', 'class' => 'warning', 'icon' => 'shield-halved'];
    }
    // HTTP коды ошибок 4xx и 5xx
    if ($httpCodeInt !== null && $httpCodeInt >= 400) {
        return ['name' => "HTTP $httpCodeInt", 'class' => 'danger', 'icon' => 'times-circle'];
    }
    // Проверка по статусу из базы данных
    if ($status === 'online' || strpos($status ?? '', 'online') !== false) {
        return ['name' => 'Online', 'class' => 'success', 'icon' => 'check-circle'];
    }
    // Не проверено (http_code = 0 или null означает curl не смог подключиться)
    if ($status === null || $status === '' || $httpCodeInt === null || $httpCodeInt === 0) {
        return ['name' => 'Не проверен', 'class' => 'secondary', 'icon' => 'question-circle'];
    }
    // Оффлайн
    return ['name' => 'Offline', 'class' => 'danger', 'icon' => 'times-circle'];
}
?>

<?php include 'sidebar.php'; ?>

<div class="content">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">Дашборд</h2>
            <p class="text-muted mb-0">Обзор ваших доменов и статусов</p>
        </div>
        <div class="d-flex gap-2">
            <!-- Dropdown для управления группами -->
            <div class="dropdown">
                <button class="btn btn-warning dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-folder me-2"></i>Группы
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addGroupModal">
                            <i class="fas fa-folder-plus me-2 text-success"></i>Добавить группу
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#deleteGroupModal">
                            <i class="fas fa-folder-minus me-2 text-danger"></i>Удалить группу
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Dropdown для добавления аккаунтов -->
            <div class="dropdown">
                <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-user-plus me-2"></i>Аккаунты
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                            <i class="fas fa-user me-2 text-primary"></i>Добавить аккаунт
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addAccountsBulkModal">
                            <i class="fas fa-users me-2 text-success"></i>Массовое добавление
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addAccountQueueModal">
                            <i class="fas fa-tasks me-2 text-info"></i>Через очередь
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#manageAccountsModal">
                            <i class="fas fa-user-cog me-2 text-secondary"></i>Управление аккаунтами
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Dropdown для добавления доменов -->
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-globe me-2"></i>Домены
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addDomainModal">
                            <i class="fas fa-plus me-2 text-primary"></i>Добавить домен
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addDomainsBulkModal">
                            <i class="fas fa-layer-group me-2 text-success"></i>Массовое добавление
                        </a>
                    </li>
                </ul>
            </div>
            
            <button class="btn btn-info" onclick="startProgressiveSync()">
                <i class="fas fa-sync-alt me-2"></i>Синхронизировать
            </button>
            <button class="btn btn-outline-secondary" onclick="refreshPage()">
                <i class="fas fa-redo"></i>
            </button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card bg-gradient-primary">
                <div class="icon"><i class="fas fa-globe"></i></div>
                <div class="info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Всего доменов</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-gradient-success">
                <div class="icon"><i class="fas fa-shield-alt"></i></div>
                <div class="info">
                    <h3><?php echo $stats['active_ssl']; ?></h3>
                    <p>Активный SSL</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-gradient-info">
                <div class="icon"><i class="fas fa-check-circle"></i></div>
                <div class="info">
                    <h3><?php echo $stats['online']; ?></h3>
                    <p>Доменов онлайн</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-gradient-warning">
                <div class="icon"><i class="fas fa-bolt"></i></div>
                <div class="info">
                    <h3><?php echo count($groups); ?></h3>
                    <p>Групп доменов</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Список доменов</h5>
            
            <div class="d-flex gap-2">
                <select id="groupFilter" class="form-select form-select-sm" style="width: 150px;" onchange="applyFilters()">
                    <option value="">Все группы</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?php echo $group['id']; ?>" <?php echo $group_id == $group['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($group['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="ipFilter" class="form-select form-select-sm" style="width: 170px;" onchange="applyFilters()" title="Фильтр по IP сайта">
                    <option value="">Все IP</option>
                    <?php foreach ($ipList as $ipRow): ?>
                        <option value="<?php echo htmlspecialchars($ipRow['dns_ip']); ?>" <?php echo $ip_filter === $ipRow['dns_ip'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ipRow['dns_ip']); ?> (<?php echo (int)$ipRow['cnt']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="searchInput" class="form-control form-control-sm" style="width: 200px;"
                       placeholder="Поиск..." value="<?php echo htmlspecialchars($search); ?>" onkeyup="searchDomains(event)">
            </div>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th style="width: 40px;" class="text-center">
                                <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('domain', $sort_by, $sort_order); ?>" class="text-decoration-none text-dark">
                                    Домен <?php echo getSortIcon('domain', $sort_by, $sort_order); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('domain_status', $sort_by, $sort_order); ?>" class="text-decoration-none text-dark">
                                    Статус <?php echo getSortIcon('domain_status', $sort_by, $sort_order); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('ssl_mode', $sort_by, $sort_order); ?>" class="text-decoration-none text-dark">
                                    SSL <?php echo getSortIcon('ssl_mode', $sort_by, $sort_order); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('dns_ip', $sort_by, $sort_order); ?>" class="text-decoration-none text-dark">
                                    DNS IP <?php echo getSortIcon('dns_ip', $sort_by, $sort_order); ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo getSortLink('group_name', $sort_by, $sort_order); ?>" class="text-decoration-none text-dark">
                                    Группа <?php echo getSortIcon('group_name', $sort_by, $sort_order); ?>
                                </a>
                            </th>
                            <th class="text-end">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($domains as $domain): ?>
                            <?php
                                $httpCode = $domain['http_code'] ?? null;
                                $statusInfo = getDomainStatusInfo($domain['domain_status'] ?? null, $httpCode);
                                $sslInfo = getSSLModeInfo($domain['ssl_mode'] ?? '');
                            ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input domain-checkbox" value="<?php echo $domain['id']; ?>">
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars(mb_strtolower($domain['domain'])); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($domain['email']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $statusInfo['class']; ?>">
                                        <i class="fas fa-<?php echo $statusInfo['icon']; ?> me-1"></i>
                                        <?php echo $statusInfo['name']; ?>
                                    </span>
                                    <?php if (!empty($domain['only_google_count'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-white border" title="Применено правило «Только Google»" style="font-weight:700; font-family: Arial, sans-serif; padding:.35em .6em;">
                                                <span style="color:#4285F4">G</span><span style="color:#EA4335">o</span><span style="color:#FBBC05">o</span><span style="color:#4285F4">g</span><span style="color:#34A853">l</span><span style="color:#EA4335">e</span>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $sslInfo['class']; ?>">
                                        <?php echo $sslInfo['name']; ?>
                                    </span>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($domain['dns_ip'] ?? '—'); ?></code>
                                    <?php if (($domain['proxied'] ?? null) !== null): ?>
                                        <?php if ($domain['proxied']): ?>
                                            <i class="fas fa-cloud text-warning ms-1" title="Проксируется Cloudflare (оранжевое облако)"></i>
                                        <?php else: ?>
                                            <i class="fas fa-cloud text-muted ms-1" title="DNS only — трафик НЕ проксируется (серое облако)"></i>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php
                                    $primaryIp = trim(explode(',', $domain['dns_ip'] ?? '')[0]);
                                    $srvName = $serverByIp[$primaryIp] ?? null;
                                    if ($srvName): ?>
                                        <div class="small text-muted"><i class="fas fa-server me-1"></i><?php echo htmlspecialchars($srvName); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm group-select"
                                            data-domain-id="<?php echo $domain['id']; ?>"
                                            data-current-group="<?php echo $domain['group_id'] ?? ''; ?>"
                                            style="min-width: 120px; font-size: 0.8rem;"
                                            onchange="changeGroup(this, <?php echo $domain['id']; ?>)">
                                        <option value="" <?php echo empty($domain['group_id']) ? 'selected' : ''; ?>>— Без группы —</option>
                                        <?php foreach ($groups as $group): ?>
                                            <option value="<?php echo $group['id']; ?>" <?php echo ($domain['group_id'] ?? '') == $group['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($group['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-light btn-sm btn-icon me-1" type="button" title="Проверить текущие NS-серверы домена (живой DNS-запрос)" onclick="checkLiveNS(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars(mb_strtolower($domain['domain'])); ?>')">
                                        <i class="fas fa-globe text-info"></i>
                                    </button>
                                    <button class="btn btn-light btn-sm btn-icon me-1" type="button" title="Очистить кэш" onclick="purgeDomainCache(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')">
                                        <i class="fas fa-broom text-warning"></i>
                                    </button>
                                    <div class="dropdown d-inline-block">
                                        <button class="btn btn-light btn-sm btn-icon" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li><h6 class="dropdown-header">Управление</h6></li>
                                            <li><a class="dropdown-item" href="#" onclick="syncDomainNow(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"><i class="fas fa-rotate me-2 text-primary"></i>Синхронизировать (IP/SSL/статус)</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="openDnsManager(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"><i class="fas fa-list me-2 text-primary"></i>DNS записи (A/CNAME/TXT/MX)</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="showCloudflareNS(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"><i class="fas fa-network-wired me-2 text-primary"></i>NS Cloudflare (для регистратора)</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="checkSSL(<?php echo $domain['id']; ?>)"><i class="fas fa-shield-alt me-2 text-success"></i>Проверить SSL</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="changeSslMode(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>', '<?php echo htmlspecialchars($domain['ssl_mode'] ?? ''); ?>'); return false;"><i class="fas fa-lock me-2 text-success"></i>Изменить SSL-режим</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="openAnalytics(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"><i class="fas fa-chart-line me-2 text-info"></i>Аналитика</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="reissueDomainToken(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>'); return false;"><i class="fas fa-key me-2 text-warning"></i>Перевыпустить токен</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><h6 class="dropdown-header">Безопасность</h6></li>
                                            <li><a class="dropdown-item" href="#" onclick="toggleUnderAttack(<?php echo $domain['id']; ?>, true)"><i class="fas fa-bolt me-2 text-warning"></i>Under Attack ON</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="toggleUnderAttack(<?php echo $domain['id']; ?>, false)"><i class="fas fa-bolt-slash me-2 text-muted"></i>Under Attack OFF</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="manageWorkers(<?php echo $domain['id']; ?>)"><i class="fas fa-code me-2 text-info"></i>Workers</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteDomain(<?php echo $domain['id']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"><i class="fas fa-trash me-2"></i>Удалить</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <?php
                    // Формируем параметры для пагинации (без page)
                    $paginationParams = $_GET;
                    unset($paginationParams['page']);
                    $queryString = http_build_query($paginationParams);
                    $queryPrefix = $queryString ? '&' . $queryString : '';
                ?>
                <div class="p-3 border-top">
                    <nav>
                        <ul class="pagination justify-content-center mb-0">
                            <!-- Первая страница -->
                            <?php if ($page > 2): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo $queryPrefix; ?>">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Предыдущая страница -->
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo $queryPrefix; ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Номера страниц -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $queryPrefix; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Следующая страница -->
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo $queryPrefix; ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Последняя страница -->
                            <?php if ($page < $totalPages - 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo $queryPrefix; ?>">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <div class="text-center mt-2 text-muted small">
                        Всего: <?php echo $totalDomains; ?> доменов | Показано: <?php echo count($domains); ?> | Страница <?php echo $page; ?> из <?php echo $totalPages; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Bulk Actions Floating Bar (Visible when items selected) -->
    <div id="bulkActionsBar" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 p-3 bg-white shadow rounded-pill d-none" style="z-index: 1050; min-width: 500px;">
        <div class="d-flex align-items-center justify-content-between gap-3">
            <span class="fw-bold"><span id="selectedCount">0</span> выбрано</span>
            <div class="vr"></div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-info" onclick="bulkSyncDomains()"><i class="fas fa-rotate me-1"></i>Синхр.</button>
                <button class="btn btn-sm btn-outline-primary" onclick="bulkUpdateDNS()">DNS IP</button>
                <button class="btn btn-sm btn-outline-success" onclick="bulkCheckSSL()">SSL</button>
                <button class="btn btn-sm btn-outline-info" onclick="openBulkWorkersModal()">Workers</button>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#bulkGroupModal"><i class="fas fa-folder me-1"></i>Группа</button>
                <button class="btn btn-sm btn-outline-danger" onclick="bulkDeleteDomains()">Удалить</button>
            </div>
            <button class="btn-close" onclick="toggleSelectAll(false)"></button>
        </div>
    </div>

</div>

<!-- Progressive Sync Modal -->
<div class="modal fade" id="progressiveSyncModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-sync-alt me-2"></i>Синхронизация доменов</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="closeSyncModal"></button>
            </div>
            <div class="modal-body">
                <!-- Выбор группы -->
                <div id="syncStep1">
                    <div class="mb-3">
                        <label class="form-label">Что синхронизировать (группа или аккаунт):</label>
                        <select id="syncGroupSelect" class="form-select">
                            <option value="all">🌐 Все домены</option>
                            <option value="unsynced">🆕 Только несинхронизированные</option>
                            <optgroup label="Группы">
                            <?php foreach ($groups as $group): ?>
                                <option value="group:<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Аккаунты">
                            <?php foreach ($accounts as $acc): ?>
                                <option value="account:<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['email']); ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Синхронизация обновит для каждого домена:
                        <ul class="mb-0 mt-2">
                            <li><strong>DNS IP</strong> — текущий IP из Cloudflare</li>
                            <li><strong>SSL режим</strong> — режим SSL</li>
                            <li><strong>Сертификат</strong> — статус SSL сертификата</li>
                            <li><strong>HTTP статус</strong> — доступность сайта</li>
                        </ul>
                    </div>
                    <button class="btn btn-info w-100" onclick="beginSync()">
                        <i class="fas fa-play me-2"></i>Начать синхронизацию
                    </button>
                </div>
                
                <!-- Прогресс -->
                <div id="syncStep2" class="d-none">
                    <div class="text-center mb-4">
                        <div class="display-4 text-info" id="syncPercent">0%</div>
                        <div class="text-muted">Обработано: <span id="syncProcessed">0</span> / <span id="syncTotal">0</span></div>
                    </div>
                    
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar bg-info progress-bar-striped progress-bar-animated"
                             id="syncProgressBar" style="width: 0%"></div>
                    </div>
                    
                    <div class="row text-center mb-3">
                        <div class="col-3">
                            <div class="fw-bold text-success" id="statOnline">0</div>
                            <small class="text-muted">Online</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-danger" id="statOffline">0</div>
                            <small class="text-muted">Offline</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-warning" id="statSSL">0</div>
                            <small class="text-muted">SSL Active</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-info" id="statChanges">0</div>
                            <small class="text-muted">Изменений</small>
                        </div>
                    </div>
                    
                    <!-- Текущий домен -->
                    <div class="card mb-3">
                        <div class="card-body py-2">
                            <small class="text-muted">Текущий домен:</small>
                            <div class="fw-bold" id="currentDomain">-</div>
                        </div>
                    </div>
                    
                    <!-- Лог последних действий -->
                    <div class="card">
                        <div class="card-header py-2">
                            <small class="fw-bold">Последние обновления</small>
                        </div>
                        <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                            <ul class="list-group list-group-flush" id="syncLog">
                                <!-- Динамически заполняется -->
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Завершено -->
                <div id="syncStep3" class="d-none text-center">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h4>Синхронизация завершена!</h4>
                    <p class="text-muted">Обработано доменов: <span id="finalCount">0</span></p>
                    
                    <div class="row text-center mb-4">
                        <div class="col-3">
                            <div class="fw-bold text-success fs-4" id="finalOnline">0</div>
                            <small class="text-muted">Online</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-danger fs-4" id="finalOffline">0</div>
                            <small class="text-muted">Offline</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-warning fs-4" id="finalSSL">0</div>
                            <small class="text-muted">SSL Active</small>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-info fs-4" id="finalChanges">0</div>
                            <small class="text-muted">Изменений</small>
                        </div>
                    </div>
                    
                    <button class="btn btn-success" onclick="location.reload()">
                        <i class="fas fa-check me-2"></i>Обновить страницу
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include Modals -->
<?php include 'modals.php'; ?>

<!-- Массовое перемещение в группу -->
<div class="modal fade" id="bulkGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder me-2"></i>Переместить выбранные в группу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Существующая группа</label>
                <select id="bulkGroupSelect" class="form-select mb-3">
                    <option value="">— без группы —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label">…или создать новую</label>
                <input id="bulkNewGroup" class="form-control" placeholder="Название новой группы (приоритетнее выбора выше)">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="moveBulkToGroup()"><i class="fas fa-check me-1"></i>Переместить</button>
            </div>
        </div>
    </div>
</div>

<!-- NS Cloudflare (выданные для зоны) -->
<div class="modal fade" id="nsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-network-wired me-2"></i><span id="nsModalKind">NS Cloudflare</span> — <span id="nsModalDomain"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="nsModalBody"></div>
        </div>
    </div>
</div>

<script>
// Re-implementing necessary JS functions for the new layout
function toggleSelectAll(forceState = null) {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    
    const newState = forceState !== null ? forceState : selectAll.checked;
    selectAll.checked = newState;
    
    checkboxes.forEach(cb => cb.checked = newState);
    updateBulkBar();
}

function updateBulkBar() {
    const selected = document.querySelectorAll('.domain-checkbox:checked').length;
    const bar = document.getElementById('bulkActionsBar');
    document.getElementById('selectedCount').textContent = selected;
    
    if (selected > 0) {
        bar.classList.remove('d-none');
    } else {
        bar.classList.add('d-none');
    }
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('domain-checkbox')) {
        updateBulkBar();
    }
});

// ... (Include other necessary JS functions from previous dashboard.php or move to a separate js file)
// For brevity, assuming functions like updateDNS, checkSSL, etc. are available or included via a script tag
</script>

<!-- Load legacy scripts for functionality -->
<script>
    // Placeholder for legacy functions to ensure buttons work
    // In a real refactor, these should be moved to dashboard.js
    function refreshPage() { window.location.reload(); }
    function applyFilters() {
        const group = document.getElementById('groupFilter').value;
        const search = document.getElementById('searchInput').value;
        window.location.href = `?group_id=${group}&search=${search}`;
    }
    function searchDomains(e) { if(e.key === 'Enter') applyFilters(); }
    
    // ... (Copying essential logic from previous dashboard.php script block)
    // Since I cannot copy-paste 500 lines of JS here easily, I recommend creating dashboard.js
</script>

<script>
// Global variables
let operationModal = null;

document.addEventListener('DOMContentLoaded', function() {
    operationModal = new bootstrap.Modal(document.getElementById('operationModal'));
});

// Navigation
function refreshPage() { window.location.reload(); }
function applyFilters() {
    const group = document.getElementById('groupFilter').value;
    const search = document.getElementById('searchInput').value;
    const ipEl = document.getElementById('ipFilter');
    const ip = ipEl ? ipEl.value : '';
    const params = new URLSearchParams(window.location.search);
    if(group) params.set('group_id', group); else params.delete('group_id');
    if(search) params.set('search', search); else params.delete('search');
    if(ip) params.set('ip', ip); else params.delete('ip');
    params.set('page', 1);
    window.location.search = params.toString();
}
function searchDomains(e) { if(e.key === 'Enter') applyFilters(); }

// Bulk Actions
function getSelectedDomains() {
    return Array.from(document.querySelectorAll('.domain-checkbox:checked')).map(cb => cb.value);
}
async function bulkSyncDomains() {
    const domains = getSelectedDomains();
    if (!domains.length) { showToast('Не выбраны домены', 'warning'); return; }
    showToast('Синхронизирую ' + domains.length + ' доменов…', 'info');
    let ok = 0, fail = 0;
    for (let i = 0; i < domains.length; i++) {
        try {
            const fd = new URLSearchParams({ action: 'sync_domain', domain_id: domains[i] });
            const r = await fetch('sync_domains_api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd }).then(r => r.json());
            if (r && r.success) ok++; else fail++;
        } catch (e) { fail++; }
    }
    showToast('Синхронизировано: ' + ok + (fail ? ', ошибок: ' + fail : ''), fail ? 'warning' : 'success');
    setTimeout(() => location.reload(), 1000);
}
async function moveBulkToGroup() {
    const domains = getSelectedDomains();
    if (!domains.length) { showToast('Не выбраны домены', 'warning'); return; }
    let groupId = document.getElementById('bulkGroupSelect').value;
    const newName = document.getElementById('bulkNewGroup').value.trim();
    try {
        if (newName) {
            const cg = await fetch('bulk_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_group', name: newName }) }).then(r => r.json());
            if (!cg.success) { showToast('Ошибка создания группы: ' + (cg.error || ''), 'error'); return; }
            groupId = cg.group_id;
        }
        const res = await fetch('bulk_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'bulk_change_group', domain_ids: domains, group_id: groupId }) }).then(r => r.json());
        if (res.success) {
            showToast('Перемещено доменов: ' + res.affected + (newName ? ' → новая группа «' + newName + '»' : ''), 'success');
            document.getElementById('bulkNewGroup').value = '';
            setTimeout(() => location.reload(), 900);
        } else showToast('Ошибка: ' + (res.error || ''), 'error');
    } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
}

async function bulkUpdateDNS() {
    const domains = getSelectedDomains();
    if (!domains.length) return alert('Выберите домены');
    await addTaskToQueue('update_dns_ip', domains, 'Массовое обновление DNS IP');
}

async function bulkCheckSSL() {
    const domains = getSelectedDomains();
    if (!domains.length) return alert('Выберите домены');
    await addTaskToQueue('check_ssl_status', domains, 'Массовая проверка SSL');
}

async function bulkDeleteDomains() {
    const domains = getSelectedDomains();
    if (!domains.length) return alert('Выберите домены');
    if (!confirm('Удалить выбранные домены?')) return;
    
    // Simple implementation for demo
    for (let id of domains) {
        await fetch('delete_domain.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `domain_id=${id}`
        });
    }
    window.location.reload();
}

// Individual Actions
// NS-серверы, ВЫДАННЫЕ Cloudflare для зоны (их надо прописать у регистратора —
// не путать с текущими NS регистратора). Берём из объекта зоны через ns_api.php.
let _nsClipboard = '';
async function showCloudflareNS(id, name) {
    try {
        const res = await fetch('ns_api.php?domain_id=' + id);
        const data = await res.json();
        if (!data.success) { showToast(data.error || 'Не удалось получить NS', 'error'); return; }
        const rows = (data.ns_records || []).map(ns =>
            '<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1">' +
                '<code>' + ns + '</code>' +
                '<button class="btn btn-outline-secondary btn-sm" onclick="copyText(\'' + ns + '\', this)"><i class="fas fa-copy"></i></button>' +
            '</div>'
        ).join('');
        document.getElementById('nsModalKind').textContent = 'NS Cloudflare (целевые)';
        document.getElementById('nsModalDomain').textContent = name;
        document.getElementById('nsModalBody').innerHTML =
            '<p class="text-muted small mb-2">Пропишите эти nameservers у <b>регистратора</b> домена (вставляются по одному) — это NS, которые Cloudflare выдал для этой зоны:</p>' +
            rows;
        new bootstrap.Modal(document.getElementById('nsModal')).show();
    } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
}
function copyText(text, btn) {
    // navigator.clipboard есть только в secure context (https/localhost) и часто недоступен
    // в iframe/по http — тогда падало молча и в буфер ничего не попадало. Фоллбэк на execCommand.
    const ok = () => {
        showToast('Скопировано: ' + text, 'success');
        if (btn) { const o = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(() => btn.innerHTML = o, 1200); }
    };
    const fallback = () => {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, text.length);
            const done = document.execCommand('copy');
            document.body.removeChild(ta);
            if (done) ok(); else showToast('Не удалось скопировать — выделите и скопируйте вручную', 'error');
        } catch (e) {
            showToast('Не удалось скопировать: ' + e.message, 'error');
        }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok).catch(fallback);
    } else {
        fallback();
    }
}
// Живая проверка текущих NS домена (публичный DNS через DoH) — на месте ли делегирование на Cloudflare.
async function checkLiveNS(id, name) {
    showToast('Проверяю текущие NS…', 'info');
    try {
        const res = await fetch('ns_api.php?mode=live&domain_id=' + id);
        const data = await res.json();
        if (!data.success) { showToast(data.error || 'Ошибка', 'error'); return; }
        document.getElementById('nsModalKind').textContent = 'Текущие NS (живой DNS)';
        document.getElementById('nsModalDomain').textContent = name;
        let body = '';
        if (data.on_cloudflare) body += '<div class="alert alert-success py-2 mb-2"><i class="fas fa-circle-check me-1"></i>NS переключены на <b>Cloudflare</b> — делегирование на месте.</div>';
        else if ((data.live_ns || []).length) body += '<div class="alert alert-warning py-2 mb-2"><i class="fas fa-triangle-exclamation me-1"></i>NS <b>ещё НЕ на Cloudflare</b> (у регистратора стоят другие). Пропишите NS Cloudflare и подождите распространения.</div>';
        else body += '<div class="alert alert-secondary py-2 mb-2">' + (data.note || 'NS не найдены') + '</div>';
        body += '<p class="text-muted small mb-1">Текущие nameservers домена:</p>';
        body += (data.live_ns || []).length ? ('<ul class="mb-0">' + data.live_ns.map(ns => '<li><code>' + ns + '</code></li>').join('') + '</ul>') : '';
        document.getElementById('nsModalBody').innerHTML = body;
        new bootstrap.Modal(document.getElementById('nsModal')).show();
    } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
}

async function purgeDomainCache(id, name) {
    if (!confirm(`Очистить весь кэш Cloudflare для ${name}?`)) return;
    try {
        const body = new URLSearchParams({ domain_id: id, purge_everything: '1' });
        const res = await fetch('cache_api.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body });
        const json = await res.json();
        showToast(json.success ? `Кэш очищен: ${name}` : (json.error || 'Не удалось очистить кэш'), json.success ? 'success' : 'error');
    } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
}
// Синхронная синхронизация одного домена: IP + SSL-режим + статус (показывает результат)
async function syncDomainNow(id, name) {
    showToast(`Синхронизация ${name}…`, 'info');
    try {
        const body = new URLSearchParams({ action: 'sync_domain', domain_id: id });
        const res = await fetch('sync_domains_api.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body });
        const d = await res.json();
        if (!d.success) { showToast(d.error || 'Ошибка синхронизации', 'error'); return; }
        const st = d.domain_status === 'online' ? '🟢 online' : (d.domain_status === 'protected' ? '🛡 защищён' : '🔴 ' + (d.domain_status||'offline'));
        showToast(`${name}: ${st}, SSL ${d.ssl_mode||'—'}, IP ${d.dns_ip||'—'}`, d.domain_status === 'offline' ? 'warning' : 'success');
        setTimeout(() => location.reload(), 1500);
    } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
}
async function updateDNS(id) { await addTaskToQueue('update_dns_ip', [id], 'Обновление DNS'); }
async function checkSSL(id) { await addTaskToQueue('check_ssl_status', [id], 'Проверка SSL'); }
async function checkStatus(id) { await addTaskToQueue('check_domain_status', [id], 'Проверка статуса'); }

// [monopanel] Перевыпустить токен домену: выбрать живой мастер → сгенерить дочерний → перепривязать домен.
let _reissueModal = null;
async function reissueDomainToken(domainId, domainName) {
    if (!document.getElementById('reissueTokenModal')) {
        const wrap = document.createElement('div');
        wrap.innerHTML =
            '<div class="modal fade" id="reissueTokenModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-key me-2 text-warning"></i>Перевыпустить токен — <span id="reissueDomainName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
            '<div class="modal-body">' +
            '<p class="text-muted small"><b>Авто</b> — панель найдёт среди сохранённых аккаунтов тот, что реально управляет доменом, и перепривяжет к нему (новый токен не создаётся). Или выберите конкретный <b>мастер-токен</b> — тогда создастся новый дочерний токен. Панель перепривяжет домен <b>только если токен реально видит зону</b>.</p>' +
            '<label class="form-label small">Источник токена</label>' +
            '<select id="reissueMasterSelect" class="form-select mb-2"></select>' +
            '<div id="reissueOut" class="small"></div>' +
            '</div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>' +
            '<button type="button" class="btn btn-warning" id="reissueConfirmBtn"><i class="fas fa-key me-1"></i>Перевыпустить</button></div>' +
            '</div></div></div>';
        document.body.appendChild(wrap.firstElementChild);
    }
    document.getElementById('reissueDomainName').textContent = domainName;
    document.getElementById('reissueOut').innerHTML = '';
    const sel = document.getElementById('reissueMasterSelect');
    sel.innerHTML = '<option>Загрузка…</option>';
    _reissueModal = new bootstrap.Modal(document.getElementById('reissueTokenModal'));
    _reissueModal.show();
    try {
        const r = await fetch('master_token_api.php?action=list_masters').then(r => r.json());
        const autoOpt = '<option value="__auto__">🔍 Авто — найти среди аккаунтов (рекомендуется)</option>';
        const masterOpts = (r.success && r.masters) ? r.masters.map(m => `<option value="${m.id}">мастер: ${(m.label || ('#' + m.id))}${m.email ? (' (' + m.email + ')') : ''}</option>`).join('') : '';
        sel.innerHTML = autoOpt + masterOpts;
    } catch (e) { sel.innerHTML = '<option value="__auto__">🔍 Авто — найти среди аккаунтов</option>'; }
    const btn = document.getElementById('reissueConfirmBtn');
    btn.onclick = async function () {
        const val = sel.value;
        if (!val) { showToast('Выберите источник токена', 'warning'); return; }
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + (val === '__auto__' ? 'Ищу аккаунт…' : 'Перевыпускаю…');
        document.getElementById('reissueOut').innerHTML = '';
        try {
            const fd = new URLSearchParams(val === '__auto__'
                ? { action: 'reissue_domain_token', domain_id: domainId, mode: 'auto' }
                : { action: 'reissue_domain_token', domain_id: domainId, master_id: val });
            const res = await fetch('master_token_api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd }).then(r => r.json());
            if (res.success) {
                const how = res.mode === 'auto' ? ('привязан к аккаунту ' + (res.via || '')) : ('новый токен ' + (res.masked || ''));
                showToast('Домен ' + res.domain + ': DNS доступ восстановлен', 'success');
                document.getElementById('reissueOut').innerHTML = '<span class="text-success">Готово — ' + how + '. DNS доступ: есть ✓. Обновляю…</span>';
                setTimeout(function () { _reissueModal.hide(); location.reload(); }, 1300);
            } else {
                showToast('Ошибка: ' + (res.error || ''), 'error');
                document.getElementById('reissueOut').innerHTML = '<span class="text-danger">' + (res.error || 'ошибка') + '</span>';
            }
        } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-key me-1"></i>Перевыпустить';
    };
}

// [monopanel] Смена SSL-режима зоны для одного домена (Off/Flexible/Full/Full Strict).
let _sslModal = null;
function changeSslMode(domainId, domainName, current) {
    if (!document.getElementById('sslModeModal')) {
        const wrap = document.createElement('div');
        wrap.innerHTML =
            '<div class="modal fade" id="sslModeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-lock me-2 text-success"></i>SSL-режим — <span id="sslModeDomain"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
            '<div class="modal-body">' +
            '<p class="text-muted small">Режим шифрования Cloudflare для зоны (это <b>не</b> сертификат). «Full (Strict)» требует валидный сертификат на сервере; «Full» — принимает и самоподписанный.</p>' +
            '<label class="form-label small">Режим</label>' +
            '<select id="sslModeSelect" class="form-select mb-2">' +
            '<option value="off">Off (без шифрования)</option>' +
            '<option value="flexible">Flexible</option>' +
            '<option value="full">Full</option>' +
            '<option value="strict">Full (Strict)</option>' +
            '</select>' +
            '<div id="sslModeOut" class="small"></div>' +
            '</div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>' +
            '<button type="button" class="btn btn-success" id="sslModeConfirmBtn"><i class="fas fa-check me-1"></i>Применить</button></div>' +
            '</div></div></div>';
        document.body.appendChild(wrap.firstElementChild);
    }
    document.getElementById('sslModeDomain').textContent = domainName;
    document.getElementById('sslModeOut').innerHTML = '';
    const sel = document.getElementById('sslModeSelect');
    // текущий режим: в БД strict/full_strict → strict
    let cur = (current || '').toLowerCase();
    if (cur === 'full_strict') cur = 'strict';
    if (['off', 'flexible', 'full', 'strict'].includes(cur)) sel.value = cur;
    _sslModal = new bootstrap.Modal(document.getElementById('sslModeModal'));
    _sslModal.show();
    const btn = document.getElementById('sslModeConfirmBtn');
    btn.onclick = async function () {
        const mode = sel.value;
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Применяю…';
        document.getElementById('sslModeOut').innerHTML = '';
        try {
            const fd = new URLSearchParams({ domain_id: domainId, mode: mode });
            const res = await fetch('ssl_mode_api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd }).then(r => r.json());
            if (res.success) {
                showToast(res.domain + ': SSL-режим → ' + res.mode, 'success');
                document.getElementById('sslModeOut').innerHTML = '<span class="text-success">Готово. Обновляю…</span>';
                setTimeout(function () { _sslModal.hide(); location.reload(); }, 1000);
            } else {
                showToast('Ошибка: ' + (res.error || ''), 'error');
                document.getElementById('sslModeOut').innerHTML = '<span class="text-danger">' + (res.error || 'ошибка') + '</span>';
            }
        } catch (e) { showToast('Ошибка: ' + e.message, 'error'); }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i>Применить';
    };
}

async function deleteDomain(id, name) {
    if (!confirm(`Удалить домен ${name}?`)) return;
    const res = await fetch('delete_domain.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `domain_id=${id}`
    });
    const json = await res.json();
    if (json.success) window.location.reload();
    else alert(json.error);
}

async function toggleUnderAttack(id, enable) {
    const action = enable ? 'under_attack_on' : 'under_attack_off';
    const res = await fetch('security_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `domain_id=${id}&action=${action}`
    });
    const json = await res.json();
    alert(json.success ? 'Успешно' : json.error);
}

// Queue Helper
async function addTaskToQueue(type, ids, title) {
    if (!confirm(`${title} для ${ids.length} доменов?`)) return;
    
    for (let id of ids) {
        await fetch('queue_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'add_task',
                task_type: type,
                domain_id: id,
                data: {}
            })
        });
    }
    
    if (confirm('Задачи добавлены. Открыть очередь?')) {
        window.open('queue_dashboard.php', '_blank');
    }
}

// Workers
function manageWorkers(id) {
    // Redirect to security manager with domain pre-selected or open modal
    // For now, simple alert as placeholder or redirect
    window.location.href = `security_rules_manager.php?domain_id=${id}#worker-manager`;
}

function openBulkWorkersModal() {
    window.location.href = `security_rules_manager.php#worker-manager`;
}

// Quick Group Change
async function changeGroup(selectElement, domainId) {
    const newGroupId = selectElement.value;
    const originalValue = selectElement.dataset.currentGroup;
    
    try {
        const formData = new FormData();
        formData.append('action', 'change_group');
        formData.append('domain_id', domainId);
        formData.append('group_id', newGroupId);
        
        const res = await fetch('bulk_api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await res.json();
        
        if (result.success) {
            // Update the current group data attribute
            selectElement.dataset.currentGroup = newGroupId;
            
            // Show brief success indicator
            selectElement.classList.add('border-success');
            setTimeout(() => {
                selectElement.classList.remove('border-success');
            }, 1500);
        } else {
            // Revert to original value
            selectElement.value = originalValue;
            alert('Ошибка: ' + (result.error || 'Не удалось изменить группу'));
        }
    } catch (e) {
        // Revert to original value
        selectElement.value = originalValue;
        alert('Ошибка сети: ' + e.message);
    }
}

// Bulk Group Change
async function bulkChangeGroup() {
    const domains = getSelectedDomains();
    if (!domains.length) return alert('Выберите домены');
    
    const groupId = prompt('Введите ID группы (или оставьте пустым для удаления из группы):');
    if (groupId === null) return; // Cancelled
    
    for (let id of domains) {
        const formData = new FormData();
        formData.append('action', 'change_group');
        formData.append('domain_id', id);
        formData.append('group_id', groupId);
        
        await fetch('bulk_api.php', {
            method: 'POST',
            body: formData
        });
    }
    
    window.location.reload();
}

// =====================
// Progressive Sync
// =====================
let syncModal = null;
let syncDomains = [];
let syncIndex = 0;
let syncStats = { online: 0, offline: 0, ssl: 0, changes: 0 };
let syncRunning = false;

function startProgressiveSync() {
    syncModal = new bootstrap.Modal(document.getElementById('progressiveSyncModal'));
    
    // Reset
    document.getElementById('syncStep1').classList.remove('d-none');
    document.getElementById('syncStep2').classList.add('d-none');
    document.getElementById('syncStep3').classList.add('d-none');
    
    syncModal.show();
}

async function beginSync() {
    const scope = document.getElementById('syncGroupSelect').value;

    // Если выбран конкретный аккаунт — сначала обнаружим и добавим НЕДОСТАЮЩИЕ зоны
    // этого аккаунта (чтобы синк увидел новые домены, созданные в Cloudflare).
    if (scope.indexOf('account:') === 0) {
        try {
            const impFd = new FormData();
            impFd.append('action', 'import_account');
            impFd.append('account_id', scope.slice(8));
            const impRes = await fetch('sync_domains_api.php', { method: 'POST', body: impFd });
            const impData = await impRes.json();
            if (impData.success && impData.imported > 0) {
                showToast('Найдено и добавлено новых доменов: ' + impData.imported, 'success');
            }
        } catch (e) { /* не блокируем синк */ }
    }

    // Получаем список доменов (фильтр по группе ИЛИ по аккаунту)
    const formData = new FormData();
    formData.append('action', 'get_domains');
    if (scope === 'unsynced') formData.append('unsynced', '1');
    else if (scope.indexOf('group:') === 0) formData.append('group_id', scope.slice(6));
    else if (scope.indexOf('account:') === 0) formData.append('account_id', scope.slice(8));
    else formData.append('group_id', 'all');
    
    const res = await fetch('sync_domains_api.php', {
        method: 'POST',
        body: formData
    });
    
    const data = await res.json();
    
    if (!data.success || !data.domains.length) {
        alert('Нет доменов для синхронизации');
        return;
    }
    
    syncDomains = data.domains;
    syncIndex = 0;
    syncStats = { online: 0, offline: 0, ssl: 0, changes: 0 };
    syncRunning = true;
    
    // Показываем прогресс
    document.getElementById('syncStep1').classList.add('d-none');
    document.getElementById('syncStep2').classList.remove('d-none');
    document.getElementById('syncTotal').textContent = syncDomains.length;
    document.getElementById('syncLog').innerHTML = '';
    
    // Блокируем кнопку закрытия
    document.getElementById('closeSyncModal').disabled = true;
    
    // Запускаем обработку
    processSyncDomains();
}

async function processSyncDomains() {
    if (syncIndex >= syncDomains.length || !syncRunning) {
        finishSync();
        return;
    }
    
    const domain = syncDomains[syncIndex];
    document.getElementById('currentDomain').textContent = domain.domain;
    
    try {
        const formData = new FormData();
        formData.append('action', 'sync_domain');
        formData.append('domain_id', domain.id);
        
        const res = await fetch('sync_domains_api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await res.json();
        
        // Обновляем статистику
        if (result.domain_status === 'online') syncStats.online++;
        else syncStats.offline++;
        
        if (result.ssl_has_active) syncStats.ssl++;
        if (result.changes && result.changes.length) syncStats.changes += result.changes.length;
        
        // Добавляем в лог
        addSyncLogEntry(domain.domain, result);
        
    } catch (e) {
        addSyncLogEntry(domain.domain, { success: false, errors: [e.message] });
    }
    
    syncIndex++;
    
    // Обновляем прогресс
    const percent = Math.round((syncIndex / syncDomains.length) * 100);
    document.getElementById('syncPercent').textContent = percent + '%';
    document.getElementById('syncProgressBar').style.width = percent + '%';
    document.getElementById('syncProcessed').textContent = syncIndex;
    document.getElementById('statOnline').textContent = syncStats.online;
    document.getElementById('statOffline').textContent = syncStats.offline;
    document.getElementById('statSSL').textContent = syncStats.ssl;
    document.getElementById('statChanges').textContent = syncStats.changes;
    
    // Небольшая задержка чтобы не перегрузить сервер и API
    await new Promise(resolve => setTimeout(resolve, 500));
    
    // Продолжаем
    processSyncDomains();
}

function addSyncLogEntry(domain, result) {
    const log = document.getElementById('syncLog');
    const li = document.createElement('li');
    li.className = 'list-group-item py-2';
    
    let statusBadge = '';
    if (result.domain_status === 'online') {
        statusBadge = '<span class="badge bg-success me-2">Online</span>';
    } else {
        statusBadge = '<span class="badge bg-danger me-2">Offline</span>';
    }
    
    let ipInfo = result.dns_ip ? `<code class="ms-2">${result.dns_ip}</code>` : '';
    let sslInfo = result.ssl_mode ? `<span class="badge bg-info ms-2">${result.ssl_mode}</span>` : '';
    
    let changesInfo = '';
    if (result.changes && result.changes.length) {
        changesInfo = `<div class="small text-warning mt-1"><i class="fas fa-exchange-alt me-1"></i>${result.changes.join(', ')}</div>`;
    }
    
    li.innerHTML = `
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <strong>${domain}</strong>
                ${statusBadge}${sslInfo}${ipInfo}
            </div>
            <small class="text-muted">HTTP ${result.http_code || '-'}</small>
        </div>
        ${changesInfo}
    `;
    
    // Добавляем в начало
    log.insertBefore(li, log.firstChild);
    
    // Ограничиваем количество записей
    while (log.children.length > 50) {
        log.removeChild(log.lastChild);
    }
}

function finishSync() {
    syncRunning = false;
    
    // Разблокируем кнопку закрытия
    document.getElementById('closeSyncModal').disabled = false;
    
    // Показываем результат
    document.getElementById('syncStep2').classList.add('d-none');
    document.getElementById('syncStep3').classList.remove('d-none');
    
    document.getElementById('finalCount').textContent = syncIndex;
    document.getElementById('finalOnline').textContent = syncStats.online;
    document.getElementById('finalOffline').textContent = syncStats.offline;
    document.getElementById('finalSSL').textContent = syncStats.ssl;
    document.getElementById('finalChanges').textContent = syncStats.changes;
}

// Остановка при закрытии модалки
document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('progressiveSyncModal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function() {
            syncRunning = false;
        });
    }
});
</script>

<?php include 'footer.php'; ?>