#!/bin/bash
set -e

DB=/var/www/html/cloudflare_panel.db

# Чистый старт: если задан RESET_DB=1 — удаляем БД и креды перед запуском
if [ "${RESET_DB:-0}" = "1" ]; then
  rm -f "$DB" /var/www/html/credentials.txt
fi

# На чистой БД создаём тестового пользователя admin/admin
if [ ! -f "$DB" ]; then
  php /var/www/html/docker-seed.php || true
fi

# Фоновый обработчик очереди: периодически дёргает queue_processor.php.
# config.php помечает queue_processor.php как API-эндпоинт (без bot-protection),
# а auth_token авторизует запрос. UA не "curl/", чтобы не попасть под .htaccess-фильтр.
(
  sleep 5
  while true; do
    curl -s -A "QueueProcessor" \
      "http://127.0.0.1:1000/queue_processor.php?auth_token=cloudflare_queue_processor_2024" \
      >/dev/null 2>&1 || true
    sleep 20
  done
) &

# Фоновый мониторинг доменов (статус/NS/IP/SSL/WHOIS) + Telegram-алерты.
# Сам троттлит по last_monitor; здесь просто периодически дёргаем батч.
(
  sleep 25
  while true; do
    curl -s -A "QueueProcessor" --max-time 110 \
      "http://127.0.0.1:1000/monitor.php?auth_token=cloudflare_queue_processor_2024" \
      >/dev/null 2>&1 || true
    sleep 120
  done
) &

# Основной процесс — Apache на переднем плане
exec apache2-foreground
