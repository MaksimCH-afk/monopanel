import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildStopwordSet,
  parseCustomStopwords,
  isStopgram,
  isStructuralNoise,
} from '../src/core/stopwords.js';

test('stopgram rule §7.2: drop ONLY when every token is a stopword (English)', () => {
  const en = buildStopwordSet('en');
  // "on our site" — all service words → dropped
  assert.equal(isStopgram(['on', 'our', 'site'], en), true);
  // "casino online" — has meaningful words → kept
  assert.equal(isStopgram(['casino', 'online'], en), false);
  // one meaningful token is enough to keep the phrase
  assert.equal(isStopgram(['the', 'casino'], en), false);
});

test('stopgram rule works on a second language (Russian)', () => {
  const ru = buildStopwordSet('ru');
  assert.equal(isStopgram(['и', 'в', 'на'], ru), true); // all stopwords
  assert.equal(isStopgram(['казино', 'онлайн'], ru), false); // meaningful
});

test('unsupported/undetermined language → empty dictionary, phrases survive (§7.1)', () => {
  const none = buildStopwordSet(null);
  assert.equal(none.size, 0);
  assert.equal(isStopgram(['казино', 'онлайн'], none), false);
});

test('custom operator stopwords augment the set (§7.4)', () => {
  const set = buildStopwordSet('en', parseCustomStopwords('BrandName, promo'));
  assert.equal(set.has('brandname'), true);
  assert.equal(isStopgram(['brandname', 'promo'], set), true); // now both are stop
});

test('structural filters §7.3: numbers/single latin letters are noise, CJK singleton is not', () => {
  assert.equal(isStructuralNoise('123'), true);
  assert.equal(isStructuralNoise('a'), true); // lone latin letter
  assert.equal(isStructuralNoise('казино'), false);
  assert.equal(isStructuralNoise('京'), false); // single CJK char can be a word
});

test('parseCustomStopwords splits on comma/space/newline and lowercases', () => {
  assert.deepEqual(parseCustomStopwords('Foo, bar\nBaz qux'), ['foo', 'bar', 'baz', 'qux']);
  assert.deepEqual(parseCustomStopwords(''), []);
  assert.deepEqual(parseCustomStopwords(null), []);
});
