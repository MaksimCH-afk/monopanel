<?php
$pageTitle = 'Telegram';
require_once 'header.php';
include 'sidebar.php';
?>
<div class="content">
    <div class="content-header">
        <h1><i class="fab fa-telegram me-2"></i>Telegram-оповещения</h1>
        <p class="text-muted mb-0">Бот шлёт уведомления напрямую через Telegram Bot API (без сторонних сервисов).</p>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-gear me-2"></i>Настройки</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Bot Token</label>
                        <input type="password" id="botToken" class="form-control" placeholder="123456:ABC-DEF… (оставьте пустым, чтобы не менять)" autocomplete="off">
                        <div class="form-text" id="tokenState"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Chat ID <span class="text-muted small">(ваш личный id, НЕ id бота)</span></label>
                        <div class="input-group">
                            <input type="text" id="chatId" class="form-control" placeholder="напр. 123456789">
                            <button class="btn btn-outline-secondary" type="button" onclick="detectChat()"><i class="fas fa-magnifying-glass me-1"></i>Определить</button>
                        </div>
                        <div id="chatDetect" class="small mt-1"></div>
                    </div>
                    <hr>
                    <label class="form-label fw-bold">Категории оповещений</label>
                    <div class="row">
                        <div class="col-6">
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_offline"><label class="form-check-label small" for="alert_offline">Домен offline/online</label></div>
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_expiry"><label class="form-check-label small" for="alert_expiry">Сроки WHOIS/SSL (дайджест)</label></div>
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_ns"><label class="form-check-label small" for="alert_ns">Смена NS</label></div>
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_ip"><label class="form-check-label small" for="alert_ip">Смена origin IP</label></div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_token"><label class="form-check-label small" for="alert_token">Токен перестал работать</label></div>
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_zone"><label class="form-check-label small" for="alert_zone">Зона не active</label></div>
                            <div class="form-check form-switch"><input class="form-check-input alert-cb" type="checkbox" id="alert_queue"><label class="form-check-label small" for="alert_queue">Очередь: сбои/готово</label></div>
                        </div>
                    </div>

                    <hr>
                    <label class="form-label fw-bold">Мониторинг</label>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-1">Перепроверять домен раз в (часов)</label>
                            <input type="number" id="intervalHours" class="form-control form-control-sm" min="0.25" step="0.25" value="12">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">Доменов за один проход</label>
                            <input type="number" id="batch" class="form-control form-control-sm" min="1" max="50" value="8">
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary" onclick="saveTg()"><i class="fas fa-save me-1"></i>Сохранить</button>
                        <button class="btn btn-outline-secondary" onclick="testTg()"><i class="fas fa-paper-plane me-1"></i>Отправить тест</button>
                        <button class="btn btn-outline-success" onclick="runMonitor()"><i class="fas fa-satellite-dish me-1"></i>Проверить сейчас</button>
                    </div>
                    <div class="alert alert-secondary small mt-3 mb-0">
                        Мониторинг работает в фоне сам (каждые ~2 мин обрабатывает батч). «Проверить сейчас» прогоняет один проход вручную. Алерты шлются только при <strong>изменениях</strong>; сроки — раз в сутки дайджестом.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-circle-info me-2"></i>Как получить</div>
                <div class="card-body small">
                    <p class="mb-2"><strong>Bot Token:</strong> напишите <code>@BotFather</code> → <code>/newbot</code> → получите токен вида <code>123456:ABC…</code>.</p>
                    <p class="mb-2"><strong>Chat ID:</strong> напишите своему боту любое сообщение, затем откройте<br><code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> — там в <code>chat.id</code> будет ваш ID. Для канала/группы добавьте бота админом, ID начинается с <code>-100…</code>.</p>
                    <p class="mb-0 text-muted">Токен хранится в БД панели (gitignored), сообщения шлёт сам сервер через <code>api.telegram.org</code>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const ALERT_KEYS = ['offline','expiry','ns','ip','token','zone','queue'];
function loadTg() {
    $.get('telegram_api.php', { action: 'get' }, function(r) {
        if (!r.success) return;
        $('#tokenState').text(r.has_token ? ('Токен сохранён: ' + r.bot_token_masked) : 'Токен не задан');
        $('#chatId').val(r.chat_id || '');
        ALERT_KEYS.forEach(function(k){ $('#alert_'+k).prop('checked', !!(r.alerts && r.alerts[k])); });
        $('#intervalHours').val(r.interval_hours || '12');
        $('#batch').val(r.batch || '8');
    }, 'json');
}
function saveTg() {
    const data = { action: 'save', bot_token: $('#botToken').val().trim(), chat_id: $('#chatId').val().trim(),
        interval_hours: $('#intervalHours').val(), batch: $('#batch').val() };
    ALERT_KEYS.forEach(function(k){ data['alert_'+k] = $('#alert_'+k).is(':checked') ? '1' : '0'; });
    $.post('telegram_api.php', data, function(r) {
        if (r.success) { showToast('Сохранено', 'success'); $('#botToken').val(''); loadTg(); }
        else showToast('Ошибка: ' + (r.error || ''), 'error');
    }, 'json');
}
function detectChat() {
    const bot = $('#botToken').val().trim(); // если пусто — сервер возьмёт сохранённый
    $('#chatDetect').html('<i class="fas fa-spinner fa-spin"></i> Ищу сообщения боту…');
    $.post('telegram_api.php', { action: 'get_updates', bot_token: bot }, function(r) {
        if (!r.success) { $('#chatDetect').html('<span class="text-danger">' + (r.error || 'ошибка') + '</span>'); return; }
        if (!r.chats.length) { $('#chatDetect').html('<span class="text-warning">' + (r.note || 'Напишите боту сообщение и нажмите снова.') + '</span>'); return; }
        let html = 'Нажмите, чтобы подставить:<div class="mt-1 d-flex flex-wrap gap-1">';
        r.chats.forEach(function(c){
            html += `<button type="button" class="btn btn-sm btn-outline-primary" onclick="$('#chatId').val('${c.id}')">${$('<div>').text(c.name).html()} <span class="text-muted">[${c.type}] ${c.id}</span></button>`;
        });
        html += '</div>';
        $('#chatDetect').html(html);
    }, 'json').fail(function(){ $('#chatDetect').html('<span class="text-danger">ошибка соединения</span>'); });
}
function runMonitor() {
    showToast('Запускаю проход мониторинга…', 'info');
    $.get('monitor.php', { auth_token: 'cloudflare_queue_processor_2024' }, function(r) {
        if (r && r.ok) showToast('Проверено доменов: ' + r.checked + ', алертов: ' + r.alerts, 'success');
        else showToast('Готово', 'success');
    }, 'json').fail(function(){ showToast('Ошибка запуска мониторинга', 'error'); });
}
function testTg() {
    $.post('telegram_api.php', { action: 'test', bot_token: $('#botToken').val().trim(), chat_id: $('#chatId').val().trim() }, function(r) {
        if (r.success) showToast('Тестовое сообщение отправлено', 'success');
        else showToast('Не отправлено: ' + (r.error || ''), 'error');
    }, 'json');
}
$(document).ready(loadTg);
</script>
<?php include 'footer.php'; ?>
