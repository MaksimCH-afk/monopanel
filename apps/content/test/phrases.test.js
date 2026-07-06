import { test } from 'node:test';
import assert from 'node:assert/strict';
import { config } from '../src/config.js';
import { aggregatePhrases } from '../src/core/aggregator.js';
import { buildPhraseProfile } from '../src/core/phrases.js';
import { codeEntities } from '../src/services/nl.js';
import { detectLanguage } from '../src/services/lang.js';

// Build a phrase unit map directly (key = phrase, salience = density).
function phraseMap(entries) {
  const m = new Map();
  for (const [key, n, salience] of entries) {
    m.set(key, { key, name: key, n, count: 2, salience });
  }
  return m;
}

test('phrase track reuses the aggregator: consensus + missing/weak (§5.3)', () => {
  const competitors = [
    { phraseMap: phraseMap([['casino bonus', 2, 0.1], ['welcome', 1, 0.05]]) },
    { phraseMap: phraseMap([['casino bonus', 2, 0.12], ['welcome', 1, 0.05]]) },
  ];
  // target has "welcome" at parity but is MISSING "casino bonus"
  const target = { phraseMap: phraseMap([['welcome', 1, 0.05]]) };

  const r = aggregatePhrases(target, competitors, config);
  const missing = r.missing.map((m) => m.phrase);
  assert.ok(missing.includes('casino bonus'));
  assert.equal(r.weak.length, 0);

  const cb = r.missing.find((m) => m.phrase === 'casino bonus');
  assert.equal(cb.n, 2);
  assert.equal(cb.coverage, 2);
  assert.equal(cb.target_density, null);
  assert.ok(['high', 'medium', 'low'].includes(cb.priority));
});

test('phrase weak: present but below 60% of competitor median density', () => {
  const competitors = [
    { phraseMap: phraseMap([['free spins', 2, 0.1]]) },
    { phraseMap: phraseMap([['free spins', 2, 0.1]]) },
  ];
  const target = { phraseMap: phraseMap([['free spins', 2, 0.01]]) }; // 0.01 < 0.1*0.6
  const r = aggregatePhrases(target, competitors, config);
  assert.equal(r.weak.length, 1);
  assert.equal(r.weak[0].phrase, 'free spins');
  assert.equal(r.missing.length, 0);
});

test('phrase priority has no mid term; longer n-grams get a specificity bonus (§5.4)', () => {
  // empty target → both phrases are missing with identical coverage and gap=1,
  // so any score difference comes purely from the n-gram specificity bonus.
  const target = { phraseMap: phraseMap([]) };
  // 4 competitors, both phrases in exactly 2 → coverage/total = 0.5 so the base
  // score stays below 1 and the specificity bonus is not clamped away.
  const withBoth = { phraseMap: phraseMap([['bonus', 1, 0.1], ['best casino bonus', 3, 0.1]]) };
  const empty = { phraseMap: phraseMap([]) };
  const comp = [withBoth, withBoth, empty, empty];
  const missing = aggregatePhrases(target, comp, config).missing;
  const rUni = missing.find((m) => m.phrase === 'bonus');
  const rTri = missing.find((m) => m.phrase === 'best casino bonus');
  assert.ok(rUni && rTri);
  // trigram scores strictly higher thanks to the specificity bonus
  assert.ok(rTri._score > rUni._score);
});

test('NL fallback: codeEntities builds unigram-density entities, no mid (§4.4/§8.1)', () => {
  const text = 'Casino bonus casino bonus casino welcome spins. Casino games and bonus offers.';
  const { entities } = codeEntities(text);
  assert.ok(entities.length > 0);
  assert.ok(entities.every((e) => e.mid === null)); // not the Knowledge Graph
  assert.ok(entities.every((e) => e.salience > 0));
  // "casino" is the most frequent content word → highest salience
  assert.equal(entities[0].name.toLowerCase(), 'casino');
});

test('phrase profile volume: sentences (with 。) and lexical density (§8.2)', () => {
  const lang = detectLanguage('这是一个测试。北京很大。'); // -> zh
  const prof = buildPhraseProfile('这是一个测试。北京很大。', lang, config);
  assert.equal(prof.sentenceCount, 2);
  assert.ok(prof.lexicalDensity > 0 && prof.lexicalDensity <= 1);
  assert.ok(prof.tokenCount > 0);
});

test('backward compatibility: phrase filtering respects min count + top-N', () => {
  // hapax phrases (count 1) are dropped by NGRAM_MIN_COUNT=2 default
  const lang = detectLanguage('casino bonus casino bonus welcome offer spins jackpot roulette');
  const prof = buildPhraseProfile(
    'casino bonus casino bonus welcome offer spins jackpot roulette',
    lang,
    config
  );
  // "casino" and "bonus" repeat (count 2) → kept; singletons dropped
  assert.ok(prof.phraseMap.has('casino'));
  assert.ok(prof.phraseMap.has('bonus'));
  assert.equal(prof.phraseMap.has('roulette'), false);
});
