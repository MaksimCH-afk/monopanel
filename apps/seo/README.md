# seo — GSC / SEO Dashboard

| | |
|---|---|
| **Назначение** | Google Search Console / SEO-метрики |
| **Стек** | Next.js (фронт) + Flask (бэкенд) |
| **Порт** | **3332** (Flask 5001 — внутренний, наружу не публикуется) |
| **Данные** | json/файлы |
| **Авторизация** | Auth0 |

## Куда положить код

- Next.js фронт (`package.json`, `pages/` или `app/`, и т.д.) — в `apps/seo/`.
- Flask-бэкенд — туда же; запускается из того же каталога во venv `/venv/seo`.
- `requirements.txt` для Flask-бэкенда — в корень `apps/seo/`.

## Запуск (см. supervisord.conf)

- `seo`       — `npm run start -- -p 3332` (Next.js, production)
- `seo-flask` — `/venv/seo/bin/python -m flask run --port=5001` (внутренний)

## Auth0

Для входа нужны ключи в `.env` (`AUTH0_DOMAIN`, `AUTH0_CLIENT_ID`, …).
Без них фронт поднимется, но вход не сработает.

## Примечание

В исходном архиве seo **не был контейнеризован** (нет Dockerfile) — здесь он
завёрнут в общий образ. Flask 2.3 у seo конфликтует с Flask 3.1 у skins,
поэтому у каждого свой venv.
