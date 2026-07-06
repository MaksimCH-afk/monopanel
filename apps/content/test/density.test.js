import { test } from 'node:test';
import assert from 'node:assert/strict';
import { countNgrams } from '../src/core/ngrams.js';
import { density } from '../src/core/density.js';

// Helper: build a single-sentence token stream from a flat token list.
const oneSentence = (tokens) => countNgrams([tokens], 4);

// The density contract (TZ §6.3, §6.4). The denominator N (total tokens) is
// COMMON to every n-level — this is the whole point of the examples below.

test('Example А: word and phrase that always co-occur have EQUAL density', () => {
  // "casino online" repeated 13×  → N = 26 tokens, casino never appears alone
  const tokens = [];
  for (let i = 0; i < 13; i++) tokens.push('casino', 'online');
  const { levels, N } = oneSentence(tokens);
  assert.equal(N, 26);

  const casino = levels.get(1).get('casino').count;
  const phrase = levels.get(2).get('casino online').count;
  assert.equal(casino, 13);
  assert.equal(phrase, 13);
  // equal counts AND common denominator → equal density
  assert.equal(density(casino, N), density(phrase, N));
  assert.equal(density(casino, N), 0.5);
});

test('Example Б: adding 6 lone "casino" splits the densities apart', () => {
  const tokens = [];
  for (let i = 0; i < 13; i++) tokens.push('casino', 'online');
  for (let i = 0; i < 6; i++) tokens.push('casino'); // 6 standalone occurrences
  const { levels, N } = oneSentence(tokens);
  assert.equal(N, 32);

  const casino = levels.get(1).get('casino').count;
  const phrase = levels.get(2).get('casino online').count;
  assert.equal(casino, 19); // 13 inside phrase + 6 alone
  assert.equal(phrase, 13); // phrase count unchanged
  assert.notEqual(density(casino, N), density(phrase, N));
  assert.equal(density(casino, N), 19 / 32);
  assert.equal(density(phrase, N), 13 / 32);
});

test('Overlap rule §6.2: a token inside a phrase still counts as a unigram', () => {
  // every "casino" here occurs only inside the bigram, yet the unigram count
  // includes those occurrences — levels are counted independently.
  const tokens = [];
  for (let i = 0; i < 5; i++) tokens.push('casino', 'online');
  const { levels } = oneSentence(tokens);
  assert.equal(levels.get(1).get('casino').count, 5);
  assert.equal(levels.get(2).get('casino online').count, 5);
});

test('n-grams do not cross a sentence boundary (§5.1)', () => {
  // two sentences; "online welcome" must NOT be formed across the boundary
  const { levels, N } = countNgrams([['casino', 'online'], ['welcome', 'bonus']], 4);
  assert.equal(N, 4);
  assert.equal(levels.get(2).has('online welcome'), false);
  assert.equal(levels.get(2).get('casino online').count, 1);
  assert.equal(levels.get(2).get('welcome bonus').count, 1);
});

test('trigrams (n=3) are generated as a first-class level (§9)', () => {
  const { levels } = countNgrams([['best', 'online', 'casino', 'bonus']], 4);
  assert.ok(levels.has(3));
  assert.equal(levels.get(3).get('best online casino').count, 1);
  assert.equal(levels.get(3).get('online casino bonus').count, 1);
  assert.equal(levels.get(4).get('best online casino bonus').count, 1);
});
