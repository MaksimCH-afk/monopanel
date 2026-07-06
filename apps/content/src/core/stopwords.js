// Dynamic multilingual stopword filtering (TZ §7). "Dynamic" = the set is chosen
// automatically per detected language and augmented, never one hardcoded
// English list. Three layers:
//   1. per-language dictionary from stopwords-iso (auto-selected by §4.1),
//   2. structural, language-independent filters (punctuation/numbers/singletons),
//   3. an operator/corpus layer (custom stop terms via env or request).
//
// The governing rule for phrases (§7.2): an n-gram is dropped ONLY when EVERY
// token is a stopword. One meaningful token keeps the whole phrase.

import { createRequire } from 'node:module';

// stopwords-iso ships a JSON main; require it (stable across Node ESM versions).
const require = createRequire(import.meta.url);
const STOPWORDS_ISO = require('stopwords-iso');

const setCache = new Map();

/**
 * Build the active stopword set for a document: the language dictionary (layer 1)
 * merged with operator custom terms (layer 3). Undetermined/unsupported language
 * → dictionary is empty; structural filters and custom terms still apply (§7.1).
 * @param {string|null} iso1  ISO 639-1 code, or null for agnostic mode
 * @param {Iterable<string>} customTerms  already-normalized custom stop terms
 * @returns {Set<string>}
 */
export function buildStopwordSet(iso1, customTerms = []) {
  const custom = [...customTerms];
  // Cache the (larger) dictionary set per language; fold custom terms on top.
  let base = setCache.get(iso1);
  if (!base) {
    const list = (iso1 && STOPWORDS_ISO[iso1]) || [];
    base = new Set(list);
    setCache.set(iso1, base);
  }
  if (custom.length === 0) return base;
  const merged = new Set(base);
  for (const t of custom) merged.add(t);
  return merged;
}

/**
 * Parse a custom stopword string (comma / whitespace / newline separated) into
 * normalized lowercase terms. Used for env CUSTOM_STOPWORDS and the optional
 * per-request field (§7.4).
 * @returns {string[]}
 */
export function parseCustomStopwords(raw) {
  if (!raw || typeof raw !== 'string') return [];
  return raw
    .split(/[\s,;]+/)
    .map((t) => t.trim().toLowerCase())
    .filter(Boolean);
}

// ── Layer 2: structural, language-independent filters (§7.3) ─────────────────
// A single CJK/Thai character can be a full word, so length-based rejection must
// not apply to those scripts. We only reject a *single-character* token when it
// is a cased/alphabetic latin-or-cyrillic letter (nav/initials noise), never a
// CJK ideograph or other complex-script glyph.
const SINGLE_CASED_LETTER = /^[\p{Script=Latin}\p{Script=Cyrillic}\p{Script=Greek}]$/u;
const PURE_NUMBER = /^[\p{Nd}\p{No}.,'’\- ]+$/u;
const HAS_LETTER = /[\p{L}]/u;

/**
 * True when a single token is structural noise (pure number, or a lone latin/
 * cyrillic letter). Called per token; CJK singletons pass through.
 * @returns {boolean}
 */
export function isStructuralNoise(token) {
  if (!token) return true;
  if (!HAS_LETTER.test(token)) return true; // no letters at all → punctuation/number
  if (PURE_NUMBER.test(token)) return true;
  if (token.length === 1 && SINGLE_CASED_LETTER.test(token)) return true;
  return false;
}

/**
 * Decide whether an n-gram (its token array) should be dropped before it reaches
 * the aggregator. Combines §7.2 (all-stopword rule) with §7.3 (structural).
 *   • drop if the phrase is empty,
 *   • drop if every token is a stopword,
 *   • drop if every token is structural noise.
 * A phrase with at least one meaningful, non-stopword token survives.
 * @param {string[]} tokens
 * @param {Set<string>} stopSet
 * @returns {boolean} true = drop
 */
export function isStopgram(tokens, stopSet) {
  if (!tokens || tokens.length === 0) return true;
  let hasMeaningful = false;
  for (const t of tokens) {
    if (isStructuralNoise(t)) continue;
    if (stopSet.has(t)) continue;
    hasMeaningful = true;
    break;
  }
  return !hasMeaningful;
}
