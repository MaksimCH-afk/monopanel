<!-- ===================================
     GROUP MODALS
     =================================== -->

<!-- Add Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Добавить группу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="handle_forms.php" id="addGroupForm">
                    <div class="mb-3">
                        <label class="form-label">Название группы</label>
                        <input type="text" name="group_name" class="form-control" placeholder="Введите название" required>
                    </div>
                    <button type="submit" name="add_group" class="btn btn-primary w-100">
                        <i class="fas fa-plus me-1"></i>Добавить
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Group Modal -->
<div class="modal fade" id="deleteGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder-minus me-2 text-danger"></i>Удалить группу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Все домены этой группы будут перемещены в "Без группы"
                </div>
                <form method="POST" action="handle_forms.php">
                    <div class="mb-3">
                        <label class="form-label">Выберите группу</label>
                        <select name="group_id" class="form-select" required>
                            <option value="">-- Выберите группу --</option>
                            <?php if (isset($groups)): ?>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" name="delete_group" class="btn btn-danger w-100">
                        <i class="fas fa-trash me-1"></i>Удалить группу
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     DOMAIN MODALS
     =================================== -->

<!-- Add Domain Modal -->
<div class="modal fade" id="addDomainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe me-2"></i>Добавить домен</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="handle_forms.php">
                    <?php
                    // Информативные подписи аккаунтов + поиск (при 1000 аккаунтов «token-XXXX» бесполезен).
                    $accountOptions = [];
                    if (isset($accounts)) {
                        $uid = $_SESSION['user_id'] ?? 1;
                        $domsByAcc = [];
                        $ds = $pdo->prepare("SELECT account_id, domain FROM cloudflare_accounts WHERE user_id = ? ORDER BY domain");
                        $ds->execute([$uid]);
                        foreach ($ds as $r) { $domsByAcc[$r['account_id']][] = $r['domain']; }
                        foreach ($accounts as $account) {
                            $doms = $domsByAcc[$account['id']] ?? [];
                            $cnt = count($doms);
                            $samples = implode(', ', array_slice($doms, 0, 3)) . ($cnt > 3 ? '…' : '');
                            $accountOptions[] = [
                                'id' => (int)$account['id'],
                                'label' => $account['email'] . ' · ' . $cnt . ' дом.' . ($samples ? ' (' . $samples . ')' : ''),
                                'search' => mb_strtolower($account['email'] . ' ' . implode(' ', $doms)),
                            ];
                        }
                    }
                    ?>
                    <div class="mb-3 position-relative">
                        <label class="form-label">Аккаунт Cloudflare</label>
                        <input type="text" id="accSearch" class="form-control" placeholder="Поиск: email / домен / токен…" autocomplete="off" oninput="acFilter(this.value)" onfocus="acFilter(this.value)">
                        <input type="hidden" name="account_id" id="accId">
                        <div id="accDrop" class="list-group position-absolute w-100 shadow" style="z-index:1080; max-height:260px; overflow-y:auto; display:none;"></div>
                    </div>
                    <script>
                    const CF_ACCOUNTS = <?php echo json_encode($accountOptions, JSON_UNESCAPED_UNICODE); ?>;
                    function acFilter(q) {
                        q = (q || '').toLowerCase().trim();
                        const list = (!q ? CF_ACCOUNTS : CF_ACCOUNTS.filter(a => a.search.indexOf(q) !== -1)).slice(0, 50);
                        const drop = document.getElementById('accDrop');
                        const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                        if (!list.length) { drop.innerHTML = '<div class="list-group-item small text-muted">Ничего не найдено</div>'; drop.style.display = 'block'; return; }
                        drop.innerHTML = list.map(a => `<button type="button" class="list-group-item list-group-item-action small py-1" onclick="acPick(${a.id}, this)">${esc(a.label)}</button>`).join('');
                        drop.style.display = 'block';
                    }
                    function acPick(id, btn) {
                        document.getElementById('accId').value = id;
                        document.getElementById('accSearch').value = btn.textContent.trim();
                        document.getElementById('accDrop').style.display = 'none';
                    }
                    document.addEventListener('click', function(e) {
                        if (!e.target.closest('#accSearch') && !e.target.closest('#accDrop')) {
                            const d = document.getElementById('accDrop'); if (d) d.style.display = 'none';
                        }
                    });
                    </script>
                    <div class="mb-3">
                        <label class="form-label">Группа</label>
                        <select name="group_id" class="form-select" required>
                            <option value="">-- Выберите группу --</option>
                            <?php if (isset($groups)): ?>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Домен</label>
                        <input type="text" name="domain" class="form-control" placeholder="example.com" required>
                    </div>
                    <?php
                    $serverList = [];
                    try { $serverList = $pdo->query("SELECT name, ip FROM servers ORDER BY name")->fetchAll(); } catch (Exception $e) {}
                    ?>
                    <div class="mb-3">
                        <label class="form-label">IP сервера</label>
                        <?php if ($serverList): ?>
                        <select class="form-select form-select-sm mb-1" onchange="if(this.value){this.closest('form').querySelector('[name=server_ip]').value=this.value;}">
                            <option value="">— выбрать сервер из списка —</option>
                            <?php foreach ($serverList as $s): ?>
                                <option value="<?php echo htmlspecialchars($s['ip']); ?>"><?php echo htmlspecialchars($s['name'] . ' — ' . $s['ip']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <input type="text" name="server_ip" class="form-control" placeholder="192.168.1.1 (или выберите сервер выше)" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="create_dns" class="form-check-input" id="createDnsSingle" value="1" checked>
                            <label class="form-check-label" for="createDnsSingle">Создать DNS-записи на выбранный IP <small class="text-muted">(A @, A *, CNAME www)</small></label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="enable_https" class="form-check-input" id="enableHttpsSingle">
                            <label class="form-check-label" for="enableHttpsSingle">Always Use HTTPS</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="enable_tls13" class="form-check-input" id="enableTlsSingle">
                            <label class="form-check-label" for="enableTlsSingle">TLS 1.3</label>
                        </div>
                    </div>
                    <button type="submit" name="add_domain" class="btn btn-primary w-100">
                        <i class="fas fa-plus me-1"></i>Добавить домен
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Add Domains Modal -->
<div class="modal fade" id="addDomainsBulkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>Массовое добавление доменов</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="handle_forms.php">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Аккаунт Cloudflare</label>
                            <select name="account_id" class="form-select" required>
                                <option value="">-- Выберите аккаунт --</option>
                                <?php if (isset($accounts)): ?>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>"><?php echo htmlspecialchars($account['email']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Группа</label>
                            <select name="group_id" class="form-select" required>
                                <option value="">-- Выберите группу --</option>
                                <?php if (isset($groups)): ?>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Список доменов</label>
                        <textarea name="domains_list" class="form-control" rows="6" placeholder="example.com;192.168.1.1&#10;example2.com;192.168.1.2" required></textarea>
                        <div class="form-text">Формат: домен;IP (каждая пара с новой строки)</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="enable_https" class="form-check-input" id="enableHttpsBulk">
                            <label class="form-check-label" for="enableHttpsBulk">Always Use HTTPS</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="enable_tls13" class="form-check-input" id="enableTlsBulk">
                            <label class="form-check-label" for="enableTlsBulk">TLS 1.3</label>
                        </div>
                    </div>
                    <button type="submit" name="add_domains_bulk" class="btn btn-primary w-100">
                        <i class="fas fa-upload me-1"></i>Добавить списком
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     ACCOUNT MODALS
     =================================== -->

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Добавить аккаунт</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="handle_forms.php">
                    <div class="mb-3">
                        <label class="form-label">Группа</label>
                        <select name="group_id" class="form-select" required>
                            <option value="">-- Выберите группу --</option>
                            <?php if (isset($groups)): ?>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Тип авторизации</label>
                        <select name="auth_type" class="form-select" id="addAccountAuthType" onchange="updateAddAccountForm()">
                            <option value="token" selected>API Token (рекомендуется)</option>
                            <option value="global">Global API Key (email + ключ)</option>
                        </select>
                        <small class="text-muted" id="addAccountAuthHint">Создайте токен в Cloudflare с правами на нужные зоны/аккаунт.</small>
                    </div>
                    <div class="mb-3" id="addAccountEmailWrap" style="display:none;">
                        <label class="form-label">Email <span class="text-muted">(для Global API Key)</span></label>
                        <input type="email" name="email" id="addAccountEmail" class="form-control" placeholder="user@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="addAccountKeyLabel">API Token</label>
                        <input type="text" name="api_key" id="addAccountKey" class="form-control" placeholder="Cloudflare API Token" required>
                    </div>
                    <button type="submit" name="add_account" class="btn btn-primary w-100">
                        <i class="fas fa-plus me-1"></i>Добавить аккаунт
                    </button>
                </form>
                <script>
                function updateAddAccountForm() {
                    var t = document.getElementById('addAccountAuthType').value;
                    var emailWrap = document.getElementById('addAccountEmailWrap');
                    var email = document.getElementById('addAccountEmail');
                    var keyLabel = document.getElementById('addAccountKeyLabel');
                    var key = document.getElementById('addAccountKey');
                    var hint = document.getElementById('addAccountAuthHint');
                    if (t === 'global') {
                        emailWrap.style.display = '';
                        email.required = true;
                        keyLabel.textContent = 'Global API Key';
                        key.placeholder = 'Cloudflare Global API Key (37 символов)';
                        hint.textContent = 'Введите email аккаунта и Global API Key из Cloudflare.';
                    } else {
                        emailWrap.style.display = 'none';
                        email.required = false;
                        email.value = '';
                        keyLabel.textContent = 'API Token';
                        key.placeholder = 'Cloudflare API Token';
                        hint.textContent = 'Создайте токен в Cloudflare с правами на нужные зоны/аккаунт.';
                    }
                }
                updateAddAccountForm();
                </script>
            </div>
        </div>
    </div>
</div>

<!-- Manage Accounts Modal -->
<div class="modal fade" id="manageAccountsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-cog me-2"></i>Управление аккаунтами</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php
                $accountsList = [];
                if (isset($pdo, $_SESSION['user_id'])) {
                    $accStmt = $pdo->prepare("
                        SELECT cc.id, cc.email, cc.auth_type,
                               (SELECT COUNT(*) FROM cloudflare_accounts ca WHERE ca.account_id = cc.id) AS domains_count
                        FROM cloudflare_credentials cc
                        WHERE cc.user_id = ?
                        ORDER BY cc.email ASC
                    ");
                    $accStmt->execute([$_SESSION['user_id']]);
                    $accountsList = $accStmt->fetchAll();
                }
                ?>
                <?php if (empty($accountsList)): ?>
                    <div class="alert alert-info mb-0">Аккаунтов пока нет.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Email / метка</th>
                                    <th>Тип авторизации</th>
                                    <th class="text-center">Доменов</th>
                                    <th class="text-end">Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($accountsList as $acc): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($acc['email']); ?></td>
                                        <td>
                                            <?php if (($acc['auth_type'] ?? 'global') === 'token'): ?>
                                                <span class="badge bg-info">API Token</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Global API Key</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo (int)$acc['domains_count']; ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="probeAccount(<?php echo (int)$acc['id']; ?>)">
                                                <i class="fas fa-key me-1"></i>Проверить права
                                            </button>
                                            <form method="POST" action="handle_forms.php" class="d-inline"
                                                  onsubmit="return confirm('Удалить аккаунт <?php echo htmlspecialchars(addslashes($acc['email'])); ?> и все его домены (<?php echo (int)$acc['domains_count']; ?>)? Это действие необратимо.');">
                                                <input type="hidden" name="account_id" value="<?php echo (int)$acc['id']; ?>">
                                                <button type="submit" name="delete_account" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash me-1"></i>Удалить
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr id="probeRow<?php echo (int)$acc['id']; ?>" style="display:none;"><td colspan="4" class="bg-light"><div id="probeResult<?php echo (int)$acc['id']; ?>"></div></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning mt-2 mb-0">
                        <small><i class="fas fa-exclamation-triangle me-1"></i>Удаление аккаунта удаляет из панели все его домены и связанные записи (очередь, правила). На самом Cloudflare ничего не удаляется.</small>
                    </div>
                    <script>
                    async function probeAccount(id) {
                        const row = document.getElementById('probeRow' + id);
                        const out = document.getElementById('probeResult' + id);
                        row.style.display = '';
                        out.innerHTML = '<span class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Проверка прав токена…</span>';
                        try {
                            const res = await fetch('tokens_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'probe', account_id:id}) });
                            const d = await res.json();
                            if (!d.success) { out.innerHTML = `<span class="text-danger small">${d.error||'Ошибка'}</span>`; return; }
                            const items = Object.keys(d.labels).map(k => {
                                const has = d.capabilities[k];
                                return `<span class="badge ${has?'bg-success':'bg-secondary'} me-1 mb-1">${has?'✓':'✗'} ${d.labels[k]}</span>`;
                            }).join('');
                            const missing = Object.keys(d.labels).filter(k => !d.capabilities[k]).map(k=>d.labels[k]);
                            out.innerHTML = `<div class="small"><div class="mb-1">Права токена (проверено на <code>${d.zone}</code>):</div>${items}` +
                                (missing.length ? `<div class="text-danger mt-1">Не хватает: ${missing.join(', ')}</div>` : `<div class="text-success mt-1">Все основные права на месте.</div>`) + `</div>`;
                        } catch(e) { out.innerHTML = `<span class="text-danger small">${e.message}</span>`; }
                    }
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Add Accounts Modal (Progressive Loading) -->
<div class="modal fade" id="addAccountsBulkModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Массовое добавление аккаунтов</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="bulkAccountsCloseBtn"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Input accounts -->
                <div id="bulkAccountsStep1">
                    <div class="mb-3">
                        <label class="form-label">Группа</label>
                        <select id="bulkAccountsGroupId" class="form-select" required>
                            <option value="">-- Выберите группу --</option>
                            <?php if (isset($groups)): ?>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Список аккаунтов</label>
                        <textarea id="bulkAccountsList" class="form-control" rows="8" placeholder="email1@example.com;api_key1&#10;email2@example.com;api_key2" required></textarea>
                    </div>
                    <div class="alert alert-info">
                        <small>
                            <strong>Формат:</strong> email;api_key (каждый аккаунт с новой строки)<br>
                            <strong>Рекомендация:</strong> для 100+ аккаунтов загрузка происходит последовательно с задержкой для стабильности
                        </small>
                    </div>
                    
                    <!-- Speed settings -->
                    <div class="mb-3">
                        <label class="form-label">Скорость загрузки</label>
                        <select id="bulkAccountsSpeed" class="form-select">
                            <option value="2000">Медленная (2 сек между аккаунтами) - безопасно для 1000+ аккаунтов</option>
                            <option value="1000" selected>Нормальная (1 сек между аккаунтами)</option>
                            <option value="500">Быстрая (0.5 сек) - только для <100 аккаунтов</option>
                            <option value="200">Очень быстрая (0.2 сек) - только для <50 аккаунтов</option>
                        </select>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="validateBulkAccounts()">
                            <i class="fas fa-check-circle me-1"></i>Проверить список
                        </button>
                        <button type="button" class="btn btn-primary flex-grow-1" onclick="startBulkAccountsImport()">
                            <i class="fas fa-upload me-1"></i>Начать загрузку
                        </button>
                    </div>
                </div>
                
                <!-- Step 2: Validation results -->
                <div id="bulkAccountsStep2" class="d-none">
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-clipboard-check me-2"></i>Результаты проверки</h6>
                        <div id="validationResults"></div>
                    </div>
                    <div id="validationErrors" class="mb-3"></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" onclick="backToStep1()">
                            <i class="fas fa-arrow-left me-1"></i>Назад
                        </button>
                        <button type="button" class="btn btn-primary flex-grow-1" onclick="startBulkAccountsImport(true)">
                            <i class="fas fa-upload me-1"></i>Продолжить загрузку
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: Progress -->
                <div id="bulkAccountsStep3" class="d-none">
                    <div class="text-center mb-3">
                        <h5><i class="fas fa-sync fa-spin me-2"></i>Загрузка аккаунтов...</h5>
                        <p class="text-muted">Не закрывайте это окно до завершения</p>
                    </div>
                    
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="bulkAccountsProgress" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    
                    <div class="row text-center mb-3">
                        <div class="col-3">
                            <div class="border rounded p-2 bg-light">
                                <div class="fs-4 fw-bold text-primary" id="bulkStatTotal">0</div>
                                <small class="text-muted">Всего</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-2 bg-light">
                                <div class="fs-4 fw-bold text-success" id="bulkStatSuccess">0</div>
                                <small class="text-muted">Добавлено</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-2 bg-light">
                                <div class="fs-4 fw-bold text-warning" id="bulkStatDuplicate">0</div>
                                <small class="text-muted">Дубликаты</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-2 bg-light">
                                <div class="fs-4 fw-bold text-danger" id="bulkStatError">0</div>
                                <small class="text-muted">Ошибки</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Текущий аккаунт:</label>
                        <div class="border rounded p-2 bg-light" id="currentAccountStatus">
                            <span class="text-muted">Подготовка...</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label d-flex justify-content-between">
                            <span>Лог операций:</span>
                            <span id="bulkDomainsTotal" class="badge bg-info">Доменов: 0</span>
                        </label>
                        <div id="bulkAccountsLog" class="border rounded p-2 bg-dark text-light font-monospace" style="height: 200px; overflow-y: auto; font-size: 12px;"></div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-danger" id="bulkAccountsStopBtn" onclick="stopBulkAccountsImport()">
                            <i class="fas fa-stop me-1"></i>Остановить
                        </button>
                        <button type="button" class="btn btn-secondary d-none" id="bulkAccountsBackBtn" onclick="backToStep1()">
                            <i class="fas fa-arrow-left me-1"></i>Назад
                        </button>
                    </div>
                </div>
                
                <!-- Step 4: Complete -->
                <div id="bulkAccountsStep4" class="d-none">
                    <div class="text-center mb-4">
                        <div class="display-1 text-success mb-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h4>Загрузка завершена!</h4>
                    </div>
                    
                    <div class="row text-center mb-4">
                        <div class="col-3">
                            <div class="border rounded p-3">
                                <div class="fs-3 fw-bold text-primary" id="finalStatTotal">0</div>
                                <small class="text-muted">Всего</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-3">
                                <div class="fs-3 fw-bold text-success" id="finalStatSuccess">0</div>
                                <small class="text-muted">Добавлено</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-3">
                                <div class="fs-3 fw-bold text-warning" id="finalStatDuplicate">0</div>
                                <small class="text-muted">Дубликаты</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded p-3">
                                <div class="fs-3 fw-bold text-danger" id="finalStatError">0</div>
                                <small class="text-muted">Ошибки</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Добавлено доменов: <strong id="finalDomainsTotal">0</strong>
                    </div>
                    
                    <div id="bulkAccountsErrorsList" class="mb-3"></div>
                    
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" onclick="resetBulkAccountsModal()">
                            <i class="fas fa-redo me-1"></i>Загрузить ещё
                        </button>
                        <button type="button" class="btn btn-primary flex-grow-1" onclick="location.reload()">
                            <i class="fas fa-sync me-1"></i>Обновить страницу
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Глобальные переменные для массовой загрузки аккаунтов
let bulkAccountsQueue = [];
let bulkAccountsRunning = false;
let bulkAccountsStats = { total: 0, success: 0, duplicate: 0, error: 0, domains: 0 };
let bulkAccountsErrors = [];
let bulkAccountsDelay = 1000;
let validatedAccounts = null;

// Валидация списка аккаунтов
async function validateBulkAccounts() {
    const accountsList = document.getElementById('bulkAccountsList').value.trim();
    
    if (!accountsList) {
        showNotification('Введите список аккаунтов', 'warning');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'validate_accounts');
        formData.append('accounts_list', accountsList);
        
        const response = await fetch('add_accounts_bulk_api.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            validatedAccounts = data.valid;
            
            document.getElementById('validationResults').innerHTML = `
                <div class="d-flex justify-content-around">
                    <span class="text-success"><i class="fas fa-check me-1"></i>Валидных: ${data.valid_count}</span>
                    <span class="text-danger"><i class="fas fa-times me-1"></i>С ошибками: ${data.invalid_count}</span>
                </div>
            `;
            
            let errorsHtml = '';
            if (data.invalid.length > 0) {
                errorsHtml = '<div class="alert alert-danger"><h6>Ошибки:</h6><ul class="mb-0">';
                data.invalid.forEach(err => {
                    errorsHtml += `<li>Строка ${err.line}: ${err.error}</li>`;
                });
                if (data.has_more_errors) {
                    errorsHtml += '<li>... и другие ошибки</li>';
                }
                errorsHtml += '</ul></div>';
            }
            document.getElementById('validationErrors').innerHTML = errorsHtml;
            
            // Показываем шаг 2
            document.getElementById('bulkAccountsStep1').classList.add('d-none');
            document.getElementById('bulkAccountsStep2').classList.remove('d-none');
        } else {
            showNotification('Ошибка валидации: ' + data.error, 'error');
        }
    } catch (error) {
        showNotification('Ошибка сети: ' + error.message, 'error');
    }
}

// Вернуться к шагу 1
function backToStep1() {
    document.getElementById('bulkAccountsStep1').classList.remove('d-none');
    document.getElementById('bulkAccountsStep2').classList.add('d-none');
    document.getElementById('bulkAccountsStep3').classList.add('d-none');
    document.getElementById('bulkAccountsStep4').classList.add('d-none');
    document.getElementById('bulkAccountsCloseBtn').disabled = false;
}

// Начать импорт аккаунтов
async function startBulkAccountsImport(useValidated = false) {
    const groupId = document.getElementById('bulkAccountsGroupId').value;
    
    if (!groupId) {
        showNotification('Выберите группу', 'warning');
        return;
    }
    
    let accounts;
    
    if (useValidated && validatedAccounts) {
        accounts = validatedAccounts;
    } else {
        // Парсим список аккаунтов
        const accountsList = document.getElementById('bulkAccountsList').value.trim();
        if (!accountsList) {
            showNotification('Введите список аккаунтов', 'warning');
            return;
        }
        
        accounts = [];
        const lines = accountsList.split('\n');
        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.indexOf(';') === -1) continue;
            
            const [email, apiKey] = trimmed.split(';', 2);
            if (email && apiKey) {
                accounts.push({ email: email.trim(), api_key: apiKey.trim() });
            }
        }
    }
    
    if (accounts.length === 0) {
        showNotification('Не найдено валидных аккаунтов для импорта', 'warning');
        return;
    }
    
    // Получаем задержку
    bulkAccountsDelay = parseInt(document.getElementById('bulkAccountsSpeed').value) || 1000;
    
    // Инициализация
    bulkAccountsQueue = accounts.map((acc, idx) => ({ ...acc, index: idx, groupId }));
    bulkAccountsStats = { total: accounts.length, success: 0, duplicate: 0, error: 0, domains: 0 };
    bulkAccountsErrors = [];
    bulkAccountsRunning = true;
    
    // Показываем шаг 3 (прогресс)
    document.getElementById('bulkAccountsStep1').classList.add('d-none');
    document.getElementById('bulkAccountsStep2').classList.add('d-none');
    document.getElementById('bulkAccountsStep3').classList.remove('d-none');
    document.getElementById('bulkAccountsCloseBtn').disabled = true;
    
    // Обновляем статистику
    updateBulkStats();
    
    // Очищаем лог
    document.getElementById('bulkAccountsLog').innerHTML = '';
    addBulkLog('info', `Начинаем загрузку ${accounts.length} аккаунтов (задержка: ${bulkAccountsDelay}ms)`);
    
    // Запускаем последовательную обработку
    await processBulkAccountsQueue();
}

// Обработка очереди аккаунтов
async function processBulkAccountsQueue() {
    while (bulkAccountsQueue.length > 0 && bulkAccountsRunning) {
        const account = bulkAccountsQueue.shift();
        
        // Обновляем текущий аккаунт
        document.getElementById('currentAccountStatus').innerHTML = `
            <i class="fas fa-spinner fa-spin me-2"></i>
            <strong>${account.email}</strong>
            <span class="text-muted">(${account.index + 1} из ${bulkAccountsStats.total})</span>
        `;
        
        try {
            const formData = new FormData();
            formData.append('action', 'add_single_account');
            formData.append('email', account.email);
            formData.append('api_key', account.api_key);
            formData.append('group_id', account.groupId);
            
            const response = await fetch('add_accounts_bulk_api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                if (data.status === 'duplicate') {
                    bulkAccountsStats.duplicate++;
                    addBulkLog('warning', `${account.email} - дубликат`);
                } else {
                    bulkAccountsStats.success++;
                    bulkAccountsStats.domains += data.domains_count || 0;
                    addBulkLog('success', `${account.email} - добавлен (${data.domains_count} доменов)`);
                }
            } else {
                bulkAccountsStats.error++;
                bulkAccountsErrors.push({ email: account.email, error: data.error });
                addBulkLog('error', `${account.email} - ошибка: ${data.error}`);
            }
        } catch (error) {
            bulkAccountsStats.error++;
            bulkAccountsErrors.push({ email: account.email, error: error.message });
            addBulkLog('error', `${account.email} - сетевая ошибка: ${error.message}`);
        }
        
        // Обновляем статистику
        updateBulkStats();
        
        // Задержка перед следующим аккаунтом
        if (bulkAccountsQueue.length > 0 && bulkAccountsRunning) {
            await new Promise(resolve => setTimeout(resolve, bulkAccountsDelay));
        }
    }
    
    // Завершение
    completeButlkAccountsImport();
}

// Обновление статистики
function updateBulkStats() {
    const processed = bulkAccountsStats.success + bulkAccountsStats.duplicate + bulkAccountsStats.error;
    const percent = bulkAccountsStats.total > 0 ? Math.round((processed / bulkAccountsStats.total) * 100) : 0;
    
    document.getElementById('bulkAccountsProgress').style.width = percent + '%';
    document.getElementById('bulkAccountsProgress').textContent = percent + '%';
    
    document.getElementById('bulkStatTotal').textContent = bulkAccountsStats.total;
    document.getElementById('bulkStatSuccess').textContent = bulkAccountsStats.success;
    document.getElementById('bulkStatDuplicate').textContent = bulkAccountsStats.duplicate;
    document.getElementById('bulkStatError').textContent = bulkAccountsStats.error;
    document.getElementById('bulkDomainsTotal').textContent = 'Доменов: ' + bulkAccountsStats.domains;
}

// Добавление записи в лог
function addBulkLog(type, message) {
    const log = document.getElementById('bulkAccountsLog');
    const time = new Date().toLocaleTimeString();
    const colors = { info: '#17a2b8', success: '#28a745', warning: '#ffc107', error: '#dc3545' };
    const icons = { info: 'info-circle', success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle' };
    
    log.innerHTML += `<div style="color: ${colors[type]}"><i class="fas fa-${icons[type]} me-1"></i>[${time}] ${message}</div>`;
    log.scrollTop = log.scrollHeight;
}

// Остановка импорта
function stopBulkAccountsImport() {
    if (confirm('Остановить загрузку? Уже добавленные аккаунты сохранятся.')) {
        bulkAccountsRunning = false;
        addBulkLog('warning', 'Загрузка остановлена пользователем');
        document.getElementById('bulkAccountsStopBtn').disabled = true;
    }
}

// Завершение импорта
function completeButlkAccountsImport() {
    bulkAccountsRunning = false;
    
    // Обновляем финальную статистику
    document.getElementById('finalStatTotal').textContent = bulkAccountsStats.total;
    document.getElementById('finalStatSuccess').textContent = bulkAccountsStats.success;
    document.getElementById('finalStatDuplicate').textContent = bulkAccountsStats.duplicate;
    document.getElementById('finalStatError').textContent = bulkAccountsStats.error;
    document.getElementById('finalDomainsTotal').textContent = bulkAccountsStats.domains;
    
    // Показываем ошибки если есть
    if (bulkAccountsErrors.length > 0) {
        let errorsHtml = '<div class="alert alert-danger"><h6><i class="fas fa-exclamation-triangle me-2"></i>Ошибки при импорте:</h6><ul class="mb-0" style="max-height: 150px; overflow-y: auto;">';
        bulkAccountsErrors.slice(0, 20).forEach(err => {
            errorsHtml += `<li><strong>${err.email}</strong>: ${err.error}</li>`;
        });
        if (bulkAccountsErrors.length > 20) {
            errorsHtml += `<li>... и ещё ${bulkAccountsErrors.length - 20} ошибок</li>`;
        }
        errorsHtml += '</ul></div>';
        document.getElementById('bulkAccountsErrorsList').innerHTML = errorsHtml;
    }
    
    // Показываем шаг 4 (завершение)
    document.getElementById('bulkAccountsStep3').classList.add('d-none');
    document.getElementById('bulkAccountsStep4').classList.remove('d-none');
    document.getElementById('bulkAccountsCloseBtn').disabled = false;
    
    addBulkLog('info', `Импорт завершен: ${bulkAccountsStats.success} добавлено, ${bulkAccountsStats.duplicate} дубликатов, ${bulkAccountsStats.error} ошибок`);
}

// Сброс модального окна
function resetBulkAccountsModal() {
    bulkAccountsQueue = [];
    bulkAccountsRunning = false;
    bulkAccountsStats = { total: 0, success: 0, duplicate: 0, error: 0, domains: 0 };
    bulkAccountsErrors = [];
    validatedAccounts = null;
    
    document.getElementById('bulkAccountsList').value = '';
    document.getElementById('bulkAccountsLog').innerHTML = '';
    
    backToStep1();
}

// Обработка закрытия модального окна
document.getElementById('addAccountsBulkModal').addEventListener('hidden.bs.modal', function() {
    if (bulkAccountsRunning) {
        bulkAccountsRunning = false;
    }
});
</script>

<!-- Add Account via Queue Modal -->
<div class="modal fade" id="addAccountQueueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Добавить аккаунт (через очередь)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Домены будут автоматически получены из Cloudflare
                </div>
                <form id="addAccountQueueForm">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="accountEmail" placeholder="user@example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Key</label>
                        <input type="text" class="form-control" id="accountApiKey" placeholder="Global API Key или Token" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Группа</label>
                        <select class="form-select" id="accountGroupId" required>
                            <option value="">-- Выберите группу --</option>
                            <?php if (isset($groups)): ?>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="processAccountAdd()">
                    <i class="fas fa-tasks me-1"></i>Добавить в очередь
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     NS SERVERS MODAL
     =================================== -->

<div class="modal fade" id="nsServersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-server me-2"></i>NS Серверы</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="nsServersContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Загрузка...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     SETTINGS MODALS
     =================================== -->

<!-- Change IP Modal -->
<div class="modal fade" id="changeIPModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-network-wired me-2"></i>Смена IP адресов</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="changeIPForm">
                    <div class="mb-3">
                        <label class="form-label">Новый IP адрес</label>
                        <input type="text" class="form-control" id="newIPAddress" placeholder="192.168.1.1" required pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                        <div class="form-text">Введите корректный IPv4 адрес</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Выбранные домены:</label>
                        <div id="selectedDomainsForIP" class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                            <span class="text-muted">Домены не выбраны</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="processIPChange()">
                    <i class="fas fa-tasks me-1"></i>Добавить в очередь
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change SSL Mode Modal -->
<div class="modal fade" id="changeSSLModeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Смена SSL режима</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="changeSSLModeForm">
                    <div class="mb-3">
                        <label class="form-label">SSL режим</label>
                        <select class="form-select" id="newSSLMode" required>
                            <option value="">-- Выберите режим --</option>
                            <option value="off">Off - SSL отключен</option>
                            <option value="flexible">Flexible - Частичное шифрование</option>
                            <option value="full">Full - Полное шифрование</option>
                            <option value="strict">Full (strict) - С проверкой сертификата</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Выбранные домены:</label>
                        <div id="selectedDomainsForSSL" class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                            <span class="text-muted">Домены не выбраны</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="processSSLModeChange()">
                    <i class="fas fa-tasks me-1"></i>Применить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change TLS Modal -->
<div class="modal fade" id="changeTLSModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i>Смена версии TLS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="changeTLSForm">
                    <div class="mb-3">
                        <label class="form-label">Версия TLS</label>
                        <select class="form-select" id="newTLSVersion" required>
                            <option value="">-- Выберите версию --</option>
                            <option value="1.0">TLS 1.0 (не рекомендуется)</option>
                            <option value="1.1">TLS 1.1 (устарело)</option>
                            <option value="1.2">TLS 1.2 (рекомендуется)</option>
                            <option value="1.3">TLS 1.3 (современный)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Выбранные домены:</label>
                        <div id="selectedDomainsForTLS" class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                            <span class="text-muted">Домены не выбраны</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="processTLSChange()">
                    <i class="fas fa-tasks me-1"></i>Применить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change HTTPS Modal -->
<div class="modal fade" id="changeHTTPSModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe me-2"></i>Always Use HTTPS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="changeHTTPSForm">
                    <div class="mb-3">
                        <label class="form-label">Настройка</label>
                        <select class="form-select" id="newHTTPSSetting" required>
                            <option value="">-- Выберите --</option>
                            <option value="1">Включить - Принудительное HTTPS</option>
                            <option value="0">Выключить - Разрешить HTTP</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Выбранные домены:</label>
                        <div id="selectedDomainsForHTTPS" class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                            <span class="text-muted">Домены не выбраны</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="processHTTPSChange()">
                    <i class="fas fa-tasks me-1"></i>Применить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     WORKERS MODALS
     =================================== -->

<!-- Manage Worker Modal -->
<div class="modal fade" id="manageWorkerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-code me-2"></i>Cloudflare Workers 
                    <span id="workerModalDomainName" class="text-primary"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="workerModalLoader" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>

                <div id="workerModalContent" class="d-none">
                    <!-- Active Routes -->
                    <div class="mb-4">
                        <h6 class="fw-bold"><i class="fas fa-route me-2"></i>Активные маршруты</h6>
                        <div id="workerRoutesContainer" class="border rounded p-3 bg-light"></div>
                    </div>

                    <!-- Apply Template -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-wrench me-2"></i>Применить шаблон
                        </div>
                        <div class="card-body">
                            <form id="workerApplyForm">
                                <input type="hidden" id="workerDomainId">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Шаблон</label>
                                        <select id="workerTemplateSelect" class="form-select">
                                            <option value="">-- Выберите шаблон --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Маршрут</label>
                                        <input type="text" id="workerRoutePattern" class="form-control" placeholder="{{domain}}/*">
                                        <div class="form-text">{{domain}} заменится на домен</div>
                                    </div>
                                </div>
                            </form>
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary" onclick="applyWorkerTemplate()">
                                    <i class="fas fa-play me-1"></i>Применить
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="reloadWorkerModalData()">
                                    <i class="fas fa-sync me-1"></i>Обновить
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Script -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-file-code me-2"></i>Пользовательский скрипт
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <textarea id="workerCustomScript" class="form-control font-monospace" rows="10" placeholder="// Ваш JavaScript код"></textarea>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="workerSaveTemplate">
                                        <label class="form-check-label" for="workerSaveTemplate">Сохранить как шаблон</label>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <input type="text" id="workerTemplateName" class="form-control d-none" placeholder="Название шаблона">
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-success" onclick="applyWorkerCustomScript()">
                                    <i class="fas fa-cloud-upload-alt me-1"></i>Загрузить скрипт
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <span id="workerModalStatus" class="me-auto text-muted"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Worker Modal -->
<div class="modal fade" id="bulkWorkerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>Массовое применение Workers</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info" id="bulkWorkerSelectionInfo"></div>
                <form id="bulkWorkerForm">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Шаблон</label>
                            <select id="bulkWorkerTemplate" class="form-select" required>
                                <option value="">-- Выберите шаблон --</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Маршрут</label>
                            <input type="text" id="bulkWorkerRoutePattern" class="form-control" placeholder="{{domain}}/*">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Область применения</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="bulkWorkerScope" id="bulkWorkerScopeSelected" value="selected" checked>
                            <label class="form-check-label" for="bulkWorkerScopeSelected">Только выбранные домены</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="bulkWorkerScope" id="bulkWorkerScopeGroup" value="group">
                            <label class="form-check-label" for="bulkWorkerScopeGroup">Вся группа</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="bulkWorkerScope" id="bulkWorkerScopeAll" value="all">
                            <label class="form-check-label" for="bulkWorkerScopeAll">Все домены</label>
                        </div>
                        <div class="mt-2 d-none" id="bulkWorkerGroupWrapper">
                            <select id="bulkWorkerGroup" class="form-select">
                                <option value="">-- Выберите группу --</option>
                                <?php if (isset($groups)): ?>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </form>
                <div id="bulkWorkerResult" class="mt-3 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                <button type="button" class="btn btn-primary" onclick="bulkApplyWorkers()">
                    <i class="fas fa-cloud-upload-alt me-1"></i>Применить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     CERTIFICATES MODAL
     =================================== -->

<div class="modal fade" id="createCertificateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-certificate me-2"></i>Создание SSL сертификатов</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Внимание!</strong> Будут созданы Origin CA сертификаты Cloudflare (срок действия 1 год).
                </div>
                <form id="createCertificateForm">
                    <div class="mb-3">
                        <label class="form-label">Выбранные домены:</label>
                        <div id="selectedDomainsForCert" class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                            <span class="text-muted">Домены не выбраны</span>
                        </div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="includeCertificateData">
                        <label class="form-check-label" for="includeCertificateData">
                            Показать сертификаты в логах
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-warning" onclick="processCertificateCreation()">
                    <i class="fas fa-tasks me-1"></i>Создать сертификаты
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================================
     WORKER TEMPLATE CHECKBOX LOGIC
     =================================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle save template input
    const saveTemplateCheck = document.getElementById('workerSaveTemplate');
    const templateNameInput = document.getElementById('workerTemplateName');
    
    if (saveTemplateCheck && templateNameInput) {
        saveTemplateCheck.addEventListener('change', function() {
            templateNameInput.classList.toggle('d-none', !this.checked);
        });
    }
    
    // Toggle group selector
    const scopeRadios = document.querySelectorAll('input[name="bulkWorkerScope"]');
    const groupWrapper = document.getElementById('bulkWorkerGroupWrapper');
    
    scopeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (groupWrapper) {
                groupWrapper.classList.toggle('d-none', this.value !== 'group');
            }
        });
    });
});
</script>
<!-- DNS Manager Modal -->
<div class="modal fade" id="dnsManagerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-list me-2"></i>DNS записи: <span id="dnsDomainName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dnsDomainId">
                <!-- Add record form -->
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="fas fa-plus me-2"></i>Добавить запись</h6>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label small mb-1">Тип</label>
                                <select id="dnsType" class="form-select form-select-sm" onchange="dnsTypeChanged()">
                                    <option>A</option><option>AAAA</option><option>CNAME</option>
                                    <option>TXT</option><option>MX</option><option>NS</option><option>SRV</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Имя</label>
                                <input id="dnsName" class="form-control form-control-sm" placeholder="@ или sub">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Содержимое</label>
                                <input id="dnsContent" class="form-control form-control-sm" placeholder="IP / домен / текст">
                            </div>
                            <div class="col-md-1" id="dnsPriorityWrap" style="display:none;">
                                <label class="form-label small mb-1">Приор.</label>
                                <input id="dnsPriority" type="number" class="form-control form-control-sm" value="10">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label small mb-1">TTL</label>
                                <select id="dnsTtl" class="form-select form-select-sm">
                                    <option value="1">Auto</option><option value="300">5м</option>
                                    <option value="3600">1ч</option><option value="86400">1д</option>
                                </select>
                            </div>
                            <div class="col-md-1" id="dnsProxiedWrap">
                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" id="dnsProxied">
                                    <label class="form-check-label small" for="dnsProxied">Proxy</label>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <button class="btn btn-primary btn-sm w-100" onclick="dnsCreate()"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <small class="text-muted">Имя <code>@</code> = корень домена. «Proxy» (оранжевое облако) доступно только для A/AAAA/CNAME.</small>
                    </div>
                </div>
                <!-- Toolbar: export/import BIND -->
                <div class="d-flex gap-2 mb-2">
                    <button class="btn btn-sm btn-outline-secondary" onclick="dnsExport()"><i class="fas fa-download me-1"></i>Экспорт зоны (BIND)</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('dnsImportWrap').classList.toggle('d-none')"><i class="fas fa-upload me-1"></i>Импорт BIND</button>
                </div>
                <div id="dnsImportWrap" class="d-none mb-3">
                    <textarea id="dnsImportText" class="form-control form-control-sm mb-1" rows="4" placeholder="Вставьте содержимое BIND-зоны…"></textarea>
                    <button class="btn btn-sm btn-primary" onclick="dnsImport()"><i class="fas fa-check me-1"></i>Импортировать</button>
                    <small class="text-muted ms-2">Записи добавятся к существующим.</small>
                </div>
                <!-- Records table -->
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th>Тип</th><th>Имя</th><th>Содержимое</th><th>Proxy</th><th>TTL</th><th></th></tr></thead>
                        <tbody id="dnsRecordsBody"><tr><td colspan="6" class="text-muted text-center">Загрузка...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let dnsModalInstance = null;
function openDnsManager(id, domain) {
    document.getElementById('dnsDomainId').value = id;
    document.getElementById('dnsDomainName').textContent = domain;
    dnsModalInstance = new bootstrap.Modal(document.getElementById('dnsManagerModal'));
    dnsModalInstance.show();
    dnsLoad();
}
function dnsTypeChanged() {
    const t = document.getElementById('dnsType').value;
    document.getElementById('dnsPriorityWrap').style.display = (t === 'MX' || t === 'SRV') ? '' : 'none';
    document.getElementById('dnsProxiedWrap').style.display = (t === 'A' || t === 'AAAA' || t === 'CNAME') ? '' : 'none';
}
async function dnsLoad() {
    const id = document.getElementById('dnsDomainId').value;
    const body = document.getElementById('dnsRecordsBody');
    body.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Загрузка...</td></tr>';
    try {
        const res = await fetch('dns_api.php?action=list&domain_id=' + id);
        const data = await res.json();
        if (!data.success) { body.innerHTML = `<tr><td colspan="6" class="text-danger">${data.error||'Ошибка'}</td></tr>`; return; }
        if (!data.records.length) { body.innerHTML = '<tr><td colspan="6" class="text-muted text-center">Записей нет</td></tr>'; return; }
        window._dnsRecords = {};
        body.innerHTML = data.records.map(r => {
            window._dnsRecords[r.id] = r;
            return `
            <tr id="dnsRow_${r.id}">
                <td><span class="badge bg-secondary">${r.type}</span></td>
                <td>${escapeHtml(r.name)}</td>
                <td><code style="white-space:normal;word-break:break-all;">${escapeHtml(String(r.content))}</code>${r.priority!=null?` <span class="text-muted small">(prio ${r.priority})</span>`:''}</td>
                <td>${r.proxiable ? (r.proxied ? '<span class="text-warning">🟠 Proxied</span>' : '<span class="text-muted">DNS only</span>') : '—'}</td>
                <td>${r.ttl === 1 ? 'Auto' : r.ttl}</td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" onclick="dnsEditStart('${r.id}')"><i class="fas fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="dnsDelete('${r.id}')"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    } catch (e) { body.innerHTML = `<tr><td colspan="6" class="text-danger">${e.message}</td></tr>`; }
}
function dnsEditStart(rid) {
    const r = (window._dnsRecords||{})[rid]; if (!r) return;
    const row = document.getElementById('dnsRow_' + rid);
    const proxyCell = r.proxiable ? `<select id="ed_proxied_${rid}" class="form-select form-select-sm"><option value="1" ${r.proxied?'selected':''}>Proxied</option><option value="0" ${!r.proxied?'selected':''}>DNS only</option></select>` : '—';
    row.innerHTML = `
        <td><span class="badge bg-secondary">${r.type}</span></td>
        <td>${escapeHtml(r.name)}</td>
        <td><input id="ed_content_${rid}" class="form-control form-control-sm" value="${escapeHtml(String(r.content))}"></td>
        <td>${proxyCell}</td>
        <td><input id="ed_ttl_${rid}" type="number" class="form-control form-control-sm" value="${r.ttl}" style="width:90px"></td>
        <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-success" onclick="dnsEditSave('${rid}')"><i class="fas fa-check"></i></button>
            <button class="btn btn-sm btn-outline-secondary" onclick="dnsLoad()"><i class="fas fa-times"></i></button>
        </td>`;
}
async function dnsEditSave(rid) {
    const id = document.getElementById('dnsDomainId').value;
    const r = (window._dnsRecords||{})[rid];
    const body = new URLSearchParams({ action:'update', domain_id:id, record_id:rid, type:r.type, name:r.name,
        content: document.getElementById('ed_content_'+rid).value.trim(),
        ttl: document.getElementById('ed_ttl_'+rid).value });
    const pe = document.getElementById('ed_proxied_'+rid); if (pe) body.append('proxied', pe.value);
    if (r.priority != null) body.append('priority', r.priority);
    try {
        const res = await fetch('dns_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const d = await res.json();
        if (d.success) { showToast('Запись обновлена','success'); dnsLoad(); } else showToast(d.error||'Ошибка','error');
    } catch(e){ showToast(e.message,'error'); }
}
async function dnsExport() {
    const id = document.getElementById('dnsDomainId').value;
    try {
        const res = await fetch('dns_api.php?action=export&domain_id=' + id);
        const d = await res.json();
        if (!d.success) { showToast(d.error||'Ошибка','error'); return; }
        const blob = new Blob([d.bind], {type:'text/plain'});
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = d.filename || 'zone.txt'; a.click();
    } catch(e){ showToast(e.message,'error'); }
}
async function dnsImport() {
    const id = document.getElementById('dnsDomainId').value;
    const content = document.getElementById('dnsImportText').value;
    if (!content.trim()) { showToast('Вставьте BIND','warning'); return; }
    try {
        const res = await fetch('dns_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({action:'import', domain_id:id, content}) });
        const d = await res.json();
        if (d.success) { showToast(`Импортировано записей: ${d.added ?? '?'} из ${d.total ?? '?'}`,'success'); document.getElementById('dnsImportText').value=''; dnsLoad(); }
        else showToast(d.error||'Ошибка','error');
    } catch(e){ showToast(e.message,'error'); }
}
async function dnsCreate() {
    const id = document.getElementById('dnsDomainId').value;
    const type = document.getElementById('dnsType').value;
    const name = document.getElementById('dnsName').value.trim();
    const content = document.getElementById('dnsContent').value.trim();
    if (!name || !content) { showToast('Заполните имя и содержимое', 'warning'); return; }
    const body = new URLSearchParams({ action:'create', domain_id:id, type, name, content, ttl: document.getElementById('dnsTtl').value });
    if (type === 'A' || type === 'AAAA' || type === 'CNAME') body.append('proxied', document.getElementById('dnsProxied').checked ? '1' : '0');
    if (type === 'MX' || type === 'SRV') body.append('priority', document.getElementById('dnsPriority').value);
    try {
        const res = await fetch('dns_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const data = await res.json();
        if (data.success) { showToast('Запись добавлена', 'success'); document.getElementById('dnsName').value=''; document.getElementById('dnsContent').value=''; dnsLoad(); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast(e.message, 'error'); }
}
async function dnsDelete(recordId) {
    if (!confirm('Удалить запись?')) return;
    const id = document.getElementById('dnsDomainId').value;
    try {
        const res = await fetch('dns_api.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({ action:'delete', domain_id:id, record_id:recordId }) });
        const data = await res.json();
        if (data.success) { showToast('Удалено', 'success'); dnsLoad(); } else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast(e.message, 'error'); }
}
function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
</script>

<!-- Analytics Modal -->
<div class="modal fade" id="analyticsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-chart-line me-2"></i>Аналитика: <span id="anDomain"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="anBody">
                <div class="text-muted text-center py-4"><i class="fas fa-spinner fa-spin me-1"></i>Загрузка…</div>
            </div>
        </div>
    </div>
</div>
<script>
async function openAnalytics(id, domain) {
    document.getElementById('anDomain').textContent = domain;
    const body = document.getElementById('anBody');
    body.innerHTML = '<div class="text-muted text-center py-4"><i class="fas fa-spinner fa-spin me-1"></i>Загрузка…</div>';
    new bootstrap.Modal(document.getElementById('analyticsModal')).show();
    try {
        const res = await fetch('analytics_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'dashboard', domain_id:id, days:7}) });
        const d = await res.json();
        if (!d.success) { body.innerHTML = `<div class="alert alert-warning">${d.error||'Ошибка'}</div>`; return; }
        const fmtBytes = b => b>1e9?(b/1e9).toFixed(2)+' ГБ':b>1e6?(b/1e6).toFixed(1)+' МБ':(b/1e3).toFixed(0)+' КБ';
        const maxReq = Math.max(1, ...d.days.map(x=>x.requests));
        const bars = d.days.map(x => `<div class="d-flex align-items-center mb-1"><div style="width:90px" class="small text-muted">${x.date}</div><div class="flex-grow-1"><div class="bg-info" style="height:14px;width:${Math.round(x.requests/maxReq*100)}%;min-width:2px;border-radius:3px"></div></div><div style="width:90px" class="small text-end">${x.requests.toLocaleString()}</div></div>`).join('');
        const countries = (d.countries||[]).map(c=>`<span class="badge bg-light text-dark border me-1 mb-1">${c.country}: ${c.count.toLocaleString()}</span>`).join('') || '<span class="text-muted small">нет данных</span>';
        body.innerHTML = `
            <div class="row text-center mb-3">
                <div class="col-4"><div class="h4 mb-0">${d.totals.requests.toLocaleString()}</div><small class="text-muted">запросов (7д)</small></div>
                <div class="col-4"><div class="h4 mb-0">${fmtBytes(d.totals.bytes)}</div><small class="text-muted">трафик</small></div>
                <div class="col-4"><div class="h4 mb-0 text-danger">${d.totals.threats.toLocaleString()}</div><small class="text-muted">угроз</small></div>
            </div>
            <h6 class="small fw-bold">Запросы по дням</h6>${bars}
            <h6 class="small fw-bold mt-3">Топ стран</h6>${countries}
        `;
    } catch(e){ body.innerHTML = `<div class="alert alert-danger">${e.message}</div>`; }
}
</script>
