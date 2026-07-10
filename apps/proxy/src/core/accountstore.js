// Runtime store for the PacketStream account settings and the (gated) Reseller
// token. Lets the operator paste them in the UI instead of hand-editing .env.
// Held in memory (used immediately when the server assembles strings / calls the
// Reseller API) and persisted best-effort to a small JSON file so they survive a
// process restart. Effective value for each field is: runtime override ?? env.
//
// TZ §6: `ps_auth_key` and `reseller_token` are secrets — they are never sent
// back to the client as separate values (only a masked form + status), never
// logged in full, and stored server-side only. The auth key is unavoidably
// embedded inside the assembled connection string (it *is* the credential), but
// that is the only place it surfaces.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// In Docker set PROXY_DATA_DIR=/data/proxy (mounted volume) so settings survive
// image rebuilds; locally defaults to apps/proxy/.data (gitignored). If the path
// is not writable we keep runtime-only overrides.
const DATA_DIR = process.env.PROXY_DATA_DIR || path.join(__dirname, '..', '..', '.data');
const FILE = path.join(DATA_DIR, 'account.json');

const store = { username: '', authKey: '', resellerToken: '' };

function load() {
  try {
    const j = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    if (typeof j.username === 'string') store.username = j.username;
    if (typeof j.authKey === 'string') store.authKey = j.authKey;
    if (typeof j.resellerToken === 'string') store.resellerToken = j.resellerToken;
  } catch {
    /* no file yet / unreadable — start with empty overrides */
  }
}
load();

function persist() {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(FILE, JSON.stringify(store), { mode: 0o600 });
    return true;
  } catch {
    return false; // read-only fs (e.g. no volume) — runtime override still works
  }
}

/** Effective account (override ?? env). Used server-side to build strings/test. */
export function getAccount() {
  return {
    username: store.username || process.env.PS_USERNAME || '',
    authKey: store.authKey || process.env.PS_AUTH_KEY || '',
    resellerToken: store.resellerToken || process.env.RESELLER_TOKEN || '',
  };
}

/** True when username + auth key are both present (a string can be assembled). */
export function isConfigured() {
  const a = getAccount();
  return !!(a.username && a.authKey);
}

/**
 * Set/clear runtime overrides. A field is applied only when present:
 *   string (non-empty) → set;  "" → clear (fall back to env).
 * Fields left `undefined` are untouched (so the UI can save just the username
 * without re-sending the secret it never received back).
 * @returns {{persisted:boolean}}
 */
export function setAccount({ username, authKey, resellerToken } = {}) {
  if (username !== undefined) store.username = typeof username === 'string' ? username.trim() : '';
  if (authKey !== undefined) store.authKey = typeof authKey === 'string' ? authKey.trim() : '';
  if (resellerToken !== undefined)
    store.resellerToken = typeof resellerToken === 'string' ? resellerToken.trim() : '';
  return { persisted: persist() };
}

// Mask a secret for display: keep a few head/tail chars, hide the middle.
export function mask(v) {
  if (!v) return null;
  if (v.length <= 8) return '••••';
  return `${v.slice(0, 3)}••••${v.slice(-4)}`;
}

/** Client-safe status. Username is not secret (shown in full); secrets masked. */
export function accountStatus() {
  const envUser = process.env.PS_USERNAME || '';
  const envKey = process.env.PS_AUTH_KEY || '';
  const envTok = process.env.RESELLER_TOKEN || '';
  const username = store.username || envUser;
  const authKey = store.authKey || envKey;
  const resellerToken = store.resellerToken || envTok;
  const src = (runtime, env) => (runtime ? 'runtime' : env ? 'env' : 'none');
  return {
    username: { set: !!username, source: src(store.username, envUser), value: username || null },
    auth_key: { set: !!authKey, source: src(store.authKey, envKey), masked: mask(authKey) },
    reseller_token: {
      set: !!resellerToken,
      source: src(store.resellerToken, envTok),
      masked: mask(resellerToken),
    },
    configured: !!(username && authKey),
    reseller_configured: !!resellerToken,
  };
}
