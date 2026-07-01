import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { serializeModel } from "@/lib/serialize";
import { invalidateTags, CacheTags } from "@/lib/cache";
import { logger } from "@/lib/logger";

export const dynamic = "force-dynamic";

export async function PATCH(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  const body = await req.json();

  const data: Record<string, unknown> = {};
  if (typeof body.name === "string") data.name = body.name;
  if (typeof body.modelId === "string") data.modelId = body.modelId;
  if (typeof body.color === "string") data.color = body.color;
  if (typeof body.enabled === "boolean") data.enabled = body.enabled;
  if (typeof body.aspectRatio === "string") data.aspectRatio = body.aspectRatio;
  if ("apiKey" in body) data.apiKey = body.apiKey || null;
  if (body.paramsSchema) data.paramsSchemaJson = JSON.stringify(body.paramsSchema);
  if (body.defaultParams) data.defaultParamsJson = JSON.stringify(body.defaultParams);

  try {
    const model = await prisma.modelAdapter.update({ where: { id }, data });
    invalidateTags(CacheTags.models);
    if ("enabled" in data) {
      logger.info(`model ${data.enabled ? "enabled" : "disabled"}: "${model.name}"`, {
        modelId: model.id,
        modelName: model.name,
      });
    }
    return NextResponse.json({ model: serializeModel(model) });
  } catch {
    return NextResponse.json({ error: "Model not found" }, { status: 404 });
  }
}

export async function DELETE(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  try {
    const model = await prisma.modelAdapter.delete({ where: { id } });
    invalidateTags(CacheTags.models);
    logger.warn(`model deleted: "${model.name}" (${model.modelId})`, {
      modelId: model.id,
      modelName: model.name,
    });
    return NextResponse.json({ ok: true });
  } catch {
    return NextResponse.json({ error: "Model not found" }, { status: 404 });
  }
}
