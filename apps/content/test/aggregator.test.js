import { test } from 'node:test';
import assert from 'node:assert/strict';
import { aggregate, canonicalKey, aggregateDoc } from '../src/core/aggregator.js';
import { median, countWords } from '../src/util/stats.js';
import { cleanText } from '../src/core/preprocess.js';

const cfg = {
  consensusThresholdRatio: 0.5,
  weakMargin: 0.4,
  salienceMin: 0.005,
  maxDocChars: 1000000,
  priority: { wCoverage: 0.5, wMid: 0.2, wGap: 0.3, high: 0.66, medium: 0.4 },
};

test('canonicalKey prefers mid, else normalized name', () => {
  assert.equal(canonicalKey({ name: 'Foo', mid: '/m/abc' }), 'mid:/m/abc');
  assert.equal(canonicalKey({ name: '  BonUs  Wager ' }), 'name:bonus wager');
});

test('median handles even/odd/empty', () => {
  assert.equal(median([3, 1, 2]), 2);
  assert.equal(median([1, 2, 3, 4]), 2.5);
  assert.equal(median([]), 0);
});

test('aggregateDoc sums duplicate keys and drops noise', () => {
  const m = aggregateDoc(
    [
      { name: 'Slot', salience: 0.3, mid: '/m/s', type: 'OTHER' },
      { name: 'slot', salience: 0.2, mid: '/m/s', type: 'OTHER' },
      { name: 'Dust', salience: 0.001, mid: null, type: 'OTHER' }, // below floor
    ],
    cfg
  );
  assert.equal(m.size, 1);
  assert.ok(Math.abs(m.get('mid:/m/s').salience - 0.5) < 1e-9);
});

test('missing = consensus entity absent from target; weak = present but low', () => {
  const target = {
    entities: [{ name: 'Bonus', salience: 0.5, mid: '/m/bonus', type: 'OTHER' }],
    words: 100,
  };
  // 3 competitors: all mention Bonus (strong) + RTP + License
  const competitors = [
    {
      entities: [
        { name: 'Bonus', salience: 0.5, mid: '/m/bonus', type: 'OTHER' },
        { name: 'RTP', salience: 0.3, mid: '/m/rtp', type: 'OTHER' },
        { name: 'License', salience: 0.2, mid: null, type: 'OTHER' },
      ],
      words: 500,
    },
    {
      entities: [
        { name: 'Bonus', salience: 0.5, mid: '/m/bonus', type: 'OTHER' },
        { name: 'RTP', salience: 0.3, mid: '/m/rtp', type: 'OTHER' },
        { name: 'License', salience: 0.2, mid: null, type: 'OTHER' },
      ],
      words: 600,
    },
    {
      entities: [
        { name: 'Bonus', salience: 0.5, mid: '/m/bonus', type: 'OTHER' },
        { name: 'RTP', salience: 0.3, mid: '/m/rtp', type: 'OTHER' },
      ],
      words: 400,
    },
  ];

  const r = aggregate(target, competitors, cfg);
  // RTP: coverage 3 -> consensus, absent from target -> missing
  // License: coverage 2 >= K(=2) -> consensus, absent -> missing
  const missingNames = r.missing.map((m) => m.name).sort();
  assert.deepEqual(missingNames, ['License', 'RTP']);
  // Bonus present in target with equal salience -> not weak, not missing
  assert.equal(r.weak.length, 0);
  // RTP has mid -> higher priority than License (no mid), both coverage-driven
  const rtp = r.missing.find((m) => m.name === 'RTP');
  assert.equal(rtp.coverage, 3);
  assert.equal(rtp.mid, '/m/rtp');
});

test('weak detection uses margin threshold', () => {
  const target = {
    entities: [{ name: 'RTP', salience: 0.1, mid: '/m/rtp', type: 'OTHER' }],
    words: 100,
  };
  const comp = {
    entities: [{ name: 'RTP', salience: 0.5, mid: '/m/rtp', type: 'OTHER' }],
    words: 300,
  };
  const r = aggregate(target, [comp, comp], cfg);
  // median 0.5, threshold = 0.5*(1-0.4)=0.3, target 0.1 < 0.3 -> weak
  assert.equal(r.weak.length, 1);
  assert.equal(r.weak[0].name, 'RTP');
  assert.equal(r.weak[0].target_salience, 0.1);
});

test('determinism: identical inputs -> identical output', () => {
  const target = { entities: [{ name: 'A', salience: 0.4, mid: null, type: 'OTHER' }], words: 50 };
  const comps = [
    { entities: [{ name: 'B', salience: 0.6, mid: '/m/b', type: 'OTHER' }], words: 200 },
    { entities: [{ name: 'B', salience: 0.5, mid: '/m/b', type: 'OTHER' }], words: 250 },
  ];
  const a = JSON.stringify(aggregate(target, comps, cfg));
  const b = JSON.stringify(aggregate(target, comps, cfg));
  assert.equal(a, b);
});

test('volume: median of competitor words', () => {
  const target = { entities: [{ name: 'A', salience: 0.4, mid: null, type: 'OTHER' }], words: 50 };
  const comps = [
    { entities: [{ name: 'B', salience: 0.6, mid: '/m/b', type: 'OTHER' }], words: 200 },
    { entities: [{ name: 'B', salience: 0.6, mid: '/m/b', type: 'OTHER' }], words: 400 },
    { entities: [{ name: 'B', salience: 0.6, mid: '/m/b', type: 'OTHER' }], words: 600 },
  ];
  const r = aggregate(target, comps, cfg);
  assert.equal(r.volume.median_competitor_words, 400);
  assert.equal(r.volume.target_words, 50);
});

test('preprocess keeps short lines, drops empty lines, normalizes whitespace', () => {
  const raw = 'Home\nMenu\nThis is a real sentence about bonuses.\n   \nRTP  and    license  details here now.';
  const { text, truncated } = cleanText(raw, cfg);
  // short non-empty lines are real content now — they must survive
  assert.ok(/^Home$/m.test(text));
  assert.ok(/^Menu$/m.test(text));
  assert.ok(/real sentence/.test(text));
  // the whitespace-only line is gone
  const lines = text.split('\n');
  assert.deepEqual(lines, ['Home', 'Menu', 'This is a real sentence about bonuses.', 'RTP and license details here now.']);
  // no collapsed-whitespace leftovers
  assert.ok(!/ {2,}/.test(text));
  assert.equal(truncated, false);
  assert.equal(countWords('one two three'), 3);
});

test('preprocess does not truncate realistic-length text', () => {
  // 200k chars is well within the safeguard cap — must pass through untouched
  const raw = ('word '.repeat(40000)).trim(); // 5 chars * 40000 - 1 = 199999 chars
  const { text, truncated } = cleanText(raw, cfg);
  assert.equal(truncated, false);
  assert.ok(text.length >= 199999);
});

test('preprocess truncates only past the configured cap', () => {
  const small = { maxDocChars: 100 };
  const raw = 'lorem ipsum '.repeat(50); // 600 chars, over the tiny cap
  const { text, truncated } = cleanText(raw, small);
  assert.equal(truncated, true);
  assert.ok(text.length <= 100);
});
