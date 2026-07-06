// Analysis history. Each completed analysis is saved so the operator can reopen
// it later. Persisted best-effort to CONTENT_DATA_DIR/history.json (the same
// mounted volume as the API keys), newest first, capped to HISTORY_MAX entries.
// Stored payload is the analysis RESULT (numbers + recommendations), not the raw
// pasted page text.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = process.env.CONTENT_DATA_DIR || path.join(__dirname, '..', '..', '.data');
const FILE = path.join(DATA_DIR, 'history.json');
const MAX = Number(process.env.HISTORY_MAX) || 50;

let entries = []; // newest first

function load() {
  try {
    const j = JSON.parse(fs.readFileSync(FILE, 'utf8'));
    if (Array.isArray(j)) entries = j;
  } catch {
    /* no history yet */
  }
}
load();

function persist() {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(FILE, JSON.stringify(entries), { mode: 0o600 });
    return true;
  } catch {
    return false; // read-only fs — history stays in memory for this run
  }
}

// Number of result items, for the list summary.
function itemCount(result) {
  if (result.mode === 'compare') {
    return (result.missing?.length || 0) + (result.weak?.length || 0);
  }
  return result.consensus_profile?.length || 0;
}

/** Save a completed analysis; annotates the result with its history_id. */
export function addAnalysis(result) {
  const id = Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
  const ts = new Date().toISOString();
  result.history_id = id;
  entries.unshift({
    id,
    ts,
    query: result.query || '',
    mode: result.mode || 'compare',
    competitors_analyzed: result.competitors_analyzed ?? null,
    items: itemCount(result),
    result,
  });
  if (entries.length > MAX) entries.length = MAX;
  persist();
  return { id, ts };
}

/** Lightweight list (no full results) for the history panel. */
export function listAnalyses() {
  return entries.map((e) => ({
    id: e.id,
    ts: e.ts,
    query: e.query,
    mode: e.mode,
    competitors_analyzed: e.competitors_analyzed,
    items: e.items,
  }));
}

/** Full stored result for one entry, or null. */
export function getAnalysis(id) {
  const e = entries.find((x) => x.id === id);
  return e ? e.result : null;
}

/** Remove one entry. Returns whether something was deleted. */
export function deleteAnalysis(id) {
  const before = entries.length;
  entries = entries.filter((e) => e.id !== id);
  const removed = entries.length < before;
  if (removed) persist();
  return removed;
}
