"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { DownloadIcon } from "./icons";

interface LogList {
  project: { file: string; size: number };
  models: { file: string; modelId: string; modelName: string; size: number }[];
}

export function LogsView() {
  const [list, setList] = useState<LogList | null>(null);
  const [file, setFile] = useState("project.log");
  const [text, setText] = useState("");
  const [live, setLive] = useState(true);

  const offsetRef = useRef(0);
  const preRef = useRef<HTMLPreElement>(null);
  const stickRef = useRef(true); // auto-scroll only when already at bottom

  // Refresh the list of available log files periodically.
  useEffect(() => {
    const load = () =>
      fetch("/api/logs").then((r) => r.json()).then(setList).catch(() => {});
    load();
    const t = setInterval(load, 5000);
    return () => clearInterval(t);
  }, []);

  // Reset when switching files.
  useEffect(() => {
    offsetRef.current = 0;
    setText("");
  }, [file]);

  const tail = useCallback(async () => {
    try {
      const d = await fetch(
        `/api/logs/file?file=${encodeURIComponent(file)}&offset=${offsetRef.current}`,
      ).then((r) => r.json());
      if (d.text) {
        offsetRef.current = d.size;
        setText((prev) => prev + d.text);
      } else if (typeof d.size === "number" && d.size < offsetRef.current) {
        // file truncated/rotated — start over
        offsetRef.current = 0;
        setText("");
      }
    } catch {
      /* keep polling */
    }
  }, [file]);

  // Real-time polling.
  useEffect(() => {
    if (!live) return;
    tail();
    const t = setInterval(tail, 1200);
    return () => clearInterval(t);
  }, [tail, live]);

  // Auto-scroll to bottom when new lines arrive (if user is at the bottom).
  useEffect(() => {
    const el = preRef.current;
    if (el && stickRef.current) el.scrollTop = el.scrollHeight;
  }, [text]);

  function onScroll() {
    const el = preRef.current;
    if (!el) return;
    stickRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
  }

  return (
    <div className="flex h-full flex-col">
      {/* Toolbar */}
      <div className="flex shrink-0 flex-wrap items-center gap-3 border-b border-bg-border px-4 py-2.5">
        <span className="label">Log</span>
        <select
          value={file}
          onChange={(e) => setFile(e.target.value)}
          className="mono rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-action"
        >
          <option value="project.log">project (all)</option>
          {list?.models.map((m) => (
            <option key={m.file} value={m.file}>
              {m.modelName}
            </option>
          ))}
        </select>

        <button
          onClick={() => setLive((v) => !v)}
          className={`mono flex items-center gap-1.5 rounded border px-2 py-1 text-[11px] uppercase tracking-wider ${
            live
              ? "border-action/40 text-action"
              : "border-bg-border text-fg-muted hover:bg-bg-hover"
          }`}
        >
          <span
            className={`inline-block h-1.5 w-1.5 rounded-full ${
              live ? "animate-pulse-dot bg-action" : "bg-fg-faint"
            }`}
          />
          {live ? "live" : "paused"}
        </button>

        <div className="ml-auto flex items-center gap-2">
          <button
            onClick={() => {
              offsetRef.current = 0;
              setText("");
            }}
            className="mono rounded border border-bg-border px-2.5 py-1 text-[11px] uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
          >
            Clear view
          </button>
          <a
            href={`/api/logs/file?file=${encodeURIComponent(file)}&download=1`}
            className="mono flex items-center gap-1.5 rounded border border-bg-border px-2.5 py-1 text-[11px] uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
          >
            <DownloadIcon width={13} height={13} /> This log
          </a>
          <a
            href="/api/logs/export"
            className="mono flex items-center gap-1.5 rounded border border-bg-border px-2.5 py-1 text-[11px] uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
          >
            <DownloadIcon width={13} height={13} /> All (ZIP)
          </a>
        </div>
      </div>

      {/* Live view */}
      <pre
        ref={preRef}
        onScroll={onScroll}
        className="mono min-h-0 flex-1 overflow-auto whitespace-pre-wrap break-words bg-black/40 p-4 text-[11px] leading-relaxed text-fg-muted"
      >
        {text || "— waiting for log output —"}
      </pre>
    </div>
  );
}
