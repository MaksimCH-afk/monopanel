"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { ResultDto, SessionDto } from "@/lib/types";
import { ResultCard } from "./ResultCard";
import { Lightbox } from "./Lightbox";
import { DownloadIcon } from "./icons";

export function Gallery({
  session,
  onChanged,
}: {
  session: SessionDto;
  onChanged?: () => void;
}) {
  const [results, setResults] = useState<ResultDto[]>(session.results);
  const [revealed, setRevealed] = useState(false);
  const [expanded, setExpanded] = useState<ResultDto | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => setResults(session.results), [session.results]);
  useEffect(() => () => {
    if (pollRef.current) clearInterval(pollRef.current);
  }, []);

  const blind = Boolean(session.config.blindMode) && !revealed;

  const active = useMemo(
    () => results.filter((r) => r.status === "pending" || r.status === "running"),
    [results],
  );
  // Cards in the grid: finished cells plus any currently (re)generating so a
  // retried card stays in place showing its spinner instead of vanishing.
  const visible = useMemo(
    () =>
      results.filter(
        (r) =>
          r.status === "done" || r.status === "error" || r.status === "running",
      ),
    [results],
  );

  // Progress rows: one per model still working, with that model's color.
  const progress = useMemo(() => {
    const byModel = new Map<
      string,
      { name: string; color: string; done: number; total: number }
    >();
    for (const r of results) {
      const e =
        byModel.get(r.modelId) ??
        { name: r.modelName, color: r.modelColor, done: 0, total: 0 };
      e.total++;
      if (r.status === "done" || r.status === "error") e.done++;
      byModel.set(r.modelId, e);
    }
    return Array.from(byModel.values()).filter((m) => m.done < m.total);
  }, [results]);

  const sorted = useMemo(
    () =>
      [...visible].sort((a, b) => {
        if (a.pinned !== b.pinned) return a.pinned ? -1 : 1;
        if (a.promptIndex !== b.promptIndex) return a.promptIndex - b.promptIndex;
        return a.batchIndex - b.batchIndex;
      }),
    [visible],
  );

  async function patch(id: string, body: Record<string, unknown>) {
    setResults((prev) => prev.map((r) => (r.id === id ? { ...r, ...body } : r)));
    await fetch(`/api/results/${id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }).catch(() => {});
    onChanged?.();
  }

  // Poll the session until no cell is pending/running (used after a retry).
  function startPolling() {
    if (pollRef.current) clearInterval(pollRef.current);
    pollRef.current = setInterval(async () => {
      try {
        const d = await fetch(`/api/sessions/${session.id}`).then((r) => r.json());
        if (d.session?.results) {
          setResults(d.session.results);
          const busy = d.session.results.some(
            (r: ResultDto) => r.status === "pending" || r.status === "running",
          );
          if (!busy && pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
            onChanged?.();
          }
        }
      } catch {
        /* keep polling */
      }
    }, 1500);
  }

  async function retry(id: string) {
    // Optimistic: show the spinner immediately.
    setResults((prev) =>
      prev.map((r) =>
        r.id === id ? { ...r, status: "running", error: null, imageUrl: null } : r,
      ),
    );
    fetch(`/api/results/${id}`, { method: "POST" }).catch(() => {});
    startPolling();
  }

  function exportSelected() {
    for (const r of sorted) {
      if (!r.imageUrl) continue;
      const a = document.createElement("a");
      a.href = r.imageUrl;
      a.download = `${r.modelName}_${r.id}.png`;
      document.body.appendChild(a);
      a.click();
      a.remove();
    }
  }

  return (
    <div className="flex h-full flex-col">
      {/* Toolbar */}
      <div className="flex shrink-0 items-center gap-4 border-b border-bg-border px-4 py-2.5">
        <span className="mono text-xs text-fg-muted">
          {visible.length} result{visible.length === 1 ? "" : "s"}
          {active.length > 0 && (
            <span className="text-fg-faint"> · {active.length} pending</span>
          )}
        </span>
        {Boolean(session.config.blindMode) && (
          <button
            onClick={() => setRevealed((v) => !v)}
            className="mono rounded border border-bg-border px-2 py-1 text-[11px] uppercase tracking-wider text-param hover:bg-bg-hover"
          >
            blind: {blind ? "on — reveal" : "revealed"}
          </button>
        )}
        <div className="ml-auto flex items-center gap-2">
          <button
            onClick={exportSelected}
            disabled={visible.length === 0}
            className="mono flex items-center gap-1.5 rounded border border-bg-border px-2.5 py-1 text-[11px] uppercase tracking-wider text-fg-muted hover:bg-bg-hover disabled:opacity-40"
          >
            <DownloadIcon width={13} height={13} /> Export PNGs
          </button>
          <a
            href={`/api/sessions/${session.id}/export`}
            className="mono flex items-center gap-1.5 rounded border border-bg-border px-2.5 py-1 text-[11px] uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
          >
            <DownloadIcon width={13} height={13} /> Export ZIP
          </a>
        </div>
      </div>

      {/* Body */}
      <div className="min-h-0 flex-1 overflow-y-auto p-4">
        {progress.length > 0 && (
          <div className="mb-4 space-y-2">
            {progress.map((m) => (
              <div key={m.name} className="flex items-center gap-3">
                <span
                  className="inline-block h-2 w-2 shrink-0 rounded-full"
                  style={{ background: m.color }}
                />
                <span className="mono w-44 shrink-0 truncate text-xs text-fg">
                  {m.name}
                </span>
                <div className="relative h-1 flex-1 overflow-hidden rounded-full bg-bg-border">
                  <div
                    className="absolute inset-y-0 left-0 w-1/3 animate-progress-indeterminate rounded-full"
                    style={{ background: m.color }}
                  />
                </div>
                <span className="mono w-12 shrink-0 text-right text-[10px] text-fg-faint">
                  {m.done}/{m.total}
                </span>
              </div>
            ))}
          </div>
        )}

        {sorted.length === 0 && progress.length === 0 ? (
          <div className="flex h-full items-center justify-center text-fg-faint">
            <span className="mono text-xs">no results</span>
          </div>
        ) : (
          <div
            className="grid gap-3"
            style={{
              gridTemplateColumns: "repeat(auto-fill, minmax(200px, 1fr))",
            }}
          >
            {sorted.map((r, i) => (
              <ResultCard
                key={r.id}
                result={r}
                index={i}
                blind={blind}
                onRate={(id, rating) => patch(id, { rating })}
                onPin={(id, pinned) => patch(id, { pinned })}
                onExpand={setExpanded}
                onRetry={retry}
              />
            ))}
          </div>
        )}
      </div>

      <Lightbox result={expanded} blind={blind} onClose={() => setExpanded(null)} />
    </div>
  );
}
