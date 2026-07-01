"use client";

import { useEffect, useState } from "react";
import { PromptDto } from "@/lib/types";
import { CopyIcon } from "@/components/icons";

export default function HistoryPage() {
  const [prompts, setPrompts] = useState<PromptDto[]>([]);
  const [copied, setCopied] = useState<string | null>(null);

  useEffect(() => {
    fetch("/api/history")
      .then((r) => r.json())
      .then((d) => setPrompts(d.prompts ?? []))
      .catch(() => {});
  }, []);

  function copy(text: string, id: string) {
    navigator.clipboard?.writeText(text).then(() => {
      setCopied(id);
      setTimeout(() => setCopied(null), 1200);
    });
  }

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-3xl space-y-3">
        <h1 className="label mb-4">Prompt history</h1>
        {prompts.length === 0 ? (
          <p className="mono text-xs text-fg-faint">no prompts yet</p>
        ) : (
          prompts.map((p) => (
            <div
              key={p.id}
              className="group flex items-start gap-3 rounded border border-bg-border bg-bg-panel p-3"
            >
              <div className="min-w-0 flex-1">
                <p className="whitespace-pre-wrap break-words text-sm text-fg">{p.text}</p>
                <div className="mono mt-2 flex gap-4 text-[10px] text-fg-faint">
                  <span>used {p.useCount}×</span>
                  <span>{new Date(p.lastUsedAt).toLocaleString()}</span>
                </div>
              </div>
              <button
                onClick={() => copy(p.text, p.id)}
                className="mono flex shrink-0 items-center gap-1 rounded border border-bg-border px-2 py-1 text-[10px] uppercase tracking-wider text-fg-muted opacity-0 transition-opacity hover:bg-bg-hover group-hover:opacity-100"
              >
                <CopyIcon width={12} height={12} />
                {copied === p.id ? "copied" : "copy"}
              </button>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
