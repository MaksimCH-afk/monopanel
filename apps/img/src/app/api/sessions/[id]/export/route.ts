import { NextRequest, NextResponse } from "next/server";
import archiver from "archiver";
import { prisma } from "@/lib/db";
import { parseConfig } from "@/lib/generation";
import { imageDiskPath } from "@/lib/config";
import { extForMime, mimeForPath } from "@/lib/images";
import fs from "node:fs";

export const dynamic = "force-dynamic";

// Download the whole session as a ZIP: every image + a metadata.json manifest.
export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> },
) {
  const { id } = await params;
  const session = await prisma.session.findUnique({
    where: { id },
    include: { results: true },
  });
  if (!session) {
    return NextResponse.json({ error: "Session not found" }, { status: 404 });
  }

  const prompts: string[] = safe(session.promptsJson, []);
  const config = parseConfig(session.configJson);

  const manifest = {
    sessionId: session.id,
    createdAt: session.createdAt,
    completedAt: session.completedAt,
    status: session.status,
    config,
    prompts,
    results: session.results.map((r) => ({
      file: r.imagePath
        ? `images/${r.modelName.replace(/[^\w.-]/g, "_")}_p${r.promptIndex}_b${r.batchIndex}.${extForMime(mimeForPath(r.imagePath))}`
        : null,
      model: r.modelName,
      modelId: r.modelId,
      promptIndex: r.promptIndex,
      prompt: prompts[r.promptIndex],
      batchIndex: r.batchIndex,
      seed: r.seed,
      latencyMs: r.latencyMs,
      rating: r.rating,
      pinned: r.pinned,
      status: r.status,
      error: r.error,
      params: safe(r.paramsJson, {}),
    })),
  };

  const archive = archiver("zip", { zlib: { level: 9 } });
  const chunks: Buffer[] = [];
  archive.on("data", (c: Buffer) => chunks.push(c));

  const done = new Promise<void>((resolve, reject) => {
    archive.on("end", () => resolve());
    archive.on("error", reject);
  });

  archive.append(JSON.stringify(manifest, null, 2), { name: "metadata.json" });

  for (const r of session.results) {
    if (!r.imagePath) continue;
    const disk = imageDiskPath(r.imagePath);
    if (!fs.existsSync(disk)) continue;
    const name = `images/${r.modelName.replace(/[^\w.-]/g, "_")}_p${r.promptIndex}_b${r.batchIndex}.${extForMime(mimeForPath(r.imagePath))}`;
    archive.append(fs.createReadStream(disk), { name });
  }

  await archive.finalize();
  await done;

  const buf = Buffer.concat(chunks);
  return new NextResponse(buf as unknown as BodyInit, {
    headers: {
      "Content-Type": "application/zip",
      "Content-Disposition": `attachment; filename="session-${session.id}.zip"`,
    },
  });
}

function safe<T>(json: string, fallback: T): T {
  try {
    return JSON.parse(json) as T;
  } catch {
    return fallback;
  }
}
