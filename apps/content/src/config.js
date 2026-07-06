// Centralized configuration. Every threshold from TZ §8 lives here — no magic
// numbers scattered through the logic. Values come from the environment with
// sane defaults so the app runs out of the box.

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
    apiKey: str('GOOGLE_NL_API_KEY', ''),
    // per-service mock: forced on globally, or when the key is absent
    get mock() {
      return globalMock || !this.apiKey;
    },
  },

  openai: {
    apiKey: str('OPENAI_API_KEY', ''),
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

  // Priority scoring
  priority: {
    wCoverage: num('PRIORITY_W_COVERAGE', 0.5),
    wMid: num('PRIORITY_W_MID', 0.2),
    wGap: num('PRIORITY_W_GAP', 0.3),
    high: num('PRIORITY_HIGH', 0.66),
    medium: num('PRIORITY_MEDIUM', 0.4),
  },

  // Retry / backoff
  retry: {
    maxAttempts: num('RETRY_MAX_ATTEMPTS', 4),
    baseMs: num('RETRY_BASE_MS', 500),
  },
};

export default config;
