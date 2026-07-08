import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  mockListMailboxes,
  mockListMessages,
  mockGetMessage,
  mockPing,
} from '../src/mock/data.js';

test('mock: mailboxes aggregate by recipient, newest first', () => {
  const boxes = mockListMailboxes();
  assert.ok(boxes.length >= 3, 'expected several demo mailboxes');
  // counts sum to total messages
  const total = boxes.reduce((n, b) => n + b.count, 0);
  assert.equal(total, mockPing().total);
  // sorted by last_at descending
  for (let i = 1; i < boxes.length; i++) {
    assert.ok(boxes[i - 1].last_at >= boxes[i].last_at, 'mailboxes not sorted by recency');
  }
});

test('mock: messages filtered by mailbox and by search', () => {
  const boxes = mockListMailboxes();
  const box = boxes[0].mailbox;
  const all = mockListMessages(box);
  assert.ok(all.length > 0);
  assert.ok(all.every((m) => 'subject' in m && !('text_body' in m)), 'list should be headers only');

  // search narrows results
  const term = all[0].subject.split(' ')[0];
  const found = mockListMessages(box, { search: term });
  assert.ok(found.length >= 1);
  const none = mockListMessages(box, { search: 'zzz-nonexistent-zzz' });
  assert.equal(none.length, 0);
});

test('mock: getMessage returns the full row incl. bodies', () => {
  const msg = mockGetMessage(1);
  assert.ok(msg);
  assert.equal(msg.id, 1);
  assert.ok('text_body' in msg && 'html_body' in msg);
  assert.equal(mockGetMessage(9999), null);
});
