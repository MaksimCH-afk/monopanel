// Runtime store for the Cloudflare credentials (account id, D1 database id, API
// token). Lets the operator paste them in the UI instead of hand-editing .env.
// Held in memory (used immediately by the D1 client) and persisted best-effort
// to a small JSON file so they survive a process restart. The effective value
// for each field is: runtime override ?? env.
//
// The API token is never sent back to the client — only a masked form + status.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// Where to persist. In Docker set MAIL_DATA_DIR=/data/mail (mounted volume) so
// the config survives image rebuilds; locally defaults to apps/mail/.data
// (gitignored). If the path is not writable we keep runtime-only overrides.
const DATA_DIR = process.env.MAIL_DATA_DIR || path.join(__dirname, '..', '..', '.data');
const FILE = path.join(DATA_DIR, 'config.json');

const store = { accountId: '', databaseId: '', token: '' };

function load() {
  try {
    const j = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    if (typeof j.accountId === 'string') store.accountId = j.accountId;
    if (typeof j.databaseId === 'string') store.databaseId = j.databaseId;
    if (typeof j.token === 'string') store.token = j.token;
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

export function getCloudflare() {
  return { ...store };
}

/**
 * Set/clear runtime credential overrides. A field is applied only when present:
 *   string (non-empty) → set;  "" or null → clear (fall back to env).
 * @returns {{persisted:boolean}}
 */
export function setCloudflare({ accountId, databaseId, token } = {}) {
  if (accountId !== undefined) store.accountId = typeof accountId === 'string' ? accountId.trim() : '';
  if (databaseId !== undefined) store.databaseId = typeof databaseId === 'string' ? databaseId.trim() : '';
  if (token !== undefined) store.token = typeof token === 'string' ? token.trim() : '';
  return { persisted: persist() };
}

// Mask a secret for display: keep a few head/tail chars, hide the middle.
export function mask(v) {
  if (!v) return null;
  if (v.length <= 8) return '••••';
  return `${v.slice(0, 3)}••••${v.slice(-4)}`;
}

/** Client-safe status (effective = override ?? env). Token is masked. */
export function cloudflareStatus() {
  const envAcc = process.env.CF_ACCOUNT_ID || '';
  const envDb = process.env.CF_D1_DATABASE_ID || '';
  const envTok = process.env.CF_API_TOKEN || '';
  const accountId = store.accountId || envAcc;
  const databaseId = store.databaseId || envDb;
  const token = store.token || envTok;
  const src = (runtime, env) => (runtime ? 'runtime' : env ? 'env' : 'none');
  return {
    accountId: { set: !!accountId, source: src(store.accountId, envAcc), value: accountId || null },
    databaseId: { set: !!databaseId, source: src(store.databaseId, envDb), value: databaseId || null },
    token: { set: !!token, source: src(store.token, envTok), masked: mask(token) },
    configured: !!(accountId && databaseId && token),
  };
}
