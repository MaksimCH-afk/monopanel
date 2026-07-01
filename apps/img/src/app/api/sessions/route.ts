import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { serializeSession } from "@/lib/serialize";
import { invalidateTags, CacheTags } from "@/lib/cache";
import { parseConfig, runSession, seedFor, SessionConfig } from "@/lib/generation";
import { logger } from "@/lib/logger";

export const dynamic = "force-dynamic";

export async function GET() {
  const sessions = await prisma.session.findMany({
    orderBy: { createdAt: "desc" },
    take: 50,
    include: { results: true },
  });
  return NextResponse.json({ sessions: sessions.map(serializeSession) });
}

export async function POST(req: NextRequest) {
  const body = await req.json();

  const prompts: string[] = Array.isArray(body.prompts)
    ? body.prompts.map((p: unknown) => String(p ?? "")).filter((p: string) => p.trim())
    : [];
  const modelIds: string[] = Array.isArray(body.modelIds) ? body.modelIds : [];

  if (prompts.length === 0) {
    return NextResponse.json({ error: "At least one prompt is required" }, { status: 400 });
  }
  if (modelIds.length === 0) {
    return NextResponse.json({ error: "Select at least one model" }, { status: 400 });
  }

  const config: SessionConfig = {
    seedSync: body.config?.seedSync ?? true,
    batchSize: clamp(Number(body.config?.batchSize ?? 1), 1, 8),
    presetId: body.config?.presetId ?? null,
    blindMode: body.config?.blindMode ?? false,
    seed: typeof body.config?.seed === "number" ? body.config.seed : undefined,
    aspectRatio: typeof body.config?.aspectRatio === "string" ? body.config.aspectRatio : "1:1",
  };
  const cfg = parseConfig(JSON.stringify(config));
  // Pin a base seed once per session so "sync seed" is consistent across all
  // models (otherwise each cell would re-randomize its own base).
  if (typeof cfg.seed !== "number") {
    cfg.seed = Math.floor(Math.random() * 2_147_483_647);
  }

  // Only generate with models that exist and are enabled.
  const models = await prisma.modelAdapter.findMany({
    where: { id: { in: modelIds }, enabled: true },
  });
  if (models.length === 0) {
    return NextResponse.json({ error: "No enabled models selected" }, { status: 400 });
  }

  const webhookUrl: string | null = body.webhookUrl?.trim() || null;

  // Record prompt history (cache-invalidating).
  for (const text of prompts) {
    const existing = await prisma.promptHistory.findFirst({ where: { text } });
    if (existing) {
      await prisma.promptHistory.update({
        where: { id: existing.id },
        data: { useCount: { increment: 1 }, lastUsedAt: new Date() },
      });
    } else {
      await prisma.promptHistory.create({ data: { text } });
    }
  }
  invalidateTags(CacheTags.history);

  // Create the session and one Result row per (prompt x model x batch) cell.
  const session = await prisma.session.create({
    data: {
      promptsJson: JSON.stringify(prompts),
      configJson: JSON.stringify(cfg),
      status: "running",
      webhookUrl,
    },
  });

  // Optional per-model parameter overrides coming from the auto-rendered panels.
  const modelParams: Record<string, Record<string, unknown>> =
    body.modelParams && typeof body.modelParams === "object" ? body.modelParams : {};

  const resultData = [];
  for (let pi = 0; pi < prompts.length; pi++) {
    for (const model of models) {
      const mergedParams = {
        ...JSON.parse(model.defaultParamsJson || "{}"),
        ...(modelParams[model.id] ?? {}),
      };
      for (let bi = 0; bi < cfg.batchSize; bi++) {
        resultData.push({
          sessionId: session.id,
          modelId: model.id,
          modelName: model.name,
          modelColor: model.color,
          promptIndex: pi,
          batchIndex: bi,
          status: "pending",
          seed: seedFor(cfg, bi),
          paramsJson: JSON.stringify(mergedParams),
        });
      }
    }
  }
  await prisma.result.createMany({ data: resultData });

  logger.info(
    `session ${session.id} created — ${prompts.length} prompt(s) x ${models.length} model(s) x batch ${cfg.batchSize} = ${resultData.length} cell(s); models: ${models.map((m) => m.name).join(", ")}`,
  );

  // Kick generation in the background; respond immediately so the UI can poll.
  void runSession(session.id);

  const full = await prisma.session.findUnique({
    where: { id: session.id },
    include: { results: true },
  });
  return NextResponse.json({ session: serializeSession(full!) }, { status: 201 });
}

function clamp(n: number, min: number, max: number) {
  if (Number.isNaN(n)) return min;
  return Math.max(min, Math.min(max, n));
}
