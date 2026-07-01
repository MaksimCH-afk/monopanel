import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { serializePreset } from "@/lib/serialize";
import { cached, invalidateTags, CacheTags } from "@/lib/cache";

export const dynamic = "force-dynamic";

export async function GET() {
  const presets = await cached(
    "presets:all",
    async () => {
      const rows = await prisma.preset.findMany({ orderBy: { createdAt: "desc" } });
      return rows.map(serializePreset);
    },
    { tags: [CacheTags.presets] },
  );
  return NextResponse.json({ presets });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  if (!body.name) {
    return NextResponse.json({ error: "name is required" }, { status: 400 });
  }
  const preset = await prisma.preset.create({
    data: { name: body.name, configJson: JSON.stringify(body.config ?? {}) },
  });
  invalidateTags(CacheTags.presets);
  return NextResponse.json({ preset: serializePreset(preset) }, { status: 201 });
}
