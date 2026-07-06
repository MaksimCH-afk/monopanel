import { test } from 'node:test';
import assert from 'node:assert/strict';
import { config } from '../src/config.js';
import { aggregateProfile, aggregatePhrasesProfile } from '../src/core/aggregator.js';
import { validateRequest } from '../src/core/analyze.js';

const ent = (name, salience, mid = null) => ({ name, type: 'OTHER', salience, mid, wikipedia_url: null });
const phraseMap = (entries) => {
  const m = new Map();
  for (const [k, n, s] of entries) m.set(k, { key: k, name: k, n, count: 2, salience: s });
  return m;
};

test('mode B: entity consensus profile — full list, no missing/weak/gap (§4.2)', () => {
  const competitors = [
    { entities: [ent('Casino', 0.5, '/m/c'), ent('Bonus', 0.3), ent('RTP', 0.2)] },
    { entities: [ent('Casino', 0.5, '/m/c'), ent('Bonus', 0.3), ent('License', 0.2)] },
    { entities: [ent('Casino', 0.5, '/m/c'), ent('Bonus', 0.3)] },
  ];
  const r = aggregateProfile(competitors, config);
  assert.ok(Array.isArray(r.profile));
  assert.ok(!('missing' in r) && !('weak' in r));
  // K = ceil(3*0.5)=2 → Casino(3), Bonus(3) consensus; RTP/License (coverage 1) dropped
  assert.deepEqual(r.profile.map((p) => p.name).sort(), ['Bonus', 'Casino']);

  const casino = r.profile.find((p) => p.name === 'Casino');
  assert.equal(casino.coverage, 3);
  assert.ok('median_salience' in casino);
  assert.ok(!('target_salience' in casino) && !('gap' in casino)); // no diff fields
  assert.ok(['high', 'medium', 'low'].includes(casino.priority));
});

test('mode B: ranks by coverage + centrality (no gap term)', () => {
  const competitors = [
    { entities: [ent('Casino', 0.6, '/m/c'), ent('Bonus', 0.1)] },
    { entities: [ent('Casino', 0.6, '/m/c'), ent('Bonus', 0.1)] },
  ];
  const r = aggregateProfile(competitors, config);
  // equal coverage; Casino has higher centrality + a mid → ranked first
  assert.equal(r.profile[0].name, 'Casino');
  assert.ok(r.profile[0]._score >= r.profile[1]._score);
});

test('mode B: phrase consensus profile on the phrase track', () => {
  const competitors = [
    { phraseMap: phraseMap([['casino bonus', 2, 0.1], ['welcome', 1, 0.05]]) },
    { phraseMap: phraseMap([['casino bonus', 2, 0.12], ['welcome', 1, 0.05]]) },
  ];
  const r = aggregatePhrasesProfile(competitors, config);
  assert.deepEqual(r.profile.map((p) => p.phrase).sort(), ['casino bonus', 'welcome']);
  const cb = r.profile.find((p) => p.phrase === 'casino bonus');
  assert.equal(cb.n, 2);
  assert.ok('median_density' in cb);
  assert.ok(!('target_density' in cb));
});

test('mode single: profile of ONE doc — coverage 1/1, no competitors needed', () => {
  const one = [{ entities: [ent('Casino', 0.5, '/m/c'), ent('Bonus', 0.3), ent('RTP', 0.1)] }];
  const r = aggregateProfile(one, config);
  // K = ceil(1*0.5)=1 → all units kept, coverage 1
  assert.deepEqual(r.profile.map((p) => p.name).sort(), ['Bonus', 'Casino', 'RTP']);
  assert.ok(r.profile.every((p) => p.coverage === 1));
  assert.ok('median_salience' in r.profile[0]);
});

test('validation: single needs page text, not competitors', () => {
  assert.throws(
    () => validateRequest({ query: 'q', mode: 'single', competitors: [] }),
    /страниц/
  );
  const s = validateRequest({ query: 'q', mode: 'single', target: { text: 'page' } });
  assert.equal(s.mode, 'single');
  assert.ok(s.target);
  assert.deepEqual(s.competitors, []); // competitors not required/ignored
});

test('validation: compare needs my page, competitors_only does not (§4.1)', () => {
  assert.throws(
    () => validateRequest({ query: 'q', mode: 'compare', competitors: [{ text: 'x' }] }),
    /Моя страница/
  );
  const b = validateRequest({ query: 'q', mode: 'competitors_only', competitors: [{ text: 'x' }] });
  assert.equal(b.mode, 'competitors_only');
  assert.equal(b.target, null);
});

test('validation: default mode is compare; competitors_only ignores a sent target (§8)', () => {
  const a = validateRequest({ query: 'q', target: { text: 'my page' }, competitors: [{ text: 'x' }] });
  assert.equal(a.mode, 'compare');

  const b = validateRequest({ query: 'q', mode: 'competitors_only', target: { text: 'ignored' }, competitors: [{ text: 'x' }] });
  assert.equal(b.target, null);
});
