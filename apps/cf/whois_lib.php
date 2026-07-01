<?php
// WHOIS/RDAP-функции, общие для whois_api.php (UI) и monitor.php (фоновый рефреш).
require_once __DIR__ . '/functions.php';
function checkDomainWhois($pdo, $userId, $domainId) {
    // Get domain info
    $stmt = $pdo->prepare("SELECT id, domain FROM cloudflare_accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$domainId, $userId]);
    $domainRow = $stmt->fetch();
    
    if (!$domainRow) {
        return ['success' => false, 'error' => 'Домен не найден', 'domain_id' => $domainId];
    }
    
    $domain = $domainRow['domain'];
    
    // 1) Сначала классический WHOIS (порт 43)
    $whoisData = fetchWhoisData($domain);
    $source = 'whois';

    // 2) RDAP — ТОЛЬКО как fallback, если WHOIS не дал полезного (нет даты и регистратора).
    //    Не заменяем WHOIS, а дополняем, когда по нему данных нет.
    $whoisUseful = !empty($whoisData['success']) && (!empty($whoisData['expiry_date']) || !empty($whoisData['registrar']));
    if (!$whoisUseful) {
        $rdap = fetchRdapData($domain);
        if (!empty($rdap['success'])) {
            $whoisData = $rdap;
            $source = 'rdap';
        } elseif (empty($whoisData['success'])) {
            // Оба источника не дали данных
            $err = ($whoisData['error'] ?? 'нет данных') . '; RDAP: ' . ($rdap['error'] ?? 'нет данных');
            logAction($pdo, $userId, "WHOIS Check Failed", "Domain: {$domain}, Error: {$err}");
            return ['success' => false, 'error' => $err, 'domain_id' => $domainId, 'domain' => $domain];
        }
    }
    
    // Calculate days until expiry
    $daysUntilExpiry = null;
    if (!empty($whoisData['expiry_date'])) {
        $expiryTime = strtotime($whoisData['expiry_date']);
        if ($expiryTime) {
            $daysUntilExpiry = (int)floor(($expiryTime - time()) / 86400);
        }
    }
    
    // Update database
    $stmt = $pdo->prepare("
        UPDATE cloudflare_accounts
        SET whois_registrar = ?,
            whois_created_date = ?,
            whois_expiry_date = ?,
            whois_updated_date = ?,
            whois_registrant = ?,
            whois_name_servers = ?,
            whois_status = ?,
            whois_dnssec = ?,
            whois_abuse = ?,
            whois_source = ?,
            whois_last_check = datetime('now'),
            whois_days_until_expiry = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $whoisData['registrar'] ?? null,
        $whoisData['created_date'] ?? null,
        $whoisData['expiry_date'] ?? null,
        $whoisData['updated_date'] ?? null,
        $whoisData['registrant'] ?? null,
        !empty($whoisData['name_servers']) ? json_encode($whoisData['name_servers']) : null,
        $whoisData['status'] ?? null,
        $whoisData['dnssec'] ?? null,
        $whoisData['abuse'] ?? null,
        $source,
        $daysUntilExpiry,
        $domainId
    ]);

    logAction($pdo, $userId, "WHOIS Check Success", "Domain: {$domain}, Источник: " . strtoupper($source) . ", Expiry: {$whoisData['expiry_date']}, Days: {$daysUntilExpiry}");

    return [
        'success' => true,
        'domain_id' => $domainId,
        'domain' => $domain,
        'source' => $source,
        'registrar' => $whoisData['registrar'] ?? null,
        'created_date' => $whoisData['created_date'] ?? null,
        'expiry_date' => $whoisData['expiry_date'] ?? null,
        'updated_date' => $whoisData['updated_date'] ?? null,
        'registrant' => $whoisData['registrant'] ?? null,
        'name_servers' => $whoisData['name_servers'] ?? [],
        'status' => $whoisData['status'] ?? null,
        'dnssec' => $whoisData['dnssec'] ?? null,
        'abuse' => $whoisData['abuse'] ?? null,
        'days_until_expiry' => $daysUntilExpiry,
        'raw_data' => $whoisData['raw'] ?? null
    ];
}

/**
 * Bulk check WHOIS for multiple domains
 */
function bulkCheckWhois($pdo, $userId, $domainIds) {
    $results = [];
    $success = 0;
    $failed = 0;
    
    foreach ($domainIds as $domainId) {
        $result = checkDomainWhois($pdo, $userId, $domainId);
        $results[] = $result;
        
        if ($result['success']) {
            $success++;
        } else {
            $failed++;
        }
        
        // Rate limiting - 1 second between requests
        usleep(1000000);
    }
    
    return [
        'success' => true,
        'processed' => count($domainIds),
        'success_count' => $success,
        'failed_count' => $failed,
        'results' => $results
    ];
}

/**
 * Get list of domains with WHOIS data
 */
function getDomainsWithWhois($pdo, $userId, $filter = 'all') {
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
            $where .= " AND ca.whois_days_until_expiry <= 0";
            break;
        case 'no_data':
            $where .= " AND ca.whois_expiry_date IS NULL";
            break;
        case 'has_data':
            $where .= " AND ca.whois_expiry_date IS NOT NULL";
            break;
    }
    
    $stmt = $pdo->prepare("
        SELECT ca.id, ca.domain, ca.whois_registrar, ca.whois_created_date, 
               ca.whois_expiry_date, ca.whois_updated_date, ca.whois_registrant,
               ca.whois_name_servers, ca.whois_status, ca.whois_last_check,
               ca.whois_days_until_expiry, g.name as group_name
        FROM cloudflare_accounts ca
        LEFT JOIN groups g ON ca.group_id = g.id
        WHERE {$where}
        ORDER BY ca.whois_days_until_expiry ASC, ca.domain ASC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get domains expiring within N days
 */
function getExpiringDomains($pdo, $userId, $days = 30) {
    $stmt = $pdo->prepare("
        SELECT ca.id, ca.domain, ca.whois_registrar, ca.whois_expiry_date, 
               ca.whois_days_until_expiry, g.name as group_name
        FROM cloudflare_accounts ca
        LEFT JOIN groups g ON ca.group_id = g.id
        WHERE ca.user_id = ? 
        AND ca.whois_days_until_expiry IS NOT NULL
        AND ca.whois_days_until_expiry <= ?
        AND ca.whois_days_until_expiry > -30
        ORDER BY ca.whois_days_until_expiry ASC
    ");
    $stmt->execute([$userId, $days]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get WHOIS statistics
 */
function getWhoisStats($pdo, $userId) {
    $stats = [];
    
    // Total domains
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['total'] = (int)$stmt->fetchColumn();
    
    // Domains with WHOIS data
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_expiry_date IS NOT NULL");
    $stmt->execute([$userId]);
    $stats['with_data'] = (int)$stmt->fetchColumn();
    
    // Domains without WHOIS data
    $stats['without_data'] = $stats['total'] - $stats['with_data'];
    
    // Expiring in 30 days
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 30 AND whois_days_until_expiry > 0");
    $stmt->execute([$userId]);
    $stats['expiring_30'] = (int)$stmt->fetchColumn();
    
    // Expiring in 60 days
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 60 AND whois_days_until_expiry > 0");
    $stmt->execute([$userId]);
    $stats['expiring_60'] = (int)$stmt->fetchColumn();
    
    // Expiring in 90 days
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 90 AND whois_days_until_expiry > 0");
    $stmt->execute([$userId]);
    $stats['expiring_90'] = (int)$stmt->fetchColumn();
    
    // Expired domains
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cloudflare_accounts WHERE user_id = ? AND whois_days_until_expiry <= 0");
    $stmt->execute([$userId]);
    $stats['expired'] = (int)$stmt->fetchColumn();
    
    // Unique registrars
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT whois_registrar) FROM cloudflare_accounts WHERE user_id = ? AND whois_registrar IS NOT NULL");
    $stmt->execute([$userId]);
    $stats['unique_registrars'] = (int)$stmt->fetchColumn();
    
    return $stats;
}

/**
 * Fetch WHOIS data for a domain using socket connection
 */
function fetchWhoisData($domain) {
    // Extract the root domain
    $parts = explode('.', $domain);
    if (count($parts) < 2) {
        return ['success' => false, 'error' => 'Некорректное имя домена'];
    }
    
    $tld = strtolower(end($parts));
    $rootDomain = implode('.', array_slice($parts, -2));
    
    // WHOIS servers for different TLDs
    $whoisServers = [
        'com' => 'whois.verisign-grs.com',
        'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org',
        'info' => 'whois.afilias.net',
        'biz' => 'whois.biz',
        'us' => 'whois.nic.us',
        'ru' => 'whois.tcinet.ru',
        'su' => 'whois.tcinet.ru',
        'рф' => 'whois.tcinet.ru',
        'uk' => 'whois.nic.uk',
        'co.uk' => 'whois.nic.uk',
        'de' => 'whois.denic.de',
        'fr' => 'whois.nic.fr',
        'nl' => 'whois.domain-registry.nl',
        'eu' => 'whois.eu',
        'io' => 'whois.nic.io',
        'co' => 'whois.nic.co',
        'me' => 'whois.nic.me',
        'tv' => 'whois.nic.tv',
        'cc' => 'ccwhois.verisign-grs.com',
        'xyz' => 'whois.nic.xyz',
        'top' => 'whois.nic.top',
        'online' => 'whois.nic.online',
        'site' => 'whois.nic.site',
        'shop' => 'whois.nic.shop',
        'club' => 'whois.nic.club',
        'app' => 'whois.nic.google',
        'dev' => 'whois.nic.google',
        'pro' => 'whois.registrypro.pro',
        'website' => 'whois.nic.website',
        'space' => 'whois.nic.space',
        'tech' => 'whois.nic.tech',
        'store' => 'whois.nic.store',
        'pw' => 'whois.nic.pw',
        'asia' => 'whois.nic.asia',
        'name' => 'whois.nic.name',
        'mobi' => 'whois.dotmobiregistry.net'
    ];
    
    // Check for two-part TLDs
    if (count($parts) >= 3) {
        $twoPartTld = $parts[count($parts) - 2] . '.' . $tld;
        if (isset($whoisServers[$twoPartTld])) {
            $tld = $twoPartTld;
            $rootDomain = implode('.', array_slice($parts, -3));
        }
    }
    
    if (!isset($whoisServers[$tld])) {
        // Try generic whois server
        $whoisServers[$tld] = 'whois.iana.org';
    }
    
    $whoisServer = $whoisServers[$tld];
    $rawData = '';
    
    // Try to connect to WHOIS server
    $socket = @fsockopen($whoisServer, 43, $errno, $errstr, 10);
    
    if (!$socket) {
        return ['success' => false, 'error' => "Не удалось подключиться к WHOIS серверу: $errstr"];
    }
    
    // Send query
    fwrite($socket, $rootDomain . "\r\n");
    
    // Read response
    while (!feof($socket)) {
        $rawData .= fgets($socket, 128);
    }
    fclose($socket);
    
    if (empty($rawData)) {
        return ['success' => false, 'error' => 'Пустой ответ от WHOIS сервера'];
    }
    
    // Parse WHOIS data
    $result = parseWhoisData($rawData, $tld);
    $result['raw'] = $rawData;
    $result['success'] = true;
    
    return $result;
}

/**
 * Parse WHOIS response data
 */
/**
 * RDAP — современная замена WHOIS (HTTPS/JSON). Используется ТОЛЬКО как fallback,
 * когда классический WHOIS (порт 43) не дал полезных данных. Через rdap.org —
 * бутстрап-редиректор к авторитетному RDAP-серверу нужной зоны (нужен follow редиректов).
 * Возвращает тот же формат, что fetchWhoisData, плюс dnssec/abuse.
 */
function fetchRdapData($domain) {
    $parts = explode('.', $domain);
    if (count($parts) < 2) return ['success' => false, 'error' => 'Некорректное имя домена'];
    $rootDomain = implode('.', array_slice($parts, -2));

    $ch = curl_init("https://rdap.org/domain/" . urlencode($rootDomain));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,   // rdap.org отдаёт 302 на авторитетный сервер
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/rdap+json'],
        CURLOPT_USERAGENT => 'CloudPanel-RDAP',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        return ['success' => false, 'error' => "RDAP недоступен (HTTP $code)"];
    }
    $d = json_decode($body, true);
    if (!is_array($d)) return ['success' => false, 'error' => 'RDAP: некорректный ответ'];

    // Даты из events
    $created = $expiry = $updated = null;
    foreach ($d['events'] ?? [] as $ev) {
        $when = $ev['eventDate'] ?? null;
        switch ($ev['eventAction'] ?? '') {
            case 'registration': $created = $when; break;
            case 'expiration':   $expiry  = $when; break;
            case 'last changed': $updated = $when; break;
        }
    }
    // Хелпер: достать поле из vcardArray
    $vcardGet = function($entity, $field) {
        foreach (($entity['vcardArray'][1] ?? []) as $item) {
            if (($item[0] ?? '') === $field) return $item[3] ?? null;
        }
        return null;
    };
    // Регистратор + abuse-контакт (вложенная сущность с ролью abuse)
    $registrar = null; $abuse = null;
    foreach ($d['entities'] ?? [] as $ent) {
        $roles = $ent['roles'] ?? [];
        if (in_array('registrar', $roles, true)) {
            $registrar = $vcardGet($ent, 'fn') ?: $registrar;
            foreach ($ent['entities'] ?? [] as $sub) {
                if (in_array('abuse', $sub['roles'] ?? [], true)) {
                    $email = $vcardGet($sub, 'email');
                    $tel   = $vcardGet($sub, 'tel');
                    $abuse = trim(($email ?: '') . ($tel ? ' / ' . str_replace('tel:', '', $tel) : ''));
                }
            }
        }
    }
    $nameServers = [];
    foreach ($d['nameservers'] ?? [] as $ns) {
        if (!empty($ns['ldhName'])) $nameServers[] = strtolower($ns['ldhName']);
    }
    $status = !empty($d['status']) ? implode(', ', (array)$d['status']) : null;
    $dnssec = null;
    if (isset($d['secureDNS']['delegationSigned'])) {
        $dnssec = $d['secureDNS']['delegationSigned'] ? 'enabled' : 'disabled';
    }

    return [
        'success'      => !empty($expiry) || !empty($registrar),
        'registrar'    => $registrar,
        'created_date' => $created,
        'expiry_date'  => $expiry,
        'updated_date' => $updated,
        'registrant'   => null, // в RDAP почти всегда скрыт (redacted)
        'name_servers' => $nameServers,
        'status'       => $status,
        'dnssec'       => $dnssec,
        'abuse'        => $abuse ?: null,
        'source'       => 'rdap',
        'raw'          => $body,
        'error'        => null,
    ];
}

function parseWhoisData($raw, $tld) {
    $data = [
        'registrar' => null,
        'created_date' => null,
        'expiry_date' => null,
        'updated_date' => null,
        'registrant' => null,
        'name_servers' => [],
        'status' => null
    ];
    
    $lines = explode("\n", $raw);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '%') === 0 || strpos($line, '#') === 0) {
            continue;
        }
        
        // Different formats for different registrars
        $lower = strtolower($line);
        
        // Registrar
        if (preg_match('/^(registrar|sponsoring registrar|registrar name):\s*(.+)/i', $line, $m)) {
            $data['registrar'] = trim($m[2]);
        }
        
        // Creation date
        if (preg_match('/^(creation date|created|created on|registered|registration date|domain registered):\s*(.+)/i', $line, $m)) {
            $data['created_date'] = parseWhoisDate(trim($m[2]));
        }
        
        // Expiry date
        if (preg_match('/^(expir|expiration|registry expiry|registrar registration expiration|paid-till|free-date).*?:\s*(.+)/i', $line, $m)) {
            $data['expiry_date'] = parseWhoisDate(trim($m[2]));
        }
        
        // Updated date
        if (preg_match('/^(updated|updated date|last modified|last updated|last update):\s*(.+)/i', $line, $m)) {
            $data['updated_date'] = parseWhoisDate(trim($m[2]));
        }
        
        // Registrant
        if (preg_match('/^(registrant|registrant name|registrant organization):\s*(.+)/i', $line, $m)) {
            if (!$data['registrant']) {
                $data['registrant'] = trim($m[2]);
            }
        }
        
        // Name servers
        if (preg_match('/^(name server|nserver|name-server|ns\d?):\s*(.+)/i', $line, $m)) {
            $ns = strtolower(trim($m[2]));
            if (!in_array($ns, $data['name_servers'])) {
                $data['name_servers'][] = $ns;
            }
        }
        
        // Status
        if (preg_match('/^(status|domain status|state):\s*(.+)/i', $line, $m)) {
            $status = trim($m[2]);
            // Take first status (usually domain status)
            if (!$data['status'] || strlen($status) < strlen($data['status'])) {
                // Remove extra info after space
                $data['status'] = preg_replace('/\s+https?:\/\/.*$/', '', $status);
            }
        }
    }
    
    return $data;
}

/**
 * Parse WHOIS date to standard format
 */
function parseWhoisDate($dateStr) {
    if (empty($dateStr)) {
        return null;
    }
    
    // Remove timezone info
    $dateStr = preg_replace('/\s*([A-Z]{3,4}|[+-]\d{4}|Z)$/i', '', $dateStr);
    $dateStr = trim($dateStr);
    
    // Try standard formats
    $formats = [
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s',
        'Y-m-d',
        'd-M-Y',
        'd-m-Y',
        'd.m.Y',
        'Y/m/d',
        'd/m/Y',
        'M d Y',
        'd M Y',
        'D M d H:i:s Y',
        'Y-m-dTH:i:sZ'
    ];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateStr);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }
    
    // Try strtotime as fallback
    $timestamp = strtotime($dateStr);
    if ($timestamp !== false && $timestamp > 0) {
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}