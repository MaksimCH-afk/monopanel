// The deterministic core (TZ §6.3). Everything numeric — canonical keys,
// consensus, missing/weak diff, priority, volumes — is computed here. The LLM
// never touches these numbers.
//
// Two analysis tracks share one engine (TZ §3): the ENTITY track (Google NL
// salience) and the PHRASE track (code-only n-gram density). The consensus /
// median / missing-weak / priority machinery is generic over the "unit" of
// analysis and lives in `diffConsensus`; each track supplies hooks for its own
// key fields, priority formula and output shape.

import { median, clamp } from '../util/stats.js';

function normalizeName(name) {
  return String(name || '').toLowerCase().trim().replace(/\s+/g, ' ');
}

// Canonical key: mid when present (canonical KG entity), else normalized name.
// This glues the same entity together across competitors. TZ §6.3.
export function canonicalKey(entity) {
  if (entity.mid) return `mid:${entity.mid}`;
  return `name:${normalizeName(entity.name)}`;
}

const round4 = (v) => Math.round(v * 1e4) / 1e4;
const round6 = (v) => Math.round(v * 1e6) / 1e6;

// Collapse one document's raw entity list into a map keyed by canonical key.
// Duplicate keys within a doc have their salience summed (salience is an
// additive share of the document). Entities below the noise floor are dropped.
export function aggregateDoc(entities, { salienceMin }) {
  const map = new Map();
  for (const e of entities || []) {
    const key = canonicalKey(e);
    const sal = Number(e.salience) || 0;
    const cur = map.get(key);
    if (cur) {
      cur.salience += sal;
      if (!cur.mid && e.mid) cur.mid = e.mid;
      if (!cur.wikipedia_url && e.wikipedia_url) cur.wikipedia_url = e.wikipedia_url;
      if (sal > cur._top) {
        cur._top = sal;
        cur.name = e.name;
        cur.type = e.type;
      }
    } else {
      map.set(key, {
        key,
        name: e.name,
        type: e.type,
        salience: sal,
        mid: e.mid || null,
        wikipedia_url: e.wikipedia_url || null,
        _top: sal,
      });
    }
  }
  // apply noise floor on the aggregated salience
  const out = new Map();
  for (const [k, v] of map) {
    if (v.salience >= salienceMin) {
      delete v._top;
      out.set(k, v);
    }
  }
  return out;
}

function bucketFor(score, { high, medium }) {
  let bucket = 'low';
  if (score >= high) bucket = 'high';
  else if (score >= medium) bucket = 'medium';
  return { score, bucket };
}

const BUCKET_RANK = { high: 3, medium: 2, low: 1 };
const rowLabel = (r) => r.name ?? r.phrase ?? '';

function sortByPriority(a, b) {
  if (BUCKET_RANK[b.priority] !== BUCKET_RANK[a.priority]) {
    return BUCKET_RANK[b.priority] - BUCKET_RANK[a.priority];
  }
  if (b._score !== a._score) return b._score - a._score;
  if (b.coverage !== a.coverage) return b.coverage - a.coverage;
  return String(rowLabel(a)).localeCompare(String(rowLabel(b)));
}

/**
 * Generic consensus + missing/weak diff over one analysis track. Works on any
 * "unit" that exposes a numeric `salience` (entity salience or phrase density).
 *
 * @param {Map<string,object>} targetMap  target units keyed by canonical key
 * @param {Array<Map<string,object>>} compMaps  competitor unit maps (success only)
 * @param {object} cfg  needs {consensusThresholdRatio, weakMargin}
 * @param {{init:Function, merge:Function, priorityFor:Function, makeRow:Function}} hooks
 * @returns {{consensusThreshold:number, missing:Array, weak:Array}}
 */
function diffConsensus(targetMap, compMaps, cfg, hooks) {
  const total = compMaps.length;
  const K = Math.max(1, Math.ceil(total * cfg.consensusThresholdRatio));

  // Per-key competitor stats (coverage + salience samples + display fields).
  const keyStats = new Map();
  for (const cm of compMaps) {
    for (const [key, unit] of cm) {
      let ks = keyStats.get(key);
      if (!ks) {
        ks = { key, coverage: 0, saliences: [], ...hooks.init(unit) };
        keyStats.set(key, ks);
      }
      ks.coverage += 1;
      ks.saliences.push(unit.salience);
      hooks.merge(ks, unit);
    }
  }

  const missing = [];
  const weak = [];
  for (const ks of keyStats.values()) {
    if (ks.coverage < K) continue; // not consensus
    const medSal = median(ks.saliences);
    const targetUnit = targetMap.get(ks.key) || null;

    if (!targetUnit) {
      const gap = 1;
      const { score, bucket } = hooks.priorityFor({ ks, total, gap });
      missing.push(hooks.makeRow({ ks, total, medSal, targetUnit: null, gap, score, bucket }));
    } else {
      const threshold = medSal * (1 - cfg.weakMargin);
      if (targetUnit.salience < threshold) {
        const gap = medSal > 0 ? clamp((medSal - targetUnit.salience) / medSal, 0, 1) : 0;
        const { score, bucket } = hooks.priorityFor({ ks, total, gap });
        weak.push(hooks.makeRow({ ks, total, medSal, targetUnit, gap, score, bucket }));
      }
    }
  }

  missing.sort(sortByPriority);
  weak.sort(sortByPriority);
  return { consensusThreshold: K, missing, weak };
}

// ── Entity track hooks ───────────────────────────────────────────────────────
function entityHooks(config) {
  return {
    init: (u) => ({
      name: u.name,
      type: u.type,
      mid: u.mid || null,
      wikipedia_url: u.wikipedia_url || null,
      _top: -1,
    }),
    merge: (ks, u) => {
      if (!ks.mid && u.mid) ks.mid = u.mid;
      if (!ks.wikipedia_url && u.wikipedia_url) ks.wikipedia_url = u.wikipedia_url;
      // display name = surface form with the highest salience across competitors
      if (u.salience > ks._top) {
        ks._top = u.salience;
        ks.name = u.name;
        ks.type = u.type;
      }
    },
    priorityFor: ({ ks, total, gap }) => {
      const p = config.priority;
      const score = p.wCoverage * (ks.coverage / total) + p.wMid * (ks.mid ? 1 : 0) + p.wGap * gap;
      return bucketFor(score, p);
    },
    makeRow: ({ ks, total, medSal, targetUnit, bucket, score }) => {
      const base = {
        name: ks.name,
        type: ks.type,
        mid: ks.mid || null,
        wikipedia_url: ks.wikipedia_url || null,
        coverage: ks.coverage,
        competitors_total: total,
        median_competitor_salience: round4(medSal),
      };
      return {
        ...base,
        target_salience: targetUnit ? round4(targetUnit.salience) : null,
        priority: bucket,
        _score: score,
      };
    },
  };
}

// ── Phrase track hooks (no `mid`; rebalanced priority + specificity bonus) ────
function phraseHooks(config) {
  const pc = config.phrasePriority;
  return {
    init: (u) => ({ name: u.name, n: u.n, _top: -1 }),
    merge: (ks, u) => {
      if (u.salience > ks._top) {
        ks._top = u.salience;
        ks.name = u.name;
        ks.n = u.n;
      }
    },
    priorityFor: ({ ks, total, gap }) => {
      let score = pc.wCoverage * (ks.coverage / total) + pc.wGap * gap;
      // longer phrase = more specific/valuable query → gentle specificity bonus
      if (ks.n > 2) score += pc.specificity * (ks.n - 2);
      return bucketFor(clamp(score, 0, 1), pc);
    },
    makeRow: ({ ks, total, medSal, targetUnit, gap, bucket, score }) => ({
      phrase: ks.name,
      n: ks.n,
      coverage: ks.coverage,
      competitors_total: total,
      median_density: round6(medSal),
      target_density: targetUnit ? round6(targetUnit.salience) : null,
      gap: round4(gap),
      priority: bucket,
      _score: score,
    }),
  };
}

/**
 * ENTITY track aggregation (Google NL salience).
 * @param {{entities:Array, words:number}} target
 * @param {Array<{entities:Array, words:number}>} competitors  successful docs only
 * @param {object} config
 * @returns aggregation result (numbers only; recommendations/intent added later)
 */
export function aggregate(target, competitors, config) {
  const n = competitors.length;
  const targetMap = aggregateDoc(target.entities, config);
  const compMaps = competitors.map((c) => aggregateDoc(c.entities, config));

  const { consensusThreshold, missing, weak } = diffConsensus(
    targetMap,
    compMaps,
    config,
    entityHooks(config)
  );

  const competitorWords = competitors.map((c) => c.words);
  const volume = {
    target_words: target.words,
    median_competitor_words: Math.round(median(competitorWords)),
    competitor_words: competitorWords,
  };

  // Compact target entity profile for the LLM (top entities by salience).
  const targetProfile = [...targetMap.values()]
    .sort((a, b) => b.salience - a.salience)
    .slice(0, 40)
    .map((e) => ({ name: e.name, type: e.type, salience: round4(e.salience), mid: e.mid || null }));

  return { consensusThreshold, missing, weak, volume, targetProfile };
}

/**
 * PHRASE track aggregation (code-only n-gram density). Reuses the same consensus
 * engine as entities, keyed on the normalized phrase (TZ §3, §5.3).
 * @param {{phraseMap:Map}} target
 * @param {Array<{phraseMap:Map}>} competitors
 * @param {object} config
 * @returns {{consensusThreshold:number, missing:Array, weak:Array}}
 */
export function aggregatePhrases(target, competitors, config) {
  const targetMap = target.phraseMap || new Map();
  const compMaps = competitors.map((c) => c.phraseMap || new Map());
  return diffConsensus(targetMap, compMaps, config, phraseHooks(config));
}
