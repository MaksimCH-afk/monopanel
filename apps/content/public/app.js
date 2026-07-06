'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

let MAX_COMPETITORS = 10;

const el = {
  form: $('#analyzeForm'),
  query: $('#query'),
  target: $('#target'),
  targetField: $('#targetField'),
  modeHint: $('#modeHint'),
  customStopwords: $('#customStopwords'),
  competitors: $('#competitors'),
  addComp: $('#addComp'),
  compCount: $('#compCount'),
  submit: $('#submitBtn'),
  formError: $('#formError'),
  inputCard: $('#inputCard'),
  loading: $('#loading'),
  result: $('#result'),
  mockBadge: $('#mockBadge'),
  // API-key settings
  keysToggle: $('#keysToggle'),
  keysBody: $('#keysBody'),
  keysSummary: $('#keysSummary'),
  keysChevron: $('.keys-chevron'),
  googleKey: $('#googleKey'),
  openaiKey: $('#openaiKey'),
  googleStatus: $('#googleStatus'),
  openaiStatus: $('#openaiStatus'),
  googleMsg: $('#googleMsg'),
  openaiMsg: $('#openaiMsg'),
  testGoogle: $('#testGoogle'),
  testOpenai: $('#testOpenai'),
  saveKeys: $('#saveKeys'),
  keysSaved: $('#keysSaved'),
};

const esc = (s) =>
  String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
  );

// ─── Competitor rows ──────────────────────────────────────────────
function addCompetitor() {
  if ($$('.comp', el.competitors).length >= MAX_COMPETITORS) return;
  const node = $('#compTemplate').content.firstElementChild.cloneNode(true);
  $('.btn-remove', node).addEventListener('click', () => {
    node.remove();
    refreshComps();
  });
  $('.comp-text', node).addEventListener('input', validate);
  el.competitors.appendChild(node);
  refreshComps();
}

function refreshComps() {
  const n = $$('.comp', el.competitors).length;
  el.compCount.textContent = `(${n}/${MAX_COMPETITORS})`;
  el.addComp.disabled = n >= MAX_COMPETITORS;
  el.addComp.style.opacity = n >= MAX_COMPETITORS ? 0.5 : 1;
  validate();
}

function collectCompetitors() {
  return $$('.comp', el.competitors)
    .map((c) => ({ label: $('.comp-label', c).value.trim() || null, text: $('.comp-text', c).value }))
    .filter((c) => c.text.trim());
}

// ─── Mode ─────────────────────────────────────────────────────────
const MODE_HINTS = {
  compare: 'Дифф вашей страницы против конкурентов: чего не хватает (missing/weak).',
  competitors_only: 'Профиль конкурентов по теме: что вообще должна покрывать страница. «Моя страница» не нужна.',
};
function getMode() {
  const r = $('input[name="mode"]:checked');
  return r ? r.value : 'compare';
}
function applyMode() {
  const mode = getMode();
  const competitorsOnly = mode === 'competitors_only';
  el.targetField.hidden = competitorsOnly; // hide "Моя страница" in mode B
  el.modeHint.textContent = MODE_HINTS[mode] || '';
  validate();
}

// ─── Validation ───────────────────────────────────────────────────
function validate() {
  const targetOk = getMode() === 'competitors_only' || el.target.value.trim();
  const ok = el.query.value.trim() && targetOk && collectCompetitors().length >= 1;
  el.submit.disabled = !ok;
  return ok;
}

// ─── Submit ───────────────────────────────────────────────────────
el.form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validate()) return;
  el.formError.textContent = '';

  const mode = getMode();
  const payload = {
    mode,
    query: el.query.value.trim(),
    competitors: collectCompetitors(),
    custom_stopwords: el.customStopwords.value.trim() || undefined,
  };
  // "Моя страница" only in compare mode (ignored otherwise).
  if (mode === 'compare') payload.target = { label: null, text: el.target.value };

  el.inputCard.hidden = true;
  el.result.hidden = true;
  el.loading.hidden = false;

  try {
    const res = await fetch('/api/analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || `Ошибка ${res.status}`);
    renderResult(data);
  } catch (err) {
    el.inputCard.hidden = false;
    el.formError.textContent = err.message;
  } finally {
    el.loading.hidden = true;
  }
});

// ─── Rendering ────────────────────────────────────────────────────
function renderResult(data) {
  const parts = [];

  // meta
  const langLabel = data.language?.dominant && data.language.dominant !== 'und'
    ? data.language.dominant
    : (data.language?.target && data.language.target !== 'und' ? data.language.target : '—');
  const modeLabel = { nl: 'NL API', code: 'код (плотность)', mixed: 'NL + код' }[data.entities_mode] || data.entities_mode;
  parts.push(`<div class="meta-row">
    <span class="pill">Запрос: <strong>${esc(data.query)}</strong></span>
    <span class="pill">Конкурентов: <strong>${data.competitors_analyzed}</strong>${
    data.competitors_failed ? ` (пропущено ${data.competitors_failed})` : ''
  }</span>
    <span class="pill">Порог консенсуса K = <strong>${data.consensus_threshold}</strong></span>
    ${langLabel !== '—' ? `<span class="pill">Язык: <strong>${esc(langLabel)}</strong></span>` : ''}
    ${data.entities_mode ? `<span class="pill">Сущности: <strong>${esc(modeLabel)}</strong></span>` : ''}
    ${data.elapsed_ms ? `<span class="pill">${data.elapsed_ms} ms</span>` : ''}
    ${data.mock_mode ? '<span class="pill" style="color:var(--medium)">mock-режим</span>' : ''}
  </div>`);

  if (data.warnings?.length) {
    parts.push(
      `<ul class="warnings">${data.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>`
    );
  }

  const profileMode = data.mode === 'competitors_only';

  // 1. Intent (verdict against my page only makes sense in compare mode)
  parts.push(intentCard(data, profileMode));

  const n = data.competitors_analyzed;
  if (profileMode) {
    // Mode B: consensus profile / brief — no missing/weak diff.
    parts.push(profileEntityCard('2 · Профиль темы: сущности конкурентов', data.consensus_profile || [], n));
    parts.push(profilePhraseCard('3 · Профиль темы: фразы (n-граммы)', data.phrase_profile || [], n));
    parts.push(volumeProfileCard(data.volume));
  } else {
    // Mode A: diff against my page.
    parts.push(entityCard('2 · Отсутствующие сущности (missing)', data.missing, false, n));
    parts.push(entityCard('3 · Слабо раскрытые сущности (weak)', data.weak, true, n));
    const pg = data.phrase_gap || { missing: [], weak: [] };
    parts.push(phraseCard('4 · Отсутствующие фразы (n-граммы, missing)', pg.missing, false, n));
    parts.push(phraseCard('5 · Слабо раскрытые фразы (weak)', pg.weak, true, n));
    parts.push(volumeCard(data.volume));
  }

  parts.push(`<button class="btn back" id="backBtn">← Изменить и проанализировать снова</button>`);

  el.result.innerHTML = parts.join('');
  el.result.hidden = false;
  $('#backBtn').addEventListener('click', () => {
    el.result.hidden = true;
    el.inputCard.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $$('table[data-sortable]').forEach(setupSort);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function intentCard(data, profileMode) {
  const dist = (data.intent.distribution || [])
    .map((d) => `<span class="pill ${d.type === data.intent.dominant ? 'dom' : ''}">${esc(d.type)}: <strong>${d.count}</strong></span>`)
    .join('');
  // Mode B: no "my page", so no match verdict — just the topic's dominant intent.
  if (profileMode) {
    return `<div class="card">
      <h2 class="section-title">1 · Интент темы (по конкурентам)</h2>
      <div>Доминирующий тип страниц по теме: <strong>${esc(data.intent.dominant ?? '—')}</strong></div>
      <div class="dist">${dist || '<span class="empty">нет данных</span>'}</div>
      ${data.intent.note ? `<p class="intent-note">${esc(data.intent.note)}</p>` : ''}
    </div>`;
  }
  const intentMatch = data.intent.target_matches_dominant;
  const verdictOk = intentMatch === true;
  return `<div class="card ${verdictOk ? 'intent-ok' : 'intent-bad'}">
    <h2 class="section-title">1 · Интент</h2>
    <p class="intent-verdict ${verdictOk ? 'ok' : 'bad'}">
      ${intentMatch === null ? 'Тип страницы не определён' : verdictOk
        ? '✓ Ваша страница того же типа, что и конкуренты'
        : '✗ Ваша страница ДРУГОГО типа — приоритетная проблема'}
    </p>
    <div>Доминирующий тип: <strong>${esc(data.intent.dominant ?? '—')}</strong>
      &nbsp;·&nbsp; ваш тип: <strong>${esc(data.intent.target_type ?? '—')}</strong></div>
    <div class="dist">${dist || '<span class="empty">нет данных</span>'}</div>
    ${data.intent.note ? `<p class="intent-note">${esc(data.intent.note)}</p>` : ''}
  </div>`;
}

// ─── Mode B: consensus profile tables ─────────────────────────────
function profileEntityCard(title, rows, n) {
  if (!rows.length) {
    return `<div class="card"><h2 class="section-title">${title} <span class="n">— 0</span></h2>
      <p class="empty">Консенсусных сущностей не найдено.</p></div>`;
  }
  const header = `<tr>
    <th data-type="text">Сущность</th>
    <th data-type="text">Тип</th>
    <th data-type="text">В графе</th>
    <th data-type="num" class="num">У конкур.</th>
    <th data-type="num" class="num">Медиана sal.</th>
    <th data-type="prio">Приоритет</th>
    <th data-type="text">Что покрыть</th>
  </tr>`;
  const body = rows.map((r) => {
    const name = r.wikipedia_url
      ? `<a href="${esc(r.wikipedia_url)}" target="_blank" rel="noopener">${esc(r.name)}</a>`
      : esc(r.name);
    const mid = r.mid ? `<span class="mid-yes" title="${esc(r.mid)}">✓ да</span>` : '<span class="mid-no">нет</span>';
    return `<tr data-prio="${r.priority}">
      <td class="ent-name">${name}</td>
      <td><span class="tag">${esc(r.type)}</span></td>
      <td>${mid}</td>
      <td class="num">${r.coverage} из ${r.competitors_total ?? n}</td>
      <td class="num">${fmt(r.median_salience)}</td>
      <td><span class="prio ${r.priority}">${r.priority}</span></td>
      <td>${esc(r.recommendation) || '<span class="empty">—</span>'}</td>
    </tr>`;
  }).join('');
  return `<div class="card"><h2 class="section-title">${title} <span class="n">— ${rows.length}</span></h2>
    <p class="intent-note">Консенсусный профиль: что тема раскрывает у конкурентов. Ранжировано по обязательности (coverage + центральность).</p>
    <div class="table-scroll"><table data-sortable><thead>${header}</thead><tbody>${body}</tbody></table></div></div>`;
}

function profilePhraseCard(title, rows, n) {
  if (!rows.length) {
    return `<div class="card"><h2 class="section-title">${title} <span class="n">— 0</span></h2>
      <p class="empty">Консенсусных фраз не найдено.</p></div>`;
  }
  const header = `<tr>
    <th data-type="text">Фраза</th>
    <th data-type="num" class="num">n</th>
    <th data-type="num" class="num">У конкур.</th>
    <th data-type="num" class="num">Медиана плотн.</th>
    <th data-type="prio">Приоритет</th>
    <th data-type="text">Что покрыть</th>
  </tr>`;
  const body = rows.map((r) => `<tr data-prio="${r.priority}">
    <td class="ent-name">${esc(r.phrase)}</td>
    <td class="num">${r.n}</td>
    <td class="num">${r.coverage} из ${r.competitors_total ?? n}</td>
    <td class="num">${fmtPct(r.median_density)}</td>
    <td><span class="prio ${r.priority}">${r.priority}</span></td>
    <td>${esc(r.recommendation) || '<span class="empty">—</span>'}</td>
  </tr>`).join('');
  return `<div class="card"><h2 class="section-title">${title} <span class="n">— ${rows.length}</span></h2>
    <p class="intent-note">Отдельный трек: буквальные слова и фразы (n-граммы), считается в коде. Не сводится с треком сущностей.</p>
    <div class="table-scroll"><table data-sortable><thead>${header}</thead><tbody>${body}</tbody></table></div></div>`;
}

function volumeProfileCard(v) {
  const parts = (v.competitor_words || []).join(', ');
  const stat = (label, med, dist, fmtFn) => `<div class="vol-stat">
    <span class="lbl2">${label}</span>
    <span>медиана: <strong>${fmtFn(med)}</strong>${dist ? ` · по конкур.: ${dist.map(fmtFn).join(', ')}` : ''}</span>
  </div>`;
  return `<div class="card"><h2 class="section-title">4 · Объём и плотность (профиль конкурентов)</h2>
    <p class="intent-note">Ориентир по теме: медиана и распределение по конкурентам (без сравнения с вашей страницей).</p>
    <div class="vol-stats">
      ${stat('Слов', v.median_competitor_words, v.competitor_words, (x) => x)}
      ${typeof v.median_competitor_sentences === 'number' ? stat('Предложений', v.median_competitor_sentences, v.competitor_sentences, (x) => x) : ''}
      ${typeof v.median_competitor_lexical_density === 'number' ? stat('Лексическая плотность', v.median_competitor_lexical_density, v.competitor_lexical_density, fmtPct) : ''}
    </div>
    <p class="intent-note">Слова по конкурентам: ${parts}</p>
  </div>`;
}

function entityCard(title, rows, isWeak, n) {
  if (!rows.length) {
    return `<div class="card"><h2 class="section-title">${title} <span class="n">— 0</span></h2>
      <p class="empty">${isWeak ? 'Слабо раскрытых сущностей нет — хорошо.' : 'Пробелов не найдено — вы покрываете консенсус конкурентов.'}</p></div>`;
  }
  const header = `<tr>
    <th data-type="text">Сущность</th>
    <th data-type="text">Тип</th>
    <th data-type="text">В графе</th>
    <th data-type="num" class="num">У конкур.</th>
    <th data-type="num" class="num">Медиана sal.</th>
    <th data-type="num" class="num">Ваша sal.</th>
    <th data-type="prio">Приоритет</th>
    <th data-type="text">Рекомендация</th>
  </tr>`;
  const body = rows.map((r) => rowHtml(r, n)).join('');
  return `<div class="card"><h2 class="section-title">${title} <span class="n">— ${rows.length}</span></h2>
    <div class="table-scroll"><table data-sortable><thead>${header}</thead><tbody>${body}</tbody></table></div></div>`;
}

function rowHtml(r, n) {
  const name = r.wikipedia_url
    ? `<a href="${esc(r.wikipedia_url)}" target="_blank" rel="noopener">${esc(r.name)}</a>`
    : esc(r.name);
  const mid = r.mid
    ? `<span class="mid-yes" title="${esc(r.mid)}">✓ да</span>`
    : '<span class="mid-no">нет</span>';
  return `<tr data-prio="${r.priority}" data-score="${r.coverage}">
    <td class="ent-name">${name}</td>
    <td><span class="tag">${esc(r.type)}</span></td>
    <td>${mid}</td>
    <td class="num">${r.coverage} из ${r.competitors_total ?? n}</td>
    <td class="num">${fmt(r.median_competitor_salience)}</td>
    <td class="num">${r.target_salience === null || r.target_salience === undefined ? '—' : fmt(r.target_salience)}</td>
    <td><span class="prio ${r.priority}">${r.priority}</span></td>
    <td>${esc(r.recommendation) || '<span class="empty">—</span>'}</td>
  </tr>`;
}

const fmt = (v) => (typeof v === 'number' ? v.toFixed(3) : '—');
// density is a share of tokens — show as a percentage; null → dash
const fmtPct = (v) => (typeof v === 'number' ? `${(v * 100).toFixed(2)}%` : '—');

// ─── Phrase gap (separate n-gram track) ───────────────────────────
function phraseCard(title, rows, isWeak, n) {
  if (!rows || !rows.length) {
    return `<div class="card"><h2 class="section-title">${title} <span class="n">— 0</span></h2>
      <p class="empty">${isWeak ? 'Слабо раскрытых фраз нет.' : 'Фразовых пробелов не найдено — вы покрываете фразовый консенсус конкурентов.'}</p></div>`;
  }
  const header = `<tr>
    <th data-type="text">Фраза</th>
    <th data-type="num" class="num">n</th>
    <th data-type="num" class="num">У конкур.</th>
    <th data-type="num" class="num">Медиана плотн.</th>
    <th data-type="num" class="num">Ваша плотн.</th>
    <th data-type="prio">Приоритет</th>
    <th data-type="text">Рекомендация</th>
  </tr>`;
  const body = rows.map((r) => phraseRowHtml(r, n)).join('');
  return `<div class="card"><h2 class="section-title">${title} <span class="n">— ${rows.length}</span></h2>
    <p class="intent-note">Отдельный трек: буквальные слова и фразы (n-граммы), считается в коде без NL API. Не сводится с треком сущностей.</p>
    <div class="table-scroll"><table data-sortable><thead>${header}</thead><tbody>${body}</tbody></table></div></div>`;
}

function phraseRowHtml(r, n) {
  return `<tr data-prio="${r.priority}" data-score="${r.coverage}">
    <td class="ent-name">${esc(r.phrase)}</td>
    <td class="num">${r.n}</td>
    <td class="num">${r.coverage} из ${r.competitors_total ?? n}</td>
    <td class="num">${fmtPct(r.median_density)}</td>
    <td class="num">${r.target_density === null || r.target_density === undefined ? '—' : fmtPct(r.target_density)}</td>
    <td><span class="prio ${r.priority}">${r.priority}</span></td>
    <td>${esc(r.recommendation) || '<span class="empty">—</span>'}</td>
  </tr>`;
}

function volumeCard(v) {
  const max = Math.max(v.target_words, v.median_competitor_words, 1);
  const bar = (label, val, cls) => `<div class="vol-bar">
    <span class="lbl2">${label}</span>
    <div class="vol-track"><div class="vol-fill ${cls}" style="width:${Math.max(
    6,
    (val / max) * 100
  )}%">${val}</div></div></div>`;

  // secondary metrics (§8.2): sentences + lexical density, shown as comparison
  const hasSentences = typeof v.sentences === 'number';
  const hasLexical = typeof v.lexical_density === 'number';
  const stat = (label, target, med, fmtFn) => `<div class="vol-stat">
    <span class="lbl2">${label}</span>
    <span>вы: <strong>${fmtFn(target)}</strong> · медиана конкур.: <strong>${fmtFn(med)}</strong></span>
  </div>`;

  return `<div class="card"><h2 class="section-title">6 · Объём и плотность</h2>
    <div class="vol-bars">
      ${bar('Ваша страница (слов)', v.target_words, '')}
      ${bar('Медиана конкурентов (слов)', v.median_competitor_words, 'competitor')}
    </div>
    <p class="intent-note">Конкуренты по отдельности: ${v.competitor_words.join(', ')}</p>
    <div class="vol-stats">
      ${hasSentences ? stat('Предложений', v.sentences, v.median_competitor_sentences, (x) => x) : ''}
      ${hasLexical ? stat('Лексическая плотность (уник./всего)', v.lexical_density, v.median_competitor_lexical_density, fmtPct) : ''}
    </div>
  </div>`;
}

// ─── Table sorting (generic — works for entity and phrase tables) ──
const PRIO_RANK = { high: 3, medium: 2, low: 1 };
function setupSort(table) {
  const tbody = $('tbody', table);
  $$('th', table).forEach((th) => {
    th.addEventListener('click', () => {
      const idx = th.cellIndex;
      const type = th.dataset.type || 'text';
      const asc = th.dataset.dir !== 'asc';
      $$('th', table).forEach((h) => delete h.dataset.dir);
      th.dataset.dir = asc ? 'asc' : 'desc';
      const rows = $$('tr', tbody);
      rows.sort((a, b) => cmpCells(a, b, idx, type) * (asc ? 1 : -1));
      rows.forEach((r) => tbody.appendChild(r));
    });
  });
}
function cmpCells(a, b, idx, type) {
  if (type === 'prio') return (PRIO_RANK[a.dataset.prio] || 0) - (PRIO_RANK[b.dataset.prio] || 0);
  const av = a.children[idx]?.textContent.trim() ?? '';
  const bv = b.children[idx]?.textContent.trim() ?? '';
  if (type === 'num') {
    const x = parseFloat(av);
    const y = parseFloat(bv);
    return (Number.isFinite(x) ? x : -1) - (Number.isFinite(y) ? y : -1);
  }
  return cmp(av.toLowerCase(), bv.toLowerCase());
}
const cmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

// ─── API keys ─────────────────────────────────────────────────────
const SOURCE_LABEL = { runtime: 'сохранён здесь', env: 'из .env', none: '' };

function renderKeyStatus(keys) {
  if (!keys) return;
  const one = (k, statusEl) => {
    if (k.set) {
      statusEl.textContent = `— задан (${SOURCE_LABEL[k.source] || k.source}: ${k.masked})`;
      statusEl.className = 'key-ok';
    } else {
      statusEl.textContent = '— не задан → mock';
      statusEl.className = 'key-off';
    }
  };
  one(keys.google, el.googleStatus);
  one(keys.openai, el.openaiStatus);
  const live = [keys.google.set && 'Google NL', keys.openai.set && 'OpenAI'].filter(Boolean);
  el.keysSummary.textContent = live.length ? `— активны: ${live.join(', ')}` : '— оба в mock-режиме';
}

function setMsg(elm, res) {
  elm.textContent = res.message || '';
  elm.className = 'key-msg ' + (res.ok ? 'key-ok' : 'key-off');
}

async function testKey(provider, input, msgEl, btn) {
  btn.disabled = true;
  msgEl.textContent = 'Проверяем…';
  msgEl.className = 'key-msg';
  try {
    const res = await fetch('/api/test-key', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ provider, key: input.value.trim() || undefined }),
    });
    setMsg(msgEl, await res.json());
  } catch (err) {
    setMsg(msgEl, { ok: false, message: err.message });
  } finally {
    btn.disabled = false;
  }
}

async function saveKeys() {
  el.saveKeys.disabled = true;
  el.keysSaved.textContent = 'Сохраняем…';
  // Only send fields the user actually typed into (keeps existing keys intact).
  const payload = {};
  if (el.googleKey.value.trim()) payload.google = el.googleKey.value.trim();
  if (el.openaiKey.value.trim()) payload.openai = el.openaiKey.value.trim();
  try {
    const res = await fetch('/api/keys', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    renderKeyStatus(data.keys);
    el.googleKey.value = '';
    el.openaiKey.value = '';
    el.keysSaved.textContent = data.persisted
      ? 'Сохранено. Ключи применены.'
      : 'Применено (без записи на диск — задайте CONTENT_DATA_DIR для персистентности).';
    // refresh mock badge
    refreshHealth();
  } catch (err) {
    el.keysSaved.textContent = 'Ошибка сохранения: ' + err.message;
  } finally {
    el.saveKeys.disabled = false;
  }
}

function refreshHealth() {
  return fetch('/api/health')
    .then((r) => r.json())
    .then((h) => {
      MAX_COMPETITORS = h.max_competitors || 10;
      el.mockBadge.hidden = !(h.mock_mode || h.nl_mock || h.openai_mock);
      renderKeyStatus(h.keys);
      refreshComps();
    })
    .catch(() => {});
}

el.keysToggle.addEventListener('click', () => {
  const open = el.keysBody.hidden;
  el.keysBody.hidden = !open;
  el.keysToggle.setAttribute('aria-expanded', String(open));
  el.keysChevron.textContent = open ? '▾' : '▸';
});
el.testGoogle.addEventListener('click', () => testKey('google', el.googleKey, el.googleMsg, el.testGoogle));
el.testOpenai.addEventListener('click', () => testKey('openai', el.openaiKey, el.openaiMsg, el.testOpenai));
el.saveKeys.addEventListener('click', saveKeys);

// ─── Init ─────────────────────────────────────────────────────────
el.addComp.addEventListener('click', addCompetitor);
el.query.addEventListener('input', validate);
el.target.addEventListener('input', validate);
// switching mode must not lose already-typed competitor text (§8)
$$('input[name="mode"]').forEach((r) => r.addEventListener('change', applyMode));

refreshHealth();
applyMode();
addCompetitor();
