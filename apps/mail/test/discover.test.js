import { test } from 'node:test';
import assert from 'node:assert/strict';
import { listAccounts, listDatabases, discover } from '../src/services/discover.js';
import { D1Error } from '../src/services/d1.js';

// Stub global.fetch with a routing table keyed by URL suffix.
function stubFetch(routes) {
  global.fetch = async (url) => {
    for (const [suffix, payload] of Object.entries(routes)) {
      if (url.endsWith(suffix)) {
        return {
          ok: payload.status ? payload.status < 400 : true,
          status: payload.status || 200,
          json: async () => payload.body,
        };
      }
    }
    throw new Error(`unexpected url ${url}`);
  };
}

test('listAccounts maps id + name', async () => {
  stubFetch({ '/accounts': { body: { success: true, result: [{ id: 'acc1', name: 'My Acc' }] } } });
  const accs = await listAccounts('tok');
  assert.deepEqual(accs, [{ id: 'acc1', name: 'My Acc' }]);
});

test('listDatabases maps uuid + name', async () => {
  stubFetch({
    '/accounts/acc1/d1/database': { body: { success: true, result: [{ uuid: 'db-uuid', name: 'mail' }] } },
  });
  const dbs = await listDatabases('acc1', 'tok');
  assert.deepEqual(dbs, [{ uuid: 'db-uuid', name: 'mail' }]);
});

test('discover auto-selects the single account and lists its databases', async () => {
  stubFetch({
    '/accounts': { body: { success: true, result: [{ id: 'acc1', name: 'Solo' }] } },
    '/accounts/acc1/d1/database': { body: { success: true, result: [{ uuid: 'u1', name: 'mail' }] } },
  });
  const r = await discover({ token: 'tok' });
  assert.equal(r.accountId, 'acc1');
  assert.equal(r.accounts.length, 1);
  assert.deepEqual(r.databases, [{ uuid: 'u1', name: 'mail' }]);
});

test('discover does NOT auto-pick when several accounts exist', async () => {
  stubFetch({
    '/accounts': { body: { success: true, result: [{ id: 'a', name: 'A' }, { id: 'b', name: 'B' }] } },
  });
  const r = await discover({ token: 'tok' });
  assert.equal(r.accountId, '');
  assert.equal(r.databases.length, 0);
});

test('discover falls back to listing DBs when /accounts is forbidden but accountId given', async () => {
  stubFetch({
    '/accounts/acc9/d1/database': { body: { success: true, result: [{ uuid: 'u9', name: 'mail' }] } },
    '/accounts': { status: 403, body: { success: false, errors: [{ message: 'forbidden' }] } },
  });
  const r = await discover({ token: 'tok', accountId: 'acc9' });
  assert.equal(r.accountId, 'acc9');
  assert.deepEqual(r.databases, [{ uuid: 'u9', name: 'mail' }]);
});

test('discover: empty /accounts + manual accountId still lists that account\'s DBs', async () => {
  stubFetch({
    '/accounts/accX/d1/database': { body: { success: true, result: [{ uuid: 'uX', name: 'mail' }] } },
    '/accounts': { body: { success: true, result: [] } }, // token can't enumerate accounts
  });
  const r = await discover({ token: 'tok', accountId: 'accX' });
  assert.equal(r.accountId, 'accX');
  assert.deepEqual(r.databases, [{ uuid: 'uX', name: 'mail' }]);
});

test('discover: empty /accounts and no accountId → nothing to pick (guides manual entry)', async () => {
  stubFetch({ '/accounts': { body: { success: true, result: [] } } });
  const r = await discover({ token: 'tok' });
  assert.equal(r.accountId, '');
  assert.equal(r.accounts.length, 0);
  assert.equal(r.databases.length, 0);
});

test('401 surfaces a clean D1Error', async () => {
  stubFetch({ '/accounts': { status: 403, body: { success: false, errors: [{ message: 'forbidden' }] } } });
  await assert.rejects(() => listAccounts('bad'), (e) => e instanceof D1Error && e.status === 401);
});
