import { NextRequest, NextResponse } from "next/server";
import { readImage, mimeForPath } from "@/lib/images";

export const dynamic = "force-dynamic";

export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> },
) {
  const { path } = await params;
  const rel = path.join("/");
  try {
    const buf = await readImage(rel);
    return new NextResponse(buf as unknown as BodyInit, {
      headers: {
        "Content-Type": mimeForPath(rel),
        "Cache-Control": "public, max-age=31536000, immutable",
      },
    });
  } catch {
    return NextResponse.json({ error: "Image not found" }, { status: 404 });
  }
}
