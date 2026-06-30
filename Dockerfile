# ============================================================================
#  Единая панель — единый образ для 6 приложений + дашборд
#  База: php:8.1-apache (для cf). Поверх: Node 22, Python 3, Caddy, supervisor.
#
#  ⚠️  Это первый рабочий каркас. Большой мульти-рантайм образ обычно требует
#      1–2 точечных правок под конкретную машину (версии npm/pip пакетов).
#      Падение на шаге сборки — ожидаемо и чинится здесь же.
# ============================================================================

FROM php:8.1-apache

ENV DEBIAN_FRONTEND=noninteractive \
    PIP_NO_CACHE_DIR=1 \
    NODE_VERSION=22

# ----------------------------------------------------------------------------
# 1. Системные пакеты: Python, tesseract (OCR для img), утилиты, Caddy, Node 22
# ----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg git unzip supervisor \
        python3 python3-venv python3-pip python3-dev build-essential \
        tesseract-ocr libtesseract-dev \
        sqlite3 libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Node 22 (нужен для сборки Next.js приложений: seo и img)
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g npm@latest

# Caddy (отдаёт статический дашборд на :333)
RUN curl -fsSL https://github.com/caddyserver/caddy/releases/latest/download/caddy_linux_amd64 \
        -o /usr/local/bin/caddy 2>/dev/null \
    && chmod +x /usr/local/bin/caddy || \
    (echo "Caddy: fallback на apt-репозиторий" && \
     apt-get update && \
     apt-get install -y debian-keyring debian-archive-keyring apt-transport-https && \
     curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg && \
     curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list && \
     apt-get update && apt-get install -y caddy && \
     rm -rf /var/lib/apt/lists/*)

# ----------------------------------------------------------------------------
# 2. PHP-расширения для cf (Cloudflare Panel: SQLite + Apache rewrite)
# ----------------------------------------------------------------------------
RUN docker-php-ext-install pdo pdo_sqlite \
    && a2enmod rewrite headers

# Apache (cf) слушает 3331 вместо исходного 1000
RUN sed -ri 's/^Listen 80$/Listen 3331/' /etc/apache2/ports.conf \
    && sed -ri 's!:80>!:3331>!' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html!' /etc/apache2/sites-available/000-default.conf

# ----------------------------------------------------------------------------
# 3. Раскладка приложений
# ----------------------------------------------------------------------------
WORKDIR /srv/panel
COPY . /srv/panel

# --- cf: PHP в DocumentRoot Apache; БД через симлинк на /data/cf ---
RUN rm -rf /var/www/html && cp -a /srv/panel/apps/cf /var/www/html

# ----------------------------------------------------------------------------
# 4. Python venv на КАЖДОЕ приложение (конфликт версий Flask: seo 2.3 / skins 3.1)
# ----------------------------------------------------------------------------
RUN for app in ank arc seo skins; do \
        python3 -m venv /venv/$app; \
        if [ -f /srv/panel/apps/$app/requirements.txt ]; then \
            /venv/$app/bin/pip install --upgrade pip && \
            /venv/$app/bin/pip install -r /srv/panel/apps/$app/requirements.txt; \
        fi; \
    done

# ----------------------------------------------------------------------------
# 5. Next.js сборки (seo и img) — выполняются если есть package.json
# ----------------------------------------------------------------------------
RUN for app in seo img; do \
        if [ -f /srv/panel/apps/$app/package.json ]; then \
            cd /srv/panel/apps/$app && npm ci && npm run build || \
            echo "WARN: сборка $app не прошла — поправьте apps/$app"; \
        fi; \
    done

# ----------------------------------------------------------------------------
# 6. Caddy-конфиг дашборда и supervisor
# ----------------------------------------------------------------------------
COPY dashboard/Caddyfile /etc/caddy/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/conf.d/panel.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Порты: 333 дашборд + 3331..3336 приложения (5001 seo-flask — внутренний)
EXPOSE 333 3331 3332 3333 3334 3335 3336

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
