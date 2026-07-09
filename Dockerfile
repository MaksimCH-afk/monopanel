# ============================================================================
#  Единая панель — единый образ для 8 РЕАЛЬНЫХ приложений + дашборд.
#  База: php:8.1-apache (debian bookworm → python 3.11). Поверх: Node 22,
#  Python venv на каждое py-приложение, tesseract, Caddy, supervisor.
#
#  Приложения втянуты в apps/* как есть (см. каждый apps/<app>/README.md).
#  ⚠️  Первая сборка тяжёлая и долгая (3 рантайма + 2 Next-сборки + Prisma).
#      Возможны точечные правки версий под конкретную машину — это ожидаемо.
# ============================================================================

FROM php:8.1-apache

ENV DEBIAN_FRONTEND=noninteractive \
    PIP_NO_CACHE_DIR=1 \
    NODE_VERSION=22 \
    NEXT_TELEMETRY_DISABLED=1

# ----------------------------------------------------------------------------
# 1. Системные пакеты
#    - python3 + venv/dev + build-essential  (ank/arc/seo/skins)
#    - tesseract-ocr                         (skins: OCR логотипа; img: OCR)
#    - lib*-dev                              (PHP-расширения cf: intl/curl/mbstring/sqlite)
#    - openssl                               (Prisma в img)
# ----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg git unzip supervisor \
        python3 python3-venv python3-pip python3-dev build-essential \
        tesseract-ocr libtesseract-dev \
        sqlite3 libsqlite3-dev libcurl4-openssl-dev libonig-dev libicu-dev \
        openssl \
    && rm -rf /var/lib/apt/lists/*

# Node 22 (сборка Next.js: seo и img)
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Caddy (статический дашборд на :333)
RUN curl -fsSL "https://caddyserver.com/api/download?os=linux&arch=amd64" -o /usr/local/bin/caddy \
    && chmod +x /usr/local/bin/caddy

# ----------------------------------------------------------------------------
# 2. PHP-расширения для cf (Cloudflare Panel)
#    pdo_sqlite (БД), curl (API CF), mbstring, intl (idn_to_ascii)
# ----------------------------------------------------------------------------
RUN docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite curl mbstring intl \
    && a2enmod rewrite headers

# Apache (cf) слушает 3331 вместо исходного 1000; .htaccess разрешён (AllowOverride All)
RUN sed -ri 's/^Listen 80$/Listen 3331/' /etc/apache2/ports.conf \
    && sed -ri 's!:80>!:3331>!' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/app-override.conf \
    && a2enconf app-override

# ----------------------------------------------------------------------------
# 3. Исходники
# ----------------------------------------------------------------------------
WORKDIR /srv/panel
COPY . /srv/panel

# --- cf: PHP в DocumentRoot Apache (БД cloudflare_panel.db — через симлинк на /data/cf) ---
RUN rm -rf /var/www/html && cp -a /srv/panel/apps/cf /var/www/html \
    && chown -R www-data:www-data /var/www/html
# cf: снять X-Frame-Options/CSP, чтобы плитка-iframe дашборда могла встроить cf
COPY docker/cf-frame.conf /etc/apache2/conf-available/cf-frame.conf
RUN a2enconf cf-frame

# ----------------------------------------------------------------------------
# 4. Python venv на КАЖДОЕ приложение (конфликт версий Flask: seo 2.3 / skins 3.1)
# ----------------------------------------------------------------------------
# ank — FastAPI (requirements.txt)
RUN python3 -m venv /venv/ank \
    && /venv/ank/bin/pip install --upgrade pip \
    && /venv/ank/bin/pip install -r /srv/panel/apps/ank/requirements.txt

# arc — webarhive, устанавливается как пакет (pyproject); тянет templates/static как package-data
RUN python3 -m venv /venv/arc \
    && /venv/arc/bin/pip install --upgrade pip \
    && cd /srv/panel/apps/arc && /venv/arc/bin/pip install .

# seo — Flask-бэкенд (backend_requirements.txt: flask 2.3.3, google-api-*, pandas, openai)
RUN python3 -m venv /venv/seo \
    && /venv/seo/bin/pip install --upgrade pip \
    && /venv/seo/bin/pip install -r /srv/panel/apps/seo/backend_requirements.txt

# skins — Flask 3.1 + gunicorn + Pillow/numpy/scikit-learn/pytesseract
RUN python3 -m venv /venv/skins \
    && /venv/skins/bin/pip install --upgrade pip \
    && /venv/skins/bin/pip install -r /srv/panel/apps/skins/requirements.txt

# ----------------------------------------------------------------------------
# 5. Next.js сборки (devDeps нужны для сборки — НЕ ставим NODE_ENV=production здесь)
# ----------------------------------------------------------------------------
# img — Next + Prisma. postinstall запускает `prisma generate` (нужен prisma/schema.prisma).
ENV DATABASE_URL=file:/data/img/imagegen.db
RUN cd /srv/panel/apps/img \
    && npm install \
    && npx prisma generate \
    && npm run build

# seo — Next 15. Конфликт peer-deps (@tremor/react хочет React 18, проект на 19) →
# ставим с --legacy-peer-deps. Если install/build упадёт — не валим весь образ
# (|| WARN), seo просто не будет предсобран (см. README, раздел seo).
# Адрес seo-бэкенда для БРАУЗЕРА зашивается в бандл на этапе сборки (NEXT_PUBLIC_*),
# поэтому пробрасываем его как build-arg. По умолчанию localhost:5001 (как раньше);
# для деплоя фронта/бэкенда на другом хосте задайте через build.args (см. compose).
ARG NEXT_PUBLIC_SEO_API_URL=http://localhost:5001
ENV NEXT_PUBLIC_SEO_API_URL=$NEXT_PUBLIC_SEO_API_URL
RUN cd /srv/panel/apps/seo \
    && (npm install --legacy-peer-deps && npm run build \
        || echo "WARN: сборка seo не прошла — поправьте apps/seo (вероятно окружение/peer-deps)")

# content — Node/Express (единственная зависимость express; HTTP через native fetch).
# Без ключей работает в mock-режиме; статику отдаёт сам сервер. Только прод-зависимости.
RUN cd /srv/panel/apps/content && npm install --omit=dev

# mail — Node/Express (единственная зависимость express; к Cloudflare D1 через native fetch).
# Без кредов Cloudflare работает в mock-режиме; статику отдаёт сам сервер. Только прод-зависимости.
# (Папка worker/ — деплой-артефакты в Cloudflare, в образе не запускается.)
RUN cd /srv/panel/apps/mail && npm install --omit=dev

# ----------------------------------------------------------------------------
# 6. Caddy-конфиг дашборда, supervisor, entrypoint
# ----------------------------------------------------------------------------
COPY dashboard/Caddyfile /etc/caddy/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/conf.d/panel.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Порты: 333 дашборд + 3331..3338 приложения + 5001 (seo-flask, нужен браузеру)
EXPOSE 333 3331 3332 3333 3334 3335 3336 3337 3338 5001

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
