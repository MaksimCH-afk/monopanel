<?php
$pageTitle = 'Мастер-токен';
require_once 'header.php';
include 'sidebar.php';
?>
<div class="content">
    <div class="content-header">
        <h1><i class="fas fa-key me-2"></i>Мастер-токен — генератор API-токенов</h1>
        <p class="text-muted mb-0">Создаёт «дочерние» токены Cloudflare с нужным набором прав. Не нужно кликать пачку токенов вручную.</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><i class="fas fa-wand-magic-sparkles me-2"></i>Создать токен</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Мастер-токен <span class="text-muted small">(с правом «Create Additional Tokens»)</span></label>
                        <div class="input-group">
                            <select id="masterSelect" class="form-select" onchange="onMasterChange()">
                                <option value="__new__">➕ Добавить новый мастер-токен…</option>
                            </select>
                            <button class="btn btn-outline-danger" id="delMasterBtn" type="button" onclick="deleteMaster()" style="display:none;" title="Удалить сохранённый мастер-токен"><i class="fas fa-trash"></i></button>
                        </div>
                        <div id="masterHint" class="form-text"></div>
                        <div id="addMasterBlock" class="border rounded p-2 mt-2 bg-light">
                            <input type="password" id="masterToken" class="form-control form-control-sm mb-2" placeholder="cf… — вставьте мастер-токен" autocomplete="off">
                            <input type="text" id="masterLabel" class="form-control form-control-sm mb-2" placeholder="Метка (напр. имя аккаунта / основной домен)">
                            <button class="btn btn-outline-primary btn-sm" type="button" onclick="saveMaster()"><i class="fas fa-save me-1"></i>Сохранить мастер-токен</button>
                            <div class="form-text mb-0">Хранится в БД панели (gitignored, права 0600). Список доменов подтянется сам после создания первого токена.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Имя нового токена</label>
                        <input type="text" id="tokenName" class="form-control" placeholder="panel-token-… (если пусто — добавится дата)">
                    </div>

                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span>Права нового токена</span>
                        <span>
                            <button type="button" class="btn btn-link btn-sm p-0 me-2" onclick="toggleAllPerms(true)">все</button>
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="toggleAllPerms(false)">ничего</button>
                        </span>
                    </label>
                    <div id="permsList" class="border rounded p-2 mb-3" style="max-height: 320px; overflow-y:auto;">
                        <div class="text-muted small">Загрузка прав…</div>
                    </div>

                    <button class="btn btn-primary w-100" id="createBtn" onclick="createToken()">
                        <i class="fas fa-key me-2"></i>Создать токен
                    </button>
                    <div class="input-group input-group-sm mt-2">
                        <input type="text" id="debugQuery" class="form-control" placeholder="debug: поиск групп прав (напр. account settings)">
                        <button class="btn btn-outline-secondary" type="button" onclick="debugGroups()"><i class="fas fa-magnifying-glass"></i></button>
                    </div>
                    <div id="debugGroupsOut" class="small mt-2"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3" id="resultCard" style="display:none;">
                <div class="card-header text-success"><i class="fas fa-circle-check me-2"></i>Токен создан</div>
                <div class="card-body">
                    <div class="alert alert-warning small"><i class="fas fa-triangle-exclamation me-1"></i>Скопируйте токен сейчас — Cloudflare показывает значение только один раз.</div>
                    <label class="form-label small mb-1">Значение токена</label>
                    <div class="input-group mb-2">
                        <input type="text" id="newToken" class="form-control font-monospace" readonly>
                        <button class="btn btn-outline-secondary" onclick="copyToken()"><i class="fas fa-copy"></i></button>
                    </div>
                    <div id="missingWarn" class="small text-warning"></div>
                    <div id="savedAsInfo" class="small text-success mb-1"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="saveAsAccount()"><i class="fas fa-plus me-1"></i>Добавить в панель как аккаунт</button>
                    <div id="saveAccOut" class="small mt-1"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="fas fa-circle-info me-2"></i>Как это работает</div>
                <div class="card-body small text-muted">
                    <ol class="ps-3 mb-2">
                        <li>В Cloudflare создайте токен по шаблону <strong>«Create Additional Tokens»</strong> (право API Tokens → Edit) — это и есть мастер-токен.</li>
                        <li>Вставьте его сюда, отметьте нужные права, нажмите «Создать токен».</li>
                        <li>Панель сама подтянет ID групп прав и создаст токен на <strong>все зоны и аккаунты</strong>.</li>
                    </ol>
                    Zone-права применяются ко всем зонам, account-права (Workers Scripts, Account Analytics) — ко всем аккаунтам.
                </div>
            </div>
        </div>
    </div>

    <!-- Добавить домены в аккаунт (через выбранный сверху мастер-токен) -->
    <div class="card mt-3">
        <div class="card-header"><i class="fas fa-globe me-2"></i>Добавить домены в аккаунт</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Использует выбранный сверху <b>сохранённый</b> мастер-токен. Панель создаёт зоны в его аккаунте (<code>POST /zones</code>) и показывает NS-серверы, которые надо прописать у регистратора. Нужны 15 прав (Zone Create + Account Settings Read).</p>
            <textarea id="domainsInput" class="form-control mb-2" rows="4" placeholder="по одному домену в строке:&#10;example.com&#10;site2.net"></textarea>
            <button class="btn btn-primary" onclick="addDomains()"><i class="fas fa-plus me-1"></i>Создать домены</button>
            <button class="btn btn-outline-secondary ms-1" onclick="importEmpty()"><i class="fas fa-download me-1"></i>Импортировать/обновить домены (все аккаунты)</button>
            <button class="btn btn-outline-warning ms-1" onclick="dedupAccounts()"><i class="fas fa-object-group me-1"></i>Убрать дубли аккаунтов</button>
            <button class="btn btn-outline-info ms-1" onclick="relinkDomains()" title="Переклеить каждый домен на аккаунт, чей токен реально владеет зоной в Cloudflare"><i class="fas fa-link me-1"></i>Проверить и переклеить домены</button>
            <div id="addDomainsOut" class="mt-2"></div>
        </div>
    </div>

    <!-- Управление существующими токенами -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-2"></i>Существующие токены</span>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadTokens()"><i class="fas fa-rotate me-1"></i>Загрузить список</button>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Список токенов мастер-аккаунта. Можно удалить лишние/неполные и создать правильный сверху. Удаление необратимо.</p>
            <div id="tokensList"><div class="text-muted small">Введите мастер-токен и нажмите «Загрузить список».</div></div>
        </div>
    </div>

    <!-- [monopanel] Здоровье мастер-токенов -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-heart-pulse me-2"></i>Здоровье мастер-токенов</span>
            <button class="btn btn-outline-primary btn-sm" onclick="checkMastersHealth()"><i class="fas fa-stethoscope me-1"></i>Проверить все</button>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Сохранённые мастер-токены с метками, аккаунтом и живым статусом (Cloudflare verify). <span class="text-danger">🔴 недействителен/истёк</span> — таким токеном генерировать нельзя.</p>
            <div id="mastersHealth"><div class="text-muted small">Нажмите «Проверить все» — панель опросит каждый токен в Cloudflare.</div></div>
        </div>
    </div>
</div>

<!-- jQuery нужен для AJAX (как в Security Manager); footer.php грузит только Bootstrap -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
let PRESET = [];
let MASTERS = [];
function loadMasters() {
    $.get('master_token_api.php', { action: 'list_masters' }, function(r) {
        MASTERS = (r && r.masters) ? r.masters : [];
        const sel = $('#masterSelect');
        const cur = sel.val();
        let html = '';
        MASTERS.forEach(function(m){
            const lbl = m.label + (m.email ? ' (' + m.email + ')' : '');
            html += `<option value="${m.id}">${$('<div>').text(lbl).html()}</option>`;
        });
        html += '<option value="__new__">➕ Добавить новый мастер-токен…</option>';
        sel.html(html);
        if (MASTERS.length && (!cur || cur === '__new__')) sel.val(String(MASTERS[0].id));
        onMasterChange();
    }, 'json');
}
function onMasterChange() {
    const v = $('#masterSelect').val();
    if (v === '__new__') {
        $('#addMasterBlock').show(); $('#delMasterBtn').hide(); $('#masterHint').text('');
    } else {
        $('#addMasterBlock').hide(); $('#delMasterBtn').show();
        const m = MASTERS.find(x => String(x.id) === String(v));
        $('#masterHint').html(m ? ((m.domains_hint || 'домены подтянутся после создания токена') + ' · <code>' + m.masked + '</code>') : '');
    }
}
function masterParam() {
    const v = $('#masterSelect').val();
    if (v && v !== '__new__') return { master_id: v };
    const t = $('#masterToken').val().trim();
    return t ? { master_token: t } : null;
}
function saveMaster() {
    const t = $('#masterToken').val().trim();
    if (!t) { showToast('Вставьте мастер-токен', 'warning'); return; }
    $.post('master_token_api.php', { action: 'add_master', master_token: t, label: $('#masterLabel').val().trim() }, function(r) {
        if (r.success) { showToast('Мастер-токен сохранён', 'success'); $('#masterToken').val(''); $('#masterLabel').val(''); loadMasters(); }
        else showToast('Ошибка: ' + (r.error || ''), 'error');
    }, 'json').fail(function(){ showToast('Ошибка соединения', 'error'); });
}
function deleteMaster() {
    const v = $('#masterSelect').val();
    if (!v || v === '__new__') return;
    if (!confirm('Удалить сохранённый мастер-токен из панели? (в Cloudflare он не трогается)')) return;
    $.post('master_token_api.php', { action: 'delete_master', id: v }, function(r) {
        if (r.success) { showToast('Удалён', 'success'); loadMasters(); }
        else showToast('Ошибка', 'error');
    }, 'json');
}
function loadPerms() {
    $.get('master_token_api.php', { action: 'list_permissions' }, function(r) {
        if (!r.success) { $('#permsList').html('<div class="text-danger small">Ошибка загрузки</div>'); return; }
        PRESET = r.preset;
        let html = '';
        r.preset.forEach(function(p) {
            const lvl = p.level === 'account' ? '<span class="badge bg-secondary ms-1">account</span>' : '<span class="badge bg-light text-dark ms-1">zone</span>';
            html += `<div class="form-check">
                <input class="form-check-input perm-cb" type="checkbox" value="${p.key}" id="perm_${p.key}" checked>
                <label class="form-check-label" for="perm_${p.key}">${p.label} ${lvl}</label>
            </div>`;
        });
        $('#permsList').html(html);
    }, 'json');
}
function toggleAllPerms(on) { $('.perm-cb').prop('checked', on); }
function debugGroups() {
    const mp = masterParam();
    if (!mp) { showToast('Выберите или вставьте мастер-токен', 'warning'); return; }
    $('#debugGroupsOut').html('<i class="fas fa-spinner fa-spin"></i> Загрузка…');
    $.post('master_token_api.php', Object.assign({ action: 'list_groups', q: $('#debugQuery').val().trim() }, mp), function(r) {
        if (!r.success) { $('#debugGroupsOut').html('<span class="text-danger">' + (r.error || 'ошибка') + '</span>'); return; }
        if (!r.matched.length) { $('#debugGroupsOut').html('<span class="text-muted">Групп с redirect/transform/url не найдено (всего групп: ' + r.total + ')</span>'); return; }
        let html = '<div class="border rounded p-2 bg-light"><b>Найдены группы (всего ' + r.total + '):</b><ul class="mb-0 ps-3">';
        r.matched.forEach(function(g){ html += '<li><code>' + $('<div>').text(g.name).html() + '</code> <span class="text-muted">[' + (g.scopes||[]).join(', ') + ']</span></li>'; });
        html += '</ul></div>';
        $('#debugGroupsOut').html(html);
    }, 'json').fail(function(){ $('#debugGroupsOut').html('<span class="text-danger">ошибка соединения</span>'); });
}
function copyToken() {
    const el = document.getElementById('newToken');
    el.select(); document.execCommand('copy');
    showToast('Токен скопирован', 'success');
}
function saveAsAccount() {
    const tok = $('#newToken').val().trim();
    if (!tok) { showToast('Нет токена', 'warning'); return; }
    $.post('master_token_api.php', { action: 'save_as_account', token: tok }, function(r) {
        if (r.success) {
            if (r.already) {
                let msg = '<span class="text-muted">Уже в панели</span>';
                if (r.import_error) msg += ' <span class="text-danger">(импорт доменов: ' + $('<div>').text(r.import_error).html() + ')</span>';
                else msg += ' <span class="text-success">— досинхронизировано доменов: ' + (r.imported || 0) + '</span>';
                $('#saveAccOut').html(msg);
            } else {
                $('#saveAccOut').html('<span class="text-success">Добавлен: <b>' + $('<div>').text(r.label || '').html() + '</b> (доменов: ' + (r.imported || 0) + ')</span>');
            }
            showToast('Аккаунт в панели', 'success');
        } else showToast('Ошибка: ' + (r.error || ''), 'error');
    }, 'json').fail(function(){ showToast('Ошибка соединения', 'error'); });
}
function createToken() {
    const mp = masterParam();
    if (!mp) { showToast('Выберите или вставьте мастер-токен', 'warning'); return; }
    const perms = $('.perm-cb:checked').map(function(){ return this.value; }).get();
    if (!perms.length) { showToast('Выберите хотя бы одно право', 'warning'); return; }
    const $btn = $('#createBtn');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Создаём…');
    $.ajax({
        url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 40000,
        data: Object.assign({ action: 'create', name: $('#tokenName').val().trim(), perms: perms }, mp)
    }).done(function(r) {
        if (r.success) {
            $('#newToken').val(r.token || '');
            $('#missingWarn').html(r.missing && r.missing.length ? ('Не найдены группы: ' + r.missing.join(', ')) : '');
            $('#savedAsInfo').html(r.saved_as ? ('✓ Уже добавлен в панель как аккаунт: <b>' + $('<div>').text(r.saved_as).html() + '</b>') : '');
            $('#saveAccOut').html('');
            $('#resultCard').show();
            loadMasters();
            showToast('Токен создан', 'success');
        } else {
            showToast('Ошибка: ' + (r.error || 'unknown'), 'error');
        }
    }).fail(function(x, st){
        showToast(st === 'timeout' ? 'Таймаут запроса к Cloudflare' : 'Ошибка соединения', 'error');
    }).always(function(){
        $btn.prop('disabled', false).html('<i class="fas fa-key me-2"></i>Создать токен');
    });
}
function importEmpty() {
    $('#addDomainsOut').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Импортирую домены…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 120000, data: { action: 'import_empty' } })
    .done(function(r) {
        if (!r.success) { $('#addDomainsOut').html('<span class="text-danger small">' + (r.error || 'ошибка') + '</span>'); return; }
        let html = '';
        if (r.renamed || r.uid_filled) html += '<div class="small text-info mb-1"><i class="fas fa-id-card me-1"></i>Идентичность: uid проставлено ' + (r.uid_filled || 0) + ', имён обновлено ' + (r.renamed || 0) + ' (заглушки «token-…» → реальное имя).</div>';
        if (r.relinked || r.orphan) html += '<div class="small text-info mb-1"><i class="fas fa-link me-1"></i>Привязка доменов: переклеено ' + (r.relinked || 0) + (r.orphan ? ', без владельца ' + r.orphan : '') + '.</div>';
        if (!r.report.length) { $('#addDomainsOut').html(html || '<span class="text-muted small">Токен-аккаунтов нет.</span>'); showToast('Готово', 'success'); return; }
        html += '<ul class="mb-0 ps-3 small">';
        r.report.forEach(function(x) {
            if (x.ok) html += '<li class="text-success">' + $('<div>').text(x.account).html() + ' — импортировано: ' + x.count + '</li>';
            else html += '<li class="text-danger">' + $('<div>').text(x.account).html() + ' — ' + $('<div>').text(x.error || 'ошибка').html() + '</li>';
        });
        html += '</ul>';
        $('#addDomainsOut').html(html);
        showToast('Импорт завершён', 'success');
    })
    .fail(function(x, st) { $('#addDomainsOut').html('<span class="text-danger small">' + (st === 'timeout' ? 'Таймаут' : 'Ошибка соединения') + '</span>'); });
}
function relinkDomains() {
    if (!confirm('Проверить все домены и переклеить каждый на аккаунт, чей токен реально владеет зоной в Cloudflare? Меняется только привязка в панели (account_id/zone_id), Cloudflare не затрагивается.')) return;
    $('#addDomainsOut').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Опрашиваю токены и сверяю зоны (это может занять время)…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 300000, data: { action: 'relink_domains' } })
    .done(function(r) {
        if (!r.success) { $('#addDomainsOut').html('<span class="text-danger small">' + (r.error || 'ошибка') + '</span>'); return; }
        let html = '<div class="alert alert-success small mb-2"><i class="fas fa-circle-check me-1"></i>Готово. Переклеено: <b>' + r.relinked + '</b>, уже верно: <b>' + r.ok + '</b>, без владельца: <b>' + r.orphan + '</b>'
                 + (r.dead_creds ? ', мёртвых токенов: <b>' + r.dead_creds + '</b>' : '') + '.</div>';
        if (r.orphan) html += '<div class="small text-warning mb-2"><i class="fas fa-triangle-exclamation me-1"></i>«Без владельца» — зону не отдал ни один токен панели. Добавьте токен нужного аккаунта в «Мастер-токен» и повторите.</div>';
        if (r.report && r.report.length) {
            html += '<div class="small mb-1">Переклеены:</div><ul class="mb-0 ps-3 small">';
            r.report.forEach(function(x) {
                html += '<li>' + $('<div>').text(x.domain).html() + ': <span class="text-muted">' + $('<div>').text(x.from).html() + '</span> → <b>' + $('<div>').text(x.to).html() + '</b></li>';
            });
            html += '</ul>';
        }
        $('#addDomainsOut').html(html);
        showToast('Переклейка завершена', 'success');
    })
    .fail(function(x, st) { $('#addDomainsOut').html('<span class="text-danger small">' + (st === 'timeout' ? 'Таймаут (много аккаунтов — попробуйте ещё раз)' : 'Ошибка соединения') + '</span>'); });
}
// Шаг 1 — превью: показать план (кого оставить, во что переименовать, кого удалить)
// без изменений в БД. Применение — отдельной кнопкой (applyDedup).
function dedupAccounts() {
    $('#addDomainsOut').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Проверяю аккаунты на дубли…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 120000, data: { action: 'dedup_preview' } })
    .done(function(r) {
        if (!r.success) { $('#addDomainsOut').html('<span class="text-danger small">' + (r.error || 'ошибка') + '</span>'); return; }
        if (!r.groups || !r.groups.length) {
            $('#addDomainsOut').html('<div class="alert alert-success small mb-0"><i class="fas fa-circle-check me-1"></i>Дублей не найдено (аккаунтов: ' + r.total + ').</div>');
            return;
        }
        let removedTotal = 0;
        let html = '<div class="alert alert-warning small mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Найдено групп-дублей: <b>' + r.merged_groups + '</b> (всего аккаунтов: ' + r.total + '). Проверьте план и подтвердите. Домены и scoped-токены удаляемых переедут к главному; Cloudflare не затрагивается.</div>';
        r.groups.forEach(function(g) {
            removedTotal += (g.removed || []).length;
            const keepName = g.renamed
                ? ('<span class="text-decoration-line-through text-muted">' + $('<div>').text(g.keep_email).html() + '</span> → <b>' + $('<div>').text(g.new_name).html() + '</b>')
                : ('<b>' + $('<div>').text(g.keep_email).html() + '</b>');
            html += '<div class="border rounded p-2 mb-2">';
            html += '<div class="small text-success"><i class="fas fa-star me-1"></i>Главный: ' + keepName + ' <span class="text-muted">· доменов: ' + g.keep_domains + '</span></div>';
            (g.removed || []).forEach(function(x) {
                html += '<div class="small text-danger"><i class="fas fa-trash me-1"></i>Удалить: ' + $('<div>').text(x.email).html() + ' <span class="text-muted">· доменов: ' + x.domains + ' → к главному</span></div>';
            });
            html += '</div>';
        });
        html += '<button class="btn btn-danger btn-sm" onclick="applyDedup(this)"><i class="fas fa-object-group me-1"></i>Применить — убрать ' + removedTotal + ' дубл.</button>'
              + ' <button class="btn btn-outline-secondary btn-sm" onclick="$(\'#addDomainsOut\').html(\'\')">Отмена</button>';
        $('#addDomainsOut').html(html);
    })
    .fail(function(x, st) { $('#addDomainsOut').html('<span class="text-danger small">' + (st === 'timeout' ? 'Таймаут' : 'Ошибка соединения') + '</span>'); });
}
// Шаг 2 — применение: сервер пересчитывает план под write-локом и выполняет слияние.
function applyDedup(btn) {
    if (!confirm('Убрать дубли аккаунтов? Домены и scoped-токены перецепятся на главный кредентал, лишние удалятся. Cloudflare не затрагивается. Действие необратимо.')) return;
    $(btn).prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Применяю…');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 120000, data: { action: 'dedup_accounts' } })
    .done(function(r) {
        if (!r.success) { $('#addDomainsOut').html('<span class="text-danger small">' + (r.error || 'ошибка') + '</span>'); return; }
        if (!r.deleted) { $('#addDomainsOut').html('<span class="text-muted small">Дублей не найдено (аккаунтов: ' + r.total + ').</span>'); return; }
        let html = '<div class="alert alert-success small mb-1"><i class="fas fa-circle-check me-1"></i>Готово. Объединено групп: <b>' + r.merged_groups + '</b>, удалено дублей: <b>' + r.deleted + '</b></div><ul class="mb-0 ps-3 small">';
        (r.report || []).forEach(function(x) {
            html += '<li>Оставлен <b>' + $('<div>').text(x.keep).html() + '</b>, убраны: ' + $('<div>').text((x.removed || []).join(', ')).html() + '</li>';
        });
        html += '</ul>';
        $('#addDomainsOut').html(html);
        showToast('Дубли объединены', 'success');
        if (typeof loadMasters === 'function') loadMasters();
    })
    .fail(function(x, st) { $('#addDomainsOut').html('<span class="text-danger small">' + (st === 'timeout' ? 'Таймаут' : 'Ошибка соединения') + '</span>'); });
}
function addDomains() {
    const v = $('#masterSelect').val();
    if (!v || v === '__new__') { showToast('Выберите СОХРАНЁННЫЙ мастер-токен сверху', 'warning'); return; }
    const domains = $('#domainsInput').val().trim();
    if (!domains) { showToast('Впишите домены', 'warning'); return; }
    $('#addDomainsOut').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Создаём зоны…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 90000,
        data: { action: 'create_zones', master_id: v, domains: domains } })
    .done(function(r) {
        if (!r.success) { $('#addDomainsOut').html('<span class="text-danger small">' + (r.error || 'ошибка') + '</span>'); return; }
        let html = '<div class="small mb-1">Аккаунт: <b>' + $('<div>').text(r.account).html() + '</b></div><ul class="mb-0 ps-3 small">';
        r.results.forEach(function(x) {
            if (x.ok) html += '<li class="text-success">' + $('<div>').text(x.domain).html() + ' — создан. NS: <code>' + (x.ns || []).join('</code>, <code>') + '</code></li>';
            else html += '<li class="text-danger">' + $('<div>').text(x.domain).html() + ' — ' + $('<div>').text(x.error).html() + '</li>';
        });
        html += '</ul><div class="alert alert-warning small mt-2 mb-0"><i class="fas fa-triangle-exclamation me-1"></i>Пропишите эти NS у регистратора каждого домена — иначе Cloudflare не активирует зону.</div>';
        $('#addDomainsOut').html(html);
        loadMasters();
    })
    .fail(function(x, st) { $('#addDomainsOut').html('<span class="text-danger small">' + (st === 'timeout' ? 'Таймаут (домены могли создаться — обновите)' : 'Ошибка соединения') + '</span>'); });
}
function loadTokens() {
    const mp = masterParam();
    if (!mp) { showToast('Выберите или вставьте мастер-токен сверху', 'warning'); return; }
    $('#tokensList').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Загрузка…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 30000,
        data: Object.assign({ action: 'list_tokens' }, mp) })
    .done(function(r) {
        if (!r.success) { $('#tokensList').html('<div class="text-danger small">' + (r.error || 'Ошибка') + '</div>'); return; }
        if (!r.tokens.length) { $('#tokensList').html('<div class="text-muted small">Токенов нет.</div>'); return; }
        let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Имя</th><th>Права</th><th>Статус</th><th></th></tr></thead><tbody>';
        r.tokens.forEach(function(t) {
            const permsTitle = (t.perms || []).join(', ').replace(/"/g, '&quot;');
            const badge = t.status === 'active' ? 'success' : 'secondary';
            html += `<tr>
                <td class="font-monospace small">${$('<div>').text(t.name).html()}</td>
                <td><span class="badge bg-light text-dark" title="${permsTitle}">${t.count} прав</span></td>
                <td><span class="badge bg-${badge}">${t.status}</span></td>
                <td class="text-end"><button class="btn btn-outline-danger btn-sm" onclick="deleteToken('${t.id}', this)"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        $('#tokensList').html(html);
    })
    .fail(function(x, st){ $('#tokensList').html('<div class="text-danger small">' + (st === 'timeout' ? 'Таймаут' : 'Ошибка соединения') + '</div>'); });
}
function deleteToken(id, btn) {
    if (!confirm('Удалить этот токен безвозвратно? Все интеграции на нём перестанут работать.')) return;
    const mp = masterParam();
    $(btn).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 20000,
        data: Object.assign({ action: 'delete_token', token_id: id }, mp) })
    .done(function(r) {
        if (r.success) { showToast('Токен удалён', 'success'); loadTokens(); }
        else { showToast('Ошибка: ' + (r.error || 'unknown'), 'error'); $(btn).prop('disabled', false).html('<i class="fas fa-trash"></i>'); }
    })
    .fail(function(){ showToast('Ошибка соединения', 'error'); $(btn).prop('disabled', false).html('<i class="fas fa-trash"></i>'); });
}
function checkMastersHealth() {
    $('#mastersHealth').html('<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Проверяю токены в Cloudflare…</div>');
    $.ajax({ url: 'master_token_api.php', method: 'POST', dataType: 'json', timeout: 120000, data: { action: 'masters_status' } })
    .done(function(r) {
        if (!r.success || !r.masters) { $('#mastersHealth').html('<div class="text-danger small">' + ((r && r.error) || 'Ошибка') + '</div>'); return; }
        if (!r.masters.length) { $('#mastersHealth').html('<div class="text-muted small">Сохранённых мастер-токенов нет.</div>'); return; }
        let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Метка</th><th>Аккаунт</th><th>Домены</th><th>Токен</th><th>Статус</th></tr></thead><tbody>';
        r.masters.forEach(function(m) {
            const ok = m.ok;
            const badge = ok ? 'success' : 'danger';
            const stTxt = ok ? 'активен' : (m.status === 'expired' ? 'истёк' : (m.status === 'disabled' ? 'отключён' : 'недействителен'));
            html += `<tr class="${ok ? '' : 'table-danger'}">
                <td>${$('<div>').text(m.label || '').html()}</td>
                <td class="small text-muted">${$('<div>').text(m.email || '—').html()}</td>
                <td class="small text-muted">${$('<div>').text(m.domains_hint || '—').html()}</td>
                <td class="font-monospace small">${$('<div>').text(m.masked || '').html()}</td>
                <td><span class="badge bg-${badge}">${stTxt}</span></td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        $('#mastersHealth').html(html);
    })
    .fail(function(x, st) { $('#mastersHealth').html('<div class="text-danger small">' + (st === 'timeout' ? 'Таймаут (много токенов — попробуйте ещё раз)' : 'Ошибка соединения') + '</div>'); });
}
$(document).ready(function(){ loadPerms(); loadMasters(); });
</script>
<?php include 'footer.php'; ?>
