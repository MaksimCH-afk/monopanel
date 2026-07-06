// OpenAI wrapper — intent classification + per-entity recommendations, using
// Structured Outputs (strict json_schema). TZ §6.4. The model NEVER computes or
// overrides numbers; it only classifies intent and writes recommendation text
// for entities that are already in the provided missing/weak lists.
// Falls back to a deterministic mock when no key is configured / MOCK_MODE.

import { config } from '../config.js';
import { withRetry } from '../util/retry.js';

const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

// Reusable array-of-{keyField, recommendation} schema fragment.
function recArray(keyField) {
  return {
    type: 'array',
    items: {
      type: 'object',
      additionalProperties: false,
      required: [keyField, 'recommendation'],
      properties: { [keyField]: { type: 'string' }, recommendation: { type: 'string' } },
    },
  };
}

const RESPONSE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['intent', 'recommendations', 'phrase_recommendations'],
  properties: {
    intent: {
      type: 'object',
      additionalProperties: false,
      required: ['dominant', 'distribution', 'target_type', 'target_matches_dominant', 'note'],
      properties: {
        dominant: { type: 'string' },
        distribution: {
          type: 'array',
          items: {
            type: 'object',
            additionalProperties: false,
            required: ['type', 'count'],
            properties: { type: { type: 'string' }, count: { type: 'integer' } },
          },
        },
        target_type: { type: 'string' },
        target_matches_dominant: { type: 'boolean' },
        note: { type: 'string' },
      },
    },
    // recommendations for the ENTITY track (keyed by entity name)
    recommendations: {
      type: 'object',
      additionalProperties: false,
      required: ['missing', 'weak'],
      properties: { missing: recArray('name'), weak: recArray('name') },
    },
    // recommendations for the PHRASE track (keyed by the literal phrase)
    phrase_recommendations: {
      type: 'object',
      additionalProperties: false,
      required: ['missing', 'weak'],
      properties: { missing: recArray('phrase'), weak: recArray('phrase') },
    },
  },
};

// Mode A — "compare": recommendations frame the gap on MY page.
const SYSTEM_PROMPT_COMPARE = [
  'Ты SEO-аналитик. Тебе дают поисковый запрос, тексты страниц конкурентов,',
  'профиль сущностей страницы пользователя и ГОТОВЫЕ списки missing/weak сущностей и фраз с метриками.',
  'Твои задачи строго три:',
  '1) Классифицировать тип страницы каждого конкурента и вывести доминирующий интент;',
  '   определить тип страницы пользователя и совпадает ли он с доминирующим.',
  '2) Написать краткую (1-2 предложения) практическую рекомендацию к КАЖДОЙ сущности из',
  '   переданных списков missing и weak — как и где раскрыть её на странице.',
  '3) Аналогично написать рекомендацию к КАЖДОЙ фразе из phrases.missing и phrases.weak —',
  '   как естественно вписать эту формулировку. Верни их в phrase_recommendations (поле phrase = сама фраза).',
  'ЗАПРЕЩЕНО: добавлять сущности или фразы, которых нет во входных списках; менять числа',
  '(coverage, salience, density, priority) — они уже посчитаны и авторитетны. Пиши на языке запроса.',
].join(' ');

// Mode B — "competitors_only": recommendations are a coverage brief/checklist —
// what a page ON THIS TOPIC should cover. There is no "my page".
const SYSTEM_PROMPT_PROFILE = [
  'Ты SEO-аналитик. Тебе дают поисковый запрос, тексты страниц конкурентов и ГОТОВЫЙ',
  'консенсусный профиль конкурентов: сущности и фразы, которые тема раскрывает у конкурентов,',
  'с метриками. Страницы пользователя НЕТ — это режим брифа «что должна покрывать страница по теме».',
  'Твои задачи строго три:',
  '1) Классифицировать тип страницы каждого конкурента и вывести доминирующий интент темы.',
  '2) Для КАЖДОЙ сущности из списка написать краткую (1-2 предложения) рекомендацию: что именно',
  '   должна раскрыть страница по этой теме (пункт чек-листа охвата), а не «исправь у себя».',
  '3) Аналогично для КАЖДОЙ фразы из phrases — как естественно использовать эту формулировку.',
  '   Верни в phrase_recommendations (поле phrase = сама фраза).',
  'ЗАПРЕЩЕНО: добавлять сущности/фразы вне входных списков; менять числа (coverage, salience,',
  'density, priority). Поля target_matches_dominant/target_type могут быть неопределимы — тогда',
  'ставь разумные значения (например, target совпадает с доминирующим). Пиши на языке запроса.',
].join(' ');

function buildUserPayload(input) {
  return JSON.stringify({
    query: input.query,
    target_type_hint: 'определи по профилю сущностей пользователя',
    target_entity_profile: input.targetProfile,
    competitors: input.competitorTexts.map((t, i) => ({ index: i + 1, text: t })),
    missing: input.missing.map((m) => ({
      name: m.name,
      type: m.type,
      coverage: m.coverage,
      competitors_total: m.competitors_total,
      // compare: median_competitor_salience; profile: median_salience
      median_salience: m.median_competitor_salience ?? m.median_salience,
      priority: m.priority,
    })),
    weak: input.weak.map((w) => ({
      name: w.name,
      type: w.type,
      coverage: w.coverage,
      competitors_total: w.competitors_total,
      median_competitor_salience: w.median_competitor_salience,
      target_salience: w.target_salience,
      priority: w.priority,
    })),
    phrases: {
      missing: (input.phrasesMissing || []).map((p) => ({
        phrase: p.phrase,
        n: p.n,
        coverage: p.coverage,
        competitors_total: p.competitors_total,
        median_density: p.median_density,
        priority: p.priority,
      })),
      weak: (input.phrasesWeak || []).map((p) => ({
        phrase: p.phrase,
        n: p.n,
        coverage: p.coverage,
        competitors_total: p.competitors_total,
        median_density: p.median_density,
        target_density: p.target_density,
        priority: p.priority,
      })),
    },
  });
}

async function callReal(input, systemPrompt) {
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${config.openai.apiKey}`,
    },
    body: JSON.stringify({
      model: config.openai.model,
      temperature: config.openai.temperature,
      messages: [
        { role: 'system', content: systemPrompt },
        { role: 'user', content: buildUserPayload(input) },
      ],
      response_format: {
        type: 'json_schema',
        json_schema: { name: 'content_gap_report', strict: true, schema: RESPONSE_SCHEMA },
      },
    }),
  });

  if (!res.ok) {
    let detail = '';
    try {
      detail = (await res.json())?.error?.message || '';
    } catch {
      /* ignore */
    }
    const err = new Error(`OpenAI ${res.status}: ${detail || res.statusText}`);
    err.status = res.status;
    throw err;
  }

  const data = await res.json();
  const content = data.choices?.[0]?.message?.content;
  if (!content) throw new Error('OpenAI returned empty content');
  return JSON.parse(content);
}

/**
 * @param {object} input  incl. optional `mode`: 'compare' (default) | 'competitors_only'
 * @returns {Promise<{intent:object, recommendations:object, phrase_recommendations:object}>}
 */
export async function classifyAndRecommend(input) {
  const profile = input.mode === 'competitors_only';
  const systemPrompt = profile ? SYSTEM_PROMPT_PROFILE : SYSTEM_PROMPT_COMPARE;
  if (config.openai.mock) return mockLLM(input, profile);
  return withRetry(() => callReal(input, systemPrompt), {
    maxAttempts: config.retry.maxAttempts,
    baseMs: config.retry.baseMs,
    label: 'OpenAI.classifyAndRecommend',
  });
}

// ─── Deterministic mock ──────────────────────────────────────────────────────
function guessType(text) {
  const t = (text || '').toLowerCase();
  if (/(отзыв|review|рейтинг|оцен)/.test(t)) return 'review';
  if (/(бонус|promo|акци|купон|no deposit)/.test(t)) return 'promo/landing';
  if (/(как|guide|инструкц|how to|правил)/.test(t)) return 'guide';
  if (/(купить|цена|price|buy|заказ)/.test(t)) return 'commercial';
  return 'informational';
}

function mockLLM(input, profile = false) {
  const types = input.competitorTexts.map(guessType);
  const counts = new Map();
  for (const ty of types) counts.set(ty, (counts.get(ty) || 0) + 1);
  const distribution = [...counts.entries()]
    .map(([type, count]) => ({ type, count }))
    .sort((a, b) => b.count - a.count || a.type.localeCompare(b.type));
  const dominant = distribution[0]?.type || 'informational';
  const targetType = guessType((input.targetProfile || []).map((e) => e.name).join(' '));

  const medSal = (m) => m.median_competitor_salience ?? m.median_salience;
  const rec = (m, kind) => ({
    name: m.name,
    recommendation: profile
      ? `[MOCK] Раскройте «${m.name}» — тема покрыта у ${m.coverage}/${m.competitors_total} конкурентов (медиана salience ${medSal(m)}). Пункт брифа охвата.`
      : kind === 'missing'
        ? `[MOCK] Добавьте раздел про «${m.name}» — сущность раскрыта у ${m.coverage}/${m.competitors_total} конкурентов, но отсутствует у вас.`
        : `[MOCK] Усильте «${m.name}»: у вас salience ${m.target_salience}, у конкурентов медиана ${m.median_competitor_salience}. Дайте больше контекста.`,
  });

  const precc = (p, kind) => ({
    phrase: p.phrase,
    recommendation: profile
      ? `[MOCK] Используйте фразу «${p.phrase}» (${p.n}-грамма) — встречается у ${p.coverage}/${p.competitors_total} конкурентов (медиана плотности ${p.median_density}).`
      : kind === 'missing'
        ? `[MOCK] Впишите фразу «${p.phrase}» (${p.n}-грамма) — встречается у ${p.coverage}/${p.competitors_total} конкурентов, у вас её нет.`
        : `[MOCK] Используйте «${p.phrase}» плотнее: у конкурентов медиана плотности ${p.median_density}, у вас ${p.target_density}.`,
  });

  return {
    intent: {
      dominant,
      distribution,
      target_type: profile ? dominant : targetType,
      target_matches_dominant: profile ? true : targetType === dominant,
      note: `[MOCK] Доминирующий тип страниц конкурентов — «${dominant}».`,
    },
    recommendations: {
      missing: input.missing.map((m) => rec(m, 'missing')),
      weak: input.weak.map((m) => rec(m, 'weak')),
    },
    phrase_recommendations: {
      missing: (input.phrasesMissing || []).map((p) => precc(p, 'missing')),
      weak: (input.phrasesWeak || []).map((p) => precc(p, 'weak')),
    },
  };
}
