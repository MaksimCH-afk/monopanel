// Profile store CRUD: server-generated session on sticky, regeneration,
// sticky/rotating transitions, app assignment, validation (TZ §5.1, §9.1, §9.5).
// Uses a throwaway data dir so nothing persists into the repo.

import { test, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import os from 'node:os';
import path from 'node:path';
import fs from 'node:fs';

process.env.PROXY_DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'proxytest-'));

const store = await import('../src/core/profilestore.js');

beforeEach(() => store._reset());

test('create sticky profile → server generates 8-char session', () => {
  const p = store.create({ name: 'US', country: 'us', proto: 'https', sticky: true });
  assert.match(p.id, /^[a-z0-9]{6,}$/);
  assert.equal(p.country, 'US');
  assert.equal(p.sticky, true);
  assert.match(p.session, /^[a-z0-9]{8}$/);
});

test('create rotating profile → no session', () => {
  const p = store.create({ name: 'rot', proto: 'socks5', sticky: false });
  assert.equal(p.sticky, false);
  assert.equal(p.session, null);
});

test('regenerate_session changes the id, only for sticky', () => {
  const p = store.create({ proto: 'https', sticky: true });
  const before = p.session;
  const after = store.update(p.id, { regenerate_session: true });
  assert.notEqual(after.session, before);
  assert.match(after.session, /^[a-z0-9]{8}$/);

  const r = store.create({ proto: 'https', sticky: false });
  assert.throws(() => store.update(r.id, { regenerate_session: true }), /rotating/);
});

test('toggling sticky on generates a session; off clears it', () => {
  const p = store.create({ proto: 'https', sticky: false });
  assert.equal(p.session, null);
  const on = store.update(p.id, { sticky: true });
  assert.match(on.session, /^[a-z0-9]{8}$/);
  const off = store.update(p.id, { sticky: false });
  assert.equal(off.session, null);
});

test('app assignment + lookup by app', () => {
  const p = store.create({ proto: 'https', sticky: false });
  store.update(p.id, { app_id: 'arc' });
  const found = store.getByApp('arc');
  assert.equal(found.id, p.id);
  assert.equal(store.getByApp('mail'), null);
});

test('validation: bad proto / country / app_id', () => {
  assert.throws(() => store.create({ proto: 'ftp' }), /protocol/i);
  assert.throws(() => store.create({ proto: 'https', country: 'USA' }), /ISO/i);
  assert.throws(() => store.create({ proto: 'https', app_id: 'nope' }), /app_id/i);
});

test('delete removes the profile', () => {
  const p = store.create({ proto: 'https', sticky: false });
  assert.equal(store.remove(p.id), true);
  assert.equal(store.get(p.id), null);
  assert.equal(store.remove('missing'), false);
});
