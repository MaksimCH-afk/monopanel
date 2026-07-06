// Orchestration: validates input, runs preprocessing + language detection per
// document, then TWO parallel tracks (TZ §3):
//   • ENTITY track  — Google NL analyzeEntities (with a code fallback, §8.1),
//   • PHRASE track  — code-only n-gram density (§5-§6),
// both fed through the same deterministic aggregator. The LLM adds intent +
// recommendation text over the fixed lists; it never computes numbers.

import { config } from '../config.js';
import { cleanText } from './preprocess.js';
import { aggregate, aggregatePhrases } from './aggregator.js';
import { buildPhraseProfile } from './phrases.js';
import { countWords, median } from '../util/stats.js';
import { analyzeEntities, codeEntities } from '../services/nl.js';
import { classifyAndRecommend } from '../services/llm.js';
import { detectLanguage, dominantLanguage } from '../services/lang.js';
import { parseCustomStopwords } from './stopwords.js';

// Minimum entities in a document before we call it "real content" (TZ §10).
const MIN_ENTITIES_TARGET = 2;
const round4 = (v) => Math.round(v * 1e4) / 1e4;

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

  // Optional per-request custom stopwords (§7.4): string or array of strings.
  let customStopwords = '';
  if (typeof body.custom_stopwords === 'string') customStopwords = body.custom_stopwords;
  else if (Array.isArray(body.custom_stopwords)) customStopwords = body.custom_stopwords.join(' ');

  return {
    query,
    target: { label: body.target.label ?? null, text: targetText },
    competitors: withText.map((c) => ({ label: c.label ?? null, text: c.text })),
    customStopwords,
  };
}

async function analyzeDoc(rawText, customTerms) {
  const { text, truncated } = cleanText(rawText, config);
  if (!text) return { ok: false, reason: 'empty', truncated };

  const lang = detectLanguage(text);
  const words = countWords(text);
  // PHRASE track profile (always code-only, independent of NL availability).
  const phrase = buildPhraseProfile(text, lang, config, customTerms);

  // ENTITY track: live NL, or code-derived entities as fallback (§8.1).
  let entities;
  let mode; // 'nl' | 'code'
  let degraded = false;
  let reason = null;
  try {
    const r = await analyzeEntities(text);
    entities = r.entities;
    mode = config.google.mock ? 'code' : 'nl';
  } catch (err) {
    entities = codeEntities(text, lang).entities;
    mode = 'code';
    degraded = true;
    reason = err.message;
  }

  return { ok: true, entities, lang, words, truncated, cleanText: text, phrase, mode, degraded, reason };
}

export async function runAnalysis(body) {
  const req = validateRequest(body);
  const warnings = [];

  // Custom stopwords = env defaults + per-request, normalized once.
  const customTerms = [
    ...parseCustomStopwords(config.stopwords.custom),
    ...parseCustomStopwords(req.customStopwords),
  ];

  // Fire all NL calls in parallel (target + competitors).
  const [targetRes, ...compResults] = await Promise.all([
    analyzeDoc(req.target.text, customTerms),
    ...req.competitors.map((c) => analyzeDoc(c.text, customTerms)),
  ]);

  if (!targetRes.ok) {
    throw new ValidationError(
      targetRes.reason === 'empty'
        ? 'После очистки в «моей странице» не осталось текста. Вставьте основной контент.'
        : `Не удалось проанализировать «мою страницу»: ${targetRes.reason}`
    );
  }
  if (targetRes.truncated) warnings.push('Текст «моей страницы» превысил лимит и был усечён.');
  if (targetRes.degraded) {
    warnings.push(`NL API недоступен для «моей страницы» — использован кодовый анализ плотности (${targetRes.reason}).`);
  }
  if ((targetRes.entities?.length || 0) < MIN_ENTITIES_TARGET) {
    warnings.push('На «моей странице» распознано мало сущностей — возможно, недостаточно контента.');
  }

  // Split successful vs failed competitors.
  const okComps = [];
  const failedIdx = [];
  const degradedIdx = [];
  compResults.forEach((r, i) => {
    if (r.ok) {
      if (r.truncated) warnings.push(`Текст конкурента ${i + 1} был усечён по лимиту длины.`);
      if (r.degraded) degradedIdx.push(i + 1);
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
  if (degradedIdx.length) {
    warnings.push(`NL API недоступен для конкурентов ${degradedIdx.join(', ')} — использован кодовый анализ плотности.`);
  }

  const smallSample = okComps.length === 1;
  if (smallSample) {
    warnings.push('Всего 1 конкурент: порог консенсуса K=1, выборка мала — выводы нестабильны.');
  }

  // ENTITY track aggregation.
  const agg = aggregate(
    { entities: targetRes.entities, words: targetRes.words },
    okComps.map((c) => ({ entities: c.entities, words: c.words })),
    config
  );

  // PHRASE track aggregation (same engine, phrase units).
  const phraseGap = aggregatePhrases(
    { phraseMap: targetRes.phrase.phraseMap },
    okComps.map((c) => ({ phraseMap: c.phrase.phraseMap })),
    config
  );

  // Volume extras (§8.2): sentences + lexical density alongside words.
  const compSentences = okComps.map((c) => c.phrase.sentenceCount);
  const compLexical = okComps.map((c) => c.phrase.lexicalDensity);
  agg.volume.sentences = targetRes.phrase.sentenceCount;
  agg.volume.median_competitor_sentences = Math.round(median(compSentences));
  agg.volume.lexical_density = round4(targetRes.phrase.lexicalDensity);
  agg.volume.median_competitor_lexical_density = round4(median(compLexical));

  // Entities mode indicator (§10): did the entity track run on NL or code?
  const modes = [targetRes.mode, ...okComps.map((c) => c.mode)];
  let entitiesMode = 'nl';
  if (modes.every((m) => m === 'code')) entitiesMode = 'code';
  else if (modes.some((m) => m === 'code')) entitiesMode = 'mixed';

  // LLM: intent + recommendations over the fixed entity AND phrase lists.
  let llm;
  try {
    llm = await classifyAndRecommend({
      query: req.query,
      targetProfile: agg.targetProfile,
      competitorTexts: okComps.map((c) => c.cleanText.slice(0, config.llmTextChars)),
      missing: agg.missing,
      weak: agg.weak,
      phrasesMissing: phraseGap.missing,
      phrasesWeak: phraseGap.weak,
    });
  } catch (err) {
    warnings.push(`Классификация интента/рекомендации недоступны: ${err.message}`);
    llm = null;
  }

  return buildResponse(req, agg, phraseGap, llm, {
    competitorsAnalyzed: okComps.length,
    competitorsFailed: failedIdx.length,
    warnings,
    smallSample,
    language: { target: targetRes.lang.code, dominant: dominantLanguage([targetRes.lang, ...okComps.map((c) => c.lang)]) },
    entitiesMode,
  });
}

// Merge authoritative numbers (agg) with LLM text. LLM items not present in the
// aggregator lists are dropped here (TZ criterion — model can't invent). Keyed
// by `keyField` so the same helper serves entities (name) and phrases (phrase).
function mergeRecommendations(aggList, llmList, keyField = 'name') {
  const byKey = new Map();
  for (const r of llmList || []) {
    const k = r?.[keyField];
    if (typeof k === 'string') byKey.set(k, r.recommendation || '');
  }
  return aggList.map((item) => {
    const { _score, ...rest } = item;
    return { ...rest, recommendation: byKey.get(item[keyField]) || '' };
  });
}

function buildResponse(req, agg, phraseGap, llm, meta) {
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
    language: meta.language,
    entities_mode: meta.entitiesMode,
    intent,
    missing: mergeRecommendations(agg.missing, llm?.recommendations?.missing),
    weak: mergeRecommendations(agg.weak, llm?.recommendations?.weak),
    phrase_gap: {
      missing: mergeRecommendations(phraseGap.missing, llm?.phrase_recommendations?.missing, 'phrase'),
      weak: mergeRecommendations(phraseGap.weak, llm?.phrase_recommendations?.weak, 'phrase'),
    },
    volume: agg.volume,
  };
}
