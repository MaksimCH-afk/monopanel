# data/ — постоянные данные (НЕ в git)

Эта папка хранит данные, которые должны переживать пересборку образа.
Всё её содержимое (кроме этого README) закрыто в `.gitignore` —
в репозиторий попадают только структура и инструкции, не сами данные.

## Что сюда кладётся

```
data/
├── cf/
│   └── cloudflare_panel.db   ← БД cf (домены, токены Cloudflare) — СЕКРЕТ
├── ank/
│   └── app.db                ← БД ank (проекты, стратегии, профили)
├── img/                      ← SQLite + сгенерированные картинки
└── seo/
    ├── client_secret.json    ← Google OAuth (для данных GSC) — СЕКРЕТ, положить сюда
    ├── authorizedcreds.dat   ← сохранённый OAuth-токен (создаётся после входа)
    └── dashboard_config.json ← конфиг seo (создаётся приложением)
```

## seo — данные Google Search Console (переживают пересборку)

Чтобы seo показывал реальные данные GSC и не пришлось авторизовываться заново
после каждого `docker compose up --build`:

1. Создайте в Google Cloud Console OAuth-клиент (тип «Desktop app») с доступом к
   Search Console API и скачайте его `client_secret.json`.
2. Положите файл сюда: `data/seo/client_secret.json` (папка `data/` — в `.gitignore`).
3. Запустите панель, откройте **SEO Dashboard → Settings** и нажмите авторизацию
   (кнопка дергает `/api/authorize`). Токен сохранится в `data/seo/authorizedcreds.dat`
   и переживёт последующие пересборки — повторно логиниться не нужно.

Путь к креденшлам берётся из `SEO_DATA_DIR=/data/seo` (том), поэтому и секрет, и
токен лежат вне образа и вне git.

## Перенос данных (только cf и ank)

Забор из работающих контейнеров:

```bash
# если контейнер остановлен — шаг stop для него пропустить
docker stop cf-panel anchor-generator

docker cp cf-panel:/var/www/html/cloudflare_panel.db ./cloudflare_panel.db
docker cp anchor-generator:/data/app.db              ./ank-app.db

docker start cf-panel anchor-generator
```

Размещение здесь **до первого старта** новой сборки:

```bash
mkdir -p data/cf data/ank
cp cloudflare_panel.db data/cf/cloudflare_panel.db
cp ank-app.db          data/ank/app.db
```

При старте контейнер подхватит их: cf — через симлинк (`entrypoint.sh`),
ank — через переменную `ANK_DATA_DIR`.

> ⚠️ `cloudflare_panel.db` содержит API-токены Cloudflare. Не коммитьте его.
