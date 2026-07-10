// Persistent store for proxy profiles (TZ §4). Profiles hold no secret of their
// own — the auth key lives in accountstore and is only ever woven into the
// assembled connection string. Kept in memory and persisted best-effort to a
// JSON file in the data dir so profiles survive a restart / image rebuild.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { genId, genSession, PROTOCOLS } from './proxystring.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = process.env.PROXY_DATA_DIR || path.join(__dirname, '..', '..', '.data');
const FILE = path.join(DATA_DIR, 'profiles.json');

// Reference set of panel apps a profile can be assigned to (TZ §4). The
// distribution endpoint (§5.3) looks profiles up by these ids.
export const APP_IDS = ['cf', 'seo', 'anc', 'img', 'arc', 'skin', 'gap', 'mail'];

/** @type {Array<object>} newest first */
let profiles = [];

function load() {
  try {
    const j = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    if (Array.isArray(j.profiles)) profiles = j.profiles;
  } catch {
    /* no file yet / unreadable — start empty */
  }
}
load();

function persist() {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(FILE, JSON.stringify({ profiles }, null, 2), { mode: 0o600 });
    return true;
  } catch {
    return false;
  }
}

export class ValidationError extends Error {
  constructor(message, status = 400) {
    super(message);
    this.status = status;
  }
}

function normProto(v) {
  const p = String(v || '').toLowerCase();
  if (!PROTOCOLS.includes(p)) throw new ValidationError(`Unknown protocol '${v}'. Use http|https|socks5.`);
  return p;
}

function normCountry(v) {
  if (v === undefined || v === null) return '';
  const c = String(v).trim().toUpperCase();
  if (c === '') return '';
  if (!/^[A-Z]{2}$/.test(c)) throw new ValidationError(`Country must be a 2-letter ISO code, got '${v}'.`);
  return c;
}

function normAppId(v) {
  if (v === undefined || v === null) return '';
  const a = String(v).trim();
  if (a === '') return '';
  if (!APP_IDS.includes(a)) throw new ValidationError(`Unknown app_id '${v}'.`);
  return a;
}

export function list() {
  return profiles.map((p) => ({ ...p }));
}

export function get(id) {
  const p = profiles.find((x) => x.id === id);
  return p ? { ...p } : null;
}

export function getByApp(appId) {
  const p = profiles.find((x) => x.app_id === appId);
  return p ? { ...p } : null;
}

/**
 * Create a profile. The server generates the id and, for sticky profiles, the
 * 8-char session (TZ §5.1 — the client never supplies it).
 */
export function create({ name, country, proto, sticky, app_id } = {}) {
  const now = new Date().toISOString();
  const isSticky = sticky === true || sticky === 'true';
  const p = {
    id: genId(),
    name: (name ? String(name) : '').trim(),
    country: normCountry(country),
    proto: normProto(proto || 'https'),
    sticky: isSticky,
    session: isSticky ? genSession() : null,
    app_id: normAppId(app_id),
    created_at: now,
    updated_at: now,
  };
  profiles.unshift(p);
  persist();
  return { ...p };
}

/**
 * Patch a profile. Accepts any mutable field plus:
 *   regenerate_session:true → new sticky session id.
 * Toggling sticky on generates a session if absent; toggling it off clears it.
 */
export function update(id, patch = {}) {
  const p = profiles.find((x) => x.id === id);
  if (!p) return null;

  if (patch.name !== undefined) p.name = String(patch.name).trim();
  if (patch.country !== undefined) p.country = normCountry(patch.country);
  if (patch.proto !== undefined) p.proto = normProto(patch.proto);
  if (patch.app_id !== undefined) p.app_id = normAppId(patch.app_id);

  if (patch.sticky !== undefined) {
    const wantSticky = patch.sticky === true || patch.sticky === 'true';
    p.sticky = wantSticky;
    if (wantSticky && !p.session) p.session = genSession();
    if (!wantSticky) p.session = null;
  }

  // Explicit session regeneration (only meaningful for sticky profiles).
  if (patch.regenerate_session === true || patch.regenerate_session === 'true') {
    if (!p.sticky) throw new ValidationError('Cannot regenerate a session on a rotating profile.');
    p.session = genSession();
  }

  p.updated_at = new Date().toISOString();
  persist();
  return { ...p };
}

export function remove(id) {
  const before = profiles.length;
  profiles = profiles.filter((x) => x.id !== id);
  const removed = profiles.length < before;
  if (removed) persist();
  return removed;
}

// Test-only reset.
export function _reset() {
  profiles = [];
}
