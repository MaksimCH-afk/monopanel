// Density — the critical section (TZ §6). Density is computed identically for
// single words and multi-word phrases and is what the phrase track compares
// pages by (it plays the role of `salience` in the aggregator).
//
//   density(g) = count(g) / N          for an n-gram g of ANY level n
//
// The denominator N (total word tokens of the document) is COMMON to every
// level — this is a deliberate client requirement (§6.3). It makes a word and a
// phrase that always co-occur come out at equal density, and lets a word rise
// above its phrase once it also appears on its own (the overlap rule, §6.2:
// levels are counted independently, tokens are never "consumed" by a phrase).

import { isStopgram } from './stopwords.js';

/** density of an n-gram given its raw count and the document token total N. */
export function density(count, N) {
  return N > 0 ? count / N : 0;
}

/**
 * Turn per-level n-gram counts into filtered phrase units keyed by phrase text,
 * ready for the aggregator (same shape as entity units: {key,name,salience,…}).
 * Filtering order per level (§5.2, §7):
 *   1. stopgram / structural filter (only when enabled),
 *   2. NGRAM_MIN_COUNT — drop hapax phrases,
 *   3. PHRASE_SALIENCE_MIN — density floor,
 *   4. NGRAM_TOP_N — cap the strongest phrases per level (4-grams explode otherwise).
 *
 * @param {{levels:Map<number,Map<string,{tokens:string[],count:number}>>, N:number}} counts
 * @param {object} config
 * @param {Set<string>} stopSet   active stopword set for this document
 * @returns {Map<string,{key:string,name:string,n:number,count:number,salience:number}>}
 */
export function buildPhraseUnits(counts, config, stopSet) {
  const { levels, N } = counts;
  const { minCount, topN } = config.ngram;
  const floor = config.phraseSalienceMin;
  const filterStops = config.stopwords.enabled;

  const out = new Map();
  for (const [n, level] of levels) {
    const kept = [];
    for (const [key, { tokens, count }] of level) {
      if (filterStops && isStopgram(tokens, stopSet)) continue;
      if (count < minCount) continue;
      const sal = density(count, N);
      if (sal < floor) continue;
      kept.push({ key, name: key, n, count, salience: sal });
    }
    // cap per level: strongest by count (then density, then key for determinism)
    kept.sort((a, b) => b.count - a.count || b.salience - a.salience || a.key.localeCompare(b.key));
    for (const u of kept.slice(0, topN)) out.set(u.key, u);
  }
  return out;
}
