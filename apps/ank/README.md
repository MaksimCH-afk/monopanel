# ank — Генератор анкоров (HubNero)

| | |
|---|---|
| **Назначение** | Генерация анкоров: проекты, стратегии, профили |
| **Стек** | FastAPI + uvicorn |
| **Порт** | **3333** (исходный был 9999) |
| **БД** | SQLite `app.db` |
| **Авторизация** | нет |

## Куда положить код

Исходники FastAPI-приложения — в `apps/ank/`. Точка входа ожидается как
`main:app` (модуль `main.py`, объект `app`). `requirements.txt` — в корень папки;
зависимости ставятся во venv `/venv/ank`.

## Данные

БД читается из `ANK_DATA_DIR/app.db` (по умолчанию `/data/ank/app.db`).
Положите её локально в `data/ank/app.db` (см. корневой README).

## Запуск (см. supervisord.conf)

`/venv/ank/bin/uvicorn main:app --host 0.0.0.0 --port 3333`
