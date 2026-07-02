// Google Cloud Natural Language API wrapper — analyzeEntities (TZ §6.2).
// One call per document. Language is NOT passed (auto-detection, mixed geo).
// Falls back to a deterministic mock when no key is configured / MOCK_MODE.

import { config } from '../config.js';
import { withRetry } from '../util/retry.js';

const ENDPOINT = 'https://language.googleapis.com/v1/documents:analyzeEntities';

function mapEntities(apiEntities) {
  return (apiEntities || []).map((e) => ({
    name: e.name,
    type: e.type,
    salience: Number(e.salience) || 0,
    mid: e.metadata?.mid || null,
    wikipedia_url: e.metadata?.wikipedia_url || null,
  }));
}

async function callReal(text) {
  const res = await fetch(`${ENDPOINT}?key=${encodeURIComponent(config.google.apiKey)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      document: { type: 'PLAIN_TEXT', content: text },
      encodingType: 'UTF8',
    }),
  });

  if (!res.ok) {
    let detail = '';
    try {
      detail = (await res.json())?.error?.message || '';
    } catch {
      /* ignore */
    }
    const err = new Error(`NL API ${res.status}: ${detail || res.statusText}`);
    err.status = res.status;
    throw err;
  }

  const data = await res.json();
  return { entities: mapEntities(data.entities), language: data.language || null };
}

/**
 * Analyze one document's entities.
 * @returns {Promise<{entities:Array, language:string|null}>}
 */
export async function analyzeEntities(text) {
  if (config.google.mock) return mockAnalyze(text);
  return withRetry(() => callReal(text), {
    maxAttempts: config.retry.maxAttempts,
    baseMs: config.retry.baseMs,
    label: 'NL.analyzeEntities',
  });
}

// ─── Deterministic mock ──────────────────────────────────────────────────────
// Extracts frequent word-tokens as pseudo-entities with frequency-based
// salience. Purely a function of the input text, so results are reproducible.

const STOP = new Set(
  ('the a an and or of to in on for with is are was were be by at as from this that ' +
    'и в на с по за из от до для что как это тот все еще она они оно его ее их но да ' +
    'a об о у же бы ли не ни то так вот при над под без через между')
    .split(/\s+/)
);

function hash(str) {
  let h = 2166136261;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

function mockAnalyze(text) {
  const tokens = (text.toLowerCase().match(/[\p{L}][\p{L}\-']{3,}/gu) || []).filter(
    (t) => !STOP.has(t)
  );
  const freq = new Map();
  for (const t of tokens) freq.set(t, (freq.get(t) || 0) + 1);

  const total = tokens.length || 1;
  const entries = [...freq.entries()]
    .filter(([, c]) => c >= 1)
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .slice(0, 25);

  const entities = entries.map(([word, count]) => {
    const h = hash(word);
    return {
      name: word.charAt(0).toUpperCase() + word.slice(1),
      // rotate a few plausible types deterministically
      type: ['ORGANIZATION', 'CONSUMER_GOOD', 'OTHER', 'LOCATION', 'PERSON', 'EVENT'][h % 6],
      salience: count / total,
      // ~half of entities get a canonical KG mid
      mid: h % 2 === 0 ? `/m/${h.toString(36)}` : null,
      wikipedia_url: h % 2 === 0 ? `https://en.wikipedia.org/wiki/${word}` : null,
    };
  });

  return { entities, language: 'und' };
}
