// Tiny in-memory cache with TTL + tag-based invalidation.
// Per the spec: prompt history, model list and analytics are cached; the live
// generation is never cached.

type Entry = { value: unknown; expiresAt: number | null; tags: string[] };

const store = new Map<string, Entry>();

export function cacheGet<T>(key: string): T | undefined {
  const e = store.get(key);
  if (!e) return undefined;
  if (e.expiresAt !== null && Date.now() > e.expiresAt) {
    store.delete(key);
    return undefined;
  }
  return e.value as T;
}

export function cacheSet<T>(
  key: string,
  value: T,
  opts: { ttlMs?: number; tags?: string[] } = {},
): T {
  store.set(key, {
    value,
    expiresAt: opts.ttlMs ? Date.now() + opts.ttlMs : null,
    tags: opts.tags ?? [],
  });
  return value;
}

export async function cached<T>(
  key: string,
  loader: () => Promise<T>,
  opts: { ttlMs?: number; tags?: string[] } = {},
): Promise<T> {
  const hit = cacheGet<T>(key);
  if (hit !== undefined) return hit;
  const value = await loader();
  return cacheSet(key, value, opts);
}

// Invalidate everything tagged with any of the given tags.
export function invalidateTags(...tags: string[]) {
  for (const [key, entry] of store.entries()) {
    if (entry.tags.some((t) => tags.includes(t))) store.delete(key);
  }
}

export const CacheTags = {
  models: "models",
  history: "history",
  analytics: "analytics",
  presets: "presets",
} as const;
