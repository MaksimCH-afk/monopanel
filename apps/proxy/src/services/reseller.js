// Thin server-side proxy to the PacketStream Reseller API (TZ §7, stage 2). Our
// backend just attaches the operator's Bearer token + Content-Type and maps the
// upstream response into our shape. Exact paths come from the Postman collection
// issued with access, so base + paths are config-driven (see config.js) and must
// not be invented. Without a token the caller returns 409 reseller_not_configured
// — unless RESELLER_MOCK is on, which serves deterministic fake data so the UI
// and tests work end-to-end before access is granted.

export class ResellerError extends Error {
  constructor(message, status, detail) {
    super(message);
    this.status = status || 502;
    this.detail = detail || message;
  }
}

async function upstream(path, token, cfg, { method = 'GET', body } = {}) {
  const url = cfg.base.replace(/\/+$/, '') + path;
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), cfg.timeoutMs);
  try {
    const res = await fetch(url, {
      method,
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: ctrl.signal,
    });
    const text = await res.text();
    let json;
    try {
      json = text ? JSON.parse(text) : {};
    } catch {
      throw new ResellerError('reseller_bad_response', 502, 'upstream did not return JSON');
    }
    if (!res.ok) {
      throw new ResellerError('reseller_upstream_error', 502, `upstream HTTP ${res.status}`);
    }
    return json;
  } catch (e) {
    if (e instanceof ResellerError) throw e;
    if (e.name === 'AbortError') throw new ResellerError('reseller_timeout', 502, 'timeout');
    throw new ResellerError('reseller_unreachable', 502, e.message);
  } finally {
    clearTimeout(t);
  }
}

/** GET balance → { gb_remaining, gb_used_14d }. */
export async function getBalance(token, cfg) {
  if (cfg.mock && !token) {
    return { gb_remaining: 42.5, gb_used_14d: 7.8, mock: true };
  }
  if (!token) throw new ResellerError('reseller_not_configured', 409);
  const d = await upstream(cfg.balancePath, token, cfg);
  // Map common field names into our contract (TZ §5.5). Real names come from the
  // Postman collection; adjust the right-hand side once access is granted.
  return {
    gb_remaining: d.gb_remaining ?? d.balance_gb ?? d.remaining ?? null,
    gb_used_14d: d.gb_used_14d ?? d.used_14d ?? d.usage_14d ?? null,
  };
}

/** POST subuser → passthrough of the created subuser. */
export async function createSubuser(token, cfg, payload) {
  if (cfg.mock && !token) {
    return { username: payload?.username || 'mock_subuser', created: true, mock: true };
  }
  if (!token) throw new ResellerError('reseller_not_configured', 409);
  return upstream(cfg.subusersPath, token, cfg, { method: 'POST', body: payload });
}
