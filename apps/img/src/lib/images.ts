import fs from "node:fs/promises";
import path from "node:path";
import { IMAGES_DIR, ensureDirs, imageDiskPath } from "./config";

const EXT_BY_MIME: Record<string, string> = {
  "image/png": "png",
  "image/jpeg": "jpg",
  "image/jpg": "jpg",
  "image/webp": "webp",
};

export function extForMime(mime: string): string {
  return EXT_BY_MIME[mime.toLowerCase()] ?? "png";
}

// Persist a generated image to disk and return the DB-relative path.
export async function saveImage(
  sessionId: string,
  resultId: string,
  base64: string,
  mime: string,
): Promise<string> {
  ensureDirs();
  const dir = path.join(IMAGES_DIR, sessionId);
  await fs.mkdir(dir, { recursive: true });
  const rel = `${sessionId}/${resultId}.${extForMime(mime)}`;
  await fs.writeFile(imageDiskPath(rel), Buffer.from(base64, "base64"));
  return rel;
}

export async function readImage(relPath: string): Promise<Buffer> {
  return fs.readFile(imageDiskPath(relPath));
}

export function mimeForPath(relPath: string): string {
  const ext = path.extname(relPath).slice(1).toLowerCase();
  if (ext === "jpg" || ext === "jpeg") return "image/jpeg";
  if (ext === "webp") return "image/webp";
  return "image/png";
}
