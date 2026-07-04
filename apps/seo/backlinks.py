"""
Мониторинг беклинков: 404-чекер, проверка наличия ссылки, индексация (XMLRIVER),
отправка на индекс (2index). Тяжёлые проверки выполняются фоновым пулом потоков
с прогрессом (рассчитано на массовые операции 100+ URL).

Ключи внешних API передаются из эндпоинтов (читаются из конфигурации), чтобы
модуль не зависел от backend_api (без циклических импортов).
"""

import logging
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
from urllib.parse import urlparse

import requests as http_requests

import xmlriver
import twoindex
from db import session_scope, Backlink

log = logging.getLogger('seo.backlinks')

UA = "Mozilla/5.0 (compatible; SEO-Dashboard-BacklinkChecker/1.0; +https://localhost)"
MAX_WORKERS = 10

_job = {"running": False, "kind": None, "done": 0, "total": 0,
        "started_at": None, "finished_at": None, "error": None}
_lock = threading.Lock()


def job_status():
    with _lock:
        return dict(_job)


def _start_job(kind, total):
    with _lock:
        if _job["running"]:
            return False
        _job.update(running=True, kind=kind, done=0, total=total,
                    started_at=time.time(), finished_at=None, error=None)
    return True


def _finish_job(error=None):
    with _lock:
        _job["running"] = False
        _job["finished_at"] = time.time()
        if error:
            _job["error"] = error


def _tick():
    with _lock:
        _job["done"] += 1


def _norm_target(target):
    """Домен/URL цели без схемы и завершающего слэша — для поиска в HTML."""
    t = (target or "").strip()
    t = t.replace("https://", "").replace("http://", "")
    return t.rstrip("/")


def check_one(source_url, target):
    """404 + наличие ссылки. Возвращает (http_status, link_present|None)."""
    try:
        resp = http_requests.get(source_url, headers={"User-Agent": UA},
                                 timeout=20, allow_redirects=True)
        status = resp.status_code
        present = None
        if status == 200 and target:
            html = resp.text or ""
            present = _norm_target(target) in html
        log.debug("backlink check %s -> %s present=%s", source_url, status, present)
        return status, present
    except Exception as e:  # noqa: BLE001
        log.warning("backlink check failed %s: %s", source_url, e)
        return 0, None


def _selected(ids):
    """Список Backlink по ids (или все, если ids пуст)."""
    with session_scope() as s:
        q = s.query(Backlink)
        if ids:
            q = q.filter(Backlink.id.in_(ids))
        return [(b.id, b.source_url, b.target_url) for b in q.all()]


# ─── фоновые прогоны ────────────────────────────────────────────────────────────
def _run_check(items):
    try:
        def work(item):
            bid, source_url, target_url = item
            try:
                status, present = check_one(source_url, target_url)
                with session_scope() as s:
                    b = s.query(Backlink).filter_by(id=bid).first()
                    if b:
                        b.http_status = status
                        b.link_present = present
                        b.last_checked = datetime.utcnow()
            finally:
                _tick()
        with ThreadPoolExecutor(max_workers=MAX_WORKERS) as ex:
            list(ex.map(work, items))
    except Exception as e:  # noqa: BLE001
        log.exception("run_check failed: %s", e)
        _finish_job(str(e)); return
    _finish_job()


def _run_index_check(items, user, key):
    try:
        def work(item):
            bid, source_url, _target = item
            try:
                res = xmlriver.check_indexation(source_url, user, key)
                with session_scope() as s:
                    b = s.query(Backlink).filter_by(id=bid).first()
                    if b:
                        b.index_status = res["status"]
                        b.index_count = res.get("count", 0)
                        b.last_checked = datetime.utcnow()
            finally:
                _tick()
        # XMLRIVER — внешний сервис с квотами: умеренная параллельность
        with ThreadPoolExecutor(max_workers=5) as ex:
            list(ex.map(work, items))
    except Exception as e:  # noqa: BLE001
        log.exception("run_index_check failed: %s", e)
        _finish_job(str(e)); return
    _finish_job()


def _run_submit(items, key):
    try:
        urls = [source_url for (_bid, source_url, _t) in items]
        res = twoindex.submit_urls(urls, key)
        if res["ok"]:
            ids = [bid for (bid, _s, _t) in items]
            with session_scope() as s:
                for b in s.query(Backlink).filter(Backlink.id.in_(ids)).all():
                    b.submitted = True
                    b.submitted_at = datetime.utcnow()
        with _lock:
            _job["done"] = _job["total"]
        _finish_job(None if res["ok"] else res.get("error"))
    except Exception as e:  # noqa: BLE001
        log.exception("run_submit failed: %s", e)
        _finish_job(str(e))


def start_check(ids=None):
    items = _selected(ids)
    if not _start_job("check", len(items)):
        return False
    threading.Thread(target=_run_check, args=(items,), daemon=True).start()
    return True


def start_index_check(ids, user, key):
    items = _selected(ids)
    if not _start_job("index", len(items)):
        return False
    threading.Thread(target=_run_index_check, args=(items, user, key), daemon=True).start()
    return True


def start_submit(ids, key):
    items = _selected(ids)
    if not _start_job("submit", len(items)):
        return False
    threading.Thread(target=_run_submit, args=(items, key), daemon=True).start()
    return True
