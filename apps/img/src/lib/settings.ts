import { prisma } from "./db";

export const SettingKeys = {
  openRouterApiKey: "openrouter_api_key",
  defaultWebhookUrl: "default_webhook_url",
} as const;

export async function getSetting(key: string): Promise<string | null> {
  const row = await prisma.setting.findUnique({ where: { key } });
  return row?.value ?? null;
}

export async function setSetting(key: string, value: string): Promise<void> {
  await prisma.setting.upsert({
    where: { key },
    create: { key, value },
    update: { value },
  });
}

// Resolve the OpenRouter key for a model: per-model override > stored setting > env.
export async function resolveApiKey(modelApiKey?: string | null): Promise<string> {
  if (modelApiKey && modelApiKey.trim()) return modelApiKey.trim();
  const stored = await getSetting(SettingKeys.openRouterApiKey);
  if (stored && stored.trim()) return stored.trim();
  return process.env.OPENROUTER_API_KEY?.trim() ?? "";
}
