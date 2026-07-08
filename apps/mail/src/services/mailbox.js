// Repository facade: routes each read/write either to the live D1 client or to
// the deterministic mock, based on config.mockMode. The server talks only to
// this module so the HTTP layer never has to branch on mock vs live.

import { config } from '../config.js';
import * as d1 from './d1.js';
import * as mock from '../mock/data.js';

export async function listMailboxes() {
  return config.mockMode ? mock.mockListMailboxes() : d1.listMailboxes();
}

export async function listMessages(mailbox, opts) {
  return config.mockMode ? mock.mockListMessages(mailbox, opts) : d1.listMessages(mailbox, opts);
}

export async function getMessage(id) {
  return config.mockMode ? mock.mockGetMessage(id) : d1.getMessage(id);
}

export async function deleteMessage(id) {
  if (config.mockMode) return 0; // mock is read-only
  return d1.deleteMessage(id);
}

export async function deleteOlderThan(isoTs) {
  if (config.mockMode) return 0;
  return d1.deleteOlderThan(isoTs);
}

export async function ping() {
  return config.mockMode ? mock.mockPing() : d1.ping();
}
