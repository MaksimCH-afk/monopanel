/**
 * Cloudflare Worker: Not Found 404
 * Отдаёт код 404 Not Found для указанных путей (или всего сайта, если список пуст).
 * Создано CloudPanel Security Manager.
 *
 * Настройка: впишите пути в NOT_FOUND_PATHS. Пустой массив [] = весь сайт.
 */
const NOT_FOUND_PATHS = {{PATHS_LIST}}; // напр. ["/old-page", "/removed/"] или [] для всего сайта

addEventListener("fetch", (event) => {
  event.respondWith(handleRequest(event.request));
});

async function handleRequest(request) {
  const path = new URL(request.url).pathname;
  const matchAll = NOT_FOUND_PATHS.length === 0;
  const hit = matchAll || NOT_FOUND_PATHS.some((p) => path === p || path.startsWith(p));
  if (hit) {
    return new Response("404 Not Found", {
      status: 404,
      headers: { "content-type": "text/plain; charset=utf-8" },
    });
  }
  return fetch(request);
}
