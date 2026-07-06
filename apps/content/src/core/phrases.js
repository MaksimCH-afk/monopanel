// Per-document phrase profiling: ties segmentation → n-grams → density →
// stopword filtering into one code-only pass (TZ §3, §5, §6), and computes the
// volume metrics that share the same tokenizer (sentences, lexical density §8.2).

import { segmentDocument } from './segment.js';
import { countNgrams } from './ngrams.js';
import { buildPhraseUnits } from './density.js';
import { buildStopwordSet } from './stopwords.js';

/**
 * @param {string} text     cleaned document text
 * @param {{iso1:string|null, locale:string|undefined}} lang  detected language
 * @param {object} config
 * @param {string[]} customTerms  normalized operator stop terms (§7.4)
 * @returns {{
 *   phraseMap: Map,          // filtered phrase units for the aggregator
 *   tokenCount: number,      // N — total word tokens (§6.1)
 *   sentenceCount: number,   // for volume (§8.2)
 *   lexicalDensity: number,  // unique/total tokens (§8.2)
 * }}
 */
export function buildPhraseProfile(text, lang, config, customTerms = []) {
  const { sentences, sentenceTokens, tokens } = segmentDocument(text, lang.locale);
  const counts = countNgrams(sentenceTokens, config.ngram.max);
  const stopSet = buildStopwordSet(lang.iso1, customTerms);
  const phraseMap = buildPhraseUnits(counts, config, stopSet);

  const uniqueTokens = new Set(tokens).size;
  const lexicalDensity = tokens.length ? uniqueTokens / tokens.length : 0;

  return {
    phraseMap,
    tokenCount: counts.N,
    sentenceCount: sentences.length,
    lexicalDensity,
  };
}
