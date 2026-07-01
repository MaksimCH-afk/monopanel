<?php
/**
 * Упрощенные массовые операции Cloudflare
 */

// Подавляем вывод ошибок и предупреждений для чистого JSON ответа
error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'functions.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Получаем домены пользователя
$stmt = $pdo->prepare("
    SELECT ca.id, ca.domain, ca.zone_id, ca.dns_ip, ca.ssl_mode, ca.always_use_https, 
           ca.min_tls_version, g.name as group_name, cc.email
    FROM cloudflare_accounts ca
    JOIN cloudflare_credentials cc ON ca.account_id = cc.id
    LEFT JOIN groups g ON ca.group_id = g.id
    WHERE ca.user_id = ?
    ORDER BY ca.domain ASC
");
$stmt->execute([$userId]);
$domains = $stmt->fetchAll();

// Обработка массовых операций
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Подавляем любые дополнительные ошибки для POST запросов
    error_reporting(0);
    ini_set('display_errors', 0);
    
    header('Content-Type: application/json');
    
    $selectedDomains = $_POST['domain_ids'] ?? [];
    if (empty($selectedDomains)) {
        echo json_encode(['success' => false, 'error' => 'Не выбраны домены']);
        exit;
    }
    
    // Декодируем JSON если нужно
    if (is_string($selectedDomains)) {
        $selectedDomains = json_decode($selectedDomains, true);
    }
    
    $results = [];
    $success = 0;
    $errors = 0;
    
    foreach ($selectedDomains as $domainId) {
        try {
            $result = performOperation($_POST['action'], $domainId, $_POST);
            $results[] = $result;
            if ($result['success']) {
                $success++;
            } else {
                $errors++;
            }
        } catch (Exception $e) {
            $results[] = ['success' => false, 'error' => $e->getMessage(), 'domain_id' => $domainId];
            $errors++;
        }
        
        // Задержка между операциями
        usleep(500000); // 0.5 секунды
    }
    
    echo json_encode([
        'success' => true,
        'processed' => count($selectedDomains),
        'success_count' => $success,
        'error_count' => $errors,
        'results' => $results
    ]);
    exit;
}

function performOperation($action, $domainId, $params) {
    global $pdo, $userId;
    
    // Получаем информацию о домене
    $stmt = $pdo->prepare("
        SELECT ca.*, cc.email, cc.api_key
        FROM cloudflare_accounts ca
        JOIN cloudflare_credentials cc ON ca.account_id = cc.id
        WHERE ca.id = ? AND ca.user_id = ?
    ");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    
    if (!$domain) {
        return ['success' => false, 'error' => 'Домен не найден', 'domain_id' => $domainId];
    }
    
    // ДОБАВЛЕНО: Логирование параметров для отладки
    logAction($pdo, $userId, "Mass Operation Request", "Action: $action, Domain: {$domain['domain']}, Params: " . json_encode($params));
    
    switch ($action) {
        case 'change_ip':
            return changeIP($domain, $params['new_ip'] ?? '');
            
        case 'change_ssl_mode':
            return changeSSLMode($domain, $params['ssl_mode'] ?? '');
            
        case 'change_https':
            return changeHTTPS($domain, $params['always_use_https'] ?? '');
            
        case 'change_tls':
            return changeTLS($domain, $params['min_tls_version'] ?? '');
            
        case 'delete_domain':
            return deleteDomainFromMass($domain);
            
        default:
            return ['success' => false, 'error' => 'Неизвестная операция', 'domain_id' => $domainId];
    }
}

function changeIP($domain, $newIP) {
    global $pdo, $userId;
    
    if (!$domain['zone_id']) {
        return ['success' => false, 'error' => 'Zone ID не найден', 'domain_id' => $domain['id']];
    }
    
    // Валидация IP адреса
    if (empty($newIP) || !filter_var($newIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['success' => false, 'error' => "Некорректный IPv4 адрес: '$newIP'", 'domain_id' => $domain['id']];
    }
    
    try {
        // Получаем прокси для API запроса
        $proxies = getProxies($pdo, $userId);
        
        logAction($pdo, $userId, "Mass IP Change Attempt", "Domain: {$domain['domain']}, New IP: '$newIP'");
        
        // Получаем ВСЕ A-записи для домена (включая поддомены)
        $dnsResponse = cloudflareApiRequest(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$domain['zone_id']}/dns_records?type=A&per_page=100",
            'GET',
            [],
            $proxies,
            $userId
        );
        
        if (!$dnsResponse || empty($dnsResponse->result)) {
            logAction($pdo, $userId, "Mass IP Change Failed", "Domain: {$domain['domain']}, Error: A-записи не найдены");
            return ['success' => false, 'error' => 'A-записи не найдены', 'domain_id' => $domain['id'], 'domain' => $domain['domain']];
        }
        
        $totalRecords = count($dnsResponse->result);
        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $recordNames = [];
        
        // Обновляем ВСЕ A-записи на новый IP (включая поддомены: www, mail, api, и т.д.)
        foreach ($dnsResponse->result as $record) {
            if ($record->type === 'A') {
                // Записи с тем же IP пропускаем
                if ($record->content === $newIP) {
                    $skippedCount++;
                    continue;
                }
                
                $updateResult = cloudflareApiRequest(
                    $pdo,
                    $domain['email'],
                    $domain['api_key'],
                    "zones/{$domain['zone_id']}/dns_records/{$record->id}",
                    'PATCH',
                    [
                        'content' => $newIP,
                        'name' => $record->name,
                        'type' => 'A',
                        'ttl' => $record->ttl ?? 1,
                        'proxied' => $record->proxied ?? false
                    ],
                    $proxies,
                    $userId
                );
                
                if ($updateResult && isset($updateResult->success) && $updateResult->success) {
                    $updatedCount++;
                    $recordNames[] = $record->name;
                } else {
                    $errorCount++;
                    logAction($pdo, $userId, "Mass IP Change Record Failed", "Domain: {$domain['domain']}, Record: {$record->name}, Error: API returned false");
                }
            }
        }
        
        if ($updatedCount > 0 || $skippedCount > 0) {
            // Обновляем IP в базе данных
            $stmt = $pdo->prepare("UPDATE cloudflare_accounts SET dns_ip = ? WHERE id = ?");
            $stmt->execute([$newIP, $domain['id']]);
            
            // Формируем детальное сообщение
            $message = "{$domain['domain']}: IP → {$newIP}";
            $details = [];
            if ($updatedCount > 0) $details[] = "обновлено: {$updatedCount}";
            if ($skippedCount > 0) $details[] = "уже имели этот IP: {$skippedCount}";
            if ($errorCount > 0) $details[] = "ошибок: {$errorCount}";
            if (!empty($details)) {
                $message .= " (" . implode(", ", $details) . ")";
            }
            
            // Логируем успешную операцию
            logAction($pdo, $userId, "Mass IP Change Success", "Domain: {$domain['domain']}, New IP: $newIP, Total A-records: $totalRecords, Updated: $updatedCount, Skipped: $skippedCount, Errors: $errorCount, Records: " . implode(", ", $recordNames));
            
            return [
                'success' => true,
                'message' => $message,
                'domain_id' => $domain['id'],
                'domain' => $domain['domain'],
                'new_ip' => $newIP,
                'total_records' => $totalRecords,
                'records_updated' => $updatedCount,
                'records_skipped' => $skippedCount,
                'errors' => $errorCount,
                'updated_names' => $recordNames
            ];
        } else if ($errorCount > 0) {
            logAction($pdo, $userId, "Mass IP Change Failed", "Domain: {$domain['domain']}, Error: Все $errorCount попыток обновления завершились с ошибкой");
            return ['success' => false, 'error' => "Не удалось обновить ни одну из $totalRecords DNS записей", 'domain_id' => $domain['id'], 'domain' => $domain['domain']];
        } else {
            // Все записи уже имеют этот IP
            return [
                'success' => true,
                'message' => "{$domain['domain']}: все {$totalRecords} A-записей уже имеют IP {$newIP}",
                'domain_id' => $domain['id'],
                'domain' => $domain['domain'],
                'new_ip' => $newIP,
                'already_set' => true
            ];
        }
        
    } catch (Exception $e) {
        logAction($pdo, $userId, "Mass IP Change Exception", "Domain: {$domain['domain']}, Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Ошибка API: ' . $e->getMessage(), 'domain_id' => $domain['id'], 'domain' => $domain['domain']];
    }
}

function changeSSLMode($domain, $sslMode) {
    global $pdo, $userId;
    
    if (!$domain['zone_id']) {
        return ['success' => false, 'error' => 'Zone ID не найден', 'domain_id' => $domain['id']];
    }
    
    try {
        $proxies = getProxies($pdo, $userId);
        
        // ИСПРАВЛЕНО: Валидация SSL режима
        $validSslModes = ['off', 'flexible', 'full', 'strict'];
        if (!in_array($sslMode, $validSslModes)) {
            return ['success' => false, 'error' => "Недопустимый SSL режим: $sslMode", 'domain_id' => $domain['id']];
        }
        
        logAction($pdo, $userId, "Mass SSL Mode Change Attempt", "Domain: {$domain['domain']}, SSL Mode: '$sslMode'");

        // Обновляем SSL режим через Cloudflare API (Detailed — чтобы получить текст ошибки)
        $result = cloudflareApiRequestDetailed(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$domain['zone_id']}/settings/ssl",
            'PATCH',
            ['value' => $sslMode],
            $proxies,
            $userId
        );

        if (!empty($result['success'])) {
            // Обновляем в базе данных
            $stmt = $pdo->prepare("UPDATE cloudflare_accounts SET ssl_mode = ? WHERE id = ?");
            $stmt->execute([$sslMode, $domain['id']]);

            logAction($pdo, $userId, "Mass SSL Mode Change Success", "Domain: {$domain['domain']}, New SSL Mode: $sslMode");

            return [
                'success' => true,
                'message' => "SSL режим изменен на $sslMode",
                'domain_id' => $domain['id'],
                'ssl_mode' => $sslMode
            ];
        } else {
            $errorMsg = 'Не удалось изменить SSL режим через API';
            if (!empty($result['api_errors'])) {
                $errors = array_map(function($err) { return $err['message'] ?? 'Unknown error'; }, $result['api_errors']);
                $errorMsg .= ': ' . implode(', ', $errors);
            } elseif (!empty($result['curl_error'])) {
                $errorMsg .= ': ' . $result['curl_error'];
            } elseif (!empty($result['http_code'])) {
                $errorMsg .= ' (HTTP ' . $result['http_code'] . ')';
            }

            logAction($pdo, $userId, "Mass SSL Mode Change Failed", "Domain: {$domain['domain']}, Error: $errorMsg");
            return ['success' => false, 'error' => $errorMsg, 'domain_id' => $domain['id']];
        }
        
    } catch (Exception $e) {
        logAction($pdo, $userId, "Mass SSL Mode Change Exception", "Domain: {$domain['domain']}, Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Ошибка API: ' . $e->getMessage(), 'domain_id' => $domain['id']];
    }
}

function changeHTTPS($domain, $alwaysUseHttps) {
    global $pdo, $userId;
    
    if (!$domain['zone_id']) {
        return ['success' => false, 'error' => 'Zone ID не найден', 'domain_id' => $domain['id']];
    }
    
    try {
        $proxies = getProxies($pdo, $userId);
        
        // ИСПРАВЛЕНО: Правильная обработка строковых значений
        // Преобразуем строковые значения в boolean, а затем в формат API
        $alwaysUseHttpsBool = ($alwaysUseHttps === '1' || $alwaysUseHttps === 1 || $alwaysUseHttps === true);
        $value = $alwaysUseHttpsBool ? 'on' : 'off';
        
        logAction($pdo, $userId, "Mass HTTPS Change Attempt", "Domain: {$domain['domain']}, Input: '$alwaysUseHttps', Bool: " . ($alwaysUseHttpsBool ? 'true' : 'false') . ", API Value: '$value'");
        
        // Обновляем Always Use HTTPS через Cloudflare API
        $result = cloudflareApiRequest(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$domain['zone_id']}/settings/always_use_https",
            'PATCH',
            ['value' => $value],
            $proxies,
            $userId
        );
        
        if ($result && isset($result->success) && $result->success) {
            // Обновляем в базе данных с правильным boolean значением
            $stmt = $pdo->prepare("UPDATE cloudflare_accounts SET always_use_https = ? WHERE id = ?");
            $stmt->execute([$alwaysUseHttpsBool ? 1 : 0, $domain['id']]);
            
            logAction($pdo, $userId, "Mass HTTPS Change Success", "Domain: {$domain['domain']}, Always Use HTTPS: $value");
            
            return [
                'success' => true,
                'message' => "Always Use HTTPS " . ($alwaysUseHttpsBool ? 'включен' : 'выключен'),
                'domain_id' => $domain['id'],
                'always_use_https' => $alwaysUseHttpsBool
            ];
        } else {
            $errorMsg = 'Не удалось изменить настройку HTTPS через API';
            if (isset($result->errors) && is_array($result->errors)) {
                $errors = array_map(function($err) { return $err->message ?? 'Unknown error'; }, $result->errors);
                $errorMsg .= ': ' . implode(', ', $errors);
            }
            
            logAction($pdo, $userId, "Mass HTTPS Change Failed", "Domain: {$domain['domain']}, Error: $errorMsg");
            return ['success' => false, 'error' => $errorMsg, 'domain_id' => $domain['id']];
        }
        
    } catch (Exception $e) {
        logAction($pdo, $userId, "Mass HTTPS Change Exception", "Domain: {$domain['domain']}, Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Ошибка API: ' . $e->getMessage(), 'domain_id' => $domain['id']];
    }
}

function changeTLS($domain, $minTlsVersion) {
    global $pdo, $userId;
    
    if (!$domain['zone_id']) {
        return ['success' => false, 'error' => 'Zone ID не найден', 'domain_id' => $domain['id']];
    }
    
    try {
        $proxies = getProxies($pdo, $userId);
        
        // ИСПРАВЛЕНО: Валидация TLS версии
        $validTlsVersions = ['1.0', '1.1', '1.2', '1.3'];
        if (!in_array($minTlsVersion, $validTlsVersions)) {
            return ['success' => false, 'error' => "Недопустимая версия TLS: $minTlsVersion", 'domain_id' => $domain['id']];
        }
        
        logAction($pdo, $userId, "Mass TLS Change Attempt", "Domain: {$domain['domain']}, TLS Version: '$minTlsVersion'");
        
        // Обновляем минимальную версию TLS через Cloudflare API
        $result = cloudflareApiRequest(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$domain['zone_id']}/settings/min_tls_version",
            'PATCH',
            ['value' => $minTlsVersion],
            $proxies,
            $userId
        );
        
        if ($result && isset($result->success) && $result->success) {
            // Обновляем в базе данных
            $stmt = $pdo->prepare("UPDATE cloudflare_accounts SET min_tls_version = ? WHERE id = ?");
            $stmt->execute([$minTlsVersion, $domain['id']]);
            
            logAction($pdo, $userId, "Mass TLS Change Success", "Domain: {$domain['domain']}, Min TLS Version: $minTlsVersion");
            
            return [
                'success' => true,
                'message' => "Минимальная версия TLS изменена на $minTlsVersion",
                'domain_id' => $domain['id'],
                'min_tls_version' => $minTlsVersion
            ];
        } else {
            $errorMsg = 'Не удалось изменить версию TLS через API';
            if (isset($result->errors) && is_array($result->errors)) {
                $errors = array_map(function($err) { return $err->message ?? 'Unknown error'; }, $result->errors);
                $errorMsg .= ': ' . implode(', ', $errors);
            }
            
            logAction($pdo, $userId, "Mass TLS Change Failed", "Domain: {$domain['domain']}, Error: $errorMsg");
            return ['success' => false, 'error' => $errorMsg, 'domain_id' => $domain['id']];
        }
        
    } catch (Exception $e) {
        logAction($pdo, $userId, "Mass TLS Change Exception", "Domain: {$domain['domain']}, Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Ошибка API: ' . $e->getMessage(), 'domain_id' => $domain['id']];
    }
}

function deleteDomainFromMass($domain) {
    global $pdo, $userId;
    
    try {
        // Начинаем транзакцию для безопасного удаления
        $pdo->beginTransaction();
        
        // Удаляем домен
        $deleteStmt = $pdo->prepare("DELETE FROM cloudflare_accounts WHERE id = ? AND user_id = ?");
        $deleteResult = $deleteStmt->execute([$domain['id'], $userId]);
        
        if (!$deleteResult || $deleteStmt->rowCount() === 0) {
            throw new Exception('Не удалось удалить домен из базы данных');
        }
        
        // Логируем операцию
        logAction($pdo, $userId, "Mass Delete Domain", "Domain deleted: {$domain['domain']} (Email: {$domain['email']})");
        
        // Подтверждаем транзакцию
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Домен {$domain['domain']} удален",
            'domain_id' => $domain['id'],
            'domain' => $domain['domain']
        ];
        
    } catch (Exception $e) {
        // Откатываем транзакцию при ошибке
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Ошибка при удалении: ' . $e->getMessage(), 'domain_id' => $domain['id']];
    }
}
?>

<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <!-- Заголовок -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">Массовые операции</h2>
            <p class="text-muted mb-0">Управление настройками для множества доменов</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Назад
        </a>
    </div>

    <!-- Статистика -->
    <!-- Быстрая замена IP по всем доменам с указанным IP -->
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0"><i class="fas fa-exchange-alt me-2"></i>Быстрая замена IP</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">Автоматически выбрать все домены с указанным IP и заменить его на новый</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Текущий IP (найти домены)</label>
                    <select id="sourceIP" class="form-select">
                        <option value="">— Выберите IP —</option>
                        <?php
                        // Собираем уникальные IP из всех доменов
                        $uniqueIPs = [];
                        foreach ($domains as $d) {
                            if (!empty($d['dns_ip']) && !in_array($d['dns_ip'], $uniqueIPs)) {
                                $uniqueIPs[] = $d['dns_ip'];
                            }
                        }
                        sort($uniqueIPs);
                        foreach ($uniqueIPs as $ip):
                            $ipCount = count(array_filter($domains, fn($d) => $d['dns_ip'] === $ip));
                        ?>
                            <option value="<?php echo htmlspecialchars($ip); ?>">
                                <?php echo htmlspecialchars($ip); ?> (<?php echo $ipCount; ?> доменов)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Новый IP (заменить на)</label>
                    <input type="text" id="targetIP" class="form-control" placeholder="Новый IPv4 адрес">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button class="btn btn-warning w-100 text-dark fw-bold" onclick="quickReplaceIP()">
                        <i class="fas fa-sync-alt me-2"></i>Заменить IP
                    </button>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Эта операция автоматически выберет все домены с указанным IP и заменит его на новый
                </small>
            </div>
        </div>
    </div>

    <!-- Статистика -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card bg-gradient-info">
                <div class="icon"><i class="fas fa-globe"></i></div>
                <div class="info">
                    <h3><?php echo count($domains); ?></h3>
                    <p>Всего доменов</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg-gradient-success">
                <div class="icon"><i class="fas fa-shield-alt"></i></div>
                <div class="info">
                    <h3><?php echo count(array_filter($domains, fn($d) => $d['ssl_mode'] !== 'off')); ?></h3>
                    <p>Защищено SSL</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg-gradient-primary">
                <div class="icon"><i class="fas fa-network-wired"></i></div>
                <div class="info">
                    <h3><?php echo count($uniqueIPs); ?></h3>
                    <p>Уникальных IP</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Выбор доменов -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Выбор доменов</h5>
                </div>
                <div class="card-body d-flex flex-column">
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" id="selectAll" class="form-check-input" onchange="toggleSelectAll()">
                            <label class="form-check-label fw-bold" for="selectAll">Выбрать все</label>
                        </div>
                    </div>
                    
                    <!-- Фильтр по IP -->
                    <div class="mb-3">
                        <select id="filterByIP" class="form-select form-select-sm" onchange="filterDomainsByIP()">
                            <option value="">Все IP адреса</option>
                            <?php foreach ($uniqueIPs as $ip):
                                $ipCount = count(array_filter($domains, fn($d) => $d['dns_ip'] === $ip));
                            ?>
                                <option value="<?php echo htmlspecialchars($ip); ?>">
                                    <?php echo htmlspecialchars($ip); ?> (<?php echo $ipCount; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex-grow-1 overflow-auto" style="max-height: 450px;">
                        <?php foreach ($domains as $domain): ?>
                            <div class="form-check mb-2 border-bottom pb-2 domain-item" data-ip="<?php echo htmlspecialchars($domain['dns_ip'] ?? ''); ?>">
                                <input class="form-check-input domain-checkbox" type="checkbox"
                                       value="<?php echo $domain['id']; ?>"
                                       id="domain-<?php echo $domain['id']; ?>"
                                       data-ip="<?php echo htmlspecialchars($domain['dns_ip'] ?? ''); ?>">
                                <label class="form-check-label w-100" for="domain-<?php echo $domain['id']; ?>">
                                    <div class="fw-bold"><?php echo htmlspecialchars($domain['domain']); ?></div>
                                    <small class="text-muted d-block">
                                        <?php echo htmlspecialchars($domain['group_name'] ?? 'Без группы'); ?>
                                        • <span class="badge bg-secondary"><?php echo htmlspecialchars($domain['dns_ip'] ?? '—'); ?></span>
                                    </small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center">
                        <small class="text-muted">Выбрано: <span id="selectedCount" class="fw-bold text-primary">0</span> доменов</small>
                        <button class="btn btn-sm btn-outline-primary" onclick="selectByIPPrompt()">
                            <i class="fas fa-filter me-1"></i>Выбрать по IP
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Операции -->
        <div class="col-md-8">
            <!-- Смена IP -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0 text-primary"><i class="fas fa-network-wired me-2"></i>Смена IP адресов</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <input type="text" id="newIP" class="form-control" placeholder="Новый IPv4 адрес (например, 1.2.3.4)">
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary w-100" onclick="changeIP()">
                                <i class="fas fa-play me-2"></i>Применить
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SSL настройки -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0 text-success"><i class="fas fa-lock me-2"></i>Настройки SSL/TLS</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label">Режим SSL</label>
                            <select id="sslMode" class="form-select">
                                <option value="off">Off (Отключено)</option>
                                <option value="flexible">Flexible</option>
                                <option value="full">Full</option>
                                <option value="strict" selected>Full (Strict)</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-success w-100" onclick="changeSSLMode()">
                                <i class="fas fa-check me-2"></i>Изменить
                            </button>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label">Always Use HTTPS</label>
                            <select id="httpsMode" class="form-select">
                                <option value="1" selected>Включить</option>
                                <option value="0">Выключить</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-success w-100" onclick="changeHTTPS()">
                                <i class="fas fa-check me-2"></i>Изменить
                            </button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Минимальная версия TLS</label>
                            <select id="tlsVersion" class="form-select">
                                <option value="1.0">TLS 1.0</option>
                                <option value="1.1">TLS 1.1</option>
                                <option value="1.2" selected>TLS 1.2</option>
                                <option value="1.3">TLS 1.3</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-success w-100" onclick="changeTLS()">
                                <i class="fas fa-check me-2"></i>Изменить
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Опасная зона -->
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Опасная зона</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold text-danger">Удаление доменов</h6>
                            <p class="text-muted mb-0 small">Удаляет выбранные домены из панели (не из Cloudflare)</p>
                        </div>
                        <button class="btn btn-outline-danger" onclick="deleteSelectedDomains()">
                            <i class="fas fa-trash me-2"></i>Удалить выбранные
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Лог операций -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Лог выполнения</h5>
        </div>
        <div class="card-body bg-dark text-light rounded-bottom p-0">
            <div class="progress rounded-0" style="height: 5px; display: none;" id="progressContainer">
                <div class="progress-bar bg-success" id="progressBar" style="width: 0%"></div>
            </div>
            <div id="operationLog" class="p-3" style="height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.9rem;">
                <div class="text-muted">Ожидание операций...</div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Управление выбором доменов
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.domain-checkbox');
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
        updateSelectedCount();
    }

    function updateSelectedCount() {
        const checked = document.querySelectorAll('.domain-checkbox:checked').length;
        document.getElementById('selectedCount').textContent = checked;
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('domain-checkbox')) {
            updateSelectedCount();
        }
    });

    // Логирование
    function addLog(message, type = 'info') {
        const log = document.getElementById('operationLog');
        if (log.querySelector('.text-muted')) log.innerHTML = '';
        
        const time = new Date().toLocaleTimeString();
        const color = type === 'success' ? 'text-success' : (type === 'error' ? 'text-danger' : 'text-info');
        
        const div = document.createElement('div');
        div.className = `mb-1 ${color}`;
        div.innerHTML = `<span class="text-secondary">[${time}]</span> ${message}`;
        
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function showProgress(current, total) {
        const container = document.getElementById('progressContainer');
        const bar = document.getElementById('progressBar');
        
        if (current === 0) container.style.display = 'flex';
        
        const percent = Math.round((current / total) * 100);
        bar.style.width = `${percent}%`;
        
        if (current >= total) {
            setTimeout(() => container.style.display = 'none', 1000);
        }
    }

    function getSelectedDomains() {
        return Array.from(document.querySelectorAll('.domain-checkbox:checked')).map(cb => cb.value);
    }

    // Последовательное выполнение операции (домен за доменом)
    async function performOperation(action, params = {}) {
        const domains = getSelectedDomains();
        if (!domains.length) return alert('Выберите домены');

        addLog(`🚀 Запуск операции для ${domains.length} доменов (последовательно)...`, 'info');
        showProgress(0, domains.length);
        
        let successCount = 0;
        let errorCount = 0;
        
        // Блокируем кнопки во время выполнения
        setButtonsDisabled(true);

        // Обрабатываем домены последовательно, по одному
        for (let i = 0; i < domains.length; i++) {
            const domainId = domains[i];
            const domainNum = i + 1;
            
            addLog(`⏳ [${domainNum}/${domains.length}] Обработка домена ID ${domainId}...`, 'info');
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('domain_ids', JSON.stringify([domainId])); // Только один домен
            Object.keys(params).forEach(key => formData.append(key, params[key]));

            try {
                const res = await fetch('mass_operations.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.success && json.results && json.results.length > 0) {
                    const r = json.results[0];
                    if (r.success) {
                        successCount++;
                        addLog(`✅ [${domainNum}/${domains.length}] ${r.message || r.domain || 'Успешно'}`, 'success');
                    } else {
                        errorCount++;
                        addLog(`❌ [${domainNum}/${domains.length}] ${r.domain || 'ID ' + domainId}: ${r.error}`, 'error');
                    }
                } else {
                    errorCount++;
                    addLog(`❌ [${domainNum}/${domains.length}] ID ${domainId}: ${json.error || 'Неизвестная ошибка'}`, 'error');
                }
            } catch (e) {
                errorCount++;
                addLog(`❌ [${domainNum}/${domains.length}] ID ${domainId}: Сбой сети - ${e.message}`, 'error');
            }
            
            // Обновляем прогресс
            showProgress(domainNum, domains.length);
            
            // Небольшая задержка между запросами (300ms)
            if (i < domains.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 300));
            }
        }
        
        // Разблокируем кнопки
        setButtonsDisabled(false);
        
        // Итоговый результат
        addLog(`🏁 Завершено! Успешно: ${successCount}, Ошибок: ${errorCount}`, successCount > 0 ? 'success' : 'error');
        
        if (action === 'delete_domain' && successCount > 0) {
            addLog(`🔄 Страница обновится через 2 секунды...`, 'info');
            setTimeout(() => location.reload(), 2000);
        }
    }
    
    // Блокировать/разблокировать кнопки во время операции
    function setButtonsDisabled(disabled) {
        document.querySelectorAll('.card-body button').forEach(btn => {
            btn.disabled = disabled;
            if (disabled) {
                btn.dataset.originalHtml = btn.innerHTML;
                if (!btn.innerHTML.includes('spinner')) {
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Ждите...';
                }
            } else if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
        });
    }

    // Wrappers
    function changeIP() {
        const ip = document.getElementById('newIP').value.trim();
        if (!ip) return alert('Введите IP');
        performOperation('change_ip', { new_ip: ip });
    }

    function changeSSLMode() { performOperation('change_ssl_mode', { ssl_mode: document.getElementById('sslMode').value }); }
    function changeHTTPS() { performOperation('change_https', { always_use_https: document.getElementById('httpsMode').value }); }
    function changeTLS() { performOperation('change_tls', { min_tls_version: document.getElementById('tlsVersion').value }); }
    
    function deleteSelectedDomains() {
        if (confirm('Удалить выбранные домены?')) performOperation('delete_domain');
    }

    // Фильтрация доменов по IP
    function filterDomainsByIP() {
        const filterIP = document.getElementById('filterByIP').value;
        const items = document.querySelectorAll('.domain-item');
        
        items.forEach(item => {
            const itemIP = item.dataset.ip;
            if (!filterIP || itemIP === filterIP) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // Выбрать все домены с определенным IP
    function selectByIP(ip) {
        const checkboxes = document.querySelectorAll('.domain-checkbox');
        let count = 0;
        
        checkboxes.forEach(cb => {
            if (cb.dataset.ip === ip) {
                cb.checked = true;
                count++;
            }
        });
        
        updateSelectedCount();
        addLog(`Выбрано ${count} доменов с IP ${ip}`, 'info');
        return count;
    }

    // Диалог выбора по IP
    function selectByIPPrompt() {
        const ip = prompt('Введите IP адрес для выбора доменов:');
        if (ip && ip.trim()) {
            selectByIP(ip.trim());
        }
    }

    // Быстрая замена IP (последовательная обработка)
    async function quickReplaceIP() {
        const sourceIP = document.getElementById('sourceIP').value;
        const targetIP = document.getElementById('targetIP').value.trim();
        
        if (!sourceIP) {
            alert('Выберите текущий IP из списка');
            return;
        }
        
        if (!targetIP) {
            alert('Введите новый IP адрес');
            return;
        }
        
        // Валидация IP
        const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
        if (!ipRegex.test(targetIP)) {
            alert('Новый IP адрес имеет некорректный формат');
            return;
        }
        
        if (sourceIP === targetIP) {
            alert('Текущий и новый IP адреса совпадают');
            return;
        }
        
        // Снимаем все выделения
        document.querySelectorAll('.domain-checkbox').forEach(cb => cb.checked = false);
        
        // Выбираем домены с исходным IP
        const selectedCount = selectByIP(sourceIP);
        
        if (selectedCount === 0) {
            alert('Не найдено доменов с указанным IP');
            return;
        }
        
        if (!confirm(`Заменить IP ${sourceIP} → ${targetIP} для ${selectedCount} доменов?\n\nДомены будут обработаны последовательно.`)) {
            return;
        }
        
        // Выполняем замену через последовательную обработку
        addLog(`🔄 Замена IP: ${sourceIP} → ${targetIP}`, 'info');
        
        // Используем общую функцию последовательной обработки
        await performOperation('change_ip', { new_ip: targetIP });
        
        // Обновляем страницу через 3 секунды для показа новых IP
        addLog(`🔄 Страница обновится через 3 секунды...`, 'info');
        setTimeout(() => location.reload(), 3000);
    }

    // При изменении sourceIP - подставляем его в newIP поле как пример
    document.getElementById('sourceIP')?.addEventListener('change', function() {
        const targetInput = document.getElementById('targetIP');
        if (this.value && !targetInput.value) {
            // Можно оставить пустым или подставить подсказку
        }
    });
</script>

<?php include 'footer.php'; ?>