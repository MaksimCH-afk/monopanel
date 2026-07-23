<?php
/**
 * Модуль «Деплой из ZIP» — оркестратор Cloudflare Workers Static Assets.
 *
 * Фаза 2:
 *  - FR-5: чтение состояния домена в аккаунте (зона/DNS/привязка воркера/SSL).
 *  - FR-3: чистые URL и кэш (._htaccess -> _headers/_redirects, иначе дефолты).
 *  - FR-6: деплой ассетов (upload-session -> buckets -> completion -> PUT воркера).
 *          Публикация на служебный *.workers.dev.
 *
 * Привязка Custom Domain и SSL (FR-7) — только по подтверждению, реализуется в фазе 3.
 *
 * Подключается из deploy_api.php (config.php + functions.php + deploy_lib.php уже загружены).
 */

if (!defined('CF_DEPLOY_COMPAT_DATE')) {
    // compatibility_date воркера. Стабильная прошлая дата (CF принимает <= последней поддерживаемой).
    define('CF_DEPLOY_COMPAT_DATE', '2024-11-01');
}
if (!defined('CF_DEPLOY_WORKER_DNS_MARKER')) {
    // Маркер в cloudflare_accounts.dns_ip, когда домен обслуживается воркером на edge CF
    // (апексной A-записи origin больше нет). Чтобы дашборд/домены не показывали старый IP.
    define('CF_DEPLOY_WORKER_DNS_MARKER', 'CF Worker (edge)');
}

/**
 * Человекочитаемое сообщение об ошибке из ответа cloudflareApiRequestDetailed.
 * Важно: при HTTP не-200 общая функция выходит ДО парсинга тела, поэтому
 * api_errors там пустой — достаём текст из raw_response. Фолбэк — HTTP-код.
 */
function cfDeployApiError($resp, $prefix = '') {
    $msg = null;
    if (!empty($resp['api_errors'][0]['message'])) {
        $code = $resp['api_errors'][0]['code'] ?? null;
        $msg  = $resp['api_errors'][0]['message'] . ($code ? " (код $code)" : '');
    } elseif (!empty($resp['raw_response'])) {
        $j = json_decode((string)$resp['raw_response'], true);
        if (!empty($j['errors'][0]['message'])) {
            $code = $j['errors'][0]['code'] ?? null;
            $msg  = $j['errors'][0]['message'] . ($code ? " (код $code)" : '');
        }
    }
    if (!$msg) {
        $msg = 'HTTP ' . ($resp['http_code'] ?? 0);
        if (!empty($resp['curl_error'])) $msg .= ' — ' . $resp['curl_error'];
    }
    return $prefix ? ($prefix . $msg) : $msg;
}

/**
 * Определяет CF account_id и статус зоны для домена.
 * Если зона есть в аккаунте — берём account_id из неё; иначе (деплой только на
 * workers.dev) — из первого доступного аккаунта токена.
 *
 * @return array ['account_cf_id'=>?,'zone_id'=>?,'zone_in_account'=>bool,'error'=>?]
 */
function cfDeployResolveAccount($pdo, $credentials, $domain, $proxies, $userId) {
    $out = ['account_cf_id' => null, 'zone_id' => null, 'zone_in_account' => false, 'error' => null];

    $zone = ensureCloudflareZone($pdo, $credentials, $domain, $proxies, $userId, false);
    if ($zone['success'] && !empty($zone['zone_id'])) {
        $out['zone_id'] = $zone['zone_id'];
        $out['zone_in_account'] = true;
        $out['account_cf_id'] = cfGetAccountId($pdo, $credentials, $zone['zone_id'], $proxies, $userId);
        if ($out['account_cf_id']) return $out;
    }

    // Зоны нет (или account_id не достали) — берём первый аккаунт токена.
    $acc = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        'accounts?per_page=1', 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($acc['success']) && !empty($acc['data'])) {
        $rec = is_array($acc['data']) ? reset($acc['data']) : $acc['data'];
        $out['account_cf_id'] = is_object($rec) ? ($rec->id ?? null) : ($rec['id'] ?? null);
    }
    if (!$out['account_cf_id']) {
        $out['error'] = 'Не удалось определить аккаунт Cloudflare для токена '
                      . '(проверьте права токена: Account → Workers Scripts).';
    }
    return $out;
}

/* =========================================================================
 *  FR-5. Состояние домена в аккаунте
 * ========================================================================= */

/**
 * Проверяет текущее состояние домена в выбранном аккаунте (только чтение).
 *
 * @return array сводка для UI/подтверждения
 */
function cfDeployCheckDomain($pdo, $credentials, $accountCfId, $domain, $scriptName, $proxies, $userId) {
    $out = [
        'zone_in_account' => false,
        'zone_id'         => null,
        'account_cf_id'   => $accountCfId,
        'dns_present'     => false,
        'worker_binding'  => null,   // имя воркера, к которому уже привязан домен (или null)
        'bound_to_self'   => false,
        'ssl_active'      => false,
        'summary'         => '',
        'can_bind'        => false,
        'messages'        => [],
    ];

    // 1) Зона в этом аккаунте? (токен account-scoped → поиск по имени = проверка владения)
    $zone = ensureCloudflareZone($pdo, $credentials, $domain, $proxies, $userId, false);
    if (!$zone['success'] || empty($zone['zone_id'])) {
        $out['summary'] = 'Зоны этого домена нет в выбранном аккаунте — привязка домена невозможна '
                        . '(§8: домен и воркер должны быть в одном аккаунте). Сайт можно опубликовать '
                        . 'на служебном *.workers.dev.';
        $out['messages'][] = 'zone_not_in_account';
        return $out;
    }
    $out['zone_in_account'] = true;
    $out['zone_id'] = $zone['zone_id'];
    $zoneId = $zone['zone_id'];

    // 2) Есть ли DNS-запись корня?
    $dns = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "zones/$zoneId/dns_records?name=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($dns['success']) && !empty($dns['data'])) {
        $out['dns_present'] = true;
    }

    // 3) Привязан ли домен как Custom Domain к какому-либо воркеру?
    $wd = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/domains?hostname=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($wd['success']) && !empty($wd['data'])) {
        $rec = is_array($wd['data']) ? reset($wd['data']) : $wd['data'];
        $service = is_object($rec) ? ($rec->service ?? null) : ($rec['service'] ?? null);
        if ($service) {
            $out['worker_binding'] = $service;
            $out['bound_to_self'] = ($service === $scriptName);
        }
    }

    // 4) SSL-режим зоны (для сводки; Custom Domain выпускает сертификат сам при привязке).
    $ssl = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "zones/$zoneId/settings/ssl", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($ssl['success']) && isset($ssl['data'])) {
        $mode = is_object($ssl['data']) ? ($ssl['data']->value ?? '') : ($ssl['data']['value'] ?? '');
        $out['ssl_active'] = ($mode && $mode !== 'off');
    }

    // 5) Человекочитаемая сводка + разрешение на привязку.
    if ($out['worker_binding'] === null) {
        $out['summary'] = 'Домен свободен в этом аккаунте — можно привязать к сайту.';
        $out['can_bind'] = true;
    } elseif ($out['bound_to_self']) {
        $out['summary'] = 'Домен уже привязан к этому сайту (воркер ' . $scriptName . ').';
        $out['can_bind'] = true;
    } else {
        $out['summary'] = 'Домен привязан к другому воркеру «' . $out['worker_binding'] . '» — '
                        . 'при подтверждении будет перепривязан на новый сайт.';
        $out['can_bind'] = true;
    }
    return $out;
}

/* =========================================================================
 *  FR-7. Привязка Custom Domain и SSL (только по подтверждению)
 * ========================================================================= */

/**
 * Привязывает домен к воркеру как Custom Domain (идемпотентный upsert — покрывает
 * и перепривязку с другого воркера). Cloudflare сам создаёт DNS-запись и SSL.
 * PUT /accounts/{id}/workers/domains {hostname, service, zone_id, environment}.
 *
 * @return array ['success'=>bool,'domain_id'=>?,'error'=>?]
 */
function cfDeployBindDomain($pdo, $credentials, $accountCfId, $zoneId, $scriptName, $domain, $proxies, $userId, $allowDnsReplace = false) {
    if (!$zoneId) {
        return ['success' => false, 'error' => 'Нет зоны в аккаунте — привязка невозможна (§8).'];
    }

    $notes = [];
    $extractId = function ($resp) {
        $d = $resp['data'] ?? null;
        return is_object($d) ? ($d->id ?? null) : (is_array($d) ? ($d['id'] ?? null) : null);
    };
    $put = function () use ($pdo, $credentials, $accountCfId, $zoneId, $scriptName, $domain, $proxies, $userId) {
        return cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
            "accounts/$accountCfId/workers/domains", 'PUT', [
                'hostname'    => $domain,
                'service'     => $scriptName,
                'zone_id'     => $zoneId,
                'environment' => 'production',
            ], $proxies, $userId, $credentials['auth_type'] ?? null);
    };

    $resp = $put();
    if (!empty($resp['success'])) {
        return ['success' => true, 'domain_id' => $extractId($resp), 'notes' => $notes, 'dns_backup' => []];
    }

    // Не 409 — сразу читаемая ошибка.
    if ((int)($resp['http_code'] ?? 0) !== 409) {
        return ['success' => false, 'error' => cfDeployApiError($resp, 'workers/domains PUT: '),
                'notes' => $notes, 'dns_backup' => []];
    }

    // ── HTTP 409 Conflict: PUT workers/domains НЕ перезаписывает молча. ──
    // Сначала ТОЛЬКО ЧИТАЕМ причины (ничего не меняем), чтобы при необходимости
    // спросить второе подтверждение до любого разрушительного действия.

    // (а) Хост уже привязан как Custom Domain к другому воркеру? (а если к этому же —
    //     привязка уже есть, считаем успехом: важно для идемпотентного re-deploy.)
    $otherWorker = null; // ['id','service','environment']
    $wd = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/domains?hostname=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($wd['success']) && !empty($wd['data'])) {
        $rec = is_array($wd['data']) ? reset($wd['data']) : $wd['data'];
        $svc = is_object($rec) ? ($rec->service ?? null)     : ($rec['service'] ?? null);
        $rid = is_object($rec) ? ($rec->id ?? null)          : ($rec['id'] ?? null);
        $env = is_object($rec) ? ($rec->environment ?? null) : ($rec['environment'] ?? null);
        $rhn = is_object($rec) ? ($rec->hostname ?? '')      : ($rec['hostname'] ?? '');
        if ($rid && $svc && strcasecmp($rhn, $domain) === 0) {
            if ($svc === $scriptName) {
                return ['success' => true, 'domain_id' => $rid, 'notes' => $notes, 'dns_backup' => []];
            }
            $otherWorker = ['id' => $rid, 'service' => $svc, 'environment' => $env ?: 'production'];
        }
    }

    // (б) На апексе есть конфликтующая DNS-запись (A/AAAA/CNAME от «прежнего сервера»)?
    //     ТОЛЬКО точное совпадение имени + маршрутные типы. TXT/MX/CAA/NS/SRV и любые
    //     другие имена НЕ трогаются (напр. TXT-подтверждение Google Search Console).
    $conflicts = []; // [['id','type','name','content','ttl','proxied'], ...]
    $dns = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "zones/$zoneId/dns_records?name=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($dns['success']) && !empty($dns['data'])) {
        foreach ((array)$dns['data'] as $r) {
            $type = is_object($r) ? ($r->type ?? '') : ($r['type'] ?? '');
            $name = is_object($r) ? ($r->name ?? '') : ($r['name'] ?? '');
            $rid  = is_object($r) ? ($r->id ?? '')   : ($r['id'] ?? '');
            if (!$rid || strcasecmp($name, $domain) !== 0) continue;
            if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) continue;
            $conflicts[] = [
                'id'      => $rid,
                'type'    => $type,
                'name'    => $name,
                'content' => is_object($r) ? ($r->content ?? '') : ($r['content'] ?? ''),
                'ttl'     => is_object($r) ? ($r->ttl ?? 1)      : ($r['ttl'] ?? 1),
                'proxied' => is_object($r) ? (bool)($r->proxied ?? false) : (bool)($r['proxied'] ?? false),
            ];
        }
    }

    // Есть что удалять на апексе, но пользователь ещё не подтвердил замену DNS —
    // возвращаем запрос второго подтверждения. Ничего не меняли — состояние чистое.
    if (!empty($conflicts) && !$allowDnsReplace) {
        return [
            'success'          => false,
            'needs_dns_confirm'=> true,
            'other_worker'     => $otherWorker['service'] ?? null,
            'conflict_records' => array_map(function ($c) {
                return ['type' => $c['type'], 'name' => $c['name'],
                        'content' => $c['content'], 'proxied' => $c['proxied']];
            }, $conflicts),
            'notes'            => $notes,
            'dns_backup'       => [],
        ];
    }

    // ── Разрешено действовать: устраняем конфликты, повторяем PUT. Всё, что меняем,
    //    кладём в стек отката ($undo) — если привязка не удастся, вернём как было. ──
    $undo = [];
    $dnsBackup = [];

    if ($otherWorker) {
        cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
            "accounts/$accountCfId/workers/domains/{$otherWorker['id']}", 'DELETE', [], $proxies, $userId, $credentials['auth_type'] ?? null);
        $undo[] = function () use ($pdo, $credentials, $accountCfId, $zoneId, $domain, $otherWorker, $proxies, $userId) {
            cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
                "accounts/$accountCfId/workers/domains", 'PUT', [
                    'hostname' => $domain, 'service' => $otherWorker['service'],
                    'zone_id' => $zoneId, 'environment' => $otherWorker['environment'],
                ], $proxies, $userId, $credentials['auth_type'] ?? null);
        };
        $notes[] = "отвязан от воркера «{$otherWorker['service']}»";
    }

    foreach ($conflicts as $c) {
        $del = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
            "zones/$zoneId/dns_records/{$c['id']}", 'DELETE', [], $proxies, $userId, $credentials['auth_type'] ?? null);
        if (($del['http_code'] ?? 0) === 200 || !empty($del['success'])) {
            unset($c['id']);
            $dnsBackup[] = $c;
        }
    }
    if (!empty($dnsBackup)) {
        $undo[] = function () use ($pdo, $credentials, $zoneId, $dnsBackup, $proxies, $userId) {
            cfDeployRecreateDnsRecords($pdo, $credentials, $zoneId, $dnsBackup, $proxies, $userId);
        };
        $notes[] = 'заменена DNS-запись на апексе (' . count($dnsBackup) . ')';
    }

    $resp = $put();
    if (!empty($resp['success'])) {
        return ['success' => true, 'domain_id' => $extractId($resp), 'notes' => $notes, 'dns_backup' => $dnsBackup];
    }

    // Привязка так и не удалась — откатываем всё внесённое (в обратном порядке).
    if (!empty($undo)) {
        for ($i = count($undo) - 1; $i >= 0; $i--) { $undo[$i](); }
        $notes[] = 'изменения отменены — домен возвращён в прежнее состояние';
    }
    return ['success' => false, 'error' => cfDeployApiError($resp, 'workers/domains PUT: '),
            'notes' => $notes, 'dns_backup' => []];
}

/**
 * Удаляет воркер-скрипт (сайт) с Cloudflare: DELETE /accounts/{id}/workers/scripts/{name}.
 * 404 (уже нет) считаем успехом. Custom Domain/маршруты CF снимает сам при удалении скрипта.
 *
 * @return array ['success'=>bool,'error'=>?]
 */
function cfDeployDeleteWorker($pdo, $credentials, $accountCfId, $scriptName, $proxies, $userId) {
    $resp = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/scripts/$scriptName", 'DELETE', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    $code = (int)($resp['http_code'] ?? 0);
    if ($code === 200 || $code === 404 || !empty($resp['success'])) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => cfDeployApiError($resp, 'workers/scripts DELETE: ')];
}

/**
 * Пересоздаёт DNS-записи из бэкапа (для отката привязки). Прокси-записи создаются с ttl=1
 * (Cloudflare требует auto-ttl для proxied). Возвращает число восстановленных записей.
 */
function cfDeployRecreateDnsRecords($pdo, $credentials, $zoneId, $records, $proxies, $userId) {
    $ok = 0;
    foreach ((array)$records as $r) {
        $proxied = !empty($r['proxied']);
        $body = [
            'type'    => $r['type'] ?? 'A',
            'name'    => $r['name'] ?? '',
            'content' => $r['content'] ?? '',
            'ttl'     => $proxied ? 1 : (int)($r['ttl'] ?? 1),
            'proxied' => $proxied,
        ];
        if ($body['name'] === '' || $body['content'] === '') continue;
        $resp = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
            "zones/$zoneId/dns_records", 'POST', $body, $proxies, $userId, $credentials['auth_type'] ?? null);
        if (!empty($resp['success'])) $ok++;
    }
    return $ok;
}

/**
 * «Мост»: приводит общее состояние домена в cloudflare_accounts (dns_ip/proxied/zone_id)
 * к реальности после привязки/отвязки Custom Domain — чтобы дашборд и раздел доменов
 * показывали то же, что и деплой (единая сущность домена).
 *
 * - $boundToWorker=true: домен ушёл на воркер CF (апексного origin A больше нет) →
 *   dns_ip=маркер, proxied=1. Иначе дашборд остался бы со старым IP «прежнего сервера».
 * - $boundToWorker=false (после отвязки): перечитываем апекс из CF и пишем фактический
 *   IP/proxied; если апексных A/AAAA/CNAME нет — состояние не трогаем (не затираем).
 *
 * Обновляет ВСЕ строки домена этого пользователя (домен = одна сущность, на каком бы
 * кредетале он ни висел). Пишет мягко, под dbRetryOnLock. Ошибки не роняют деплой.
 */
function cfDeployBridgeSyncDomain($pdo, $userId, $domain, $credentials, $zoneId, $proxies, $boundToWorker) {
    try {
        $dnsIp = null; $proxied = null;

        if ($boundToWorker) {
            $dnsIp = CF_DEPLOY_WORKER_DNS_MARKER;
            $proxied = 1;
        } elseif ($zoneId) {
            // Перечитываем апексные маршрутные записи (после отвязки/восстановления).
            $resp = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
                "zones/$zoneId/dns_records?name=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
            if (!empty($resp['success']) && !empty($resp['data'])) {
                $ips = [];
                foreach ((array)$resp['data'] as $r) {
                    $type = is_object($r) ? ($r->type ?? '') : ($r['type'] ?? '');
                    $name = is_object($r) ? ($r->name ?? '') : ($r['name'] ?? '');
                    if (strcasecmp($name, $domain) !== 0) continue;
                    if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) continue;
                    $ips[] = is_object($r) ? ($r->content ?? '') : ($r['content'] ?? '');
                    if ($proxied === null) {
                        $p = is_object($r) ? ($r->proxied ?? false) : ($r['proxied'] ?? false);
                        $proxied = $p ? 1 : 0;
                    }
                }
                $ips = array_values(array_filter(array_unique($ips)));
                if ($ips) $dnsIp = implode(', ', $ips);
            }
        }

        // После отвязки апекс пуст (нет origin-записи) — не показываем ложный маркер «на
        // воркере»: сбрасываем dns_ip там, где стоит именно наш маркер. Прочее не трогаем.
        if ($dnsIp === null && $proxied === null) {
            if (!$boundToWorker) {
                dbImmediateTxn($pdo, function () use ($pdo, $userId, $domain) {
                    $pdo->prepare("UPDATE cloudflare_accounts
                        SET dns_ip = NULL, proxied = 0, last_check = datetime('now'), updated_at = datetime('now')
                        WHERE user_id = ? AND domain = ? AND dns_ip = ?")
                        ->execute([$userId, $domain, CF_DEPLOY_WORKER_DNS_MARKER]);
                });
            }
            return;
        }

        dbImmediateTxn($pdo, function () use ($pdo, $userId, $domain, $zoneId, $dnsIp, $proxied) {
            $pdo->prepare("UPDATE cloudflare_accounts
                SET dns_ip = COALESCE(?, dns_ip),
                    proxied = COALESCE(?, proxied),
                    zone_id = COALESCE(NULLIF(?, ''), zone_id),
                    last_check = datetime('now'),
                    updated_at = datetime('now')
                WHERE user_id = ? AND domain = ?")
                ->execute([$dnsIp, $proxied, $zoneId, $userId, $domain]);
        });
    } catch (Exception $e) {
        // «Мост» — вспомогательная синхронизация: не должна ронять привязку/отвязку.
        if ($userId) logActionSafe($pdo, $userId, 'Deploy Bridge Sync Failed', "domain=$domain err=" . $e->getMessage());
    }
}

/**
 * Отвязывает домен от воркера: находит запись Custom Domain по hostname и удаляет её.
 * DELETE /accounts/{id}/workers/domains/{domain_id}.
 *
 * @return array ['success'=>bool,'error'=>?]
 */
function cfDeployUnbindDomain($pdo, $credentials, $accountCfId, $domain, $proxies, $userId) {
    $wd = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/domains?hostname=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (empty($wd['success']) || empty($wd['data'])) {
        return ['success' => true, 'error' => null]; // уже не привязан — считаем успехом
    }
    $rec = is_array($wd['data']) ? reset($wd['data']) : $wd['data'];
    $id = is_object($rec) ? ($rec->id ?? null) : ($rec['id'] ?? null);
    if (!$id) return ['success' => true, 'error' => null];

    $del = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/domains/$id", 'DELETE', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    // DELETE у CF отвечает 200 с пустым result — cloudflareApiRequestDetailed вернёт success=false
    // только при не-200. Считаем успехом при http 200.
    if (($del['http_code'] ?? 0) === 200 || !empty($del['success'])) {
        return ['success' => true, 'error' => null];
    }
    return ['success' => false, 'error' => cfDeployApiError($del, 'workers/domains DELETE: ')];
}

/**
 * Состояние привязки и SSL для домена (для UI после привязки / кнопки «Проверить SSL»).
 * SSL для Custom Domain выпускается автоматически; читаем статус edge-сертификата зоны.
 *
 * @return array ['bound'=>bool,'bound_to'=>?,'ssl_status'=>string]
 */
function cfDeployBindingStatus($pdo, $credentials, $accountCfId, $zoneId, $scriptName, $domain, $proxies, $userId) {
    $out = ['bound' => false, 'bound_to' => null, 'ssl_status' => 'неизвестно'];

    $wd = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/domains?hostname=$domain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($wd['success']) && !empty($wd['data'])) {
        $rec = is_array($wd['data']) ? reset($wd['data']) : $wd['data'];
        $svc = is_object($rec) ? ($rec->service ?? null) : ($rec['service'] ?? null);
        if ($svc) { $out['bound'] = true; $out['bound_to'] = $svc; }
    }

    // Статус edge-сертификата зоны (best-effort): ssl/verification возвращает
    // certificate_status по хостам зоны ('active' = сертификат выпущен).
    if ($zoneId) {
        $ver = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
            "zones/$zoneId/ssl/verification", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
        if (!empty($ver['success']) && !empty($ver['data'])) {
            $status = null;
            foreach ((array)$ver['data'] as $item) {
                $host = is_object($item) ? ($item->hostname ?? '') : ($item['hostname'] ?? '');
                $cs   = is_object($item) ? ($item->certificate_status ?? '') : ($item['certificate_status'] ?? '');
                if ($host === $domain) { $status = $cs; break; }
                if ($status === null && $cs) { $status = $cs; } // фоллбэк на первый
            }
            if ($status) {
                $out['ssl_status'] = ($status === 'active') ? 'активен' : ('выпускается (' . $status . ')');
            } else {
                $out['ssl_status'] = $out['bound'] ? 'выпускается' : 'нет';
            }
        } elseif ($out['bound']) {
            $out['ssl_status'] = 'выпускается';
        }
    }
    return $out;
}

/* =========================================================================
 *  Извлечение архива и подготовка набора ассетов (FR-3)
 * ========================================================================= */

/**
 * Распаковывает валидный ZIP во временную директорию, поднимая root_prefix как корень.
 * Возвращает ['dir'=>путь, 'files'=>[отн.пути]] или ['error'=>...].
 */
function cfDeployExtractZip($zipPath, $rootPrefix) {
    $dir = sys_get_temp_dir() . '/cfdeploy_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true)) {
        return ['error' => 'Не удалось создать временную директорию для распаковки.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        cfDeployRmrf($dir);
        return ['error' => 'Не удалось открыть архив при распаковке.'];
    }

    $prefLen = strlen($rootPrefix);
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        $name = $stat['name'];
        if (cfDeployIsJunkEntry($name)) continue;
        if ($rootPrefix !== '' && strncmp($name, $rootPrefix, $prefLen) !== 0) continue;
        $rel = $rootPrefix !== '' ? substr($name, $prefLen) : $name;
        if ($rel === '') continue;
        // Повторная защита от Zip Slip.
        if (strpos($rel, '..') !== false || $rel[0] === '/') continue;

        $dest = $dir . '/' . $rel;
        $destDir = dirname($dest);
        if (!is_dir($destDir) && !mkdir($destDir, 0700, true)) {
            $zip->close(); cfDeployRmrf($dir);
            return ['error' => 'Не удалось создать директорию: ' . $rel];
        }
        $stream = $zip->getStream($name);
        if (!$stream) continue;
        $fp = fopen($dest, 'wb');
        if (!$fp) { fclose($stream); continue; }
        stream_copy_to_stream($stream, $fp);
        fclose($fp);
        fclose($stream);
        $files[] = $rel;
    }
    $zip->close();

    if (empty($files)) {
        cfDeployRmrf($dir);
        return ['error' => 'После распаковки не осталось файлов.'];
    }
    return ['dir' => $dir, 'files' => $files];
}

/** Рекурсивное удаление временной директории. */
function cfDeployRmrf($path) {
    if (!is_dir($path)) { @unlink($path); return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
    }
    @rmdir($path);
}

/**
 * FR-3: готовит чистые URL и кэш. Возвращает конфиг Static Assets
 * ['html_handling'=>..., 'not_found_handling'=>...] и при необходимости
 * дописывает _headers/_redirects в набор ассетов.
 *
 * Логика:
 *  - _headers/_redirects из архива уважаем (не перезаписываем).
 *  - есть .htaccess → лёгкий разбор Cache-Control/Redirect в _headers/_redirects.
 *  - нет ничего → дефолтный _headers с кэшем для статики.
 */
function cfDeployPrepareAssets($dir, &$files) {
    $config = [
        'html_handling'      => 'auto-trailing-slash',   // чистые URL: /page.html -> /page, папки с / (FR-3)
        'not_found_handling' => is_file($dir . '/404.html') ? '404-page' : 'none',
    ];

    $hasHeaders   = is_file($dir . '/_headers');
    $hasRedirects = is_file($dir . '/_redirects');
    $htaccess     = is_file($dir . '/.htaccess') ? file_get_contents($dir . '/.htaccess') : null;

    $headersOut = '';
    $redirectsOut = '';

    if ($htaccess !== null) {
        list($headersOut, $redirectsOut) = cfDeployParseHtaccess($htaccess);
    }

    // Дефолтный кэш для статики, если пользователь не задал _headers и .htaccess не дал правил.
    if (!$hasHeaders && $headersOut === '') {
        $headersOut = "# сгенерировано monopanel (дефолтные кэш-заголовки)\n"
            . "/*.css\n  Cache-Control: public, max-age=31536000, immutable\n"
            . "/*.js\n  Cache-Control: public, max-age=31536000, immutable\n"
            . "/*.woff2\n  Cache-Control: public, max-age=31536000, immutable\n"
            . "/*.png\n  Cache-Control: public, max-age=604800\n"
            . "/*.jpg\n  Cache-Control: public, max-age=604800\n"
            . "/*.svg\n  Cache-Control: public, max-age=604800\n";
    }

    if (!$hasHeaders && $headersOut !== '') {
        file_put_contents($dir . '/_headers', $headersOut);
        $files[] = '_headers';
    }
    if (!$hasRedirects && $redirectsOut !== '') {
        file_put_contents($dir . '/_redirects', $redirectsOut);
        $files[] = '_redirects';
    }

    // .htaccess не нужен в раздаче статики — исключаем из набора ассетов.
    $files = array_values(array_filter($files, function ($f) { return $f !== '.htaccess'; }));

    return $config;
}

/**
 * Лёгкий разбор .htaccess → [_headers-текст, _redirects-текст] (best-effort, FR-3).
 * Поддержаны: ExpiresByType / Header set Cache-Control → _headers;
 * Redirect[Match] 301 и простые RewriteRule [R=301] → _redirects.
 */
function cfDeployParseHtaccess($content) {
    $headers = '';
    $redirects = '';
    $globalCache = [];

    foreach (preg_split('/\r?\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // ExpiresByType "text/css" "access plus 1 year"
        if (preg_match('/ExpiresByType\s+"?([^"\s]+)"?\s+"?access\s+plus\s+(.+?)"?$/i', $line, $m)) {
            $sec = cfDeployHumanTimeToSeconds($m[2]);
            if ($sec > 0) $globalCache[strtolower($m[1])] = $sec;
            continue;
        }
        // Header set Cache-Control "..."
        if (preg_match('/Header\s+(set|append)\s+Cache-Control\s+"([^"]+)"/i', $line, $m)) {
            $headers .= "/*\n  Cache-Control: {$m[2]}\n";
            continue;
        }
        // Redirect 301 /old /new   |   Redirect permanent /old /new
        if (preg_match('/^Redirect(?:Match)?\s+(?:301|permanent)\s+(\S+)\s+(\S+)/i', $line, $m)) {
            $redirects .= "{$m[1]} {$m[2]} 301\n";
            continue;
        }
        // RewriteRule ^old$ /new [R=301,L]
        if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s+\[[^\]]*R=301[^\]]*\]/i', $line, $m)) {
            $from = '/' . ltrim(preg_replace('/[\^\$]/', '', $m[1]), '/');
            $redirects .= "{$from} {$m[2]} 301\n";
            continue;
        }
    }

    // Кэш по MIME → правила _headers на типовые расширения.
    static $mimeExt = [
        'text/css' => 'css', 'text/javascript' => 'js', 'application/javascript' => 'js',
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg',
        'image/webp' => 'webp', 'font/woff2' => 'woff2', 'image/gif' => 'gif',
    ];
    foreach ($globalCache as $mime => $sec) {
        if (isset($mimeExt[$mime])) {
            $headers .= "/*.{$mimeExt[$mime]}\n  Cache-Control: public, max-age={$sec}\n";
        }
    }
    return [$headers, $redirects];
}

/** «1 year» / «7 days» / «1 month» → секунды. */
function cfDeployHumanTimeToSeconds($str) {
    if (!preg_match('/(\d+)\s*(year|month|week|day|hour|minute|second)/i', $str, $m)) return 0;
    $n = (int)$m[1];
    $unit = strtolower($m[2]);
    $map = ['year'=>31536000,'month'=>2592000,'week'=>604800,'day'=>86400,'hour'=>3600,'minute'=>60,'second'=>1];
    return $n * ($map[$unit] ?? 0);
}

/* =========================================================================
 *  FR-6. Хэш-манифест и загрузка ассетов
 * ========================================================================= */

/**
 * Строит манифест для assets-upload-session.
 * hash = sha256( base64(содержимое) + расширение_без_точки )[:32]  (алгоритм Cloudflare/Wrangler).
 *
 * @return array ['manifest'=>[ '/path'=>['hash'=>..,'size'=>..] ], 'byHash'=>[ hash=>['abs'=>..,'mime'=>..] ]]
 */
function cfDeployBuildManifest($dir, $files) {
    $manifest = [];
    $byHash = [];
    foreach ($files as $rel) {
        $abs = $dir . '/' . $rel;
        if (!is_file($abs)) continue;
        $content = file_get_contents($abs);
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION)); // без точки
        $hash = substr(hash('sha256', base64_encode($content) . $ext), 0, 32);
        $path = '/' . str_replace('\\', '/', $rel);
        $manifest[$path] = ['hash' => $hash, 'size' => strlen($content)];
        $byHash[$hash] = ['abs' => $abs, 'mime' => cfDeployMimeType($rel)];
    }
    return ['manifest' => $manifest, 'byHash' => $byHash];
}

/**
 * POST /accounts/{id}/workers/scripts/{name}/assets-upload-session
 * @return array ['success'=>bool,'jwt'=>?,'buckets'=>array,'error'=>?]
 */
function cfDeployCreateUploadSession($pdo, $credentials, $accountCfId, $scriptName, $manifest, $proxies, $userId) {
    $resp = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/scripts/$scriptName/assets-upload-session",
        'POST', ['manifest' => $manifest], $proxies, $userId, $credentials['auth_type'] ?? null);

    if (empty($resp['success']) || !isset($resp['data'])) {
        $msg = $resp['api_errors'][0]['message'] ?? ('HTTP ' . ($resp['http_code'] ?? 0));
        return ['success' => false, 'error' => 'assets-upload-session: ' . $msg];
    }
    $d = $resp['data'];
    $jwt = is_object($d) ? ($d->jwt ?? null) : ($d['jwt'] ?? null);
    $buckets = is_object($d) ? ($d->buckets ?? []) : ($d['buckets'] ?? []);
    // Приводим buckets к обычному массиву массивов.
    $buckets = json_decode(json_encode($buckets), true) ?: [];
    return ['success' => true, 'jwt' => $jwt, 'buckets' => $buckets];
}

/**
 * Загружает недостающие файлы бакетами и возвращает completion-JWT.
 * POST /accounts/{id}/workers/assets/upload?base64=true  (Bearer uploadJwt, multipart).
 * Поле формы = hash, значение = base64(содержимое), Content-Type части = MIME файла.
 *
 * @return array ['success'=>bool,'completion_jwt'=>?,'error'=>?,'uploaded'=>int]
 */
function cfDeployUploadBuckets($accountCfId, $uploadJwt, $buckets, $byHash, $proxies) {
    $completion = $uploadJwt; // если бакетов нет — сессионный JWT уже финальный
    $uploaded = 0;

    foreach ($buckets as $bucket) {
        if (empty($bucket)) continue;
        $boundary = '----cfDeployAssets' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($bucket as $hash) {
            if (!isset($byHash[$hash])) {
                return ['success' => false, 'error' => "Файл для хэша $hash не найден при загрузке."];
            }
            $content = file_get_contents($byHash[$hash]['abs']);
            $b64 = base64_encode($content);
            $mime = $byHash[$hash]['mime'];
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$hash\"; filename=\"$hash\"\r\n";
            $body .= "Content-Type: $mime\r\n\r\n";
            $body .= $b64 . "\r\n";
            $uploaded++;
        }
        $body .= "--$boundary--\r\n";

        $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/$accountCfId/workers/assets/upload?base64=true");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $uploadJwt",
                "Content-Type: multipart/form-data; boundary=$boundary",
            ],
        ]);
        if (!empty($proxies)) {
            $proxy = getRandomProxy($proxies);
            if ($proxy && preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):(\d+)@([^:@]+):(.+)$/', $proxy, $m)) {
                curl_setopt($ch, CURLOPT_PROXY, "{$m[1]}:{$m[2]}");
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$m[3]}:{$m[4]}");
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) {
            $j = json_decode((string)$resp, true);
            $msg = $j['errors'][0]['message'] ?? ($cerr ?: "HTTP $code");
            return ['success' => false, 'error' => 'assets/upload: ' . $msg];
        }
        $j = json_decode($resp, true);
        // Финальный бакет возвращает result.jwt = completion-токен.
        if (!empty($j['result']['jwt'])) {
            $completion = $j['result']['jwt'];
        }
    }

    return ['success' => true, 'completion_jwt' => $completion, 'uploaded' => $uploaded];
}

/**
 * PUT /accounts/{id}/workers/scripts/{name} — создать/обновить воркер с ассетами.
 * static-only: без main_module (assets-only). worker-first: + скрипт-модуль.
 *
 * @return array ['success'=>bool,'error'=>?]
 */
function cfDeployPutWorker($credentials, $accountCfId, $scriptName, $completionJwt, $assetConfig, $mode, $proxies) {
    $metadata = [
        'compatibility_date' => CF_DEPLOY_COMPAT_DATE,
        'assets' => [
            'jwt' => $completionJwt,
            'config' => [
                'html_handling'      => $assetConfig['html_handling'],
                'not_found_handling' => $assetConfig['not_found_handling'],
            ],
        ],
    ];

    $boundary = '----cfDeployWorker' . bin2hex(random_bytes(8));
    $body = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"metadata\"; filename=\"metadata.json\"\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";

    if ($mode === 'worker-first') {
        // worker-first: минимальный воркер, который передаёт запрос в ассеты (env.ASSETS.fetch).
        // Каждый заход исполняет воркера (расход дневного лимита) — предупреждение в UI (FR-8).
        $metadata['main_module'] = 'worker.js';
        $metadata['assets']['config']['serve_directly'] = false;
        $metadata['bindings'] = [['type' => 'assets', 'name' => 'ASSETS']];
        $body .= json_encode($metadata) . "\r\n";
        $script = "export default {\n  async fetch(request, env) {\n    return env.ASSETS.fetch(request);\n  }\n};\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"worker.js\"; filename=\"worker.js\"\r\n";
        $body .= "Content-Type: application/javascript+module\r\n\r\n";
        $body .= $script . "\r\n";
    } else {
        // static-only: assets-only воркер, без кода. Раздача ассетов не считается вызовом воркера.
        $body .= json_encode($metadata) . "\r\n";
    }
    $body .= "--$boundary--\r\n";

    list($authHeaders) = cfBuildAuthHeaders($credentials['email'], $credentials['api_key'], $credentials['auth_type'] ?? null);
    $headers = array_values(array_filter($authHeaders, function ($h) { return stripos($h, 'Content-Type:') !== 0; }));
    $headers[] = "Content-Type: multipart/form-data; boundary=$boundary";

    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/$accountCfId/workers/scripts/$scriptName");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if (!empty($proxies)) {
        $proxy = getRandomProxy($proxies);
        if ($proxy && preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):(\d+)@([^:@]+):(.+)$/', $proxy, $m)) {
            curl_setopt($ch, CURLOPT_PROXY, "{$m[1]}:{$m[2]}");
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$m[3]}:{$m[4]}");
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        $j = json_decode((string)$resp, true);
        $msg = $j['errors'][0]['message'] ?? ($cerr ?: "HTTP $code");
        return ['success' => false, 'error' => 'workers/scripts PUT: ' . $msg];
    }
    return ['success' => true];
}

/**
 * Включает служебный поддомен воркера (*.workers.dev) и возвращает итоговый URL.
 * @return array ['success'=>bool,'url'=>?,'error'=>?]
 */
function cfDeployEnableWorkersDev($pdo, $credentials, $accountCfId, $scriptName, $proxies, $userId) {
    // Включаем workers.dev для скрипта.
    cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/scripts/$scriptName/subdomain",
        'POST', ['enabled' => true], $proxies, $userId, $credentials['auth_type'] ?? null);

    // Поддомен аккаунта (account-wide, задаётся один раз).
    $sub = cfDeployGetAccountSubdomain($pdo, $credentials, $accountCfId, $proxies, $userId);

    // Не задан — регистрируем автоматически (иначе служебного URL просто не существует).
    if (!$sub) {
        $sub = cfDeployRegisterAccountSubdomain($pdo, $credentials, $accountCfId, $proxies, $userId);
    }

    if (!$sub) {
        return ['success' => false, 'error' =>
            'У аккаунта не задан *.workers.dev-поддомен, и зарегистрировать его автоматически не удалось. '
            . 'Откройте Cloudflare → Workers & Pages → задайте поддомен аккаунта, затем повторите публикацию.'];
    }
    return ['success' => true, 'url' => "https://$scriptName.$sub.workers.dev"];
}

/** Читает текущий *.workers.dev-поддомен аккаунта (или null, если не задан). */
function cfDeployGetAccountSubdomain($pdo, $credentials, $accountCfId, $proxies, $userId) {
    $sd = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
        "accounts/$accountCfId/workers/subdomain", 'GET', [], $proxies, $userId, $credentials['auth_type'] ?? null);
    if (!empty($sd['success']) && isset($sd['data'])) {
        $sub = is_object($sd['data']) ? ($sd['data']->subdomain ?? null) : ($sd['data']['subdomain'] ?? null);
        return $sub ?: null;
    }
    return null;
}

/**
 * Регистрирует *.workers.dev-поддомен аккаунта (разовое действие; имя глобально уникально).
 * Имя производно от account_id; при конфликте имени пробуем со случайным суффиксом.
 * @return string|null занятый поддомен или null, если не удалось.
 */
function cfDeployRegisterAccountSubdomain($pdo, $credentials, $accountCfId, $proxies, $userId) {
    // База: буквенно-цифровой префикс из account_id. Должна начинаться с буквы.
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', substr((string)$accountCfId, 0, 12)));
    if ($base === '' || ctype_digit($base[0])) $base = 'w' . $base;
    $base = substr($base, 0, 20);

    for ($i = 0; $i < 4; $i++) {
        $candidate = ($i === 0) ? $base : substr($base, 0, 16) . bin2hex(random_bytes(2));
        $put = cloudflareApiRequestDetailed($pdo, $credentials['email'], $credentials['api_key'],
            "accounts/$accountCfId/workers/subdomain", 'PUT', ['subdomain' => $candidate],
            $proxies, $userId, $credentials['auth_type'] ?? null);
        if (!empty($put['success'])) {
            if ($userId) logAction($pdo, $userId, 'Deploy workers.dev subdomain set',
                "account=$accountCfId subdomain=$candidate");
            return $candidate;
        }
        // Не конфликт имени (нет прав, невалидно и т.п.) — дальше перебирать бессмысленно.
        if ((int)($put['http_code'] ?? 0) !== 409) break;
    }
    // Вдруг поддомен уже был занят нами между попытками — перечитываем.
    return cfDeployGetAccountSubdomain($pdo, $credentials, $accountCfId, $proxies, $userId);
}

/* =========================================================================
 *  Высокоуровневый деплой (FR-6) — от распакованного архива до *.workers.dev
 * ========================================================================= */

/**
 * Полный цикл публикации сайта на Static Assets. Возвращает пошаговый лог и итог.
 * Домен/SSL не трогает (FR-7 — фаза 3).
 *
 * @return array ['success'=>bool,'steps'=>[...],'workers_dev_url'=>?,'error'=>?,'config'=>...]
 */
function cfDeployRun($pdo, $credentials, $accountCfId, $scriptName, $zipPath, $report, $mode, $proxies, $userId) {
    $steps = [];
    // 1) Распаковка
    $ext = cfDeployExtractZip($zipPath, $report['root_prefix']);
    if (isset($ext['error'])) {
        return ['success' => false, 'steps' => [['step' => 'Распаковка', 'ok' => false, 'info' => $ext['error']]],
                'error' => $ext['error']];
    }
    $dir = $ext['dir'];
    $files = $ext['files'];
    $steps[] = ['step' => 'Распаковка', 'ok' => true, 'info' => count($files) . ' файлов'];

    try {
        // 2) Чистые URL и кэш (FR-3)
        $config = cfDeployPrepareAssets($dir, $files);
        $steps[] = ['step' => 'Чистые URL и кэш', 'ok' => true,
            'info' => 'html_handling=' . $config['html_handling'] . ', not_found=' . $config['not_found_handling']];

        // 3-7) Публикация
        $pub = cfDeployPublishDir($pdo, $credentials, $accountCfId, $scriptName, $dir, $files, $config, $mode, $proxies, $userId);
        $pub['steps'] = array_merge($steps, $pub['steps']);
        return $pub;
    } finally {
        cfDeployRmrf($dir);
    }
}

/**
 * Публикует ГОТОВЫЙ набор ассетов (директория + список файлов + конфиг) на Static Assets:
 * манифест → upload-сессия → бакеты → PUT воркера → *.workers.dev.
 * Используется и прямым деплоем (cfDeployRun), и сборкой версий/меты (фаза 4).
 *
 * @return array ['success'=>bool,'steps'=>[...],'workers_dev_url'=>?,'config'=>...,'files_count'=>int,'error'=>?]
 */
function cfDeployPublishDir($pdo, $credentials, $accountCfId, $scriptName, $dir, $files, $config, $mode, $proxies, $userId) {
    $steps = [];
    $addStep = function ($name, $ok, $info = '') use (&$steps) {
        $steps[] = ['step' => $name, 'ok' => $ok, 'info' => $info];
    };

    // Манифест
    $mf = cfDeployBuildManifest($dir, $files);
    $addStep('Хэш-манифест', true, count($mf['manifest']) . ' файлов');

    // Upload-сессия
    $sess = cfDeployCreateUploadSession($pdo, $credentials, $accountCfId, $scriptName, $mf['manifest'], $proxies, $userId);
    if (!$sess['success']) {
        $addStep('Upload-сессия', false, $sess['error']);
        return ['success' => false, 'steps' => $steps, 'error' => $sess['error']];
    }
    $bucketCount = array_sum(array_map('count', $sess['buckets']));
    $addStep('Upload-сессия', true, $bucketCount . ' файлов к загрузке');

    // Загрузка бакетов
    $up = cfDeployUploadBuckets($accountCfId, $sess['jwt'], $sess['buckets'], $mf['byHash'], $proxies);
    if (!$up['success']) {
        $addStep('Загрузка файлов', false, $up['error']);
        return ['success' => false, 'steps' => $steps, 'error' => $up['error']];
    }
    $addStep('Загрузка файлов', true, $up['uploaded'] . ' загружено (остальные — дедуп по хэшу)');

    // Создание/обновление воркера
    $put = cfDeployPutWorker($credentials, $accountCfId, $scriptName, $up['completion_jwt'], $config, $mode, $proxies);
    if (!$put['success']) {
        $addStep('Деплой воркера', false, $put['error']);
        return ['success' => false, 'steps' => $steps, 'error' => $put['error']];
    }
    $addStep('Деплой воркера', true, 'имя ' . $scriptName . ' (' . $mode . ')');

    // Публикация на *.workers.dev
    $dev = cfDeployEnableWorkersDev($pdo, $credentials, $accountCfId, $scriptName, $proxies, $userId);
    if (!$dev['success']) {
        $addStep('Публикация на workers.dev', false, $dev['error']);
        return ['success' => true, 'steps' => $steps, 'workers_dev_url' => null,
                'config' => $config, 'files_count' => count($files), 'error' => $dev['error']];
    }
    $addStep('Публикация на workers.dev', true, $dev['url']);

    return ['success' => true, 'steps' => $steps, 'workers_dev_url' => $dev['url'],
            'config' => $config, 'files_count' => count($files)];
}
