'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

let MAX_COMPETITORS = 10;

const el = {
  form: $('#analyzeForm'),
  query: $('#query'),
  target: $('#target'),
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

// ─── Validation ───────────────────────────────────────────────────
function validate() {
  const ok = el.query.value.trim() && el.target.value.trim() && collectCompetitors().length >= 1;
  el.submit.disabled = !ok;
  return ok;
}

// ─── Submit ───────────────────────────────────────────────────────
el.form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validate()) return;
  el.formError.textContent = '';

  const payload = {
    query: el.query.value.trim(),
    target: { label: null, text: el.target.value },
    competitors: collectCompetitors(),
    custom_stopwords: el.customStopwords.value.trim() || undefined,
  };

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
  const intentMatch = data.intent.target_matches_dominant;
  const verdictOk = intentMatch === true;

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

  // 1. Intent
  const dist = (data.intent.distribution || [])
    .map(
      (d) =>
        `<span class="pill ${d.type === data.intent.dominant ? 'dom' : ''}">${esc(
          d.type
        )}: <strong>${d.count}</strong></span>`
    )
    .join('');
  parts.push(`<div class="card ${verdictOk ? 'intent-ok' : 'intent-bad'}">
    <h2 class="section-title">1 · Интент</h2>
    <p class="intent-verdict ${verdictOk ? 'ok' : 'bad'}">
      ${
        intentMatch === null
          ? 'Тип страницы не определён'
          : verdictOk
          ? '✓ Ваша страница того же типа, что и конкуренты'
          : '✗ Ваша страница ДРУГОГО типа — приоритетная проблема'
      }
    </p>
    <div>Доминирующий тип: <strong>${esc(data.intent.dominant ?? '—')}</strong>
      &nbsp;·&nbsp; ваш тип: <strong>${esc(data.intent.target_type ?? '—')}</strong></div>
    <div class="dist">${dist || '<span class="empty">нет данных</span>'}</div>
    ${data.intent.note ? `<p class="intent-note">${esc(data.intent.note)}</p>` : ''}
  </div>`);

  // 2. Missing (entities)
  parts.push(entityCard('2 · Отсутствующие сущности (missing)', data.missing, false, data.competitors_analyzed));
  // 3. Weak (entities)
  parts.push(entityCard('3 · Слабо раскрытые сущности (weak)', data.weak, true, data.competitors_analyzed));

  // 4-5. Phrase gap (separate track — do NOT merge with entities)
  const pg = data.phrase_gap || { missing: [], weak: [] };
  parts.push(phraseCard('4 · Отсутствующие фразы (n-граммы, missing)', pg.missing, false, data.competitors_analyzed));
  parts.push(phraseCard('5 · Слабо раскрытые фразы (weak)', pg.weak, true, data.competitors_analyzed));

  // 6. Volume
  parts.push(volumeCard(data.volume));

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

// ─── Init ─────────────────────────────────────────────────────────
el.addComp.addEventListener('click', addCompetitor);
el.query.addEventListener('input', validate);
el.target.addEventListener('input', validate);

fetch('/api/health')
  .then((r) => r.json())
  .then((h) => {
    MAX_COMPETITORS = h.max_competitors || 10;
    if (h.mock_mode || h.nl_mock || h.openai_mock) el.mockBadge.hidden = false;
    refreshComps();
  })
  .catch(() => {});

addCompetitor();
