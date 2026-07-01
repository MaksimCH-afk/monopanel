/**
 * Cloudflare Worker: Gone 410
 * Отдаёт код 410 Gone для указанных путей (или всего сайта, если список пуст).
 * Создано CloudPanel Security Manager.
 *
 * Настройка: впишите пути в GONE_PATHS. Пустой массив [] = весь сайт.
 */
const GONE_PATHS = {{PATHS_LIST}}; // напр. ["/old-page", "/removed/"] или [] для всего сайта

addEventListener("fetch", (event) => {
  event.respondWith(handleRequest(event.request));
});

async function handleRequest(request) {
  const path = new URL(request.url).pathname;
  const matchAll = GONE_PATHS.length === 0;
  const hit = matchAll || GONE_PATHS.some((p) => path === p || path.startsWith(p));
  if (hit) {
    return new Response("410 Gone", {
      status: 410,
      headers: { "content-type": "text/plain; charset=utf-8" },
    });
  }
  // Остальные пути отдаём как обычно (с origin-сервера)
  return fetch(request);
}
