"""
Главный дашборд: фоновая агрегация сводных метрик по всем сайтам всех аккаунтов.

Тяжёлую работу (по запросу к GSC на каждый сайт) выполняет фоновый пул потоков,
результат кладётся в таблицу site_summary. Эндпоинт дашборда читает готовый кэш
моментально (ленивая подгрузка) и, при необходимости, дёргает обновление в фоне.
Рассчитано на 1000+ сайтов и несколько аккаунтов.
"""

import logging
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import date, timedelta

import gsc_manager as gscm
from db import session_scope, SiteSummary

log = logging.getLogger('seo.dashboard')

# GSC отдаёт данные с задержкой ~2-3 дня — берём запас.
GSC_LAG_DAYS = 3
# На сайт делаем ОДИН запрос (оба периода сразу, см. _totals_split), поэтому при
# 389 сайтах это ~389 запросов. Квота GSC — ~1200 запросов/мин на пользователя,
# так что 12 воркеров держат хороший темп с запасом от лимита.
MAX_WORKERS = 12
# Сколько раз повторить запрос при 429/5xx (rate limit), с экспоненциальной паузой.
_RETRIES = 4

# Статус фонового прогона (для прогресс-бара на фронте)
_job = {
    "running": False,
    "done": 0,
    "total": 0,
    "period": None,
    "started_at": None,
    "finished_at": None,
    "error": None,
}
_lock = threading.Lock()


def _period_ranges(period_days):
    """(start, end, prev_start, prev_end) в формате YYYY-MM-DD."""
    end = date.today() - timedelta(days=GSC_LAG_DAYS)
    start = end - timedelta(days=period_days - 1)
    prev_end = start - timedelta(days=1)
    prev_start = prev_end - timedelta(days=period_days - 1)
    fmt = "%Y-%m-%d"
    return start.strftime(fmt), end.strftime(fmt), prev_start.strftime(fmt), prev_end.strftime(fmt)


def period_ranges(period_days):
    """Публичная обёртка: (start, end, prev_start, prev_end) для периода."""
    return _period_ranges(period_days)


def _empty_bucket():
    return {"clicks": 0, "impressions": 0, "pos_weighted": 0.0}


def _finalize_bucket(b):
    """clicks/impressions → ctr; позиция взвешена по показам."""
    clicks, impr = b["clicks"], b["impressions"]
    return {
        "clicks": clicks,
        "impressions": impr,
        "ctr": (clicks / impr) if impr else 0.0,
        "position": (b["pos_weighted"] / impr) if impr else 0.0,
    }


def _query_with_retry(service, site_url, body):
    """Запрос к GSC с ретраем на 429/5xx (rate limit) и экспоненциальной паузой."""
    for attempt in range(_RETRIES):
        try:
            return service.searchanalytics().query(siteUrl=site_url, body=body).execute()
        except Exception as e:  # noqa: BLE001
            status = getattr(getattr(e, 'resp', None), 'status', None)
            if status in (429, 500, 503) and attempt < _RETRIES - 1:
                time.sleep(2 ** attempt)  # 1, 2, 4 c
                continue
            raise


def _totals_split(service, site_url, prev_start, end, cur_start):
    """
    ОДИН запрос за оба периода: тянем дни prev_start..end с разбивкой по датам и
    сами раскладываем на текущий (>= cur_start) и прошлый период. Раньше на сайт
    уходило два запроса — это вдвое меньше обращений к GSC.

    CTR = clicks/impressions; позиция усреднена с весом по показам (близко к тому,
    что показывает GSC для периода-агрегата).
    """
    body = {"startDate": prev_start, "endDate": end, "dimensions": ["date"],
            "rowLimit": 25000, "type": "web"}
    resp = _query_with_retry(service, site_url, body)
    cur, prev = _empty_bucket(), _empty_bucket()
    for r in resp.get("rows", []):
        day = (r.get("keys") or [""])[0]
        b = cur if day >= cur_start else prev
        impr = int(r.get("impressions", 0))
        b["clicks"] += int(r.get("clicks", 0))
        b["impressions"] += impr
        b["pos_weighted"] += float(r.get("position", 0.0)) * impr
    return _finalize_bucket(cur), _finalize_bucket(prev)


def compute_summary(site_url, period_days):
    """Посчитать текущий и предыдущий период для сайта. None, если нет сервиса."""
    service = gscm.get_service_for_site(site_url)
    if not service:
        return None
    start, end, prev_start, prev_end = _period_ranges(period_days)
    cur, prev = _totals_split(service, site_url, prev_start, end, start)
    return {
        "account_email": gscm.account_email_for_site(site_url),
        "clicks": cur["clicks"], "impressions": cur["impressions"],
        "ctr": cur["ctr"], "position": cur["position"],
        "prev_clicks": prev["clicks"], "prev_impressions": prev["impressions"],
        "prev_ctr": prev["ctr"], "prev_position": prev["position"],
    }


def _upsert(site_url, period_days, data):
    with session_scope() as s:
        row = (s.query(SiteSummary)
               .filter_by(site_url=site_url, period_days=period_days).first())
        if not row:
            row = SiteSummary(site_url=site_url, period_days=period_days)
            s.add(row)
        row.account_email = data["account_email"]
        row.clicks = data["clicks"]; row.impressions = data["impressions"]
        row.ctr = data["ctr"]; row.position = data["position"]
        row.prev_clicks = data["prev_clicks"]; row.prev_impressions = data["prev_impressions"]
        row.prev_ctr = data["prev_ctr"]; row.prev_position = data["prev_position"]


def _run_refresh(period_days):
    started = time.time()
    log.info("Dashboard refresh started (period=%sd)", period_days)
    try:
        sites = gscm.all_site_urls()
        with _lock:
            _job["total"] = len(sites)

        def work(site):
            try:
                data = compute_summary(site, period_days)
                if data:
                    _upsert(site, period_days, data)
                    log.debug("summary ok: %s (%s clicks)", site, data["clicks"])
            except Exception as e:  # noqa: BLE001
                log.warning("summary failed for %s: %s", site, e)
            finally:
                with _lock:
                    _job["done"] += 1

        with ThreadPoolExecutor(max_workers=MAX_WORKERS) as ex:
            list(ex.map(work, sites))

        log.info("Dashboard refresh done: %s sites in %.1fs",
                 len(sites), time.time() - started)
    except Exception as e:  # noqa: BLE001
        log.exception("Dashboard refresh failed: %s", e)
        with _lock:
            _job["error"] = str(e)
    finally:
        with _lock:
            _job["running"] = False
            _job["finished_at"] = time.time()


def refresh_all(period_days=28):
    """Запустить фоновое обновление. False, если уже идёт."""
    with _lock:
        if _job["running"]:
            return False
        _job.update(running=True, done=0, total=0, period=period_days,
                    started_at=time.time(), finished_at=None, error=None)
    threading.Thread(target=_run_refresh, args=(period_days,), daemon=True).start()
    return True


def job_status():
    with _lock:
        return dict(_job)


def get_summary(period_days=28):
    """Прочитать готовый кэш метрик по всем сайтам за период."""
    with session_scope() as s:
        rows = (s.query(SiteSummary)
                .filter_by(period_days=period_days)
                .order_by(SiteSummary.clicks.desc()).all())
        out = []
        for r in rows:
            out.append({
                "site_url": r.site_url,
                "account_email": r.account_email,
                "clicks": r.clicks, "impressions": r.impressions,
                "ctr": r.ctr, "position": r.position,
                "prev_clicks": r.prev_clicks, "prev_impressions": r.prev_impressions,
                "prev_ctr": r.prev_ctr, "prev_position": r.prev_position,
                "updated_at": r.updated_at.isoformat() if r.updated_at else None,
            })
        return out
