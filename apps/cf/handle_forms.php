<?php
require_once 'config.php';
require_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Обработка массовых действий
    if (isset($_POST['bulk_action']) && !empty($_POST['bulk_action']) && !empty($_POST['selected_domains'])) {
        $action = $_POST['bulk_action'];
        $selected = $_POST['selected_domains'];
        $successCount = 0;
        
        foreach ($selected as $id) {
            $stmt = $pdo->prepare("
                SELECT ca.*, cc.email, cc.api_key 
                FROM cloudflare_accounts ca 
                JOIN cloudflare_credentials cc ON ca.account_id = cc.id 
                WHERE ca.id = ? AND ca.user_id = ?
            ");
            $stmt->execute([$id, $_SESSION['user_id']]);
            $domain = $stmt->fetch();
            
            if (!$domain) {
                continue;
            }
            
            $settings = [];
            switch ($action) {
                case 'enable_https':
                    $settings['always_use_https'] = true;
                    break;
                case 'disable_https':
                    $settings['always_use_https'] = false;
                    break;
                case 'set_tls_10':
                    $settings['min_tls_version'] = '1.0';
                    break;
                case 'set_tls_12':
                    $settings['min_tls_version'] = '1.2';
                    break;
                case 'set_tls_13':
                    $settings['min_tls_version'] = '1.3';
                    break;
                case 'set_ssl_off':
                    $settings['ssl_mode'] = 'off';
                    break;
                case 'set_ssl_flexible':
                    $settings['ssl_mode'] = 'flexible';
                    break;
                case 'set_ssl_full':
                    $settings['ssl_mode'] = 'full';
                    break;
                case 'set_ssl_strict':
                    $settings['ssl_mode'] = 'strict';
                    break;
                default:
                    continue 2;
            }
            
            $proxies = getProxies($pdo, $_SESSION['user_id']);
            $result = updateCloudflareSettings($pdo, $id, $domain['email'], $domain['api_key'], $domain['domain'], $settings, $proxies);
            
            if ($result) {
                $successCount++;
                logAction($pdo, $_SESSION['user_id'], "Bulk Action Applied", "Action: $action, Domain: {$domain['domain']}");
            }
        }
        
        header("Location: " . BASE_PATH . "dashboard.php?notification=Обновлено настроек для $successCount доменов");
        exit;
    }

    // Добавление группы
    if (isset($_POST['add_group'])) {
        $groupName = trim($_POST['group_name']);
        if (!empty($groupName)) {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO groups (user_id, name) VALUES (?, ?)");
            $stmt->execute([$_SESSION['user_id'], $groupName]);
            logAction($pdo, $_SESSION['user_id'], "Group Added", "Group: $groupName");
            header('Location: ' . BASE_PATH . 'dashboard.php?notification=Группа добавлена');
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=Введите название группы');
        }
        exit;
    }
    
    // Удаление группы
    if (isset($_POST['delete_group']) && !empty($_POST['group_id'])) {
        $groupId = (int)$_POST['group_id'];
        
        $stmt = $pdo->prepare("SELECT name FROM groups WHERE id = ? AND user_id = ?");
        $stmt->execute([$groupId, $_SESSION['user_id']]);
        $groupName = $stmt->fetchColumn();
        
        if ($groupName) {
            $updateStmt = $pdo->prepare("UPDATE cloudflare_accounts SET group_id = NULL WHERE group_id = ? AND user_id = ?");
            $updateStmt->execute([$groupId, $_SESSION['user_id']]);
            
            $deleteStmt = $pdo->prepare("DELETE FROM groups WHERE id = ? AND user_id = ?");
            $deleteStmt->execute([$groupId, $_SESSION['user_id']]);
            
            logAction($pdo, $_SESSION['user_id'], "Group Deleted", "Group: $groupName");
            header('Location: ' . BASE_PATH . 'dashboard.php?notification=Группа удалена');
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=Группа не найдена');
        }
        exit;
    }
    
    // Добавление одного домена
    if (isset($_POST['add_domain'])) {
        $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $domain = mb_strtolower(trim($_POST['domain']));
        $serverIp = trim($_POST['server_ip']);
        $enableHttps = isset($_POST['enable_https']) ? 1 : 0;
        $minTls = isset($_POST['enable_tls13']) ? '1.3' : '1.0';

        if ($groupId && $accountId && $domain && $serverIp) {
            try {
                // ИСПРАВЛЕНО: Получаем данные аккаунта для API запросов
                $accStmt = $pdo->prepare("SELECT email, api_key FROM cloudflare_credentials WHERE id = ? AND user_id = ?");
                $accStmt->execute([$accountId, $_SESSION['user_id']]);
                $account = $accStmt->fetch();
                
                if (!$account) {
                    header('Location: ' . BASE_PATH . 'dashboard.php?error=Аккаунт не найден');
                    exit;
                }
                
                // ИСПРАВЛЕНО: Проверяем есть ли уже зона в Cloudflare
                $proxies = getProxies($pdo, $_SESSION['user_id']);
                $existingZones = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones?name=$domain", 'GET', [], $proxies, $_SESSION['user_id']);
                
                $zoneId = null;
                $sslMode = 'flexible';
                $alwaysHttps = 0;
                $minTlsVersion = '1.0';
                
                if ($existingZones['success'] && !empty($existingZones['data'])) {
                    // Зона уже существует - получаем её данные
                    $zone = $existingZones['data'][0];
                    $zoneId = $zone->id;
                    
                    logAction($pdo, $_SESSION['user_id'], "Domain Add - Zone Exists", "Domain: $domain, Zone ID: $zoneId");
                    
                    // Получаем актуальные SSL настройки из Cloudflare
                    $sslResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/ssl", 'GET', [], $proxies, $_SESSION['user_id']);
                    if ($sslResponse['success'] && isset($sslResponse['data'])) {
                        $sslMode = $sslResponse['data']->value ?? 'flexible';
                    }
                    
                    $httpsResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/always_use_https", 'GET', [], $proxies, $_SESSION['user_id']);
                    if ($httpsResponse['success'] && isset($httpsResponse['data'])) {
                        $alwaysHttps = ($httpsResponse['data']->value === 'on') ? 1 : 0;
                    }
                    
                    $tlsResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/min_tls_version", 'GET', [], $proxies, $_SESSION['user_id']);
                    if ($tlsResponse['success'] && isset($tlsResponse['data'])) {
                        $minTlsVersion = $tlsResponse['data']->value ?? '1.0';
                    }
                    
                } else {
                    // ИСПРАВЛЕНО: Создаем новую зону в Cloudflare с улучшенной обработкой ошибок
                    logAction($pdo, $_SESSION['user_id'], "Domain Add - Creating Zone", "Domain: $domain");
                    
                    $zoneData = [
                        'name' => $domain,
                        'jump_start' => false
                    ];
                    
                    $zoneResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones", 'POST', $zoneData, $proxies, $_SESSION['user_id']);
                    
                    if ($zoneResponse['success'] && isset($zoneResponse['data'])) {
                        $zoneId = $zoneResponse['data']->id;
                        logAction($pdo, $_SESSION['user_id'], "Domain Add - Zone Created", "Domain: $domain, Zone ID: $zoneId");
                        
                        // Устанавливаем начальные SSL настройки если нужно
                        if ($enableHttps) {
                            $httpsResult = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/always_use_https", 'PATCH', ['value' => 'on'], $proxies, $_SESSION['user_id']);
                            if ($httpsResult['success']) {
                                $alwaysHttps = 1;
                                logAction($pdo, $_SESSION['user_id'], "Domain Add - HTTPS Enabled", "Domain: $domain");
                            }
                        }
                        
                        if ($minTls !== '1.0') {
                            $tlsResult = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/min_tls_version", 'PATCH', ['value' => $minTls], $proxies, $_SESSION['user_id']);
                            if ($tlsResult['success']) {
                                $minTlsVersion = $minTls;
                                logAction($pdo, $_SESSION['user_id'], "Domain Add - TLS Updated", "Domain: $domain, TLS: $minTls");
                            }
                        }
                        
                    } else {
                        // УЛУЧШЕНО: Детальная обработка ошибок с API информацией
                        $errorMsg = 'Не удалось создать зону в Cloudflare';
                        
                        // Добавляем HTTP код ошибки
                        if ($zoneResponse['http_code'] > 0) {
                            $errorMsg .= " (HTTP: {$zoneResponse['http_code']})";
                        }
                        
                        // Добавляем cURL ошибки
                        if (!empty($zoneResponse['curl_error'])) {
                            $errorMsg .= " - cURL Error: {$zoneResponse['curl_error']}";
                        }
                        
                        // Добавляем ошибки API
                        if (!empty($zoneResponse['api_errors'])) {
                            $apiErrors = [];
                            foreach ($zoneResponse['api_errors'] as $error) {
                                $apiErrors[] = "[{$error['code']}] {$error['message']}";
                            }
                            $errorMsg .= " - API Errors: " . implode(', ', $apiErrors);
                        }
                        
                        // Добавляем сообщения API
                        if (!empty($zoneResponse['api_messages'])) {
                            $errorMsg .= " - Messages: " . implode(', ', $zoneResponse['api_messages']);
                        }
                        
                        // Если нет детальной информации, показываем raw response
                        if (empty($zoneResponse['api_errors']) && empty($zoneResponse['curl_error']) && !empty($zoneResponse['raw_response'])) {
                            $shortResponse = substr($zoneResponse['raw_response'], 0, 200);
                            $errorMsg .= " - Response: $shortResponse";
                        }
                        
                        logAction($pdo, $_SESSION['user_id'], "Domain Add - Zone Creation Failed", "Domain: $domain, Error: $errorMsg");
                        header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode($errorMsg));
                        exit;
                    }
                }
                
                // Базовые DNS-записи на выбранный сервер (A @, A *, CNAME www) — по галочке
                $dnsNote = '';
                if ($zoneId && $serverIp && (!isset($_POST['create_dns']) || $_POST['create_dns'])) {
                    $dnsRes = cfCreateOriginDnsRecords($pdo, $account['email'], $account['api_key'], $zoneId, $domain, $serverIp, $proxies, $_SESSION['user_id']);
                    logAction($pdo, $_SESSION['user_id'], "Domain DNS-записи", "Domain: $domain, создано: {$dnsRes['created']}/3" . ($dnsRes['errors'] ? "; ошибки: " . implode('; ', $dnsRes['errors']) : ''));
                    $dnsNote = ", DNS-записей создано: {$dnsRes['created']}/3";
                }

                // ИСПРАВЛЕНО: Добавляем домен в базу с реальными данными из Cloudflare
                $stmt = $pdo->prepare("
                    INSERT OR IGNORE INTO cloudflare_accounts
                    (user_id, account_id, group_id, domain, server_ip, always_use_https, min_tls_version, ssl_mode, zone_id, dns_ip)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $accountId, $groupId, $domain, $serverIp, $alwaysHttps, $minTlsVersion, $sslMode, $zoneId, $serverIp]);
                
                if ($stmt->rowCount() > 0) {
                    logAction($pdo, $_SESSION['user_id'], "Domain Added Successfully", "Domain: $domain, Zone ID: $zoneId, SSL: $sslMode, HTTPS: $alwaysHttps, TLS: $minTlsVersion");
                    header('Location: ' . BASE_PATH . 'dashboard.php?notification=' . urlencode('Домен добавлен в Cloudflare и базу данных' . $dnsNote));
                } else {
                    header('Location: ' . BASE_PATH . 'dashboard.php?error=Домен уже существует в базе данных');
                }
                
            } catch (Exception $e) {
                $errorMsg = 'Ошибка при добавлении домена: ' . $e->getMessage();
                logAction($pdo, $_SESSION['user_id'], "Domain Add Exception", "Domain: $domain, Error: $errorMsg");
                header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode($errorMsg));
            }
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=Заполните все поля');
        }
        exit;
    }
    
    // Массовое добавление доменов
    if (isset($_POST['add_domains_bulk'])) {
        $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $domainsList = explode("\n", trim($_POST['domains_list']));
        $enableHttps = isset($_POST['enable_https']) ? 1 : 0;
        $minTls = isset($_POST['enable_tls13']) ? '1.3' : '1.0';
        $successCount = 0;
        $errorCount = 0;
        $duplicateCount = 0;
        $errors = [];

        if ($groupId && $accountId) {
            $stmt = $pdo->prepare("
                INSERT OR IGNORE INTO cloudflare_accounts 
                (user_id, account_id, group_id, domain, server_ip, always_use_https, min_tls_version, ssl_mode, zone_id, dns_ip) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($domainsList as $domainData) {
                $domainData = trim($domainData);
                if (empty($domainData)) continue;
                
                // Проверяем формат: domain;server_ip
                if (strpos($domainData, ';') === false) {
                    $errorCount++;
                    $errors[] = "Неверный формат: $domainData (ожидается domain;server_ip)";
                    continue;
                }
                
                list($domain, $serverIp) = explode(';', $domainData, 2);
                $domain = mb_strtolower(trim($domain));
                $serverIp = trim($serverIp);
                
                // Валидация домена
                if (empty($domain)) {
                    $errorCount++;
                    $errors[] = "Пустое имя домена в строке: $domainData";
                    continue;
                }
                
                // Валидация IP адреса
                if (!filter_var($serverIp, FILTER_VALIDATE_IP)) {
                    $errorCount++;
                    $errors[] = "Неверный IP адрес '$serverIp' для домена '$domain'";
                    continue;
                }
                
                try {
                    // Проверяем, существует ли уже такой домен
                    $checkStmt = $pdo->prepare("SELECT id FROM cloudflare_accounts WHERE user_id = ? AND domain = ?");
                    $checkStmt->execute([$_SESSION['user_id'], $domain]);
                    
                    if ($checkStmt->fetch()) {
                        $duplicateCount++;
                        continue;
                    }
                    
                    // ИСПРАВЛЕНО: Получаем данные аккаунта для API запросов
                    $accStmt = $pdo->prepare("SELECT email, api_key FROM cloudflare_credentials WHERE id = ? AND user_id = ?");
                    $accStmt->execute([$accountId, $_SESSION['user_id']]);
                    $account = $accStmt->fetch();
                    
                    if (!$account) {
                        $errorCount++;
                        $errors[] = "Аккаунт не найден для домена '$domain'";
                        continue;
                    }
                    
                    // ИСПРАВЛЕНО: Проверяем есть ли уже зона в Cloudflare или создаем новую
                    $proxies = getProxies($pdo, $_SESSION['user_id']);
                    $existingZones = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones?name=$domain", 'GET', [], $proxies, $_SESSION['user_id']);
                    
                    $zoneId = null;
                    $sslMode = 'flexible';
                    $alwaysHttps = $enableHttps;
                    $minTlsVersion = $minTls;
                    
                    if ($existingZones['success'] && !empty($existingZones['data'])) {
                        // Зона уже существует - используем её
                        $zone = $existingZones['data'][0];
                        $zoneId = $zone->id;
                        
                        // Получаем актуальные SSL настройки
                        $sslResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/ssl", 'GET', [], $proxies, $_SESSION['user_id']);
                        if ($sslResponse['success'] && isset($sslResponse['data'])) {
                            $sslMode = $sslResponse['data']->value ?? 'flexible';
                        }
                        
                        $httpsResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/always_use_https", 'GET', [], $proxies, $_SESSION['user_id']);
                        if ($httpsResponse['success'] && isset($httpsResponse['data'])) {
                            $alwaysHttps = ($httpsResponse['data']->value === 'on') ? 1 : 0;
                        }
                        
                        $tlsResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/min_tls_version", 'GET', [], $proxies, $_SESSION['user_id']);
                        if ($tlsResponse['success'] && isset($tlsResponse['data'])) {
                            $minTlsVersion = $tlsResponse['data']->value ?? '1.0';
                        }
                        
                    } else {
                        // ИСПРАВЛЕНО: Создаем новую зону в Cloudflare с улучшенной обработкой ошибок
                        logAction($pdo, $_SESSION['user_id'], "Domain Add - Creating Zone", "Domain: $domain");
                        
                        $zoneData = [
                            'name' => $domain,
                            'jump_start' => false
                        ];
                        
                        $zoneResponse = cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones", 'POST', $zoneData, $proxies, $_SESSION['user_id']);
                        
                        if ($zoneResponse['success'] && isset($zoneResponse['data'])) {
                            $zoneId = $zoneResponse['data']->id;
                            
                            // Устанавливаем начальные SSL настройки
                            if ($enableHttps) {
                                cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/always_use_https", 'PATCH', ['value' => 'on'], $proxies, $_SESSION['user_id']);
                            }
                            
                            if ($minTls !== '1.0') {
                                cloudflareApiRequestDetailed($pdo, $account['email'], $account['api_key'], "zones/$zoneId/settings/min_tls_version", 'PATCH', ['value' => $minTls], $proxies, $_SESSION['user_id']);
                            }
                            
                        } else {
                            $errorCount++;
                            $errorMsg = "Не удалось создать зону в Cloudflare для '$domain'";
                            if (isset($zoneResponse['api_errors']) && is_array($zoneResponse['api_errors'])) {
                                $cfErrors = array_map(function($err) { return $err['message'] ?? 'Unknown'; }, $zoneResponse['api_errors']);
                                $errorMsg .= ': ' . implode(', ', $cfErrors);
                            }
                            $errors[] = $errorMsg;
                            continue;
                        }
                    }
                    
                    // ИСПРАВЛЕНО: Добавляем домен в базу с реальными данными из Cloudflare
                    $stmt->execute([$_SESSION['user_id'], $accountId, $groupId, $domain, $serverIp, $alwaysHttps, $minTlsVersion, $sslMode, $zoneId, $serverIp]);
                    
                    if ($stmt->rowCount() > 0) {
                        $successCount++;
                        logAction($pdo, $_SESSION['user_id'], "Domain Added (Bulk)", "Domain: $domain, IP: $serverIp, Zone ID: $zoneId, SSL: $sslMode");
                    }
                    
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = "Ошибка при добавлении домена '$domain': " . $e->getMessage();
                    logAction($pdo, $_SESSION['user_id'], "Domain Add Error (Bulk)", "Domain: $domain, Error: " . $e->getMessage());
                }
            }
            
            // Формируем сообщение о результатах
            $message = "Добавлено доменов: $successCount";
            if ($duplicateCount > 0) {
                $message .= ", дубликатов пропущено: $duplicateCount";
            }
            if ($errorCount > 0) {
                $message .= ", ошибок: $errorCount";
            }
            
            if (!empty($errors)) {
                $errorDetails = implode('; ', array_slice($errors, 0, 5)); // Показываем первые 5 ошибок
                if (count($errors) > 5) {
                    $errorDetails .= '...';
                }
                header("Location: " . BASE_PATH . "dashboard.php?notification=" . urlencode($message) . "&error=" . urlencode($errorDetails));
            } else {
                header("Location: " . BASE_PATH . "dashboard.php?notification=" . urlencode($message));
            }
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=Выберите аккаунт и группу');
        }
        exit;
    }
    
    // Добавление одного аккаунта
    if (isset($_POST['add_account'])) {
        $authType = ($_POST['auth_type'] ?? 'global') === 'token' ? 'token' : 'global';
        $email = trim($_POST['email'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;

        // Для Global API Key email обязателен; для токена — генерируем метку, если пусто
        if ($authType === 'global' && empty($email)) {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode('Для Global API Key укажите email'));
            exit;
        }
        if (empty($email)) {
            $email = 'token-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $apiKey), -8);
        }

        // Проверяем ключ и сразу получаем все зоны (с пагинацией)
        $proxies = getProxies($pdo, $_SESSION['user_id']);
        $zonesResult = cfFetchAllZones($pdo, $email, $apiKey, $proxies, $_SESSION['user_id'], $authType);

        if (!$zonesResult['success']) {
            logAction($pdo, $_SESSION['user_id'], "Account Add Failed", "Email: $email, Error: {$zonesResult['error']}");
            header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode('Не удалось добавить аккаунт: ' . $zonesResult['error']));
            exit;
        }

        $stmt = $pdo->prepare("INSERT OR IGNORE INTO cloudflare_credentials (user_id, email, api_key, auth_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $email, $apiKey, $authType]);
        $accountId = $pdo->lastInsertId();

        if ($accountId) {
            $domainStmt = $pdo->prepare("INSERT OR IGNORE INTO cloudflare_accounts (user_id, account_id, group_id, domain, server_ip, ssl_mode, zone_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $added = 0;
            foreach ($zonesResult['zones'] as $zone) {
                // ssl_mode оставляем NULL — реальный режим подтянет синхронизация
                $domainStmt->execute([$_SESSION['user_id'], $accountId, $groupId, $zone->name, '0.0.0.0', null, $zone->id]);
                $added++;
            }
            logAction($pdo, $_SESSION['user_id'], "Account Added", "Email: $email, Auth: $authType, Domains: $added");
            header('Location: ' . BASE_PATH . 'dashboard.php?notification=' . urlencode("Аккаунт добавлен, импортировано доменов: $added"));
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?notification=Аккаунт уже существует');
        }
        exit;
    }

    // Удаление аккаунта Cloudflare (вместе со всеми его доменами и связанными данными)
    if (isset($_POST['delete_account'])) {
        $accountId = (int)($_POST['account_id'] ?? 0);
        if ($accountId) {
            // Проверяем, что аккаунт принадлежит пользователю
            $chk = $pdo->prepare("SELECT email FROM cloudflare_credentials WHERE id = ? AND user_id = ?");
            $chk->execute([$accountId, $_SESSION['user_id']]);
            $acc = $chk->fetch();

            if ($acc) {
                // Собираем id доменов аккаунта для каскадной чистки зависимых таблиц
                $domStmt = $pdo->prepare("SELECT id FROM cloudflare_accounts WHERE account_id = ? AND user_id = ?");
                $domStmt->execute([$accountId, $_SESSION['user_id']]);
                $domainIds = $domStmt->fetchAll(PDO::FETCH_COLUMN);

                $pdo->beginTransaction();
                try {
                    if (!empty($domainIds)) {
                        $in = implode(',', array_fill(0, count($domainIds), '?'));
                        foreach (['queue', 'security_rules', 'cloudflare_firewall_rules', 'cloudflare_worker_routes'] as $tbl) {
                            try {
                                $pdo->prepare("DELETE FROM $tbl WHERE domain_id IN ($in)")->execute($domainIds);
                            } catch (Exception $e) { /* таблица может отсутствовать */ }
                        }
                    }
                    $pdo->prepare("DELETE FROM cloudflare_accounts WHERE account_id = ? AND user_id = ?")
                        ->execute([$accountId, $_SESSION['user_id']]);
                    $pdo->prepare("DELETE FROM cloudflare_credentials WHERE id = ? AND user_id = ?")
                        ->execute([$accountId, $_SESSION['user_id']]);
                    $pdo->commit();
                    logAction($pdo, $_SESSION['user_id'], "Account Deleted", "Email: {$acc['email']}, Domains removed: " . count($domainIds));
                    header('Location: ' . BASE_PATH . 'dashboard.php?notification=' . urlencode('Аккаунт удалён, доменов удалено: ' . count($domainIds)));
                } catch (Exception $e) {
                    $pdo->rollBack();
                    logAction($pdo, $_SESSION['user_id'], "Account Delete Failed", "Email: {$acc['email']}, Error: " . $e->getMessage());
                    header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode('Ошибка удаления аккаунта'));
                }
            } else {
                header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode('Аккаунт не найден'));
            }
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=' . urlencode('Не указан аккаунт'));
        }
        exit;
    }
    
    // Массовое добавление аккаунтов
    if (isset($_POST['add_accounts_bulk'])) {
        $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $accountsList = explode("\n", trim($_POST['accounts_list']));
        $successCount = 0;
        $errorCount = 0;
        $duplicateCount = 0;
        $errors = [];

        if ($groupId) {
            foreach ($accountsList as $accountData) {
                $accountData = trim($accountData);
                if (empty($accountData)) continue;
                
                // Проверяем формат: email;api_key
                if (strpos($accountData, ';') === false) {
                    $errorCount++;
                    $errors[] = "Неверный формат: $accountData (ожидается email;api_key)";
                    continue;
                }
                
                list($email, $apiKey) = explode(';', $accountData, 2);
                $email = trim($email);
                $apiKey = trim($apiKey);
                
                // Валидация email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorCount++;
                    $errors[] = "Неверный email: $email";
                    continue;
                }
                
                // Валидация API ключа
                if (strlen($apiKey) < 30) {
                    $errorCount++;
                    $errors[] = "API ключ слишком короткий для $email";
                    continue;
                }
                
                try {
                    // Проверяем, существует ли уже такой аккаунт
                    $checkStmt = $pdo->prepare("SELECT id FROM cloudflare_credentials WHERE user_id = ? AND email = ?");
                    $checkStmt->execute([$_SESSION['user_id'], $email]);
                    
                    if ($checkStmt->fetch()) {
                        $duplicateCount++;
                        continue;
                    }
                    
                    // Определяем тип авторизации и получаем все зоны (с пагинацией)
                    $proxies = getProxies($pdo, $_SESSION['user_id']);
                    $authType = cfDetectAuthType($email, $apiKey) === 'bearer' ? 'token' : 'global';
                    $zonesResult = cfFetchAllZones($pdo, $email, $apiKey, $proxies, $_SESSION['user_id'], $authType);

                    if (!$zonesResult['success']) {
                        $errorCount++;
                        $errors[] = "Ошибка при добавлении $email: " . $zonesResult['error'];
                        continue;
                    }

                    // Добавляем аккаунт
                    $stmt = $pdo->prepare("INSERT INTO cloudflare_credentials (user_id, email, api_key, auth_type) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $email, $apiKey, $authType]);
                    $accountId = $pdo->lastInsertId();

                    if ($accountId) {
                        if (!empty($zonesResult['zones'])) {
                            $domainStmt = $pdo->prepare("INSERT OR IGNORE INTO cloudflare_accounts (user_id, account_id, group_id, domain, server_ip, ssl_mode, zone_id) VALUES (?, ?, ?, ?, ?, ?, ?)");

                            foreach ($zonesResult['zones'] as $zone) {
                                // ssl_mode = NULL — реальный режим подтянет синхронизация
                                $domainStmt->execute([$_SESSION['user_id'], $accountId, $groupId, $zone->name, '0.0.0.0', null, $zone->id]);
                            }
                        }

                        $successCount++;
                        logAction($pdo, $_SESSION['user_id'], "Account Added (Bulk)", "Email: $email, Auth: $authType, Domains: " . count($zonesResult['zones']));
                    }
                    
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = "Ошибка при добавлении $email: " . $e->getMessage();
                    logAction($pdo, $_SESSION['user_id'], "Account Add Error (Bulk)", "Email: $email, Error: " . $e->getMessage());
                }
            }
            
            // Формируем сообщение о результатах
            $message = "Добавлено аккаунтов: $successCount";
            if ($duplicateCount > 0) {
                $message .= ", дубликатов пропущено: $duplicateCount";
            }
            if ($errorCount > 0) {
                $message .= ", ошибок: $errorCount";
            }
            
            if (!empty($errors)) {
                $errorDetails = implode('; ', array_slice($errors, 0, 5)); // Показываем первые 5 ошибок
                if (count($errors) > 5) {
                    $errorDetails .= '...';
                }
                header("Location: " . BASE_PATH . "dashboard.php?notification=" . urlencode($message) . "&error=" . urlencode($errorDetails));
            } else {
                header("Location: " . BASE_PATH . "dashboard.php?notification=" . urlencode($message));
            }
        } else {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=Выберите группу для аккаунтов');
        }
        exit;
    }
    
    // Смена группы для выбранных доменов
    if (isset($_POST['change_group']) && !empty($_POST['selected_domains']) && !empty($_POST['new_group_id'])) {
        $selected = $_POST['selected_domains'];
        $newGroupId = (int)$_POST['new_group_id'];
        $stmt = $pdo->prepare("UPDATE cloudflare_accounts SET group_id = ? WHERE id = ? AND user_id = ?");
        $successCount = 0;

        $groupStmt = $pdo->prepare("SELECT name FROM groups WHERE id = ? AND user_id = ?");
        $groupStmt->execute([$newGroupId, $_SESSION['user_id']]);
        $groupName = $groupStmt->fetchColumn();
        
        if (!$groupName) {
            header("Location: " . BASE_PATH . "dashboard.php?error=Группа не найдена");
            exit;
        }

        foreach ($selected as $id) {
            $stmt->execute([$newGroupId, $id, $_SESSION['user_id']]);
            $successCount += $stmt->rowCount();
        }
        
        header("Location: " . BASE_PATH . "dashboard.php?notification=Группа изменена для $successCount доменов");
        exit;
    }

    // Смена IP DNS для выбранных доменов
    if (isset($_POST['change_ip']) && !empty($_POST['selected_domains']) && !empty($_POST['new_ip'])) {
        $selected = $_POST['selected_domains'];
        $newIp = trim($_POST['new_ip']);
        
        if (!filter_var($newIp, FILTER_VALIDATE_IP)) {
            header('Location: ' . BASE_PATH . 'dashboard.php?error=Неверный IP-адрес');
            exit;
        }

        $successCount = 0;
        
        foreach ($selected as $id) {
            $stmt = $pdo->prepare("
                SELECT ca.*, cc.email, cc.api_key 
                FROM cloudflare_accounts ca 
                JOIN cloudflare_credentials cc ON ca.account_id = cc.id 
                WHERE ca.id = ? AND ca.user_id = ?
            ");
            $stmt->execute([$id, $_SESSION['user_id']]);
            $domain = $stmt->fetch();
            
            if (!$domain) continue;
            
            $proxies = getProxies($pdo, $_SESSION['user_id']);
            $settings = ['new_ip' => $newIp];
            $result = updateCloudflareSettings($pdo, $id, $domain['email'], $domain['api_key'], $domain['domain'], $settings, $proxies);
            
            if ($result) {
                $successCount++;
                logAction($pdo, $_SESSION['user_id'], "IP DNS Changed", "Domain: {$domain['domain']}, New IP: $newIp");
            }
        }
        
        header("Location: " . BASE_PATH . "dashboard.php?notification=IP DNS изменен для $successCount доменов");
        exit;
    }
}
?>