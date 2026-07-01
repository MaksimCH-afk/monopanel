// Fire-and-forget POST to a session webhook when all generations complete.
export async function fireWebhook(url: string, payload: unknown): Promise<void> {
  try {
    await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(10_000),
    });
  } catch {
    // Webhooks are best-effort; failures must not break the session.
  }
}
