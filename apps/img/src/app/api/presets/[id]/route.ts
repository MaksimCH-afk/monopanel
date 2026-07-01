import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { invalidateTags, CacheTags } from "@/lib/cache";

export const dynamic = "force-dynamic";

export async function DELETE(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  try {
    await prisma.preset.delete({ where: { id } });
    invalidateTags(CacheTags.presets);
    return NextResponse.json({ ok: true });
  } catch {
    return NextResponse.json({ error: "Preset not found" }, { status: 404 });
  }
}
