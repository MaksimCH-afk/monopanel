# DOCKER — запуск шаблона в контейнере

Шаблон — статический сайт, но в корне лежит рабочий `.htaccess` (extensionless-URL
и редиректы). Поэтому образ сайта собран на **Apache** — `.htaccess` применяется
как на проде. Отдельный образ (`Dockerfile.tools`) даёт Python-окружение для
скрипта авто-определения цветов.

## Быстрый старт (сайт)

```bash
# через docker compose (рекомендуется)
docker compose up --build          # → http://localhost:8080

# либо «голым» docker
docker build -t brandskins .
docker run --rm -p 8080:80 brandskins
```

Открыть в браузере: **http://localhost:8080**

Порт меняется в `docker-compose.yml` (`"8080:80"`) или флагом `-p ВАШ_ПОРТ:80`.

## Сменить бренд без пересборки

Тема живёт в одном файле `css/brand.css`. Можно подменить его томом на лету —
например, применить пример Fraga:

```bash
docker run --rm -p 8080:80 \
  -v "$(pwd)/css/brand.fraga.css:/usr/local/apache2/htdocs/css/brand.css:ro" \
  brandskins
```

Или просто скопируйте нужный файл в `css/brand.css` и пересоберите образ.

## Прогнать экстрактор цветов в контейнере

Образ `tools` содержит Pillow/numpy/scikit-learn — локальный Python не нужен.
Положите референс-скриншоты в папку `refs/` (она монтируется вместе с проектом):

```bash
# через compose (профиль tools)
docker compose --profile tools run --rm tools \
  python tools/extract_brand.py refs/*.png \
    --name MyBrand \
    -o css/brand.mybrand.css \
    --report out/report.md \
    --swatch out/palette.svg \
    --enforce-contrast

# либо «голым» docker
docker build -f Dockerfile.tools -t brandskins-tools .
docker run --rm -v "$(pwd):/work" -w /work brandskins-tools \
  python tools/extract_brand.py refs/*.png --name MyBrand \
  -o css/brand.mybrand.css --report out/report.md --enforce-contrast
```

Результат (`brand.mybrand.css`, отчёт, превью) появится прямо в проекте, т.к. папка
смонтирована как том. Дальше подключите его как описано выше или скопируйте в
`css/brand.css`.

## Заметки

* Внутренние ссылки используют каталоги с завершающим слешем (`bonuses/`, …) и
  корневые пути (`/assets/img/flags/…`) — поэтому сайт раздаётся из корня веб-сервера.
* Образ сайта не включает `tools/`, `out/`, `*.md`, Docker-файлы (см. `.dockerignore`) —
  в контейнер попадает только то, что нужно для отдачи сайта.
* Нужен nginx вместо Apache? Тогда `.htaccess` не действует: либо смиритесь с тем,
  что 301-редиректы на extensionless-URL не отработают (навигация по сайту всё равно
  работает — каталоги отдают `index.html`), либо перенесите правила в `nginx.conf`.
