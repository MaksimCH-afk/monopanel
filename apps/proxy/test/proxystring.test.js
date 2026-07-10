// Verifies the connection-string builder against the TZ §3 reference and the
// §2 port/scheme table across all four formats and three protocols, on country
// (set/empty) and sticky/rotating — acceptance criteria §9.2 / §9.3.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildString,
  buildPass,
  PORTS,
  SCHEMES,
  HOST,
  genSession,
} from '../src/core/proxystring.js';

const account = { username: 'user', authKey: 'KEY' };

test('port/scheme table matches TZ §2', () => {
  assert.equal(PORTS.http, 31112);
  assert.equal(PORTS.https, 31111);
  assert.equal(PORTS.socks5, 31113);
  assert.equal(SCHEMES.http, 'http');
  assert.equal(SCHEMES.https, 'https');
  assert.equal(SCHEMES.socks5, 'socks5h'); // §9.3: SOCKS5 → 31113 + socks5h
});

test('password order: auth_key + _country + _session (TZ §3)', () => {
  const p = { proto: 'https', country: 'US', sticky: true, session: 'abcd1234' };
  assert.equal(buildPass(p, 'KEY'), 'KEY_country-US_session-abcd1234');
});

test('country uppercased; empty country = global (no _country)', () => {
  assert.equal(buildPass({ country: 'de' }, 'KEY'), 'KEY_country-DE');
  assert.equal(buildPass({ country: '' }, 'KEY'), 'KEY');
  assert.equal(buildPass({ country: null }, 'KEY'), 'KEY');
});

test('rotating profile never adds _session', () => {
  const p = { proto: 'http', country: 'US', sticky: false, session: null };
  assert.equal(buildPass(p, 'KEY'), 'KEY_country-US');
});

test('url format, https, country, sticky', () => {
  const p = { proto: 'https', country: 'US', sticky: true, session: 'sess0001' };
  assert.equal(
    buildString(p, account, 'url'),
    `https://user:KEY_country-US_session-sess0001@${HOST}:31111`
  );
});

test('url format, socks5 uses socks5h + 31113', () => {
  const p = { proto: 'socks5', country: '', sticky: false, session: null };
  assert.equal(buildString(p, account, 'url'), `socks5h://user:KEY@${HOST}:31113`);
});

test('list format = host:port:user:pass', () => {
  const p = { proto: 'http', country: 'DE', sticky: false };
  assert.equal(buildString(p, account, 'list'), `${HOST}:31112:user:KEY_country-DE`);
});

test('env format', () => {
  const p = { proto: 'https', country: '', sticky: false };
  assert.equal(buildString(p, account, 'env'), `PROXY_URL=https://user:KEY@${HOST}:31111`);
});

test('curl format includes echo url', () => {
  const p = { proto: 'https', country: '', sticky: false };
  const s = buildString(p, account, 'curl', { echoUrl: 'https://ifconfig.co/json' });
  assert.equal(s, `curl -x "https://user:KEY@${HOST}:31111" https://ifconfig.co/json`);
});

test('genSession → 8 alphanumeric chars', () => {
  for (let i = 0; i < 50; i++) {
    const s = genSession();
    assert.match(s, /^[a-z0-9]{8}$/);
  }
});
