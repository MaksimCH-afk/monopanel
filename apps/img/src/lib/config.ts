import path from "node:path";
import fs from "node:fs";

export const DATA_DIR = process.env.DATA_DIR || path.join(process.cwd(), "data");
export const IMAGES_DIR = path.join(DATA_DIR, "images");

export function ensureDirs() {
  fs.mkdirSync(IMAGES_DIR, { recursive: true });
}

export function imageDiskPath(relPath: string) {
  // relPath is stored in DB (e.g. "<sessionId>/<resultId>.png"). Guard traversal.
  const normalized = path
    .normalize(relPath)
    .replace(/^(\.\.(\/|\\|$))+/, "");
  return path.join(IMAGES_DIR, normalized);
}
