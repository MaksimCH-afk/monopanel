// Auto-discovery helpers so the operator doesn't have to know the Account ID or
// run `wrangler d1 create` to find the Database ID. With just the API token
// (Account · D1 · Read) Cloudflare's API can list the accounts the token can
// reach and the D1 databases in each — we surface both for a pick-from-list UX.

import { config } from '../config.js';
import { D1Error } from './d1.js';

async function cfGet(path, token) {
  if (!token) throw new D1Error('Нужен API-токен Cloudflare.', 400);
  let resp;
  try {
    resp = await fetch(`${config.cloudflare.apiBase}${path}`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    });
  } catch (e) {
    throw new D1Error(`Сеть недоступна: ${e.message}`, 502);
  }
  if (resp.status === 401 || resp.status === 403) {
    throw new D1Error('Cloudflare отклонил токен (401/403). Проверьте токен и его права (Account · D1 · Read).', 401);
  }
  let json;
  try {
    json = await resp.json();
  } catch {
    throw new D1Error(`Некорректный ответ Cloudflare (HTTP ${resp.status}).`, 502);
  }
  if (!resp.ok || json.success === false) {
    const msg = (json.errors && json.errors.map((e) => e.message).join('; ')) || `HTTP ${resp.status}`;
    throw new D1Error(`Cloudflare API: ${msg}`, resp.status >= 500 ? 502 : 400);
  }
  return json.result || [];
}

// Accounts the token can access → [{ id, name }].
export async function listAccounts(token) {
  const result = await cfGet('/accounts', token);
  return result.map((a) => ({ id: a.id, name: a.name || a.id }));
}

// D1 databases in an account → [{ uuid, name }].
export async function listDatabases(accountId, token) {
  if (!accountId) throw new D1Error('Не указан account id.', 400);
  const result = await cfGet(`/accounts/${encodeURIComponent(accountId)}/d1/database`, token);
  return result.map((d) => ({ uuid: d.uuid, name: d.name }));
}

/**
 * One-call discovery for the UI. Given a token (and optionally a chosen
 * account), returns the accounts list, the resolved account, and its databases.
 * If exactly one account is visible it is auto-selected and its databases are
 * fetched in the same round-trip.
 */
export async function discover({ token, accountId } = {}) {
  // Listing accounts can be forbidden for narrowly-scoped tokens; if the caller
  // already supplied an account id we can still proceed to its databases.
  let accounts = [];
  try {
    accounts = await listAccounts(token);
  } catch (e) {
    if (!accountId) throw e;
  }
  const account = accountId || (accounts.length === 1 ? accounts[0].id : '');
  let databases = [];
  if (account) {
    databases = await listDatabases(account, token);
  }
  return { accounts, accountId: account, databases };
}
