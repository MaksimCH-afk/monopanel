# ImageGen Dashboard v1.0

Developer tool for comparing AI image-generation models side by side.
Write a prompt, pick models, hit Generate — images arrive in a live grid
so you can rate, pin, and export the best ones. All models connect via
[OpenRouter](https://openrouter.ai).

**Stack:** Next.js 15 (App Router) &middot; TypeScript &middot; Prisma + SQLite &middot; Tailwind CSS &middot; Docker

---

## Table of Contents

1. [Quick Start (Docker)](#quick-start-docker)
2. [Quick Start (Local)](#quick-start-local)
3. [Architecture Overview](#architecture-overview)
4. [UI Tabs](#ui-tabs)
5. [Data Model](#data-model)
6. [Adapter System](#adapter-system)
7. [Generation Pipeline](#generation-pipeline)
8. [Logging](#logging)
9. [REST API](#rest-api)
10. [Configuration & Environment](#configuration--environment)
11. [Pre-Seeded Models](#pre-seeded-models)
12. [Version Scheme](#version-scheme)
13. [Project Structure](#project-structure)

---

## Quick Start (Docker)

```bash
cp .env.example .env
# Edit .env — set OPENROUTER_API_KEY and/or per-model OR_KEY_* vars

docker compose up --build
```

Open **http://localhost:3000**. The SQLite database and generated images
live in a named Docker volume (`imagegen-data`) and survive restarts.

On every container start the seed script reconciles the five built-in
models: corrects slugs, refreshes the parameter schema, and writes API
keys from env vars. Models added manually in the UI are left untouched.

## Quick Start (Local)

```bash
npm install
cp .env.example .env
# set DATABASE_URL="file:./data/imagegen.db"  and  DATA_DIR="./data"

npx prisma db push
npx tsx prisma/seed.ts   # optional — seeds example models
npm run dev
```

---

## Architecture Overview

```
┌─────────────────────── Next.js App ───────────────────────┐
│                                                           │
│  UI (React, Tailwind)          API Routes (/api/*)        │
│  ├── GenerateView              ├── sessions (CRUD, ZIP)   │
│  ├── Gallery / ResultCard      ├── models   (CRUD)        │
│  ├── SettingsView              ├── results  (rate/pin)    │
│  ├── LogsView                  ├── analytics, history     │
│  └── Header / Lightbox         ├── logs     (tail, ZIP)   │
│                                ├── presets, settings      │
│                                └── providers, images      │
├───────────────────────────────────────────────────────────┤
│  Library layer (src/lib/)                                 │
│  ├── generation.ts   — session orchestrator, retry        │
│  ├── adapters/       — pluggable model adapters           │
│  ├── logger.ts       — real-time sync file logging        │
│  ├── cache.ts        — tag/TTL in-memory cache            │
│  ├── images.ts       — save / serve generated images      │
│  ├── settings.ts     — key resolution, global settings    │
│  └── webhook.ts      — fire-and-forget POST on complete   │
├───────────────────────────────────────────────────────────┤
│  Prisma + SQLite (file-based, zero-config)                │
│  Docker volume for data persistence                       │
└───────────────────────────────────────────────────────────┘
         │
         ▼
   OpenRouter API  (chat/completions with image modalities)
```

The app is a single Next.js service. No separate backend, no external
database — SQLite runs in-process. Docker wraps everything into one
container with a persistent volume for the DB and images.

---

## UI Tabs

### Generate

Left panel: prompt textarea, A/B mode toggle (send multiple prompts),
model toggle chips with per-model parameter editing, generation controls
(sync seed, batch size, aspect ratio, preset selector, blind mode).
Press **Cmd+Enter** (or the Generate button) to start.

Right panel: live Gallery showing results as they arrive.

### Results

History of past sessions. Click to re-open a session's gallery.

### History

Every prompt ever used, sorted by last used. Click to reuse.

### Analytics

Per-model stats: average rating, success rate, average latency, and a
latency sparkline chart.

### Logs

Live log tail with file selector (project-wide or per-model), pause /
resume, download individual files or all logs as a ZIP.

### Settings

- Global OpenRouter API key and webhook URL.
- Full model management: add / edit / toggle / delete.
- Edit model form: name, provider, model ID (slug), API key, color,
  aspect ratio, JSON parameter schema.
- Preset management: save / delete named generation configs.

---

## Data Model

Defined in `prisma/schema.prisma`. SQLite, all JSON stored as stringified
text.

| Table | Purpose |
|-------|---------|
| **ModelAdapter** | A connected model. Stores provider, model slug, API key, accent color, enabled flag, parameter schema JSON, default params. |
| **Session** | One generation run. Contains prompts array, config (seed, batch, blind mode, etc.), status, optional webhook URL. |
| **Result** | One cell in the grid: (session × model × prompt × batch index). Image path, latency, seed, star rating, pinned flag, error. |
| **Preset** | Named generation config (seed sync, batch size, aspect ratio, blind mode). |
| **PromptHistory** | Prompt text with use count and last-used timestamp. |
| **Setting** | Global key/value store (API key, webhook URL, etc.). |

Cascade deletes: deleting a session removes its results; deleting a model
removes its results.

---

## Adapter System

Models connect through a **pluggable adapter registry** (`src/lib/adapters/`).

```
types.ts     — ImageAdapter interface, ParamSpec, GenerateInput/Output
openrouter.ts — OpenRouter adapter (the default and only built-in adapter)
registry.ts  — maps provider name → adapter instance
```

### How it works

1. Each `ModelAdapter` row has a `provider` field (e.g. `"openrouter"`).
2. `getAdapter(provider)` returns the matching `ImageAdapter` instance.
3. The adapter's `generate()` receives the prompt, model slug, API key,
   aspect ratio, and a params map — and returns base64 image data.

### Adding a new provider

Implement the `ImageAdapter` interface, register it in `registry.ts`.
Zero UI changes needed — the parameter panel renders from the model's
`paramsSchemaJson` automatically.

### Parameter Schema

Each model stores a JSON array of `ParamSpec` objects:

```ts
interface ParamSpec {
  key: string;       // e.g. "negative_prompt", "ic_image_size"
  label: string;     // display name
  type: "text" | "number" | "slider" | "select" | "boolean";
  default?: unknown;
  options?: { value: string; label: string }[];  // for select type
  help?: string;     // tooltip
}
```

Keys prefixed `ic_` are forwarded into OpenRouter's `image_config`
object (e.g. `ic_image_size` → `image_config.image_size`).

The `ParamFields` component auto-renders the settings panel from this
schema with no per-model UI code.

### OpenRouter Adapter Details

- Calls `POST https://openrouter.ai/api/v1/chat/completions` with
  `modalities: ["image"]`.
- Dedicated image models (FLUX, Seedream, Recraft, Grok) only support
  `["image"]`. If the API returns 404 "No endpoints found that support
  the requested output modalities", the adapter automatically retries
  with `["image", "text"]` for multimodal models (e.g. Gemini).
- `image_config` carries aspect ratio and any `ic_`-prefixed params.
- `negative_prompt` is appended to the prompt text as `\nAvoid: ...`.

---

## Generation Pipeline

Defined in `src/lib/generation.ts`.

1. **Session creation** (`POST /api/sessions`): validates prompts and
   models, creates `Result` rows for every (prompt × model × batch)
   cell, kicks off `runSession()` in the background.
2. **`runSession()`**: fans out with concurrency 4. Each worker picks the
   next pending result and calls `processResult()`.
3. **`processResult()`**: resolves API key (per-model → stored →
   env fallback), calls the adapter, saves the image to disk, records
   latency. On error, stores the error message.
4. **Completion**: aggregates result statuses, updates session to `done`
   or `error`, fires webhook if configured.
5. **Retry** (`POST /api/results/:id`): re-runs a single cell with a
   fresh random seed — avoids re-paying for the entire session.

### Seed Logic

- **Sync seed ON** (default): all models get the same base seed.
  `seed = base + batchIndex`.
- **Sync seed OFF**: each cell gets an independent random seed.
- Retry always generates a fresh seed.

---

## Logging

Real-time synchronous file logging (`src/lib/logger.ts`).

- **`project.log`** — every event from every model.
- **`model-<id>.log`** — one file per model.

Each log line is appended synchronously (`fs.appendFileSync`) so logs are
available for live tailing immediately, not only after a run completes.
Lines are also mirrored to stdout so `docker logs` shows activity.

Format: `ISO-timestamp [LEVEL] [scope] message`

### Log API

| Endpoint | Description |
|----------|-------------|
| `GET /api/logs` | List available log files |
| `GET /api/logs/file?file=project.log&offset=0` | Incremental read (for live tail) |
| `GET /api/logs/file?file=project.log&download=1` | Full download |
| `GET /api/logs/export` | ZIP of all log files |

The Logs UI tab polls with offset for live tailing with pause/resume.

---

## REST API

All endpoints are under `/api/`. Responses are JSON unless noted.

### Sessions

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/sessions` | List sessions (newest first) |
| `POST` | `/api/sessions` | Create & start a generation |
| `GET` | `/api/sessions/:id` | Session detail with all results |
| `DELETE` | `/api/sessions/:id` | Delete session and its results |
| `GET` | `/api/sessions/:id/export` | Download ZIP (images + metadata.json) |

**POST /api/sessions** body:

```json
{
  "prompts": ["a neon city at night"],
  "modelIds": ["<model-adapter-id>", "..."],
  "config": {
    "seedSync": true,
    "batchSize": 2,
    "blindMode": false,
    "aspectRatio": "16:9",
    "seed": 42
  },
  "paramsMap": {
    "<model-id>": { "negative_prompt": "blurry", "ic_image_size": "2K" }
  },
  "webhookUrl": "https://example.com/hook"
}
```

### Models

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/models` | List all models (cached) |
| `POST` | `/api/models` | Add a new model |
| `PATCH` | `/api/models/:id` | Update model fields |
| `DELETE` | `/api/models/:id` | Delete model and its results |

### Results

| Method | Path | Description |
|--------|------|-------------|
| `PATCH` | `/api/results/:id` | Update rating / pinned status |
| `POST` | `/api/results/:id` | Retry with a new seed |

### Other

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/history` | Prompt history |
| `GET/POST` | `/api/presets` | List / create presets |
| `DELETE` | `/api/presets/:id` | Delete a preset |
| `GET/PUT` | `/api/settings` | Global settings (API key, webhook) |
| `GET` | `/api/analytics` | Per-model stats |
| `GET` | `/api/providers` | List registered adapter providers |
| `GET` | `/api/images/*` | Serve generated images |
| `GET` | `/api/logs` | List log files |
| `GET` | `/api/logs/file` | Read / download log file |
| `GET` | `/api/logs/export` | ZIP of all logs |

### Webhook

Set a webhook URL globally (Settings) or per-session. When all cells in
a session complete, a `POST` fires with:

```json
{
  "event": "session.completed",
  "sessionId": "...",
  "status": "done",
  "results": { "done": 5, "errored": 0, "total": 5 },
  "completedAt": "2024-01-01T00:00:00.000Z"
}
```

---

## Configuration & Environment

All config is via environment variables. Copy `.env.example` to `.env`.

| Variable | Required | Description |
|----------|----------|-------------|
| `DATABASE_URL` | Yes | SQLite path. Docker: `file:/data/imagegen.db`. Local: `file:./data/imagegen.db` |
| `DATA_DIR` | Yes | Where images and logs are stored. Docker: `/data`. Local: `./data` |
| `OPENROUTER_API_KEY` | No | Global fallback OpenRouter key |
| `OPENROUTER_APP_URL` | No | Sent to OpenRouter for attribution |
| `OPENROUTER_APP_TITLE` | No | Sent to OpenRouter for attribution |
| `OR_KEY_FLUX2_PRO` | No | Per-model key for FLUX.2 Pro |
| `OR_KEY_FLUX2_MAX` | No | Per-model key for FLUX.2 Max |
| `OR_KEY_SEEDREAM` | No | Per-model key for Seedream 4.5 |
| `OR_KEY_GROK_IMAGINE` | No | Per-model key for Grok Imagine |
| `OR_KEY_RECRAFT` | No | Per-model key for Recraft V4.1 Pro |

**API key resolution order:** per-model key (from model row) → stored
global key (from Settings table) → `OPENROUTER_API_KEY` env var.

Per-model keys from env vars are only written to the DB during seed
(container start). After that, change keys in the Settings UI.

---

## Pre-Seeded Models

The seed script (`prisma/seed.ts`) runs on every container start and
reconciles these five models:

| Name | OpenRouter Slug | Color | Env Key |
|------|----------------|-------|---------|
| FLUX.2 Pro | `black-forest-labs/flux.2-pro` | cyan | `OR_KEY_FLUX2_PRO` |
| FLUX.2 Max | `black-forest-labs/flux.2-max` | blue | `OR_KEY_FLUX2_MAX` |
| Seedream 4.5 | `bytedance-seed/seedream-4.5` | pink | `OR_KEY_SEEDREAM` |
| xAI: Grok Imagine | `x-ai/grok-imagine-image-quality` | amber | `OR_KEY_GROK_IMAGINE` |
| Recraft V4.1 Pro | `recraft/recraft-v4.1-pro` | rose | `OR_KEY_RECRAFT` |

Self-healing: if a slug was wrong from a previous version, the seed
corrects it in place. The seed also refreshes the parameter schema JSON
so existing databases gain new parameter fields automatically.

These are all **paid** models on OpenRouter. You need credits at
[openrouter.ai/settings/credits](https://openrouter.ai/settings/credits).
You can also add free models (e.g. Gemini with image output) through the
Settings UI.

---

## Version Scheme

Simple integer counter: `1.0` → `2.0` → `3.0` etc.

The version is defined in `src/lib/version.ts` and shown as a badge in
the header. Bump it on every deploy.

---

## Project Structure

```
├── prisma/
│   ├── schema.prisma          # Data model (6 tables)
│   └── seed.ts                # Self-reconciling model seed
├── src/
│   ├── app/
│   │   ├── api/               # 15 API route files
│   │   │   ├── sessions/      # CRUD + ZIP export
│   │   │   ├── models/        # CRUD
│   │   │   ├── results/       # Rate, pin, retry
│   │   │   ├── logs/          # List, tail, download, ZIP
│   │   │   ├── analytics/     # Per-model stats
│   │   │   ├── history/       # Prompt history
│   │   │   ├── presets/       # Preset CRUD
│   │   │   ├── settings/      # Global key/value
│   │   │   ├── providers/     # Adapter list
│   │   │   └── images/        # Serve generated images
│   │   ├── page.tsx           # Generate tab (home)
│   │   ├── results/page.tsx   # Results tab
│   │   ├── analytics/page.tsx # Analytics tab
│   │   ├── history/page.tsx   # History tab
│   │   ├── logs/page.tsx      # Logs tab
│   │   ├── settings/page.tsx  # Settings tab
│   │   ├── layout.tsx         # Root layout (fonts, theme)
│   │   ├── globals.css        # Tailwind + custom theme vars
│   │   └── icon.svg           # Favicon
│   ├── components/
│   │   ├── GenerateView.tsx   # Main generation UI
│   │   ├── Gallery.tsx        # Result grid with live polling
│   │   ├── ResultCard.tsx     # Image card (rate/pin/retry)
│   │   ├── Lightbox.tsx       # Full-screen image viewer
│   │   ├── SettingsView.tsx   # Model & preset management
│   │   ├── LogsView.tsx       # Live log viewer
│   │   ├── Header.tsx         # Navigation + version badge
│   │   ├── ParamFields.tsx    # Auto-rendered param panel
│   │   ├── Stars.tsx          # Star rating widget
│   │   └── icons.tsx          # SVG icon set
│   └── lib/
│       ├── adapters/
│       │   ├── types.ts       # ImageAdapter interface
│       │   ├── openrouter.ts  # OpenRouter adapter
│       │   └── registry.ts    # Provider registry
│       ├── generation.ts      # Session orchestrator + retry
│       ├── logger.ts          # Real-time file logging
│       ├── cache.ts           # Tag/TTL in-memory cache
│       ├── images.ts          # Image save/read/mime
│       ├── settings.ts        # Key resolution, get/set
│       ├── webhook.ts         # Fire-and-forget POST
│       ├── serialize.ts       # DB → client DTO mappers
│       ├── types.ts           # Client-facing interfaces
│       ├── config.ts          # DATA_DIR, path helpers
│       ├── db.ts              # Prisma singleton
│       └── version.ts         # App version constant
├── Dockerfile                 # Single-stage node:22-slim
├── docker-compose.yml         # One service, named volume
├── .env.example               # Template with all env vars
├── package.json
├── tailwind.config.ts
├── tsconfig.json
└── next.config.mjs
```

---

## Design Decisions

- **SQLite over Postgres**: zero config, single file, perfect for a
  local dev tool. No Docker dependency on a database container.
- **JSON-in-string over relations**: parameter schemas and session
  configs are stored as stringified JSON because SQLite (via Prisma)
  lacks native JSON columns. Simpler than extra join tables.
- **Sync file logging**: `fs.appendFileSync` on every event so logs are
  immediately available for tailing. The cost is negligible for a
  tool that makes a few API calls per session.
- **Adapter pattern with schema-driven UI**: adding a model is pure
  config — no React code needed. The `ParamFields` component reads the
  JSON schema and renders the appropriate inputs.
- **Self-reconciling seed**: runs on every container start, not just
  first boot. Fixes wrong model slugs and refreshes schemas without
  requiring a volume wipe.
- **Per-card retry**: re-runs one failed cell with a fresh seed instead
  of re-running (and re-paying for) the entire session.
- **Modality auto-detection**: tries `["image"]` first; on 404 falls
  back to `["image", "text"]` for multimodal models like Gemini.

---

## License

Private project. Not licensed for redistribution.
