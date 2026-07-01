"use client";

import { useEffect, useState } from "react";
import { Model, PresetDto } from "@/lib/types";
import { PlusIcon, TrashIcon, EditIcon } from "./icons";

const PALETTE = [
  "#22d3ee", "#f59e0b", "#ec4899", "#34d399", "#a78bfa",
  "#f87171", "#60a5fa", "#fbbf24", "#4ade80", "#e879f9",
];

export function SettingsView() {
  const [models, setModels] = useState<Model[]>([]);
  const [presets, setPresets] = useState<PresetDto[]>([]);
  const [settings, setSettings] = useState<{
    openRouterApiKey: string | null;
    hasStoredKey: boolean;
    hasEnvKey: boolean;
    defaultWebhookUrl: string;
  } | null>(null);

  const reloadModels = () =>
    fetch("/api/models").then((r) => r.json()).then((d) => setModels(d.models ?? []));
  const reloadPresets = () =>
    fetch("/api/presets").then((r) => r.json()).then((d) => setPresets(d.presets ?? []));

  useEffect(() => {
    reloadModels();
    reloadPresets();
    fetch("/api/settings").then((r) => r.json()).then(setSettings).catch(() => {});
  }, []);

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-3xl space-y-10">
        <ApiSection settings={settings} onSaved={() =>
          fetch("/api/settings").then((r) => r.json()).then(setSettings)} />
        <ModelsSection models={models} reload={reloadModels} />
        <PresetsSection presets={presets} reload={reloadPresets} />
      </div>
    </div>
  );
}

/* ---------------- API & integrations ---------------- */
function ApiSection({
  settings,
  onSaved,
}: {
  settings: {
    openRouterApiKey: string | null;
    hasStoredKey: boolean;
    hasEnvKey: boolean;
    defaultWebhookUrl: string;
  } | null;
  onSaved: () => void;
}) {
  const [key, setKey] = useState("");
  const [webhook, setWebhook] = useState("");
  const [status, setStatus] = useState("");

  useEffect(() => {
    if (settings) setWebhook(settings.defaultWebhookUrl ?? "");
  }, [settings]);

  async function save() {
    setStatus("saving…");
    const body: Record<string, string> = { defaultWebhookUrl: webhook };
    if (key.trim()) body.openRouterApiKey = key.trim();
    await fetch("/api/settings", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    setKey("");
    setStatus("saved");
    setTimeout(() => setStatus(""), 1500);
    onSaved();
  }

  return (
    <section className="space-y-4">
      <h2 className="label">API &amp; integrations</h2>

      <div className="space-y-1">
        <label className="label">OpenRouter API key</label>
        <input
          type="password"
          value={key}
          onChange={(e) => setKey(e.target.value)}
          placeholder={
            settings?.hasStoredKey
              ? `stored (${settings.openRouterApiKey})`
              : settings?.hasEnvKey
                ? "using OPENROUTER_API_KEY from env"
                : "sk-or-…"
          }
          className="w-full rounded border border-bg-border bg-bg px-2.5 py-1.5 text-sm text-fg outline-none focus:border-action"
        />
        <p className="mono text-[10px] text-fg-faint">
          Get a free key at openrouter.ai/keys. Falls back to the OPENROUTER_API_KEY env var.
        </p>
      </div>

      <div className="space-y-1">
        <label className="label">Default webhook URL</label>
        <input
          type="text"
          value={webhook}
          onChange={(e) => setWebhook(e.target.value)}
          placeholder="https://…  (POST on session completion)"
          className="w-full rounded border border-bg-border bg-bg px-2.5 py-1.5 text-sm text-fg outline-none focus:border-action"
        />
      </div>

      <div className="flex items-center gap-3">
        <button
          onClick={save}
          className="mono rounded bg-action px-4 py-1.5 text-xs font-medium uppercase tracking-wider text-black hover:opacity-90"
        >
          Save
        </button>
        {status && <span className="mono text-[11px] text-fg-muted">{status}</span>}
      </div>
    </section>
  );
}

/* ---------------- Models ---------------- */
function ModelsSection({ models, reload }: { models: Model[]; reload: () => void }) {
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  async function toggle(m: Model) {
    await fetch(`/api/models/${m.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ enabled: !m.enabled }),
    });
    reload();
  }
  async function remove(m: Model) {
    if (!confirm(`Delete model "${m.name}"? Its results are removed too.`)) return;
    await fetch(`/api/models/${m.id}`, { method: "DELETE" });
    reload();
  }

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="label">Models</h2>
        <button
          onClick={() => setAdding((v) => !v)}
          className="mono flex items-center gap-1 rounded border border-bg-border px-2 py-1 text-[11px] uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
        >
          <PlusIcon width={12} height={12} /> add model
        </button>
      </div>

      {adding && <AddModelForm onDone={() => { setAdding(false); reload(); }} />}

      <div className="space-y-1.5">
        {models.map((m) =>
          editingId === m.id ? (
            <EditModelForm
              key={m.id}
              model={m}
              onDone={() => {
                setEditingId(null);
                reload();
              }}
            />
          ) : (
            <div
              key={m.id}
              className="flex items-center gap-3 rounded border border-bg-border bg-bg-panel px-3 py-2"
            >
              <span
                className="inline-block h-3 w-3 shrink-0 rounded-full"
                style={{ background: m.color }}
              />
              <div className="min-w-0 flex-1">
                <p className="mono truncate text-sm text-fg">{m.name}</p>
                <p className="mono truncate text-[10px] text-fg-faint">
                  {m.modelId}
                  {` · ${m.aspectRatio}`}
                  {m.hasOwnKey && " · own key"}
                </p>
              </div>
              <button
                onClick={() => setEditingId(m.id)}
                className="rounded p-1.5 text-fg-muted hover:bg-bg-hover hover:text-fg"
                title="Edit"
              >
                <EditIcon width={14} height={14} />
              </button>
              <button
                onClick={() => toggle(m)}
                className={`mono rounded px-2 py-1 text-[10px] uppercase tracking-wider ${
                  m.enabled ? "bg-action/20 text-action" : "border border-bg-border text-fg-muted"
                }`}
              >
                {m.enabled ? "enabled" : "disabled"}
              </button>
              <button
                onClick={() => remove(m)}
                className="rounded p-1.5 text-fg-muted hover:bg-bg-hover hover:text-red-400"
                title="Delete"
              >
                <TrashIcon width={14} height={14} />
              </button>
            </div>
          ),
        )}
        {models.length === 0 && (
          <p className="mono text-xs text-fg-faint">no models — add one above</p>
        )}
      </div>
    </section>
  );
}

const ASPECT_RATIOS = ["1:1", "16:9", "21:9", "4:3", "3:2", "9:16", "2:3"];

function EditModelForm({ model, onDone }: { model: Model; onDone: () => void }) {
  const [name, setName] = useState(model.name);
  const [modelId, setModelId] = useState(model.modelId);
  const [color, setColor] = useState(model.color);
  const [aspectRatio, setAspectRatio] = useState(model.aspectRatio);
  const [apiKey, setApiKey] = useState("");
  const [error, setError] = useState("");

  async function save() {
    setError("");
    if (!name.trim() || !modelId.trim()) {
      setError("Name and model id are required.");
      return;
    }
    const body: Record<string, unknown> = {
      name: name.trim(),
      modelId: modelId.trim(),
      color,
      aspectRatio,
    };
    // Only send the key when changed, so we don't clobber the stored one.
    if (apiKey.trim()) body.apiKey = apiKey.trim();
    const res = await fetch(`/api/models/${model.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      const d = await res.json();
      setError(d.error ?? "Failed to save.");
      return;
    }
    onDone();
  }

  return (
    <div className="space-y-3 rounded border border-action/40 bg-bg-panel p-4">
      <div className="grid grid-cols-2 gap-3">
        <Field label="Display name">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-sm text-fg outline-none focus:border-action"
          />
        </Field>
        <Field label="OpenRouter model id">
          <input
            value={modelId}
            onChange={(e) => setModelId(e.target.value)}
            className="mono w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-xs text-fg outline-none focus:border-action"
          />
        </Field>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <Field label="Aspect ratio">
          <select
            value={aspectRatio}
            onChange={(e) => setAspectRatio(e.target.value)}
            className="w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-xs text-fg outline-none focus:border-action"
          >
            {ASPECT_RATIOS.map((r) => (
              <option key={r} value={r}>
                {r}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Accent color">
          <div className="flex flex-wrap gap-1.5">
            {PALETTE.map((c) => (
              <button
                key={c}
                onClick={() => setColor(c)}
                className="h-6 w-6 rounded-full transition-transform hover:scale-110"
                style={{
                  background: c,
                  outline: color === c ? "2px solid #fff" : "none",
                  outlineOffset: "1px",
                }}
              />
            ))}
            <input
              type="color"
              value={color}
              onChange={(e) => setColor(e.target.value)}
              className="h-6 w-6 cursor-pointer rounded border-0 bg-transparent p-0"
            />
          </div>
        </Field>
      </div>

      <Field label={model.hasOwnKey ? "Replace API key (leave blank to keep)" : "Per-model API key (optional)"}>
        <input
          type="password"
          value={apiKey}
          onChange={(e) => setApiKey(e.target.value)}
          placeholder={model.hasOwnKey ? "•••• stored" : "overrides the global key"}
          className="w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-sm text-fg outline-none focus:border-action"
        />
      </Field>

      {error && <p className="mono text-[11px] text-red-400">{error}</p>}

      <div className="flex gap-2">
        <button
          onClick={save}
          className="mono rounded bg-action px-4 py-1.5 text-xs font-medium uppercase tracking-wider text-black hover:opacity-90"
        >
          Save
        </button>
        <button
          onClick={onDone}
          className="mono rounded border border-bg-border px-4 py-1.5 text-xs uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

function AddModelForm({ onDone }: { onDone: () => void }) {
  const [name, setName] = useState("");
  const [modelId, setModelId] = useState("");
  const [color, setColor] = useState(PALETTE[0]);
  const [apiKey, setApiKey] = useState("");
  const [error, setError] = useState("");

  async function submit() {
    setError("");
    if (!name.trim() || !modelId.trim()) {
      setError("Name and model id are required.");
      return;
    }
    const res = await fetch("/api/models", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: name.trim(),
        modelId: modelId.trim(),
        color,
        apiKey: apiKey.trim() || undefined,
      }),
    });
    if (!res.ok) {
      const d = await res.json();
      setError(d.error ?? "Failed to add model.");
      return;
    }
    onDone();
  }

  return (
    <div className="space-y-3 rounded border border-bg-border bg-bg-panel p-4">
      <div className="grid grid-cols-2 gap-3">
        <Field label="Display name">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Gemini Flash Image"
            className="w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-sm text-fg outline-none focus:border-action"
          />
        </Field>
        <Field label="OpenRouter model id">
          <input
            value={modelId}
            onChange={(e) => setModelId(e.target.value)}
            placeholder="google/gemini-2.5-flash-image-preview"
            className="mono w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-xs text-fg outline-none focus:border-action"
          />
        </Field>
      </div>

      <Field label="Accent color">
        <div className="flex flex-wrap gap-1.5">
          {PALETTE.map((c) => (
            <button
              key={c}
              onClick={() => setColor(c)}
              className="h-6 w-6 rounded-full transition-transform hover:scale-110"
              style={{
                background: c,
                outline: color === c ? "2px solid #fff" : "none",
                outlineOffset: "1px",
              }}
            />
          ))}
          <input
            type="color"
            value={color}
            onChange={(e) => setColor(e.target.value)}
            className="h-6 w-6 cursor-pointer rounded border-0 bg-transparent p-0"
          />
        </div>
      </Field>

      <Field label="Per-model API key (optional)">
        <input
          type="password"
          value={apiKey}
          onChange={(e) => setApiKey(e.target.value)}
          placeholder="overrides the global key"
          className="w-full rounded border border-bg-border bg-bg px-2 py-1.5 text-sm text-fg outline-none focus:border-action"
        />
      </Field>

      {error && <p className="mono text-[11px] text-red-400">{error}</p>}

      <div className="flex gap-2">
        <button
          onClick={submit}
          className="mono rounded bg-action px-4 py-1.5 text-xs font-medium uppercase tracking-wider text-black hover:opacity-90"
        >
          Add
        </button>
        <button
          onClick={onDone}
          className="mono rounded border border-bg-border px-4 py-1.5 text-xs uppercase tracking-wider text-fg-muted hover:bg-bg-hover"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

/* ---------------- Presets ---------------- */
function PresetsSection({ presets, reload }: { presets: PresetDto[]; reload: () => void }) {
  const [name, setName] = useState("");
  const [seedSync, setSeedSync] = useState(true);
  const [batchSize, setBatchSize] = useState(1);
  const [blindMode, setBlindMode] = useState(false);

  async function add() {
    if (!name.trim()) return;
    await fetch("/api/presets", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: name.trim(), config: { seedSync, batchSize, blindMode } }),
    });
    setName("");
    reload();
  }
  async function remove(id: string) {
    await fetch(`/api/presets/${id}`, { method: "DELETE" });
    reload();
  }

  return (
    <section className="space-y-3">
      <h2 className="label">Configuration presets</h2>

      <div className="space-y-1.5">
        {presets.map((p) => (
          <div
            key={p.id}
            className="flex items-center gap-3 rounded border border-bg-border bg-bg-panel px-3 py-2"
          >
            <span className="mono flex-1 text-sm text-fg">{p.name}</span>
            <span className="mono text-[10px] text-fg-faint">
              {JSON.stringify(p.config)}
            </span>
            <button
              onClick={() => remove(p.id)}
              className="rounded p-1.5 text-fg-muted hover:bg-bg-hover hover:text-red-400"
            >
              <TrashIcon width={14} height={14} />
            </button>
          </div>
        ))}
        {presets.length === 0 && (
          <p className="mono text-xs text-fg-faint">no presets yet</p>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded border border-bg-border bg-bg-panel p-4">
        <Field label="Name">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="High-batch compare"
            className="w-48 rounded border border-bg-border bg-bg px-2 py-1.5 text-sm text-fg outline-none focus:border-param"
          />
        </Field>
        <label className="mono flex items-center gap-2 text-xs text-fg-muted">
          <input type="checkbox" checked={seedSync} onChange={(e) => setSeedSync(e.target.checked)} />
          sync seed
        </label>
        <label className="mono flex items-center gap-2 text-xs text-fg-muted">
          batch
          <input
            type="number"
            min={1}
            max={8}
            value={batchSize}
            onChange={(e) => setBatchSize(Number(e.target.value))}
            className="w-14 rounded border border-bg-border bg-bg px-1.5 py-1 text-xs"
          />
        </label>
        <label className="mono flex items-center gap-2 text-xs text-fg-muted">
          <input type="checkbox" checked={blindMode} onChange={(e) => setBlindMode(e.target.checked)} />
          blind
        </label>
        <button
          onClick={add}
          className="mono rounded bg-param px-4 py-1.5 text-xs font-medium uppercase tracking-wider text-black hover:opacity-90"
        >
          Save preset
        </button>
      </div>
    </section>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <label className="label">{label}</label>
      {children}
    </div>
  );
}
