// Multilingual segmentation on top of the built-in Intl.Segmenter (ICU, UAX #29)
// — the shared basis for phrases, density and volume (TZ §4.2-§4.3). No external
// tokenizer: ICU gives Unicode-correct word/sentence boundaries, and for
// no-space scripts (Chinese/Japanese/Thai) dictionary segmentation — provided
// the correct locale is passed (北京 stays one word, not 北 + 京).

// Segmenter construction is comparatively costly; cache per locale+granularity.
const cache = new Map();
function segmenter(locale, granularity) {
  const key = `${locale ?? ''}|${granularity}`;
  let seg = cache.get(key);
  if (!seg) {
    seg = new Intl.Segmenter(locale, { granularity });
    cache.set(key, seg);
  }
  return seg;
}

// Token normalization: Unicode NFC + locale-aware lowercase. For scripts without
// case (CJK etc.) toLocaleLowerCase is a no-op — that's expected (TZ §4.2, §12).
function normalizeToken(segment, locale) {
  const nfc = segment.normalize('NFC');
  return locale ? nfc.toLocaleLowerCase(locale) : nfc.toLocaleLowerCase();
}

/**
 * Word-tokenize a single string, keeping only word-like segments (drops
 * whitespace/punctuation).
 * @returns {string[]} normalized tokens
 */
export function tokenizeWords(text, locale) {
  if (!text) return [];
  const out = [];
  for (const s of segmenter(locale, 'word').segment(String(text))) {
    if (s.isWordLike) out.push(normalizeToken(s.segment, locale));
  }
  return out;
}

/**
 * Split text into sentence strings (trimmed, non-empty). Handles non-Latin
 * terminators (e.g. the Chinese period 。) via ICU. (TZ §4.3)
 * @returns {string[]}
 */
export function splitSentences(text, locale) {
  if (!text) return [];
  const out = [];
  for (const s of segmenter(locale, 'sentence').segment(String(text))) {
    const t = s.segment.trim();
    if (t) out.push(t);
  }
  return out;
}

/**
 * Full document segmentation used by the phrase track and volume metrics.
 * Tokens are grouped by sentence so n-grams never cross a sentence boundary
 * (TZ §5.1). `tokens` is the flat unigram stream; its length is N (TZ §6.1).
 * @returns {{sentences:string[], sentenceTokens:string[][], tokens:string[]}}
 */
export function segmentDocument(text, locale) {
  const sentences = splitSentences(text, locale);
  const sentenceTokens = sentences.map((s) => tokenizeWords(s, locale));
  const tokens = sentenceTokens.flat();
  return { sentences, sentenceTokens, tokens };
}

/**
 * Infrastructure self-check (TZ §4.5): confirm ICU does dictionary segmentation
 * for a no-space script. If ICU data is trimmed (e.g. a minimal alpine image
 * without full-icu), CJK degrades to per-character segmentation and this returns
 * false — the caller logs a warning.
 * @returns {boolean}
 */
export function icuSegmentationOk() {
  try {
    const t = tokenizeWords('北京', 'zh');
    return t.length === 1 && t[0] === '北京';
  } catch {
    return false;
  }
}
