// Language detection (TZ §4.1). Uses franc-min (Unicode trigram model, no
// network) to guess each document's language. The detected language drives:
//   • the Intl.Segmenter locale (word/sentence segmentation),
//   • the stopwords-iso set,
//   • locale-aware case normalization.
// When the language is undetermined or unmapped we fall back to a
// language-agnostic mode (default segmenter locale, no stopword dictionary) —
// the pipeline must keep working, never throw (TZ §4.1, §12).

import { franc } from 'franc-min';
import { config } from '../config.js';

// ISO 639-3 (what franc returns) → { iso1: ISO 639-1 for stopwords-iso,
// locale: BCP-47 for Intl.Segmenter }. Covers the languages franc-min can
// distinguish that also have ICU segmentation / stopword support. Anything not
// here degrades gracefully to the agnostic mode.
const LANG_MAP = {
  eng: { iso1: 'en', locale: 'en' },
  rus: { iso1: 'ru', locale: 'ru' },
  deu: { iso1: 'de', locale: 'de' },
  fra: { iso1: 'fr', locale: 'fr' },
  spa: { iso1: 'es', locale: 'es' },
  por: { iso1: 'pt', locale: 'pt' },
  ita: { iso1: 'it', locale: 'it' },
  nld: { iso1: 'nl', locale: 'nl' },
  pol: { iso1: 'pl', locale: 'pl' },
  ukr: { iso1: 'uk', locale: 'uk' },
  ces: { iso1: 'cs', locale: 'cs' },
  ron: { iso1: 'ro', locale: 'ro' },
  swe: { iso1: 'sv', locale: 'sv' },
  fin: { iso1: 'fi', locale: 'fi' },
  dan: { iso1: 'da', locale: 'da' },
  nob: { iso1: 'no', locale: 'nb' },
  hun: { iso1: 'hu', locale: 'hu' },
  ell: { iso1: 'el', locale: 'el' },
  tur: { iso1: 'tr', locale: 'tr' },
  bul: { iso1: 'bg', locale: 'bg' },
  srp: { iso1: 'sr', locale: 'sr' },
  hrv: { iso1: 'hr', locale: 'hr' },
  slk: { iso1: 'sk', locale: 'sk' },
  slv: { iso1: 'sl', locale: 'sl' },
  lit: { iso1: 'lt', locale: 'lt' },
  lav: { iso1: 'lv', locale: 'lv' },
  est: { iso1: 'et', locale: 'et' },
  arb: { iso1: 'ar', locale: 'ar' },
  heb: { iso1: 'he', locale: 'he' },
  fas: { iso1: 'fa', locale: 'fa' },
  urd: { iso1: 'ur', locale: 'ur' },
  hin: { iso1: 'hi', locale: 'hi' },
  ben: { iso1: 'bn', locale: 'bn' },
  tam: { iso1: 'ta', locale: 'ta' },
  tel: { iso1: 'te', locale: 'te' },
  mar: { iso1: 'mr', locale: 'mr' },
  guj: { iso1: 'gu', locale: 'gu' },
  // no-space scripts: correct locale is critical for dictionary segmentation
  cmn: { iso1: 'zh', locale: 'zh' },
  jpn: { iso1: 'ja', locale: 'ja' },
  kor: { iso1: 'ko', locale: 'ko' },
  tha: { iso1: 'th', locale: 'th' },
  vie: { iso1: 'vi', locale: 'vi' },
  ind: { iso1: 'id', locale: 'id' },
  zsm: { iso1: 'ms', locale: 'ms' },
};

// The neutral fallback: default segmenter locale (undefined → runtime default),
// no stopword dictionary. Structural filters still apply downstream.
export const AGNOSTIC = Object.freeze({ code: 'und', iso1: null, locale: undefined });

/**
 * Detect one document's language.
 * @param {string} text  cleaned document text
 * @returns {{code:string, iso1:string|null, locale:string|undefined}}
 *   `code` is the ISO 639-3 tag ('und' when undetermined/disabled).
 */
export function detectLanguage(text) {
  if (!config.langDetect || !text) return { ...AGNOSTIC };
  // franc needs some length to be reliable; short inputs return 'und'.
  const code = franc(String(text));
  if (!code || code === 'und') return { ...AGNOSTIC };
  const mapped = LANG_MAP[code];
  if (!mapped) return { code, iso1: null, locale: undefined }; // known tag, agnostic handling
  return { code, iso1: mapped.iso1, locale: mapped.locale };
}

/**
 * Dominant language of a set of detected languages (mode; ties broken by first
 * seen). Used for the set-level `language` report field (TZ §10).
 * @param {Array<{code:string}>} langs
 * @returns {string} ISO 639-3 code, or 'und'
 */
export function dominantLanguage(langs) {
  const counts = new Map();
  for (const l of langs) {
    const c = l?.code || 'und';
    if (c === 'und') continue;
    counts.set(c, (counts.get(c) || 0) + 1);
  }
  let best = 'und';
  let bestN = 0;
  for (const [c, n] of counts) {
    if (n > bestN) {
      bestN = n;
      best = c;
    }
  }
  return best;
}
