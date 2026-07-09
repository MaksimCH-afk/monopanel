<?php
$pageTitle = 'Деплой';
require_once 'header.php';

$userId = $_SESSION['user_id'];

// Аккаунты Cloudflare = cloudflare_credentials (email + токен). Ручной выбор (FR-4).
$stmt = $pdo->prepare("SELECT id, email, status FROM cloudflare_credentials WHERE user_id = ? ORDER BY email");
$stmt->execute([$userId]);
$accounts = $stmt->fetchAll();

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
            служебный <code>*.workers.dev</code>. Привязка домена и SSL подключаются на фазе&nbsp;3.
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
                        <select class="form-select" id="accountSelect">
                            <option value="">— выберите аккаунт —</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?php echo (int)$acc['id']; ?>">
                                    <?php echo htmlspecialchars($acc['email']); ?>
                                    <?php echo $acc['status'] !== 'active' ? ' (' . htmlspecialchars($acc['status']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($accounts)): ?>
                            <div class="form-text text-warning">Нет аккаунтов Cloudflare — добавьте их в «Мастер-токен».</div>
                        <?php else: ?>
                            <div class="form-text">Домен должен принадлежать этому аккаунту (проверим на фазе 2).</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Домен сайта</label>
                        <input type="text" class="form-control" id="domainInput" placeholder="example.com" autocomplete="off">
                        <div class="form-text">Имя воркера будет получено из домена автоматически.</div>
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
    const accountSelect = document.getElementById('accountSelect');
    const domainInput = document.getElementById('domainInput');
    const reportArea = document.getElementById('reportArea');
    let selectedFile = null;
    let archiveValid = false;

    function currentMode() {
        const el = document.querySelector('input[name="mode"]:checked');
        return el ? el.value : 'static-only';
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
    accountSelect.addEventListener('change', refreshButtons);
    domainInput.addEventListener('input', refreshButtons);

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

    function renderReport(r) {
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
            const data = await resp.json();
            const r = data.report;

            // Файлы > 25 MiB — всплывающее окно (FR-1), даже если success=false.
            if (r && r.oversized && r.oversized.length) {
                showOversized(r.oversized);
            }
            if (data.success) {
                renderReport(r);
                archiveValid = true;
                showToast('Архив прошёл проверку', 'success');
            } else {
                if (r) renderReport(r);
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
            const data = await resp.json();
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
            rows.push('<div class="text-muted mt-1"><i class="fas fa-circle-info me-1"></i>'
                + 'Привязка домена и SSL подключаются на фазе 3. Сейчас публикация идёт на служебный '
                + '<code>*.workers.dev</code>.</div>');
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
            const data = await resp.json();

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
                finalEl.innerHTML = '<div class="alert alert-success mb-0">'
                    + '<i class="fas fa-circle-check me-2"></i>Сайт опубликован. '
                    + (url ? ('<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>') : 'URL уточните в Cloudflare.')
                    + '</div>';
                showToast('Сайт опубликован', 'success');
            } else {
                if (data.report && data.report.oversized && data.report.oversized.length) {
                    showOversized(data.report.oversized);
                }
                finalEl.innerHTML = '<div class="alert alert-danger mb-0">'
                    + '<i class="fas fa-circle-xmark me-2"></i>' + (data.error || 'Ошибка публикации') + '</div>';
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
})();
JS;

require_once 'footer.php';
?>
