import type { ModelAdapter, Result, Session, Preset, PromptHistory } from "@prisma/client";
import type { ParamSpec } from "./adapters/types";

function parse<T>(json: string, fallback: T): T {
  try {
    return JSON.parse(json) as T;
  } catch {
    return fallback;
  }
}

export function serializeModel(m: ModelAdapter) {
  return {
    id: m.id,
    name: m.name,
    provider: m.provider,
    modelId: m.modelId,
    color: m.color,
    enabled: m.enabled,
    aspectRatio: m.aspectRatio,
    hasOwnKey: Boolean(m.apiKey),
    paramsSchema: parse<ParamSpec[]>(m.paramsSchemaJson, []),
    defaultParams: parse<Record<string, unknown>>(m.defaultParamsJson, {}),
    sortOrder: m.sortOrder,
  };
}

export function serializeResult(r: Result) {
  return {
    id: r.id,
    sessionId: r.sessionId,
    modelId: r.modelId,
    modelName: r.modelName,
    modelColor: r.modelColor,
    promptIndex: r.promptIndex,
    batchIndex: r.batchIndex,
    status: r.status,
    error: r.error,
    imageUrl: r.imagePath ? `/api/images/${r.imagePath}` : null,
    latencyMs: r.latencyMs,
    seed: r.seed,
    rating: r.rating,
    pinned: r.pinned,
  };
}

export function serializeSession(
  s: Session & { results?: Result[] },
) {
  return {
    id: s.id,
    prompts: parse<string[]>(s.promptsJson, []),
    config: parse<Record<string, unknown>>(s.configJson, {}),
    status: s.status,
    webhookUrl: s.webhookUrl,
    createdAt: s.createdAt,
    completedAt: s.completedAt,
    results: s.results?.map(serializeResult) ?? [],
  };
}

export function serializePreset(p: Preset) {
  return {
    id: p.id,
    name: p.name,
    config: parse<Record<string, unknown>>(p.configJson, {}),
    createdAt: p.createdAt,
  };
}

export function serializePrompt(p: PromptHistory) {
  return {
    id: p.id,
    text: p.text,
    useCount: p.useCount,
    lastUsedAt: p.lastUsedAt,
    createdAt: p.createdAt,
  };
}
