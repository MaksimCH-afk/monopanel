// Centralized configuration. Every threshold from TZ §8 lives here — no magic
// numbers scattered through the logic. Values come from the environment with
// sane defaults so the app runs out of the box. API keys additionally support a
// runtime override set from the UI (see core/keystore.js).

import { getGoogleKey, getOpenAIKey } from './core/keystore.js';

function num(name, def) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return def;
  const v = Number(raw);
  return Number.isFinite(v) ? v : def;
}

function bool(name, def) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return def;
  return /^(1|true|yes|on)$/i.test(raw.trim());
}

function str(name, def) {
  const raw = process.env[name];
  return raw === undefined || raw === '' ? def : raw;
}

const globalMock = bool('MOCK_MODE', false);

export const config = {
  port: num('PORT', 3340),

  google: {
    // effective key: runtime override (set via the UI) falls back to env
    get apiKey() {
      return getGoogleKey() || str('GOOGLE_NL_API_KEY', '');
    },
    // per-service mock: forced on globally, or when the key is absent
    get mock() {
      return globalMock || !this.apiKey;
    },
  },

  openai: {
    get apiKey() {
      return getOpenAIKey() || str('OPENAI_API_KEY', '');
    },
    model: str('OPENAI_MODEL', 'gpt-4o-mini'),
    temperature: num('OPENAI_TEMPERATURE', 0.1),
    get mock() {
      return globalMock || !this.apiKey;
    },
  },

  mockMode: globalMock,

  // Analysis thresholds
  maxCompetitors: num('MAX_COMPETITORS', 10),
  consensusThresholdRatio: num('CONSENSUS_THRESHOLD_RATIO', 0.5),
  weakMargin: num('WEAK_MARGIN', 0.4),
  salienceMin: num('SALIENCE_MIN', 0.005),
  // Safeguard cap against the NL API's hard document-size limit (1M Unicode
  // chars for analyzeEntities). High by design so realistic pages never hit it.
  maxDocChars: num('MAX_DOC_CHARS', 1000000),
  llmTextChars: num('LLM_TEXT_CHARS', 4000),

  // Priority scoring (entity track)
  priority: {
    wCoverage: num('PRIORITY_W_COVERAGE', 0.5),
    wMid: num('PRIORITY_W_MID', 0.2),
    wGap: num('PRIORITY_W_GAP', 0.3),
    high: num('PRIORITY_HIGH', 0.66),
    medium: num('PRIORITY_MEDIUM', 0.4),
  },

  // ── Phrase track (code-only n-gram layer, TZ §5-§7) ──────────────────────
  // n-gram generation and filtering
  ngram: {
    max: num('NGRAM_MAX', 4), // levels n = 1..max (trigrams n=3 are mandatory)
    minCount: num('NGRAM_MIN_COUNT', 2), // drop hapax phrases (< this many hits)
    topN: num('NGRAM_TOP_N', 100), // cap kept phrases per n-level per document
  },
  // density floor for phrases — analogue of salienceMin for entities
  phraseSalienceMin: num('PHRASE_SALIENCE_MIN', 0.0005),

  // Phrase priority: no `mid` component (phrases never have one), so weights are
  // rebalanced. A small specificity bonus rewards longer, more specific phrases.
  phrasePriority: {
    wCoverage: num('PHRASE_PRIORITY_W_COVERAGE', 0.6),
    wGap: num('PHRASE_PRIORITY_W_GAP', 0.4),
    specificity: num('PHRASE_SPECIFICITY_BONUS', 0.05), // per n above bigram
    high: num('PHRASE_PRIORITY_HIGH', 0.66),
    medium: num('PHRASE_PRIORITY_MEDIUM', 0.4),
  },

  // Language detection + multilingual stopword filtering
  langDetect: bool('LANG_DETECT', true),
  stopwords: {
    enabled: bool('STOPWORDS_ENABLED', true),
    // operator-supplied brand/boilerplate stop terms (env; also per-request)
    custom: str('CUSTOM_STOPWORDS', ''),
  },

  // Retry / backoff
  retry: {
    maxAttempts: num('RETRY_MAX_ATTEMPTS', 4),
    baseMs: num('RETRY_BASE_MS', 500),
  },
};

export default config;
