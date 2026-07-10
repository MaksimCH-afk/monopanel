// Express server for the Proxy Manager (TZ §5). It is a CONTROL PLANE only: it
// builds, stores and distributes PacketStream configs — traffic never flows
// through it (TZ §1). The only outbound calls it makes are the exit-IP test and
// the Reseller API. All secrets stay server-side (TZ §6): the auth key is
// substituted into strings here and never returned as a separate value.

import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config } from './config.js';
import * as profiles from './core/profilestore.js';
import { getAccount, isConfigured, setAccount, accountStatus } from './core/accountstore.js';
import { buildAllStrings, buildString, FORMATS } from './core/proxystring.js';
import { APP_IDS } from './core/profilestore.js';
import { allow } from './util/ratelimit.js';
import { testProfile, ProxyTestError } from './services/proxytest.js';
import { getBalance, createSubuser, ResellerError } from './services/reseller.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

const app = express();
app.use(express.json({ limit: '1mb' }));

// Serialise a stored profile for the client: attach the assembled strings (with
// the key already woven into the credential) so the UI never needs the key.
function outward(p) {
  const account = getAccount();
  return { ...p, strings: buildAllStrings(p, account, { echoUrl: config.test.echoUrl }) };
}

// ── Health / status ───────────────────────────────────────────────────────
app.get('/api/health', (_req, res) => {
  res.json({
    ok: true,
    app: 'proxy',
    configured: isConfigured(),
    profiles: profiles.list().length,
    apps: APP_IDS,
  });
});

// ── Account settings (secrets in, masked status out) — TZ §6 ────────────────
app.get('/api/proxy/settings', (_req, res) => {
  res.json({ ok: true, account: accountStatus() });
});

app.post('/api/proxy/settings', (req, res) => {
  const b = req.body || {};
  // Only touch a field when the client actually sent a string — a blank/omitted
  // secret must not wipe an already-stored one (the UI never gets it back).
  const patch = {};
  if (typeof b.username === 'string') patch.username = b.username;
  if (typeof b.auth_key === 'string' && b.auth_key.trim() !== '') patch.authKey = b.auth_key;
  if (typeof b.reseller_token === 'string' && b.reseller_token.trim() !== '') patch.resellerToken = b.reseller_token;
  const { persisted } = setAccount(patch);
  res.json({ ok: true, persisted, account: accountStatus() });
});

// ── Profiles CRUD — TZ §5.1 ─────────────────────────────────────────────────
app.get('/api/proxy/profiles', (_req, res) => {
  res.json(profiles.list().map(outward));
});

app.post('/api/proxy/profiles', (req, res) => {
  try {
    const p = profiles.create(req.body || {});
    res.status(201).json(outward(p));
  } catch (e) {
    if (e instanceof profiles.ValidationError) return res.status(e.status).json({ error: e.message });
    console.error('[profiles] create failed:', e.message);
    res.status(500).json({ error: 'internal_error' });
  }
});

app.patch('/api/proxy/profiles/:id', (req, res) => {
  try {
    const p = profiles.update(req.params.id, req.body || {});
    if (!p) return res.status(404).json({ error: 'profile_not_found' });
    res.json(outward(p));
  } catch (e) {
    if (e instanceof profiles.ValidationError) return res.status(e.status).json({ error: e.message });
    console.error('[profiles] update failed:', e.message);
    res.status(500).json({ error: 'internal_error' });
  }
});

app.delete('/api/proxy/profiles/:id', (req, res) => {
  const ok = profiles.remove(req.params.id);
  if (!ok) return res.status(404).json({ error: 'profile_not_found' });
  res.json({ ok: true });
});

// ── Connection string — TZ §5.2 ─────────────────────────────────────────────
app.get('/api/proxy/profiles/:id/string', (req, res) => {
  const p = profiles.get(req.params.id);
  if (!p) return res.status(404).json({ error: 'profile_not_found' });
  const format = FORMATS.includes(req.query.format) ? req.query.format : 'url';
  const value = buildString(p, getAccount(), format, { echoUrl: config.test.echoUrl });
  res.json({ format, value });
});

// ── Distribution: a software pulls its assigned proxy — TZ §5.3 ─────────────
app.get('/api/apps/:appId/proxy', (req, res) => {
  const appId = req.params.appId;
  const p = profiles.getByApp(appId);
  if (!p) return res.status(404).json({ error: `no profile assigned to app '${appId}'` });
  const format = FORMATS.includes(req.query.format) ? req.query.format : 'url';
  const value = buildString(p, getAccount(), format, { echoUrl: config.test.echoUrl });
  res.json({ app: appId, profile_id: p.id, format, value });
});

// ── Exit-IP test — TZ §5.4 ──────────────────────────────────────────────────
app.get('/api/proxy/test', async (req, res) => {
  const id = req.query.id;
  const p = profiles.get(id);
  if (!p) return res.status(404).json({ error: 'profile_not_found' });
  if (!isConfigured()) {
    return res.status(409).json({ error: 'account_not_configured', detail: 'set PacketStream username + auth key first' });
  }

  const wait = allow(`test:${id}`, config.test.rateLimitMs);
  if (wait !== true) {
    return res.status(429).json({ error: 'rate_limited', detail: `retry in ${wait}ms` });
  }

  try {
    const out = await testProfile(p, getAccount(), config.test);
    res.json(out);
  } catch (e) {
    // Any proxy failure → 502 with a human-readable detail, never a 500 (TZ §5.4).
    const detail = e instanceof ProxyTestError ? e.detail : e.message;
    console.error(`[test] profile ${id} failed: ${detail}`);
    res.status(502).json({ error: 'proxy_unreachable', detail });
  }
});

// ── Reseller (balance / subusers) — TZ §5.5, §7 ─────────────────────────────
app.get('/api/reseller/balance', async (_req, res) => {
  try {
    const { resellerToken } = getAccount();
    res.json(await getBalance(resellerToken, config.reseller));
  } catch (e) {
    const status = e instanceof ResellerError ? e.status : 502;
    res.status(status).json({ error: e.message, detail: e.detail });
  }
});

app.post('/api/reseller/subusers', async (req, res) => {
  try {
    const { resellerToken } = getAccount();
    res.json(await createSubuser(resellerToken, config.reseller, req.body || {}));
  } catch (e) {
    const status = e instanceof ResellerError ? e.status : 502;
    res.status(status).json({ error: e.message, detail: e.detail });
  }
});

app.use(express.static(PUBLIC_DIR));

// Export the app for tests; only listen when run directly.
export { app };

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
  app.listen(config.port, () => {
    console.log(
      `Proxy Manager → http://localhost:${config.port}  [account:${isConfigured() ? 'configured' : 'not set'}]`
    );
  });
}
