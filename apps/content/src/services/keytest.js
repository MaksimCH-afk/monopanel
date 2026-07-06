// Lightweight "is this key valid?" probes for the UI "Проверить" button.
// Each makes the cheapest possible authenticated call and reports ok/among with
// the provider's error message. A short timeout keeps the endpoint responsive.

const TIMEOUT_MS = 12000;

async function fetchWithTimeout(url, opts) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
  try {
    return await fetch(url, { ...opts, signal: ctrl.signal });
  } finally {
    clearTimeout(t);
  }
}

async function errDetail(res) {
  try {
    return (await res.json())?.error?.message || '';
  } catch {
    return '';
  }
}

/** Validate a Google NL key with a minimal analyzeEntities call. */
export async function testGoogleKey(key) {
  try {
    const res = await fetchWithTimeout(
      `https://language.googleapis.com/v1/documents:analyzeEntities?key=${encodeURIComponent(key)}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          document: { type: 'PLAIN_TEXT', content: 'Google Cloud' },
          encodingType: 'UTF8',
        }),
      }
    );
    if (res.ok) return { ok: true, message: 'Ключ Google NL валиден.' };
    return { ok: false, message: `Google NL ${res.status}: ${(await errDetail(res)) || res.statusText}` };
  } catch (e) {
    return { ok: false, message: `Не удалось проверить ключ Google NL: ${e.message}` };
  }
}

/** Validate an OpenAI key with a cheap GET /v1/models. */
export async function testOpenAIKey(key) {
  try {
    const res = await fetchWithTimeout('https://api.openai.com/v1/models', {
      headers: { Authorization: `Bearer ${key}` },
    });
    if (res.ok) return { ok: true, message: 'Ключ OpenAI валиден.' };
    return { ok: false, message: `OpenAI ${res.status}: ${(await errDetail(res)) || res.statusText}` };
  } catch (e) {
    return { ok: false, message: `Не удалось проверить ключ OpenAI: ${e.message}` };
  }
}
