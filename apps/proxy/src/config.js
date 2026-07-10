// Centralized configuration. Values come from the environment with sane
// defaults so the app runs out of the box on :3339. Account secrets are NOT
// here — they live in core/accountstore.js (runtime override ?? env).

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

export const config = {
  port: num('PORT', 3339),

  // Exit-IP test (TZ §5.4): one GET through the proxy to an IP-echo endpoint.
  test: {
    echoUrl: str('PROXY_TEST_URL', 'https://ifconfig.co/json'),
    timeoutMs: num('PROXY_TEST_TIMEOUT_MS', 15000),
    // Rate-limit per profile (TZ §6): min interval between /api/proxy/test calls.
    rateLimitMs: num('PROXY_TEST_RATE_MS', 1000),
  },

  // Reseller API (TZ §7). Gated behind an application; paths come from the
  // Postman collection issued with access, so keep base + paths configurable
  // and never hard-code them. When no token is set the endpoints return 409.
  reseller: {
    base: str('RESELLER_API_BASE', 'https://reseller.packetstream.io'),
    balancePath: str('RESELLER_BALANCE_PATH', '/api/balance'),
    subusersPath: str('RESELLER_SUBUSERS_PATH', '/api/subusers'),
    timeoutMs: num('RESELLER_TIMEOUT_MS', 15000),
    // When true, /api/reseller/* returns deterministic fake data even without a
    // token (their docs mention a mock endpoint for testing without access).
    mock: bool('RESELLER_MOCK', false),
  },
};

export default config;
