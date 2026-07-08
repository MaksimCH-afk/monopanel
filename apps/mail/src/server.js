// Express server: serves the static frontend and a small JSON API that reads
// mail out of Cloudflare D1 (see services/d1.js). The Cloudflare API token stays
// on the server — the client only ever gets masked status and the parsed rows.

import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config } from './config.js';
import { setCloudflare, cloudflareStatus } from './store/configstore.js';
import * as repo from './services/mailbox.js';
import { ping as d1Ping, D1Error } from './services/d1.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

const app = express();
app.use(express.json({ limit: '1mb' }));

// Wrap async handlers so a rejected promise becomes a clean 4xx/5xx instead of
// an unhandled rejection.
const wrap = (fn) => (req, res) => fn(req, res).catch((err) => {
  if (err instanceof D1Error) return res.status(err.status).json({ error: err.message });
  console.error('[mail] unexpected error:', err);
  res.status(500).json({ error: 'Внутренняя ошибка сервера.' });
});

// Health/status — reports mock vs live and the masked Cloudflare config.
app.get('/api/health', (_req, res) => {
  res.json({
    ok: true,
    mock_mode: config.mockMode,
    mail_domain: config.mailDomain,
    cloudflare: cloudflareStatus(),
  });
});

// Save/clear Cloudflare credentials from the UI (applied immediately + persisted).
app.post('/api/config', (req, res) => {
  const b = req.body || {};
  const { persisted } = setCloudflare({
    accountId: b.accountId,
    databaseId: b.databaseId,
    token: b.token,
  });
  res.json({ ok: true, persisted, mock_mode: config.mockMode, cloudflare: cloudflareStatus() });
});

// Test a set of credentials (posted, or the currently effective ones) against D1.
app.post('/api/test', wrap(async (req, res) => {
  const b = req.body || {};
  const creds =
    b.accountId || b.databaseId || b.token
      ? {
          accountId: (b.accountId || cloudflareStatus().accountId.value || '').trim(),
          databaseId: (b.databaseId || cloudflareStatus().databaseId.value || '').trim(),
          token: (b.token || config.cloudflare.token || '').trim(),
        }
      : null;
  if (config.mockMode && !creds) {
    return res.json({ ok: false, message: 'MOCK-режим: Cloudflare не настроен — показаны демо-письма.' });
  }
  const stats = await d1Ping(creds);
  res.json({ ok: true, message: `Соединение с D1 успешно. Писем: ${stats.total}, ящиков: ${stats.mailboxes}.`, stats });
}));

// List of mailboxes with counts.
app.get('/api/mailboxes', wrap(async (_req, res) => {
  res.json({ ok: true, mock_mode: config.mockMode, mailboxes: await repo.listMailboxes() });
}));

// Message headers for one mailbox: /api/messages?mailbox=acc37@..&limit=50&search=..
app.get('/api/messages', wrap(async (req, res) => {
  const mailbox = (req.query.mailbox || '').toString().trim();
  if (!mailbox) return res.status(400).json({ error: 'Не указан параметр mailbox.' });
  const messages = await repo.listMessages(mailbox, {
    limit: req.query.limit,
    search: (req.query.search || '').toString(),
  });
  res.json({ ok: true, mailbox, messages });
}));

// One full message by id.
app.get('/api/messages/:id', wrap(async (req, res) => {
  const msg = await repo.getMessage(req.params.id);
  if (!msg) return res.status(404).json({ error: 'Письмо не найдено.' });
  res.json({ ok: true, message: msg });
}));

// Delete one message (no-op in mock mode).
app.delete('/api/messages/:id', wrap(async (req, res) => {
  if (config.mockMode) return res.status(400).json({ error: 'MOCK-режим: удаление недоступно.' });
  const changes = await repo.deleteMessage(req.params.id);
  if (!changes) return res.status(404).json({ error: 'Письмо не найдено.' });
  res.json({ ok: true });
}));

// Temp-mail cleanup: delete messages older than N days (?days=30) or an ISO ts.
app.post('/api/cleanup', wrap(async (req, res) => {
  if (config.mockMode) return res.status(400).json({ error: 'MOCK-режим: очистка недоступна.' });
  const b = req.body || {};
  let ts = typeof b.before === 'string' && b.before ? b.before : null;
  if (!ts) {
    const days = Number(b.days);
    if (!Number.isFinite(days) || days <= 0) {
      return res.status(400).json({ error: 'Укажите days (>0) или before (ISO-8601).' });
    }
    ts = new Date(Date.now() - days * 86_400_000).toISOString();
  }
  const changes = await repo.deleteOlderThan(ts);
  res.json({ ok: true, deleted: changes, before: ts });
}));

app.use(express.static(PUBLIC_DIR));

app.listen(config.port, () => {
  const mode = config.mockMode ? 'MOCK (demo mail, no Cloudflare calls)' : 'LIVE (Cloudflare D1)';
  console.log(`Mail catcher admin → http://localhost:${config.port}  [${mode}]`);
});
