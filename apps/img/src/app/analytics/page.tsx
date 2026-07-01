"use client";

import { useEffect, useState } from "react";
import { AnalyticsModel } from "@/lib/types";
import { Stars } from "@/components/Stars";

export default function AnalyticsPage() {
  const [models, setModels] = useState<AnalyticsModel[]>([]);

  useEffect(() => {
    fetch("/api/analytics")
      .then((r) => r.json())
      .then((d) => setModels(d.models ?? []))
      .catch(() => {});
  }, []);

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-4xl space-y-5">
        <h1 className="label mb-4">Model analytics</h1>
        {models.length === 0 ? (
          <p className="mono text-xs text-fg-faint">
            no data yet — generate and rate some results
          </p>
        ) : (
          models.map((m) => (
            <div
              key={m.modelId}
              className="rounded border border-bg-border bg-bg-panel p-4"
              style={{ borderLeft: `2px solid ${m.modelColor}` }}
            >
              <div className="flex items-center gap-2">
                <span
                  className="inline-block h-2.5 w-2.5 rounded-full"
                  style={{ background: m.modelColor }}
                />
                <span className="mono text-sm text-fg">{m.modelName}</span>
                <div className="ml-auto">
                  <Sparkline points={m.latencyHistory.map((h) => h.latencyMs)} color={m.modelColor} />
                </div>
              </div>

              <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Metric label="Avg rating">
                  {m.avgRating != null ? (
                    <div className="flex items-center gap-2">
                      <Stars value={Math.round(m.avgRating)} size={12} />
                      <span className="mono text-xs text-fg">{m.avgRating.toFixed(1)}</span>
                    </div>
                  ) : (
                    <span className="mono text-xs text-fg-faint">—</span>
                  )}
                  <span className="mono text-[10px] text-fg-faint">{m.ratedCount} rated</span>
                </Metric>

                <Metric label="Avg latency">
                  <span className="mono text-sm text-fg">
                    {m.avgLatencyMs != null ? `${(m.avgLatencyMs / 1000).toFixed(1)}s` : "—"}
                  </span>
                </Metric>

                <Metric label="Success rate">
                  <span className="mono text-sm text-action">
                    {Math.round(m.successRate * 100)}%
                  </span>
                </Metric>

                <Metric label="Generations">
                  <span className="mono text-sm text-fg">{m.total}</span>
                  <span className="mono text-[10px] text-fg-faint">
                    {m.errored} failed
                  </span>
                </Metric>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function Metric({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <span className="label">{label}</span>
      <div>{children}</div>
    </div>
  );
}

function Sparkline({ points, color }: { points: number[]; color: string }) {
  if (points.length < 2) return null;
  const w = 120;
  const h = 28;
  const max = Math.max(...points);
  const min = Math.min(...points);
  const range = max - min || 1;
  const step = w / (points.length - 1);
  const path = points
    .map((p, i) => `${i === 0 ? "M" : "L"} ${(i * step).toFixed(1)} ${(h - ((p - min) / range) * h).toFixed(1)}`)
    .join(" ");
  return (
    <svg width={w} height={h} className="overflow-visible">
      <path d={path} fill="none" stroke={color} strokeWidth={1.5} />
    </svg>
  );
}
