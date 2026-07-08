// Centralized configuration for the mail admin.
//
// The admin never receives mail itself — Cloudflare Email Routing forwards every
// message to an Email Worker, the Worker parses it and writes a row into a D1
// (SQLite) database. This admin reads that database over the Cloudflare D1 REST
// API, using an account id + database id + API token.
//
// Those three credentials can come from the environment (below) OR be pasted in
// the UI at runtime; the runtime override wins (see store/configstore.js). When
// no credentials are effective the app runs in MOCK mode: it serves a small set
// of demo mailboxes/messages so the UI works end-to-end without any Cloudflare
// account.

import { getCloudflare } from './store/configstore.js';

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

const forcedMock = bool('MOCK_MODE', false);

export const config = {
  port: num('PORT', 3338),

  cloudflare: {
    // effective credential: runtime override (UI) falls back to env
    get accountId() {
      return getCloudflare().accountId || str('CF_ACCOUNT_ID', '');
    },
    get databaseId() {
      return getCloudflare().databaseId || str('CF_D1_DATABASE_ID', '');
    },
    get token() {
      return getCloudflare().token || str('CF_API_TOKEN', '');
    },
    // API base — overridable only for tests / self-hosted proxies.
    apiBase: str('CF_API_BASE', 'https://api.cloudflare.com/client/v4'),
    // all three parts must be present for a live connection
    get configured() {
      return !!(this.accountId && this.databaseId && this.token);
    },
  },

  // MOCK when forced, or when Cloudflare credentials are incomplete.
  get mockMode() {
    return forcedMock || !this.cloudflare.configured;
  },

  // Domain shown as a hint in the UI (purely cosmetic, e.g. "acc37@<domain>").
  mailDomain: str('MAIL_DOMAIN', 'mydomain.com'),

  // Default page size for the message list.
  listLimit: num('MAIL_LIST_LIMIT', 50),

  // Retry / backoff for the D1 REST calls.
  retry: {
    maxAttempts: num('RETRY_MAX_ATTEMPTS', 4),
    baseMs: num('RETRY_BASE_MS', 500),
  },
};

export default config;
