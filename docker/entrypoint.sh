#!/usr/bin/env bash
# ============================================================================
#  entrypoint.sh — подготовка данных перед стартом supervisord.
#  Запускается один раз при старте контейнера.
# ============================================================================
set -euo pipefail

echo "[entrypoint] Подготовка данных…"

# --- Каталоги постоянных данных (создаём, если тома пустые) ---
mkdir -p /data/cf /data/ank /data/img

# --- cf: симлинк cloudflare_panel.db из /data/cf в корень кода ---
# Так БД (с токенами Cloudflare) живёт в томе, а код её видит в /var/www/html.
CF_DB="/data/cf/cloudflare_panel.db"
if [ ! -f "$CF_DB" ]; then
    echo "[entrypoint] cf: $CF_DB не найдена — старт с чистой БД (создастся приложением)."
fi
ln -sf "$CF_DB" /var/www/html/cloudflare_panel.db

# --- ank: БД подхватывается через DATA_DIR ---
# uvicorn-приложение читает ${ANK_DATA_DIR}/app.db (см. .env).
if [ ! -f "/data/ank/app.db" ]; then
    echo "[entrypoint] ank: /data/ank/app.db не найдена — старт с чистого листа."
fi

# --- Права на каталоги, в которые пишут приложения ---
chown -R www-data:www-data /var/www/html 2>/dev/null || true

echo "[entrypoint] Готово. Запускаю процессы через supervisord."

# Передаём управление CMD (supervisord), сохраняя сигналы.
exec "$@"
