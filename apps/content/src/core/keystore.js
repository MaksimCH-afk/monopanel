// Runtime API-key store. Lets the operator paste keys in the UI instead of
// hand-editing .env. Keys are held in memory (used immediately by the NL/OpenAI
// services) and persisted best-effort to a small JSON file so they survive a
// process restart. The effective key for a provider is: runtime override ?? env.
//
// The full key is never sent back to the client — only a masked form + status.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// Where to persist. In Docker set CONTENT_DATA_DIR=/data/content (mounted
// volume) so keys survive image rebuilds; locally defaults to apps/content/.data
// (gitignored). If the path is not writable we keep runtime-only overrides.
const DATA_DIR = process.env.CONTENT_DATA_DIR || path.join(__dirname, '..', '..', '.data');
const FILE = path.join(DATA_DIR, 'keys.json');

const store = { google: '', openai: '' };

function load() {
  try {
    const j = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    if (typeof j.google === 'string') store.google = j.google;
    if (typeof j.openai === 'string') store.openai = j.openai;
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

export function getGoogleKey() {
  return store.google || '';
}
export function getOpenAIKey() {
  return store.openai || '';
}

/**
 * Set/clear runtime key overrides. A property is applied only when present:
 *   string (non-empty) → set;  "" or null → clear (fall back to env).
 * @returns {{persisted:boolean}}
 */
export function setKeys({ google, openai } = {}) {
  if (google !== undefined) store.google = typeof google === 'string' ? google.trim() : '';
  if (openai !== undefined) store.openai = typeof openai === 'string' ? openai.trim() : '';
  return { persisted: persist() };
}

// Mask a key for display: keep a few head/tail chars, hide the middle.
export function maskKey(k) {
  if (!k) return null;
  if (k.length <= 8) return '••••';
  return `${k.slice(0, 3)}••••${k.slice(-4)}`;
}

/** Masked, client-safe status of both providers (effective = override ?? env). */
export function keyStatus() {
  const gEnv = process.env.GOOGLE_NL_API_KEY || '';
  const oEnv = process.env.OPENAI_API_KEY || '';
  const g = store.google || gEnv;
  const o = store.openai || oEnv;
  return {
    google: {
      set: !!g,
      source: store.google ? 'runtime' : gEnv ? 'env' : 'none',
      masked: maskKey(g),
    },
    openai: {
      set: !!o,
      source: store.openai ? 'runtime' : oEnv ? 'env' : 'none',
      masked: maskKey(o),
    },
  };
}
