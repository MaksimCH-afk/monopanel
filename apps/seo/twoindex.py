"""
Адаптер 2index Ninja (API v1) — отправка URL на индексацию.

Реальный контракт (https://2index.ninja/api-documentation):
- база: https://2index.ninja/api/v1/
- авторизация: заголовок Authorization: Bearer <API_TOKEN> (НЕ параметр key!);
- при 403 от хостинга помогает user-agent — шлём браузерный UA;
- у каждого ответа есть поле success (bool) и errors (сообщения об ошибках);
- модель проектная: GET /account, GET/POST /project, GET /geo-targets;
- отправка ссылок: POST /link/add_simple (links + хотя бы одна ПС google/yandex/bing).
"""

import logging
import os

import requests as http_requests

log = logging.getLogger('seo.twoindex')

TWOINDEX_BASE = os.environ.get('TWOINDEX_BASE', 'https://2index.ninja/api/v1').rstrip('/')
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36")


def _headers(token):
    return {"Authorization": f"Bearer {token}", "User-Agent": UA, "Accept": "application/json"}


def _errors_text(data):
    """Достать текст ошибки из ответа 2index (поле errors/ message)."""
    if isinstance(data, dict):
        errs = data.get("errors") or data.get("error") or data.get("message")
        if errs:
            return errs if isinstance(errs, str) else str(errs)
        if "body" in data:
            return str(data["body"])[:200]
    return str(data)[:200]


def get_account(token, timeout=20):
    """
    Данные аккаунта 2index (баланс, доступные ссылки/лимиты). GET /account.
    Возвращает {ok, account, balance, available_links, available_check, error, raw}.
    """
    if not token:
        return {"ok": False, "error": "2index не настроен (нет токена)"}
    try:
        resp = http_requests.get(f"{TWOINDEX_BASE}/account", headers=_headers(token), timeout=timeout)
        try:
            data = resp.json()
        except Exception:  # noqa: BLE001
            data = {"body": resp.text[:300]}
        if resp.status_code != 200 or not (isinstance(data, dict) and data.get("success")):
            return {"ok": False, "error": f"HTTP {resp.status_code}: {_errors_text(data)}", "raw": data}
        acc = data.get("account", data) or {}
        return {
            "ok": True,
            "account": acc,
            "balance": acc.get("balance"),
            "available_links": acc.get("available_links"),
            "available_check": acc.get("available_indexation_check_links"),
            "tariff": acc.get("tariff"),
            "raw": data,
        }
    except Exception as e:  # noqa: BLE001
        log.warning("2index get_account failed: %s", e)
        return {"ok": False, "error": str(e)}


def list_projects(token, timeout=20):
    """Список проектов пользователя. GET /project → {ok, projects, error}."""
    if not token:
        return {"ok": False, "projects": [], "error": "нет токена"}
    try:
        resp = http_requests.get(f"{TWOINDEX_BASE}/project", headers=_headers(token), timeout=timeout)
        try:
            data = resp.json()
        except Exception:  # noqa: BLE001
            data = {"body": resp.text[:300]}
        if resp.status_code != 200 or not (isinstance(data, dict) and data.get("success", True)):
            return {"ok": False, "projects": [], "error": f"HTTP {resp.status_code}: {_errors_text(data)}"}
        projects = data.get("projects") or data.get("data") or []
        return {"ok": True, "projects": projects, "error": None, "raw": data}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "projects": [], "error": str(e)}


# Эндпоинт «добавить ссылки по имени проекта» (реальная дока):
# POST /api/v1/link/add_simple — project_name (необяз., по умолчанию "default"),
# links (обяз., массив или текст по ссылке в строке), google/yandex/bing (хотя бы
# один обязателен). Проект создаётся автоматически, отдельно управлять не нужно.
TWOINDEX_SEND_PATH = os.environ.get('TWOINDEX_SEND_PATH', '/link/add_simple')


def submit_urls(urls, token, project_name=None, timeout=40):
    """
    Отправить список URL на индексацию через 2index (Bearer-токен).
    POST /link/add_simple с {links: [...], google: 1, project_name?: ...}.
    Возвращает dict: {ok, accepted, error, raw}.
    """
    if not token:
        return {"ok": False, "accepted": 0, "error": "2index не настроен (нет токена)", "raw": None}
    urls = [u for u in (urls or []) if u]
    if not urls:
        return {"ok": False, "accepted": 0, "error": "нет URL", "raw": None}

    endpoint = f"{TWOINDEX_BASE}{TWOINDEX_SEND_PATH}"
    # API принимает form-urlencoded (как http_build_query в PHP-примере), НЕ JSON.
    # links — текст «по ссылке в строке»; google=1 — обязательна хотя бы одна ПС.
    payload = {
        "links": "\n".join(urls),
        "google": 1,
        "yandex": 0,
        "bing": 0,
        "google_access_granted": 0,
    }
    if project_name:
        payload["project_name"] = project_name
    try:
        log.info("2index submit %s url(s) -> %s (project=%s)", len(urls), endpoint, project_name)
        resp = http_requests.post(endpoint, data=payload, headers=_headers(token), timeout=timeout)
        log.debug("2index status=%s body[:500]=%s", resp.status_code, resp.text[:500])
        try:
            data = resp.json()
        except Exception:  # noqa: BLE001
            data = {"body": resp.text[:300]}

        ok = resp.status_code in (200, 201) and (not isinstance(data, dict) or data.get("success", True))
        if not ok:
            return {"ok": False, "accepted": 0,
                    "error": f"HTTP {resp.status_code} от {endpoint} — {_errors_text(data)}",
                    "raw": data}
        return {"ok": True, "accepted": len(urls), "error": None, "raw": data}
    except Exception as e:  # noqa: BLE001
        log.warning("2index submit failed: %s", e)
        return {"ok": False, "accepted": 0, "error": str(e), "raw": None}
