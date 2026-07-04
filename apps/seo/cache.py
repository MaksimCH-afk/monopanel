"""
Слой кэша seo на Redis с мягким фолбэком.

Если Redis недоступен (env REDIS_URL не задан или сервер не отвечает) — кэш
превращается в no-op, приложение продолжает работать (просто без ускорения).
Все операции подробно логируются.

Использование:
    from cache import cache_get_json, cache_set_json
    data = cache_get_json(key)
    if data is None:
        data = expensive_fetch()
        cache_set_json(key, data, ttl=300)
"""

import json
import logging
import os

log = logging.getLogger('seo.cache')

_client = None
_initialized = False
DEFAULT_TTL = int(os.environ.get('SEO_CACHE_TTL', '300'))  # 5 минут


def get_client():
    """Ленивое подключение к Redis. None, если недоступен."""
    global _client, _initialized
    if _initialized:
        return _client
    _initialized = True

    url = os.environ.get('REDIS_URL')
    if not url:
        log.info("REDIS_URL not set — cache disabled (no-op mode)")
        _client = None
        return None
    try:
        import redis  # локальный импорт: пакет нужен только при включённом кэше
        client = redis.from_url(url, socket_connect_timeout=2,
                                socket_timeout=2, decode_responses=True)
        client.ping()
        _client = client
        log.info("Connected to Redis at %s", url)
    except Exception as e:  # noqa: BLE001
        log.warning("Redis unavailable (%s) — cache disabled (no-op mode)", e)
        _client = None
    return _client


def cache_get_json(key):
    """Вернуть распарсенное значение по ключу или None."""
    client = get_client()
    if client is None:
        return None
    try:
        raw = client.get(key)
        if raw is None:
            log.debug("cache MISS %s", key)
            return None
        log.debug("cache HIT %s", key)
        return json.loads(raw)
    except Exception as e:  # noqa: BLE001
        log.warning("cache_get failed for %s: %s", key, e)
        return None


def cache_set_json(key, value, ttl=DEFAULT_TTL):
    """Сохранить значение (JSON-сериализуемое) с TTL в секундах."""
    client = get_client()
    if client is None:
        return False
    try:
        client.set(key, json.dumps(value, ensure_ascii=False), ex=ttl)
        log.debug("cache SET %s (ttl=%ss)", key, ttl)
        return True
    except Exception as e:  # noqa: BLE001
        log.warning("cache_set failed for %s: %s", key, e)
        return False


def cache_delete(*keys):
    """Удалить ключи из кэша (инвалидация)."""
    client = get_client()
    if client is None or not keys:
        return 0
    try:
        n = client.delete(*keys)
        log.debug("cache DEL %s -> %s", keys, n)
        return n
    except Exception as e:  # noqa: BLE001
        log.warning("cache_delete failed for %s: %s", keys, e)
        return 0


def cache_healthy():
    """Проверка доступности Redis (для /api/status). None = отключён намеренно."""
    if not os.environ.get('REDIS_URL'):
        return None
    client = get_client()
    if client is None:
        return False
    try:
        client.ping()
        return True
    except Exception:  # noqa: BLE001
        return False
