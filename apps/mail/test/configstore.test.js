import { test } from 'node:test';
import assert from 'node:assert/strict';
import os from 'node:os';
import fs from 'node:fs';
import path from 'node:path';

// Point the store at a throwaway data dir before importing it.
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'mail-cfg-'));
process.env.MAIL_DATA_DIR = tmp;

const { setCloudflare, getCloudflare, cloudflareStatus, mask } = await import('../src/store/configstore.js');

test('mask hides the middle of a secret', () => {
  assert.equal(mask(''), null);
  assert.equal(mask('short'), '••••');
  assert.match(mask('abcdefghijklmnop'), /^abc••••mnop$/);
});

test('setCloudflare persists and getCloudflare reads back', () => {
  setCloudflare({ accountId: ' acc123 ', databaseId: 'db-uuid', token: 'tok-secret-value' });
  const cur = getCloudflare();
  assert.equal(cur.accountId, 'acc123'); // trimmed
  assert.equal(cur.databaseId, 'db-uuid');
  assert.equal(cur.token, 'tok-secret-value');
  // written to disk
  const onDisk = JSON.parse(fs.readFileSync(path.join(tmp, 'config.json'), 'utf8'));
  assert.equal(onDisk.accountId, 'acc123');
});

test('status is client-safe: token masked, marked configured', () => {
  const s = cloudflareStatus();
  assert.equal(s.configured, true);
  assert.equal(s.token.masked, mask('tok-secret-value'));
  assert.ok(!('value' in s.token), 'raw token must not be exposed');
  assert.equal(s.accountId.value, 'acc123');
});

test('empty string clears a field back to none', () => {
  setCloudflare({ token: '' });
  assert.equal(getCloudflare().token, '');
  assert.equal(cloudflareStatus().configured, false);
});
