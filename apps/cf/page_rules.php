<?php
$pageTitle = 'Page Rules';
require_once 'header.php';

$userId = $_SESSION['user_id'];

// Get domains for dropdown
$stmt = $pdo->prepare("
    SELECT ca.id, ca.domain, ca.zone_id, g.name as group_name 
    FROM cloudflare_accounts ca 
    LEFT JOIN groups g ON ca.group_id = g.id 
    WHERE ca.user_id = ? 
    ORDER BY ca.domain
");
$stmt->execute([$userId]);
$domains = $stmt->fetchAll();

include 'sidebar.php';
?>

<div class="content">
    <div class="content-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="fas fa-scroll me-2"></i>Page Rules</h1>
                <p class="text-muted mb-0">Быстрое применение типовых правил страниц</p>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Quick Rules -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bolt me-2"></i>Быстрые правила
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Выберите домен и нажмите на нужное правило для его применения.
                    </div>
                    
                    <div class="mb-4">
                        <label for="domainSelect" class="form-label">Выберите домен</label>
                        <input type="text" id="domainSelect" class="form-control" list="prDomainList"
                               placeholder="Начните вводить домен…" autocomplete="off" spellcheck="false">
                        <datalist id="prDomainList">
                            <?php foreach ($domains as $domain): ?>
                                <option value="<?php echo htmlspecialchars($domain['domain']); ?>"><?php echo htmlspecialchars($domain['group_name'] ? $domain['group_name'] : ''); ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <script>window.__prDomains = <?php echo json_encode(array_column($domains, 'id', 'domain'), JSON_UNESCAPED_UNICODE); ?>;</script>
                    </div>
                    
                    <div class="d-grid gap-3">
                        <button class="btn btn-warning btn-lg" onclick="applyRule('cache_everything')">
                            <i class="fas fa-box me-2"></i>Cache Everything
                            <small class="d-block mt-1 opacity-75">Кешировать все содержимое</small>
                        </button>
                        
                        <button class="btn btn-primary btn-lg" onclick="applyRule('redirect_https')">
                            <i class="fas fa-lock me-2"></i>Always Use HTTPS
                            <small class="d-block mt-1 opacity-75">Перенаправление на HTTPS</small>
                        </button>
                        
                        <button class="btn btn-info btn-lg" onclick="applyRule('cache_static')">
                            <i class="fas fa-images me-2"></i>Cache Static Files
                            <small class="d-block mt-1 opacity-75">Кеш для статических файлов</small>
                        </button>
                        
                        <button class="btn btn-success btn-lg" onclick="applyRule('browser_cache')">
                            <i class="fas fa-clock me-2"></i>Browser Cache TTL
                            <small class="d-block mt-1 opacity-75">Установить время кеша браузера</small>
                        </button>
                    </div>

                </div>
            </div>
        </div>
        
        <!-- Rule Templates Info -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle me-2"></i>Описание правил
                </div>
                <div class="card-body">
                    <div class="mb-4">
                        <h6 class="fw-bold text-warning">
                            <i class="fas fa-box me-2"></i>Cache Everything
                        </h6>
                        <p class="text-muted small mb-0">
                            Кеширует все типы контента на edge-серверах Cloudflare, включая HTML.
                            Идеально для статических сайтов. Применяется к паттерну: <code>*domain.com/*</code>
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary">
                            <i class="fas fa-lock me-2"></i>Always Use HTTPS
                        </h6>
                        <p class="text-muted small mb-0">
                            Автоматически перенаправляет все HTTP запросы на HTTPS.
                            Повышает безопасность сайта. Применяется к: <code>http://*domain.com/*</code>
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="fw-bold text-info">
                            <i class="fas fa-images me-2"></i>Cache Static Files
                        </h6>
                        <p class="text-muted small mb-0">
                            Кеширует только статические файлы (изображения, CSS, JS).
                            Ускоряет загрузку без кеширования динамического контента.
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="fw-bold text-success">
                            <i class="fas fa-clock me-2"></i>Browser Cache TTL
                        </h6>
                        <p class="text-muted small mb-0">
                            Устанавливает время жизни кеша в браузере посетителя.
                            По умолчанию устанавливается на 1 месяц.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-exclamation-triangle me-2 text-warning"></i>Важно
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-0">
                        <ul class="mb-0 ps-3">
                            <li>Бесплатный план Cloudflare позволяет 3 Page Rules на домен</li>
                            <li>Правила применяются по порядку (первое совпадение)</li>
                            <li>Изменения могут вступить в силу через несколько минут</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 301 Редирект — отдельная секция -->
    <div class="card mb-3">
        <div class="card-header">
            <i class="fas fa-arrow-right-arrow-left me-2 text-primary"></i>301 Редирект
        </div>
        <div class="card-body">
            <p class="text-muted small">Использует выбранный сверху домен. Тип определяет, как трактуется поле «Куда».</p>

            <!-- Переключатель режима -->
            <div class="btn-group w-100 mb-3" role="group">
                <input type="radio" class="btn-check" name="redirMode" id="redirModeRel" value="relative" checked onchange="updateRedirMode()">
                <label class="btn btn-outline-primary" for="redirModeRel"><i class="fas fa-arrows-left-right-to-line me-1"></i>Внутри этого домена (путь → путь)</label>
                <input type="radio" class="btn-check" name="redirMode" id="redirModeAbs" value="absolute" onchange="updateRedirMode()">
                <label class="btn btn-outline-primary" for="redirModeAbs"><i class="fas fa-up-right-from-square me-1"></i>На другой адрес (полный URL)</label>
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Откуда — только путь <span class="text-muted">(пусто = весь сайт)</span></label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text redir-host-prefix">https://домен</span>
                        <input type="text" id="redir301Source" class="form-control" placeholder="пусто = весь сайт, либо /page">
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1" id="redirTargetLabel">Куда (путь на этом же домене)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text redir-host-prefix" id="redirTargetPrefix">https://домен</span>
                        <input type="text" id="redir301Target" class="form-control" placeholder="/en-au2/">
                    </div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm w-100" onclick="applyRedirect301()">
                        <i class="fas fa-check me-1"></i>Применить
                    </button>
                </div>
            </div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="redirPreserveQuery">
                <label class="form-check-label small" for="redirPreserveQuery">Сохранять query-строку (<code>?utm=…</code> и т.п.)</label>
            </div>

            <div class="alert alert-secondary small mt-3 mb-0">
                <i class="fas fa-info-circle me-1"></i><strong>Как это работает:</strong> современное <strong>Single Redirect Rule</strong> (Rulesets, фаза <code>http_request_dynamic_redirect</code>), на edge Cloudflare — сервер не трогается.
                <ul class="mb-0 mt-1 ps-3">
                    <li><strong>Внутри домена:</strong> пишешь только пути, напр. <code>/en-au/</code> → <code>/en-au2/</code> — домен подставляется сам.</li>
                    <li><strong>На другой адрес:</strong> «Куда» — полный URL, можно на чужой сайт.</li>
                    <li><strong>Весь сайт → на внутреннюю страницу другого домена:</strong> режим <em>«На другой адрес»</em>, «Откуда» оставь <u>пустым</u>, «Куда» = <code>https://other-domain.com/mobile-app/</code>.</li>
                    <li>«Откуда» — <strong>только путь</strong> (<code>/page</code>). Если вставить полный URL — панель сама возьмёт из него путь. <strong>404/410</strong> — вкладка Cloudflare Workers.</li>
                </ul>
                <span class="text-danger">Требует право токена «Single Redirect» / «Dynamic URL Redirects» (Edit).</span>
            </div>
        </div>
    </div>

    <!-- Operation Log -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-terminal me-2"></i>Результаты операций</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="clearLog()">
                <i class="fas fa-eraser me-1"></i>Очистить
            </button>
        </div>
        <div class="card-body p-0">
            <div id="operationLog" class="bg-dark text-light p-3" style="min-height: 100px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.85rem;">
                <div class="text-muted">Лог операций будет отображаться здесь...</div>
            </div>
        </div>
    </div>
</div>

<script>
function logMessage(message, type = 'info') {
    const log = document.getElementById('operationLog');
    const time = new Date().toLocaleTimeString();
    const colors = {
        info: '#60a5fa',
        success: '#34d399',
        error: '#f87171',
        warning: '#fbbf24'
    };
    log.innerHTML += `<div style="color: ${colors[type]}">[${time}] ${message}</div>`;
    log.scrollTop = log.scrollHeight;
}

function clearLog() {
    document.getElementById('operationLog').innerHTML = '<div class="text-muted">Лог очищен...</div>';
}

// Домен теперь текстовое поле с поиском (input+datalist). Возвращает {name, id}.
function prGetDomain() {
    const inp = document.getElementById('domainSelect');
    const name = (inp?.value || '').trim();
    if (!name) return null;
    const id = (window.__prDomains || {})[name] || null;
    return { name, id };
}
// Текущий домен в префиксах input-group + переключение режима
function currentRedirHost() {
    const dom = prGetDomain();
    return dom && dom.name ? dom.name : 'домен';
}
function updateRedirMode() {
    const mode = document.querySelector('input[name="redirMode"]:checked').value;
    const host = currentRedirHost();
    // «Откуда» всегда путь на текущем домене
    document.querySelectorAll('.redir-host-prefix').forEach(el => el.textContent = 'https://' + host);
    const targetPrefix = document.getElementById('redirTargetPrefix');
    const targetInput  = document.getElementById('redir301Target');
    const targetLabel  = document.getElementById('redirTargetLabel');
    if (mode === 'relative') {
        targetPrefix.style.display = '';
        targetLabel.textContent = 'Куда (путь на этом же домене)';
        targetInput.placeholder = '/en-au2/';
    } else {
        targetPrefix.style.display = 'none';
        targetLabel.textContent = 'Куда (полный URL, можно другой сайт)';
        targetInput.placeholder = 'https://newsite.com/page';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    updateRedirMode();
    const ds = document.getElementById('domainSelect');
    if (ds) { ds.addEventListener('change', updateRedirMode); ds.addEventListener('input', updateRedirMode); }
    // «Откуда» — только путь: если вставили полный URL/host, оставляем лишь путь.
    const src = document.getElementById('redir301Source');
    if (src) src.addEventListener('blur', function () {
        let v = this.value.trim();
        if (!v) return;
        if (/^https?:\/\//i.test(v)) { try { v = new URL(v).pathname; } catch (e) {} }
        const host = currentRedirHost();
        if (host && host !== 'домен' && v.toLowerCase().indexOf(host.toLowerCase()) === 0) v = v.slice(host.length);
        if (v === '' || v === '/') { this.value = ''; return; }
        this.value = '/' + v.replace(/^\/+/, '');
    });
});

async function applyRedirect301() {
    const dom = prGetDomain();
    if (!dom || !dom.id) { showToast('Выберите домен из списка', 'warning'); return; }
    const domainId = dom.id;
    const mode = document.querySelector('input[name="redirMode"]:checked').value;
    const source = document.getElementById('redir301Source').value.trim();
    const target = document.getElementById('redir301Target').value.trim();
    const preserveQuery = document.getElementById('redirPreserveQuery').checked ? '1' : '0';
    if (!target) { showToast('Укажите «Куда»', 'warning'); return; }
    if (mode === 'absolute' && !/^https?:\/\//i.test(target)) {
        showToast('Для режима «на другой адрес» укажите полный URL (https://…)', 'warning'); return;
    }
    const domainName = dom.name;
    const shownTarget = (mode === 'relative') ? ('https://' + domainName + '/' + target.replace(/^\/+/, '')) : target;
    logMessage(`Применяем 301 (${mode === 'relative' ? 'внутри домена' : 'на другой адрес'}) для ${domainName}: ${source || 'весь сайт'} → ${shownTarget} ...`, 'info');
    try {
        const form = new URLSearchParams({ domain_id: domainId, rule_type: 'redirect_301', mode, source, target, preserve_query: preserveQuery });
        const response = await fetch('page_rules_api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
        const data = await response.json();
        if (data.success) {
            logMessage(`✓ 301 редирект применён`, 'success');
            showToast('301 редирект применён', 'success');
        } else {
            logMessage(`✗ Ошибка: ${data.error || 'unknown'}`, 'error');
            showToast('Ошибка: ' + (data.error || 'unknown'), 'error');
        }
    } catch (err) { logMessage(`✗ Ошибка сети: ${err.message}`, 'error'); showToast('Ошибка сети', 'error'); }
}

async function applyRule(ruleType) {
    const dom = prGetDomain();
    if (!dom || !dom.id) {
        showToast('Выберите домен из списка', 'warning');
        return;
    }
    const domainId = dom.id;
    const domainName = dom.name;
    const ruleNames = {
        'cache_everything': 'Cache Everything',
        'redirect_https': 'Always Use HTTPS',
        'cache_static': 'Cache Static Files',
        'browser_cache': 'Browser Cache TTL'
    };
    
    logMessage(`Применяем правило "${ruleNames[ruleType]}" для ${domainName}...`, 'info');
    
    try {
        const form = new URLSearchParams();
        form.append('domain_id', domainId);
        form.append('rule_type', ruleType);
        
        const response = await fetch('page_rules_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form.toString()
        });
        
        const data = await response.json();
        
        if (data.success) {
            logMessage(`✓ Правило "${ruleNames[ruleType]}" успешно применено`, 'success');
            showToast('Правило успешно применено', 'success');
        } else {
            logMessage(`✗ Ошибка: ${data.error || 'Неизвестная ошибка'}`, 'error');
            showToast('Ошибка: ' + (data.error || 'unknown'), 'error');
        }
    } catch (err) {
        logMessage(`✗ Ошибка сети: ${err.message}`, 'error');
        showToast('Ошибка сети', 'error');
    }
}
</script>

<?php include 'footer.php'; ?>