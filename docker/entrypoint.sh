#!/usr/bin/env bash
# ============================================================================
#  entrypoint.sh — подготовка данных перед стартом supervisord.
# ============================================================================
set -euo pipefail

echo "[entrypoint] Подготовка каталогов данных…"
mkdir -p /data/cf /data/ank /data/arc /data/img /data/seo
chown -R www-data:www-data /data/cf 2>/dev/null || true

# --- Версия панели: авто-инкремент счётчика перезаливов (1, 2, 3, …) ---
# Счётчик лежит в томе /data/cf, поэтому переживает пересборку образа.
# Каждый старт контейнера (docker compose up) прибавляет 1 → в UI видно «N.0».
PV_FILE=/data/cf/panel_build
pv=$(tr -dc '0-9' < "$PV_FILE" 2>/dev/null || echo '')
[ -z "$pv" ] && pv=0
pv=$((pv + 1))
echo "$pv" > "$PV_FILE"
chown www-data:www-data "$PV_FILE" 2>/dev/null || true
echo "[entrypoint] Версия панели: ${pv}.0"

# --- cf: БД (с токенами Cloudflare) живёт в томе /data/cf; код видит её в корне ---
CF_DB="/data/cf/cloudflare_panel.db"
ln -sf "$CF_DB" /var/www/html/cloudflare_panel.db
# credentials.txt (логин/пароль админа) тоже в том, чтобы переживал пересборку
touch /data/cf/credentials.txt 2>/dev/null || true
ln -sf /data/cf/credentials.txt /var/www/html/credentials.txt
if [ ! -f "$CF_DB" ]; then
    echo "[entrypoint] cf: БД не найдена — создаю чистую (admin/admin) через docker-seed.php"
    php /var/www/html/docker-seed.php || echo "[entrypoint] cf: docker-seed.php не отработал (создастся приложением при первом запросе)"
fi
chown -R www-data:www-data /data/cf /var/www/html 2>/dev/null || true

# --- ank / arc / img: чистый старт, если данных нет ---
[ -f /data/ank/app.db ]       || echo "[entrypoint] ank: старт с чистой БД (/data/ank/app.db)."
[ -f /data/arc/webarhive.db ] || echo "[entrypoint] arc: старт с чистой БД (/data/arc/webarhive.db)."
[ -f /data/img/imagegen.db ]  || echo "[entrypoint] img: старт с чистой БД (/data/img/imagegen.db)."

echo "[entrypoint] Готово. Запускаю процессы через supervisord."
exec "$@"
