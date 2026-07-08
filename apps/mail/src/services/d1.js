// Cloudflare D1 REST client + the handful of queries the admin needs.
//
// Every request goes to:
//   POST {apiBase}/accounts/{account_id}/d1/database/{database_id}/query
//   Authorization: Bearer <token>
//   { "sql": "...", "params": [...] }
//
// The response envelope is Cloudflare's standard:
//   { success, errors:[{code,message}], messages, result:[ { results:[...], success, meta } ] }
// We surface `result[0].results` (the rows) and translate API/auth errors into a
// single Error the server can report cleanly.

import { config } from '../config.js';

export class D1Error extends Error {
  constructor(message, status = 502) {
    super(message);
    this.name = 'D1Error';
    this.status = status;
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Raw D1 query. `creds` lets callers (e.g. the "Проверить соединение" button)
// test a set of credentials before saving; omit to use the effective config.
export async function d1Query(sql, params = [], creds = null) {
  const accountId = creds?.accountId ?? config.cloudflare.accountId;
  const databaseId = creds?.databaseId ?? config.cloudflare.databaseId;
  const token = creds?.token ?? config.cloudflare.token;

  if (!accountId || !databaseId || !token) {
    throw new D1Error('Cloudflare не настроен: нужны account id, database id и API-токен.', 400);
  }

  const url = `${config.cloudflare.apiBase}/accounts/${encodeURIComponent(accountId)}/d1/database/${encodeURIComponent(databaseId)}/query`;
  const body = JSON.stringify({ sql, params });

  const { maxAttempts, baseMs } = config.retry;
  let lastErr;
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const resp = await fetch(url, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body,
      });

      // 401/403 → bad token/permissions; don't retry, report clearly.
      if (resp.status === 401 || resp.status === 403) {
        throw new D1Error('Cloudflare отклонил токен (401/403). Проверьте API-токен и его права (D1 Read/Edit).', 401);
      }
      // 404 → wrong account/database id.
      if (resp.status === 404) {
        throw new D1Error('База D1 не найдена (404). Проверьте account id и database id.', 404);
      }

      let json;
      try {
        json = await resp.json();
      } catch {
        throw new D1Error(`Некорректный ответ Cloudflare (HTTP ${resp.status}).`, 502);
      }

      if (!resp.ok || json.success === false) {
        const msg = (json.errors && json.errors.map((e) => e.message).join('; ')) || `HTTP ${resp.status}`;
        // Retry only on transient 5xx; otherwise fail fast.
        if (resp.status >= 500) throw new D1Error(`Cloudflare API: ${msg}`, 502);
        throw new D1Error(`Cloudflare API: ${msg}`, 400);
      }

      const first = Array.isArray(json.result) ? json.result[0] : json.result;
      return {
        rows: (first && first.results) || [],
        meta: (first && first.meta) || {},
      };
    } catch (err) {
      lastErr = err;
      // Only retry on transient conditions (5xx / network); never on 4xx.
      const retryable = err instanceof D1Error ? err.status >= 500 : true;
      if (!retryable || attempt === maxAttempts) break;
      await sleep(baseMs * 2 ** (attempt - 1));
    }
  }
  if (lastErr instanceof D1Error) throw lastErr;
  throw new D1Error(`Не удалось обратиться к Cloudflare D1: ${lastErr?.message || 'сеть недоступна'}`, 502);
}

// --- Domain queries ---------------------------------------------------------

// List of "mailboxes" (distinct recipient addresses) with counts + last message.
export async function listMailboxes(creds = null) {
  const { rows } = await d1Query(
    `SELECT mailbox, COUNT(*) AS count, MAX(received_at) AS last_at
       FROM messages
      GROUP BY mailbox
      ORDER BY last_at DESC`,
    [],
    creds
  );
  return rows;
}

// Message headers for one mailbox (newest first). Optional free-text search over
// subject/sender/body.
export async function listMessages(mailbox, { limit = config.listLimit, search = '' } = {}, creds = null) {
  const lim = Math.max(1, Math.min(500, Number(limit) || config.listLimit));
  let sql = `SELECT id, sender, subject, received_at, raw_size
               FROM messages
              WHERE mailbox = ?`;
  const params = [mailbox];
  if (search && search.trim()) {
    sql += ` AND (subject LIKE ? OR sender LIKE ? OR text_body LIKE ?)`;
    const like = `%${search.trim()}%`;
    params.push(like, like, like);
  }
  sql += ` ORDER BY received_at DESC LIMIT ?`;
  params.push(lim);
  const { rows } = await d1Query(sql, params, creds);
  return rows;
}

// One full message by id.
export async function getMessage(id, creds = null) {
  const { rows } = await d1Query(`SELECT * FROM messages WHERE id = ?`, [Number(id)], creds);
  return rows[0] || null;
}

// Delete one message. Returns number of rows changed.
export async function deleteMessage(id, creds = null) {
  const { meta } = await d1Query(`DELETE FROM messages WHERE id = ?`, [Number(id)], creds);
  return meta.changes ?? 0;
}

// Temp-mail cleanup: drop messages older than an ISO timestamp.
export async function deleteOlderThan(isoTs, creds = null) {
  const { meta } = await d1Query(`DELETE FROM messages WHERE received_at < ?`, [isoTs], creds);
  return meta.changes ?? 0;
}

// Lightweight connectivity probe used by the "Проверить соединение" button.
export async function ping(creds = null) {
  const { rows } = await d1Query(
    `SELECT COUNT(*) AS total, COUNT(DISTINCT mailbox) AS mailboxes FROM messages`,
    [],
    creds
  );
  return rows[0] || { total: 0, mailboxes: 0 };
}
