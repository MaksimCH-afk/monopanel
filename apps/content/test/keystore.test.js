import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// keystore reads CONTENT_DATA_DIR at import time, so each test sets it before a
// fresh dynamic import (unique query string → fresh module instance).

test('keystore: set (trimmed), mask hides the middle, persists to disk', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ckeys-'));
  process.env.CONTENT_DATA_DIR = dir;
  delete process.env.GOOGLE_NL_API_KEY;
  delete process.env.OPENAI_API_KEY;

  const ks = await import('../src/core/keystore.js?a');
  assert.equal(ks.getGoogleKey(), '');

  const r = ks.setKeys({ google: '  AIzaSECRETkey1234  ', openai: 'sk-openaiKEY5678' });
  assert.equal(r.persisted, true);
  assert.equal(ks.getGoogleKey(), 'AIzaSECRETkey1234'); // trimmed
  assert.equal(ks.getOpenAIKey(), 'sk-openaiKEY5678');

  const st = ks.keyStatus();
  assert.equal(st.google.set, true);
  assert.equal(st.google.source, 'runtime');
  assert.ok(st.google.masked.includes('••••'));
  assert.ok(!st.google.masked.includes('SECRET')); // full key never exposed

  const saved = JSON.parse(fs.readFileSync(path.join(dir, 'keys.json'), 'utf8'));
  assert.equal(saved.google, 'AIzaSECRETkey1234');

  assert.equal(ks.maskKey('abc'), '••••'); // short keys fully masked
  assert.equal(ks.maskKey(''), null);
});

test('keystore: clearing an override falls back to env', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ckeys2-'));
  process.env.CONTENT_DATA_DIR = dir;
  process.env.GOOGLE_NL_API_KEY = 'envGOOGLEkey999';

  const ks = await import('../src/core/keystore.js?b');
  let st = ks.keyStatus();
  assert.equal(st.google.source, 'env'); // no override yet
  assert.equal(st.google.set, true);

  ks.setKeys({ google: 'runtimeKEY' });
  assert.equal(ks.keyStatus().google.source, 'runtime');

  ks.setKeys({ google: '' }); // clear → back to env
  st = ks.keyStatus();
  assert.equal(st.google.source, 'env');
  assert.equal(ks.getGoogleKey(), ''); // override empty; effective key comes from env via config
});
