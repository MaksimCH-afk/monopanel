<?php
/**
 * Мастер-токен: создаёт «дочерние» API-токены Cloudflare с нужным набором прав,
 * чтобы не кликать пачку токенов вручную в интерфейсе CF.
 * Мастер-токен (с правом «Create Additional Tokens» / API Tokens Write) вводится
 * пользователем в рантайме и НЕ сохраняется.
 */
require_once 'config.php';
require_once 'functions.php';
require_once 'db_retry.php';

header('Content-Type: application/json; charset=utf-8');
$userId = $_SESSION['user_id'] ?? 1;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Предустановленный набор прав (UI-метка → имя группы в CF → уровень). */
function masterTokenPreset() {
    return [
        ['key' => 'zone',             'label' => 'Zone (Edit)',                 'cf' => 'Zone Write',                 'level' => 'zone'],
        ['key' => 'dns',              'label' => 'DNS (Edit)',                  'cf' => 'DNS Write',                  'level' => 'zone'],
        ['key' => 'zone_settings',    'label' => 'Zone Settings (Edit)',        'cf' => 'Zone Settings Write',        'level' => 'zone'],
        ['key' => 'ssl',              'label' => 'SSL and Certificates (Edit)', 'cf' => 'SSL and Certificates Write', 'level' => 'zone'],
        ['key' => 'cache_purge',      'label' => 'Cache Purge',                 'cf' => 'Cache Purge',                'level' => 'zone'],
        ['key' => 'firewall',         'label' => 'Firewall Services (Edit)',    'cf' => 'Firewall Services Write',    'level' => 'zone'],
        ['key' => 'page_rules',       'label' => 'Page Rules (Edit)',           'cf' => 'Page Rules Write',           'level' => 'zone'],
        ['key' => 'workers_routes',   'label' => 'Workers Routes (Edit)',       'cf' => 'Workers Routes Write',       'level' => 'zone'],
        ['key' => 'workers_scripts',  'label' => 'Workers Scripts (Edit)',      'cf' => 'Workers Scripts Write',      'level' => 'account'],
        ['key' => 'account_analytics','label' => 'Account Analytics (Read)',     'cf' => 'Account Analytics Read',     'level' => 'account'],
        ['key' => 'zone_waf',         'label' => 'Zone WAF (Edit)',             'cf' => 'Zone WAF Write',             'level' => 'zone'],
        ['key' => 'analytics',        'label' => 'Analytics (Read)',            'cf' => 'Analytics Read',             'level' => 'zone'],
        // У Single Redirect имя группы в CF отличается от UI-метки. Несколько кандидатов
        // + fuzzy-поиск (имя должно содержать оба слова: 'redirect' и 'write').
        // ВАЖНО: НЕ матчим 'Transform Rules Write' — она не грантится на ресурс «все зоны»
        // и роняет создание всего токена.
        ['key' => 'single_redirect',  'label' => 'Single Redirect (Edit)',
         'cf' => ['Single Redirect Write', 'Dynamic URL Redirect Write', 'Dynamic Redirects Write', 'Dynamic Redirect Write'],
         'match' => ['redirect', 'write'], 'level' => 'zone'],
        // Для добавления доменов: создание зон даёт уже зональное «Zone (Edit)» (Zone Write
        // на всех зонах). Дополнительно нужно лишь Account Settings (Read) — чтобы знать
        // account_id (обязателен для POST /zones) и имя аккаунта для подписи.
        ['key' => 'account_settings', 'label' => 'Account Settings (Read)',
         'cf' => ['Account Settings Read', 'Account Settings: Read'],
         'match' => ['account', 'settings', 'read'], 'level' => 'account'],
    ];
}

/** Находит id группы права в списке CF: сперва по точным кандидатам, затем fuzzy по ключевым словам. */
function matchPermissionGroupId($preset, $byName, $allGroups) {
    foreach ((array)($preset['cf'] ?? []) as $cand) {
        $k = mb_strtolower($cand);
        if (isset($byName[$k])) return $byName[$k];
    }
    if (!empty($preset['match'])) {
        foreach ($allGroups as $g) {
            $n = mb_strtolower($g['name']);
            $ok = true;
            foreach ($preset['match'] as $kw) {
                if (mb_strpos($n, mb_strtolower($kw)) === false) { $ok = false; break; }
            }
            if ($ok) return $g['id'];
        }
    }
    return null;
}

/** Прямой вызов CF API мастер-токеном (отдельно от cloudflareApiRequest — там логика аккаунтов панели). */
function cfMasterApi($token, $method, $path, $body = null) {
    $ch = curl_init("https://api.cloudflare.com/client/v4/$path");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    curl_close($ch);
    $r = json_decode($raw, true);
    return is_array($r) ? $r : ['success' => false, 'errors' => [['message' => 'нет ответа от Cloudflare']]];
}
function cfErr($r) { return $r['errors'][0]['message'] ?? 'неизвестная ошибка'; }

/** Возвращает значение мастер-токена: из сохранённого (master_id) или введённого (master_token). */
function resolveMasterToken($pdo) {
    $mid = trim($_POST['master_id'] ?? '');
    if ($mid !== '' && ctype_digit($mid)) {
        $st = $pdo->prepare("SELECT token FROM master_tokens WHERE id = ?");
        $st->execute([$mid]);
        $t = $st->fetchColumn();
        if ($t) return $t;
    }
    return trim($_POST['master_token'] ?? '');
}

/** Импортирует все зоны (домены) аккаунта в панель под кредентал $credId. */
function mtImportZones($pdo, $userId, $credId, $email, $token, $groupId = null) {
    $proxies = function_exists('getProxies') ? getProxies($pdo, $userId) : [];
    $zr = cfFetchAllZones($pdo, $email, $token, $proxies, $userId, 'token');
    if (empty($zr['success'])) return ['ok' => false, 'error' => $zr['error'] ?? 'не удалось получить зоны', 'count' => 0];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO cloudflare_accounts (user_id, account_id, group_id, domain, server_ip, ssl_mode, zone_id) VALUES (?, ?, ?, ?, '0.0.0.0', NULL, ?)");
    $n = 0;
    foreach ($zr['zones'] as $zone) {
        $zn = is_object($zone) ? ($zone->name ?? null) : ($zone['name'] ?? null);
        $zid = is_object($zone) ? ($zone->id ?? '') : ($zone['id'] ?? '');
        if (!$zn) continue;
        dbRetryOnLock(function () use ($ins, $userId, $credId, $groupId, $zn, $zid) {
            $ins->execute([$userId, $credId, $groupId, $zn, $zid]);
        });
        $n++;
    }
    return ['ok' => true, 'count' => $n];
}

/** Создаёт child-токен мастером по списку ключей прав. Возвращает ['ok','token','id','missing','error']. */
function mtCreateToken($master, $name, $selected) {
    $pg = cfMasterApi($master, 'GET', 'user/tokens/permission_groups');
    if (empty($pg['success'])) return ['ok' => false, 'error' => 'нет доступа к группам прав: ' . cfErr($pg)];
    $byName = [];
    foreach ($pg['result'] as $g) $byName[mb_strtolower($g['name'])] = $g['id'];
    $byKey = [];
    foreach (masterTokenPreset() as $p) $byKey[$p['key']] = $p;
    $zoneGroups = []; $accountGroups = []; $missing = [];
    foreach ($selected as $key) {
        if (!isset($byKey[$key])) continue;
        $p = $byKey[$key];
        $id = matchPermissionGroupId($p, $byName, $pg['result']);
        if (!$id) { $missing[] = $p['label']; continue; }
        if ($p['level'] === 'account') $accountGroups[] = ['id' => $id]; else $zoneGroups[] = ['id' => $id];
    }
    $policies = [];
    if ($zoneGroups)    $policies[] = ['effect' => 'allow', 'resources' => ['com.cloudflare.api.account.zone.*' => '*'], 'permission_groups' => $zoneGroups];
    if ($accountGroups) $policies[] = ['effect' => 'allow', 'resources' => ['com.cloudflare.api.account.*' => '*'], 'permission_groups' => $accountGroups];
    if (!$policies) return ['ok' => false, 'error' => 'не удалось сопоставить права'];
    $res = cfMasterApi($master, 'POST', 'user/tokens', ['name' => $name, 'policies' => $policies]);
    if (empty($res['success'])) return ['ok' => false, 'error' => cfErr($res)];
    return ['ok' => true, 'token' => $res['result']['value'] ?? null, 'id' => $res['result']['id'] ?? null, 'missing' => $missing];
}

try {
    switch ($action) {
        case 'list_permissions':
            echo json_encode(['success' => true, 'preset' => masterTokenPreset()]);
            break;

        case 'create':
            $master   = resolveMasterToken($pdo);
            $name     = trim($_POST['name'] ?? '');
            $selected = $_POST['perms'] ?? [];
            if (!is_array($selected)) $selected = [];
            if ($master === '')   throw new Exception('Укажите мастер-токен');
            if (empty($selected)) throw new Exception('Выберите хотя бы одно право');
            if ($name === '')     $name = 'panel-token-' . date('Ymd-His');

            // 1) Проверяем мастер-токен и получаем список групп прав (name -> id)
            $pg = cfMasterApi($master, 'GET', 'user/tokens/permission_groups');
            if (empty($pg['success'])) {
                throw new Exception('Мастер-токен недействителен или у него нет права «Create Additional Tokens»: ' . cfErr($pg));
            }
            $byName = [];
            foreach ($pg['result'] as $g) $byName[mb_strtolower($g['name'])] = $g['id'];

            $byKey = [];
            foreach (masterTokenPreset() as $p) $byKey[$p['key']] = $p;

            $zoneGroups = [];
            $accountGroups = [];
            $missing = [];
            $granted = [];
            foreach ($selected as $key) {
                if (!isset($byKey[$key])) continue;
                $p  = $byKey[$key];
                $id = matchPermissionGroupId($p, $byName, $pg['result']);
                if (!$id) { $missing[] = $p['label']; continue; }
                $granted[] = $p['label'];
                if ($p['level'] === 'account') $accountGroups[] = ['id' => $id];
                else                            $zoneGroups[]    = ['id' => $id];
            }
            if (empty($zoneGroups) && empty($accountGroups)) {
                throw new Exception('Не удалось сопоставить выбранные права с группами Cloudflare: ' . implode(', ', $missing));
            }

            // 2) Политики: zone-права на ВСЕ зоны, account-права на ВСЕ аккаунты
            $policies = [];
            if ($zoneGroups) {
                $policies[] = ['effect' => 'allow', 'resources' => ['com.cloudflare.api.account.zone.*' => '*'], 'permission_groups' => $zoneGroups];
            }
            if ($accountGroups) {
                $policies[] = ['effect' => 'allow', 'resources' => ['com.cloudflare.api.account.*' => '*'], 'permission_groups' => $accountGroups];
            }

            // 3) Создаём токен
            $res = cfMasterApi($master, 'POST', 'user/tokens', ['name' => $name, 'policies' => $policies]);
            if (empty($res['success'])) {
                logAction($pdo, $userId, 'Master Token: ошибка создания', cfErr($res) . " | права: " . implode(',', $selected));
                throw new Exception('Cloudflare отклонил создание токена: ' . cfErr($res));
            }

            logAction($pdo, $userId, 'Создан API-токен через мастер', "Токен «{$name}» создан с " . count($granted) . " правами: " . implode(', ', $granted) . ($missing ? ". НЕ найдены: " . implode(', ', $missing) : ''));

            // Если создавали из СОХРАНЁННОГО мастера — подтянем домены новым child-токеном
            // (у него есть Zone Read) и привяжем подсказку к мастеру. Сам мастер зоны не видит.
            $mid = trim($_POST['master_id'] ?? '');
            $newToken = $res['result']['value'] ?? null;
            if ($mid !== '' && ctype_digit($mid) && $newToken) {
                $z = cfMasterApi($newToken, 'GET', 'zones?per_page=50');
                if (!empty($z['success'])) {
                    $names = array_map(function ($zone) { return $zone['name']; }, $z['result']);
                    $total = $z['result_info']['total_count'] ?? count($names);
                    $hint = $total . ' доменов: ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? '…' : '');
                    dbRetryOnLock(function () use ($pdo, $hint, $mid) {
                        $pdo->prepare("UPDATE master_tokens SET domains_hint = ? WHERE id = ?")->execute([$hint, $mid]);
                    });
                }
            }

            // Авто-сохранение токена как аккаунта панели — чтобы он не потерялся
            // (CF показывает значение один раз) и сразу был доступен для добавления доменов.
            $savedAs = null;
            if ($newToken) {
                try {
                    $ex = $pdo->prepare("SELECT id FROM cloudflare_credentials WHERE user_id = ? AND api_key = ?");
                    $ex->execute([$userId, $newToken]);
                    if (!$ex->fetchColumn()) {
                        $an = '';
                        $acc2 = cfMasterApi($newToken, 'GET', 'accounts?per_page=1');
                        if (!empty($acc2['success']) && !empty($acc2['result'][0]['name'])) $an = $acc2['result'][0]['name'];
                        $em = $an ?: ('token-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $newToken), -8));
                        $b = $em; $k = 2;
                        while (true) {
                            $c = $pdo->prepare("SELECT 1 FROM cloudflare_credentials WHERE user_id = ? AND email = ?");
                            $c->execute([$userId, $em]);
                            if (!$c->fetchColumn()) break;
                            $em = $b . ' #' . $k; $k++;
                        }
                        dbRetryOnLock(function () use ($pdo, $userId, $em, $newToken) {
                            $pdo->prepare("INSERT INTO cloudflare_credentials (user_id, email, api_key, auth_type) VALUES (?, ?, ?, 'token')")->execute([$userId, $em, $newToken]);
                        });
                        $credId = (int)$pdo->lastInsertId();
                        $grp = $pdo->query("SELECT id FROM groups WHERE user_id = $userId ORDER BY id LIMIT 1")->fetchColumn();
                        $imp = mtImportZones($pdo, $userId, $credId, $em, $newToken, $grp ?: null);
                        $importedCount = $imp['count'] ?? 0;
                        logAction($pdo, $userId, 'Аккаунт добавлен в панель', "авто после создания токена: {$em}, импортировано доменов: {$importedCount}");
                        $savedAs = $em . ' (импортировано доменов: ' . $importedCount . ')';
                    }
                } catch (Exception $e) { /* не критично */ }
            }

            echo json_encode([
                'success' => true,
                'saved_as' => $savedAs,
                'token'   => $newToken,
                'id'      => $res['result']['id'] ?? null,
                'name'    => $name,
                'missing' => $missing,
            ]);
            break;

        case 'add_master':
            $tok   = trim($_POST['master_token'] ?? '');
            $label = trim($_POST['label'] ?? '');
            if ($tok === '') throw new Exception('Вставьте мастер-токен');
            // Проверяем валидность токена
            $v = cfMasterApi($tok, 'GET', 'user/tokens/verify');
            if (empty($v['success'])) throw new Exception('Токен недействителен: ' . cfErr($v));
            // Пытаемся узнать email аккаунта (если у токена есть доступ — иначе пусто)
            $email = '';
            $u = cfMasterApi($tok, 'GET', 'user');
            if (!empty($u['success'])) $email = $u['result']['email'] ?? '';
            if ($label === '') $label = $email ?: ('master-' . date('Ymd-His'));
            dbRetryOnLock(function () use ($pdo, $label, $tok, $email) {
                $pdo->prepare("INSERT INTO master_tokens (label, token, account_email) VALUES (?, ?, ?)")->execute([$label, $tok, $email]);
            });
            logAction($pdo, $userId, 'Master Token: сохранён мастер', "label: {$label}");
            echo json_encode(['success' => true]);
            break;

        case 'list_masters':
            $rows = $pdo->query("SELECT id, label, account_email, domains_hint, token FROM master_tokens ORDER BY id DESC")->fetchAll();
            $out = array_map(function ($r) {
                return [
                    'id' => $r['id'],
                    'label' => $r['label'],
                    'email' => $r['account_email'],
                    'domains_hint' => $r['domains_hint'],
                    'masked' => mb_substr($r['token'], 0, 10) . '…' . mb_substr($r['token'], -4),
                ];
            }, $rows);
            echo json_encode(['success' => true, 'masters' => $out]);
            break;

        // [monopanel] Живой статус каждого сохранённого мастер-токена (Cloudflare verify).
        case 'masters_status':
            $rows = $pdo->query("SELECT id, label, account_email, domains_hint, token FROM master_tokens ORDER BY id DESC")->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $v = cfMasterApi($r['token'], 'GET', 'user/tokens/verify');
                $status = !empty($v['success']) ? ($v['result']['status'] ?? 'active') : 'invalid';
                $out[] = [
                    'id' => $r['id'],
                    'label' => $r['label'],
                    'email' => $r['account_email'],
                    'domains_hint' => $r['domains_hint'],
                    'masked' => mb_substr($r['token'], 0, 10) . '…' . mb_substr($r['token'], -4),
                    'status' => $status,
                    'ok' => ($status === 'active'),
                ];
            }
            echo json_encode(['success' => true, 'masters' => $out]);
            break;

        // [monopanel] Перевыпустить токен домену: создать дочерний токен выбранным мастером
        // и ПЕРЕПРИВЯЗАТЬ домен к нему (лечит домены, застрявшие на старом/отозванном токене).
        case 'reissue_domain_token':
            $domainId = (int)($_POST['domain_id'] ?? 0);
            if ($domainId <= 0) throw new Exception('Не указан домен');
            $st = $pdo->prepare("SELECT id, domain FROM cloudflare_accounts WHERE id = ? AND user_id = ?");
            $st->execute([$domainId, $userId]);
            $dom = $st->fetch();
            if (!$dom) throw new Exception('Домен не найден');
            $domName = $dom['domain'];
            $mode = $_POST['mode'] ?? '';

            // --- Режим АВТО: перебрать ВСЕ сохранённые аккаунты панели (и token, и global-key)
            //     и найти тот, который реально управляет этой зоной. Новый токен не создаём. ---
            if ($mode === 'auto') {
                $creds = $pdo->query("SELECT id, email, api_key, auth_type FROM cloudflare_credentials WHERE user_id = $userId ORDER BY id DESC")->fetchAll();
                $proxies = function_exists('getProxies') ? getProxies($pdo, $userId) : [];
                $bound = null; $boundZone = '';
                foreach ($creds as $c) {
                    // userId=null у проб — чтобы не засорять логи ошибками по мёртвым аккаунтам.
                    $z = cloudflareApiRequestDetailed($pdo, $c['email'], $c['api_key'], 'zones?name=' . rawurlencode($domName), 'GET', [], $proxies, null, $c['auth_type'] ?? null);
                    if (empty($z['success']) || empty($z['data'])) continue;
                    $zoneId = $z['data'][0]->id ?? '';
                    if ($zoneId === '') continue;
                    $chk = cloudflareApiRequestDetailed($pdo, $c['email'], $c['api_key'], "zones/$zoneId/dns_records?per_page=1", 'GET', [], $proxies, null, $c['auth_type'] ?? null);
                    if (empty($chk['success'])) continue;
                    // Перепривязка — с повтором при «database is locked» (фоновые cf-queue/cf-monitor
                    // могут держать запись в момент клика «Перевыпустить»).
                    dbRetryOnLock(function () use ($pdo, $c, $zoneId, $domainId, $userId) {
                        $pdo->prepare("UPDATE cloudflare_accounts SET account_id = ?, zone_id = ? WHERE id = ? AND user_id = ?")->execute([$c['id'], $zoneId, $domainId, $userId]);
                    });
                    $bound = $c; $boundZone = $zoneId;
                    break;
                }
                if ($bound) {
                    logAction($pdo, $userId, 'Перевыпущен токен домена', "{$domName}: авто-привязка к аккаунту {$bound['email']}, зона {$boundZone}");
                    echo json_encode(['success' => true, 'domain' => $domName, 'zone_ok' => true, 'dns_ok' => true, 'via' => $bound['email'], 'mode' => 'auto']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Среди сохранённых аккаунтов панели ни один не управляет доменом ' . $domName . '. Добавьте (на вкладке «Мастер-токен») токен того аккаунта Cloudflare, где заведён домен, затем повторите «Авто» или выберите этот мастер вручную.']);
                }
                break;
            }

            // --- Режим МАСТЕР: создать дочерний токен выбранным мастером ---
            $master = resolveMasterToken($pdo);
            if ($master === '') throw new Exception('Выберите мастер-токен или режим «Авто»');
            $r = mtCreateToken($master, 'panel-token-' . date('Ymd-His'), array_column(masterTokenPreset(), 'key'));
            if (empty($r['ok'])) throw new Exception('Не удалось создать токен: ' . ($r['error'] ?? 'неизвестно'));
            $tok = $r['token'];
            if (!$tok) throw new Exception('Cloudflare не вернул значение токена');
            // Проверяем, что этот токен ВИДИТ зону (иначе мастер из другого аккаунта — не привязываем).
            $zoneId = '';
            $z = cfMasterApi($tok, 'GET', 'zones?name=' . rawurlencode($domName));
            if (!empty($z['success']) && !empty($z['result'][0]['id'])) $zoneId = $z['result'][0]['id'];
            if ($zoneId === '') {
                logAction($pdo, $userId, 'Перевыпуск токена: мимо', "{$domName}: выбранный мастер не управляет доменом (зона не найдена)");
                echo json_encode(['success' => false, 'error' => 'Выбранный мастер-токен НЕ из того аккаунта Cloudflare — домен ' . $domName . ' в нём не найден. Выберите мастер аккаунта, где заведён домен, или режим «Авто». (Домен не тронут.)']);
                break;
            }
            $chk = cfMasterApi($tok, 'GET', "zones/$zoneId/dns_records?per_page=1");
            if (empty($chk['success'])) {
                echo json_encode(['success' => false, 'error' => 'Токен создан, но нет доступа к DNS зоны — проверьте право DNS:Edit у мастера. Домен не перепривязан.']);
                break;
            }
            // upsert креденшла (как save_as_account) и ПЕРЕПРИВЯЗКА
            $ex = $pdo->prepare("SELECT id FROM cloudflare_credentials WHERE user_id = ? AND api_key = ?");
            $ex->execute([$userId, $tok]);
            $credId = (int)($ex->fetchColumn() ?: 0);
            if (!$credId) {
                $nm = '';
                $acc = cfMasterApi($tok, 'GET', 'accounts?per_page=1');
                if (!empty($acc['success']) && !empty($acc['result'][0]['name'])) $nm = $acc['result'][0]['name'];
                $email = $nm ?: ('token-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $tok), -8));
                $base = $email; $i = 2;
                while (true) {
                    $c = $pdo->prepare("SELECT 1 FROM cloudflare_credentials WHERE user_id = ? AND email = ?");
                    $c->execute([$userId, $email]);
                    if (!$c->fetchColumn()) break;
                    $email = $base . ' #' . $i; $i++;
                }
                dbRetryOnLock(function () use ($pdo, $userId, $email, $tok) {
                    $pdo->prepare("INSERT INTO cloudflare_credentials (user_id, email, api_key, auth_type) VALUES (?, ?, ?, 'token')")->execute([$userId, $email, $tok]);
                });
                $credId = (int)$pdo->lastInsertId();
            }
            dbRetryOnLock(function () use ($pdo, $credId, $zoneId, $domainId, $userId) {
                $pdo->prepare("UPDATE cloudflare_accounts SET account_id = ?, zone_id = ? WHERE id = ? AND user_id = ?")->execute([$credId, $zoneId, $domainId, $userId]);
            });
            logAction($pdo, $userId, 'Перевыпущен токен домена', "{$domName}: новый токен, зона {$zoneId}, DNS доступ: да");
            echo json_encode(['success' => true, 'domain' => $domName, 'zone_ok' => true, 'dns_ok' => true, 'masked' => mb_substr($tok, 0, 10) . '…' . mb_substr($tok, -4)]);
            break;

        case 'import_empty':
            // Импорт/обновление зон по ВСЕМ токен-аккаунтам панели: подтягивает недостающие
            // домены из Cloudflare (INSERT OR IGNORE — существующие не трогаются). Так в панель
            // попадают зоны, созданные в CF после добавления аккаунта (напр. новые домены).
            $creds = $pdo->query("SELECT cc.id, cc.email, cc.api_key FROM cloudflare_credentials cc
                WHERE cc.user_id = $userId AND COALESCE(cc.auth_type,'') = 'token'")->fetchAll();
            $grp = $pdo->query("SELECT id FROM groups WHERE user_id = $userId ORDER BY id LIMIT 1")->fetchColumn();
            $report = [];
            foreach ($creds as $c) {
                $imp = mtImportZones($pdo, $userId, $c['id'], $c['email'], $c['api_key'], $grp ?: null);
                $report[] = ['account' => $c['email'], 'ok' => !empty($imp['ok']), 'count' => $imp['count'] ?? 0, 'error' => $imp['error'] ?? null];
            }
            logAction($pdo, $userId, 'Импорт доменов в пустые аккаунты', 'аккаунтов обработано: ' . count($creds));
            echo json_encode(['success' => true, 'report' => $report]);
            break;

        case 'save_as_account':
            // Сохранить токен как аккаунт панели. Дедуп: один CF-аккаунт = один кредентал
            // (ключ — cf_account_uid). Повторный токен того же аккаунта не плодит дубль,
            // а обновляет существующий и досинхронизирует домены.
            $tok = trim($_POST['token'] ?? '');
            $label = trim($_POST['label'] ?? '');
            if ($tok === '') throw new Exception('Нет токена для сохранения');

            // Имя и UID аккаунта из CF (нужны Account Settings:Read).
            $name = ''; $uid = '';
            $acc = cfMasterApi($tok, 'GET', 'accounts?per_page=1');
            if (!empty($acc['success']) && !empty($acc['result'][0])) {
                $name = $acc['result'][0]['name'] ?? '';
                $uid  = $acc['result'][0]['id'] ?? '';
            }
            $grp = $pdo->query("SELECT id FROM groups WHERE user_id = $userId ORDER BY id LIMIT 1")->fetchColumn();

            // Существующий кредентал того же аккаунта: сначала по UID, затем по точному токену.
            $existing = null;
            if ($uid !== '') {
                $q = $pdo->prepare("SELECT id, email FROM cloudflare_credentials WHERE user_id = ? AND cf_account_uid = ? LIMIT 1");
                $q->execute([$userId, $uid]);
                $existing = $q->fetch() ?: null;
            }
            if (!$existing) {
                $q = $pdo->prepare("SELECT id, email FROM cloudflare_credentials WHERE user_id = ? AND api_key = ? LIMIT 1");
                $q->execute([$userId, $tok]);
                $existing = $q->fetch() ?: null;
            }

            if ($existing) {
                // Обновляем токен и UID существующего аккаунта, досинхронизируем домены — без дубля.
                dbRetryOnLock(function () use ($pdo, $existing, $tok, $uid) {
                    $pdo->prepare("UPDATE cloudflare_credentials SET api_key = ?, auth_type = 'token',
                        cf_account_uid = COALESCE(NULLIF(?, ''), cf_account_uid) WHERE id = ?")
                        ->execute([$tok, $uid, $existing['id']]);
                });
                $imp = mtImportZones($pdo, $userId, (int)$existing['id'], $existing['email'], $tok, $grp ?: null);
                logAction($pdo, $userId, 'Аккаунт уже в панели — обновление токена и доменов',
                    "{$existing['email']}: доменов: " . ($imp['count'] ?? 0));
                echo json_encode(['success' => true, 'already' => true,
                    'imported' => $imp['count'] ?? 0, 'import_error' => $imp['error'] ?? null]);
                break;
            }

            // Новый аккаунт.
            $email = $label ?: ($name ?: ('token-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $tok), -8)));
            $base = $email; $i = 2;
            while (true) {
                $c = $pdo->prepare("SELECT 1 FROM cloudflare_credentials WHERE user_id = ? AND email = ?");
                $c->execute([$userId, $email]);
                if (!$c->fetchColumn()) break;
                $email = $base . ' #' . $i; $i++;
            }
            dbRetryOnLock(function () use ($pdo, $userId, $email, $tok, $uid) {
                $pdo->prepare("INSERT INTO cloudflare_credentials (user_id, email, api_key, auth_type, cf_account_uid) VALUES (?, ?, ?, 'token', ?)")
                    ->execute([$userId, $email, $tok, $uid !== '' ? $uid : null]);
            });
            $credId = (int)$pdo->lastInsertId();
            $imp = mtImportZones($pdo, $userId, $credId, $email, $tok, $grp ?: null);
            logAction($pdo, $userId, 'Аккаунт добавлен в панель', "через мастер-токен: {$email}, доменов: " . ($imp['count'] ?? 0));
            echo json_encode(['success' => true, 'label' => $email, 'imported' => $imp['count'] ?? 0]);
            break;

        case 'dedup_accounts':
            // Чистка дублей: один CF-аккаунт мог попасть в панель несколько раз (Account, #2, #3).
            // Заполняем cf_account_uid где пусто, группируем по UID (фоллбэк — имя без " #N"),
            // оставляем один кредентал, перецепляем домены и токены, лишние удаляем.
            $creds = $pdo->query("SELECT id, email, api_key, cf_account_uid, auth_type
                FROM cloudflare_credentials WHERE user_id = $userId ORDER BY id")->fetchAll();

            // 1) Досоздать UID где пусто (best-effort по токену аккаунта).
            foreach ($creds as &$c) {
                if (empty($c['cf_account_uid']) && !empty($c['api_key'])) {
                    $a = cfMasterApi($c['api_key'], 'GET', 'accounts?per_page=1');
                    if (!empty($a['success']) && !empty($a['result'][0]['id'])) {
                        $c['cf_account_uid'] = $a['result'][0]['id'];
                        $uidVal = $c['cf_account_uid']; $cid = $c['id'];
                        dbRetryOnLock(function () use ($pdo, $uidVal, $cid) {
                            $pdo->prepare("UPDATE cloudflare_credentials SET cf_account_uid = ? WHERE id = ?")->execute([$uidVal, $cid]);
                        });
                    }
                }
            }
            unset($c);

            // 2) Группировка.
            $groups = [];
            foreach ($creds as $c) {
                $key = !empty($c['cf_account_uid'])
                    ? ('uid:' . $c['cf_account_uid'])
                    : ('name:' . preg_replace('/\s+#\d+$/', '', $c['email']));
                $groups[$key][] = $c;
            }

            // 3) Слияние.
            $mergedGroups = 0; $deleted = 0; $report = [];
            foreach ($groups as $items) {
                if (count($items) < 2) continue;
                usort($items, function ($a, $b) {
                    $as = preg_match('/\s+#\d+$/', $a['email']) ? 1 : 0;
                    $bs = preg_match('/\s+#\d+$/', $b['email']) ? 1 : 0;
                    if ($as !== $bs) return $as - $bs;      // без суффикса — приоритетнее
                    return (int)$a['id'] - (int)$b['id'];   // затем меньший id
                });
                $keep = $items[0];
                $dupEmails = [];
                for ($k = 1; $k < count($items); $k++) {
                    $dup = $items[$k];
                    dbRetryOnLock(function () use ($pdo, $userId, $dup, $keep) {
                        $pdo->prepare("UPDATE cloudflare_accounts SET account_id = ? WHERE user_id = ? AND account_id = ?")->execute([$keep['id'], $userId, $dup['id']]);
                        $pdo->prepare("UPDATE cloudflare_api_tokens SET account_id = ? WHERE user_id = ? AND account_id = ?")->execute([$keep['id'], $userId, $dup['id']]);
                        $pdo->prepare("DELETE FROM cloudflare_credentials WHERE id = ? AND user_id = ?")->execute([$dup['id'], $userId]);
                    });
                    $dupEmails[] = $dup['email'];
                    $deleted++;
                }
                $mergedGroups++;
                $report[] = ['keep' => $keep['email'], 'removed' => $dupEmails];
            }
            logAction($pdo, $userId, 'Дедуп аккаунтов', "групп: $mergedGroups, удалено дублей: $deleted");
            echo json_encode(['success' => true, 'merged_groups' => $mergedGroups, 'deleted' => $deleted,
                'total' => count($creds), 'report' => $report]);
            break;

        case 'delete_master':
            $mid = trim($_POST['id'] ?? '');
            if ($mid === '' || !ctype_digit($mid)) throw new Exception('Не указан id');
            $pdo->prepare("DELETE FROM master_tokens WHERE id = ?")->execute([$mid]);
            echo json_encode(['success' => true]);
            break;

        case 'create_zones':
            // Добавление доменов (создание зон) в аккаунт через сохранённый мастер-токен.
            $mid = trim($_POST['master_id'] ?? '');
            $raw = trim($_POST['domains'] ?? '');
            if ($mid === '' || !ctype_digit($mid)) throw new Exception('Выберите сохранённый мастер-токен');
            $domains = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', mb_strtolower($raw))))));
            if (empty($domains)) throw new Exception('Укажите хотя бы один домен');

            $row = $pdo->prepare("SELECT token, working_token FROM master_tokens WHERE id = ?");
            $row->execute([$mid]);
            $m = $row->fetch();
            if (!$m) throw new Exception('Мастер-токен не найден');

            // Получаем/создаём рабочий токен (15 прав) — он умеет создавать зоны
            $work = (string)($m['working_token'] ?? '');
            if ($work === '') {
                $allKeys = array_map(function ($p) { return $p['key']; }, masterTokenPreset());
                $mk = mtCreateToken($m['token'], 'panel-worker-' . date('Ymd-His'), $allKeys);
                if (empty($mk['ok'])) throw new Exception('Не удалось создать рабочий токен: ' . ($mk['error'] ?? ''));
                $work = $mk['token'];
                dbRetryOnLock(function () use ($pdo, $work, $mid) {
                    $pdo->prepare("UPDATE master_tokens SET working_token = ? WHERE id = ?")->execute([$work, $mid]);
                });
            }

            // account_id (нужен для POST /zones) + имя аккаунта
            $acc = cfMasterApi($work, 'GET', 'accounts?per_page=1');
            if (empty($acc['success']) || empty($acc['result'][0]['id'])) {
                throw new Exception('Не удалось получить account_id — у токена нет права Account Settings (Read). Пересоздайте мастер/токен с 15 правами. ' . cfErr($acc));
            }
            $accountId = $acc['result'][0]['id'];
            $accountName = $acc['result'][0]['name'] ?? '';

            // Кредентал в панели под рабочий токен (чтобы домены были управляемы)
            $email = 'master#' . $mid . ($accountName ? ' ' . $accountName : '');
            dbRetryOnLock(function () use ($pdo, $userId, $email, $work) {
                $pdo->prepare("INSERT OR IGNORE INTO cloudflare_credentials (user_id, email, api_key, auth_type) VALUES (?, ?, ?, 'token')")->execute([$userId, $email, $work]);
            });
            $credId = $pdo->query("SELECT id FROM cloudflare_credentials WHERE user_id = $userId AND email = " . $pdo->quote($email))->fetchColumn();
            $grp = $pdo->query("SELECT id FROM groups WHERE user_id = $userId ORDER BY id LIMIT 1")->fetchColumn();

            $results = [];
            foreach ($domains as $dom) {
                $z = cfMasterApi($work, 'POST', 'zones', ['name' => $dom, 'account' => ['id' => $accountId], 'type' => 'full']);
                if (!empty($z['success'])) {
                    $zid = $z['result']['id'] ?? '';
                    $ns  = $z['result']['name_servers'] ?? [];
                    try {
                        dbRetryOnLock(function () use ($pdo, $userId, $credId, $grp, $dom, $zid, $ns) {
                            $pdo->prepare("INSERT OR IGNORE INTO cloudflare_accounts (user_id, account_id, group_id, domain, server_ip, zone_id, ns_records, domain_status) VALUES (?, ?, ?, ?, '', ?, ?, 'unknown')")
                                ->execute([$userId, $credId, $grp ?: null, $dom, $zid, json_encode($ns)]);
                        });
                    } catch (Exception $e) {}
                    $results[] = ['domain' => $dom, 'ok' => true, 'ns' => $ns];
                } else {
                    $results[] = ['domain' => $dom, 'ok' => false, 'error' => cfErr($z)];
                }
            }
            logAction($pdo, $userId, 'Master Token: добавлены домены', 'account: ' . ($accountName ?: $accountId) . ', доменов: ' . count($domains));
            echo json_encode(['success' => true, 'account' => $accountName ?: $accountId, 'results' => $results]);
            break;

        case 'list_groups':
            // DEBUG: показать группы прав, относящиеся к редиректам/трансформам —
            // чтобы найти точное имя группы Single Redirect в этом аккаунте.
            $master = resolveMasterToken($pdo);
            if ($master === '') throw new Exception('Укажите мастер-токен');
            $pg = cfMasterApi($master, 'GET', 'user/tokens/permission_groups');
            if (empty($pg['success'])) throw new Exception('Не удалось получить группы: ' . cfErr($pg));
            $q = mb_strtolower(trim($_POST['q'] ?? ''));
            $kw = $q !== '' ? [$q] : ['redirect', 'transform', 'single', 'account settings', 'zone create'];
            $found = [];
            foreach ($pg['result'] as $g) {
                $n = mb_strtolower($g['name']);
                foreach ($kw as $k) {
                    if (mb_strpos($n, $k) !== false) {
                        $found[] = ['name' => $g['name'], 'scopes' => $g['scopes'] ?? []];
                        break;
                    }
                }
            }
            echo json_encode(['success' => true, 'total' => count($pg['result']), 'matched' => $found]);
            break;

        case 'list_tokens':
            $master = resolveMasterToken($pdo);
            if ($master === '') throw new Exception('Укажите мастер-токен');
            $res = cfMasterApi($master, 'GET', 'user/tokens?per_page=50');
            if (empty($res['success'])) throw new Exception('Не удалось получить список токенов: ' . cfErr($res));
            $tokens = [];
            foreach ($res['result'] as $t) {
                // Собираем имена групп прав по всем политикам токена
                $perms = [];
                foreach ($t['policies'] ?? [] as $pol) {
                    foreach ($pol['permission_groups'] ?? [] as $g) {
                        if (!empty($g['name'])) $perms[$g['name']] = true;
                    }
                }
                $tokens[] = [
                    'id'      => $t['id'],
                    'name'    => $t['name'] ?? '(без имени)',
                    'status'  => $t['status'] ?? '',
                    'perms'   => array_keys($perms),
                    'count'   => count($perms),
                ];
            }
            echo json_encode(['success' => true, 'tokens' => $tokens]);
            break;

        case 'delete_token':
            $master = resolveMasterToken($pdo);
            $tokenId = trim($_POST['token_id'] ?? '');
            if ($master === '')  throw new Exception('Укажите мастер-токен');
            if ($tokenId === '') throw new Exception('Не указан id токена');
            $res = cfMasterApi($master, 'DELETE', 'user/tokens/' . rawurlencode($tokenId));
            if (empty($res['success'])) throw new Exception('Не удалось удалить токен: ' . cfErr($res));
            logAction($pdo, $userId, 'Master Token: удалён токен', "id: {$tokenId}");
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    // Фиксируем сбой в разделе «Логи» (logAction сам повторит запись при блокировке БД),
    // чтобы ошибки вроде «database is locked» были видны в панели, а не только в тосте.
    if (isset($pdo)) {
        $act = $action !== '' ? $action : 'master_token_api';
        logActionSafe($pdo, $userId ?? 1, 'Ошибка перевыпуска/операции токена', "{$act}: " . $e->getMessage());
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
