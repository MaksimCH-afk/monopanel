import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  tokenizeWords,
  splitSentences,
  segmentDocument,
  icuSegmentationOk,
} from '../src/core/segment.js';

test('CJK dictionary segmentation: 北京 is ONE word, not 北 + 京 (§4.2)', () => {
  const zh = tokenizeWords('北京和上海', 'zh');
  assert.deepEqual(zh, ['北京', '和', '上海']);
  // and the infra self-check agrees full-icu is present
  assert.equal(icuSegmentationOk(), true);
});

test('Latin/Cyrillic tokenization + case + punctuation stripping', () => {
  assert.deepEqual(tokenizeWords('Best, online CASINO!', 'en'), ['best', 'online', 'casino']);
  assert.deepEqual(tokenizeWords('Лучшее  онлайн-казино', 'ru'), ['лучшее', 'онлайн', 'казино']);
});

test('Arabic (RTL) tokenizes into words without error (§12)', () => {
  const ar = tokenizeWords('أفضل كازينو على الإنترنت', 'ar');
  assert.ok(ar.length >= 3);
  assert.ok(ar.every((t) => t.length > 0));
});

test('sentence splitting handles non-Latin terminators like 。 (§4.3)', () => {
  assert.equal(splitSentences('第一句话。第二句话。', 'zh').length, 2);
  assert.equal(splitSentences('First sentence. Second one!', 'en').length, 2);
});

test('segmentDocument: flat token count = N, tokens grouped by sentence', () => {
  const doc = segmentDocument('Casino bonus. Free spins today.', 'en');
  assert.equal(doc.sentences.length, 2);
  assert.equal(doc.tokens.length, doc.sentenceTokens.flat().length);
  assert.equal(doc.tokens.length, 5); // casino bonus free spins today
});
