// OpenAI wrapper — intent classification + per-entity recommendations, using
// Structured Outputs (strict json_schema). TZ §6.4. The model NEVER computes or
// overrides numbers; it only classifies intent and writes recommendation text
// for entities that are already in the provided missing/weak lists.
// Falls back to a deterministic mock when no key is configured / MOCK_MODE.

import { config } from '../config.js';
import { withRetry } from '../util/retry.js';

const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

const RESPONSE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['intent', 'recommendations'],
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
    recommendations: {
      type: 'object',
      additionalProperties: false,
      required: ['missing', 'weak'],
      properties: {
        missing: {
          type: 'array',
          items: {
            type: 'object',
            additionalProperties: false,
            required: ['name', 'recommendation'],
            properties: { name: { type: 'string' }, recommendation: { type: 'string' } },
          },
        },
        weak: {
          type: 'array',
          items: {
            type: 'object',
            additionalProperties: false,
            required: ['name', 'recommendation'],
            properties: { name: { type: 'string' }, recommendation: { type: 'string' } },
          },
        },
      },
    },
  },
};

const SYSTEM_PROMPT = [
  'Ты SEO-аналитик. Тебе дают поисковый запрос, тексты страниц конкурентов,',
  'профиль сущностей страницы пользователя и ГОТОВЫЕ списки missing/weak сущностей с метриками.',
  'Твои задачи строго две:',
  '1) Классифицировать тип страницы каждого конкурента и вывести доминирующий интент;',
  '   определить тип страницы пользователя и совпадает ли он с доминирующим.',
  '2) Написать краткую (1-2 предложения) практическую рекомендацию к КАЖДОЙ сущности из',
  '   переданных списков missing и weak — как и где раскрыть её на странице.',
  'ЗАПРЕЩЕНО: добавлять сущности, которых нет во входных списках; менять числа',
  '(coverage, salience, priority) — они уже посчитаны и авторитетны. Пиши на языке запроса.',
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
      median_competitor_salience: m.median_competitor_salience,
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
  });
}

async function callReal(input) {
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
        { role: 'system', content: SYSTEM_PROMPT },
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
 * @returns {Promise<{intent:object, recommendations:{missing:[],weak:[]}}>}
 */
export async function classifyAndRecommend(input) {
  if (config.openai.mock) return mockLLM(input);
  return withRetry(() => callReal(input), {
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

function mockLLM(input) {
  const types = input.competitorTexts.map(guessType);
  const counts = new Map();
  for (const ty of types) counts.set(ty, (counts.get(ty) || 0) + 1);
  const distribution = [...counts.entries()]
    .map(([type, count]) => ({ type, count }))
    .sort((a, b) => b.count - a.count || a.type.localeCompare(b.type));
  const dominant = distribution[0]?.type || 'informational';
  const targetType = guessType(input.targetProfile.map((e) => e.name).join(' '));

  const rec = (m, kind) => ({
    name: m.name,
    recommendation:
      kind === 'missing'
        ? `[MOCK] Добавьте раздел про «${m.name}» — сущность раскрыта у ${m.coverage}/${m.competitors_total} конкурентов, но отсутствует у вас.`
        : `[MOCK] Усильте «${m.name}»: у вас salience ${m.target_salience}, у конкурентов медиана ${m.median_competitor_salience}. Дайте больше контекста.`,
  });

  return {
    intent: {
      dominant,
      distribution,
      target_type: targetType,
      target_matches_dominant: targetType === dominant,
      note: `[MOCK] Доминирующий тип страниц конкурентов — «${dominant}».`,
    },
    recommendations: {
      missing: input.missing.map((m) => rec(m, 'missing')),
      weak: input.weak.map((m) => rec(m, 'weak')),
    },
  };
}
