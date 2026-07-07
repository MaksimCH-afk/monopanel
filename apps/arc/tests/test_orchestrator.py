"""End-to-end smoke test: orchestrator on in-memory SQLite with mocked
HTTP for CDX, Wayback snapshots, and OpenRouter."""

from datetime import datetime

import httpx
import pytest
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine

from webarhive.config import get_settings
from webarhive.db import Base, Domain, DomainStatus, Run, RunStatus
from webarhive.db.repo import get_pending_for_run


@pytest.fixture
async def session_factory():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    sf = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)
    yield sf
    await engine.dispose()


def _cdx_handler_factory():
    """Pretend foo.com has three captures: two pizza pages and a casino page."""
    pizza_html = (
        b"<html><head><title>Pizza shop online</title>"
        b'<meta name="description" content="best pizza">'
        b"</head><body><h1>Order pizza</h1>"
        b"<p>We deliver pizza fast worldwide</p></body></html>"
    )
    casino_html = (
        b"<html><head><title>Casino slots roulette bonus</title>"
        b'<meta name="description" content="win big">'
        b"</head><body><h1>Top casino</h1>"
        b"<p>Spin and win at our casino with daily bonus</p></body></html>"
    )

    def handler(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "cdx/search/cdx" in url:
            header = ["urlkey", "timestamp", "original", "mimetype", "statuscode", "digest", "length"]
            all_rows = [
                ["com,foo)/", "20100101000000", "http://foo.com/", "text/html", "200", "AAA", "100"],
                ["com,foo)/", "20120101000000", "http://foo.com/", "text/html", "200", "AAB", "100"],
                ["com,foo)/", "20180101000000", "http://foo.com/", "text/html", "200", "CCC", "100"],
            ]
            # CDX server-side filter: process_domain now does parallel
            # filtered queries per status bucket. Honour `filter=` so
            # filter=statuscode:3.. returns nothing (no redirects).
            filters = request.url.params.get_list("filter") if hasattr(
                request.url.params, "get_list"
            ) else [request.url.params.get("filter", "")]
            rows = all_rows
            for f in filters:
                if not f:
                    continue
                if f.startswith("statuscode:"):
                    spec = f.split(":", 1)[1]
                    if spec == "200":
                        rows = [r for r in rows if r[4] == "200"]
                    elif spec == "3..":
                        rows = [r for r in rows if r[4].startswith("3")]
                    elif spec == "404":
                        rows = [r for r in rows if r[4] == "404"]
            return httpx.Response(200, json=[header] + rows)
        if "/web/20180101" in url:
            return httpx.Response(200, content=casino_html,
                                  headers={"content-type": "text/html; charset=utf-8"})
        if "/web/" in url:
            return httpx.Response(200, content=pizza_html,
                                  headers={"content-type": "text/html; charset=utf-8"})
        if "openrouter" in url:
            # Read user prompt to decide which category to return
            req_body = request.read().decode("utf-8")
            if "Casino" in req_body or "casino" in req_body:
                content = '{"category":"гемблинг_казино","confidence":0.95,"reason":"slots"}'
            elif "Pizza" in req_body or "pizza" in req_body:
                content = '{"category":"коммерция_магазин","confidence":0.9,"reason":"food ecom"}'
            else:
                content = '{"category":"не_определено","confidence":0.0,"reason":"x"}'
            return httpx.Response(
                200,
                json={
                    "choices": [{"message": {"content": content}}],
                    "usage": {"prompt_tokens": 50, "completion_tokens": 10, "total_cost": 0.0001},
                },
            )
        return httpx.Response(404)

    return handler


async def test_end_to_end_single_domain(session_factory, monkeypatch):
    """One domain through the whole pipeline; verifies row state,
    epochs written, verdict reached, counters updated."""
    from webarhive.cdx.client import CdxClient
    from webarhive.cdx.throttle import IAThrottle
    from webarhive.db.repo import create_run, seed_domains
    from webarhive.fetcher.snapshot import SnapshotFetcher
    from webarhive.llm.client import OpenRouterClient
    from webarhive.orchestrator.runner import process_domain

    snap = get_settings().snapshot()
    snap["roles"]["verdict"] = True  # ensure verdict path runs
    # Make shift threshold tiny so the pipeline definitely sees the casino shift.
    snap["limits"]["title_shift_threshold"] = 0

    async with session_factory() as s:
        run = await create_run(s, total=1, settings_snapshot=snap)
        rows = await seed_domains(s, run.id, ["foo.com"])
        await s.commit()
        domain_row = rows[0]
        run_id = run.id

    transport = httpx.MockTransport(_cdx_handler_factory())
    async with httpx.AsyncClient(transport=transport, follow_redirects=False) as http:
        throttle = IAThrottle(rate=100)
        cdx = CdxClient(throttle=throttle, client=http, backoff_base=0.01, max_retries=2)
        fetcher = SnapshotFetcher(throttle=throttle, client=http, backoff_base=0.01, max_retries=2)
        llm = OpenRouterClient(api_key="test-key", client=http, backoff_base=0.01, max_retries=2)

        await process_domain(
            domain_row=domain_row,
            run_id=run_id,
            snapshot=snap,
            session_factory=session_factory,
            cdx=cdx,
            fetcher=fetcher,
            llm=llm,
        )

    async with session_factory() as s:
        d = await s.get(Domain, domain_row.id)
        assert d is not None
        assert d.status in (DomainStatus.DONE.value, DomainStatus.PARTIAL.value)
        assert d.total_captures == 3
        assert d.age_days is not None and d.age_days > 0
        # Two epochs: pizza period then casino
        await s.refresh(d, attribute_names=["epochs"])
        cats = [e.category for e in d.epochs]
        assert "гемблинг_казино" in cats
        assert d.risky_flag_count >= 1
        assert d.trace and "START" in d.trace and "готово" in d.trace

        run = await s.get(Run, run_id)
        assert run is not None
        assert run.processed_domains == 1


def _cdx_handler_with_redirects():
    """foo.com: одна живая главная + 3xx с главной И с внутренних страниц,
    все ведут на newsite.com. Вариант А должен оставить только редирект
    самой главной."""
    home_html = (
        b"<html><head><title>Foo home</title></head>"
        b"<body><h1>Foo</h1></body></html>"
    )
    # 3xx-строки: одна с главной (во «внешней» форме без слеша), остальные —
    # внутренние страницы. Все 301 → newsite.com.
    redirect_rows = [
        ["com,foo)/", "20200101000000", "http://foo.com", "text/html", "301", "R00", "0"],
        ["com,foo)/blog/post-1", "20200102000000", "http://foo.com/blog/post-1", "text/html", "301", "R01", "0"],
        ["com,foo)/robots.txt", "20200103000000", "http://foo.com/robots.txt", "text/plain", "301", "R02", "0"],
        ["com,foo)/tag/news", "20200104000000", "http://foo.com/tag/news", "text/html", "301", "R03", "0"],
        ["com,foo)/wp-login.php", "20200105000000", "http://foo.com/wp-login.php", "text/html", "301", "R04", "0"],
    ]
    live_rows = [
        ["com,foo)/", "20100101000000", "http://foo.com/", "text/html", "200", "AAA", "100"],
    ]

    def handler(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "cdx/search/cdx" in url:
            header = ["urlkey", "timestamp", "original", "mimetype", "statuscode", "digest", "length"]
            filters = request.url.params.get_list("filter") if hasattr(
                request.url.params, "get_list"
            ) else [request.url.params.get("filter", "")]
            rows: list[list[str]] = []
            spec = None
            for f in filters:
                if f and f.startswith("statuscode:"):
                    spec = f.split(":", 1)[1]
            if spec == "200":
                rows = live_rows
            elif spec == "3..":
                rows = redirect_rows
            elif spec == "404":
                rows = []
            else:
                rows = live_rows + redirect_rows
            return httpx.Response(200, json=[header] + rows)
        # 3xx-снапшоты (ts=2020..) — отдаём Location на новый домен.
        if "/web/2020" in url:
            return httpx.Response(
                200, content=b"", headers={"location": "https://newsite.com/"},
            )
        if "/web/" in url:
            return httpx.Response(200, content=home_html,
                                  headers={"content-type": "text/html; charset=utf-8"})
        return httpx.Response(404)

    return handler


async def test_variant_a_redirects_only_from_home(session_factory):
    """Вариант А: из 5 3xx (1 с главной + 4 внутренних) в анализ и БД
    попадает только редирект самой главной."""
    from sqlalchemy import select

    from webarhive.cdx.client import CdxClient
    from webarhive.cdx.throttle import IAThrottle
    from webarhive.db import Redirect
    from webarhive.db.repo import create_run, seed_domains
    from webarhive.fetcher.snapshot import SnapshotFetcher
    from webarhive.orchestrator.runner import process_domain

    snap = get_settings().snapshot()
    snap["roles"]["verdict"] = False  # без LLM, чистая классификация редиректов

    async with session_factory() as s:
        run = await create_run(s, total=1, settings_snapshot=snap)
        rows = await seed_domains(s, run.id, ["foo.com"])
        await s.commit()
        domain_row = rows[0]
        run_id = run.id

    transport = httpx.MockTransport(_cdx_handler_with_redirects())
    async with httpx.AsyncClient(transport=transport, follow_redirects=False) as http:
        throttle = IAThrottle(rate=100)
        cdx = CdxClient(throttle=throttle, client=http, backoff_base=0.01, max_retries=2)
        fetcher = SnapshotFetcher(throttle=throttle, client=http, backoff_base=0.01, max_retries=2)

        await process_domain(
            domain_row=domain_row,
            run_id=run_id,
            snapshot=snap,
            session_factory=session_factory,
            cdx=cdx,
            fetcher=fetcher,
            llm=None,  # вариант А не зависит от LLM
        )

    async with session_factory() as s:
        stored = (await s.execute(
            select(Redirect).where(Redirect.domain_id == domain_row.id)
        )).scalars().all()
        # только один редирект — с главной; все внутренние отфильтрованы
        assert len(stored) == 1, [r.from_url for r in stored]
        assert stored[0].from_url == "http://foo.com"
        assert stored[0].target_domain == "newsite.com"

        d = await s.get(Domain, domain_row.id)
        # один уникальный целевой домен → один флаг, а не 5
        assert d.review_flag_count == 1
        assert d.trace and "внутренних отброшено 4" in d.trace


async def test_resumability_skips_done_domains(session_factory):
    """Re-running the pipeline on a run should only process pending rows."""
    from webarhive.db.repo import create_run, seed_domains

    async with session_factory() as s:
        run = await create_run(s, total=2, settings_snapshot={})
        await seed_domains(s, run.id, ["foo.com", "bar.com"])
        await s.commit()
        run_id = run.id

    # Mark foo.com done manually
    async with session_factory() as s:
        d = (await s.execute(
            __import__("sqlalchemy").select(Domain).where(Domain.run_id == run_id, Domain.domain == "foo.com")
        )).scalar_one()
        d.status = DomainStatus.DONE.value
        await s.commit()

    async with session_factory() as s:
        pending = await get_pending_for_run(s, run_id)
        names = sorted([d.domain for d in pending])
        assert names == ["bar.com"]
