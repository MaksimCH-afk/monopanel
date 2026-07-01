<?php
/**
 * API для прогрессивной синхронизации доменов
 * Обновляет статус, SSL и DNS IP последовательно
 */

require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_domains':
        getDomains($pdo, $userId);
        break;
    case 'import_account':
        // Обнаружить и добавить недостающие зоны ОДНОГО аккаунта (перед синхронизацией).
        $accountId = (int)($_POST['account_id'] ?? 0);
        if (!$accountId) { echo json_encode(['success' => false, 'error' => 'Не указан аккаунт']); break; }
        $cr = $pdo->prepare("SELECT id, email, api_key, COALESCE(auth_type,'') auth FROM cloudflare_credentials WHERE id = ? AND user_id = ?");
        $cr->execute([$accountId, $userId]);
        $c = $cr->fetch();
        if (!$c) { echo json_encode(['success' => false, 'error' => 'Аккаунт не найден']); break; }
        $grp = $pdo->query("SELECT id FROM groups WHERE user_id = $userId ORDER BY id LIMIT 1")->fetchColumn();
        $imp = cfImportZonesForCredential($pdo, $userId, $c['id'], $c['email'], $c['api_key'], $grp ?: null);
        if (!empty($imp['count'])) logAction($pdo, $userId, 'Синк: добавлены новые домены аккаунта', $c['email'] . ', добавлено: ' . $imp['count']);
        echo json_encode(['success' => !empty($imp['ok']), 'imported' => $imp['count'] ?? 0, 'error' => $imp['error'] ?? null]);
        break;
    case 'sync_domain':
        syncDomain($pdo, $userId);
        break;
    case 'get_progress':
        getProgress($pdo, $userId);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

/**
 * Получить список доменов для синхронизации
 */
function getDomains($pdo, $userId) {
    $groupId = $_POST['group_id'] ?? null;
    
    $sql = "
        SELECT ca.id, ca.domain, ca.zone_id, cc.email, cc.api_key
        FROM cloudflare_accounts ca
        JOIN cloudflare_credentials cc ON ca.account_id = cc.id
        WHERE ca.user_id = ? AND ca.zone_id IS NOT NULL AND ca.zone_id != ''
    ";
    $params = [$userId];
    
    if ($groupId && $groupId !== 'all') {
        $sql .= " AND ca.group_id = ?";
        $params[] = $groupId;
    }
    // Фильтр по конкретному аккаунту (credential id)
    $accountId = $_POST['account_id'] ?? null;
    if ($accountId) {
        $sql .= " AND ca.account_id = ?";
        $params[] = (int)$accountId;
    }

    $sql .= " ORDER BY ca.domain ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $domains = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'domains' => $domains,
        'total' => count($domains)
    ]);
}

/**
 * Синхронизировать один домен
 */
function syncDomain($pdo, $userId) {
    $domainId = (int)($_POST['domain_id'] ?? 0);
    
    if (!$domainId) {
        echo json_encode(['success' => false, 'error' => 'Domain ID required']);
        return;
    }
    
    // Получаем данные домена
    $stmt = $pdo->prepare("
        SELECT ca.*, cc.email, cc.api_key
        FROM cloudflare_accounts ca
        JOIN cloudflare_credentials cc ON ca.account_id = cc.id
        WHERE ca.id = ? AND ca.user_id = ?
    ");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    
    if (!$domain) {
        echo json_encode(['success' => false, 'error' => 'Domain not found']);
        return;
    }
    
    $result = [
        'success' => true,
        'domain_id' => $domainId,
        'domain' => $domain['domain'],
        'dns_ip' => null,
        'proxied' => null,
        'ssl_mode' => null,
        'ssl_status' => null,
        'http_code' => null,
        'domain_status' => null,
        'changes' => [],
        'errors' => []
    ];
    
    $proxies = getProxies($pdo, $userId);
    $zoneId = $domain['zone_id'];
    
    // 1. Получаем DNS IP (все A-записи)
    try {
        $dnsResponse = cloudflareApiRequestDetailed(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$zoneId}/dns_records?type=A&per_page=100",
            'GET', [], $proxies, $userId
        );
        
        if ($dnsResponse['success'] && !empty($dnsResponse['data'])) {
            // origin IP и proxied — ТОЛЬКО по апекс-записи (name = домен), а не по всем A-записям.
            // Иначе A-запись поддомена (напр. dnd.domain) меняет origin IP и шлёт ложный алерт.
            $ips = [];
            $proxiedFlag = null;
            $records = is_array($dnsResponse['data']) ? $dnsResponse['data'] : [$dnsResponse['data']];
            foreach ($records as $record) {
                if (isset($record->content) && $record->content && ($record->name ?? '') === $domain['domain']) {
                    $ips[] = $record->content;
                    if ($proxiedFlag === null) $proxiedFlag = !empty($record->proxied) ? 1 : 0;
                }
            }
            // Фоллбэк: если апекс-A нет — берём первую A-запись.
            if (empty($ips)) {
                foreach ($records as $record) {
                    if (isset($record->content) && $record->content) {
                        $ips[] = $record->content;
                        $proxiedFlag = !empty($record->proxied) ? 1 : 0;
                        break;
                    }
                }
            }
            if (!empty($ips)) {
                $uniqueIps = array_unique($ips);
                $result['dns_ip'] = implode(', ', $uniqueIps);
                $result['proxied'] = $proxiedFlag;
                $result['a_records_count'] = count($records);

                if ($domain['dns_ip'] !== $result['dns_ip']) {
                    $result['changes'][] = "IP: {$domain['dns_ip']} → {$result['dns_ip']}";
                }
            }
        } elseif (empty($dnsResponse['success'])) {
            $result['errors'][] = 'DNS: ' . cfReadableError($dnsResponse);
        }
    } catch (Exception $e) {
        $result['errors'][] = 'DNS: ' . $e->getMessage();
    }
    
    // 2. Получаем SSL настройки
    try {
        $sslResponse = cloudflareApiRequestDetailed(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$zoneId}/settings/ssl",
            'GET', [], $proxies, $userId
        );
        
        if ($sslResponse['success'] && isset($sslResponse['data'])) {
            $sslData = $sslResponse['data'];
            $sslMode = null;
            
            // Робастное извлечение значения SSL mode
            if (is_object($sslData)) {
                if (isset($sslData->value)) {
                    $sslMode = $sslData->value;
                } else {
                    // Попробуем через get_object_vars
                    $sslVars = get_object_vars($sslData);
                    if (isset($sslVars['value'])) {
                        $sslMode = $sslVars['value'];
                    }
                }
            } elseif (is_array($sslData) && isset($sslData['value'])) {
                $sslMode = $sslData['value'];
            }
            
            if ($sslMode) {
                $result['ssl_mode'] = $sslMode;

                if ($domain['ssl_mode'] !== $result['ssl_mode']) {
                    $result['changes'][] = "SSL: {$domain['ssl_mode']} → {$result['ssl_mode']}";
                }
            }
        } elseif (empty($sslResponse['success'])) {
            $result['errors'][] = 'SSL: ' . cfReadableError($sslResponse);
        }
    } catch (Exception $e) {
        $result['errors'][] = 'SSL: ' . $e->getMessage();
    }
    
    // 3. Проверяем статус SSL сертификата
    try {
        $certResponse = cloudflareApiRequestDetailed(
            $pdo,
            $domain['email'],
            $domain['api_key'],
            "zones/{$zoneId}/ssl/certificate_packs?status=active",
            'GET', [], $proxies, $userId
        );
        
        if ($certResponse['success']) {
            $result['ssl_status'] = !empty($certResponse['data']) ? 'active' : 'none';
            $result['ssl_has_active'] = !empty($certResponse['data']) ? 1 : 0;
        }
    } catch (Exception $e) {
        $result['errors'][] = 'Certificate: ' . $e->getMessage();
    }
    
    // 4. Проверяем HTTP статус домена
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$domain['domain']}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_NOBODY => true,
            // Googlebot-UA: домены, настроенные на «Только Google», пропустят проверку
            // и покажут реальный статус, а не 403.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ]);
        
        // Добавляем прокси если есть (формат: IP:PORT@LOGIN:PASS)
        if (!empty($proxies)) {
            $proxyString = $proxies[array_rand($proxies)];
            if ($proxyString && preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):(\d+)@([^:@]+):(.+)$/', $proxyString, $matches)) {
                $proxyIp = $matches[1];
                $proxyPort = $matches[2];
                $proxyLogin = $matches[3];
                $proxyPass = $matches[4];
                
                curl_setopt($ch, CURLOPT_PROXY, "$proxyIp:$proxyPort");
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, "$proxyLogin:$proxyPass");
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result['http_code'] = $httpCode;
        // Классификация: 2xx/3xx — online; 401/403/429/503 — «защищён/ограничен»
        // (домен жив, но доступ ограничен правилами); прочее — offline.
        if ($httpCode >= 200 && $httpCode < 400) {
            $result['domain_status'] = 'online';
        } elseif (in_array($httpCode, [401, 403, 429, 503], true)) {
            $result['domain_status'] = 'protected';
        } elseif ($httpCode === 0) {
            $result['domain_status'] = 'offline';
        } else {
            $result['domain_status'] = 'offline';
        }

    } catch (Exception $e) {
        $result['errors'][] = 'HTTP: ' . $e->getMessage();
        $result['http_code'] = 0;
        $result['domain_status'] = 'error';
    }

    // Сверяем фактическое состояние «Только Google» в Cloudflare с записью панели
    // (правило могли применить/снять прямо в интерфейсе CF, минуя панель).
    try {
        if (!empty($zoneId)) {
            $og = cfDetectOnlyGoogle($pdo, $domain['email'], $domain['api_key'], $zoneId, $proxies, $userId);
            if ($og === true) {
                $ex = $pdo->prepare("SELECT 1 FROM security_rules WHERE user_id = ? AND domain_id = ? AND rule_type = 'only_google'");
                $ex->execute([$userId, $domainId]);
                if (!$ex->fetchColumn()) {
                    $pdo->prepare("INSERT INTO security_rules (user_id, domain_id, rule_type, rule_data, created_at) VALUES (?, ?, 'only_google', ?, datetime('now'))")
                        ->execute([$userId, $domainId, json_encode(['rules' => 2, 'source' => 'cf'])]);
                    $result['changes'][] = 'Только Google: обнаружено в CF';
                }
            } elseif ($og === false) {
                $del = $pdo->prepare("DELETE FROM security_rules WHERE user_id = ? AND domain_id = ? AND rule_type = 'only_google'");
                $del->execute([$userId, $domainId]);
                if ($del->rowCount() > 0) $result['changes'][] = 'Только Google: снято (нет в CF)';
            }
        }
    } catch (Exception $e) { /* реконсиляция не должна ронять синк */ }

    // Обновляем БД - проверяем наличие колонки http_code
    try {
        $updateSql = "
            UPDATE cloudflare_accounts SET
                dns_ip = COALESCE(?, dns_ip),
                proxied = COALESCE(?, proxied),
                ssl_mode = COALESCE(?, ssl_mode),
                ssl_has_active = COALESCE(?, ssl_has_active),
                http_code = ?,
                domain_status = ?,
                last_check = datetime('now'),
                ssl_last_check = datetime('now')
            WHERE id = ?
        ";

        $stmt = $pdo->prepare($updateSql);
        $stmt->execute([
            $result['dns_ip'],
            $result['proxied'],
            $result['ssl_mode'],
            $result['ssl_has_active'] ?? null,
            $result['http_code'],
            $result['domain_status'],
            $domainId
        ]);
    } catch (PDOException $e) {
        // Если нет колонки http_code, обновляем без неё
        if (strpos($e->getMessage(), 'http_code') !== false) {
            $updateSql = "
                UPDATE cloudflare_accounts SET
                    dns_ip = COALESCE(?, dns_ip),
                    ssl_mode = COALESCE(?, ssl_mode),
                    ssl_has_active = COALESCE(?, ssl_has_active),
                    domain_status = ?,
                    last_check = datetime('now'),
                    ssl_last_check = datetime('now')
                WHERE id = ?
            ";
            
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                $result['dns_ip'],
                $result['ssl_mode'],
                $result['ssl_has_active'] ?? null,
                $result['domain_status'],
                $domainId
            ]);
        } else {
            throw $e;
        }
    }
    
    echo json_encode($result);
}

/**
 * Получить текущий прогресс
 */
function getProgress($pdo, $userId) {
    // Подсчитываем статистику
    $stats = [];
    
    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId")->fetchColumn();
    $stats['online'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND domain_status = 'online'")->fetchColumn();
    $stats['offline'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND domain_status = 'offline'")->fetchColumn();
    $stats['active_ssl'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND ssl_has_active = 1")->fetchColumn();
    $stats['checked_today'] = $pdo->query("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = $userId AND date(last_check) = date('now')")->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}