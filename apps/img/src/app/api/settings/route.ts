import { NextRequest, NextResponse } from "next/server";
import { getSetting, setSetting, SettingKeys } from "@/lib/settings";

export const dynamic = "force-dynamic";

function mask(value: string | null): string | null {
  if (!value) return null;
  if (value.length <= 8) return "••••";
  return `${value.slice(0, 4)}••••${value.slice(-4)}`;
}

export async function GET() {
  const apiKey = await getSetting(SettingKeys.openRouterApiKey);
  const webhook = await getSetting(SettingKeys.defaultWebhookUrl);
  return NextResponse.json({
    openRouterApiKey: mask(apiKey),
    hasStoredKey: Boolean(apiKey),
    hasEnvKey: Boolean(process.env.OPENROUTER_API_KEY?.trim()),
    defaultWebhookUrl: webhook ?? "",
  });
}

export async function PUT(req: NextRequest) {
  const body = await req.json();
  if (typeof body.openRouterApiKey === "string") {
    await setSetting(SettingKeys.openRouterApiKey, body.openRouterApiKey.trim());
  }
  if (typeof body.defaultWebhookUrl === "string") {
    await setSetting(SettingKeys.defaultWebhookUrl, body.defaultWebhookUrl.trim());
  }
  return NextResponse.json({ ok: true });
}
