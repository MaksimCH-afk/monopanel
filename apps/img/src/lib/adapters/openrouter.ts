import {
  AdapterError,
  GenerateInput,
  GenerateOutput,
  ImageAdapter,
  ParamSpec,
  parseDataUrl,
} from "./types";

const OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions";

// OpenRouter exposes image-output models behind an OpenAI-compatible
// chat/completions endpoint. Requesting the "image" modality makes the model
// return generated images in `message.images[]` as data URLs.
export const openRouterAdapter: ImageAdapter = {
  provider: "openrouter",

  defaultParamsSchema(): ParamSpec[] {
    return [
      {
        key: "negative_prompt",
        label: "Negative prompt",
        type: "text",
        default: "",
        help: "Appended as guidance for what to avoid (model dependent).",
      },
    ];
  },

  async generate(input: GenerateInput): Promise<GenerateOutput> {
    if (!input.apiKey) {
      throw new AdapterError(
        "No OpenRouter API key configured (set OPENROUTER_API_KEY or a per-model key in Settings).",
      );
    }

    // Dedicated image models (FLUX, Seedream, Recraft, Grok Imagine) only
    // support the "image" output modality. Models that also emit text (e.g.
    // Gemini) need ["image","text"]. Try image-only first, then fall back.
    let json: OpenRouterResponse;
    try {
      json = await callOpenRouter(input, ["image"]);
    } catch (e) {
      if (e instanceof ModalityError) {
        json = await callOpenRouter(input, ["image", "text"]);
      } else {
        throw e;
      }
    }

    const image = extractImage(json);
    if (!image) {
      const finish = json.choices?.[0]?.message?.content;
      throw new AdapterError(
        "Model returned no image. " +
          (typeof finish === "string" && finish
            ? `Model said: "${finish.slice(0, 200)}"`
            : "This model id may not be an image-output model."),
      );
    }

    return { imageBase64: image.base64, mime: image.mime, seed: input.seed };
  },
};

// Signals that the chosen modalities aren't supported, so the caller can retry.
class ModalityError extends Error {}

async function callOpenRouter(
  input: GenerateInput,
  modalities: string[],
): Promise<OpenRouterResponse> {
  const body: Record<string, unknown> = {
    model: input.modelId,
    modalities,
    messages: [{ role: "user", content: buildPrompt(input) }],
    ...(typeof input.seed === "number" ? { seed: input.seed } : {}),
  };

  // image_config carries aspect ratio + any model-specific image settings.
  // Aspect ratio only when non-default so a plain 1:1 body stays minimal.
  const imageConfig: Record<string, unknown> = {};
  if (input.aspectRatio && input.aspectRatio !== "1:1") {
    imageConfig.aspect_ratio = input.aspectRatio;
  }
  // Per-model params (from the auto-rendered panel) prefixed "ic_" are
  // forwarded into image_config, e.g. ic_image_size -> image_config.image_size.
  for (const [key, value] of Object.entries(input.params ?? {})) {
    if (key.startsWith("ic_") && value !== "" && value != null) {
      imageConfig[key.replace(/^ic_/, "")] = value;
    }
  }
  if (Object.keys(imageConfig).length > 0) {
    body.image_config = imageConfig;
  }

  let res: Response;
  try {
    res = await fetch(OPENROUTER_URL, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${input.apiKey}`,
        "Content-Type": "application/json",
        "HTTP-Referer": process.env.OPENROUTER_APP_URL || "http://localhost:3000",
        "X-Title": process.env.OPENROUTER_APP_TITLE || "ImageGen Dashboard",
      },
      body: JSON.stringify(body),
    });
  } catch (e) {
    throw new AdapterError(
      `Network error reaching OpenRouter: ${(e as Error).message}`,
    );
  }

  const text = await res.text();
  if (!res.ok) {
    const msg = extractError(text) ?? text.slice(0, 300);
    // 404 "No endpoints found that support the requested output modalities"
    if (res.status === 404 && /output modalities/i.test(msg)) {
      throw new ModalityError(msg);
    }
    throw new AdapterError(`OpenRouter ${res.status}: ${msg}`);
  }

  try {
    return JSON.parse(text) as OpenRouterResponse;
  } catch {
    throw new AdapterError("OpenRouter returned a non-JSON response.");
  }
}

function buildPrompt(input: GenerateInput): string {
  let prompt = input.prompt;
  const negative = input.params?.negative_prompt;
  if (typeof negative === "string" && negative.trim()) {
    prompt += `\n\nAvoid: ${negative.trim()}`;
  }
  // Aspect ratio is passed via image_config, not appended to the prompt.
  return prompt;
}

interface OpenRouterResponse {
  choices?: {
    message?: {
      content?: string;
      images?: { type?: string; image_url?: { url?: string } }[];
    };
  }[];
  error?: { message?: string };
}

function extractImage(
  json: OpenRouterResponse,
): { mime: string; base64: string } | null {
  const images = json.choices?.[0]?.message?.images;
  if (!images?.length) return null;
  for (const img of images) {
    const url = img?.image_url?.url;
    if (url) {
      const parsed = parseDataUrl(url);
      if (parsed) return parsed;
    }
  }
  return null;
}

function extractError(text: string): string | null {
  try {
    const j = JSON.parse(text) as OpenRouterResponse;
    return j.error?.message ?? null;
  } catch {
    return null;
  }
}
