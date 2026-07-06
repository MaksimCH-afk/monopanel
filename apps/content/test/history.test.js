import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// historystore reads CONTENT_DATA_DIR at import; use a fresh dir + dynamic import.
test('history: add annotates id, lists summary, gets full, deletes, persists', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'chist-'));
  process.env.CONTENT_DATA_DIR = dir;
  const hs = await import('../src/core/historystore.js?a');

  const result = { query: 'casino', mode: 'compare', competitors_analyzed: 3, missing: [{ name: 'RTP' }], weak: [] };
  const { id } = hs.addAnalysis(result);
  assert.ok(id);
  assert.equal(result.history_id, id); // result annotated

  const list = hs.listAnalyses();
  assert.equal(list.length, 1);
  assert.equal(list[0].query, 'casino');
  assert.equal(list[0].mode, 'compare');
  assert.equal(list[0].items, 1); // missing(1)+weak(0)
  assert.ok(!('result' in list[0])); // list is lightweight

  const full = hs.getAnalysis(id);
  assert.equal(full.query, 'casino');
  assert.equal(full.missing[0].name, 'RTP');

  // persisted to disk
  const saved = JSON.parse(fs.readFileSync(path.join(dir, 'history.json'), 'utf8'));
  assert.equal(saved.length, 1);

  assert.equal(hs.deleteAnalysis(id), true);
  assert.equal(hs.listAnalyses().length, 0);
  assert.equal(hs.deleteAnalysis(id), false); // already gone
});

test('history: newest first, item count uses profile length for competitors_only', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'chist2-'));
  process.env.CONTENT_DATA_DIR = dir;
  const hs = await import('../src/core/historystore.js?b');

  hs.addAnalysis({ query: 'first', mode: 'compare', missing: [], weak: [] });
  hs.addAnalysis({ query: 'second', mode: 'competitors_only', consensus_profile: [{ name: 'A' }, { name: 'B' }] });

  const list = hs.listAnalyses();
  assert.equal(list[0].query, 'second'); // newest first
  assert.equal(list[0].items, 2); // consensus_profile length
});
