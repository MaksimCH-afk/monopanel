import fs from "node:fs";
import path from "node:path";
import { DATA_DIR } from "./config";

// Real-time logging: every event is appended to disk immediately (synchronous
// append) so logs can be tailed / downloaded live, not only after a run ends.
//
// Two scopes per the requirement:
//   - project.log         — everything (global)
//   - model-<id>.log      — one file per model
// Model-scoped lines are written to BOTH the model file and the project file.

export const LOGS_DIR = path.join(DATA_DIR, "logs");
export const PROJECT_LOG = "project.log";

export type LogLevel = "info" | "warn" | "error" | "debug";

function ensureLogsDir() {
  fs.mkdirSync(LOGS_DIR, { recursive: true });
}

export function modelLogName(modelId: string): string {
  return `model-${modelId}.log`;
}

function appendLine(file: string, line: string) {
  try {
    ensureLogsDir();
    fs.appendFileSync(path.join(LOGS_DIR, file), line + "\n");
  } catch {
    // Logging must never break generation.
  }
}

function fmt(level: LogLevel, scope: string, message: string): string {
  return `${new Date().toISOString()} [${level.toUpperCase().padEnd(5)}] [${scope}] ${message}`;
}

export interface LogOptions {
  modelId?: string;
  modelName?: string;
}

// Write a single log entry in real time.
export function log(level: LogLevel, message: string, opts: LogOptions = {}) {
  const scope = opts.modelName ?? opts.modelId ?? "project";
  const line = fmt(level, scope, message);
  appendLine(PROJECT_LOG, line);
  if (opts.modelId) {
    appendLine(modelLogName(opts.modelId), line);
  }
  // Mirror to stdout so `docker logs` shows activity too.
  const sink = level === "error" ? console.error : console.log;
  sink(line);
}

export const logger = {
  info: (m: string, o?: LogOptions) => log("info", m, o),
  warn: (m: string, o?: LogOptions) => log("warn", m, o),
  error: (m: string, o?: LogOptions) => log("error", m, o),
  debug: (m: string, o?: LogOptions) => log("debug", m, o),
};

// --- Read helpers for the API / UI ---------------------------------------

export function logFilePath(file: string): string {
  // Only allow known log file names (project.log or model-*.log).
  const safe = path.basename(file);
  if (safe !== PROJECT_LOG && !/^model-[\w-]+\.log$/.test(safe)) {
    throw new Error("Invalid log file");
  }
  return path.join(LOGS_DIR, safe);
}

export function readLogSlice(
  file: string,
  offset = 0,
): { text: string; size: number } {
  const p = logFilePath(file);
  if (!fs.existsSync(p)) return { text: "", size: 0 };
  const size = fs.statSync(p).size;
  if (offset >= size) return { text: "", size };
  const fd = fs.openSync(p, "r");
  try {
    const len = size - offset;
    const buf = Buffer.alloc(len);
    fs.readSync(fd, buf, 0, len, offset);
    return { text: buf.toString("utf8"), size };
  } finally {
    fs.closeSync(fd);
  }
}

export function logSize(file: string): number {
  try {
    const p = logFilePath(file);
    return fs.existsSync(p) ? fs.statSync(p).size : 0;
  } catch {
    return 0;
  }
}

export function listLogFiles(): string[] {
  ensureLogsDir();
  return fs.readdirSync(LOGS_DIR).filter((f) => f.endsWith(".log"));
}
