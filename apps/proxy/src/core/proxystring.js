// Reference connection-string builder (TZ §2, §3). This is the single source of
// truth for PacketStream ports/schemes and for how the credential password is
// assembled from the auth key + country + sticky session. Everything else
// (server, tests, proxy-test) goes through here so the four formats and three
// protocols always match the spec exactly.

// PacketStream gateway + fixed port/scheme table (TZ §2). Do not change these
// values arbitrarily — SOCKS5 in particular must be 31113 + socks5h so DNS is
// resolved on the proxy side.
export const HOST = 'proxy.packetstream.io';
export const PORTS = { http: 31112, https: 31111, socks5: 31113 };
export const SCHEMES = { http: 'http', https: 'https', socks5: 'socks5h' };

export const PROTOCOLS = Object.keys(PORTS);
export const FORMATS = ['url', 'list', 'env', 'curl'];

// URL used by the `curl` format and by the exit-IP test (TZ §5.4). Overridable
// so a self-hosted echo can be swapped in.
const DEFAULT_ECHO_URL = 'https://ifconfig.co/json';

const ALNUM = 'abcdefghijklmnopqrstuvwxyz0123456789';

/** Random 8-char alphanumeric sticky session id (TZ §2). */
export function genSession(len = 8) {
  let s = '';
  for (let i = 0; i < len; i++) s += ALNUM[(Math.random() * ALNUM.length) | 0];
  return s;
}

/** Short profile id (6+ chars, TZ §4). */
export function genId(len = 6) {
  let s = '';
  for (let i = 0; i < len; i++) s += ALNUM[(Math.random() * ALNUM.length) | 0];
  return s;
}

/**
 * Assemble the password portion (TZ §3): auth_key + _country-XX + _session-XXXXXXXX,
 * strictly in that order. Missing country → global; non-sticky → no session.
 */
export function buildPass(profile, authKey) {
  let pass = authKey || '';
  if (profile.country) pass += `_country-${String(profile.country).toUpperCase()}`;
  if (profile.sticky && profile.session) pass += `_session-${profile.session}`;
  return pass;
}

/**
 * Build one connection string for a profile in the requested format.
 * @param {object} profile  { proto, country, sticky, session }
 * @param {object} account  { username, authKey }
 * @param {'url'|'list'|'env'|'curl'} format
 * @param {object} [opts]   { echoUrl }
 */
export function buildString(profile, account, format = 'url', opts = {}) {
  const proto = profile.proto;
  const port = PORTS[proto];
  const scheme = SCHEMES[proto];
  const user = account.username || 'USERNAME';
  const pass = buildPass(profile, account.authKey || 'AUTH_KEY');
  const echoUrl = opts.echoUrl || DEFAULT_ECHO_URL;

  switch (format) {
    case 'list':
      return `${HOST}:${port}:${user}:${pass}`;
    case 'env':
      return `PROXY_URL=${scheme}://${user}:${pass}@${HOST}:${port}`;
    case 'curl':
      return `curl -x "${scheme}://${user}:${pass}@${HOST}:${port}" ${echoUrl}`;
    case 'url':
    default:
      return `${scheme}://${user}:${pass}@${HOST}:${port}`;
  }
}

/** All four formats at once (used when serialising a profile outward). */
export function buildAllStrings(profile, account, opts = {}) {
  const out = {};
  for (const f of FORMATS) out[f] = buildString(profile, account, f, opts);
  return out;
}
