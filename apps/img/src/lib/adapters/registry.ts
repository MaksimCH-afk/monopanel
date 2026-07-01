import { ImageAdapter, ParamSpec } from "./types";
import { openRouterAdapter } from "./openrouter";

// Adding a new provider driver = registering it here. Everything else
// (UI panels, generation, storage) is driven by config + the adapter contract.
const ADAPTERS: Record<string, ImageAdapter> = {
  openrouter: openRouterAdapter,
};

export function getAdapter(provider: string): ImageAdapter {
  const a = ADAPTERS[provider];
  if (!a) throw new Error(`Unknown adapter provider: ${provider}`);
  return a;
}

export function listProviders(): string[] {
  return Object.keys(ADAPTERS);
}

export function defaultSchemaFor(provider: string): ParamSpec[] {
  const a = ADAPTERS[provider];
  return a ? a.defaultParamsSchema() : [];
}
