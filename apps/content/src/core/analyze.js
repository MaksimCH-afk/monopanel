// Orchestration: validates input, runs preprocessing + NL API per document,
// runs the deterministic aggregator, calls the LLM for intent + recommendations,
// then merges authoritative numbers (code) with text (LLM) into the §7.3 contract.

import { config } from '../config.js';
import { cleanText } from './preprocess.js';
import { aggregate } from './aggregator.js';
import { countWords } from '../util/stats.js';
import { analyzeEntities } from '../services/nl.js';
import { classifyAndRecommend } from '../services/llm.js';

// Minimum entities in a document before we call it "real content" (TZ §10).
const MIN_ENTITIES_TARGET = 2;

export class ValidationError extends Error {
  constructor(message) {
    super(message);
    this.name = 'ValidationError';
    this.status = 400;
  }
}

export function validateRequest(body) {
  if (!body || typeof body !== 'object') throw new ValidationError('Пустой запрос.');
  const query = typeof body.query === 'string' ? body.query.trim() : '';
  if (!query) throw new ValidationError('Поле «Поисковый запрос» обязательно.');

  const targetText = body.target && typeof body.target.text === 'string' ? body.target.text : '';
  if (!targetText.trim()) throw new ValidationError('Поле «Моя страница» обязательно.');

  const competitors = Array.isArray(body.competitors) ? body.competitors : [];
  const withText = competitors.filter((c) => c && typeof c.text === 'string' && c.text.trim());
  if (withText.length < 1) throw new ValidationError('Нужен минимум 1 конкурент с текстом.');
  if (withText.length > config.maxCompetitors) {
    throw new ValidationError(`Максимум ${config.maxCompetitors} конкурентов.`);
  }

  return {
    query,
    target: { label: body.target.label ?? null, text: targetText },
    competitors: withText.map((c) => ({ label: c.label ?? null, text: c.text })),
  };
}

async function analyzeDoc(rawText) {
  const { text, truncated } = cleanText(rawText, config);
  if (!text) return { ok: false, reason: 'empty', truncated };
  const words = countWords(text);
  try {
    const { entities, language } = await analyzeEntities(text);
    return { ok: true, entities, language, words, truncated, cleanText: text };
  } catch (err) {
    return { ok: false, reason: err.message, truncated, words };
  }
}

export async function runAnalysis(body) {
  const req = validateRequest(body);
  const warnings = [];

  // Fire all NL calls in parallel (target + competitors).
  const [targetRes, ...compResults] = await Promise.all([
    analyzeDoc(req.target.text),
    ...req.competitors.map((c) => analyzeDoc(c.text)),
  ]);

  if (!targetRes.ok) {
    throw new ValidationError(
      targetRes.reason === 'empty'
        ? 'После очистки в «моей странице» не осталось текста. Вставьте основной контент.'
        : `Не удалось проанализировать «мою страницу»: ${targetRes.reason}`
    );
  }
  if (targetRes.truncated) warnings.push('Текст «моей страницы» превысил лимит и был усечён.');
  if ((targetRes.entities?.length || 0) < MIN_ENTITIES_TARGET) {
    warnings.push('На «моей странице» распознано мало сущностей — возможно, недостаточно контента.');
  }

  // Split successful vs failed competitors.
  const okComps = [];
  const failedIdx = [];
  compResults.forEach((r, i) => {
    if (r.ok) {
      if (r.truncated) warnings.push(`Текст конкурента ${i + 1} был усечён по лимиту длины.`);
      okComps.push({ ...r, sourceIndex: i, label: req.competitors[i].label });
    } else {
      failedIdx.push(i + 1);
    }
  });

  if (okComps.length === 0) {
    throw new ValidationError('Ни один конкурент не обработан (пустой текст или сбой API).');
  }
  if (failedIdx.length) {
    warnings.push(`Конкуренты исключены из анализа (сбой/пустой текст): ${failedIdx.join(', ')}.`);
  }

  const smallSample = okComps.length === 1;
  if (smallSample) {
    warnings.push('Всего 1 конкурент: порог консенсуса K=1, выборка мала — выводы нестабильны.');
  }

  // Deterministic aggregation.
  const agg = aggregate(
    { entities: targetRes.entities, words: targetRes.words },
    okComps.map((c) => ({ entities: c.entities, words: c.words })),
    config
  );

  // LLM: intent + recommendations over the fixed lists.
  let llm;
  try {
    llm = await classifyAndRecommend({
      query: req.query,
      targetProfile: agg.targetProfile,
      competitorTexts: okComps.map((c) => c.cleanText.slice(0, config.llmTextChars)),
      missing: agg.missing,
      weak: agg.weak,
    });
  } catch (err) {
    warnings.push(`Классификация интента/рекомендации недоступны: ${err.message}`);
    llm = null;
  }

  return buildResponse(req, agg, llm, {
    competitorsAnalyzed: okComps.length,
    competitorsFailed: failedIdx.length,
    warnings,
    smallSample,
  });
}

// Merge authoritative numbers (agg) with LLM text. LLM entities not present in
// the aggregator lists are dropped here (TZ criterion #4).
function mergeRecommendations(aggList, llmList) {
  const byName = new Map();
  for (const r of llmList || []) {
    if (r && typeof r.name === 'string') byName.set(r.name, r.recommendation || '');
  }
  return aggList.map((item) => {
    const { _score, wikipedia_url, ...rest } = item;
    return {
      ...rest,
      wikipedia_url: wikipedia_url ?? null,
      recommendation: byName.get(item.name) || '',
    };
  });
}

function buildResponse(req, agg, llm, meta) {
  const intent = llm?.intent ?? {
    dominant: null,
    distribution: [],
    target_type: null,
    target_matches_dominant: null,
    note: 'Интент не определён (LLM недоступен).',
  };

  return {
    query: req.query,
    competitors_analyzed: meta.competitorsAnalyzed,
    competitors_failed: meta.competitorsFailed,
    consensus_threshold: agg.consensusThreshold,
    small_sample: meta.smallSample,
    warnings: meta.warnings,
    mock_mode: config.google.mock || config.openai.mock,
    intent,
    missing: mergeRecommendations(agg.missing, llm?.recommendations?.missing),
    weak: mergeRecommendations(agg.weak, llm?.recommendations?.weak),
    volume: agg.volume,
  };
}
