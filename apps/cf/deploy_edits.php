<?php
/**
 * Модуль «Деплой из ZIP» — правки по домену (FR-10).
 *
 *  - FR-10.1: копия сайта в подпапку (/en/, /es-cl/) — самодостаточная (переписывание
 *    корне-абсолютных путей) либо с общими ассетами корня.
 *  - FR-10.2: управление мета-тегами (canonical / hreflang / x-default) с авто-реципрокным
 *    кластером; конфиг хранится в модуле (cf_deploy_meta) и переприменяется при каждом
 *    деплое/пересборке (в т.ч. после re-upload — FR-9).
 *
 * Чтобы править сайт БЕЗ повторной загрузки ZIP, модуль хранит исходник корня сайта в
 * постоянном хранилище (см. cfDeployStoreBase) и на каждую публикацию собирает финальный
 * набор ассетов = корень + подпапки-версии + мета.
 *
 * Подключается из deploy_api.php после deploy_worker.php.
 */

/**
 * База постоянного хранилища исходников. В контейнере — том /data/cf; иначе — локальный
 * fallback (в .gitignore).
 */
function cfDeployStoreBase() {
    $base = is_dir('/data/cf') ? '/data/cf/deploy_sites' : (__DIR__ . '/deploy_data/sites');
    if (!is_dir($base)) { @mkdir($base, 0700, true); }
    return $base;
}

/** Директория исходника корня конкретного сайта. */
function cfDeploySiteSrcDir($siteId) {
    return cfDeployStoreBase() . '/' . (int)$siteId . '/src';
}

/** Есть ли сохранённый исходник сайта. */
function cfDeployHasSource($siteId) {
    $d = cfDeploySiteSrcDir($siteId);
    return is_file($d . '/index.html');
}

/**
 * Сохраняет распакованный корень сайта в постоянное хранилище (замена предыдущего).
 * @param string $extractDir директория из cfDeployExtractZip
 * @param array  $files      относительные пути
 */
function cfDeploySaveRootSource($siteId, $extractDir, $files) {
    $dst = cfDeploySiteSrcDir($siteId);
    if (is_dir($dst)) { cfDeployRmrf($dst); }
    if (!mkdir($dst, 0700, true)) {
        throw new Exception('Не удалось создать хранилище исходника сайта.');
    }
    foreach ($files as $rel) {
        $from = $extractDir . '/' . $rel;
        if (!is_file($from)) continue;
        $to = $dst . '/' . $rel;
        $toDir = dirname($to);
        if (!is_dir($toDir)) { @mkdir($toDir, 0700, true); }
        copy($from, $to);
    }
}

/** Рекурсивный список файлов директории (относительные пути, forward slashes). */
function cfDeployListFiles($dir) {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $prefLen = strlen(rtrim($dir, '/')) + 1;
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = str_replace('\\', '/', substr($f->getPathname(), $prefLen));
        }
    }
    return $out;
}

/* =========================================================================
 *  FR-10.1. Переписывание корне-абсолютных путей для подпапки
 * ========================================================================= */

/**
 * Переписывает корне-абсолютные ссылки/пути на префикс подпапки, чтобы версия
 * работала самодостаточно: href/src/... "/x" -> "/<prefix>/x", srcset, url(/...).
 * Протокол-относительные (//host) и внешние (http://) не трогаем.
 */
function cfDeployRewriteRootAbsolute($content, $prefix) {
    $p = '/' . trim($prefix, '/');

    // Атрибуты со ссылками.
    $content = preg_replace_callback(
        '/\b(href|src|action|poster|data-src)(\s*=\s*)(["\'])(\/(?!\/)[^"\']*)\3/i',
        function ($m) use ($p) { return $m[1] . $m[2] . $m[3] . $p . $m[4] . $m[3]; },
        $content
    );

    // srcset (несколько URL через запятую).
    $content = preg_replace_callback(
        '/\bsrcset(\s*=\s*)(["\'])([^"\']*)\2/i',
        function ($m) use ($p) {
            $parts = array_map(function ($seg) use ($p) {
                $seg = trim($seg);
                return preg_replace('/^\/(?!\/)/', $p . '/', $seg);
            }, explode(',', $m[3]));
            return 'srcset' . $m[1] . $m[2] . implode(', ', $parts) . $m[2];
        },
        $content
    );

    // CSS url(/...) (в т.ч. инлайновые стили в HTML).
    $content = preg_replace_callback(
        '/url\(\s*(["\']?)\/(?!\/)([^)"\']*)\1\s*\)/i',
        function ($m) use ($p) { return 'url(' . $m[1] . $p . '/' . $m[2] . $m[1] . ')'; },
        $content
    );

    return $content;
}

/** Копирует корень в подпапку версии. share=true — только HTML (ассеты общие с корнем). */
function cfDeployBuildSubfolder($srcRoot, $assembleDir, $prefix, $share) {
    $verDir = $assembleDir . '/' . trim($prefix, '/');
    $files = cfDeployListFiles($srcRoot);
    foreach ($files as $rel) {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $isHtml = ($ext === 'html' || $ext === 'htm');
        // Служебные файлы корня (_headers/_redirects/.htaccess) в подпапку не тащим.
        $base = basename($rel);
        if (in_array($base, ['_headers', '_redirects', '.htaccess'], true)) continue;
        if ($share && !$isHtml) continue; // общий режим: ассеты берём из корня

        $to = $verDir . '/' . $rel;
        $toDir = dirname($to);
        if (!is_dir($toDir)) { @mkdir($toDir, 0700, true); }

        if ($isHtml || $ext === 'css') {
            $content = file_get_contents($srcRoot . '/' . $rel);
            if (!$share) {
                $content = cfDeployRewriteRootAbsolute($content, $prefix);
            }
            file_put_contents($to, $content);
        } else {
            copy($srcRoot . '/' . $rel, $to);
        }
    }
}

/* =========================================================================
 *  FR-10.2. Мета-теги: canonical / hreflang / x-default
 * ========================================================================= */

/** Конфиг меты сайта (дефолт — пустой). */
function cfDeployLoadMeta($pdo, $siteId) {
    $stmt = $pdo->prepare("SELECT config_json FROM cf_deploy_meta WHERE site_id = ?");
    $stmt->execute([$siteId]);
    $json = $stmt->fetchColumn();
    $cfg = $json ? json_decode($json, true) : null;
    if (!is_array($cfg)) $cfg = [];
    $cfg += ['enabled' => false, 'x_default' => '', 'locales' => []];
    if (!is_array($cfg['locales'])) $cfg['locales'] = [];
    return $cfg;
}

function cfDeploySaveMeta($pdo, $siteId, $config) {
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    dbRetryOnLock(function () use ($pdo, $siteId, $json) {
        $pdo->prepare("INSERT INTO cf_deploy_meta (site_id, config_json, updated_at)
            VALUES (?, ?, datetime('now'))
            ON CONFLICT(site_id) DO UPDATE SET config_json = excluded.config_json, updated_at = datetime('now')")
            ->execute([$siteId, $json]);
    });
}

/** URL страницы с учётом чистых URL (index.html -> /, page.html -> /page). */
function cfDeployPageUrl($domain, $prefix, $relPath) {
    $clean = preg_replace('/(^|\/)index\.html?$/i', '$1', $relPath);
    $clean = preg_replace('/\.html?$/i', '', $clean);
    $base = 'https://' . $domain . '/';
    if ($prefix !== '') $base .= trim($prefix, '/') . '/';
    return $base . $clean;
}

/** Реципрокные hreflang-ссылки кластера (одинаковы для всех версий). */
function cfDeployHreflangLinks($domain, $config) {
    if (empty($config['locales'])) return '';
    $links = '';
    foreach ($config['locales'] as $pfx => $loc) {
        if (!$loc) continue;
        $u = 'https://' . $domain . '/' . ($pfx !== '' ? trim($pfx, '/') . '/' : '');
        $links .= '<link rel="alternate" hreflang="' . htmlspecialchars($loc, ENT_QUOTES) . '" href="' . $u . '">' . "\n";
    }
    $xd = $config['x_default'] ?? '';
    $xu = 'https://' . $domain . '/' . ($xd !== '' ? trim($xd, '/') . '/' : '');
    $links .= '<link rel="alternate" hreflang="x-default" href="' . $xu . '">' . "\n";
    return $links;
}

/** Удаляет ранее вставленный managed-блок и разрозненные canonical/hreflang. */
function cfDeployStripManagedMeta($html) {
    $html = preg_replace('/<!-- monopanel:meta start -->.*?<!-- monopanel:meta end -->\s*/si', '', $html);
    $html = preg_replace('/<link[^>]+rel=["\']canonical["\'][^>]*>\s*/i', '', $html);
    $html = preg_replace('/<link[^>]+hreflang=["\'][^"\']*["\'][^>]*>\s*/i', '', $html);
    return $html;
}

/** Вставляет managed-блок меты перед </head>. */
function cfDeployInjectMeta($html, $selfUrl, $hreflangLinks) {
    $block = "<!-- monopanel:meta start -->\n";
    if ($selfUrl) $block .= '<link rel="canonical" href="' . $selfUrl . '">' . "\n";
    $block .= $hreflangLinks;
    $block .= "<!-- monopanel:meta end -->\n";

    $html = cfDeployStripManagedMeta($html);
    if (stripos($html, '</head>') !== false) {
        return preg_replace('/<\/head>/i', $block . '</head>', $html, 1);
    }
    return $block . $html;
}

/**
 * Применяет мету ко всем HTML одной версии.
 * @param array $skipTop верхние сегменты-подпапки, которые пропустить (для корня).
 */
function cfDeployApplyMetaToVersion($assembleDir, $prefix, $domain, $hreflangLinks, $selfCanonical, $skipTop = []) {
    $verDir = $prefix === '' ? $assembleDir : $assembleDir . '/' . trim($prefix, '/');
    foreach (cfDeployListFiles($verDir) as $rel) {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext !== 'html' && $ext !== 'htm') continue;
        // Для корня не заходим в директории других версий.
        if ($prefix === '' && $skipTop) {
            $top = explode('/', $rel)[0];
            if (in_array($top, $skipTop, true)) continue;
        }
        if (!$selfCanonical && $hreflangLinks === '') continue;
        $abs = $verDir . '/' . $rel;
        $html = file_get_contents($abs);
        $selfUrl = $selfCanonical ? cfDeployPageUrl($domain, $prefix, $rel) : null;
        file_put_contents($abs, cfDeployInjectMeta($html, $selfUrl, $hreflangLinks));
    }
}

/* =========================================================================
 *  FR-10.3. Пер-страничные SEO-переопределения (title/description/H1/canonical/robots)
 *
 *  Читаем текущие значения через DOMDocument (надёжно на разной вёрстке), а ПРИМЕНЯЕМ
 *  точечной заменой конкретных тегов — остальной HTML остаётся байт-в-байт (чтобы не
 *  «перекроить» тяжёлые лендинги с инлайновым JS). Применяется при сборке из исходника.
 * ========================================================================= */

/** Список HTML-страниц исходника сайта (относительные пути). */
function cfDeployListSitePages($siteId) {
    $srcDir = cfDeploySiteSrcDir($siteId);
    $pages = [];
    foreach (cfDeployListFiles($srcDir) as $rel) {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'html' || $ext === 'htm') $pages[] = $rel;
    }
    sort($pages);
    return $pages;
}

/** Читает текущие title/description/H1/canonical/robots из HTML (только чтение). */
function cfDeployReadPageMetaFromHtml($html) {
    $out = ['title' => '', 'description' => '', 'h1' => '', 'canonical' => '', 'robots' => ''];
    if (trim((string)$html) === '') return $out;

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    // Подсказка кодировки — иначе кириллица в title/H1 «поедет».
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $titles = $doc->getElementsByTagName('title');
    if ($titles->length) $out['title'] = trim($titles->item(0)->textContent);
    $h1s = $doc->getElementsByTagName('h1');
    if ($h1s->length) $out['h1'] = trim($h1s->item(0)->textContent);

    foreach ($doc->getElementsByTagName('meta') as $m) {
        $name = strtolower($m->getAttribute('name'));
        if ($name === 'description' && $out['description'] === '') $out['description'] = trim($m->getAttribute('content'));
        if ($name === 'robots' && $out['robots'] === '') $out['robots'] = trim($m->getAttribute('content'));
    }
    foreach ($doc->getElementsByTagName('link') as $l) {
        if (strtolower($l->getAttribute('rel')) === 'canonical') { $out['canonical'] = trim($l->getAttribute('href')); break; }
    }
    return $out;
}

/** Сохранённые переопределения сайта: [path => ['title'=>..,'description'=>..,...]]. */
function cfDeployLoadPageMeta($pdo, $siteId) {
    $stmt = $pdo->prepare("SELECT path, title, description, h1, canonical, robots, hreflang
        FROM cf_deploy_page_meta WHERE site_id = ?");
    $stmt->execute([$siteId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) { $out[$r['path']] = $r; }
    return $out;
}

/** Сохраняет/удаляет переопределения одной страницы (пустые поля не переопределяют). */
function cfDeploySavePageMeta($pdo, $siteId, $path, $fields) {
    $norm = function ($v) { $v = trim((string)$v); return $v === '' ? null : $v; };
    $vals = [];
    $has = false;
    foreach (['title', 'description', 'h1', 'canonical', 'robots', 'hreflang'] as $k) {
        $vals[$k] = $norm($fields[$k] ?? '');
        if ($vals[$k] !== null) $has = true;
    }
    dbRetryOnLock(function () use ($pdo, $siteId, $path, $vals, $has) {
        if (!$has) {
            $pdo->prepare("DELETE FROM cf_deploy_page_meta WHERE site_id=? AND path=?")->execute([$siteId, $path]);
            return;
        }
        $pdo->prepare("INSERT INTO cf_deploy_page_meta (site_id, path, title, description, h1, canonical, robots, hreflang, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ON CONFLICT(site_id, path) DO UPDATE SET title=excluded.title, description=excluded.description,
                h1=excluded.h1, canonical=excluded.canonical, robots=excluded.robots, hreflang=excluded.hreflang,
                updated_at=datetime('now')")
            ->execute([$siteId, $path, $vals['title'], $vals['description'], $vals['h1'], $vals['canonical'],
                $vals['robots'], $vals['hreflang']]);
    });
}

/** Вставляет тег перед </head> (или в начало, если head нет). */
function cfDeployInsertIntoHead($html, $tag) {
    if (stripos($html, '</head>') !== false) {
        return preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);
    }
    return $tag . "\n" . $html;
}

/** Заменяет/вставляет <meta name="X" content="…">. */
function cfDeployUpsertMetaByName($html, $name, $content) {
    $tag = '<meta name="' . $name . '" content="' . htmlspecialchars($content, ENT_QUOTES) . '">';
    $re  = '/<meta\b[^>]*\bname=["\']' . preg_quote($name, '/') . '["\'][^>]*>/i';
    if (preg_match($re, $html)) return preg_replace($re, $tag, $html, 1);
    return cfDeployInsertIntoHead($html, $tag);
}

/** Применяет переопределения к одному HTML (точечная замена тегов). */
function cfDeployApplyMetaToHtml($html, $ov) {
    if (!empty($ov['title'])) {
        $t = '<title>' . htmlspecialchars($ov['title'], ENT_QUOTES) . '</title>';
        if (preg_match('/<title\b[^>]*>.*?<\/title>/is', $html)) {
            $html = preg_replace('/<title\b[^>]*>.*?<\/title>/is', $t, $html, 1);
        } else {
            $html = cfDeployInsertIntoHead($html, $t);
        }
    }
    if (!empty($ov['description'])) $html = cfDeployUpsertMetaByName($html, 'description', $ov['description']);
    if (!empty($ov['robots']))      $html = cfDeployUpsertMetaByName($html, 'robots', $ov['robots']);

    if (!empty($ov['canonical'])) {
        $link = '<link rel="canonical" href="' . htmlspecialchars($ov['canonical'], ENT_QUOTES) . '">';
        if (preg_match('/<link\b[^>]*rel=["\']canonical["\'][^>]*>/i', $html)) {
            $html = preg_replace('/<link\b[^>]*rel=["\']canonical["\'][^>]*>/i', $link, $html, 1);
        } else {
            $html = cfDeployInsertIntoHead($html, $link);
        }
    }
    // H1: заменяем внутреннее содержимое первого <h1>, атрибуты тега сохраняем.
    if (!empty($ov['h1'])) {
        $h1 = htmlspecialchars($ov['h1'], ENT_QUOTES);
        $html = preg_replace_callback('/(<h1\b[^>]*>)(.*?)(<\/h1>)/is',
            function ($m) use ($h1) { return $m[1] . $h1 . $m[3]; }, $html, 1);
    }

    // hreflang: пользователь вставляет ГОТОВЫЙ код (настройка hreflang слишком вариативна,
    // чтобы генерировать её из шаблона). Вставляем как есть в управляемый блок перед </head>,
    // предыдущий такой блок убираем — переприменение идемпотентно.
    $html = preg_replace('/<!-- monopanel:hreflang start -->.*?<!-- monopanel:hreflang end -->\s*/si', '', $html);
    if (!empty($ov['hreflang'])) {
        $block = "<!-- monopanel:hreflang start -->\n" . trim($ov['hreflang']) . "\n<!-- monopanel:hreflang end -->\n";
        $html = cfDeployInsertIntoHead($html, $block);
    }
    return $html;
}

/** Применяет пер-страничные переопределения к собранному набору. Возвращает число правок. */
function cfDeployApplyPageMetaOverrides($assembleDir, $overridesByPath) {
    if (empty($overridesByPath)) return 0;
    $applied = 0;
    foreach ($overridesByPath as $path => $ov) {
        $file = $assembleDir . '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, '..') !== false || !is_file($file)) continue;
        $html = file_get_contents($file);
        $new = cfDeployApplyMetaToHtml($html, $ov);
        if ($new !== $html) { file_put_contents($file, $new); $applied++; }
    }
    return $applied;
}

/* =========================================================================
 *  Сборка финального набора и публикация (используется деплоем и правками)
 * ========================================================================= */

/**
 * Собирает набор ассетов сайта (корень + подпапки-версии + мета) из постоянного
 * исходника и публикует его на Static Assets. Единая точка для деплоя, добавления
 * подпапок и правок меты — гарантирует переприменение меты (FR-10.2).
 *
 * @return array результат cfDeployPublishDir + ['assembled_files'=>int]
 */
function cfDeployAssembleAndPublish($pdo, $credentials, $accountCfId, $scriptName, $siteId, $domain, $mode, $proxies, $userId) {
    $srcRoot = cfDeploySiteSrcDir($siteId);
    if (!is_file($srcRoot . '/index.html')) {
        return ['success' => false, 'steps' => [['step' => 'Сборка', 'ok' => false, 'info' => 'Нет сохранённого исходника сайта — загрузите ZIP.']],
                'error' => 'Нет сохранённого исходника сайта.'];
    }

    $assemble = sys_get_temp_dir() . '/cfassemble_' . bin2hex(random_bytes(6));
    if (!mkdir($assemble, 0700, true)) {
        return ['success' => false, 'steps' => [], 'error' => 'Не удалось создать директорию сборки.'];
    }

    try {
        // 1) Корень.
        foreach (cfDeployListFiles($srcRoot) as $rel) {
            $to = $assemble . '/' . $rel;
            $toDir = dirname($to);
            if (!is_dir($toDir)) { @mkdir($toDir, 0700, true); }
            copy($srcRoot . '/' . $rel, $to);
        }

        // 2) Подпапки-версии (источник — корень).
        $stmt = $pdo->prepare("SELECT prefix, source_prefix, share_root_assets FROM cf_deploy_versions
            WHERE site_id = ? AND prefix != '' ORDER BY prefix");
        $stmt->execute([$siteId]);
        $versions = $stmt->fetchAll();
        $subPrefixes = [];
        foreach ($versions as $v) {
            $pfx = trim($v['prefix'], '/');
            if ($pfx === '') continue;
            $subPrefixes[] = $pfx;
            cfDeployBuildSubfolder($srcRoot, $assemble, $pfx, (int)$v['share_root_assets'] === 1);
        }

        // 3) Мета (canonical/hreflang/x-default), реципрокно по всем версиям.
        $meta = cfDeployLoadMeta($pdo, $siteId);
        $hreflang = cfDeployHreflangLinks($domain, $meta);
        $multiVersion = count($subPrefixes) > 0;
        $selfCanonical = $meta['enabled'] || $multiVersion; // при >1 версии — self-canonical против дублей
        if ($selfCanonical || $hreflang !== '') {
            cfDeployApplyMetaToVersion($assemble, '', $domain, $hreflang, $selfCanonical, $subPrefixes);
            foreach ($subPrefixes as $pfx) {
                cfDeployApplyMetaToVersion($assemble, $pfx, $domain, $hreflang, $selfCanonical);
            }
        }

        // 3b) Пер-страничные SEO-переопределения (title/description/H1/canonical/robots).
        //     После managed-меты — чтобы пер-страничный canonical перебивал self-canonical.
        $pageMeta = cfDeployLoadPageMeta($pdo, $siteId);
        if (!empty($pageMeta)) {
            cfDeployApplyPageMetaOverrides($assemble, $pageMeta);
        }

        // 4) Чистые URL/кэш (FR-3) на собранном наборе + публикация.
        $files = cfDeployListFiles($assemble);
        $config = cfDeployPrepareAssets($assemble, $files);
        $files = cfDeployListFiles($assemble); // после prepare (добавились _headers/_redirects)

        $pub = cfDeployPublishDir($pdo, $credentials, $accountCfId, $scriptName, $assemble, $files, $config, $mode, $proxies, $userId);
        $pub['assembled_files'] = count($files);
        return $pub;
    } finally {
        cfDeployRmrf($assemble);
    }
}

/** Находит сайт пользователя по аккаунту и домену. */
function cfDeployFindSite($pdo, $userId, $accountId, $domain) {
    $stmt = $pdo->prepare("SELECT * FROM cf_deploy_sites WHERE user_id = ? AND account_id = ? AND domain = ?");
    $stmt->execute([$userId, $accountId, $domain]);
    return $stmt->fetch();
}

/** Нормализация префикса подпапки: латиница/цифры/дефис, нижний регистр. */
function cfDeployNormalizePrefix($prefix) {
    $p = strtolower(trim($prefix));
    $p = trim($p, '/');
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $p)) {
        throw new Exception('Некорректный префикс подпапки (допустимо: en, es-cl, ru-1).');
    }
    return $p;
}
