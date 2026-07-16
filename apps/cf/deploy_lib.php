<?php
/**
 * Модуль «Деплой из ZIP» — вспомогательная логика.
 *
 * Фаза 1: приём и валидация ZIP со статическим сайтом (FR-1, FR-2).
 * Здесь только чтение/инвентаризация архива — без обращений к Cloudflare
 * и без записи. Деплой, привязка домена и правки — в следующих фазах.
 *
 * Никакой глобальной инициализации: файл подключается из deploy_api.php,
 * который уже загрузил config.php + functions.php.
 */

if (!defined('CF_DEPLOY_MAX_FILE_BYTES')) {
    // Лимит Cloudflare Static Assets: 25 MiB на ОТДЕЛЬНЫЙ файл (не на архив).
    define('CF_DEPLOY_MAX_FILE_BYTES', 25 * 1024 * 1024);
    // Лимит числа файлов на сайт: 20 000 (Free) / 100 000 (Paid). Блокируем по нижней границе.
    define('CF_DEPLOY_MAX_FILES_FREE', 20000);
    define('CF_DEPLOY_MAX_FILES_PAID', 100000);
}

/**
 * MIME-тип по расширению файла (для инвентаря и последующей загрузки ассетов).
 */
function cfDeployMimeType($path) {
    static $map = [
        'html' => 'text/html',            'htm'  => 'text/html',
        'css'  => 'text/css',             'js'   => 'text/javascript',
        'mjs'  => 'text/javascript',      'json' => 'application/json',
        'xml'  => 'application/xml',       'txt'  => 'text/plain',
        'svg'  => 'image/svg+xml',        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',           'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',            'webp' => 'image/webp',
        'avif' => 'image/avif',           'ico'  => 'image/x-icon',
        'bmp'  => 'image/bmp',            'woff' => 'font/woff',
        'woff2'=> 'font/woff2',           'ttf'  => 'font/ttf',
        'otf'  => 'font/otf',             'eot'  => 'application/vnd.ms-fontobject',
        'pdf'  => 'application/pdf',       'zip'  => 'application/zip',
        'mp4'  => 'video/mp4',            'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',           'ogg'  => 'audio/ogg',
        'wav'  => 'audio/wav',            'map'  => 'application/json',
        'wasm' => 'application/wasm',      'apk'  => 'application/vnd.android.package-archive',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Расширения, которые указывают на серверную логику (не исполняется на Static Assets).
 */
function cfDeployIsServerFile($path) {
    static $exts = ['php','php5','php7','phtml','asp','aspx','jsp','cgi','pl','py','rb','sh'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $exts, true);
}

/**
 * Служебные записи архива, которые не должны попадать в инвентарь/деплой.
 */
function cfDeployIsJunkEntry($name) {
    if ($name === '' || substr($name, -1) === '/') return true;               // директории
    if (strpos($name, '__MACOSX/') === 0) return true;                        // macOS resource forks
    $base = basename($name);
    if ($base === '.DS_Store' || $base === 'Thumbs.db') return true;
    if (strpos($name, '/.') !== false || strpos($name, '.') === 0) {
        // Скрытые файлы допускаем только для .htaccess / _headers / _redirects.
        $allowed = ['.htaccess', '_headers', '_redirects'];
        if (!in_array($base, $allowed, true)) return true;
    }
    return false;
}

/**
 * Валидация и инвентаризация ZIP-архива (FR-1, FR-2).
 *
 * Не распаковывает файлы на диск — читает оглавление через ZipArchive::statIndex,
 * поэтому размер самого архива значения не имеет (FR-1: лимит только на файл).
 *
 * @param string $zipPath путь к загруженному .zip
 * @return array сводка; при провале ['valid'=>false,'error'=>...]
 */
function cfDeployValidateArchive($zipPath) {
    $out = [
        'valid'        => false,
        'error'        => null,
        'root_prefix'  => '',      // подпапка, поднимаемая как корень (если сайт в одной верхней папке)
        'total_files'  => 0,
        'total_size'   => 0,
        'files'        => [],      // [{path, size, mime}] — пути уже относительно корня сайта
        'pages'        => [],      // html-страницы (относительно корня сайта)
        'oversized'    => [],      // [{path, size}] — файлы > 25 MiB (FR-1, всплывающее окно)
        'server_files' => [],      // серверные файлы (.php и т.п.) — предупреждение
        'has_index'    => false,
        'has_htaccess' => false,
        'has_404'      => false,
        'warnings'     => [],
        'limit_files'  => CF_DEPLOY_MAX_FILES_FREE,
    ];

    if (!is_file($zipPath)) {
        $out['error'] = 'Файл архива не найден.';
        return $out;
    }

    $zip = new ZipArchive();
    $rc = $zip->open($zipPath, ZipArchive::CHECKCONS);
    if ($rc !== true) {
        // CHECKCONS иногда ругается на легитимные архивы — пробуем без строгой проверки.
        $rc = $zip->open($zipPath);
    }
    if ($rc !== true) {
        $out['error'] = 'Не удалось открыть архив: файл повреждён или это не ZIP.';
        return $out;
    }

    // --- Первый проход: собрать все значимые записи ---
    $entries = [];              // name => size
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        $name = $stat['name'];
        if (cfDeployIsJunkEntry($name)) continue;
        // Защита от Zip Slip: отклоняем выход за пределы корня.
        if (strpos($name, '../') !== false || strpos($name, '..\\') !== false || $name[0] === '/') {
            $out['error'] = 'В архиве недопустимый путь (выход за пределы корня): ' . $name;
            $zip->close();
            return $out;
        }
        $entries[$name] = (int)$stat['size'];
    }
    $zip->close();

    if (empty($entries)) {
        $out['error'] = 'Архив пуст — нет файлов для публикации.';
        return $out;
    }

    // --- Определить корень сайта (FR-1: index.html в корне или в единственной верхней папке) ---
    $hasRootIndex = isset($entries['index.html']);
    $rootPrefix = '';
    if (!$hasRootIndex) {
        // Собираем верхние сегменты путей.
        $topDirs = [];
        $topFilesAtRoot = false;
        foreach ($entries as $name => $size) {
            $slash = strpos($name, '/');
            if ($slash === false) { $topFilesAtRoot = true; continue; }
            $topDirs[substr($name, 0, $slash)] = true;
        }
        if (!$topFilesAtRoot && count($topDirs) === 1) {
            $only = array_key_first($topDirs);
            if (isset($entries[$only . '/index.html'])) {
                $rootPrefix = $only . '/';
                $hasRootIndex = true;
            }
        }
    }

    // --- Второй проход: инвентарь относительно выбранного корня ---
    $prefLen = strlen($rootPrefix);
    foreach ($entries as $name => $size) {
        if ($rootPrefix !== '' && strncmp($name, $rootPrefix, $prefLen) !== 0) {
            // Файл вне поднятого корня (не должно случаться при count(topDirs)===1) — пропускаем.
            continue;
        }
        $rel = $rootPrefix !== '' ? substr($name, $prefLen) : $name;
        if ($rel === '') continue;

        $out['total_files']++;
        $out['total_size'] += $size;

        if ($size > CF_DEPLOY_MAX_FILE_BYTES) {
            $out['oversized'][] = ['path' => $rel, 'size' => $size];
        }
        if (cfDeployIsServerFile($rel)) {
            $out['server_files'][] = $rel;
        }

        $base = basename($rel);
        if ($base === '.htaccess') $out['has_htaccess'] = true;
        if ($base === '404.html')  $out['has_404'] = true;

        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'html' || $ext === 'htm') {
            $out['pages'][] = $rel;
        }

        $out['files'][] = ['path' => $rel, 'size' => $size, 'mime' => cfDeployMimeType($rel)];
    }

    $out['root_prefix'] = $rootPrefix;
    $out['has_index']   = $hasRootIndex;

    // --- Итоговые проверки/предупреждения ---
    if (!$hasRootIndex) {
        $out['error'] = 'Не найден корневой index.html. Он должен лежать в корне архива '
                      . 'или в единственной верхней папке.';
        return $out;
    }
    if (!empty($out['oversized'])) {
        // FR-1: блокируем — фронт покажет всплывающее окно со списком.
        $out['error'] = 'Есть файлы больше 25 MiB — их нельзя залить на Cloudflare Static Assets. '
                      . 'Удалите или замените их и повторите загрузку.';
        return $out;
    }
    if ($out['total_files'] > CF_DEPLOY_MAX_FILES_FREE) {
        $out['error'] = 'Файлов в сайте: ' . $out['total_files'] . '. Лимит — '
                      . CF_DEPLOY_MAX_FILES_FREE . ' (Free). Уменьшите число файлов.';
        return $out;
    }

    if (!empty($out['server_files'])) {
        $n = count($out['server_files']);
        $out['warnings'][] = 'Найдены серверные файлы (' . $n . ' шт., напр. ' .
            htmlspecialchars($out['server_files'][0]) . ') — серверная логика на Static Assets '
            . 'не исполняется, такие файлы будут отдаваться как есть.';
    }
    if (!$out['has_htaccess']) {
        $out['warnings'][] = '.htaccess не найден — применим дефолтные соглашения (чистые URL '
            . '+ разумные кэш-заголовки).';
    }
    if (!$out['has_404']) {
        $out['warnings'][] = '404.html не найден (необязательно) — на отсутствующих путях покажется '
            . 'стандартная страница 404. На саму публикацию это не влияет.';
    }

    $out['valid'] = true;
    return $out;
}
