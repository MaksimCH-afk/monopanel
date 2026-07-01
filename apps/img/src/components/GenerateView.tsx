"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Model, PresetDto, SessionDto } from "@/lib/types";
import { ParamFields } from "./ParamFields";
import { Gallery } from "./Gallery";
import { ImageIcon, PlusIcon } from "./icons";

const abLabel = (i: number) =>
  "A" + String.fromCharCode(65 + i); // AA, AB, AC...

export function GenerateView() {
  const [models, setModels] = useState<Model[]>([]);
  const [presets, setPresets] = useState<PresetDto[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [expandedModel, setExpandedModel] = useState<string | null>(null);
  const [modelParams, setModelParams] = useState<Record<string, Record<string, unknown>>>({});

  const [abMode, setAbMode] = useState(false);
  const [prompts, setPrompts] = useState<string[]>([""]);

  const [seedSync, setSeedSync] = useState(true);
  const [batchSize, setBatchSize] = useState(1);
  const [blindMode, setBlindMode] = useState(false);
  const [presetId, setPresetId] = useState<string>("");
  const [aspectRatio, setAspectRatio] = useState("1:1");

  const [session, setSession] = useState<SessionDto | null>(null);
  const [error, setError] = useState<string | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const generating = session?.status === "running";

  // Initial load.
  useEffect(() => {
    fetch("/api/models")
      .then((r) => r.json())
      .then((d) => {
        const ms: Model[] = d.models ?? [];
        setModels(ms);
        setSelected(new Set(ms.filter((m) => m.enabled).map((m) => m.id)));
      })
      .catch(() => {});
    fetch("/api/presets")
      .then((r) => r.json())
      .then((d) => setPresets(d.presets ?? []))
      .catch(() => {});
  }, []);

  // Poll the running session.
  const poll = useCallback((id: string) => {
    if (pollRef.current) clearInterval(pollRef.current);
    pollRef.current = setInterval(async () => {
      try {
        const d = await fetch(`/api/sessions/${id}`).then((r) => r.json());
        if (d.session) {
          setSession(d.session);
          if (d.session.status !== "running" && pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
          }
        }
      } catch {
        /* keep polling */
      }
    }, 1500);
  }, []);

  useEffect(() => () => {
    if (pollRef.current) clearInterval(pollRef.current);
  }, []);

  const toggleModel = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });

  function applyPreset(id: string) {
    setPresetId(id);
    const p = presets.find((x) => x.id === id);
    if (!p) return;
    const c = p.config as Record<string, unknown>;
    if (typeof c.seedSync === "boolean") setSeedSync(c.seedSync);
    if (typeof c.batchSize === "number") setBatchSize(c.batchSize);
    if (typeof c.blindMode === "boolean") setBlindMode(c.blindMode);
  }

  const generate = useCallback(async () => {
    setError(null);
    const activePrompts = (abMode ? prompts : [prompts[0]])
      .map((p) => p.trim())
      .filter(Boolean);
    const modelIds = Array.from(selected);

    if (activePrompts.length === 0) return setError("Write a prompt first.");
    if (modelIds.length === 0) return setError("Select at least one model.");

    try {
      const res = await fetch("/api/sessions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          prompts: activePrompts,
          modelIds,
          modelParams,
          config: { seedSync, batchSize, blindMode, aspectRatio, presetId: presetId || null },
        }),
      });
      const d = await res.json();
      if (!res.ok) return setError(d.error ?? "Failed to start generation.");
      setSession(d.session);
      localStorage.setItem("lastSessionId", d.session.id);
      poll(d.session.id);
    } catch {
      setError("Network error starting generation.");
    }
  }, [abMode, prompts, selected, modelParams, seedSync, batchSize, blindMode, aspectRatio, presetId, poll]);

  // ⌘/Ctrl + Enter
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "Enter" && !generating) {
        e.preventDefault();
        generate();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [generate, generating]);

  return (
    <div className="flex h-full">
      {/* ---- Left panel ---- */}
      <aside className="flex w-[340px] shrink-0 flex-col border-r border-bg-border bg-bg-panel">
        <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-4">
          {/* Prompt */}
          <section className="space-y-2">
            <div className="flex items-center justify-between">
              <span className="label">Prompt</span>
              <button
                onClick={() => {
                  setAbMode((v) => !v);
                  if (!abMode && prompts.length < 2)
                    setPrompts([prompts[0] || "", ""]);
                }}
                className={`mono rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wider ${
                  abMode ? "bg-action/20 text-action" : "border border-bg-border text-fg-muted"
                }`}
              >
                A/B {abMode ? "on" : "off"}
              </button>
            </div>

            {!abMode ? (
              <textarea
                value={prompts[0]}
                onChange={(e) => setPrompts([e.target.value])}
                placeholder="Describe what you want to generate…"
                rows={4}
                className="w-full resize-y rounded border border-bg-border bg-bg p-2.5 text-sm text-fg outline-none placeholder:text-fg-faint focus:border-action"
              />
            ) : (
              <div className="space-y-2">
                {prompts.map((p, i) => (
                  <div key={i} className="space-y-1">
                    <div className="flex items-center justify-between">
                      <span className="mono text-[10px] text-action">{abLabel(i)}</span>
                      {prompts.length > 2 && (
                        <button
                          onClick={() => setPrompts(prompts.filter((_, j) => j !== i))}
                          className="mono text-[10px] text-fg-faint hover:text-fg"
                        >
                          remove
                        </button>
                      )}
                    </div>
                    <textarea
                      value={p}
                      onChange={(e) =>
                        setPrompts(prompts.map((x, j) => (j === i ? e.target.value : x)))
                      }
                      rows={3}
                      className="w-full resize-y rounded border border-bg-border bg-bg p-2 text-sm text-fg outline-none focus:border-action"
                    />
                  </div>
                ))}
                <button
                  onClick={() => setPrompts([...prompts, ""])}
                  className="mono flex items-center gap-1 text-[11px] text-fg-muted hover:text-fg"
                >
                  <PlusIcon width={12} height={12} /> add prompt
                </button>
              </div>
            )}
          </section>

          {/* Models */}
          <section className="space-y-2">
            <span className="label">Models</span>
            {models.length === 0 ? (
              <p className="mono text-[11px] text-fg-faint">
                No models configured. Add one in Settings.
              </p>
            ) : (
              <div className="space-y-1.5">
                {models.map((m) => {
                  const on = selected.has(m.id);
                  const open = expandedModel === m.id;
                  return (
                    <div
                      key={m.id}
                      className={`rounded border ${on ? "border-bg-border" : "border-transparent"} ${
                        !m.enabled ? "opacity-40" : ""
                      }`}
                    >
                      <div className="flex items-center gap-2 px-2 py-1.5">
                        <button
                          onClick={() => m.enabled && toggleModel(m.id)}
                          disabled={!m.enabled}
                          className="flex flex-1 items-center gap-2 text-left"
                        >
                          <span
                            className="inline-block h-2.5 w-2.5 rounded-full"
                            style={{
                              background: on ? m.color : "transparent",
                              boxShadow: `inset 0 0 0 1.5px ${m.color}`,
                            }}
                          />
                          <span className="mono truncate text-xs text-fg">{m.name}</span>
                        </button>
                        {m.paramsSchema.length > 0 && (
                          <button
                            onClick={() => setExpandedModel(open ? null : m.id)}
                            className="mono text-[10px] text-fg-faint hover:text-param"
                          >
                            {open ? "−" : "params"}
                          </button>
                        )}
                      </div>
                      {open && (
                        <div className="border-t border-bg-border p-2.5">
                          <ParamFields
                            schema={m.paramsSchema}
                            values={modelParams[m.id] ?? m.defaultParams}
                            onChange={(k, v) =>
                              setModelParams((prev) => ({
                                ...prev,
                                [m.id]: {
                                  ...(prev[m.id] ?? m.defaultParams),
                                  [k]: v,
                                },
                              }))
                            }
                          />
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </section>

          {/* Parameters */}
          <section className="space-y-3">
            <span className="label" style={{ color: "#a78bfa" }}>
              Parameters
            </span>

            <Toggle label="Sync seed" value={seedSync} onChange={setSeedSync} />

            <div className="space-y-1">
              <label className="label flex items-center justify-between">
                <span>Batch / model</span>
                <span className="text-param">{batchSize}</span>
              </label>
              <input
                type="range"
                min={1}
                max={8}
                value={batchSize}
                onChange={(e) => setBatchSize(Number(e.target.value))}
                className="w-full"
              />
            </div>

            <div className="space-y-1">
              <label className="label">Aspect ratio</label>
              <select
                value={aspectRatio}
                onChange={(e) => setAspectRatio(e.target.value)}
                className="w-full rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-param"
              >
                {["1:1", "16:9", "21:9", "4:3", "3:2", "9:16", "2:3"].map((r) => (
                  <option key={r} value={r}>
                    {r}
                  </option>
                ))}
              </select>
            </div>

            <div className="space-y-1">
              <label className="label">Preset</label>
              <select
                value={presetId}
                onChange={(e) => applyPreset(e.target.value)}
                className="w-full rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-param"
              >
                <option value="">— none —</option>
                {presets.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>

            <Toggle label="Blind mode" value={blindMode} onChange={setBlindMode} />
          </section>
        </div>

        {/* Generate button pinned to bottom */}
        <div className="shrink-0 border-t border-bg-border p-3">
          {error && (
            <p className="mono mb-2 text-[11px] text-red-400">{error}</p>
          )}
          <button
            onClick={generate}
            disabled={generating}
            className="mono w-full rounded bg-action py-2.5 text-sm font-medium uppercase tracking-wider text-black transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {generating ? "Generating…" : "Generate"}
          </button>
          <p className="mono mt-1.5 text-center text-[10px] text-fg-faint">
            ⌘/Ctrl + Enter
          </p>
        </div>
      </aside>

      {/* ---- Right: results ---- */}
      <section className="min-w-0 flex-1">
        {session ? (
          <Gallery session={session} />
        ) : (
          <div className="flex h-full flex-col items-center justify-center gap-3 text-fg-faint">
            <ImageIcon width={40} height={40} />
            <p className="mono text-xs">write a prompt, pick models, hit generate</p>
          </div>
        )}
      </section>
    </div>
  );
}

function Toggle({
  label,
  value,
  onChange,
}: {
  label: string;
  value: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <button
      onClick={() => onChange(!value)}
      className="flex w-full items-center justify-between"
    >
      <span className="label">{label}</span>
      <span
        className={`relative h-4 w-7 rounded-full transition-colors ${
          value ? "bg-param" : "bg-bg-border"
        }`}
      >
        <span
          className={`absolute top-0.5 h-3 w-3 rounded-full bg-white transition-transform ${
            value ? "translate-x-3.5" : "translate-x-0.5"
          }`}
        />
      </span>
    </button>
  );
}
