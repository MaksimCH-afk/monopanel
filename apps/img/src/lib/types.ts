// Client-facing shapes (mirror of the serializers).
import type { ParamSpec } from "./adapters/types";
export type { ParamSpec };

export interface Model {
  id: string;
  name: string;
  provider: string;
  modelId: string;
  color: string;
  enabled: boolean;
  aspectRatio: string;
  hasOwnKey: boolean;
  paramsSchema: ParamSpec[];
  defaultParams: Record<string, unknown>;
  sortOrder: number;
}

export interface ResultDto {
  id: string;
  sessionId: string;
  modelId: string;
  modelName: string;
  modelColor: string;
  promptIndex: number;
  batchIndex: number;
  status: "pending" | "running" | "done" | "error";
  error: string | null;
  imageUrl: string | null;
  latencyMs: number | null;
  seed: number | null;
  rating: number;
  pinned: boolean;
}

export interface SessionDto {
  id: string;
  prompts: string[];
  config: {
    seedSync?: boolean;
    batchSize?: number;
    blindMode?: boolean;
    presetId?: string | null;
    seed?: number;
  };
  status: "pending" | "running" | "done" | "error";
  webhookUrl: string | null;
  createdAt: string;
  completedAt: string | null;
  results: ResultDto[];
}

export interface PresetDto {
  id: string;
  name: string;
  config: Record<string, unknown>;
  createdAt: string;
}

export interface PromptDto {
  id: string;
  text: string;
  useCount: number;
  lastUsedAt: string;
  createdAt: string;
}

export interface AnalyticsModel {
  modelId: string;
  modelName: string;
  modelColor: string;
  total: number;
  done: number;
  errored: number;
  successRate: number;
  avgRating: number | null;
  ratedCount: number;
  avgLatencyMs: number | null;
  latencyHistory: { at: string; latencyMs: number }[];
}
