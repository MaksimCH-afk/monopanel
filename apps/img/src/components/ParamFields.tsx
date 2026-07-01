"use client";

import { ParamSpec } from "@/lib/types";

// Renders a model's parameter panel purely from its schema — no per-model code.
export function ParamFields({
  schema,
  values,
  onChange,
}: {
  schema: ParamSpec[];
  values: Record<string, unknown>;
  onChange: (key: string, value: unknown) => void;
}) {
  if (!schema.length) {
    return <p className="mono text-[10px] text-fg-faint">no parameters</p>;
  }
  return (
    <div className="space-y-2.5">
      {schema.map((p) => {
        const val = values[p.key] ?? p.default ?? "";
        return (
          <div key={p.key} className="space-y-1">
            <label className="label flex items-center justify-between">
              <span>{p.label}</span>
              {(p.type === "slider" || p.type === "number") && (
                <span className="text-param">{String(val)}</span>
              )}
            </label>

            {p.type === "text" && (
              <input
                type="text"
                value={String(val)}
                onChange={(e) => onChange(p.key, e.target.value)}
                className="w-full rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-param"
              />
            )}

            {p.type === "number" && (
              <input
                type="number"
                value={Number(val)}
                min={p.min}
                max={p.max}
                step={p.step}
                onChange={(e) => onChange(p.key, Number(e.target.value))}
                className="w-full rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-param"
              />
            )}

            {p.type === "slider" && (
              <input
                type="range"
                value={Number(val)}
                min={p.min ?? 0}
                max={p.max ?? 100}
                step={p.step ?? 1}
                onChange={(e) => onChange(p.key, Number(e.target.value))}
                className="w-full"
              />
            )}

            {p.type === "boolean" && (
              <button
                type="button"
                onClick={() => onChange(p.key, !val)}
                className={`mono rounded px-2 py-1 text-[11px] uppercase tracking-wider ${
                  val ? "bg-param/20 text-param" : "border border-bg-border text-fg-muted"
                }`}
              >
                {val ? "on" : "off"}
              </button>
            )}

            {p.type === "select" && (
              <select
                value={String(val)}
                onChange={(e) => onChange(p.key, e.target.value)}
                className="w-full rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-param"
              >
                {(p.options ?? []).map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            )}

            {p.help && <p className="mono text-[10px] text-fg-faint">{p.help}</p>}
          </div>
        );
      })}
    </div>
  );
}
