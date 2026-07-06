// Google Cloud Natural Language API wrapper — analyzeEntities (TZ §6.2).
// One call per document. Language is NOT passed (auto-detection, mixed geo).
//
// When no key is configured / MOCK_MODE, and as a fallback when a live NL call
// fails (TZ §8.1), entities are derived IN CODE from a unigram-density profile
// (TZ §4.4) built on the same multilingual tokenizer as the phrase track. This
// upgrades the free/no-API mode from a crude frequency hack to a real, language-
// aware analysis whose salience = density (so it sums to ~1 like NL salience).

import { config } from '../config.js';
import { withRetry } from '../util/retry.js';
import { tokenizeWords } from '../core/segment.js';
import { buildStopwordSet, isStructuralNoise } from '../core/stopwords.js';
import { detectLanguage } from './lang.js';

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
 * Analyze one document's entities via the live NL API.
 * Throws on failure (the caller decides whether to fall back to codeEntities).
 * @returns {Promise<{entities:Array, language:string|null}>}
 */
export async function analyzeEntities(text) {
  if (config.google.mock) return codeEntities(text);
  return withRetry(() => callReal(text), {
    maxAttempts: config.retry.maxAttempts,
    baseMs: config.retry.baseMs,
    label: 'NL.analyzeEntities',
  });
}

// ─── Code-derived entities (free mode + live-failure fallback) ───────────────
const MAX_CODE_ENTITIES = 60;

// Capitalize the first character for display (no-op for caseless scripts).
function displayName(token) {
  return token.charAt(0).toLocaleUpperCase() + token.slice(1);
}

/**
 * Build pseudo-entities from a unigram-density profile (TZ §4.4). Purely a
 * function of the input text → reproducible. Salience = density = count / N,
 * matching the phrase track's word-level density. No KG `mid` (this is not the
 * Knowledge Graph), so downstream priority relies on coverage/gap only.
 * @param {string} text
 * @param {{code:string, iso1:string|null, locale:string|undefined}} [lang]
 * @returns {{entities:Array, language:string|null}}
 */
export function codeEntities(text, lang = detectLanguage(text)) {
  const tokens = tokenizeWords(text, lang.locale);
  const N = tokens.length || 1;
  const stopSet = config.stopwords.enabled ? buildStopwordSet(lang.iso1) : new Set();

  const freq = new Map();
  for (const t of tokens) {
    // single-token entities: drop stopwords and structural noise outright
    if (config.stopwords.enabled && (stopSet.has(t) || isStructuralNoise(t))) continue;
    freq.set(t, (freq.get(t) || 0) + 1);
  }

  const entities = [...freq.entries()]
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .slice(0, MAX_CODE_ENTITIES)
    .map(([token, count]) => ({
      name: displayName(token),
      type: 'OTHER',
      salience: count / N,
      mid: null,
      wikipedia_url: null,
    }));

  return { entities, language: lang.code || null };
}
