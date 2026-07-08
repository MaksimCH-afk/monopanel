# mail — Приём почты на множестве адресов через Cloudflare

Принимает письма вида `что-угодно@mydomain.com`, складывает их в базу и даёт
читать из админки. Работает на **Cloudflare Email Routing + Email Worker + D1**,
без почтовых серверов и без заведения ящиков.

В монопанели это приложение **mail** — открывается на своём порту **:3338**
(плитка «Почта»), как и остальные софты.

```
Входящее письмо на *@mydomain.com
        │  Cloudflare Email Routing (catch-all)
        ▼
   Email Worker  ──parse──►  запись в D1 (SQLite)
                                   │
                                   ▼
   Админка (:3338)  ── D1 REST API ──►  читает письма по колонке mailbox
```

«Ящик №37» — это просто выборка из базы по `mailbox = 'acc37@mydomain.com'`.
Новый адрес заводить не нужно: начал слать на него — он сразу работает (catch-all).

---

## Две части

| Часть | Где живёт | Что делает |
|-------|-----------|------------|
| **Админка** | этот контейнер, `src/` + `public/`, порт **:3338** | читает письма из D1 по REST API, показывает ящики/письма |
| **Worker + схема** | Cloudflare (деплой из `worker/`) | принимает письмо, парсит, пишет строку в D1 |
| **Автоматизация** | `scripts/setup-domains.mjs` | включает routing + MX/SPF + catch-all→worker на много доменов |

Админка **самодостаточна**: без Cloudflare-кредов она поднимается в **mock-режиме**
с демо-письмами, так что UI можно посмотреть сразу.

---

## Админка (эта папка)

Node + Express, единственная зависимость — `express` (HTTP к Cloudflare через
нативный `fetch`).

```bash
npm install
npm start          # → http://localhost:3338  (MOCK, если не заданы креды)
npm test           # node --test
```

### Настройка подключения к D1

Три значения — **Account ID**, **D1 Database ID**, **API Token** — задаются либо
через `.env` (см. `.env.example`), либо прямо в UI (кнопка **⚙ Настройки**).
Значение из UI переопределяет env и сохраняется в `MAIL_DATA_DIR/config.json`
(в Docker — том `/data/mail`, переживает пересборку). Токен хранится только на
сервере; клиенту уходит лишь маскированный статус.

Токен нужен с правами **D1 Read** (и **Edit**, если удалять/чистить письма из
админки).

### API

| Метод | Путь | Назначение |
|-------|------|-----------|
| GET | `/api/health` | режим (mock/live) + маскированный статус кредов |
| POST | `/api/config` | сохранить/очистить креды Cloudflare |
| POST | `/api/test` | проверить соединение с D1 |
| GET | `/api/mailboxes` | список ящиков + счётчики |
| GET | `/api/messages?mailbox=..&limit=..&search=..` | письма ящика (заголовки) |
| GET | `/api/messages/:id` | одно письмо целиком |
| DELETE | `/api/messages/:id` | удалить письмо (live-режим) |
| POST | `/api/cleanup` | удалить старые письма (`{days}` или `{before}`) |

---

## Worker + D1 (папка `worker/`, деплой в Cloudflare)

```bash
cd worker
npm install                      # ставит postal-mime + wrangler
cp wrangler.toml.example wrangler.toml

npx wrangler d1 create mail      # запомни database_id → впиши в wrangler.toml
npx wrangler d1 execute mail --remote --file=./schema.sql
npx wrangler deploy
```

Затем в Cloudflare: **Email → Email Routing** → включить, подтвердить MX/TXT,
во вкладке **Routing rules** включить **Catch-all** с действием
**Send to a Worker → mail-catcher**. (Либо всё это скриптом — см. ниже.)

Опционально: temp-mail-очистка по Cron — задай `MAIL_RETENTION_DAYS` и `crons`
в `wrangler.toml` (Worker сам удалит старые письма, см. `scheduled()`).

---

## Автоматизация на много доменов (`scripts/setup-domains.mjs`)

Через Cloudflare REST API для списка доменов: включить Email Routing, проставить
MX + SPF (TXT), повесить catch-all на Worker.

```bash
CF_API_TOKEN=xxxx node scripts/setup-domains.mjs example.com other.com
CF_API_TOKEN=xxxx node scripts/setup-domains.mjs --file domains.txt --worker mail-catcher
```

Токен нужен с правами **Zone → Email Routing (Edit)** и **Zone → DNS (Edit)**.
Скрипт идемпотентен — повторный запуск безопасен.

---

## Подводные камни (из ТЗ §6)

- **Индивидуальных routing-правил** ~200 на домен — поэтому только catch-all, не по одному адресу.
- **До 30 доменов в одной зоне** для Email Routing/Sending суммарно; отдельный домен = своя зона.
- **Аутентификация входящих:** Cloudflare требует прохождения хотя бы SPF или валидной DKIM —
  письма, проваливающие обе проверки, отклоняются.
- **Локальная часть — только ASCII** (латиница/цифры/дефисы до `@`).
- **Non-delivery report не пересылается** исходному отправителю.
- **`mailbox` нормализуется в нижний регистр** (в Worker уже есть `.toLowerCase()`).
- **Письма копятся в D1 навсегда**, пока не чистишь — для temp-mail включи Cron-очистку.

### Тарифы

Email Routing — бесплатно; Workers — free ~100k запросов/день; D1 — free-тариф
такой объём покрывает.
