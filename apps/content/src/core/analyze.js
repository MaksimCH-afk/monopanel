// Orchestration: validates input, runs preprocessing + language detection per
// document, then TWO parallel tracks (TZ §3):
//   • ENTITY track  — Google NL analyzeEntities (with a code fallback, §8.1),
//   • PHRASE track  — code-only n-gram density (§5-§6),
// both fed through the same deterministic aggregator. The LLM adds intent +
// recommendation text over the fixed lists; it never computes numbers.

import { config } from '../config.js';
import { cleanText } from './preprocess.js';
import {
  aggregate,
  aggregatePhrases,
  aggregateProfile,
  aggregatePhrasesProfile,
} from './aggregator.js';
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

  // Mode (TZ §3): "compare" (default) needs my page; "competitors_only" doesn't.
  const mode = body.mode === 'competitors_only' ? 'competitors_only'
    : body.mode === 'compare' ? 'compare'
    : config.defaultMode === 'competitors_only' ? 'competitors_only' : 'compare';

  const targetText = body.target && typeof body.target.text === 'string' ? body.target.text : '';
  // Mode A: my page is required. Mode B: ignored even if sent (§4.1, §8).
  if (mode === 'compare' && !targetText.trim()) {
    throw new ValidationError('Поле «Моя страница» обязательно.');
  }

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
    mode,
    target: mode === 'compare' ? { label: body.target.label ?? null, text: targetText } : null,
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
  // Custom stopwords = env defaults + per-request, normalized once.
  const customTerms = [
    ...parseCustomStopwords(config.stopwords.custom),
    ...parseCustomStopwords(req.customStopwords),
  ];
  return req.mode === 'competitors_only'
    ? runCompetitorsOnly(req, customTerms)
    : runCompare(req, customTerms);
}

// ── Shared helpers ───────────────────────────────────────────────────────────

// Split competitor results into ok/failed, pushing the standard warnings.
// Same behavior in both modes (TZ §4.1). Throws if none succeed.
function splitCompetitors(compResults, competitors, warnings) {
  const okComps = [];
  const failedIdx = [];
  const degradedIdx = [];
  compResults.forEach((r, i) => {
    if (r.ok) {
      if (r.truncated) warnings.push(`Текст конкурента ${i + 1} был усечён по лимиту длины.`);
      if (r.degraded) degradedIdx.push(i + 1);
      okComps.push({ ...r, sourceIndex: i, label: competitors[i].label });
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
  return { okComps, failedCount: failedIdx.length };
}

function entitiesModeOf(modes) {
  if (modes.every((m) => m === 'code')) return 'code';
  if (modes.some((m) => m === 'code')) return 'mixed';
  return 'nl';
}

// ── Mode A: compare against my page ──────────────────────────────────────────
async function runCompare(req, customTerms) {
  const warnings = [];

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

  const { okComps, failedCount } = splitCompetitors(compResults, req.competitors, warnings);
  const smallSample = okComps.length === 1;
  if (smallSample) {
    warnings.push('Всего 1 конкурент: порог консенсуса K=1, выборка мала — выводы нестабильны.');
  }

  // ENTITY + PHRASE diff tracks (same engine).
  const agg = aggregate(
    { entities: targetRes.entities, words: targetRes.words },
    okComps.map((c) => ({ entities: c.entities, words: c.words })),
    config
  );
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

  const entitiesMode = entitiesModeOf([targetRes.mode, ...okComps.map((c) => c.mode)]);

  let llm;
  try {
    llm = await classifyAndRecommend({
      mode: 'compare',
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
    competitorsFailed: failedCount,
    warnings,
    smallSample,
    language: {
      target: targetRes.lang.code,
      dominant: dominantLanguage([targetRes.lang, ...okComps.map((c) => c.lang)]),
    },
    entitiesMode,
  });
}

// ── Mode B: competitors-only consensus profile (TZ §4) ───────────────────────
async function runCompetitorsOnly(req, customTerms) {
  const warnings = [];

  const compResults = await Promise.all(req.competitors.map((c) => analyzeDoc(c.text, customTerms)));
  const { okComps, failedCount } = splitCompetitors(compResults, req.competitors, warnings);
  const smallSample = okComps.length === 1;
  if (smallSample) {
    warnings.push('Всего 1 конкурент: консенсуса как такового нет (K=1), выборка мала — это профиль по одному источнику.');
  }

  // Consensus profiles (no diff, no target).
  const entProfile = aggregateProfile(okComps.map((c) => ({ entities: c.entities })), config);
  const phraseProfile = aggregatePhrasesProfile(
    okComps.map((c) => ({ phraseMap: c.phrase.phraseMap })),
    config
  );

  // Volume as a competitor profile: median + distribution, no target compare.
  const words = okComps.map((c) => c.words);
  const sentences = okComps.map((c) => c.phrase.sentenceCount);
  const lexical = okComps.map((c) => c.phrase.lexicalDensity);
  const volume = {
    median_competitor_words: Math.round(median(words)),
    competitor_words: words,
    median_competitor_sentences: Math.round(median(sentences)),
    competitor_sentences: sentences,
    median_competitor_lexical_density: round4(median(lexical)),
    competitor_lexical_density: lexical.map(round4),
  };

  const entitiesMode = entitiesModeOf(okComps.map((c) => c.mode));

  let llm;
  try {
    llm = await classifyAndRecommend({
      mode: 'competitors_only',
      query: req.query,
      targetProfile: [],
      competitorTexts: okComps.map((c) => c.cleanText.slice(0, config.llmTextChars)),
      missing: entProfile.profile, // profile units as the coverage list
      weak: [],
      phrasesMissing: phraseProfile.profile,
      phrasesWeak: [],
    });
  } catch (err) {
    warnings.push(`Классификация интента/рекомендации недоступны: ${err.message}`);
    llm = null;
  }

  return buildProfileResponse(req, entProfile, phraseProfile, volume, llm, {
    competitorsAnalyzed: okComps.length,
    competitorsFailed: failedCount,
    warnings,
    smallSample,
    language: { target: null, dominant: dominantLanguage(okComps.map((c) => c.lang)) },
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
    mode: 'compare',
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

// Mode B response: consensus profile / brief instead of a missing/weak diff.
function buildProfileResponse(req, entProfile, phraseProfile, volume, llm, meta) {
  const intent = llm?.intent ?? {
    dominant: null,
    distribution: [],
    target_type: null,
    target_matches_dominant: null,
    note: 'Интент не определён (LLM недоступен).',
  };

  return {
    query: req.query,
    mode: 'competitors_only',
    competitors_analyzed: meta.competitorsAnalyzed,
    competitors_failed: meta.competitorsFailed,
    consensus_threshold: entProfile.consensusThreshold,
    small_sample: meta.smallSample,
    warnings: meta.warnings,
    mock_mode: config.google.mock || config.openai.mock,
    language: meta.language,
    entities_mode: meta.entitiesMode,
    intent,
    consensus_profile: mergeRecommendations(entProfile.profile, llm?.recommendations?.missing),
    phrase_profile: mergeRecommendations(phraseProfile.profile, llm?.phrase_recommendations?.missing, 'phrase'),
    volume,
  };
}
