// A model adapter declares a parameter schema; the dashboard renders the
// settings panel from it with no per-model UI code.

export type ParamType = "number" | "slider" | "select" | "boolean" | "text";

export interface ParamSpec {
  key: string;
  label: string;
  type: ParamType;
  default?: unknown;
  min?: number;
  max?: number;
  step?: number;
  options?: { value: string; label: string }[];
  help?: string;
}

export interface GenerateInput {
  prompt: string;
  seed?: number;
  params: Record<string, unknown>;
  /** Provider-specific model id, e.g. "google/gemini-2.5-flash-image-preview". */
  modelId: string;
  apiKey: string;
  aspectRatio: string;
}

export interface GenerateOutput {
  /** Raw base64 (no data: prefix). */
  imageBase64: string;
  mime: string;
  seed?: number;
}

export interface ImageAdapter {
  provider: string;
  /** Parameter schema offered when none is configured on the model. */
  defaultParamsSchema(): ParamSpec[];
  generate(input: GenerateInput): Promise<GenerateOutput>;
}

export class AdapterError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "AdapterError";
  }
}

export function parseDataUrl(url: string): { mime: string; base64: string } | null {
  const m = /^data:([^;]+);base64,(.+)$/s.exec(url);
  if (!m) return null;
  return { mime: m[1], base64: m[2] };
}
