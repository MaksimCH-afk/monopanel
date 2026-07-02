// Express server: serves the static frontend and the /api/analyze endpoint.
// All external API keys stay here on the server (TZ §9); the client never sees
// them and never calls Google/OpenAI directly.

import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config } from './config.js';
import { runAnalysis, ValidationError } from './core/analyze.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

const app = express();
app.use(express.json({ limit: '4mb' }));

// Health/status — reports whether real keys are wired or we're in mock mode.
app.get('/api/health', (_req, res) => {
  res.json({
    ok: true,
    mock_mode: config.mockMode,
    nl_mock: config.google.mock,
    openai_mock: config.openai.mock,
    max_competitors: config.maxCompetitors,
  });
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
});
