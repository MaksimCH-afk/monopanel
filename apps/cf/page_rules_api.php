<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    $domainId = (int)($_POST['domain_id'] ?? 0);
    $ruleType = $_POST['rule_type'] ?? '';

    if ($domainId <= 0 || !$ruleType) throw new Exception('Неверные параметры');

    $stmt = $pdo->prepare("SELECT ca.*, cc.email, cc.api_key FROM cloudflare_accounts ca JOIN cloudflare_credentials cc ON ca.account_id = cc.id WHERE ca.id = ? AND ca.user_id = ?");
    $stmt->execute([$domainId, $_SESSION['user_id']]);
    $domain = $stmt->fetch();
    if (!$domain) throw new Exception('Домен не найден');

    $zoneId = $domain['zone_id'];
    $proxies = getProxies($pdo, $_SESSION['user_id']);

    if (!$zoneId) {
        $z = cloudflareApiRequest($pdo, $domain['email'], $domain['api_key'], "zones?name={$domain['domain']}", 'GET', [], $proxies, $_SESSION['user_id']);
        if (!$z || empty($z->result)) throw new Exception('Zone ID не найден');
        $zoneId = $z->result[0]->id;
        $pdo->prepare("UPDATE cloudflare_accounts SET zone_id = ? WHERE id = ?")->execute([$zoneId, $domainId]);
    }

    // Формируем правило
    $rule = null;
    switch ($ruleType) {
        case 'cache_static':
            $rule = [
                'targets' => [[
                    'target' => 'url',
                    'constraint' => [
                        'operator' => 'matches',
                        'value' => "*{$domain['domain']}/*.{jpg,jpeg,png,gif,webp,svg,css,js,woff,woff2,ico}"
                    ]
                ]],
                'actions' => [[ 'id' => 'cache_level', 'value' => 'cache_everything' ]],
                'priority' => 1,
                'status' => 'active'
            ];
            break;
        case 'cache_everything':
            $rule = [
                'targets' => [[ 'target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => "*{$domain['domain']}/*"] ]],
                'actions' => [[ 'id' => 'cache_level', 'value' => 'cache_everything' ]],
                'priority' => 1,
                'status' => 'active'
            ];
            break;
        case 'browser_cache':
            $rule = [
                'targets' => [[ 'target' => 'url', 'constraint' => ['operator' => 'matches', 'value' => "*{$domain['domain']}/*"] ]],
                'actions' => [[ 'id' => 'browser_cache_ttl', 'value' => 2678400 ]],
                'priority' => 1,
                'status' => 'active'
            ];
            break;
        case 'redirect_https':
            $rule = [
                'targets' => [[
                    'target' => 'url',
                    'constraint' => [ 'operator' => 'matches', 'value' => "http://{$domain['domain']}/*" ]
                ]],
                'actions' => [[ 'id' => 'forwarding_url', 'value' => ['url' => "https://{$domain['domain']}/$1", 'status_code' => 301] ]],
                'priority' => 2,
                'status' => 'active'
            ];
            break;

        // ПРЕСЕТ 1: 301-редирект через Single/Dynamic Redirect (Rulesets) — замена Page Rules.
        // Поддерживает: страница -> страница (любые сайты) и весь сайт -> одна страница другого сайта.
        case 'redirect_301':
            $target   = trim($_POST['target'] ?? '');          // «Куда»: путь (relative) или полный URL (absolute)
            $source   = trim($_POST['source'] ?? '');          // исходный путь (пусто = весь сайт)
            $mode     = ($_POST['mode'] ?? 'absolute') === 'relative' ? 'relative' : 'absolute';
            $preserve = ($_POST['preserve_query'] ?? '0') === '1';
            $whole    = empty($source) || $source === '/' || $source === '/*';
            if ($target === '') throw new Exception('Укажите «Куда» (target)');

            $host = $domain['domain'];

            // Целевой URL по режиму:
            //  - relative: «Куда» — путь на ЭТОМ ЖЕ домене → https://<host>/<path>
            //  - absolute: «Куда» — уже полный URL (можно на другой сайт)
            if ($mode === 'relative') {
                $targetUrl = 'https://' . $host . '/' . ltrim($target, '/');
            } else {
                if (!preg_match('~^https?://~i', $target)) {
                    throw new Exception('Для режима «на другой адрес» нужен полный URL (https://…)');
                }
                $targetUrl = $target;
            }

            if ($whole) {
                $expr = '(http.host eq "' . addslashes($host) . '")';
                $desc = "301 весь сайт -> $targetUrl";
            } else {
                $src = '/' . ltrim($source, '/');
                $expr = '(http.host eq "' . addslashes($host) . '" and http.request.uri.path eq "' . addslashes($src) . '")';
                $desc = "301 $src -> $targetUrl";
            }
            $res = cfAddRedirectRule($pdo, $domain['email'], $domain['api_key'], $zoneId, $expr, $targetUrl, $desc, 301, $preserve, $proxies, $_SESSION['user_id']);
            if (!$res['success']) throw new Exception('Не удалось применить редирект: ' . $res['error']);
            echo json_encode(['success' => true, 'message' => '301-редирект применён (Single Redirect Rule)']);
            exit;

        // ПРЕСЕТ 2: отдавать 404/410 для страницы или всего сайта (через WAF custom rule)
        case 'gone_410':
        case 'not_found_404':
            $code = $ruleType === 'not_found_404' ? 404 : (int)($_POST['code'] ?? 410);
            if (!in_array($code, [404, 410], true)) $code = 410;
            $path = trim($_POST['path'] ?? '');
            if ($path === '' || $path === '/*') {
                $expr = '(starts_with(http.request.uri.path, "/"))';
                $scopeDesc = 'весь сайт';
            } else {
                $p = '/' . ltrim($path, '/');
                $expr = '(http.request.uri.path eq "' . addslashes($p) . '")';
                $scopeDesc = $p;
            }
            $res = cfAddCustomRule($pdo, $domain['email'], $domain['api_key'], $zoneId, [
                'action' => 'block',
                'expression' => $expr,
                'description' => "Return $code ($scopeDesc) - CloudPanel",
                'action_parameters' => [
                    'response' => [
                        'status_code' => $code,
                        'content' => $code === 410 ? 'Gone' : 'Not Found',
                        'content_type' => 'text/plain',
                    ],
                ],
            ], $proxies, $_SESSION['user_id']);
            if (!$res['success']) throw new Exception('Не удалось применить правило: ' . $res['error']);
            echo json_encode(['success' => true, 'message' => "Правило $code применено ($scopeDesc)"]);
            exit;

        default:
            throw new Exception('Неизвестный тип правила');
    }

    $resp = cloudflareApiRequestDetailed($pdo, $domain['email'], $domain['api_key'], "zones/$zoneId/pagerules", 'POST', $rule, $proxies, $_SESSION['user_id']);

    if (!$resp || !$resp['success']) {
        throw new Exception('Не удалось применить правило: ' . ($resp ? cfReadableError($resp) : 'нет ответа'));
    }

    echo json_encode(['success' => true, 'message' => 'Правило применено']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} 