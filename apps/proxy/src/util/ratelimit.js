// Minimal in-memory rate limiter keyed by an arbitrary string (TZ §6: protect
// /api/proxy/test from being hammered — default 1 request/sec per profile).

const last = new Map();

/**
 * @returns {true} if the call is allowed (and records the timestamp), or the
 *          number of ms left to wait if it is too soon.
 */
export function allow(key, minIntervalMs = 1000) {
  const now = Date.now();
  const prev = last.get(key) || 0;
  const wait = minIntervalMs - (now - prev);
  if (wait > 0) return wait;
  last.set(key, now);
  return true;
}
