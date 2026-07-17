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

/**
 * Реальное имя аккаунта Cloudflare по токену — тонкая обёртка над единым резолвером
 * cfResolveAccount (functions.php). Возвращает '' если имя получить не удалось.
 */
function mtResolveAccountName($pdo, $token, $proxies = []) {
    $r = cfResolveAccount($pdo, '', $token, $proxies, null, 'token');
    return $r ? (string)($r['name'] ?? '') : '';
}

/** Является ли имя аккаунта заглушкой (token-XXXX / master#NN…), а не реальным именем CF. */
function mtIsPlaceholderName($email) {
    return (bool)preg_match('/^\s*(token-[A-Za-z0-9]+|master#\d+)/u', (string)$email);
}

/**
 * id первой группы пользователя. Курсор закрываем ЯВНО (closeCursor): pdo_sqlite строг к
 * MISUSE (ошибка 21) — незакрытый временный курсор SELECT ломает финализацию следующего
 * statement (напр. INSERT в mtImportZones). Возвращает id или false.
 */
function mtFirstGroupId($pdo, $userId) {
    $st = $pdo->query("SELECT id FROM groups WHERE user_id = " . (int)$userId . " ORDER BY id LIMIT 1");
    $id = $st->fetchColumn();
    $st->closeCursor();
    return $id;
}

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

    // Сеть уже завершена — собираем строки БЕЗ сети, затем пишем ОДНОЙ короткой транзакцией
    // (BEGIN IMMEDIATE берёт write-лок один раз). Построчный dbRetryOnLock под фоновыми
    // писателями давал шторм блокировок → таймауты и «bad parameter or other API misuse».
    $rows = [];
    foreach ($zr['zones'] as $zone) {
        $zn  = is_object($zone) ? ($zone->name ?? null) : ($zone['name'] ?? null);
        $zid = is_object($zone) ? ($zone->id ?? '')   : ($zone['id'] ?? '');
        if (!$zn) continue;
        $rows[] = [$zn, $zid];
    }
    if (!$rows) return ['ok' => true, 'count' => 0];

    $n = 0;
    dbRetryOnLock(function () use ($pdo, $userId, $credId, $groupId, $rows, &$n) {
        $pdo->exec('PRAGMA busy_timeout = 60000');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $ins = $pdo->prepare("INSERT OR IGNORE INTO cloudflare_accounts (user_id, account_id, group_id, domain, server_ip, ssl_mode, zone_id) VALUES (?, ?, ?, ?, '0.0.0.0', NULL, ?)");
            $n = 0;
            foreach ($rows as $r) { $ins->execute([$userId, $credId, $groupId, $r[0], $r[1]]); $n++; }
            $ins->closeCursor();
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignore) {}
            throw $e;
        }
    });
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

/**
 * Считает план слияния дублей аккаунтов БЕЗ записи в БД: группирует кредентала
 * по нормализованному имени (« #N» отбрасывается), разводя по разным cf_account_uid,
 * и выбирает «главного» по баллу (мастер-токен → scoped-токен → auth_type=token →
 * ключ/uid → домены). Один источник правды для превью (dedup_preview, только чтение)
 * и применения (dedup_accounts, внутри BEGIN IMMEDIATE).
 * Возвращает ['merges'=>[...], 'total'=>int, 'dom_count'=>[account_id=>N]].
 */
function mtDedupComputeMerges($pdo, $userId) {
    $userId = (int)$userId;
    $baseName = function ($email) { return preg_replace('/\s*#\d+$/', '', trim((string)$email)); };

    $creds = $pdo->query("SELECT id, email, api_key, cf_account_uid, auth_type
        FROM cloudflare_credentials WHERE user_id = $userId ORDER BY id")->fetchAll();
    $total = count($creds);

    $masterTokset = [];
    foreach ($pdo->query("SELECT token FROM master_tokens WHERE token IS NOT NULL AND token <> ''")
                ->fetchAll(PDO::FETCH_COLUMN) as $mt) { $masterTokset[$mt] = true; }
    $scopedCount = []; $domCount = [];
    foreach ($pdo->query("SELECT account_id, COUNT(*) c FROM cloudflare_api_tokens WHERE user_id = $userId GROUP BY account_id")->fetchAll() as $r) {
        $scopedCount[(int)$r['account_id']] = (int)$r['c'];
    }
    foreach ($pdo->query("SELECT account_id, COUNT(*) c FROM cloudflare_accounts WHERE user_id = $userId GROUP BY account_id")->fetchAll() as $r) {
        $domCount[(int)$r['account_id']] = (int)$r['c'];
    }
    // Балл «главности»: мастер-токен → кастом-токен → auth_type=token → ключ/uid → домены.
    $valueScore = function ($c) use ($masterTokset, $scopedCount, $domCount) {
        $id = (int)$c['id'];
        $hasMaster = !empty($c['api_key']) && isset($masterTokset[$c['api_key']]);
        $hasScoped = !empty($scopedCount[$id]);
        $isToken   = ($c['auth_type'] ?? '') === 'token';
        $hasKey    = !empty($c['api_key']);
        $hasUid    = !empty($c['cf_account_uid']);
        return ($hasMaster ? 1 : 0) * 100000 + ($hasScoped ? 1 : 0) * 10000
             + ($isToken ? 1 : 0) * 1000 + ($hasKey ? 1 : 0) * 100 + ($hasUid ? 1 : 0) * 10
             + min((int)($domCount[$id] ?? 0), 9);
    };

    // Группировка по РЕАЛЬНОМУ аккаунту: приоритет cf_account_uid (шаг 3 «моста»).
    // Кредентала с одним uid сливаются даже при разных именах. Кредентала без uid
    // (токен не резолвится) группируются по имени и подклеиваются к uid-группе с тем
    // же именем, если такая ровно одна (сохраняет прежнюю склейку по имени).
    $groups = [];
    $nameToUid = [];   // baseName(lower) => uidKey | false (имя у разных uid — неоднозначно)
    foreach ($creds as $c) {
        $uid = trim((string)($c['cf_account_uid'] ?? ''));
        if ($uid === '') continue;
        $key = 'uid:' . $uid;
        $groups[$key][] = $c;
        $bn = strtolower($baseName($c['email']));
        if (!array_key_exists($bn, $nameToUid)) $nameToUid[$bn] = $key;
        elseif ($nameToUid[$bn] !== $key)       $nameToUid[$bn] = false;
    }
    foreach ($creds as $c) {
        $uid = trim((string)($c['cf_account_uid'] ?? ''));
        if ($uid !== '') continue;
        $bn = strtolower($baseName($c['email']));
        if (isset($nameToUid[$bn]) && $nameToUid[$bn] !== false) $groups[$nameToUid[$bn]][] = $c;
        else                                                     $groups['name:' . $bn][] = $c;
    }

    $merges = [];
    foreach ($groups as $items) {
        if (count($items) < 2) continue;
        usort($items, function ($a, $b) use ($valueScore) {
            $sa = $valueScore($a); $sb = $valueScore($b);
            if ($sa !== $sb) return $sb - $sa;
            $as = preg_match('/\s*#\d+$/', $a['email']) ? 1 : 0;
            $bs = preg_match('/\s*#\d+$/', $b['email']) ? 1 : 0;
            if ($as !== $bs) return $as - $bs;
            return (int)$a['id'] - (int)$b['id'];
        });
        $keep = $items[0];
        $removeIds = []; $removeEmails = [];
        for ($k = 1; $k < count($items); $k++) {
            $removeIds[]    = (int)$items[$k]['id'];
            $removeEmails[] = $items[$k]['email'];
        }
        $bestKey = $keep['api_key']; $bestAuth = $keep['auth_type']; $bestUid = $keep['cf_account_uid'];
        foreach ($items as $it) {
            if (empty($bestKey) && !empty($it['api_key'])) { $bestKey = $it['api_key']; $bestAuth = $it['auth_type']; }
            if (empty($bestUid) && !empty($it['cf_account_uid'])) { $bestUid = $it['cf_account_uid']; }
        }
        $merges[] = [
            'keep'          => (int)$keep['id'],
            'keep_email'    => $keep['email'],
            'remove'        => $removeIds,
            'remove_emails' => $removeEmails,
            'name'          => $baseName($keep['email']),
            'api_key'       => $bestKey,
            'auth_type'     => $bestAuth ?: 'token',
            'uid'           => $bestUid,
        ];
    }
    return ['merges' => $merges, 'total' => $total, 'dom_count' => $domCount];
}

/**
 * Массовая переклейка доменов на кредентал, чей токен РЕАЛЬНО владеет зоной в Cloudflare
 * (fetch зон на кредентал). По факту владения — не зависит от cf_account_uid. Идемпотентно:
 * если текущий account_id уже владеет зоной — не трогаем (лишь дозаполняем zone_id).
 * Возвращает ['relinked','ok','orphan','dead_creds','report'].
 */
function mtRelinkDomains($pdo, $userId, $proxies = []) {
    $userId = (int)$userId;
    $creds = $pdo->query("SELECT id, email, api_key, COALESCE(auth_type,'global') AS auth_type
        FROM cloudflare_credentials WHERE user_id = $userId ORDER BY id")->fetchAll();

    $zoneOwners = []; $credPref = []; $emailById = []; $deadCreds = [];
    foreach ($creds as $c) {
        $cid = (int)$c['id'];
        $emailById[$cid] = $c['email'];
        $credPref[$cid]  = (($c['auth_type'] === 'token') ? 1000000 : 0) - $cid;
        $zr = cfFetchAllZones($pdo, $c['email'], $c['api_key'], $proxies, null, $c['auth_type']);
        if (empty($zr['success'])) { $deadCreds[] = $c['email']; continue; }
        foreach ($zr['zones'] as $zone) {
            $zn  = is_object($zone) ? ($zone->name ?? '') : ($zone['name'] ?? '');
            $zid = is_object($zone) ? ($zone->id ?? '')   : ($zone['id'] ?? '');
            if ($zn === '') continue;
            $zoneOwners[mb_strtolower($zn)][$cid] = $zid;
        }
    }

    $domains = $pdo->query("SELECT id, domain, account_id, zone_id FROM cloudflare_accounts WHERE user_id = $userId")->fetchAll();
    $relinked = 0; $okCount = 0; $orphan = 0; $report = [];
    foreach ($domains as $d) {
        $k = mb_strtolower((string)$d['domain']);
        if (empty($zoneOwners[$k])) { $orphan++; continue; }
        $ownerMap = $zoneOwners[$k];
        $curAcc   = (int)$d['account_id'];
        if (isset($ownerMap[$curAcc])) {
            $zid = $ownerMap[$curAcc];
            if ($zid !== '' && (string)$d['zone_id'] !== (string)$zid) {
                dbRetryOnLock(function () use ($pdo, $zid, $d, $userId) {
                    $pdo->prepare("UPDATE cloudflare_accounts SET zone_id = ? WHERE id = ? AND user_id = ?")->execute([$zid, $d['id'], $userId]);
                });
            }
            $okCount++;
            continue;
        }
        $bestId = null; $bestScore = null;
        foreach ($ownerMap as $ownId => $ownZid) {
            if ($bestScore === null || $credPref[$ownId] > $bestScore) { $bestScore = $credPref[$ownId]; $bestId = $ownId; }
        }
        $bestZid = $ownerMap[$bestId];
        dbRetryOnLock(function () use ($pdo, $bestId, $bestZid, $d, $userId) {
            $pdo->prepare("UPDATE cloudflare_accounts SET account_id = ?, zone_id = COALESCE(NULLIF(?, ''), zone_id) WHERE id = ? AND user_id = ?")
                ->execute([$bestId, $bestZid, $d['id'], $userId]);
        });
        $relinked++;
        $report[] = ['domain' => $d['domain'],
                     'from' => $emailById[$curAcc] ?? ('#' . $curAcc),
                     'to'   => $emailById[$bestId] ?? ('#' . $bestId)];
    }

    return ['relinked' => $relinked, 'ok' => $okCount, 'orphan' => $orphan,
            'dead_creds' => count($deadCreds), 'report' => $report];
}

/** Уникальный email кредентала: добавляет « #N» при коллизии (исключая $excludeId). */
function cfUniqueEmail($pdo, $userId, $base, $excludeId = 0) {
    $base = $base !== '' ? $base : ('token-' . substr((string)mt_rand(10000000, 99999999), 0, 8));
    $email = $base; $i = 2;
    while (true) {
        $q = $pdo->prepare("SELECT 1 FROM cloudflare_credentials WHERE user_id = ? AND email = ? AND id <> ?");
        $q->execute([(int)$userId, $email, (int)$excludeId]);
        if (!$q->fetchColumn()) return $email;
        $email = $base . ' #' . $i; $i++;
    }
}

/**
 * Единая точка привязки токена к каноническому аккаунту панели («мост», шаг 6).
 * Резолвит аккаунт (uid+name), находит существующий кредентал по uid (затем по токену),
 * обновляет его токен/uid/имя-заглушку — либо создаёт новый. Дубли по аккаунту не плодятся.
 * Зоны НЕ импортирует (это решает вызывающий). Возвращает ['id','email','uid','created'].
 */
function cfUpsertAccountByToken($pdo, $userId, $token, $authType = 'token', $proxies = [], $label = '') {
    $userId = (int)$userId;
    $r = cfResolveAccount($pdo, '', $token, $proxies, null, $authType);
    $uid  = $r ? (string)($r['uid'] ?? '')  : '';
    $name = $r ? (string)($r['name'] ?? '') : '';

    // Существующий кредентал того же аккаунта: сперва по uid, затем по точному токену.
    $existing = null;
    if ($uid !== '') {
        $q = $pdo->prepare("SELECT id, email FROM cloudflare_credentials WHERE user_id = ? AND cf_account_uid = ? LIMIT 1");
        $q->execute([$userId, $uid]);
        $existing = $q->fetch() ?: null;
    }
    if (!$existing) {
        $q = $pdo->prepare("SELECT id, email FROM cloudflare_credentials WHERE user_id = ? AND api_key = ? LIMIT 1");
        $q->execute([$userId, $token]);
        $existing = $q->fetch() ?: null;
    }

    if ($existing) {
        dbRetryOnLock(function () use ($pdo, $existing, $token, $authType, $uid) {
            $pdo->prepare("UPDATE cloudflare_credentials SET api_key = ?, auth_type = ?,
                cf_account_uid = COALESCE(NULLIF(?, ''), cf_account_uid) WHERE id = ?")
                ->execute([$token, $authType, $uid, $existing['id']]);
        });
        // Заглушку имени лечим на реальное имя (уникально), реальное имя не трогаем.
        if ($name !== '' && $name !== $existing['email'] && mtIsPlaceholderName($existing['email'])) {
            $target = cfUniqueEmail($pdo, $userId, $name, (int)$existing['id']);
            try {
                dbRetryOnLock(function () use ($pdo, $target, $existing) {
                    $pdo->prepare("UPDATE cloudflare_credentials SET email = ? WHERE id = ?")->execute([$target, $existing['id']]);
                });
                $existing['email'] = $target;
            } catch (Exception $e) { /* коллизия — оставляем */ }
        }
        return ['id' => (int)$existing['id'], 'email' => $existing['email'], 'uid' => $uid, 'created' => false];
    }

    $email = cfUniqueEmail($pdo, $userId, $label ?: ($name ?: ('token-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $token), -8))), 0);
    dbRetryOnLock(function () use ($pdo, $userId, $email, $token, $authType, $uid) {
        $pdo->prepare("INSERT INTO cloudflare_credentials (user_id, email, api_key, auth_type, cf_account_uid) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $email, $token, $authType, $uid !== '' ? $uid : null]);
    });
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email, 'uid' => $uid, 'created' => true];
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
            // ВАЖНО: НЕ импортируем домены здесь — импорт зон (mtImportZones) под нагрузкой
            // долгий и роняет создание токена по таймауту. Только лёгкая привязка токена к
            // аккаунту (upsert по uid). Домены подтянутся кнопкой «Импортировать/обновить».
            $savedAs = null;
            if ($newToken) {
                try {
                    $up = cfUpsertAccountByToken($pdo, $userId, $newToken, 'token');
                    logAction($pdo, $userId, 'Аккаунт добавлен/обновлён в панели',
                        ($up['created'] ? 'авто после создания токена: ' : 'обновление аккаунта: ') . $up['email']);
                    $savedAs = $up['email'] . ' (домены — по кнопке «Импортировать/обновить»)';
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
            // Каноническая привязка токена к аккаунту (upsert по uid) — единая точка «моста».
            $up = cfUpsertAccountByToken($pdo, $userId, $tok, 'token');
            $credId = $up['id'];
            dbRetryOnLock(function () use ($pdo, $credId, $zoneId, $domainId, $userId) {
                $pdo->prepare("UPDATE cloudflare_accounts SET account_id = ?, zone_id = ? WHERE id = ? AND user_id = ?")->execute([$credId, $zoneId, $domainId, $userId]);
            });
            logAction($pdo, $userId, 'Перевыпущен токен домена', "{$domName}: новый токен, зона {$zoneId}, DNS доступ: да");
            echo json_encode(['success' => true, 'domain' => $domName, 'zone_ok' => true, 'dns_ok' => true, 'masked' => mb_substr($tok, 0, 10) . '…' . mb_substr($tok, -4)]);
            break;

        case 'import_empty':
            // Импорт/обновление зон + бэкфилл идентичности — БАТЧАМИ по несколько аккаунтов
            // за запрос (offset/batch), фронт крутит батчи с прогрессом. Так каждый запрос
            // короткий и не упирается в таймаут Cloudflare/PHP при большом числе аккаунтов.
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $batch  = (int)($_POST['batch'] ?? 3);
            if ($batch < 1)  $batch = 3;
            if ($batch > 15) $batch = 15;

            $grp = mtFirstGroupId($pdo, $userId);
            $proxies = function_exists('getProxies') ? getProxies($pdo, $userId) : [];
            $phaseErrors = [];

            // Все кредентала в стабильном порядке; обрабатываем только срез [offset .. offset+batch).
            $allCreds = $pdo->query("SELECT id, email, api_key, cf_account_uid, COALESCE(auth_type,'global') AS auth_type
                FROM cloudflare_credentials WHERE user_id = $userId ORDER BY id")->fetchAll();
            $total = count($allCreds);
            $slice = array_slice($allCreds, $offset, $batch);

            $report = []; $renamed = 0; $uidFilled = 0;
            foreach ($slice as $c) {
                // 1) Импорт зон — только для токен-аккаунтов (mtImportZones ждёт токен).
                if ($c['auth_type'] === 'token') {
                    try {
                        $imp = mtImportZones($pdo, $userId, $c['id'], $c['email'], $c['api_key'], $grp ?: null);
                        $report[] = ['account' => $c['email'], 'ok' => !empty($imp['ok']), 'count' => $imp['count'] ?? 0, 'error' => $imp['error'] ?? null];
                    } catch (Throwable $e) {
                        $report[] = ['account' => $c['email'], 'ok' => false, 'count' => 0, 'error' => $e->getMessage()];
                    }
                }
                // 2) Бэкфилл идентичности: cf_account_uid + реальное имя вместо заглушки.
                try {
                    $needUid  = empty($c['cf_account_uid']);
                    $needName = mtIsPlaceholderName($c['email']);
                    if ($needUid || $needName) {
                        $r = cfResolveAccount($pdo, $c['email'], $c['api_key'], $proxies, null, $c['auth_type']);
                        if ($r) {
                            if ($needUid && !empty($r['uid'])) {
                                dbRetryOnLock(function () use ($pdo, $r, $c, $userId) {
                                    $pdo->prepare("UPDATE cloudflare_credentials SET cf_account_uid = ?
                                        WHERE id = ? AND user_id = ? AND (cf_account_uid IS NULL OR cf_account_uid = '')")
                                        ->execute([$r['uid'], $c['id'], $userId]);
                                });
                                $uidFilled++;
                            }
                            if ($needName && !empty($r['name']) && $r['name'] !== $c['email']) {
                                $target = $r['name']; $k = 2;
                                while (true) {
                                    $chk = $pdo->prepare("SELECT 1 FROM cloudflare_credentials WHERE user_id = ? AND email = ? AND id <> ?");
                                    $chk->execute([$userId, $target, $c['id']]);
                                    $hit = $chk->fetchColumn();
                                    $chk->closeCursor();
                                    if (!$hit) break;
                                    $target = $r['name'] . ' #' . $k; $k++;
                                }
                                try {
                                    dbRetryOnLock(function () use ($pdo, $target, $c, $userId) {
                                        $pdo->prepare("UPDATE cloudflare_credentials SET email = ? WHERE id = ? AND user_id = ?")->execute([$target, $c['id'], $userId]);
                                    });
                                    $renamed++;
                                } catch (Exception $e) { /* коллизия — оставляем как есть */ }
                            }
                        }
                    }
                } catch (Throwable $e) { $phaseErrors[] = 'backfill ' . $c['email'] . ': ' . $e->getMessage(); }
            }

            $nextOffset = ($offset + $batch < $total) ? ($offset + $batch) : null;
            if ($nextOffset === null) {
                logActionSafe($pdo, $userId, 'Импорт доменов + бэкфилл идентичности (батчами)', 'аккаунтов всего: ' . $total);
            }
            echo json_encode(['success' => true, 'report' => $report, 'renamed' => $renamed, 'uid_filled' => $uidFilled,
                'total' => $total, 'offset' => $offset, 'processed' => count($slice),
                'next_offset' => $nextOffset, 'phase_errors' => $phaseErrors]);
            break;

        case 'save_as_account':
            // Сохранить токен как аккаунт панели. Дедуп: один CF-аккаунт = один кредентал
            // (ключ — cf_account_uid). Повторный токен того же аккаунта не плодит дубль,
            // а обновляет существующий и досинхронизирует домены.
            $tok = trim($_POST['token'] ?? '');
            $label = trim($_POST['label'] ?? '');
            if ($tok === '') throw new Exception('Нет токена для сохранения');

            // Каноническая привязка токена к аккаунту (upsert по uid) — единая точка «моста».
            $grp = mtFirstGroupId($pdo, $userId);
            $up  = cfUpsertAccountByToken($pdo, $userId, $tok, 'token', [], $label);
            $imp = mtImportZones($pdo, $userId, $up['id'], $up['email'], $tok, $grp ?: null);
            if (!$up['created']) {
                logAction($pdo, $userId, 'Аккаунт уже в панели — обновление токена и доменов',
                    "{$up['email']}: доменов: " . ($imp['count'] ?? 0));
                echo json_encode(['success' => true, 'already' => true,
                    'imported' => $imp['count'] ?? 0, 'import_error' => $imp['error'] ?? null]);
            } else {
                logAction($pdo, $userId, 'Аккаунт добавлен в панель', "через мастер-токен: {$up['email']}, доменов: " . ($imp['count'] ?? 0));
                echo json_encode(['success' => true, 'label' => $up['email'], 'imported' => $imp['count'] ?? 0]);
            }
            break;

        case 'dedup_preview':
            // Превью дедупа: тот же план, что применит dedup_accounts, но БЕЗ записи в БД.
            // Показываем, какой аккаунт останется главным, во что переименуется и кого удалим.
            $prev = mtDedupComputeMerges($pdo, $userId);
            $dom  = $prev['dom_count'];
            $groups = [];
            foreach ($prev['merges'] as $m) {
                $removed = [];
                foreach ($m['remove'] as $i => $rid) {
                    $removed[] = ['id' => $rid, 'email' => $m['remove_emails'][$i], 'domains' => $dom[$rid] ?? 0];
                }
                $groups[] = [
                    'keep_id'      => $m['keep'],
                    'keep_email'   => $m['keep_email'],
                    'new_name'     => $m['name'],
                    'renamed'      => ($m['name'] !== $m['keep_email']),
                    'keep_domains' => $dom[$m['keep']] ?? 0,
                    'removed'      => $removed,
                ];
            }
            echo json_encode([
                'success'       => true,
                'total'         => $prev['total'],
                'merged_groups' => count($groups),
                'groups'        => $groups,
            ]);
            break;

        case 'dedup_accounts':
            // Применение дедупа: один CF-аккаунт мог попасть в панель несколько раз (Account, #2, #3).
            // Дедуп БЕЗ сети: план слияния считает общий helper mtDedupComputeMerges
            // (группировка по нормализованному имени + разводка по cf_account_uid). ВСЁ —
            // чтения плана и записи — под ОДНИМ BEGIN IMMEDIATE (write-лок первым, без гонки
            // read→write), чтобы не ловить «database is locked» с фоновыми cf-queue/cf-monitor.
            $mergedGroups = 0; $deleted = 0; $report = []; $total = 0;
            dbRetryOnLock(function () use ($pdo, $userId,
                    &$mergedGroups, &$deleted, &$report, &$total) {
                $mergedGroups = 0; $deleted = 0; $report = [];
                // Ждём освобождения write-лока подольше (фоновый import/monitor может держать серию записей).
                $pdo->exec('PRAGMA busy_timeout = 120000');
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    // Свежий план под write-локом — теми же чтениями, что и превью.
                    $res    = mtDedupComputeMerges($pdo, $userId);
                    $merges = $res['merges'];
                    $total  = $res['total'];
                    $report = [];
                    foreach ($merges as $m) {
                        $report[] = ['keep' => $m['name'], 'removed' => $m['remove_emails']];
                    }
                    $mergedGroups = count($merges);

                    // Записи (в той же транзакции).
                    foreach ($merges as $m) {
                        foreach ($m['remove'] as $rid) {
                            $pdo->prepare("UPDATE cloudflare_accounts SET account_id = ? WHERE user_id = ? AND account_id = ?")
                                ->execute([$m['keep'], $userId, $rid]);
                            $pdo->prepare("UPDATE cloudflare_api_tokens SET account_id = ? WHERE user_id = ? AND account_id = ?")
                                ->execute([$m['keep'], $userId, $rid]);
                            $pdo->prepare("DELETE FROM cloudflare_credentials WHERE id = ? AND user_id = ?")
                                ->execute([$rid, $userId]);
                            $deleted++;
                        }
                        // Стандартное имя (без #N) — ПОСЛЕ удаления дублей, чтобы не нарушить UNIQUE.
                        $pdo->prepare("UPDATE cloudflare_credentials
                            SET api_key = COALESCE(NULLIF(?, ''), api_key),
                                auth_type = ?,
                                cf_account_uid = COALESCE(NULLIF(?, ''), cf_account_uid),
                                email = ?
                            WHERE id = ? AND user_id = ?")
                            ->execute([$m['api_key'], $m['auth_type'], $m['uid'], $m['name'], $m['keep'], $userId]);
                    }
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignore) {}
                    throw $e;
                }
            }, 30); // операция разовая, но может конкурировать с фоновой записью

            logActionSafe($pdo, $userId, 'Дедуп аккаунтов', "групп: $mergedGroups, удалено дублей: $deleted");
            echo json_encode(['success' => true, 'merged_groups' => $mergedGroups, 'deleted' => $deleted,
                'total' => $total, 'report' => $report]);
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

            // Кредентал в панели под рабочий токен — канонической привязкой (upsert по uid),
            // чтобы не плодить отдельный «master#N»-дубль того же CF-аккаунта.
            $up = cfUpsertAccountByToken($pdo, $userId, $work, 'token', [], $accountName ? ('master#' . $mid . ' ' . $accountName) : '');
            $credId = $up['id'];
            $grp = mtFirstGroupId($pdo, $userId);

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

        case 'relink_domains':
            // Массовая починка привязки доменов (общий helper mtRelinkDomains): для каждого
            // домена находим кредентал, чей токен РЕАЛЬНО владеет зоной в Cloudflare.
            $proxies = function_exists('getProxies') ? getProxies($pdo, $userId) : [];
            $res = mtRelinkDomains($pdo, $userId, $proxies);
            // Синхронизация нормализованной модели (шаг 8) — здесь, а не в авто-импорте.
            $sync = ['accounts' => 0, 'tokens' => 0];
            try { if (function_exists('cfSyncCanonicalTables')) $sync = cfSyncCanonicalTables($pdo, $userId); }
            catch (Throwable $e) { /* не критично для переклейки */ }
            logActionSafe($pdo, $userId, 'Проверка и переклейка доменов',
                "переклеено: {$res['relinked']}, уже верно: {$res['ok']}, без владельца: {$res['orphan']}, мёртвых токенов: {$res['dead_creds']}, cf_account: {$sync['accounts']}");
            echo json_encode(['success' => true, 'relinked' => $res['relinked'], 'ok' => $res['ok'],
                'orphan' => $res['orphan'], 'dead_creds' => $res['dead_creds'],
                'canonical_accounts' => $sync['accounts'], 'canonical_tokens' => $sync['tokens'],
                'report' => array_slice($res['report'], 0, 200)]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    // Фиксируем сбой в разделе «Логи» (logAction сам повторит запись при блокировке БД),
    // чтобы ошибки вроде «database is locked» были видны в панели, а не только в тосте.
    // Локация исключения — чтобы точно видеть, КАКОЙ запрос упал (а не только текст).
    $loc = basename($e->getFile()) . ':' . $e->getLine();
    if (isset($pdo)) {
        $act = $action !== '' ? $action : 'master_token_api';
        logActionSafe($pdo, $userId ?? 1, 'Ошибка перевыпуска/операции токена', "{$act} @ {$loc}: " . $e->getMessage());
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage() . ' @ ' . $loc]);
}
