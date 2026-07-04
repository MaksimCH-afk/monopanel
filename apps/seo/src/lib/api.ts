// [monopanel] Базовый адрес Flask-бэкенда seo.
// Запросы уходят ИЗ БРАУЗЕРА, поэтому переменная должна быть NEXT_PUBLIC_*
// (инлайнится в бандл на этапе сборки). Если не задана — как раньше,
// localhost:5001 (локальный запуск на той же машине).
export const API_BASE =
  process.env.NEXT_PUBLIC_SEO_API_URL?.replace(/\/$/, "") ||
  "http://localhost:5001";
