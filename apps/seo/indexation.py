"""
Раздел «Индексация»: обход sitemap → реальные страницы сайта, затем по каждой
странице — статус индексации из Google (URL Inspection), проверка через XMLRIVER
и отправка на индекс через 2index. Массовые операции идут в фоне с прогрессом.
"""

import logging
import threading
import time
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime

import requests as http_requests

import gsc_manager as gscm
import xmlriver
import twoindex
from db import session_scope, IndexPage

log = logging.getLogger('seo.indexation')

UA = "Mozilla/5.0 (compatible; SEO-Dashboard-SitemapCrawler/1.0)"
MAX_WORKERS = 8
MAX_SITEMAPS = 200          # предохранитель от бесконечной вложенности
MAX_URLS = 100000

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


def _tick(n=1):
    with _lock:
        _job["done"] += n


# ─── обход sitemap ──────────────────────────────────────────────────────────────
def _site_base(site_url):
    """Базовый http-адрес сайта из GSC-ресурса (учёт sc-domain:)."""
    if site_url.startswith('sc-domain:'):
        return 'https://' + site_url.split(':', 1)[1].rstrip('/')
    return site_url.rstrip('/')


def _default_sitemap(site_url):
    return _site_base(site_url) + '/sitemap.xml'


def _fetch_sitemap_urls(sitemap_url, depth=0, seen=None):
    """Рекурсивно собрать URL из sitemap / sitemap-index."""
    if seen is None:
        seen = set()
    if sitemap_url in seen or len(seen) > MAX_SITEMAPS or depth > 5:
        return []
    seen.add(sitemap_url)
    try:
        resp = http_requests.get(sitemap_url, headers={"User-Agent": UA}, timeout=30)
        if resp.status_code != 200:
            log.warning("sitemap %s -> HTTP %s", sitemap_url, resp.status_code)
            return []
        root = ET.fromstring(resp.content)
    except Exception as e:  # noqa: BLE001
        log.warning("sitemap fetch/parse failed %s: %s", sitemap_url, e)
        return []

    tag = root.tag.lower()
    urls = []
    if tag.endswith('sitemapindex'):
        # вложенные карты
        child_locs = [el.text.strip() for el in root.iter()
                      if el.tag.lower().endswith('loc') and el.text]
        log.info("sitemap index %s -> %s child sitemaps", sitemap_url, len(child_locs))
        for loc in child_locs:
            urls.extend(_fetch_sitemap_urls(loc, depth + 1, seen))
    else:
        for el in root.iter():
            if el.tag.lower().endswith('loc') and el.text:
                urls.append(el.text.strip())
    return urls


def _run_crawl(site_url, sitemap_url):
    try:
        log.info("Sitemap crawl start: site=%s sitemap=%s", site_url, sitemap_url)
        urls = _fetch_sitemap_urls(sitemap_url)
        # уникализируем, ограничиваем
        uniq = list(dict.fromkeys(urls))[:MAX_URLS]
        with _lock:
            _job["total"] = len(uniq)
        added = 0
        with session_scope() as s:
            existing = {p.url for p in s.query(IndexPage.url).filter_by(site_url=site_url).all()}
            for u in uniq:
                if u not in existing:
                    s.add(IndexPage(site_url=site_url, url=u))
                    added += 1
                _tick()
        log.info("Sitemap crawl done: %s urls (%s new) for %s", len(uniq), added, site_url)
    except Exception as e:  # noqa: BLE001
        log.exception("crawl failed: %s", e)
        _finish_job(str(e)); return
    _finish_job()


def start_crawl(site_url, sitemap_url=None):
    sm = sitemap_url or _default_sitemap(site_url)
    if not _start_job("crawl", 0):
        return False
    threading.Thread(target=_run_crawl, args=(site_url, sm), daemon=True).start()
    return True


# ─── выборка страниц ────────────────────────────────────────────────────────────
def _selected(site_url, ids):
    with session_scope() as s:
        q = s.query(IndexPage)
        if site_url:
            q = q.filter_by(site_url=site_url)
        if ids:
            q = q.filter(IndexPage.id.in_(ids))
        return [(p.id, p.site_url, p.url) for p in q.all()]


# ─── Google URL Inspection (статус индексации + данные Google) ──────────────────
def _run_inspect(items):
    try:
        def work(item):
            pid, site_url, url = item
            try:
                service = gscm.get_service_for_site(site_url)
                if not service:
                    return
                body = {"inspectionUrl": url, "siteUrl": site_url, "languageCode": "ru"}
                resp = service.urlInspection().index().inspect(body=body).execute()
                idx = (resp.get("inspectionResult", {}) or {}).get("indexStatusResult", {}) or {}
                coverage = idx.get("coverageState")
                verdict = idx.get("verdict")
                last_crawl = idx.get("lastCrawlTime")
                # эвристика статуса
                cov = (coverage or "").lower()
                if "submitted and indexed" in cov or verdict == "PASS":
                    status = "indexed"
                elif coverage:
                    status = "not_indexed"
                else:
                    status = "unknown"
                with session_scope() as s:
                    p = s.query(IndexPage).filter_by(id=pid).first()
                    if p:
                        p.coverage_state = coverage
                        p.verdict = verdict
                        p.last_crawl_time = last_crawl
                        p.index_status = status
                        p.last_checked = datetime.utcnow()
            except Exception as e:  # noqa: BLE001
                log.warning("inspect failed %s: %s", url, e)
                with session_scope() as s:
                    p = s.query(IndexPage).filter_by(id=pid).first()
                    if p:
                        p.index_status = "error"
                        p.last_checked = datetime.utcnow()
            finally:
                _tick()
        # URL Inspection имеет строгие квоты — низкая параллельность
        with ThreadPoolExecutor(max_workers=4) as ex:
            list(ex.map(work, items))
    except Exception as e:  # noqa: BLE001
        log.exception("run_inspect failed: %s", e)
        _finish_job(str(e)); return
    _finish_job()


def _run_xmlriver(items, user, key):
    try:
        def work(item):
            pid, _site, url = item
            try:
                res = xmlriver.check_indexation(url, user, key)
                with session_scope() as s:
                    p = s.query(IndexPage).filter_by(id=pid).first()
                    if p:
                        p.index_status = res["status"]
                        p.index_count = res.get("count", 0)
                        p.last_checked = datetime.utcnow()
            finally:
                _tick()
        with ThreadPoolExecutor(max_workers=5) as ex:
            list(ex.map(work, items))
    except Exception as e:  # noqa: BLE001
        log.exception("run_xmlriver failed: %s", e)
        _finish_job(str(e)); return
    _finish_job()


def _run_submit(items, key):
    try:
        urls = [url for (_pid, _s, url) in items]
        res = twoindex.submit_urls(urls, key)
        if res["ok"]:
            ids = [pid for (pid, _s, _u) in items]
            with session_scope() as s:
                for p in s.query(IndexPage).filter(IndexPage.id.in_(ids)).all():
                    p.submitted = True
                    p.submitted_at = datetime.utcnow()
        with _lock:
            _job["done"] = _job["total"]
        _finish_job(None if res["ok"] else res.get("error"))
    except Exception as e:  # noqa: BLE001
        log.exception("run_submit failed: %s", e)
        _finish_job(str(e))


def start_inspect(site_url, ids):
    items = _selected(site_url, ids)
    if not _start_job("inspect", len(items)):
        return False
    threading.Thread(target=_run_inspect, args=(items,), daemon=True).start()
    return True


def start_xmlriver(site_url, ids, user, key):
    items = _selected(site_url, ids)
    if not _start_job("xmlriver", len(items)):
        return False
    threading.Thread(target=_run_xmlriver, args=(items, user, key), daemon=True).start()
    return True


def start_submit(site_url, ids, key):
    items = _selected(site_url, ids)
    if not _start_job("submit", len(items)):
        return False
    threading.Thread(target=_run_submit, args=(items, key), daemon=True).start()
    return True
