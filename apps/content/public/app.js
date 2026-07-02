'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

let MAX_COMPETITORS = 10;

const el = {
  form: $('#analyzeForm'),
  query: $('#query'),
  target: $('#target'),
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
  parts.push(`<div class="meta-row">
    <span class="pill">Запрос: <strong>${esc(data.query)}</strong></span>
    <span class="pill">Конкурентов: <strong>${data.competitors_analyzed}</strong>${
    data.competitors_failed ? ` (пропущено ${data.competitors_failed})` : ''
  }</span>
    <span class="pill">Порог консенсуса K = <strong>${data.consensus_threshold}</strong></span>
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

  // 2. Missing
  parts.push(entityCard('2 · Отсутствующие сущности (missing)', data.missing, false, data.competitors_analyzed));
  // 3. Weak
  parts.push(entityCard('3 · Слабо раскрытые сущности (weak)', data.weak, true, data.competitors_analyzed));

  // 4. Volume
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
    <th data-key="name">Сущность</th>
    <th data-key="type">Тип</th>
    <th data-key="mid">В графе</th>
    <th data-key="coverage" class="num">У конкур.</th>
    <th data-key="median_competitor_salience" class="num">Медиана sal.</th>
    <th data-key="target_salience" class="num">Ваша sal.</th>
    <th data-key="priority">Приоритет</th>
    <th data-key="recommendation">Рекомендация</th>
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

function volumeCard(v) {
  const max = Math.max(v.target_words, v.median_competitor_words, 1);
  const bar = (label, val, cls) => `<div class="vol-bar">
    <span class="lbl2">${label}</span>
    <div class="vol-track"><div class="vol-fill ${cls}" style="width:${Math.max(
    6,
    (val / max) * 100
  )}%">${val}</div></div></div>`;
  return `<div class="card"><h2 class="section-title">4 · Объём (слов)</h2>
    <div class="vol-bars">
      ${bar('Ваша страница', v.target_words, '')}
      ${bar('Медиана конкурентов', v.median_competitor_words, 'competitor')}
    </div>
    <p class="intent-note">Конкуренты по отдельности: ${v.competitor_words.join(', ')}</p>
  </div>`;
}

// ─── Table sorting ────────────────────────────────────────────────
const PRIO_RANK = { high: 3, medium: 2, low: 1 };
function setupSort(table) {
  const tbody = $('tbody', table);
  $$('th', table).forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.dataset.key;
      const asc = th.dataset.dir !== 'asc';
      $$('th', table).forEach((h) => delete h.dataset.dir);
      th.dataset.dir = asc ? 'asc' : 'desc';
      const rows = $$('tr', tbody);
      rows.sort((a, b) => cmp(cellVal(a, key), cellVal(b, key)) * (asc ? 1 : -1));
      rows.forEach((r) => tbody.appendChild(r));
    });
  });
}
function cellVal(tr, key) {
  if (key === 'priority') return PRIO_RANK[tr.dataset.prio] || 0;
  const idx = { name: 0, type: 1, mid: 2, coverage: 3, median_competitor_salience: 4, target_salience: 5, recommendation: 7 }[key];
  const txt = tr.children[idx]?.textContent.trim() ?? '';
  if (['coverage', 'median_competitor_salience', 'target_salience'].includes(key)) {
    const num = parseFloat(txt);
    return Number.isFinite(num) ? num : -1;
  }
  return txt.toLowerCase();
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
