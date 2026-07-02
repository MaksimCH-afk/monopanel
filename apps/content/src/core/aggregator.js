// The deterministic core (TZ §6.3). Everything numeric — canonical keys,
// consensus, missing/weak diff, priority, volumes — is computed here from the
// NL-API entities. The LLM never touches these numbers.

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

function priorityFor({ coverage, n, hasMid, gap }, pcfg) {
  const score =
    pcfg.wCoverage * (coverage / n) +
    pcfg.wMid * (hasMid ? 1 : 0) +
    pcfg.wGap * gap;
  let bucket = 'low';
  if (score >= pcfg.high) bucket = 'high';
  else if (score >= pcfg.medium) bucket = 'medium';
  return { score, bucket };
}

const BUCKET_RANK = { high: 3, medium: 2, low: 1 };

function sortByPriority(a, b) {
  if (BUCKET_RANK[b.priority] !== BUCKET_RANK[a.priority]) {
    return BUCKET_RANK[b.priority] - BUCKET_RANK[a.priority];
  }
  if (b._score !== a._score) return b._score - a._score;
  if (b.coverage !== a.coverage) return b.coverage - a.coverage;
  return String(a.name).localeCompare(String(b.name));
}

/**
 * @param {{entities:Array, words:number}} target
 * @param {Array<{entities:Array, words:number}>} competitors  successful docs only
 * @param {object} config
 * @returns aggregation result (numbers only; recommendations/intent added later)
 */
export function aggregate(target, competitors, config) {
  const n = competitors.length;
  const K = Math.max(1, Math.ceil(n * config.consensusThresholdRatio));

  const targetMap = aggregateDoc(target.entities, config);
  const compMaps = competitors.map((c) => aggregateDoc(c.entities, config));

  // Build per-key competitor stats.
  const keyStats = new Map();
  for (const cm of compMaps) {
    for (const [key, v] of cm) {
      let ks = keyStats.get(key);
      if (!ks) {
        ks = {
          key,
          name: v.name,
          type: v.type,
          mid: v.mid || null,
          wikipedia_url: v.wikipedia_url || null,
          saliences: [],
          coverage: 0,
          _top: -1,
        };
        keyStats.set(key, ks);
      }
      ks.coverage += 1;
      ks.saliences.push(v.salience);
      if (!ks.mid && v.mid) ks.mid = v.mid;
      if (!ks.wikipedia_url && v.wikipedia_url) ks.wikipedia_url = v.wikipedia_url;
      // display name = surface form with the highest salience across competitors
      if (v.salience > ks._top) {
        ks._top = v.salience;
        ks.name = v.name;
        ks.type = v.type;
      }
    }
  }

  const missing = [];
  const weak = [];

  for (const ks of keyStats.values()) {
    if (ks.coverage < K) continue; // not consensus
    const medSal = median(ks.saliences);
    const hasMid = !!ks.mid;
    const targetEntity = targetMap.get(ks.key);

    const base = {
      name: ks.name,
      type: ks.type,
      mid: ks.mid || null,
      wikipedia_url: ks.wikipedia_url || null,
      coverage: ks.coverage,
      competitors_total: n,
      median_competitor_salience: round4(medSal),
    };

    if (!targetEntity) {
      const { score, bucket } = priorityFor({ coverage: ks.coverage, n, hasMid, gap: 1 }, config.priority);
      missing.push({ ...base, target_salience: null, priority: bucket, _score: score });
    } else {
      const threshold = medSal * (1 - config.weakMargin);
      if (targetEntity.salience < threshold) {
        const gap = medSal > 0 ? clamp((medSal - targetEntity.salience) / medSal, 0, 1) : 0;
        const { score, bucket } = priorityFor({ coverage: ks.coverage, n, hasMid, gap }, config.priority);
        weak.push({
          ...base,
          target_salience: round4(targetEntity.salience),
          priority: bucket,
          _score: score,
        });
      }
    }
  }

  missing.sort(sortByPriority);
  weak.sort(sortByPriority);

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

  return { consensusThreshold: K, missing, weak, volume, targetProfile };
}
