import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();

// Runs on every container start. The five managed models below are reconciled
// by name: their modelId / color are kept correct, and their API key is set
// from env when provided. This self-heals an existing database (e.g. fixing a
// wrong model slug) without needing a volume reset. Models added by hand in the
// UI are left untouched.
//
// Per-model OpenRouter API keys come from environment variables (kept in .env,
// never committed) so secrets stay out of source control.
async function main() {
  // Default per-model parameter schema. The dashboard renders the settings
  // panel from this automatically. Keys prefixed "ic_" are forwarded into
  // OpenRouter's image_config (e.g. ic_image_size -> image_config.image_size).
  const paramsSchema = JSON.stringify([
    {
      key: "negative_prompt",
      label: "Negative prompt",
      type: "text",
      default: "",
      help: "What to avoid (model dependent).",
    },
    {
      key: "ic_image_size",
      label: "Image size",
      type: "select",
      default: "",
      options: [
        { value: "", label: "Model default" },
        { value: "1K", label: "1K" },
        { value: "2K", label: "2K" },
        { value: "4K", label: "4K" },
      ],
      help: "Resolution hint (supported by FLUX.2 / Recraft / Grok).",
    },
  ]);

  // `modelId` values are the exact OpenRouter slugs (verified against
  // openrouter.ai). `keyEnv` names the env var holding that model's key.
  const models = [
    {
      name: "FLUX.2 Pro",
      modelId: "black-forest-labs/flux.2-pro",
      color: "#22d3ee",
      keyEnv: "OR_KEY_FLUX2_PRO",
    },
    {
      name: "FLUX.2 Max",
      modelId: "black-forest-labs/flux.2-max",
      color: "#3b82f6",
      keyEnv: "OR_KEY_FLUX2_MAX",
    },
    {
      name: "Seedream 4.5",
      modelId: "bytedance-seed/seedream-4.5",
      color: "#ec4899",
      keyEnv: "OR_KEY_SEEDREAM",
    },
    {
      name: "xAI: Grok Imagine",
      modelId: "x-ai/grok-imagine-image-quality",
      color: "#f59e0b",
      keyEnv: "OR_KEY_GROK_IMAGINE",
    },
    {
      name: "Recraft V4.1 Pro",
      modelId: "recraft/recraft-v4.1-pro",
      color: "#fb7185",
      keyEnv: "OR_KEY_RECRAFT",
    },
  ];

  let order = 0;
  for (const m of models) {
    const envKey = process.env[m.keyEnv]?.trim() || null;
    const existing = await prisma.modelAdapter.findFirst({ where: { name: m.name } });

    if (existing) {
      await prisma.modelAdapter.update({
        where: { id: existing.id },
        data: {
          modelId: m.modelId,
          color: m.color,
          // Keep the param schema fresh so existing DBs gain new params too.
          paramsSchemaJson: paramsSchema,
          // Only overwrite the key when env actually provides one.
          ...(envKey ? { apiKey: envKey } : {}),
        },
      });
      console.log(`[seed] ~ ${m.name} -> ${m.modelId}${envKey ? " [key set]" : ""}`);
    } else {
      await prisma.modelAdapter.create({
        data: {
          name: m.name,
          provider: "openrouter",
          modelId: m.modelId,
          color: m.color,
          apiKey: envKey,
          enabled: true,
          aspectRatio: "1:1",
          paramsSchemaJson: paramsSchema,
          defaultParamsJson: "{}",
          sortOrder: order,
        },
      });
      console.log(`[seed] + ${m.name} (${m.modelId})${envKey ? " [key set]" : " [no key]"}`);
    }
    order++;
  }
  console.log(`[seed] reconciled ${models.length} models.`);
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(() => prisma.$disconnect());
