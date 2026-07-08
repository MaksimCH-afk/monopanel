// Frontend for the mail admin. Three panes: mailboxes → messages → reader.
// Read-state is tracked client-side in localStorage (the D1 schema has no read
// flag; the Worker just appends rows), keyed by message id.

const $ = (sel) => document.querySelector(sel);
const el = (tag, cls, html) => {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (html !== undefined) n.innerHTML = html;
  return n;
};
const esc = (s) =>
  (s || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const READ_KEY = 'mail-read-ids';
const readSet = new Set(JSON.parse(localStorage.getItem(READ_KEY) || '[]'));
const isRead = (id) => readSet.has(Number(id));
function markRead(id) {
  readSet.add(Number(id));
  try { localStorage.setItem(READ_KEY, JSON.stringify([...readSet])); } catch {}
}

const state = { mailbox: null, mockMode: true, search: '' };

function fmtDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  return sameDay
    ? d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) +
        ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function fmtSize(n) {
  if (!n && n !== 0) return '';
  return n < 1024 ? `${n} B` : `${(n / 1024).toFixed(1)} KB`;
}

async function api(path, opts) {
  const res = await fetch(path, opts);
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
  return json;
}

// ── Health / badges ─────────────────────────────────────────────────────────
async function loadHealth() {
  const h = await api('/api/health');
  state.mockMode = h.mock_mode;
  $('#mockBadge').hidden = !h.mock_mode;
  $('#liveBadge').hidden = h.mock_mode;
  // Prefill settings (ids are not secret; token is masked and left blank).
  const cf = h.cloudflare || {};
  if (cf.accountId?.value) $('#accountId').value = cf.accountId.value;
  if (cf.databaseId?.value) $('#databaseId').value = cf.databaseId.value;
  $('#token').placeholder = cf.token?.masked ? `${cf.token.masked} (пусто → без изменений)` : 'пусто → без изменений';
}

// ── Mailboxes ───────────────────────────────────────────────────────────────
async function loadMailboxes() {
  const list = $('#mailboxList');
  list.innerHTML = '';
  let data;
  try {
    data = await api('/api/mailboxes');
  } catch (e) {
    list.appendChild(el('li', 'empty', esc(e.message)));
    return;
  }
  const boxes = data.mailboxes || [];
  $('#boxCount').textContent = boxes.length ? `${boxes.length}` : '';
  if (!boxes.length) {
    list.appendChild(el('div', 'empty', state.mockMode ? 'Нет писем.' : 'Пока нет писем в D1.'));
    return;
  }
  boxes.forEach((b) => {
    const li = el('li');
    li.dataset.mailbox = b.mailbox;
    li.appendChild(el('span', 'box__addr', esc(b.mailbox)));
    li.appendChild(el('div', 'box__meta',
      `<span>${b.count} писем</span><span>${esc(fmtDate(b.last_at))}</span>`));
    li.onclick = () => selectMailbox(b.mailbox);
    list.appendChild(li);
  });
  // Auto-select the first mailbox.
  if (!state.mailbox && boxes.length) selectMailbox(boxes[0].mailbox);
  else highlightMailbox();
}

function highlightMailbox() {
  document.querySelectorAll('#mailboxList li').forEach((li) =>
    li.classList.toggle('active', li.dataset.mailbox === state.mailbox));
}

async function selectMailbox(mailbox) {
  state.mailbox = mailbox;
  highlightMailbox();
  $('#listTitle').textContent = mailbox;
  $('#search').disabled = false;
  await loadMessages();
}

// ── Message list ────────────────────────────────────────────────────────────
async function loadMessages() {
  const list = $('#messageList');
  list.innerHTML = '';
  if (!state.mailbox) return;
  const qs = new URLSearchParams({ mailbox: state.mailbox });
  if (state.search) qs.set('search', state.search);
  let data;
  try {
    data = await api(`/api/messages?${qs}`);
  } catch (e) {
    list.appendChild(el('li', 'empty', esc(e.message)));
    return;
  }
  const msgs = data.messages || [];
  if (!msgs.length) {
    list.appendChild(el('div', 'empty', 'Ничего не найдено.'));
    return;
  }
  msgs.forEach((m) => {
    const li = el('li', isRead(m.id) ? '' : 'unread');
    li.dataset.id = m.id;
    li.appendChild(el('div', 'msg__from',
      `<span>${esc(m.sender || '—')}</span><span class="msg__when">${esc(fmtDate(m.received_at))}</span>`));
    li.appendChild(el('div', 'msg__subject', esc(m.subject || '(без темы)')));
    li.onclick = () => openMessage(m.id, li);
    list.appendChild(li);
  });
}

// ── Reader ──────────────────────────────────────────────────────────────────
async function openMessage(id, li) {
  document.querySelectorAll('#messageList li').forEach((x) => x.classList.remove('active'));
  if (li) li.classList.add('active');
  markRead(id);
  if (li) li.classList.remove('unread');

  const reader = $('#reader');
  reader.innerHTML = '<div class="reader__empty">Загрузка…</div>';
  let msg;
  try {
    ({ message: msg } = await api(`/api/messages/${id}`));
  } catch (e) {
    reader.innerHTML = `<div class="reader__empty">${esc(e.message)}</div>`;
    return;
  }
  renderMessage(msg);
}

function renderMessage(m) {
  const reader = $('#reader');
  reader.innerHTML = '';

  const head = el('div', 'reader__head');
  head.appendChild(el('h1', 'reader__subject', esc(m.subject || '(без темы)')));
  head.appendChild(el('div', 'reader__row', `<b>От:</b> ${esc(m.sender || '—')}`));
  head.appendChild(el('div', 'reader__row', `<b>Кому:</b> ${esc(m.mailbox || '')}`));
  head.appendChild(el('div', 'reader__row',
    `<b>Получено:</b> ${esc(new Date(m.received_at).toLocaleString('ru-RU'))} · ${esc(fmtSize(m.raw_size))}`));

  if (!state.mockMode) {
    const actions = el('div', 'reader__actions');
    const del = el('button', 'btn btn--danger', 'Удалить письмо');
    del.onclick = () => deleteMessage(m.id);
    actions.appendChild(del);
    head.appendChild(actions);
  }
  reader.appendChild(head);

  const hasHtml = !!(m.html_body && m.html_body.trim());
  const hasText = !!(m.text_body && m.text_body.trim());

  const bodyWrap = el('div', 'reader__body');
  const showText = () => {
    bodyWrap.innerHTML = '';
    bodyWrap.appendChild(el('pre', null, esc(m.text_body || '(пусто)')));
  };
  const showHtml = () => {
    bodyWrap.innerHTML = '';
    const box = el('div', 'reader__html');
    const iframe = document.createElement('iframe');
    iframe.sandbox = ''; // no scripts, no same-origin — safe rendering of arbitrary mail HTML
    iframe.srcdoc = m.html_body;
    box.appendChild(iframe);
    bodyWrap.appendChild(box);
    // Size the iframe to its content once loaded.
    iframe.onload = () => {
      try { iframe.style.height = Math.min(2000, iframe.contentWindow.document.body.scrollHeight + 24) + 'px'; }
      catch { iframe.style.height = '480px'; }
    };
  };

  if (hasHtml && hasText) {
    const tabs = el('div', 'tabs');
    const bText = el('button', 'active', 'Текст');
    const bHtml = el('button', null, 'HTML');
    bText.onclick = () => { bText.classList.add('active'); bHtml.classList.remove('active'); showText(); };
    bHtml.onclick = () => { bHtml.classList.add('active'); bText.classList.remove('active'); showHtml(); };
    tabs.append(bText, bHtml);
    reader.appendChild(tabs);
    showText();
  } else if (hasHtml) {
    showHtml();
  } else {
    showText();
  }
  reader.appendChild(bodyWrap);
}

async function deleteMessage(id) {
  if (!confirm('Удалить это письмо из D1? Действие необратимо.')) return;
  try {
    await api(`/api/messages/${id}`, { method: 'DELETE' });
    $('#reader').innerHTML = '<div class="reader__empty">Письмо удалено.</div>';
    await loadMessages();
    await loadMailboxes();
  } catch (e) {
    alert('Не удалось удалить: ' + e.message);
  }
}

// ── Drawers (Help + Settings) ────────────────────────────────────────────────
const backdrop = $('#backdrop');
function openDrawer(id) {
  // close any other open drawer first, then open the requested one
  document.querySelectorAll('.drawer.open').forEach((d) => { if (d.id !== id) closeDrawer(d); });
  const d = document.getElementById(id);
  d.classList.add('open');
  d.setAttribute('aria-hidden', 'false');
  backdrop.hidden = false;
}
function closeDrawer(d) {
  d.classList.remove('open');
  d.setAttribute('aria-hidden', 'true');
}
function closeAllDrawers() {
  document.querySelectorAll('.drawer.open').forEach(closeDrawer);
  backdrop.hidden = true;
}
$('#helpBtn').onclick = () => openDrawer('help');
$('#settingsBtn').onclick = () => openDrawer('settings');
$('#workerBtn').onclick = () => { loadWorkerSource(); openDrawer('worker'); };
$('#openHelpFromCfg').onclick = () => openDrawer('help');
backdrop.onclick = closeAllDrawers;
document.querySelectorAll('[data-close]').forEach((b) => (b.onclick = closeAllDrawers));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllDrawers(); });

// ── Worker source: load once, copy to clipboard ──────────────────────────────
const workerText = { code: '', schema: '' };
let workerLoaded = false;
async function loadWorkerSource() {
  if (workerLoaded) return;
  workerLoaded = true;
  for (const [type, elId] of [['code', 'workerCode'], ['schema', 'workerSchema']]) {
    try {
      const res = await fetch(`/api/worker/${type}`);
      const text = await res.text();
      workerText[type] = text;
      $('#' + elId).textContent = text;
    } catch {
      $('#' + elId).textContent = '// не удалось загрузить';
      workerLoaded = false; // allow a retry on next open
    }
  }
}
document.querySelectorAll('.copy-btn').forEach((btn) => {
  btn.onclick = async () => {
    const text = workerText[btn.dataset.copy] || '';
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      // Fallback for non-secure contexts / older browsers.
      const ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch {}
      ta.remove();
    }
    const orig = btn.textContent;
    btn.textContent = '✓ Скопировано';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1600);
  };
});

// ── Discovery: pick account + D1 database by the token (no wrangler) ──────────
const accountPick = $('#accountPick');
const dbPick = $('#dbPick');

async function runDiscover(accountId) {
  const msg = $('#discoverMsg');
  msg.className = 'msg';
  msg.textContent = 'Опрос Cloudflare…';
  let data;
  try {
    data = await api('/api/discover', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: $('#token').value, accountId: accountId || undefined }),
    });
  } catch (e) {
    msg.className = 'msg err';
    msg.textContent = e.message;
    return;
  }

  // Accounts
  if (data.accounts && data.accounts.length) {
    accountPick.innerHTML = data.accounts
      .map((a) => `<option value="${a.id}">${esc(a.name)} — ${esc(a.id)}</option>`)
      .join('');
    accountPick.value = data.accountId || data.accounts[0].id;
    $('#accountPickWrap').hidden = data.accounts.length < 2; // single account → just autofill
    $('#accountId').value = accountPick.value;
  }

  // Databases
  if (data.databases && data.databases.length) {
    dbPick.innerHTML =
      '<option value="">— выберите базу —</option>' +
      data.databases.map((d) => `<option value="${d.uuid}">${esc(d.name)} — ${esc(d.uuid)}</option>`).join('');
    $('#dbPickWrap').hidden = false;
    // Prefer a database literally named "mail".
    const mail = data.databases.find((d) => d.name === 'mail');
    if (mail) { dbPick.value = mail.uuid; $('#databaseId').value = mail.uuid; }
    msg.className = 'msg ok';
    msg.textContent = `Найдено баз: ${data.databases.length}. Выберите нужную — ID подставится.`;
  } else {
    $('#dbPickWrap').hidden = true;
    if (data.accountId) {
      // We have an account but it holds no D1 databases.
      msg.className = 'msg ok';
      msg.textContent = 'В этом аккаунте нет баз D1. Создайте базу «mail» (см. ⧉ Воркер) и нажмите снова.';
    } else if (data.accounts && data.accounts.length > 1) {
      msg.className = 'msg';
      msg.textContent = 'Выберите аккаунт выше — покажем базы D1.';
    } else {
      // /accounts came back empty: the token can't enumerate accounts (needs
      // Account Settings · Read). Ask for the Account ID and retry — D1 Read is
      // enough to then list the databases.
      msg.className = 'msg err';
      msg.innerHTML =
        'Cloudflare не вернул аккаунт по этому токену (у токена нет права ' +
        '<b>Account · Account Settings · Read</b>). Впишите <b>Account ID</b> вручную в поле выше ' +
        '(он в адресной строке дашборда <code>dash.cloudflare.com/&lt;account-id&gt;</code>) и нажмите ' +
        '«Подобрать» снова — база D1 подтянется. Для чтения писем прав <b>D1 · Read</b> достаточно.';
      const acc = $('#accountId');
      acc.focus();
      acc.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }
}

// Use a manually-typed Account ID if present (needed when the token can't list
// accounts): then discovery lists that account's D1 databases via D1·Read.
$('#discoverBtn').onclick = () => runDiscover($('#accountId').value.trim() || undefined);
accountPick.onchange = () => { $('#accountId').value = accountPick.value; runDiscover(accountPick.value); };
dbPick.onchange = () => { if (dbPick.value) $('#databaseId').value = dbPick.value; };

$('#saveCfg').onclick = async () => {
  const msg = $('#cfgMsg');
  msg.className = 'msg';
  msg.textContent = 'Сохранение…';
  try {
    await api('/api/config', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accountId: $('#accountId').value,
        databaseId: $('#databaseId').value,
        token: $('#token').value, // empty → server keeps existing token
      }),
    });
    $('#token').value = '';
    msg.className = 'msg ok';
    msg.textContent = 'Сохранено.';
    await refreshAll();
  } catch (e) {
    msg.className = 'msg err';
    msg.textContent = e.message;
  }
};

$('#testCfg').onclick = async () => {
  const msg = $('#cfgMsg');
  msg.className = 'msg';
  msg.textContent = 'Проверка…';
  try {
    const r = await api('/api/test', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        accountId: $('#accountId').value,
        databaseId: $('#databaseId').value,
        token: $('#token').value,
      }),
    });
    msg.className = r.ok ? 'msg ok' : 'msg err';
    msg.textContent = r.message;
  } catch (e) {
    msg.className = 'msg err';
    msg.textContent = e.message;
  }
};

// ── Search / refresh ────────────────────────────────────────────────────────
let searchTimer;
$('#search').oninput = (e) => {
  clearTimeout(searchTimer);
  const v = e.target.value;
  searchTimer = setTimeout(() => { state.search = v; loadMessages(); }, 250);
};
$('#refreshBtn').onclick = () => refreshAll();

async function refreshAll() {
  await loadHealth();
  await loadMailboxes();
}

refreshAll().catch((e) => console.error(e));
