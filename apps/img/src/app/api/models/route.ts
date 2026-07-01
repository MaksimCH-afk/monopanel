import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { serializeModel } from "@/lib/serialize";
import { cached, invalidateTags, CacheTags } from "@/lib/cache";
import { defaultSchemaFor, listProviders } from "@/lib/adapters/registry";
import { logger } from "@/lib/logger";

export const dynamic = "force-dynamic";

export async function GET() {
  const models = await cached(
    "models:all",
    async () => {
      const rows = await prisma.modelAdapter.findMany({
        orderBy: [{ sortOrder: "asc" }, { createdAt: "asc" }],
      });
      return rows.map(serializeModel);
    },
    { tags: [CacheTags.models] },
  );
  return NextResponse.json({ models });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const { name, modelId, color, provider = "openrouter" } = body ?? {};

  if (!name || !modelId || !color) {
    return NextResponse.json(
      { error: "name, modelId and color are required" },
      { status: 400 },
    );
  }
  if (!listProviders().includes(provider)) {
    return NextResponse.json({ error: `Unknown provider: ${provider}` }, { status: 400 });
  }

  const paramsSchema = body.paramsSchema ?? defaultSchemaFor(provider);
  const count = await prisma.modelAdapter.count();

  const model = await prisma.modelAdapter.create({
    data: {
      name,
      provider,
      modelId,
      color,
      apiKey: body.apiKey || null,
      enabled: body.enabled ?? true,
      aspectRatio: body.aspectRatio || "1:1",
      paramsSchemaJson: JSON.stringify(paramsSchema),
      defaultParamsJson: JSON.stringify(body.defaultParams ?? {}),
      sortOrder: count,
    },
  });

  invalidateTags(CacheTags.models);
  logger.info(`model added: "${model.name}" (${model.modelId})`, {
    modelId: model.id,
    modelName: model.name,
  });
  return NextResponse.json({ model: serializeModel(model) }, { status: 201 });
}
