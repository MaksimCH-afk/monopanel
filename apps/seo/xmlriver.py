"""
Адаптер XMLRIVER — проверка индексации URL в Google.

ВНИМАНИЕ: официальная документация (https://xmlriver.com/api/) недоступна из
среды разработки, поэтому контракт восстановлен по общей информации о сервисе.
Все параметры вынесены в константы/переменные окружения — при расхождении с
реальным API правьте здесь (endpoint, имена параметров, парсинг ответа).
Формат: GET https://xmlriver.com/search/xml?user=..&key=..&query=site:URL
Ответ — XML с результатами; индексация = URL присутствует в выдаче.
"""

import logging
import os
import xml.etree.ElementTree as ET

import requests as http_requests

log = logging.getLogger('seo.xmlriver')

XMLRIVER_BASE = os.environ.get('XMLRIVER_BASE', 'https://xmlriver.com/search/xml')


def check_indexation(url, user, key, timeout=25):
    """
    Проверить, есть ли URL в индексе Google через XMLRIVER.
    Возвращает dict: {status: 'indexed'|'not_indexed'|'unknown'|'error',
                      count: int, error: str|None}.
    """
    if not user or not key:
        return {"status": "error", "count": 0, "error": "XMLRIVER не настроен (user/key)"}

    query = f'site:{url}'
    params = {"user": user, "key": key, "query": query}
    try:
        log.info("XMLRIVER request base=%s query=%s (user=%s key=***)",
                 XMLRIVER_BASE, query, user)
        resp = http_requests.get(XMLRIVER_BASE, params=params, timeout=timeout)
        log.debug("XMLRIVER status=%s body[:500]=%s", resp.status_code, resp.text[:500])

        if resp.status_code != 200:
            return {"status": "error", "count": 0, "error": f"HTTP {resp.status_code}"}

        try:
            root = ET.fromstring(resp.text)
        except Exception as e:  # noqa: BLE001
            return {"status": "error", "count": 0,
                    "error": f"XML parse: {e}; body={resp.text[:200]}"}

        # Ошибка от сервиса (например, неверный ключ/баланс)
        for el in root.iter():
            if el.tag.endswith('error') and (el.text or '').strip():
                return {"status": "error", "count": 0, "error": el.text.strip()}

        # Собираем URL из выдачи
        found_urls = [el.text for el in root.iter()
                      if el.tag.endswith('url') and el.text]
        norm = url.rstrip('/')
        present = any(norm in (u or '') for u in found_urls)

        if present or len(found_urls) > 0:
            return {"status": "indexed", "count": len(found_urls), "error": None}
        return {"status": "not_indexed", "count": 0, "error": None}

    except Exception as e:  # noqa: BLE001
        log.warning("XMLRIVER check failed for %s: %s", url, e)
        return {"status": "error", "count": 0, "error": str(e)}
