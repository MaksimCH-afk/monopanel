# Прокси-менеджер (PacketStream) — `:3339`

Внутренний модуль единой панели. **Control-plane**: собирает, хранит и раздаёт
прокси-конфиги PacketStream вашим софтам. Трафик через панель **не идёт** —
софты ходят в `proxy.packetstream.io` напрямую (ТЗ §1). Бэкенд сам обращается к
сети только в двух случаях: тест exit-IP профиля и Reseller API.

Стек: Node/Express (как `content` и `mail`), ESM, хранение — JSON-файлы в томе
данных. Без ключей поднимается, строки показывает как шаблон, тест возвращает
409.

## Запуск

```bash
cd apps/proxy
npm install
cp .env.example .env      # заполните PS_USERNAME / PS_AUTH_KEY (или введите в UI)
npm start                 # http://localhost:3339
npm test                  # юнит-тесты (node --test)
```

В составе панели запускается через supervisor (`docker compose up --build`),
данные — в томе `/data/proxy` (`PROXY_DATA_DIR`).

## Факты PacketStream (ТЗ §2)

| Протокол | Порт | Схема |
|---|---|---|
| HTTP | `31112` | `http` |
| HTTPS | `31111` | `https` |
| SOCKS5 | `31113` | `socks5h` |

Пароль: `auth_key` + `_country-XX` + `_session-XXXXXXXX` (строго в этом порядке,
`session` — 8 alnum, генерит сервер один раз на sticky-профиль).

## API (ТЗ §5)

| Метод | Путь | Назначение |
|---|---|---|
| `GET` | `/api/health` | статус, число профилей |
| `GET/POST` | `/api/proxy/settings` | аккаунт (секреты in, маскированный статус out) |
| `GET` | `/api/proxy/profiles` | список профилей (со `strings`) |
| `POST` | `/api/proxy/profiles` | создать (session генерит сервер) |
| `PATCH` | `/api/proxy/profiles/:id` | правка, `app_id`, `regenerate_session` |
| `DELETE` | `/api/proxy/profiles/:id` | удалить |
| `GET` | `/api/proxy/profiles/:id/string?format=url\|list\|env\|curl` | одна строка |
| `GET` | `/api/apps/:appId/proxy?format=url` | раздача софту назначенного прокси |
| `GET` | `/api/proxy/test?id=:id` | exit-IP через прокси (502 на битом, не 500) |
| `GET` | `/api/reseller/balance` | баланс (409 если токен не задан) |
| `POST` | `/api/reseller/subusers` | сабюзер (409 если токен не задан) |

`app_id` (ТЗ §4): `cf seo anc img arc skin gap mail`.

## Безопасность (ТЗ §6)

- `PS_AUTH_KEY` и `RESELLER_TOKEN` — только на сервере, шифр/маска в UI, не в
  клиентском коде и логах. Ключ неизбежно вшит в саму строку подключения (это
  часть credential) — это единственное место, где он появляется.
- `/api/proxy/test` — rate-limit 1 запрос/сек на профиль.

## Reseller API (ТЗ §7, этап 2)

Гейтед: доступ по заявке, авторизация Bearer-токеном. Наш бэкенд — тонкий
прокси: складывает токен в `Authorization: Bearer …`, маппит ответ в наши
`/api/reseller/*`. **Точные пути берутся из выданной Postman-коллекции** и
задаются конфигом (`RESELLER_*` в `.env`), не изобретаются. Пока доступа нет —
`RESELLER_MOCK=true` отдаёт демо-данные, иначе `409`.
