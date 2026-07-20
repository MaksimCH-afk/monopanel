<?php
$pageTitle = 'Деплой';
require_once 'header.php';

$userId = $_SESSION['user_id'];

// Аккаунты Cloudflare = cloudflare_credentials (email + токен). Ручной выбор (FR-4).
$stmt = $pdo->prepare("SELECT id, email, status, COALESCE(auth_type,'global') AS auth_type, api_key, cf_account_uid FROM cloudflare_credentials WHERE user_id = ? ORDER BY email");
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll();

// Наличие scoped-токена «Мастер-токен» по аккаунту (нужен для Workers Scripts:Edit).
$tokenCounts = [];
$ts = $pdo->prepare("SELECT account_id, COUNT(*) AS c FROM cloudflare_api_tokens WHERE user_id = ? GROUP BY account_id");
$ts->execute([$userId]);
foreach ($ts->fetchAll() as $r) { $tokenCounts[(int)$r['account_id']] = (int)$r['c']; }

// Домены, уже привязанные к аккаунтам — чтобы искать аккаунт не только по email,
// но и по домену (cloudflare_accounts.domain → cloudflare_credentials.id).
$accountDomains = [];
$ds = $pdo->prepare("SELECT DISTINCT account_id, domain FROM cloudflare_accounts
    WHERE user_id = ? AND domain IS NOT NULL AND domain <> '' ORDER BY domain");
$ds->execute([$userId]);
foreach ($ds->fetchAll() as $r) { $accountDomains[(int)$r['account_id']][] = $r['domain']; }

include 'sidebar.php';
?>

<div class="content">
    <div class="content-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="fas fa-rocket me-2"></i>Деплой из ZIP</h1>
                <p class="text-muted mb-0">
                    Публикация статического сайта из ZIP на Cloudflare Workers (Static Assets) —
                    без хостинга и сервера. Один сайт = один воркер.
                </p>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex align-items-start">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Загрузите ZIP → проверьте архив → выберите аккаунт и домен → опубликуйте.</strong>
            Лимит 25&nbsp;MiB <em>на каждый файл</em> (размер архива не ограничен). Публикация идёт на
            служебный <code>*.workers.dev</code>; если домен в выбранном аккаунте — его можно привязать
            (Custom Domain + SSL) в блоке «Мои сайты». Тогда воркер обслуживает домен на edge Cloudflare
            с приоритетом над прежним сервером.
        </div>
    </div>

    <div class="row g-3">
        <!-- Шаг 1: архив -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-file-zipper me-2"></i>1. Архив сайта</div>
                <div class="card-body">
                    <div id="dropzone" class="deploy-dropzone">
                        <i class="fas fa-cloud-arrow-up fa-2x mb-2 text-primary"></i>
                        <div class="fw-semibold">Перетащите ZIP сюда или нажмите, чтобы выбрать</div>
                        <div class="text-muted small mt-1">Ожидается статический сайт с корневым index.html</div>
                        <input type="file" id="archiveInput" accept=".zip,application/zip" hidden>
                    </div>
                    <div id="fileInfo" class="mt-3 d-none">
                        <span class="badge bg-secondary" id="fileName"></span>
                        <span class="badge bg-light text-dark" id="fileSize"></span>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" id="clearFile">убрать</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Шаг 2: аккаунт и домен -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-user-shield me-2"></i>2. Аккаунт и домен</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Аккаунт Cloudflare</label>
                        <?php
                            // Данные для тайпхеда: одна запись на РЕАЛЬНЫЙ аккаунт (коллапс по
                            // cf_account_uid — «мост», шаг 7). Даже если в БД ещё остались дубли,
                            // деплой показывает один аккаунт: представитель — предпочтительно
                            // token-кредентал с токеном, затем с большим числом доменов, затем меньший
                            // id; домены объединяются. Поиск — по логину ИЛИ любому домену.
                            $mkLogin = function ($email) {
                                $l = preg_replace('/\s*#\d+$/u', '', (string)$email);        // суффикс коллизии « #N»
                                $l = preg_replace('/[\'\x{2019}]s Account$/u', '', (string)$l); // хвост «'s Account»
                                return trim($l) !== '' ? trim($l) : (string)$email;
                            };
                            $byKey = [];
                            foreach ($accounts as $acc) {
                                $aid     = (int)$acc['id'];
                                $uid     = trim((string)($acc['cf_account_uid'] ?? ''));
                                $key     = $uid !== '' ? 'uid:' . $uid : 'id:' . $aid;
                                $isToken = (($acc['auth_type'] ?? '') === 'token') && trim((string)$acc['api_key']) !== '';
                                $hasTok  = !empty($tokenCounts[$aid]) || $isToken;
                                $doms    = array_map('strtolower', $accountDomains[$aid] ?? []);
                                if (!isset($byKey[$key])) {
                                    $byKey[$key] = [
                                        'id' => $aid, 'login' => $mkLogin($acc['email']), 'label' => (string)$acc['email'],
                                        'status' => $acc['status'], 'hasToken' => $hasTok, 'domains' => $doms,
                                        '_isToken' => $isToken, '_domCount' => count($doms),
                                    ];
                                    continue;
                                }
                                $g = $byKey[$key];
                                $g['domains']  = array_values(array_unique(array_merge($g['domains'], $doms)));
                                $g['hasToken'] = $g['hasToken'] || $hasTok;
                                $better = ($isToken && !$g['_isToken'])
                                    || ($isToken === $g['_isToken'] && count($doms) > $g['_domCount'])
                                    || ($isToken === $g['_isToken'] && count($doms) === $g['_domCount'] && $aid < $g['id']);
                                if ($better) {
                                    $g['id'] = $aid; $g['login'] = $mkLogin($acc['email']); $g['label'] = (string)$acc['email'];
                                    $g['status'] = $acc['status']; $g['_isToken'] = $isToken; $g['_domCount'] = count($doms);
                                }
                                $byKey[$key] = $g;
                            }
                            $accountData = array_values(array_map(function ($g) {
                                unset($g['_isToken'], $g['_domCount']);
                                return $g;
                            }, $byKey));
                        ?>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="accountSearch" role="combobox"
                                   aria-autocomplete="list" aria-expanded="false" autocomplete="off"
                                   placeholder="начните вводить логин или домен аккаунта…">
                            <div id="accountDropdown" class="list-group position-absolute w-100 shadow"
                                 style="z-index:1050; max-height:280px; overflow-y:auto; display:none;"></div>
                        </div>
                        <input type="hidden" id="accountSelect" value="">
                        <script>window.CF_ACCOUNTS = <?php echo json_encode($accountData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
                        <?php if (empty($accounts)): ?>
                            <div class="form-text text-warning">Нет аккаунтов Cloudflare — добавьте их в «Мастер-токен».</div>
                        <?php else: ?>
                            <div class="form-text" id="accountHint">Начните вводить логин <em>или домен</em> — найдём аккаунт. Токен берётся из «Мастер-токен».</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Домен сайта</label>
                        <input type="text" class="form-control" id="domainInput" list="domainList" placeholder="example.com" autocomplete="off">
                        <datalist id="domainList"></datalist>
                        <div class="form-text" id="domainHint">Выберите аккаунт — подтянем его домены. Можно ввести домен вручную.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Режим защиты (FR-8) -->
    <div class="card mt-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-4 align-items-center">
                <span class="fw-semibold"><i class="fas fa-shield-halved me-2"></i>Режим</span>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="modeStatic" value="static-only" checked>
                    <label class="form-check-label" for="modeStatic">
                        <strong>static-only</strong> — чистая раздача (не расходует дневной лимит вызовов)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="modeWorker" value="worker-first">
                    <label class="form-check-label" for="modeWorker">
                        <strong>worker-first</strong> — воркер на каждый запрос
                    </label>
                </div>
            </div>
            <div id="workerFirstWarn" class="alert alert-warning py-2 mt-2 mb-0 small d-none">
                <i class="fas fa-triangle-exclamation me-2"></i>
                В режиме worker-first <strong>каждый заход расходует дневной лимит вызовов аккаунта</strong>
                (Free — 100&nbsp;000/день). Для большого трафика нужен платный аккаунт.
            </div>
        </div>
    </div>

    <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
        <button type="button" class="btn btn-primary" id="validateBtn">
            <i class="fas fa-magnifying-glass me-2"></i>Проверить архив
        </button>
        <button type="button" class="btn btn-outline-secondary" id="checkDomainBtn" disabled>
            <i class="fas fa-globe me-2"></i>Проверить домен
        </button>
        <button type="button" class="btn btn-success" id="publishBtn" disabled>
            <i class="fas fa-rocket me-2"></i>Опубликовать
        </button>
    </div>

    <!-- Состояние домена (FR-5) -->
    <div id="domainState" class="mt-3 d-none">
        <div class="alert alert-secondary mb-0">
            <div class="fw-semibold mb-1"><i class="fas fa-globe me-2"></i>Состояние домена</div>
            <div id="domainStateBody" class="small"></div>
        </div>
    </div>

    <!-- Прогресс/итог деплоя -->
    <div id="deployResult" class="mt-3 d-none">
        <div class="card">
            <div class="card-header"><i class="fas fa-list-check me-2"></i>Публикация</div>
            <div class="card-body">
                <ul class="list-group list-group-flush" id="deploySteps"></ul>
                <div id="deployFinal" class="mt-3"></div>
            </div>
        </div>
    </div>

    <!-- Сводка проверки -->
    <div id="reportArea" class="mt-4 d-none">
        <div class="card">
            <div class="card-header"><i class="fas fa-clipboard-check me-2"></i>Сводка по архиву</div>
            <div class="card-body">
                <div class="row text-center g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="deploy-stat"><div class="deploy-stat__num" id="statFiles">0</div><div class="deploy-stat__lbl">файлов</div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="deploy-stat"><div class="deploy-stat__num" id="statSize">0</div><div class="deploy-stat__lbl">размер</div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="deploy-stat"><div class="deploy-stat__num" id="statPages">0</div><div class="deploy-stat__lbl">страниц</div></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="deploy-stat"><div class="deploy-stat__num" id="statHtaccess">—</div><div class="deploy-stat__lbl">.htaccess</div></div>
                    </div>
                </div>
                <div id="reportRoot" class="small text-muted mb-2"></div>
                <div id="reportWarnings"></div>
            </div>
        </div>
    </div>

    <!-- Мои сайты (FR-7 привязка/SSL, FR-9 обновление) -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-server me-2"></i>Мои сайты</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshSites" title="Обновить список">
                <i class="fas fa-rotate"></i>
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Домен</th><th>Аккаунт</th><th>Режим</th>
                            <th>Custom Domain</th><th>SSL</th><th class="text-end">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="sitesBody">
                        <tr><td colspan="6" class="text-muted small">Загрузка…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="form-text mt-2">
                <i class="fas fa-circle-info me-1"></i>Обновление сайта (FR-9): выберите тот же аккаунт и домен,
                загрузите новый ZIP и нажмите «Опубликовать» — версия заменится атомарно (неизменённые файлы
                не перезаливаются). Правки мета/подпапок — фаза&nbsp;4.
            </div>
        </div>
    </div>
</div>

<!-- Всплывающее окно: файлы > 25 MiB (FR-1) -->
<div class="modal fade" id="oversizedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Файлы больше 25 MiB</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <p>Эти файлы нельзя залить на Cloudflare Static Assets (лимит — 25&nbsp;MiB на файл).
                   Удалите или замените их в архиве и повторите загрузку:</p>
                <ul class="list-group" id="oversizedList"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Понятно</button>
            </div>
        </div>
    </div>
</div>

<!-- Версии и мета (FR-10) -->
<div class="modal fade" id="versionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sitemap me-2"></i>Версии: <span id="vmDomain"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <div id="vmNoSource" class="alert alert-warning small d-none">
                    <i class="fas fa-triangle-exclamation me-2"></i>Нет сохранённого исходника — загрузите ZIP для этого
                    домена заново, тогда правки версий/меты станут доступны.
                </div>

                <h6 class="mb-2">Версии сайта (копии в подпапках)</h6>
                <div class="table-responsive mb-2">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr>
                            <th>Версия</th><th></th>
                        </tr></thead>
                        <tbody id="vmVersions"></tbody>
                    </table>
                </div>

                <div class="row g-2 align-items-end mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small mb-1">Новая подпапка</label>
                        <input type="text" class="form-control form-control-sm" id="vmNewPrefix" placeholder="en, es-cl">
                    </div>
                    <div class="col-sm-5">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="vmShare">
                            <label class="form-check-label small" for="vmShare">делить ассеты с корнем (не дублировать)</label>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="button" class="btn btn-sm btn-primary w-100" id="vmAdd">
                            <i class="fas fa-plus me-1"></i>Добавить копию
                        </button>
                    </div>
                </div>

                <div class="form-text mb-0">
                    Метатеги (title / description / H1 / canonical / hreflang) настраиваются по каждой странице
                    в разделе «SEO».
                </div>
                <div id="vmStatus" class="small mt-2"></div>
            </div>
        </div>
    </div>
</div>

<!-- SEO/мета по страницам (FR-10.3) -->
<div class="modal fade" id="pageMetaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tags me-2"></i>SEO-метатеги: <span id="pmDomain"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <div id="pmNoSource" class="alert alert-warning small d-none">
                    <i class="fas fa-triangle-exclamation me-2"></i>Нет сохранённого исходника — загрузите ZIP для этого
                    домена заново, тогда правки метатегов станут доступны.
                </div>
                <div id="pmBody" class="d-none">
                    <div class="mb-3">
                        <label class="form-label small mb-1">Страница</label>
                        <select class="form-select form-select-sm" id="pmPage"></select>
                        <div class="form-text">Пустое поле = не переопределять (останется как в исходнике). Placeholder показывает текущее значение.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Title</label>
                        <input type="text" class="form-control form-control-sm" id="pmTitle" maxlength="300">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Meta description</label>
                        <textarea class="form-control form-control-sm" id="pmDesc" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">H1 (первый на странице)</label>
                        <input type="text" class="form-control form-control-sm" id="pmH1" maxlength="300">
                    </div>
                    <div class="row g-2">
                        <div class="col-sm-8 mb-2">
                            <label class="form-label small mb-1">Canonical (URL)</label>
                            <input type="text" class="form-control form-control-sm" id="pmCanonical" placeholder="https://…">
                        </div>
                        <div class="col-sm-4 mb-2">
                            <label class="form-label small mb-1">Robots</label>
                            <input type="text" class="form-control form-control-sm" id="pmRobots" placeholder="index,follow">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">hreflang (свой код в &lt;head&gt;)</label>
                        <textarea class="form-control form-control-sm font-monospace" id="pmHreflang" rows="4" spellcheck="false"
                            placeholder='&lt;link rel="alternate" hreflang="en" href="https://example.com/"&gt;
&lt;link rel="alternate" hreflang="x-default" href="https://example.com/"&gt;'></textarea>
                        <div class="form-text">Вставляется как есть в &lt;head&gt; (настройка hreflang вариативна — задаёте своим кодом). Можно любой head-HTML. Пусто = не добавлять.</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="form-text mb-0">Правки применяются точечно к тегам; остальной HTML не меняется. После сохранения сайт переиздаётся.</div>
                        <button type="button" class="btn btn-success btn-sm" id="pmSave">
                            <i class="fas fa-floppy-disk me-1"></i>Сохранить и переиздать
                        </button>
                    </div>
                    <div id="pmStatus" class="small mt-2"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$pageStyles = <<<CSS
.deploy-dropzone {
    border: 2px dashed var(--bs-border-color, #ccc);
    border-radius: 12px;
    padding: 2.2rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.deploy-dropzone:hover, .deploy-dropzone.dragover {
    border-color: var(--bs-primary, #2358e0);
    background: rgba(35,88,224,.05);
}
.deploy-stat { padding: .6rem; border-radius: 10px; background: rgba(0,0,0,.03); }
.deploy-stat__num { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
.deploy-stat__lbl { font-size: .8rem; color: var(--bs-secondary, #6c757d); }
CSS;

$pageScripts = <<<'JS'
(function () {
    const dz = document.getElementById('dropzone');
    const input = document.getElementById('archiveInput');
    const fileInfo = document.getElementById('fileInfo');
    const fileNameEl = document.getElementById('fileName');
    const fileSizeEl = document.getElementById('fileSize');
    const clearBtn = document.getElementById('clearFile');
    const validateBtn = document.getElementById('validateBtn');
    const checkDomainBtn = document.getElementById('checkDomainBtn');
    const publishBtn = document.getElementById('publishBtn');
    const accountSelect = document.getElementById('accountSelect');   // скрытый: хранит id аккаунта
    const accountSearch = document.getElementById('accountSearch');    // поисковый ввод
    const domainInput = document.getElementById('domainInput');
    const domainList = document.getElementById('domainList');
    const reportArea = document.getElementById('reportArea');
    let selectedFile = null;
    let archiveValid = false;
    let accountZones = [];   // домены выбранного аккаунта (для проверки наличия)

    // Аккаунты для тайпхеда (эмитятся из PHP как window.CF_ACCOUNTS). Каждый аккаунт —
    // ОДНА запись; ищем по логину ИЛИ по любому домену аккаунта.
    const ACCOUNTS = (window.CF_ACCOUNTS || []);
    const byId = {};
    ACCOUNTS.forEach(a => { byId[String(a.id)] = a; });

    function currentMode() {
        const el = document.querySelector('input[name="mode"]:checked');
        return el ? el.value : 'static-only';
    }
    // Ответ эндпоинта всегда JSON. Если пришёл HTML (PHP-ошибка/редирект на логин),
    // resp.json() падает с «Unexpected token '<'» — показываем понятную причину.
    async function readJson(resp) {
        const text = await resp.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            const snippet = text.trim().slice(0, 160).replace(/\s+/g, ' ');
            throw new Error('сервер вернул не JSON (HTTP ' + resp.status + '): ' + (snippet || '(пустой ответ)'));
        }
    }
    function ready() {
        return archiveValid && selectedFile && accountSelect.value && domainInput.value.trim();
    }
    function refreshButtons() {
        const ok = ready();
        checkDomainBtn.disabled = !ok;
        publishBtn.disabled = !ok;
    }
    document.querySelectorAll('input[name="mode"]').forEach(r =>
        r.addEventListener('change', () => {
            document.getElementById('workerFirstWarn').classList.toggle('d-none', currentMode() !== 'worker-first');
        }));

    // --- Выбор аккаунта: кастомный тайпхед (поиск по логину ИЛИ домену) ---
    const accountHint = document.getElementById('accountHint');
    const accountDropdown = document.getElementById('accountDropdown');
    let loadedZonesAccountId = null;
    let LAST_ITEMS = [];   // текущий отфильтрованный список (для клавиатуры)
    let activeIdx = -1;    // подсвеченная строка

    function esc(s) { const d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

    // Совпадения: {acc, matchedDomain|null}. matchedDomain != null → нашли ТОЛЬКО по домену.
    function matchAccounts(q) {
        q = (q || '').trim().toLowerCase();
        const out = [];
        ACCOUNTS.forEach(a => {
            const loginHit = !q || a.login.toLowerCase().indexOf(q) !== -1;
            let matchedDomain = null;
            if (q) { for (let i = 0; i < a.domains.length; i++) { if (a.domains[i].indexOf(q) !== -1) { matchedDomain = a.domains[i]; break; } } }
            if (loginHit || matchedDomain) out.push({ acc: a, matchedDomain: loginHit ? null : matchedDomain });
        });
        return out;
    }

    function renderDropdown(items) {
        if (!items.length) { accountDropdown.style.display = 'none'; accountDropdown.innerHTML = ''; accountSearch.setAttribute('aria-expanded', 'false'); return; }
        let html = '';
        items.slice(0, 50).forEach((it, i) => {
            const a = it.acc;
            const sub = it.matchedDomain
                ? ('<i class="fas fa-globe me-1"></i><span class="text-primary">' + esc(it.matchedDomain) + '</span>')
                : ('<span class="text-muted">доменов: ' + a.domains.length + '</span>');
            const noTok = a.hasToken ? '' : ' <span class="badge bg-warning text-dark">нет токена</span>';
            const inact = (a.status && a.status !== 'active') ? ' <span class="badge bg-secondary">' + esc(a.status) + '</span>' : '';
            html += '<button type="button" class="list-group-item list-group-item-action py-2 acc-opt" data-id="' + a.id + '" data-idx="' + i + '">'
                  + '<div class="fw-semibold text-truncate">' + esc(a.login) + noTok + inact + '</div>'
                  + '<div class="small text-truncate">' + sub + '</div>'
                  + '</button>';
        });
        accountDropdown.innerHTML = html;
        accountDropdown.style.display = 'block';
        accountSearch.setAttribute('aria-expanded', 'true');
    }

    function openFor(q) { LAST_ITEMS = matchAccounts(q); activeIdx = -1; renderDropdown(LAST_ITEMS); }

    function selectAccount(acc) {
        accountSearch.value = acc.login;
        accountSelect.value = acc.id;
        accountDropdown.style.display = 'none';
        accountSearch.setAttribute('aria-expanded', 'false');
        refreshButtons();
        loadAccountZones(acc);
    }

    // Заполнить datalist «Домен сайта».
    function fillDomainList(zones) {
        domainList.innerHTML = '';
        zones.forEach(z => { const o = document.createElement('option'); o.value = z; domainList.appendChild(o); });
    }
    function updateAccountHint(entry, zones) {
        if (!accountHint) return;
        if (!entry.hasToken) {
            accountHint.innerHTML = '<span class="text-warning"><i class="fas fa-triangle-exclamation me-1"></i>'
                + 'У аккаунта нет scoped-токена — создайте его в «Мастер-токен» (право Workers Scripts:Edit).</span>';
        } else if (!zones.length) {
            accountHint.innerHTML = '<span class="text-muted">В аккаунте нет доменов — введите домен вручную.</span>';
        } else {
            accountHint.innerHTML = '<span class="text-muted">Доменов в аккаунте: ' + zones.length + '. Выберите из списка или введите вручную.</span>';
        }
    }

    // Домены выбранного аккаунта (FR-5). Источник правды — домены из панели (БД): они уже
    // показаны в списке аккаунтов и корректны. Живой запрос к Cloudflare лишь ДОПОЛНЯЕТ список
    // (union) — он не должен обнулять выпадающий список, если у токена нет прав/зона не видна.
    async function loadAccountZones(entry) {
        if (String(entry.id) === String(loadedZonesAccountId)) { updateDomainHint(); return; }
        loadedZonesAccountId = entry.id;
        // 1) Базовый список — из БД панели (entry.domains).
        accountZones = (entry.domains || []).slice().sort();
        fillDomainList(accountZones);
        updateAccountHint(entry, accountZones);
        updateDomainHint();
        // 2) Дополняем живыми зонами из Cloudflare (union), не теряя домены из БД.
        try {
            const data = await apiPost({ action: 'account_zones', account_id: entry.id });
            if (String(entry.id) !== String(loadedZonesAccountId)) return; // аккаунт уже сменили
            if (data.success && Array.isArray(data.zones) && data.zones.length) {
                const set = {};
                accountZones.forEach(z => { set[z.toLowerCase()] = z; });
                data.zones.forEach(z => { if (z) set[z.toLowerCase()] = z; });
                accountZones = Object.keys(set).map(k => set[k]).sort();
                fillDomainList(accountZones);
                updateAccountHint(entry, accountZones);
            }
        } catch (e) { /* оффлайн / нет прав — остаёмся на доменах из БД */ }
        updateDomainHint();
    }

    accountSearch.addEventListener('input', () => {
        accountSelect.value = '';   // до явного выбора аккаунт не считается выбранным
        refreshButtons();
        openFor(accountSearch.value);
    });
    accountSearch.addEventListener('focus', () => openFor(accountSearch.value));
    accountSearch.addEventListener('keydown', (e) => {
        if (accountDropdown.style.display === 'none') return;
        const opts = Array.prototype.slice.call(accountDropdown.querySelectorAll('.acc-opt'));
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, opts.length - 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); }
        else if (e.key === 'Enter') {
            if (activeIdx >= 0 && LAST_ITEMS[activeIdx]) { e.preventDefault(); selectAccount(LAST_ITEMS[activeIdx].acc); }
            else if (LAST_ITEMS.length === 1) { e.preventDefault(); selectAccount(LAST_ITEMS[0].acc); }
            return;
        }
        else if (e.key === 'Escape') { accountDropdown.style.display = 'none'; return; }
        else return;
        opts.forEach((o, i) => o.classList.toggle('active', i === activeIdx));
        if (opts[activeIdx]) opts[activeIdx].scrollIntoView({ block: 'nearest' });
    });
    // mousedown (не click) — чтобы выбор срабатывал до blur поля.
    accountDropdown.addEventListener('mousedown', (e) => {
        const btn = e.target.closest('.acc-opt');
        if (!btn) return;
        e.preventDefault();
        const acc = byId[btn.dataset.id];
        if (acc) selectAccount(acc);
    });
    document.addEventListener('click', (e) => {
        if (e.target !== accountSearch && !accountDropdown.contains(e.target)) accountDropdown.style.display = 'none';
    });

    // --- Проверка наличия домена в аккаунте ---
    const domainHint = document.getElementById('domainHint');
    function updateDomainHint() {
        const d = domainInput.value.trim().toLowerCase().replace(/^https?:\/\//,'').replace(/\/$/,'');
        if (!d || !accountSelect.value) { domainHint.textContent = 'Выберите аккаунт — подтянем его домены. Можно ввести вручную.'; return; }
        if (accountZones.length && accountZones.map(z=>z.toLowerCase()).includes(d)) {
            domainHint.innerHTML = '<span class="text-success"><i class="fas fa-circle-check me-1"></i>Домен есть в аккаунте — привязка домена будет доступна.</span>';
        } else {
            domainHint.innerHTML = '<span class="text-secondary"><i class="fas fa-circle-info me-1"></i>Домена нет в этом аккаунте — публикация пойдёт на *.workers.dev (привязка домена недоступна, §8).</span>';
        }
    }
    domainInput.addEventListener('input', () => { refreshButtons(); updateDomainHint(); });

    function fmtSize(bytes) {
        if (!bytes && bytes !== 0) return '—';
        const u = ['Б','КБ','МБ','ГБ','ТБ'];
        let i = 0, n = bytes;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
    }

    function setFile(file) {
        selectedFile = file;
        archiveValid = false;
        refreshButtons();
        if (file) {
            fileNameEl.textContent = file.name;
            fileSizeEl.textContent = fmtSize(file.size);
            fileInfo.classList.remove('d-none');
        } else {
            fileInfo.classList.add('d-none');
            input.value = '';
        }
    }

    dz.addEventListener('click', () => input.click());
    input.addEventListener('change', () => { if (input.files[0]) setFile(input.files[0]); });
    clearBtn.addEventListener('click', () => setFile(null));

    ['dragenter', 'dragover'].forEach(ev =>
        dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach(ev =>
        dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('dragover'); }));
    dz.addEventListener('drop', e => {
        const f = e.dataTransfer.files[0];
        if (f) setFile(f);
    });

    function renderReport(r, valid) {
        document.getElementById('statFiles').textContent = r.total_files;
        document.getElementById('statSize').textContent = fmtSize(r.total_size);
        document.getElementById('statPages').textContent = r.pages_count;
        document.getElementById('statHtaccess').textContent = r.has_htaccess ? 'есть' : 'нет';

        const rootEl = document.getElementById('reportRoot');
        rootEl.textContent = r.root_prefix
            ? 'Корень сайта поднят из папки: ' + r.root_prefix
            : 'Корень сайта — корень архива.';

        const warn = document.getElementById('reportWarnings');
        warn.innerHTML = '';
        // Явное подтверждение, что архив валиден: предупреждения ниже — необязательные,
        // публикацию не блокируют (частая путаница: оранжевые warning принимают за ошибку).
        if (valid) {
            const ok = document.createElement('div');
            ok.className = 'alert alert-success py-2 mb-2 small';
            ok.innerHTML = '<i class="fas fa-circle-check me-2"></i>Архив валиден: index.html найден — можно публиковать. '
                + 'Замечания ниже (если есть) — необязательные и не мешают публикации.';
            warn.appendChild(ok);
        }
        (r.warnings || []).forEach(w => {
            const d = document.createElement('div');
            d.className = 'alert alert-warning py-2 mb-2 small';
            d.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i>' + w;
            warn.appendChild(d);
        });
        if (r.server_files && r.server_files.length) {
            const d = document.createElement('div');
            d.className = 'alert alert-warning py-2 mb-2 small';
            d.textContent = 'Серверные файлы (не исполняются): ' + r.server_files.slice(0, 10).join(', ')
                + (r.server_files.length > 10 ? ' …' : '');
            warn.appendChild(d);
        }
        reportArea.classList.remove('d-none');
    }

    function showOversized(list) {
        const ul = document.getElementById('oversizedList');
        ul.innerHTML = '';
        list.forEach(f => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center';
            li.innerHTML = '<span class="text-truncate me-2">' + f.path + '</span>'
                + '<span class="badge bg-danger">' + fmtSize(f.size) + '</span>';
            ul.appendChild(li);
        });
        new bootstrap.Modal(document.getElementById('oversizedModal')).show();
    }

    validateBtn.addEventListener('click', async () => {
        if (!selectedFile) { showToast('Сначала выберите ZIP-архив', 'warning'); return; }

        const fd = new FormData();
        fd.append('action', 'validate');
        fd.append('archive', selectedFile);

        validateBtn.disabled = true;
        const original = validateBtn.innerHTML;
        validateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Проверяю…';
        reportArea.classList.add('d-none');

        try {
            const resp = await fetch('deploy_api.php', { method: 'POST', body: fd });
            const data = await readJson(resp);
            const r = data.report;

            // Файлы > 25 MiB — всплывающее окно (FR-1), даже если success=false.
            if (r && r.oversized && r.oversized.length) {
                showOversized(r.oversized);
            }
            if (data.success) {
                renderReport(r, true);
                archiveValid = true;
                showToast('Архив прошёл проверку', 'success');
            } else {
                if (r) renderReport(r, false);
                archiveValid = false;
                showToast(data.error || 'Архив не прошёл проверку', 'error');
            }
        } catch (e) {
            showToast('Ошибка сети: ' + e.message, 'error');
        } finally {
            validateBtn.disabled = false;
            validateBtn.innerHTML = original;
            refreshButtons();
        }
    });

    // ---- FR-5: проверка состояния домена ----
    checkDomainBtn.addEventListener('click', async () => {
        const fd = new FormData();
        fd.append('action', 'check_domain');
        fd.append('account_id', accountSelect.value);
        fd.append('domain', domainInput.value.trim());

        checkDomainBtn.disabled = true;
        const original = checkDomainBtn.innerHTML;
        checkDomainBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Проверяю…';
        try {
            const resp = await fetch('deploy_api.php', { method: 'POST', body: fd });
            const data = await readJson(resp);
            if (!data.success) { showToast(data.error || 'Ошибка проверки домена', 'error'); return; }
            const s = data.state;
            const rows = [];
            rows.push('<strong>Домен:</strong> ' + data.domain + ' &nbsp; <strong>Воркер:</strong> <code>' + data.worker_name + '</code>');
            rows.push('<strong>Зона в аккаунте:</strong> ' + (s.zone_in_account
                ? '<span class="text-success">да</span>' : '<span class="text-danger">нет</span>'));
            if (s.zone_in_account) {
                rows.push('<strong>DNS-запись:</strong> ' + (s.dns_present ? 'есть' : 'нет'));
                rows.push('<strong>Привязка воркера:</strong> ' + (s.worker_binding
                    ? ('к «' + s.worker_binding + '»') : 'свободен'));
                rows.push('<strong>SSL:</strong> ' + (s.ssl_active ? 'активен' : 'выключен'));
            }
            rows.push('<div class="mt-2">' + s.summary + '</div>');
            // Пояснение о приоритете относительно возможного старого сервера.
            if (s.zone_in_account) {
                rows.push('<div class="alert alert-light border mt-2 mb-0 py-2">'
                    + '<i class="fas fa-circle-info me-1"></i><strong>Приоритет.</strong> '
                    + 'Домен в этом аккаунте, NS указывают на Cloudflare. После привязки Custom Domain '
                    + 'запрос обслуживает воркер на edge Cloudflare — до старого сервера трафик не доходит, '
                    + 'воркер имеет приоритет над прежней A-записью/origin.</div>');
            } else {
                rows.push('<div class="alert alert-light border mt-2 mb-0 py-2">'
                    + '<i class="fas fa-circle-info me-1"></i><strong>Приоритет.</strong> '
                    + 'Зоны нет в этом аккаунте — публикация живёт только на <code>*.workers.dev</code> и '
                    + '<strong>не влияет на боевой домен</strong>: по нему по-прежнему отвечает текущий сервер '
                    + '(куда указывают nameservers). Чтобы переключить — перенесите зону в этот аккаунт (смена NS).</div>');
            }
            document.getElementById('domainStateBody').innerHTML = rows.join('<br>');
            document.getElementById('domainState').classList.remove('d-none');
        } catch (e) {
            showToast('Ошибка сети: ' + e.message, 'error');
        } finally {
            checkDomainBtn.disabled = false;
            checkDomainBtn.innerHTML = original;
            refreshButtons();
        }
    });

    // ---- FR-6: публикация на Static Assets ----
    publishBtn.addEventListener('click', async () => {
        if (!ready()) { showToast('Проверьте архив, выберите аккаунт и домен', 'warning'); return; }

        const fd = new FormData();
        fd.append('action', 'deploy');
        fd.append('account_id', accountSelect.value);
        fd.append('domain', domainInput.value.trim());
        fd.append('mode', currentMode());
        fd.append('archive', selectedFile);

        publishBtn.disabled = true;
        checkDomainBtn.disabled = true;
        validateBtn.disabled = true;
        const original = publishBtn.innerHTML;
        publishBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Публикую…';

        const stepsEl = document.getElementById('deploySteps');
        const finalEl = document.getElementById('deployFinal');
        stepsEl.innerHTML = '';
        finalEl.innerHTML = '';
        document.getElementById('deployResult').classList.remove('d-none');

        try {
            const resp = await fetch('deploy_api.php', { method: 'POST', body: fd });
            const data = await readJson(resp);

            (data.steps || []).forEach(st => {
                const li = document.createElement('li');
                li.className = 'list-group-item d-flex align-items-center';
                const icon = st.ok
                    ? '<i class="fas fa-circle-check text-success me-2"></i>'
                    : '<i class="fas fa-circle-xmark text-danger me-2"></i>';
                li.innerHTML = icon + '<span class="fw-semibold me-2">' + st.step + '</span>'
                    + '<span class="text-muted small">' + (st.info || '') + '</span>';
                stepsEl.appendChild(li);
            });

            if (data.success) {
                const url = data.workers_dev_url;
                let html = '<div class="alert alert-success mb-2">'
                    + '<i class="fas fa-circle-check me-2"></i>Сайт опубликован. '
                    + (url ? ('<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>') : 'URL уточните в Cloudflare.')
                    + '</div>';
                if (data.warning) {
                    html += '<div class="alert alert-warning py-2 mb-2 small"><i class="fas fa-triangle-exclamation me-2"></i>'
                        + esc(data.warning) + '</div>';
                }
                if (data.zone_in_account) {
                    html += '<button type="button" class="btn btn-primary btn-sm" id="bindAfterDeploy">'
                        + '<i class="fas fa-link me-2"></i>Привязать домен ' + domainInput.value.trim() + ' + SSL</button>';
                } else {
                    html += '<div class="text-muted small"><i class="fas fa-circle-info me-1"></i>'
                        + 'Зоны домена нет в этом аккаунте — привязка недоступна (§8). Сайт живёт на workers.dev.</div>';
                }
                finalEl.innerHTML = html;
                const bindBtn = document.getElementById('bindAfterDeploy');
                if (bindBtn) bindBtn.addEventListener('click', () =>
                    bindDomain(accountSelect.value, domainInput.value.trim()));
                loadSites();
                showToast('Сайт опубликован', 'success');
            } else {
                if (data.report && data.report.oversized && data.report.oversized.length) {
                    showOversized(data.report.oversized);
                }
                const phase = data.phase
                    ? '<div class="small mt-2"><i class="fas fa-diagram-project me-1"></i>Шаг: <span class="fw-semibold">' + esc(data.phase) + '</span></div>' : '';
                const kind = data.error_kind === 'db_locked'
                    ? '<div class="small mt-1"><i class="fas fa-database me-1"></i>Причина: конкурентная блокировка SQLite (одновременная фоновая запись). Публикация файлов могла пройти — повторите через несколько секунд.</div>' : '';
                const detail = (data.detail && data.detail !== data.error)
                    ? '<div class="small text-muted mt-1 font-monospace" style="word-break:break-word">' + esc(data.detail) + '</div>' : '';
                finalEl.innerHTML = '<div class="alert alert-danger mb-0">'
                    + '<i class="fas fa-circle-xmark me-2"></i>' + esc(data.error || 'Ошибка публикации')
                    + phase + kind + detail + '</div>';
                showToast(data.error || 'Ошибка публикации', 'error');
            }
        } catch (e) {
            showToast('Ошибка сети: ' + e.message, 'error');
        } finally {
            publishBtn.innerHTML = original;
            validateBtn.disabled = false;
            refreshButtons();
        }
    });

    // ---- Мои сайты (FR-7 / FR-9) ----
    const sitesBody = document.getElementById('sitesBody');

    async function apiPost(params) {
        const fd = new FormData();
        Object.keys(params).forEach(k => fd.append(k, params[k]));
        const resp = await fetch('deploy_api.php', { method: 'POST', body: fd });
        return readJson(resp);
    }

    async function loadSites() {
        try {
            const data = await apiPost({ action: 'list_sites' });
            const sites = (data && data.sites) || [];
            if (!sites.length) {
                sitesBody.innerHTML = '<tr><td colspan="6" class="text-muted small">Пока нет опубликованных сайтов.</td></tr>';
                return;
            }
            sitesBody.innerHTML = '';
            sites.forEach(s => {
                const bound = Number(s.custom_domain_bound) === 1;
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><div class="fw-semibold">' + s.domain + '</div>'
                        + (s.workers_dev_url ? '<a href="' + s.workers_dev_url + '" target="_blank" rel="noopener" class="small">workers.dev ↗</a>' : '') + '</td>'
                    + '<td class="small">' + (s.account_email || '') + '</td>'
                    + '<td><span class="badge bg-' + (s.protection_mode === 'worker-first' ? 'warning text-dark' : 'secondary') + '">' + s.protection_mode + '</span></td>'
                    + '<td>' + (bound ? '<span class="text-success"><i class="fas fa-link me-1"></i>привязан</span>' : '<span class="text-muted">нет</span>') + '</td>'
                    + '<td class="small">' + (bound ? (s.ssl_status || '—') : '—') + '</td>'
                    + '<td class="text-end"></td>';
                const actions = tr.querySelector('td:last-child');
                if (bound) {
                    const site = 'https://' + s.domain;
                    actions.innerHTML =
                        '<a href="' + site + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary me-1">Открыть</a>'
                        + '<button class="btn btn-sm btn-outline-secondary me-1" data-act="ssl">SSL</button>'
                        + '<button class="btn btn-sm btn-outline-danger me-1" data-act="unbind">Отвязать</button>';
                    actions.querySelector('[data-act="ssl"]').onclick = () => checkSsl(s.account_id, s.domain);
                    actions.querySelector('[data-act="unbind"]').onclick = () => unbindDomain(s.account_id, s.domain);
                } else {
                    actions.innerHTML = '<button class="btn btn-sm btn-primary me-1" data-act="bind">Привязать + SSL</button>';
                    actions.querySelector('[data-act="bind"]').onclick = () => bindDomain(s.account_id, s.domain);
                }
                // SEO/мета по страницам (FR-10.3) — для всех сайтов.
                const seoBtn = document.createElement('button');
                seoBtn.className = 'btn btn-sm btn-outline-secondary me-1';
                seoBtn.innerHTML = '<i class="fas fa-tags"></i> SEO';
                seoBtn.onclick = () => openPageMeta(s.account_id, s.domain);
                actions.appendChild(seoBtn);
                // Версии/мета (FR-10) — для всех сайтов.
                const verBtn = document.createElement('button');
                verBtn.className = 'btn btn-sm btn-outline-dark';
                verBtn.innerHTML = '<i class="fas fa-sitemap"></i> Версии';
                verBtn.onclick = () => openVersions(s.account_id, s.domain);
                actions.appendChild(verBtn);
                sitesBody.appendChild(tr);
            });
        } catch (e) {
            sitesBody.innerHTML = '<tr><td colspan="6" class="text-danger small">Ошибка загрузки: ' + e.message + '</td></tr>';
        }
    }

    function bindResult(data) {
        if (data.success) {
            const notes = (data.notes && data.notes.length) ? ' (' + data.notes.join('; ') + ')' : '';
            showToast('Домен привязан. SSL: ' + (data.ssl_status || '—') + notes, 'success');
            loadSites();
        } else {
            showToast(data.error || 'Ошибка привязки', 'error');
        }
    }

    async function bindDomain(accountId, domain) {
        if (!confirm('Привязать домен ' + domain + ' к этому сайту и включить SSL?\n\n'
            + 'Cloudflare создаст управляемую DNS-запись и выпустит сертификат. Если домен привязан к другому '
            + 'воркеру — он будет перепривязан на этот сайт. TXT/MX/CAA не трогаются.')) return;
        try {
            let data = await apiPost({ action: 'bind_domain', account_id: accountId, domain: domain, confirm: 1 });

            // Второй барьер: на апексе есть A/AAAA/CNAME на прежний сервер — спрашиваем отдельно.
            if (data && data.needs_dns_confirm) {
                const recs = (data.conflict_records || [])
                    .map(r => '  • ' + r.type + ' ' + r.name + ' → ' + r.content + (r.proxied ? ' (proxied)' : ''))
                    .join('\n');
                const msg = 'На апексе домена ' + domain + ' есть DNS-запись, указывающая на прежний сервер:\n\n'
                    + recs + '\n\n'
                    + 'Чтобы домен обслуживался этим сайтом на edge Cloudflare, эту запись нужно заменить '
                    + 'на управляемую. Прежняя запись будет сохранена и восстановлена при «Отвязать». '
                    + 'TXT/MX/CAA и другие записи не трогаются.\n\nЗаменить и привязать?';
                if (!confirm(msg)) { showToast('Привязка отменена', 'info'); return; }
                data = await apiPost({ action: 'bind_domain', account_id: accountId, domain: domain,
                    confirm: 1, confirm_dns_replace: 1 });
            }
            bindResult(data);
        } catch (e) { showToast('Ошибка сети: ' + e.message, 'error'); }
    }

    async function unbindDomain(accountId, domain) {
        if (!confirm('Отвязать домен ' + domain + '? Сайт останется доступен на *.workers.dev.\n\n'
            + 'Если при привязке заменялась DNS-запись апекса — она будет восстановлена '
            + '(домен вернётся на прежний сервер).')) return;
        try {
            const data = await apiPost({ action: 'unbind_domain', account_id: accountId, domain: domain });
            if (data.success) {
                const r = Number(data.restored_dns || 0);
                showToast('Домен отвязан' + (r > 0 ? ' (восстановлено DNS-записей: ' + r + ')' : ''), 'success');
                loadSites();
            }
            else showToast(data.error || 'Ошибка отвязки', 'error');
        } catch (e) { showToast('Ошибка сети: ' + e.message, 'error'); }
    }

    async function checkSsl(accountId, domain) {
        try {
            const data = await apiPost({ action: 'binding_status', account_id: accountId, domain: domain });
            if (data.success) { showToast('SSL: ' + (data.ssl_status || '—') + (data.bound ? '' : ' (не привязан)'), 'info'); loadSites(); }
            else showToast(data.error || 'Ошибка проверки', 'error');
        } catch (e) { showToast('Ошибка сети: ' + e.message, 'error'); }
    }

    // ---- Версии и мета (FR-10) ----
    let vmCtx = { accountId: null, domain: null };
    const vmModalEl = document.getElementById('versionsModal');
    const vmVersions = document.getElementById('vmVersions');

    function vmLabel(prefix) { return prefix === '' ? '/ (корень)' : '/' + prefix + '/'; }

    function renderVersions(data) {
        document.getElementById('vmDomain').textContent = data.domain;
        document.getElementById('vmNoSource').classList.toggle('d-none', !!data.has_source);
        vmVersions.innerHTML = '';
        (data.versions || []).forEach(v => {
            const prefix = (v.prefix || '').replace(/\/+$/,'');
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td class="fw-semibold">' + vmLabel(prefix)
                    + (Number(v.share_root_assets) === 1 ? ' <span class="badge bg-light text-dark">общие ассеты</span>' : '') + '</td>'
                + '<td class="text-end"></td>';
            if (prefix !== '') {
                const rm = document.createElement('button');
                rm.className = 'btn btn-sm btn-outline-danger';
                rm.innerHTML = '<i class="fas fa-trash"></i>';
                rm.onclick = () => removeSubfolder(prefix);
                tr.querySelector('td:last-child').appendChild(rm);
            }
            vmVersions.appendChild(tr);
        });
    }

    async function openVersions(accountId, domain) {
        vmCtx = { accountId, domain };
        document.getElementById('vmStatus').textContent = '';
        try {
            const data = await apiPost({ action: 'list_versions', account_id: accountId, domain: domain });
            if (!data.success) { showToast(data.error || 'Ошибка', 'error'); return; }
            renderVersions(data);
            new bootstrap.Modal(vmModalEl).show();
        } catch (e) { showToast('Ошибка сети: ' + e.message, 'error'); }
    }

    async function refreshVersions() {
        const data = await apiPost({ action: 'list_versions', account_id: vmCtx.accountId, domain: vmCtx.domain });
        if (data.success) renderVersions(data);
    }

    function vmSetStatus(html) { document.getElementById('vmStatus').innerHTML = html; }

    async function addSubfolder() {
        const prefix = document.getElementById('vmNewPrefix').value.trim();
        const share = document.getElementById('vmShare').checked ? 1 : 0;
        if (!prefix) { showToast('Укажите префикс подпапки', 'warning'); return; }
        vmSetStatus('<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Копирую и переиздаю…</span>');
        try {
            const data = await apiPost({ action: 'add_subfolder', account_id: vmCtx.accountId, domain: vmCtx.domain, prefix, share_assets: share });
            if (data.success) {
                vmSetStatus('<span class="text-success">Подпапка ' + data.url + ' создана и переиздана.</span>');
                document.getElementById('vmNewPrefix').value = '';
                await refreshVersions(); loadSites();
            } else { vmSetStatus('<span class="text-danger">' + (data.error || 'Ошибка') + '</span>'); }
        } catch (e) { vmSetStatus('<span class="text-danger">Ошибка сети: ' + e.message + '</span>'); }
    }

    async function removeSubfolder(prefix) {
        if (!confirm('Удалить версию /' + prefix + '/ и переиздать сайт?')) return;
        vmSetStatus('<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Удаляю и переиздаю…</span>');
        try {
            const data = await apiPost({ action: 'remove_subfolder', account_id: vmCtx.accountId, domain: vmCtx.domain, prefix });
            if (data.success) { vmSetStatus('<span class="text-success">Версия удалена.</span>'); await refreshVersions(); loadSites(); }
            else vmSetStatus('<span class="text-danger">' + (data.error || 'Ошибка') + '</span>');
        } catch (e) { vmSetStatus('<span class="text-danger">Ошибка сети: ' + e.message + '</span>'); }
    }

    document.getElementById('vmAdd').addEventListener('click', addSubfolder);

    // ---- SEO/мета по страницам (FR-10.3) ----
    const pmModalEl = document.getElementById('pageMetaModal');
    const pmPage = document.getElementById('pmPage');
    let pmCtx = null, pmPages = [], pmPrevIdx = '';

    function pmStash(idx) {
        if (idx === '' || idx == null || !pmPages[idx]) return;
        const ov = pmPages[idx].override || (pmPages[idx].override = {});
        ov.title = document.getElementById('pmTitle').value;
        ov.description = document.getElementById('pmDesc').value;
        ov.h1 = document.getElementById('pmH1').value;
        ov.canonical = document.getElementById('pmCanonical').value;
        ov.robots = document.getElementById('pmRobots').value;
        ov.hreflang = document.getElementById('pmHreflang').value;
    }
    function pmHasOverride(ov) {
        return !!(ov && (ov.title || ov.description || ov.h1 || ov.canonical || ov.robots || ov.hreflang));
    }
    function pmFill(idx) {
        const p = pmPages[idx]; if (!p) return;
        const cur = p.current || {}, ov = p.override || {};
        const set = (id, val, ph) => { const el = document.getElementById(id); el.value = val || ''; el.placeholder = ph ? ('текущее: ' + ph) : '—'; };
        set('pmTitle', ov.title, cur.title);
        set('pmDesc', ov.description, cur.description);
        set('pmH1', ov.h1, cur.h1);
        set('pmCanonical', ov.canonical, cur.canonical);
        set('pmRobots', ov.robots, cur.robots || 'index,follow');
        document.getElementById('pmHreflang').value = ov.hreflang || '';
        document.getElementById('pmStatus').textContent = '';
    }
    function pmOptLabel(p) { return (pmHasOverride(p.override) ? '✎ ' : '') + p.path; }

    async function openPageMeta(accountId, domain) {
        pmCtx = { accountId, domain };
        document.getElementById('pmDomain').textContent = domain;
        document.getElementById('pmStatus').textContent = '';
        try {
            const data = await apiPost({ action: 'list_pages', account_id: accountId, domain: domain });
            if (!data.success) { showToast(data.error || 'Ошибка', 'error'); return; }
            const noSrc = !data.has_source;
            document.getElementById('pmNoSource').classList.toggle('d-none', !noSrc);
            document.getElementById('pmBody').classList.toggle('d-none', noSrc);
            pmPages = data.pages || [];
            pmPage.innerHTML = '';
            pmPages.forEach((p, i) => {
                const o = document.createElement('option');
                o.value = String(i); o.textContent = pmOptLabel(p);
                pmPage.appendChild(o);
            });
            if (pmPages.length) { pmPage.value = '0'; pmPrevIdx = '0'; pmFill(0); }
            new bootstrap.Modal(pmModalEl).show();
        } catch (e) { showToast('Ошибка сети: ' + e.message, 'error'); }
    }

    pmPage.addEventListener('change', () => {
        pmStash(pmPrevIdx);
        const prevOpt = pmPage.querySelector('option[value="' + pmPrevIdx + '"]');
        if (prevOpt && pmPages[pmPrevIdx]) prevOpt.textContent = pmOptLabel(pmPages[pmPrevIdx]);
        pmPrevIdx = pmPage.value;
        pmFill(pmPage.value);
    });

    async function savePageMeta() {
        const idx = pmPage.value;
        if (idx === '' || !pmPages[idx]) return;
        pmStash(idx);
        const p = pmPages[idx], ov = p.override || {};
        document.getElementById('pmStatus').innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Сохраняю и переиздаю…</span>';
        try {
            const data = await apiPost({ action: 'save_page_meta', account_id: pmCtx.accountId, domain: pmCtx.domain,
                path: p.path, title: ov.title || '', description: ov.description || '', h1: ov.h1 || '',
                canonical: ov.canonical || '', robots: ov.robots || '', hreflang: ov.hreflang || '' });
            if (data.success) {
                document.getElementById('pmStatus').innerHTML = '<span class="text-success">Сохранено и переиздано.</span>';
                const opt = pmPage.querySelector('option[value="' + idx + '"]');
                if (opt) opt.textContent = pmOptLabel(p);
            } else {
                document.getElementById('pmStatus').innerHTML = '<span class="text-danger">' + (data.error || 'Ошибка') + '</span>';
            }
        } catch (e) { document.getElementById('pmStatus').innerHTML = '<span class="text-danger">Ошибка сети: ' + e.message + '</span>'; }
    }
    document.getElementById('pmSave').addEventListener('click', savePageMeta);
    window.openPageMeta = openPageMeta;

    document.getElementById('refreshSites').addEventListener('click', loadSites);

    // Чистый старт: браузер восстанавливает значения полей при перезагрузке/возврате
    // (bfcache), из-за чего аккаунт/домен «оставались выбраны» без действий пользователя.
    // Явно сбрасываем ввод и производное состояние на инициализации и на pageshow.
    function resetDeployForm() {
        accountSearch.value = '';
        accountSelect.value = '';
        domainInput.value = '';
        accountZones = [];
        if (typeof loadedZonesAccountId !== 'undefined') loadedZonesAccountId = null;
        if (accountDropdown) accountDropdown.style.display = 'none';
        if (typeof updateDomainHint === 'function') updateDomainHint();
        refreshButtons();
    }
    resetDeployForm();
    window.addEventListener('pageshow', function (e) { if (e.persisted) resetDeployForm(); });

    loadSites();
})();
JS;

require_once 'footer.php';
?>
