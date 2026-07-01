import { prisma } from "./db";
import { getAdapter } from "./adapters/registry";
import { saveImage } from "./images";
import { resolveApiKey } from "./settings";
import { fireWebhook } from "./webhook";
import { invalidateTags, CacheTags } from "./cache";
import { logger } from "./logger";

export interface SessionConfig {
  seedSync: boolean;
  batchSize: number;
  presetId?: string | null;
  blindMode: boolean;
  seed?: number; // base seed
  aspectRatio?: string; // e.g. "1:1", "16:9"
}

export function parseConfig(json: string): SessionConfig {
  const def: SessionConfig = {
    seedSync: true,
    batchSize: 1,
    blindMode: false,
    aspectRatio: "1:1",
  };
  try {
    return { ...def, ...JSON.parse(json) };
  } catch {
    return def;
  }
}

const CONCURRENCY = 4;
const randomSeed = () => Math.floor(Math.random() * 2_147_483_647);

// Process all pending results for a session. Designed to be invoked without
// awaiting (background). Each result is updated independently so the UI can
// show cards appearing as models finish.
export async function runSession(sessionId: string): Promise<void> {
  const session = await prisma.session.findUnique({ where: { id: sessionId } });
  if (!session) return;

  const sessionConfig = parseConfig(session.configJson);
  const aspectRatio = sessionConfig.aspectRatio || "1:1";

  const results = await prisma.result.findMany({
    where: { sessionId, status: "pending" },
    include: { model: true },
    orderBy: { createdAt: "asc" },
  });

  logger.info(
    `session ${sessionId} started — ${results.length} cell(s) queued (concurrency ${CONCURRENCY})`,
  );

  let index = 0;
  async function worker() {
    while (index < results.length) {
      const r = results[index++];
      await processResult(r, aspectRatio);
    }
  }
  await Promise.all(
    Array.from({ length: Math.min(CONCURRENCY, results.length) }, worker),
  );

  // Finalize session.
  const counts = await prisma.result.groupBy({
    by: ["status"],
    where: { sessionId },
    _count: true,
  });
  const errored = counts.find((c) => c.status === "error")?._count ?? 0;
  const done = counts.find((c) => c.status === "done")?._count ?? 0;
  const status = done === 0 && errored > 0 ? "error" : "done";

  await prisma.session.update({
    where: { id: sessionId },
    data: { status, completedAt: new Date() },
  });
  invalidateTags(CacheTags.analytics);
  logger.info(
    `session ${sessionId} completed — status=${status}, done=${done}, errored=${errored}`,
  );

  if (session.webhookUrl) {
    await fireWebhook(session.webhookUrl, {
      event: "session.completed",
      sessionId,
      status,
      results: { done, errored, total: results.length },
      completedAt: new Date().toISOString(),
    });
    await prisma.session.update({
      where: { id: sessionId },
      data: { webhookFiredAt: new Date() },
    });
    logger.info(`session ${sessionId} webhook POSTed to ${session.webhookUrl}`);
  }
}

type ResultWithModel = Awaited<
  ReturnType<typeof prisma.result.findMany>
>[number] & { model: { provider: string; modelId: string; apiKey: string | null } };

async function processResult(r: ResultWithModel, aspectRatio: string) {
  const startedAt = Date.now();
  const logCtx = { modelId: r.modelId, modelName: r.modelName };
  await prisma.result.update({
    where: { id: r.id },
    data: { status: "running" },
  });
  logger.info(
    `generating prompt#${r.promptIndex} batch#${r.batchIndex} (seed=${r.seed}) via ${r.model.modelId}`,
    logCtx,
  );

  try {
    const adapter = getAdapter(r.model.provider);
    const apiKey = await resolveApiKey(r.model.apiKey);
    const params = safeParse(r.paramsJson, {} as Record<string, unknown>);

    const out = await adapter.generate({
      prompt: await promptForResult(r),
      seed: r.seed ?? undefined,
      params,
      modelId: r.model.modelId,
      apiKey,
      aspectRatio,
    });

    const imagePath = await saveImage(r.sessionId, r.id, out.imageBase64, out.mime);
    const latencyMs = Date.now() - startedAt;
    await prisma.result.update({
      where: { id: r.id },
      data: {
        status: "done",
        imagePath,
        latencyMs,
        seed: out.seed ?? r.seed ?? undefined,
      },
    });
    logger.info(
      `done prompt#${r.promptIndex} batch#${r.batchIndex} in ${latencyMs}ms (${out.mime})`,
      logCtx,
    );
  } catch (e) {
    const latencyMs = Date.now() - startedAt;
    const message = (e as Error).message?.slice(0, 500) ?? "Unknown error";
    await prisma.result.update({
      where: { id: r.id },
      data: { status: "error", error: message, latencyMs },
    });
    logger.error(
      `failed prompt#${r.promptIndex} batch#${r.batchIndex} after ${latencyMs}ms: ${message}`,
      logCtx,
    );
  }
}

// Re-run a single result cell (e.g. after an error), optionally with a fresh
// seed. Used by the per-card Retry button so a failed/poor cell can be redone
// without re-running the whole — and re-paying for the whole — session.
export async function retryResult(
  resultId: string,
  newSeed = true,
): Promise<boolean> {
  const r = await prisma.result.findUnique({
    where: { id: resultId },
    include: { model: true },
  });
  if (!r) return false;

  const session = await prisma.session.findUnique({ where: { id: r.sessionId } });
  const aspectRatio = parseConfig(session?.configJson ?? "{}").aspectRatio || "1:1";

  if (newSeed) {
    await prisma.result.update({
      where: { id: r.id },
      data: { seed: randomSeed() },
    });
    r.seed = (await prisma.result.findUnique({ where: { id: r.id } }))!.seed;
  }

  logger.info(`retry of result ${r.id}`, { modelId: r.modelId, modelName: r.modelName });
  await processResult(r as ResultWithModel, aspectRatio);
  // Refresh session aggregate status so the gallery reflects the new outcome.
  await refreshSessionStatus(r.sessionId);
  return true;
}

async function refreshSessionStatus(sessionId: string) {
  const counts = await prisma.result.groupBy({
    by: ["status"],
    where: { sessionId },
    _count: true,
  });
  const pending =
    (counts.find((c) => c.status === "pending")?._count ?? 0) +
    (counts.find((c) => c.status === "running")?._count ?? 0);
  const done = counts.find((c) => c.status === "done")?._count ?? 0;
  const status = pending > 0 ? "running" : done > 0 ? "done" : "error";
  await prisma.session.update({
    where: { id: sessionId },
    data: { status },
  });
  invalidateTags(CacheTags.analytics);
}

async function promptForResult(r: { sessionId: string; promptIndex: number }) {
  const s = await prisma.session.findUnique({ where: { id: r.sessionId } });
  const prompts = safeParse<string[]>(s?.promptsJson ?? "[]", []);
  return prompts[r.promptIndex] ?? prompts[0] ?? "";
}

function safeParse<T>(json: string, fallback: T): T {
  try {
    return JSON.parse(json) as T;
  } catch {
    return fallback;
  }
}

// Compute the seed for a (model, promptIndex, batchIndex) cell.
export function seedFor(
  config: SessionConfig,
  batchIndex: number,
): number {
  const base = typeof config.seed === "number" ? config.seed : randomSeed();
  if (config.seedSync) return base + batchIndex;
  return randomSeed();
}
