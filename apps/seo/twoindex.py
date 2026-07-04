"""
Адаптер 2index Ninja — отправка URL на индексацию.

ВНИМАНИЕ: официальная документация (https://2index.ninja/api-documentation)
недоступна из среды разработки, поэтому контракт восстановлен по общей
информации. Endpoint и имена полей вынесены в константы — правьте под реальное
API при расхождении. Формат: POST {base}/add  body={key, urls:[...]}.
"""

import logging
import os

import requests as http_requests

log = logging.getLogger('seo.twoindex')

TWOINDEX_BASE = os.environ.get('TWOINDEX_BASE', 'https://2index.ninja/api')
TWOINDEX_ADD_PATH = os.environ.get('TWOINDEX_ADD_PATH', '/add')


def submit_urls(urls, key, timeout=40):
    """
    Отправить список URL на индексацию.
    Возвращает dict: {ok: bool, accepted: int, error: str|None, raw: any}.
    """
    if not key:
        return {"ok": False, "accepted": 0, "error": "2index не настроен (нет ключа)", "raw": None}
    if not urls:
        return {"ok": False, "accepted": 0, "error": "нет URL", "raw": None}

    endpoint = f"{TWOINDEX_BASE.rstrip('/')}{TWOINDEX_ADD_PATH}"
    payload = {"key": key, "urls": list(urls)}
    try:
        log.info("2index submit %s url(s) -> %s", len(urls), endpoint)
        resp = http_requests.post(endpoint, json=payload, timeout=timeout)
        log.debug("2index status=%s body[:500]=%s", resp.status_code, resp.text[:500])

        try:
            data = resp.json()
        except Exception:  # noqa: BLE001
            data = {"body": resp.text[:300]}

        if resp.status_code not in (200, 201):
            return {"ok": False, "accepted": 0,
                    "error": f"HTTP {resp.status_code}", "raw": data}
        return {"ok": True, "accepted": len(urls), "error": None, "raw": data}
    except Exception as e:  # noqa: BLE001
        log.warning("2index submit failed: %s", e)
        return {"ok": False, "accepted": 0, "error": str(e), "raw": None}
