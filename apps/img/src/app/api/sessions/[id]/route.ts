import { NextRequest, NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { serializeSession } from "@/lib/serialize";

export const dynamic = "force-dynamic";

export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  const session = await prisma.session.findUnique({
    where: { id },
    include: { results: { orderBy: [{ promptIndex: "asc" }, { createdAt: "asc" }] } },
  });
  if (!session) {
    return NextResponse.json({ error: "Session not found" }, { status: 404 });
  }
  return NextResponse.json({ session: serializeSession(session) });
}

export async function DELETE(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  try {
    await prisma.session.delete({ where: { id } });
    return NextResponse.json({ ok: true });
  } catch {
    return NextResponse.json({ error: "Session not found" }, { status: 404 });
  }
}
