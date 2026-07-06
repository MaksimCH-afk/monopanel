// Express server: serves the static frontend and the /api/analyze endpoint.
// All external API keys stay here on the server (TZ §9); the client never sees
// them and never calls Google/OpenAI directly.

import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config } from './config.js';
import { runAnalysis, ValidationError } from './core/analyze.js';
import { icuSegmentationOk } from './core/segment.js';
import { keyStatus, setKeys, getGoogleKey, getOpenAIKey } from './core/keystore.js';
import { testGoogleKey, testOpenAIKey } from './services/keytest.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

const app = express();
app.use(express.json({ limit: '4mb' }));

// Health/status — reports whether real keys are wired or we're in mock mode,
// plus masked key status for the settings UI.
app.get('/api/health', (_req, res) => {
  res.json({
    ok: true,
    mock_mode: config.mockMode,
    nl_mock: config.google.mock,
    openai_mock: config.openai.mock,
    max_competitors: config.maxCompetitors,
    keys: keyStatus(),
  });
});

// Set/clear runtime API keys from the UI (used immediately + persisted).
app.post('/api/keys', (req, res) => {
  const body = req.body || {};
  const { persisted } = setKeys({ google: body.google, openai: body.openai });
  res.json({ ok: true, persisted, keys: keyStatus() });
});

// Validate a key ("Проверить"). Uses the posted key, else the stored/env one.
app.post('/api/test-key', async (req, res) => {
  const { provider, key } = req.body || {};
  const typed = typeof key === 'string' && key.trim() ? key.trim() : null;
  try {
    if (provider === 'google') {
      const k = typed || getGoogleKey() || process.env.GOOGLE_NL_API_KEY;
      if (!k) return res.json({ ok: false, message: 'Ключ Google NL не указан.' });
      return res.json(await testGoogleKey(k));
    }
    if (provider === 'openai') {
      const k = typed || getOpenAIKey() || process.env.OPENAI_API_KEY;
      if (!k) return res.json({ ok: false, message: 'Ключ OpenAI не указан.' });
      return res.json(await testOpenAIKey(k));
    }
    return res.status(400).json({ ok: false, message: 'Неизвестный провайдер.' });
  } catch (e) {
    res.json({ ok: false, message: `Ошибка проверки: ${e.message}` });
  }
});

app.post('/api/analyze', async (req, res) => {
  const started = Date.now();
  try {
    const result = await runAnalysis(req.body);
    result.elapsed_ms = Date.now() - started;
    res.json(result);
  } catch (err) {
    if (err instanceof ValidationError) {
      return res.status(err.status).json({ error: err.message });
    }
    console.error('[analyze] unexpected error:', err);
    res.status(500).json({ error: 'Внутренняя ошибка анализа. Попробуйте ещё раз.' });
  }
});

app.use(express.static(PUBLIC_DIR));

app.listen(config.port, () => {
  const mode = config.mockMode
    ? 'MOCK (no external calls)'
    : `NL:${config.google.mock ? 'mock' : 'live'} OpenAI:${config.openai.mock ? 'mock' : 'live'}`;
  console.log(`Content Gap Analyzer → http://localhost:${config.port}  [${mode}]`);

  // ICU self-check (TZ §4.5): warn if CJK degrades to per-character segmentation
  // (e.g. a minimal alpine image without full-icu) — the phrase track then loses
  // dictionary segmentation for no-space scripts.
  if (!icuSegmentationOk()) {
    console.warn(
      '[icu] WARNING: Intl.Segmenter did not segment 北京 as one word. ICU data ' +
        'looks incomplete (missing full-icu?) — CJK/Thai phrase analysis will degrade.'
    );
  }
});
