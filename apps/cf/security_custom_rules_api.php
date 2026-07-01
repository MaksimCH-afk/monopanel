<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

$userId = $_SESSION['user_id'];

// Действие Cloudflare WAF custom rules (rulesets)
// skip — пропустить (для «белых» правил), block/challenge/managed_challenge/js_challenge
function cfMapAction($a) {
    $map = [
        'block' => 'block', 'allow' => 'skip', 'skip' => 'skip',
        'challenge' => 'challenge', 'js_challenge' => 'js_challenge',
        'managed_challenge' => 'managed_challenge',
    ];
    return $map[$a] ?? 'block';
}

// «Пропустить всё» — параметры для action=skip (как в скриншоте Allow Google Bot)
function cfSkipAllParams() {
    return [
        'ruleset' => 'current',
        'phases' => ['http_ratelimit', 'http_request_sbfm', 'http_request_firewall_managed'],
        'products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],
    ];
}

// Готовые наборы правил (каждый — массив правил для фазы custom firewall)
function cfPresetRules($preset) {
    switch ($preset) {
        // Пресет из скриншотов: пропускаем Googlebot, остальное блокируем
        case 'only_google':
            return [
                [
                    'action' => 'skip',
                    'expression' => '(http.user_agent contains "Googlebot") or (http.user_agent contains "Google-") or (http.user_agent contains "-Google")',
                    'description' => 'Allow Google Bot',
                    'action_parameters' => cfSkipAllParams(),
                    'logging' => ['enabled' => true],
                ],
                [
                    'action' => 'block',
                    'expression' => '(starts_with(http.request.uri.path, "/"))',
                    'description' => 'Block all other',
                ],
            ];
        // Только поисковики: Google/Yandex/Bing/DuckDuckGo
        case 'only_search':
            return [
                [
                    'action' => 'skip',
                    'expression' => '(http.user_agent contains "Googlebot") or (http.user_agent contains "Google-") or (http.user_agent contains "YandexBot") or (http.user_agent contains "bingbot") or (http.user_agent contains "DuckDuckBot")',
                    'description' => 'Allow Search Engines',
                    'action_parameters' => cfSkipAllParams(),
                    'logging' => ['enabled' => true],
                ],
                [
                    'action' => 'block',
                    'expression' => '(starts_with(http.request.uri.path, "/"))',
                    'description' => 'Block all other',
                ],
            ];
        case 'block_ai_bots':
            return [[
                'action' => 'block',
                'expression' => '(http.user_agent contains "GPTBot") or (http.user_agent contains "ChatGPT") or (http.user_agent contains "CCBot") or (http.user_agent contains "ClaudeBot") or (http.user_agent contains "Bytespider")',
                'description' => 'Block AI bots',
            ]];
        case 'block_bad_bots':
            return [[
                'action' => 'block',
                'expression' => '(cf.client.bot) or (http.user_agent contains "spider") or (http.user_agent contains "scraper") or (http.user_agent contains "crawler")',
                'description' => 'Block bad bots',
            ]];
        case 'block_countries':
            return [[
                'action' => 'block',
                'expression' => 'ip.geoip.country in {"CN" "KP" "IR"}',
                'description' => 'Block countries',
            ]];
    }
    return null;
}

try {
    $domainIdsRaw = $_POST['domain_ids'] ?? '[]';
    $domainIds = is_array($domainIdsRaw) ? $domainIdsRaw : json_decode($domainIdsRaw, true);
    $applyToAll = isset($_POST['apply_all']) ? (bool)$_POST['apply_all'] : false;
    $preset = $_POST['preset'] ?? null;
    $mode = $_POST['mode'] ?? 'append'; // append | replace
    $expression = trim($_POST['expression'] ?? '');
    $action = cfMapAction($_POST['action'] ?? 'block');
    $description = trim($_POST['description'] ?? 'Custom security rule');

    if ($applyToAll) {
        $stmt = $pdo->prepare("SELECT id FROM cloudflare_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $domainIds = array_column($stmt->fetchAll(), 'id');
    }

    if (empty($domainIds)) {
        throw new Exception('Не выбраны домены для применения правила');
    }

    // Определяем набор правил: из пресета или из одиночного expression
    $presetRules = $preset ? cfPresetRules($preset) : null;
    if ($presetRules === null) {
        if ($expression === '') {
            throw new Exception('Не задано условие (expression) или неизвестный пресет');
        }
        $presetRules = [[
            'action' => $action,
            'expression' => $expression,
            'description' => $description,
        ]];
    }
    // Пресеты, заменяющие весь набор (например «только поисковики»), идут в режиме replace
    if (in_array($preset, ['only_google', 'only_search'], true)) {
        $mode = 'replace';
    }

    $results = [];
    $summary = ['processed' => 0, 'success' => 0, 'failed' => 0];

    foreach ($domainIds as $domainId) {
        $summary['processed']++;
        $stmt = $pdo->prepare("SELECT ca.domain, ca.zone_id, cc.email, cc.api_key FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
        $stmt->execute([(int)$domainId, $userId]);
        $domain = $stmt->fetch();

        if (!$domain || !$domain['zone_id']) {
            $results[] = ['domain_id' => $domainId, 'success' => false, 'error' => $domain ? 'Zone ID не найден' : 'Домен не найден'];
            $summary['failed']++;
            continue;
        }

        $proxies = getProxies($pdo, $userId);
        $zoneId = $domain['zone_id'];

        if ($mode === 'replace') {
            $res = cfSetCustomRules($pdo, $domain['email'], $domain['api_key'], $zoneId, $presetRules, $proxies, $userId);
        } else {
            // append: добавляем правила по одному, сохраняя существующие
            $res = ['success' => true, 'error' => null];
            foreach ($presetRules as $r) {
                $res = cfAddCustomRule($pdo, $domain['email'], $domain['api_key'], $zoneId, $r, $proxies, $userId);
                if (!$res['success']) break;
            }
        }

        if ($res['success']) {
            $results[] = ['domain_id' => $domainId, 'domain' => $domain['domain'], 'success' => true];
            $summary['success']++;
            logAction($pdo, $userId, 'WAF Custom Rule Applied', "Domain: {$domain['domain']}, Preset: " . ($preset ?: 'custom') . ", Mode: $mode");
        } else {
            $results[] = ['domain_id' => $domainId, 'domain' => $domain['domain'], 'success' => false, 'error' => $res['error']];
            $summary['failed']++;
            logAction($pdo, $userId, 'WAF Custom Rule Failed', "Domain: {$domain['domain']}, Error: {$res['error']}");
        }
    }

    echo json_encode(['success' => true, 'summary' => $summary, 'results' => $results]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
