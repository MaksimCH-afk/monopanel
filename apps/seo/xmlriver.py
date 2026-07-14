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
import re
import xml.etree.ElementTree as ET

import requests as http_requests

log = logging.getLogger('seo.xmlriver')

XMLRIVER_BASE = os.environ.get('XMLRIVER_BASE', 'https://xmlriver.com/search/xml')
# Базовый адрес сервисных методов (get_balance, get_cost и т.п.)
XMLRIVER_API_BASE = os.environ.get('XMLRIVER_API_BASE', 'https://xmlriver.com/api')


def get_balance(user, key, timeout=15):
    """
    Текущий баланс аккаунта XMLRIVER (сумма основного и бонусного счетов).
    GET xmlriver.com/api/get_balance/?user=..&key=.. — возвращает строку с числом
    или сообщение об ошибке. Возвращает dict:
    {ok: bool, balance: float|None, raw: str, error: str|None}.
    """
    if not user or not key:
        return {"ok": False, "balance": None, "raw": "", "error": "XMLRIVER не настроен (user ID / key)"}
    try:
        resp = http_requests.get(f"{XMLRIVER_API_BASE}/get_balance/",
                                 params={"user": user, "key": key}, timeout=timeout)
        text = (resp.text or "").strip()
        if resp.status_code != 200:
            return {"ok": False, "balance": None, "raw": text, "error": f"HTTP {resp.status_code}: {text[:200]}"}
        if not text:
            return {"ok": False, "balance": None, "raw": "", "error": "Пустой ответ от XMLRIVER"}
        # Ответ — строка с балансом (число) ИЛИ сообщение об ошибке.
        looks_error = any(w in text.lower() for w in ("error", "ошибк", "invalid", "ключ", "не заре"))
        m = re.search(r"-?\d+(?:[.,]\d+)?", text)
        if m and not looks_error:
            return {"ok": True, "balance": float(m.group().replace(",", ".")), "raw": text, "error": None}
        return {"ok": False, "balance": None, "raw": text, "error": text[:200]}
    except Exception as e:  # noqa: BLE001
        log.warning("XMLRIVER get_balance failed: %s", e)
        return {"ok": False, "balance": None, "raw": "", "error": str(e)}


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

        # Ошибка от сервиса. Код 15 = «нет результатов по запросу» — это НЕ сбой,
        # а признак, что страница не в индексе (см. коды ошибок XMLRIVER).
        for el in root.iter():
            if el.tag.endswith('error'):
                code = (el.get('code') or '').strip()
                text = (el.text or '').strip()
                if code == '15':
                    return {"status": "not_indexed", "count": 0, "error": None}
                if code or text:
                    return {"status": "error", "count": 0,
                            "error": f"[{code}] {text}".strip() if code else text}

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
