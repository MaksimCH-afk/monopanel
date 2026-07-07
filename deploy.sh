#!/usr/bin/env bash
# ============================================================================
#  Обновление панели одной командой.
#  Подтягивает main → пересобирает образ панели → перезапускает контейнер.
#
#  Запуск из корня репозитория:
#      ./deploy.sh
#
#  Собирается ВСЯ панель (7 приложений в одном образе monopanel:latest).
#  webarhive (arc) внутри неё отдаётся на :3335 — отдельный контейнер
#  `webarhive` из apps/arc/ здесь НЕ используется.
#
#  --no-cache не нужен: `COPY . /srv/panel` в Dockerfile идёт до установки
#  приложений, поэтому изменённые исходники после git pull подхватываются
#  сами, а тяжёлые системные слои (apt/node/venv) переиспользуются.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")"

echo "→ git pull origin main"
git pull origin main

echo "→ docker compose build panel"
docker compose build panel

echo "→ docker compose up -d"
docker compose up -d

echo
echo "✓ Готово. Проверь версию arc в шапке: http://localhost:3335"
echo "  (после этих правок должна быть v4.1)"
