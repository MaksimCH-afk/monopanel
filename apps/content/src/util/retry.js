// Exponential backoff for external API calls (TZ §9: retries on 429/5xx).
// An error is considered retryable if it carries a `status` in {429, 5xx} or is
// a transient network error (no status).

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function isRetryable(err) {
  const s = err?.status;
  if (s === undefined || s === null) return true; // network/timeout
  return s === 429 || (s >= 500 && s <= 599);
}

export async function withRetry(fn, { maxAttempts, baseMs, label = 'call' } = {}) {
  let lastErr;
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastErr = err;
      if (attempt >= maxAttempts || !isRetryable(err)) break;
      // full-jitter exponential backoff
      const backoff = baseMs * 2 ** (attempt - 1);
      const delay = Math.round(Math.random() * backoff);
      console.warn(
        `[retry] ${label} attempt ${attempt}/${maxAttempts} failed (status=${err?.status ?? 'n/a'}); retrying in ${delay}ms`
      );
      await sleep(delay);
    }
  }
  throw lastErr;
}
