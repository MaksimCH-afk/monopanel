<?php
/**
 * Security Rules API - Working Version
 * API для управления правилами безопасности
 */

// Показываем все ошибки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// JSON заголовок
header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'config.php';
    require_once 'functions.php';
    // Освобождаем session-lock: длинные операции (CF API) не должны держать сессию
    // и блокировать другие запросы браузера.
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    // Чтобы запрос не висел дольше клиентского таймаута (CF-вызовы и так ограничены).
    @set_time_limit(55);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка инициализации: ' . $e->getMessage()
    ]);
    exit;
}

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if (!$action) {
        throw new Exception('Не указано действие');
    }
    
    switch ($action) {
        case 'get_worker_template':
            $template = $_GET['template'] ?? '';
            $result = getWorkerTemplate($template);
            echo json_encode($result);
            break;
            
        case 'apply_bot_blocker':
            $result = applyBotBlocker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;
            
        case 'apply_ip_blocker':
            $result = applyIPBlocker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;
            
        case 'apply_geo_blocker':
            $result = applyGeoBlocker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;
            
        case 'apply_referrer_only':
            $result = applyReferrerOnly($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'apply_only_google':
            $result = applyOnlyGoogle($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'remove_only_google':
            $result = removeOnlyGoogle($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'deploy_worker':
            $result = deployWorker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;
            
        case 'deploy_worker_with_config':
            $result = deployWorkerWithConfig($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'deploy_custom_worker':
            $result = deployCustomWorker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'delete_custom_worker':
            $result = deleteCustomWorker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'get_custom_worker':
            $result = getCustomWorker($pdo, $userId, $_POST);
            echo json_encode($result);
            break;

        case 'debug_info':
            echo json_encode([
                'success' => true,
                'php_version' => PHP_VERSION,
                'session_id' => session_id(),
                'user_id' => $userId
            ]);
            break;
            
        default:
            throw new Exception('Неизвестное действие: ' . $action);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

// === ФУНКЦИИ ===

function getWorkerTemplate($template) {
    if (empty($template)) {
        return [
            'success' => false,
            'error' => 'Шаблон не указан',
            'available' => ['advanced-protection', 'bot-only', 'geo-only', 'referrer-only', 'rate-limit']
        ];
    }
    
    $templateFiles = [
        'advanced-protection' => '/worker_templates/advanced-protection.js',
        'bot-only' => '/worker_templates/bot-only.js',
        'geo-only' => '/worker_templates/geo-only.js',
        'referrer-only' => '/worker_templates/referrer-only.js',
        'rate-limit' => '/worker_templates/rate-limit.js',
        'gone-410' => '/worker_templates/gone-410.js',
        'not-found-404' => '/worker_templates/not-found-404.js'
    ];
    
    if (!isset($templateFiles[$template])) {
        return ['success' => false, 'error' => 'Шаблон не найден: ' . $template];
    }
    
    $filePath = __DIR__ . $templateFiles[$template];
    
    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => 'Файл не найден: ' . basename($filePath)];
    }
    
    $code = @file_get_contents($filePath);
    if ($code === false) {
        return ['success' => false, 'error' => 'Ошибка чтения файла'];
    }
    
    // Заменяем плейсхолдеры
    $replacements = [
        '{{BAD_BOTS_LIST}}' => '["semrush", "ahrefs", "mj12bot", "dotbot", "petalbot"]',
        '{{BLOCKED_IPS_LIST}}' => '["192.168.1.1", "10.0.0.1"]',
        '{{GEO_MODE}}' => 'whitelist',
        '{{ALLOWED_COUNTRIES_LIST}}' => '["RU", "US", "DE", "FR", "GB"]',
        '{{ALLOWED_REFERRERS_LIST}}' => '["google.", "yandex.", "bing.com"]',
        '{{URL_EXCEPTIONS_LIST}}' => '["/api/*", "/health", "/robots.txt"]',
        '{{BLOCKED_COUNTRIES_LIST}}' => '["CN", "KP"]',
    ];
    
    foreach ($replacements as $placeholder => $value) {
        $code = str_replace($placeholder, $value, $code);
    }
    
    return [
        'success' => true,
        'code' => $code,
        'template' => $template,
        'description' => getTemplateDescription($template)
    ];
}

function getTemplateDescription($template) {
    $descriptions = [
        'advanced-protection' => 'Полная защита: блокировка ботов, IP, геоблокировка, проверка реферреров',
        'bot-only' => 'Только блокировка плохих ботов и сканеров',
        'geo-only' => 'Только геоблокировка по странам',
        'referrer-only' => 'Только проверка реферреров (защита от прямого доступа)',
        'rate-limit' => 'Ограничение частоты запросов (Rate Limiting)'
    ];
    return $descriptions[$template] ?? 'Без описания';
}

function applyBotBlocker($pdo, $userId, $data) {
    $rules = $data['rules'] ?? [];
    $scope = $data['scope'] ?? [];
    
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    
    $applied = 0;
    $proxies = getProxies($pdo, $userId);
    $badBots = loadBadBotsList($rules);
    
    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("
            SELECT ca.*, cc.email, cc.api_key 
            FROM cloudflare_accounts ca 
            JOIN cloudflare_credentials cc ON ca.account_id = cc.id 
            WHERE ca.id = ? AND ca.user_id = ?
        ");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        
        if (!$domain || !$domain['zone_id']) continue;
        
        $expression = buildBotBlockExpression($badBots);

        $res = cfAddCustomRule($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], [
            'action' => 'block',
            'expression' => $expression,
            'description' => 'Auto Bot Blocker - CloudPanel'
        ], $proxies, $userId);

        if ($res['success']) {
            $applied++;
            saveSecurityRule($pdo, $userId, $domainId, 'bad_bot', $expression);
        }
    }
    
    return ['success' => true, 'applied' => $applied, 'total' => count($domainIds)];
}

function applyIPBlocker($pdo, $userId, $data) {
    $ips = $data['ips'] ?? [];
    $importKnown = $data['importKnown'] ?? false;
    $scope = $data['scope'] ?? [];
    
    if ($importKnown) {
        $ips = array_merge($ips, loadKnownBadIPs());
    }
    
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    
    if (empty($domainIds) || empty($ips)) {
        return ['success' => false, 'error' => 'Нет данных для применения'];
    }
    
    $applied = 0;
    $proxies = getProxies($pdo, $userId);
    
    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("
            SELECT ca.*, cc.email, cc.api_key 
            FROM cloudflare_accounts ca 
            JOIN cloudflare_credentials cc ON ca.account_id = cc.id 
            WHERE ca.id = ? AND ca.user_id = ?
        ");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        
        if (!$domain || !$domain['zone_id']) continue;
        
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (empty($ip)) continue;
            
            $accessRule = [
                'mode' => 'block',
                'configuration' => [
                    'target' => strpos($ip, '/') !== false ? 'ip_range' : 'ip',
                    'value' => $ip
                ],
                'notes' => 'Auto IP Block - CloudPanel'
            ];
            
            $response = cloudflareApiRequestDetailed(
                $pdo,
                $domain['email'],
                $domain['api_key'],
                "zones/{$domain['zone_id']}/firewall/access_rules/rules",
                'POST',
                $accessRule,
                $proxies,
                $userId
            );
            
            if ($response['success']) {
                saveSecurityRule($pdo, $userId, $domainId, 'ip_block', $ip);
            }
        }
        $applied++;
    }
    
    return ['success' => true, 'applied' => $applied, 'total' => count($domainIds), 'ips_blocked' => count($ips)];
}

function applyGeoBlocker($pdo, $userId, $data) {
    $mode = $data['mode'] ?? 'whitelist';
    $whitelist = $data['whitelist'] ?? [];
    $blacklist = $data['blacklist'] ?? [];
    $scope = $data['scope'] ?? [];
    
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    
    if ($mode === 'whitelist' && empty($whitelist)) {
        return ['success' => false, 'error' => 'Whitelist пуст'];
    }
    if ($mode === 'blacklist' && empty($blacklist)) {
        return ['success' => false, 'error' => 'Blacklist пуст'];
    }
    
    $applied = 0;
    $rulesCreated = 0;
    $proxies = getProxies($pdo, $userId);
    
    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("
            SELECT ca.*, cc.email, cc.api_key
            FROM cloudflare_accounts ca
            JOIN cloudflare_credentials cc ON ca.account_id = cc.id
            WHERE ca.id = ? AND ca.user_id = ?
        ");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        
        if (!$domain || !$domain['zone_id']) continue;
        
        $domainRulesCreated = 0;
        
        // Опция: не блокировать проверенные поисковые боты (Google/Bing/Yandex...)
        // Иначе whitelist «только страна X» заблокирует и поисковики (они краулят из других стран).
        $allowBots = !empty($data['allow_bots']);
        $botExcept = $allowBots ? ' and (not cf.client.bot)' : '';

        // Whitelist
        if (($mode === 'whitelist' || $mode === 'both') && !empty($whitelist)) {
            $codes = implode(' ', array_map(fn($c) => '"' . $c . '"', $whitelist));
            $expression = "(not ip.geoip.country in {{$codes}}{$botExcept})";

            $res = cfAddCustomRule($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], [
                'action' => 'block',
                'expression' => $expression,
                'description' => 'Geo Whitelist - CloudPanel'
            ], $proxies, $userId);

            if ($res['success']) {
                $domainRulesCreated++;
                $rulesCreated++;
                saveSecurityRule($pdo, $userId, $domainId, 'geo_whitelist', json_encode(['countries' => $whitelist]));
            }
        }
        
        // Blacklist
        if (($mode === 'blacklist' || $mode === 'both') && !empty($blacklist)) {
            $codes = implode(' ', array_map(fn($c) => '"' . $c . '"', $blacklist));
            $expression = "(ip.geoip.country in {{$codes}})";

            $res = cfAddCustomRule($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], [
                'action' => 'block',
                'expression' => $expression,
                'description' => 'Geo Blacklist - CloudPanel'
            ], $proxies, $userId);

            if ($res['success']) {
                $domainRulesCreated++;
                $rulesCreated++;
                saveSecurityRule($pdo, $userId, $domainId, 'geo_blacklist', json_encode(['countries' => $blacklist]));
            }
        }
        
        if ($domainRulesCreated > 0) $applied++;
    }
    
    return ['success' => true, 'applied' => $applied, 'total' => count($domainIds), 'rulesCreated' => $rulesCreated, 'mode' => $mode];
}

function applyReferrerOnly($pdo, $userId, $data) {
    $allowedReferrers = $data['allowedReferrers'] ?? [];
    $action = $data['action'] ?? 'block';
    $scope = $data['scope'] ?? [];
    
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    
    $applied = 0;
    $proxies = getProxies($pdo, $userId);
    $expression = buildReferrerExpression($allowedReferrers);
    
    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("
            SELECT ca.*, cc.email, cc.api_key 
            FROM cloudflare_accounts ca 
            JOIN cloudflare_credentials cc ON ca.account_id = cc.id 
            WHERE ca.id = ? AND ca.user_id = ?
        ");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        
        if (!$domain || !$domain['zone_id']) continue;
        
        $res = cfAddCustomRule($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], [
            'action' => $action === 'challenge' ? 'managed_challenge' : 'block',
            'expression' => $expression,
            'description' => 'Auto Referrer Protection - CloudPanel'
        ], $proxies, $userId);

        if ($res['success']) {
            $applied++;
            saveSecurityRule($pdo, $userId, $domainId, 'referrer_only', json_encode($allowedReferrers));
        }
    }
    
    return ['success' => true, 'applied' => $applied, 'total' => count($domainIds)];
}

// Распознаёт «Google-only» правило по смыслу (skip с Googlebot в UA, либо block всего сайта).
// Нужно, чтобы повторное применение/отмена работали независимо от названия правила.
// Точное распознавание ТОЛЬКО двух правил, которые создаёт сама функция «Только Google».
// КРИТИЧНО: никаких широких подстрок (раньше любое block-правило с
// starts_with(http.request.uri ... — например «block /admin» — ошибочно считалось
// нашим и удалялось). Совпадение по точному описанию ИЛИ точному выражению нашего
// шаблона. Любые ДРУГИЕ правила пользователя сохраняются.
function isGoogleOnlyRule($r) {
    $expr = trim($r['expression'] ?? '');
    $act  = $r['action'] ?? '';
    $desc = $r['description'] ?? '';
    $allowExpr = '(http.user_agent contains "Googlebot") or (http.user_agent contains "Google-") or (http.user_agent contains "-Google")';
    $blockExpr = '(starts_with(http.request.uri, "/"))';
    if ($act === 'skip'  && ($desc === 'Allow Google Bot' || $expr === $allowExpr)) return true;  // Allow Google Bot
    if ($act === 'block' && ($desc === 'Block all other'  || $expr === $blockExpr)) return true;  // Block all other (catch-all)
    return false;
}
function isNotGoogleOnlyRule($r) { return !isGoogleOnlyRule($r); }

// «Только Google»: 2 WAF custom-правила (skip Googlebot первым + block all other последним).
// Существующие правила сохраняются между ними. Повторное применение не дублирует.
function applyOnlyGoogle($pdo, $userId, $data) {
    $scope = $data['scope'] ?? [];
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    // Пишем «Started» ДО обращения к Cloudflare — чтобы в логах было видно, что запрос дошёл.
    logAction($pdo, $userId, 'Only Google Started', 'domains: ' . count($domainIds));

    $allowRule = [
        'action' => 'skip',
        'expression' => '(http.user_agent contains "Googlebot") or (http.user_agent contains "Google-") or (http.user_agent contains "-Google")',
        'description' => 'Allow Google Bot',
        'action_parameters' => [
            'ruleset' => 'current',
            'phases' => ['http_ratelimit', 'http_request_sbfm', 'http_request_firewall_managed'],
            'products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],
        ],
        'logging' => ['enabled' => true],
    ];
    $blockRule = [
        'action' => 'block',
        'expression' => '(starts_with(http.request.uri, "/"))',
        'description' => 'Block all other',
    ];

    $applied = 0;
    $errors = [];
    $proxies = getProxies($pdo, $userId);

    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("SELECT ca.domain, ca.zone_id, cc.email, cc.api_key, cc.auth_type FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        if (!$domain) { $errors[] = "ID $domainId: домен не найден"; continue; }
        if (!$domain['zone_id']) { $errors[] = $domain['domain'] . ': нет zone_id (сначала синхронизируйте домен)'; continue; }

        // Текущие правила БЕЗ любых Google-only правил (распознаём по смыслу, не по названию),
        // чтобы при повторном применении / поверх ручных правил получилось ровно 2, а не 4.
        // userId=null: внутренние вызовы к CF не пишут логи (меньше конкуренции за БД при апплае)
        $current = cfGetCustomRuleset($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], $proxies, null);
        if ($current['error']) { $errors[] = $domain['domain'] . ': ' . $current['error']; continue; }
        $middle = array_values(array_filter($current['rules'], 'isNotGoogleOnlyRule'));

        // Порядок: skip Google -> существующие -> block all
        $rules = array_merge([$allowRule], $middle, [$blockRule]);
        $res = cfSetCustomRules($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], $rules, $proxies, null);

        if ($res['success']) {
            $applied++;
            saveSecurityRule($pdo, $userId, $domainId, 'only_google', json_encode(['rules' => 2]));
            $keptCount = count($middle);
            $detail = "Домен: {$domain['domain']} — применено 2 правила: "
                    . "«Allow Google Bot» (skip — пропускает Googlebot мимо WAF) + "
                    . "«Block all other» (block — блокирует весь остальной трафик). "
                    . "Существующих правил сохранено: {$keptCount}. Zone: {$domain['zone_id']}";
            logAction($pdo, $userId, 'Only Google Applied', $detail);
        } else {
            $errors[] = $domain['domain'] . ': ' . $res['error'];
            logAction($pdo, $userId, 'Only Google FAILED', "Домен: {$domain['domain']} — НЕ применено. Ошибка: {$res['error']}. Zone: {$domain['zone_id']}");
        }
    }

    return [
        'success' => $applied > 0,
        'applied' => $applied,
        'total' => count($domainIds),
        'errors' => $errors,
        'error' => $applied > 0 ? null : (implode('; ', $errors) ?: 'Не удалось применить'),
    ];
}

// Отмена «Только Google»: удаляет 2 правила (Allow Google Bot + Block all other).
function removeOnlyGoogle($pdo, $userId, $data) {
    $scope = $data['scope'] ?? [];
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    $removed = 0;
    $errors = [];
    $proxies = getProxies($pdo, $userId);
    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("SELECT ca.domain, ca.zone_id, cc.email, cc.api_key, cc.auth_type FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        if (!$domain) { $errors[] = "ID $domainId: домен не найден"; continue; }
        if (!$domain['zone_id']) { $errors[] = $domain['domain'] . ': нет zone_id'; continue; }

        $current = cfGetCustomRuleset($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], $proxies, $userId);
        if ($current['error']) { $errors[] = $domain['domain'] . ': ' . $current['error']; continue; }
        $kept = array_values(array_filter($current['rules'], 'isNotGoogleOnlyRule'));
        if (count($kept) === count($current['rules'])) { $removed++; continue; } // нечего удалять
        $res = cfSetCustomRules($pdo, $domain['email'], $domain['api_key'], $domain['zone_id'], $kept, $proxies, $userId);
        if ($res['success']) {
            $removed++;
            $pdo->prepare("DELETE FROM security_rules WHERE user_id = ? AND domain_id = ? AND rule_type = 'only_google'")->execute([$userId, $domainId]);
        } else {
            $errors[] = $domain['domain'] . ': ' . $res['error'];
        }
    }
    return ['success' => $removed > 0, 'applied' => $removed, 'total' => count($domainIds), 'error' => $removed > 0 ? null : (implode('; ', $errors) ?: 'Не удалось отменить')];
}

function deployWorker($pdo, $userId, $data) {
    $template = $data['template'] ?? '';
    $route = $data['route'] ?? '*';
    $scope = $data['scope'] ?? [];
    $config = $data['config'] ?? [];
    
    $workerData = getWorkerTemplate($template);
    if (!$workerData['success']) {
        return $workerData;
    }
    
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    
    $applied = 0;
    $errors = [];
    $proxies = getProxies($pdo, $userId);

    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("
            SELECT ca.*, cc.email, cc.api_key, cc.auth_type
            FROM cloudflare_accounts ca
            JOIN cloudflare_credentials cc ON ca.account_id = cc.id
            WHERE ca.id = ? AND ca.user_id = ?
        ");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();

        if (!$domain || !$domain['zone_id']) continue;

        // Маршрут: '*' (или пусто) = весь домен (домен/*); {{domain}} тоже поддерживаем
        $r = trim($route);
        if ($r === '' || $r === '*') {
            $routePattern = $domain['domain'] . '/*';
        } else {
            $routePattern = str_replace('{{domain}}', $domain['domain'], $r);
        }

        $credentials = ['email' => $domain['email'], 'api_key' => $domain['api_key'], 'auth_type' => $domain['auth_type'] ?? null];
        $templateRow = ['id' => null, 'name' => $template, 'script' => $workerData['code']];

        // Используем общий (исправленный) путь деплоя: account-level скрипт + маршрут
        $apply = cloudflareApplyWorkerTemplate($pdo, $userId, $domain, $credentials, $templateRow, $routePattern, $proxies, $config);

        if (!empty($apply['success'])) {
            $applied++;
            saveSecurityRule($pdo, $userId, $domainId, 'worker', json_encode([
                'template' => $template,
                'route' => $apply['pattern'] ?? $routePattern
            ]));
        } else {
            $errors[] = $domain['domain'] . ': ' . ($apply['error'] ?? 'unknown');
        }
    }

    return ['success' => $applied > 0, 'applied' => $applied, 'total' => count($domainIds), 'template' => $template, 'errors' => $errors];
}

/**
 * Развернуть ПОЛЬЗОВАТЕЛЬСКИЙ (кастомный) Worker на выбранный домен.
 * Скрипт заливается как есть (без подстановки шаблонных {{...}}). Маршрутов может
 * быть НЕСКОЛЬКО (через запятую или с новой строки) — скрипт один, а путей к нему
 * сколько нужно, например apex-домен/* и *.домен/* сразу (как в Cloudflare).
 */
function deployCustomWorker($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    $routeRaw = trim($data['route'] ?? '');
    $script   = (string)($data['script'] ?? '');

    if ($domainId <= 0)          return ['success' => false, 'error' => 'Не выбран домен'];
    if (trim($script) === '')    return ['success' => false, 'error' => 'Пустой код Worker — вставьте скрипт'];
    // Базовая защита от мусора: у воркера должен быть обработчик запросов.
    if (stripos($script, 'addEventListener') === false && stripos($script, 'export default') === false) {
        return ['success' => false, 'error' => 'Код не похож на Worker: нужен либо addEventListener("fetch", …), либо export default { fetch(…) }'];
    }

    $stmt = $pdo->prepare("
        SELECT ca.*, cc.email, cc.api_key, cc.auth_type
        FROM cloudflare_accounts ca
        JOIN cloudflare_credentials cc ON ca.account_id = cc.id
        WHERE ca.id = ? AND ca.user_id = ?
    ");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    if (!$domain) return ['success' => false, 'error' => 'Домен не найден'];

    // Разбираем список маршрутов (запятая/перевод строки), нормализуем, убираем дубли.
    $patterns = [];
    foreach (preg_split('/[\n,]+/', $routeRaw) as $p) {
        $p = trim($p);
        if ($p === '' || $p === '*') {
            $p = $domain['domain'] . '/*';
        } else {
            $p = str_replace('{{domain}}', $domain['domain'], $p);
        }
        $patterns[$p] = true; // ключ = дедуп
    }
    if (empty($patterns)) { $patterns[$domain['domain'] . '/*'] = true; }
    $patterns = array_keys($patterns);

    $proxies = getProxies($pdo, $userId);
    $credentials = ['email' => $domain['email'], 'api_key' => $domain['api_key'], 'auth_type' => $domain['auth_type'] ?? null];
    // id=null + пустой config → generateWorkerScript вернёт скрипт как есть (без {{...}}).
    $templateRow = ['id' => null, 'name' => 'custom', 'script' => $script];

    // Один скрипт — несколько маршрутов. cloudflareApplyWorkerTemplate заливает скрипт
    // (PUT идемпотентен) и ставит один маршрут; вызываем на каждый паттерн.
    $results = [];
    $okCount = 0;
    $lastError = null;
    foreach ($patterns as $pat) {
        $apply = cloudflareApplyWorkerTemplate($pdo, $userId, $domain, $credentials, $templateRow, $pat, $proxies, []);
        if (!empty($apply['success'])) {
            $okCount++;
            $finalPat = $apply['pattern'] ?? $pat;
            $results[] = ['pattern' => $finalPat, 'ok' => true];
            saveSecurityRule($pdo, $userId, $domainId, 'worker', json_encode([
                'template' => 'custom',
                'route' => $finalPat
            ]));
        } else {
            $lastError = $apply['error'] ?? 'unknown';
            $results[] = ['pattern' => $pat, 'ok' => false, 'error' => $lastError];
        }
    }

    return [
        'success' => $okCount > 0,
        'domain'  => $domain['domain'],
        'applied' => $okCount,
        'total'   => count($patterns),
        'results' => $results,
        'error'   => $okCount > 0 ? null : ('Не удалось развернуть Worker: ' . ($lastError ?? 'неизвестно')),
    ];
}

/**
 * Удалить кастомный Worker выбранного домена: снять все маршруты, указывающие на
 * его скрипт (cfp-<домен>), и удалить сам скрипт с аккаунта. Плюс локальная чистка.
 */
function deleteCustomWorker($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    if ($domainId <= 0) return ['success' => false, 'error' => 'Не выбран домен'];

    $stmt = $pdo->prepare("
        SELECT ca.*, cc.email, cc.api_key, cc.auth_type
        FROM cloudflare_accounts ca
        JOIN cloudflare_credentials cc ON ca.account_id = cc.id
        WHERE ca.id = ? AND ca.user_id = ?
    ");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    if (!$domain) return ['success' => false, 'error' => 'Домен не найден'];

    $proxies = getProxies($pdo, $userId);
    $credentials = ['email' => $domain['email'], 'api_key' => $domain['api_key'], 'auth_type' => $domain['auth_type'] ?? null];
    $scriptName = cfWorkerScriptName($domain['domain']);
    $zoneId = $domain['zone_id'];

    // 1) Снимаем маршруты зоны, указывающие на наш скрипт.
    $routesRemoved = 0;
    $routeErrors = [];
    if ($zoneId) {
        $list = cloudflareListWorkerRoutes($pdo, $credentials, $zoneId, $proxies, $userId);
        if (!empty($list['success']) && !empty($list['data'])) {
            $routes = is_array($list['data']) ? $list['data'] : [$list['data']];
            foreach ($routes as $r) {
                $rScript = is_object($r) ? ($r->script ?? '') : ($r['script'] ?? '');
                $rId     = is_object($r) ? ($r->id ?? '')     : ($r['id'] ?? '');
                if ($rScript === $scriptName && $rId !== '') {
                    $del = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'], "zones/$zoneId/workers/routes/$rId", 'DELETE', [], $proxies, $userId, $credentials['auth_type']);
                    if (!empty($del['success'])) $routesRemoved++;
                    else $routeErrors[] = cfReadableError($del);
                }
            }
        }
    }

    // 2) Удаляем сам скрипт с аккаунта (force — на случай оставшихся ссылок).
    $accountId = $zoneId ? cfGetAccountId($pdo, $credentials, $zoneId, $proxies, $userId) : null;
    $scriptDeleted = false;
    $scriptError = null;
    if ($accountId) {
        $ds = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'], "accounts/$accountId/workers/scripts/$scriptName?force=true", 'DELETE', [], $proxies, $userId, $credentials['auth_type']);
        if (!empty($ds['success']) || (int)($ds['http_code'] ?? 0) === 404) {
            $scriptDeleted = true; // 404 = скрипта уже нет → тоже успех
        } else {
            $scriptError = cfReadableError($ds);
        }
    } else {
        $scriptError = 'account_id не определён (нужно право Account Settings: Read)';
    }

    // 3) Локальная чистка (некритично).
    try { $pdo->prepare("DELETE FROM cloudflare_worker_routes WHERE user_id = ? AND domain_id = ?")->execute([$userId, $domainId]); } catch (Exception $e) {}
    try { $pdo->prepare("DELETE FROM security_rules WHERE user_id = ? AND domain_id = ? AND rule_type = 'worker'")->execute([$userId, $domainId]); } catch (Exception $e) {}

    logAction($pdo, $userId, 'Worker удалён', "{$domain['domain']}: маршрутов снято {$routesRemoved}, скрипт " . ($scriptDeleted ? 'удалён' : 'НЕ удалён'));

    $ok = $scriptDeleted || $routesRemoved > 0;
    return [
        'success' => $ok,
        'domain' => $domain['domain'],
        'routes_removed' => $routesRemoved,
        'script_deleted' => $scriptDeleted,
        'error' => $ok ? null : ('Не удалось удалить воркер: ' . ($scriptError ?? (implode('; ', $routeErrors) ?: 'воркер не найден на домене'))),
    ];
}

/**
 * Текущий кастомный Worker домена: есть ли скрипт, его маршруты и код (для показа/редактирования).
 */
function getCustomWorker($pdo, $userId, $data) {
    $domainId = (int)($data['domain_id'] ?? 0);
    if ($domainId <= 0) return ['success' => false, 'error' => 'Не выбран домен'];

    $stmt = $pdo->prepare("
        SELECT ca.*, cc.email, cc.api_key, cc.auth_type
        FROM cloudflare_accounts ca
        JOIN cloudflare_credentials cc ON ca.account_id = cc.id
        WHERE ca.id = ? AND ca.user_id = ?
    ");
    $stmt->execute([$domainId, $userId]);
    $domain = $stmt->fetch();
    if (!$domain) return ['success' => false, 'error' => 'Домен не найден'];

    $proxies = getProxies($pdo, $userId);
    $credentials = ['email' => $domain['email'], 'api_key' => $domain['api_key'], 'auth_type' => $domain['auth_type'] ?? null];
    $scriptName = cfWorkerScriptName($domain['domain']);
    $zoneId = $domain['zone_id'];

    // ВСЕ worker-маршруты зоны (а не только наш скрипт) — чтобы видеть и воркеры,
    // заведённые руками в кабинете Cloudflare (у них другое имя скрипта).
    $routes = [];            // паттерны (для подстановки в поле)
    $scriptsSeen = [];       // имя_скрипта => true (в порядке появления)
    $foreign = false;        // есть ли маршрут на скрипт НЕ нашего формата
    if ($zoneId) {
        $list = cloudflareListWorkerRoutes($pdo, $credentials, $zoneId, $proxies, $userId);
        if (!empty($list['success']) && !empty($list['data'])) {
            $rs = is_array($list['data']) ? $list['data'] : [$list['data']];
            foreach ($rs as $r) {
                $rScript = is_object($r) ? ($r->script ?? '') : ($r['script'] ?? '');
                $rPat    = is_object($r) ? ($r->pattern ?? '') : ($r['pattern'] ?? '');
                if ($rPat === '') continue;
                $routes[] = $rPat;
                if ($rScript !== '') {
                    $scriptsSeen[$rScript] = true;
                    if ($rScript !== $scriptName) $foreign = true;
                }
            }
        }
    }

    // Из какого скрипта тянуть код: сперва наш (cfp-…), иначе первый привязанный.
    $loadScript = null;
    if (isset($scriptsSeen[$scriptName])) $loadScript = $scriptName;
    elseif (!empty($scriptsSeen))         $loadScript = array_key_first($scriptsSeen);

    $accountId = $zoneId ? cfGetAccountId($pdo, $credentials, $zoneId, $proxies, $userId) : null;
    $code = '';
    $exists = !empty($routes);

    // Код скрипта (best-effort): того, что привязан маршрутом; если маршрутов нет —
    // проверяем, не залит ли наш скрипт без маршрута.
    if ($accountId) {
        $sc = cfGetWorkerScriptContent($credentials, $accountId, $loadScript ?: $scriptName, $proxies);
        if (!empty($sc['exists'])) { $exists = true; $code = $sc['code'] ?? ''; if (!$loadScript) $loadScript = $scriptName; }
    }

    return [
        'success'      => true,
        'exists'       => $exists,
        'domain'       => $domain['domain'],
        'script_name'  => $loadScript ?: $scriptName,
        'scripts'      => array_keys($scriptsSeen),
        'foreign'      => $foreign,
        'routes'       => array_values(array_unique($routes)),
        'code'         => $code,
    ];
}

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===

function getScopeDomains($pdo, $userId, $scope) {
    $type = $scope['type'] ?? 'all';
    
    if ($type === 'all') {
        $stmt = $pdo->prepare("SELECT id FROM cloudflare_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($type === 'group') {
        $groupId = $scope['groupId'] ?? null;
        $stmt = $pdo->prepare("SELECT id FROM cloudflare_accounts WHERE user_id = ? AND group_id = ?");
        $stmt->execute([$userId, $groupId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($type === 'selected') {
        return $scope['domainIds'] ?? [];
    }
    
    return [];
}

function saveSecurityRule($pdo, $userId, $domainId, $ruleType, $ruleData) {
    // Запись в security_rules — НЕкритичная (CF-правило уже применено к моменту вызова).
    // Под нагрузкой SQLite может вернуть "database is locked", если параллельный запрос
    // (probe прав / очередь / health) держит write-лок. Раньше это исключение всплывало
    // как HTTP 500, и пользователь видел ошибку/вечный спиннер, хотя правило уже стояло.
    // Поэтому: несколько ретраев, и при стойкой блокировке — тихо пропускаем (не валим apply).
    $sql = "INSERT INTO security_rules (user_id, domain_id, rule_type, rule_data, created_at)
            VALUES (?, ?, ?, ?, datetime('now'))";
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        try {
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([$userId, $domainId, $ruleType, $ruleData]);
        } catch (PDOException $e) {
            $locked = stripos($e->getMessage(), 'locked') !== false
                   || stripos($e->getMessage(), 'busy') !== false;
            if ($locked && $attempt < 4) {
                usleep(300000 * $attempt); // 0.3s, 0.6s, 0.9s
                continue;
            }
            // Стойкая блокировка/иная ошибка БД — не критично: правило на Cloudflare стоит.
            error_log("saveSecurityRule: пропущена запись bookkeeping ($ruleType, domain $domainId): " . $e->getMessage());
            return false;
        }
    }
    return false;
}

function loadBadBotsList($rules) {
    $badBots = [];
    
    if ($rules['blockAllBots'] ?? false) {
        $url = 'https://raw.githubusercontent.com/mitchellkrogza/nginx-ultimate-bad-bot-blocker/master/_generator_lists/bad-user-agents.list';
        $content = @file_get_contents($url);
        if ($content) {
            $badBots = array_merge($badBots, explode("\n", $content));
        }
    }
    
    if ($rules['blockVulnScanners'] ?? false) {
        $badBots = array_merge($badBots, ['nikto', 'nmap', 'sqlmap', 'nessus', 'openvas', 'acunetix', 'metasploit', 'w3af', 'burpsuite', 'owasp', 'skipfish']);
    }
    
    if ($rules['blockMalware'] ?? false) {
        $badBots = array_merge($badBots, ['malware', 'ransomware', 'trojan', 'adware', 'spyware']);
    }
    
    return array_filter(array_unique(array_map('trim', $badBots)));
}

function loadKnownBadIPs() {
    $content = @file_get_contents('https://raw.githubusercontent.com/mitchellkrogza/Suspicious.Snooping.Sniffing.Hacking.IP.Addresses/master/ips.list');
    if ($content) {
        return array_filter(array_unique(array_map('trim', explode("\n", $content))));
    }
    return [];
}

function buildBotBlockExpression($badBots) {
    $badBots = array_slice($badBots, 0, 100);
    $conditions = [];
    foreach ($badBots as $bot) {
        $bot = addslashes($bot);
        $conditions[] = "(lower(http.user_agent) contains \"$bot\")";
    }
    return implode(' or ', $conditions);
}

function buildReferrerExpression($allowedReferrers) {
    $allowed = [];
    
    if ($allowedReferrers['google'] ?? false) $allowed[] = '(http.referer contains "google.")';
    if ($allowedReferrers['yandex'] ?? false) $allowed[] = '(http.referer contains "yandex.")';
    if ($allowedReferrers['bing'] ?? false) $allowed[] = '(http.referer contains "bing.com")';
    if ($allowedReferrers['duckduckgo'] ?? false) $allowed[] = '(http.referer contains "duckduckgo.com")';
    if ($allowedReferrers['baidu'] ?? false) $allowed[] = '(http.referer contains "baidu.com")';
    
    foreach ($allowedReferrers['custom'] ?? [] as $domain) {
        $allowed[] = "(http.referer contains \"$domain\")";
    }
    
    if (empty($allowed)) {
        return '(http.referer eq "")';
    }
    
    return "(not (" . implode(' or ', $allowed) . "))";
}

/**
 * Развернуть Worker с пользовательской конфигурацией
 */
function deployWorkerWithConfig($pdo, $userId, $data) {
    $template = $data['template'] ?? '';
    $route = $data['route'] ?? '/*';
    $scope = $data['scope'] ?? [];
    $configJson = $data['config'] ?? '{}';
    $customCode = $data['code'] ?? null;
    
    // Парсим конфигурацию
    $config = json_decode($configJson, true);
    if (!is_array($config)) {
        $config = [];
    }
    
    // Если пользовательский код не передан, загружаем шаблон
    if (empty($customCode)) {
        $workerData = getWorkerTemplate($template);
        if (!$workerData['success']) {
            return $workerData;
        }
        $customCode = applyConfigToWorkerCode($workerData['code'], $config);
    }
    
    $domainIds = getScopeDomains($pdo, $userId, $scope);
    
    if (empty($domainIds)) {
        return ['success' => false, 'error' => 'Нет доменов для применения'];
    }
    
    $applied = 0;
    $errors = [];
    $proxies = getProxies($pdo, $userId);
    
    foreach ($domainIds as $domainId) {
        $stmt = $pdo->prepare("
            SELECT ca.*, cc.email, cc.api_key, cc.cf_account_id
            FROM cloudflare_accounts ca
            JOIN cloudflare_credentials cc ON ca.account_id = cc.id
            WHERE ca.id = ? AND ca.user_id = ?
        ");
        $stmt->execute([$domainId, $userId]);
        $domain = $stmt->fetch();
        
        if (!$domain || !$domain['zone_id']) {
            $errors[] = ['domain_id' => $domainId, 'error' => 'Домен не найден или нет zone_id'];
            continue;
        }
        
        // Используем cf_account_id если есть, иначе пробуем получить
        $accountId = $domain['cf_account_id'] ?? null;
        if (!$accountId) {
            // Получаем account_id от Cloudflare
            $accountId = getCloudflareAccountId($pdo, $domain['email'], $domain['api_key'], $proxies, $userId);
        }
        
        if (!$accountId) {
            $errors[] = ['domain_id' => $domainId, 'domain' => $domain['domain'], 'error' => 'Не удалось получить Account ID'];
            continue;
        }
        
        // Имя скрипта
        $scriptName = sanitizeWorkerName("security-{$template}-" . substr(md5($domain['domain']), 0, 8));
        
        // Загружаем Worker скрипт через специальный endpoint (требует multipart)
        $uploadResult = uploadWorkerScript($pdo, $domain['email'], $domain['api_key'], $accountId, $scriptName, $customCode, $proxies, $userId);
        
        if (!$uploadResult['success']) {
            $errors[] = ['domain_id' => $domainId, 'domain' => $domain['domain'], 'error' => $uploadResult['error'] ?? 'Ошибка загрузки Worker'];
            continue;
        }
        
        // Создаем route для Worker
        $routePattern = str_replace(['{{domain}}', '*'], [$domain['domain'], $domain['domain'] . '/*'], $route);
        if (strpos($routePattern, $domain['domain']) === false) {
            $routePattern = $domain['domain'] . '/' . ltrim($route, '/');
        }
        
        // Убедимся что route начинается с домена
        if (!preg_match('/^[a-z0-9]/', $routePattern)) {
            $routePattern = $domain['domain'] . '/*';
        }
        
        $routeResponse = cloudflareApiRequestDetailed(
            $pdo, $domain['email'], $domain['api_key'],
            "zones/{$domain['zone_id']}/workers/routes",
            'POST',
            ['pattern' => $routePattern, 'script' => $scriptName],
            $proxies, $userId
        );
        
        if ($routeResponse['success']) {
            $applied++;
            saveSecurityRule($pdo, $userId, $domainId, 'worker_custom', json_encode([
                'template' => $template,
                'script_name' => $scriptName,
                'route' => $routePattern,
                'config' => $config,
                'deployed_at' => date('Y-m-d H:i:s')
            ]));
        } else {
            // Worker загружен, но route не создан - может уже существует
            $errors[] = [
                'domain_id' => $domainId,
                'domain' => $domain['domain'],
                'error' => 'Worker создан, но route не привязан: ' . ($routeResponse['error'] ?? 'Unknown'),
                'partial' => true
            ];
            $applied++; // Считаем частичным успехом
        }
    }
    
    return [
        'success' => $applied > 0,
        'applied' => $applied,
        'total' => count($domainIds),
        'template' => $template,
        'errors' => $errors
    ];
}

/**
 * Применить конфигурацию к коду Worker
 */
function applyConfigToWorkerCode($code, $config) {
    // Bad bots
    if (!empty($config['badBots'])) {
        $botsString = implode("', '", $config['badBots']);
        $code = preg_replace(
            "/const\s+BAD_BOTS\s*=\s*\[[^\]]*\]/",
            "const BAD_BOTS = ['$botsString']",
            $code
        );
    }
    
    // Blocked IPs
    if (!empty($config['blockedIps'])) {
        $ipsString = implode("', '", $config['blockedIps']);
        $code = preg_replace(
            "/const\s+BLOCKED_IPS\s*=\s*\[[^\]]*\]/",
            "const BLOCKED_IPS = ['$ipsString']",
            $code
        );
    }
    
    // Geo settings
    $geoMode = $config['geoMode'] ?? 'whitelist';
    if ($geoMode === 'whitelist' && !empty($config['allowedCountries'])) {
        $countriesString = implode("', '", $config['allowedCountries']);
        $code = preg_replace(
            "/const\s+ALLOWED_COUNTRIES\s*=\s*\[[^\]]*\]/",
            "const ALLOWED_COUNTRIES = ['$countriesString']",
            $code
        );
        $code = preg_replace(
            "/const\s+GEO_MODE\s*=\s*['\"][^'\"]*['\"]/",
            "const GEO_MODE = 'whitelist'",
            $code
        );
    } elseif ($geoMode === 'blacklist' && !empty($config['blockedCountries'])) {
        $countriesString = implode("', '", $config['blockedCountries']);
        $code = preg_replace(
            "/const\s+BLOCKED_COUNTRIES\s*=\s*\[[^\]]*\]/",
            "const BLOCKED_COUNTRIES = ['$countriesString']",
            $code
        );
        $code = preg_replace(
            "/const\s+GEO_MODE\s*=\s*['\"][^'\"]*['\"]/",
            "const GEO_MODE = 'blacklist'",
            $code
        );
    }
    
    // Rate limit
    if (isset($config['rateLimit'])) {
        $requests = $config['rateLimit']['requests'] ?? 100;
        $window = $config['rateLimit']['window'] ?? 60;
        $code = preg_replace("/const\s+RATE_LIMIT\s*=\s*\d+/", "const RATE_LIMIT = $requests", $code);
        $code = preg_replace("/const\s+RATE_WINDOW\s*=\s*\d+/", "const RATE_WINDOW = $window", $code);
    }
    
    // Referrers
    if (!empty($config['allowedReferrers'])) {
        $referrersString = implode("', '", $config['allowedReferrers']);
        $code = preg_replace(
            "/const\s+ALLOWED_REFERRERS\s*=\s*\[[^\]]*\]/",
            "const ALLOWED_REFERRERS = ['$referrersString']",
            $code
        );
    }
    
    // URL exceptions
    if (!empty($config['urlExceptions'])) {
        $exceptionsString = implode("', '", $config['urlExceptions']);
        $code = preg_replace(
            "/const\s+URL_EXCEPTIONS\s*=\s*\[[^\]]*\]/",
            "const URL_EXCEPTIONS = ['$exceptionsString']",
            $code
        );
    }
    
    return $code;
}

/**
 * Загрузить Worker скрипт через Cloudflare API
 */
function uploadWorkerScript($pdo, $email, $apiKey, $accountId, $scriptName, $scriptCode, $proxies, $userId) {
    $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/workers/scripts/{$scriptName}";
    
    $headers = [
        "Authorization: Bearer {$apiKey}",
        "Content-Type: application/javascript"
    ];
    
    // Альтернативно для X-Auth ключей
    if (strpos($apiKey, '.') === false && strlen($apiKey) === 37) {
        $headers = [
            "X-Auth-Email: {$email}",
            "X-Auth-Key: {$apiKey}",
            "Content-Type: application/javascript"
        ];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $scriptCode,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);
    
    if (!empty($proxies)) {
        $proxy = $proxies[array_rand($proxies)];
        curl_setopt($ch, CURLOPT_PROXY, $proxy['ip'] . ':' . $proxy['port']);
        if (!empty($proxy['username']) && !empty($proxy['password'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['success']) && $data['success']) {
        return ['success' => true, 'script_name' => $scriptName];
    }
    
    $errorMsg = 'HTTP ' . $httpCode;
    if (isset($data['errors']) && !empty($data['errors'])) {
        $errorMsg .= ': ' . ($data['errors'][0]['message'] ?? json_encode($data['errors']));
    }
    
    return ['success' => false, 'error' => $errorMsg];
}

/**
 * Получить Account ID из Cloudflare
 */
function getCloudflareAccountId($pdo, $email, $apiKey, $proxies, $userId) {
    $response = cloudflareApiRequestDetailed($pdo, $email, $apiKey, 'accounts', 'GET', null, $proxies, $userId);
    
    if ($response['success'] && !empty($response['result'])) {
        return $response['result'][0]['id'] ?? null;
    }
    
    return null;
}

/**
 * Очистить имя Worker от недопустимых символов
 */
function sanitizeWorkerName($name) {
    // Worker name должен содержать только буквы, цифры и дефисы
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9-]/', '-', $name);
    $name = preg_replace('/-+/', '-', $name);
    $name = trim($name, '-');
    return substr($name, 0, 63); // Максимум 63 символа
}

// Функция getProxies доступна из functions.php
// Функция generateWorkerScript доступна из functions.php