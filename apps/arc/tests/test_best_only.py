"""End-to-end smoke test for the standalone «best copy» mode
(process_domain_best_only): no LLM, one best snapshot per calendar year."""

import httpx
import pytest
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine

from webarhive.config import get_settings
from webarhive.db import Base, Domain, DomainStatus, Run


@pytest.fixture
async def session_factory():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    sf = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)
    yield sf
    await engine.dispose()


def _handler():
    home_html = (
        b"<html><head><title>Home</title>"
        b'<link rel="stylesheet" href="/static/app.css">'
        b'<script src="/static/app.js"></script>'
        b"</head><body><img src=/img/logo.png></body></html>"
    )

    def handler(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "cdx/search/cdx" in url:
            header = ["urlkey", "timestamp", "original", "mimetype",
                      "statuscode", "digest", "length"]
            rows = [
                ["com,foo)/", "20180101000000", "http://foo.com/", "text/html", "200", "AAA", "500"],
                ["com,foo)/", "20180601000000", "http://foo.com/", "text/html", "200", "AAB", "500"],
                ["com,foo)/", "20200101000000", "http://foo.com/", "text/html", "200", "CCC", "500"],
            ]
            filters = request.url.params.get_list("filter")
            for f in filters:
                if f.startswith("statuscode:"):
                    spec = f.split(":", 1)[1]
                    if spec != "200":
                        rows = []
            return httpx.Response(200, json=[header] + rows)
        if "wayback/available" in url:
            # Every resource is reported archived and OK.
            return httpx.Response(200, json={
                "archived_snapshots": {
                    "closest": {"available": True, "status": "200",
                                "url": "http://web.archive.org/web/x"}
                }
            })
        if "/web/" in url:
            return httpx.Response(200, content=home_html,
                                  headers={"content-type": "text/html; charset=utf-8"})
        return httpx.Response(404)

    return handler


async def test_best_only_one_snapshot_per_year(session_factory):
    from webarhive.cdx.client import CdxClient
    from webarhive.cdx.throttle import IAThrottle
    from webarhive.db.repo import create_run, seed_domains
    from webarhive.fetcher.snapshot import SnapshotFetcher
    from webarhive.orchestrator.runner import process_domain_best_only

    snap = get_settings().snapshot()
    snap["mode"] = "best"

    async with session_factory() as s:
        run = await create_run(s, total=1, settings_snapshot=snap)
        rows = await seed_domains(s, run.id, ["foo.com"])
        await s.commit()
        domain_row = rows[0]
        run_id = run.id

    transport = httpx.MockTransport(_handler())
    async with httpx.AsyncClient(transport=transport, follow_redirects=False) as http:
        throttle = IAThrottle(rate=1000)
        cdx = CdxClient(throttle=throttle, client=http, backoff_base=0.01, max_retries=2)
        fetcher = SnapshotFetcher(throttle=throttle, client=http, backoff_base=0.01, max_retries=2)

        await process_domain_best_only(
            domain_row=domain_row,
            run_id=run_id,
            snapshot=snap,
            session_factory=session_factory,
            cdx=cdx,
            fetcher=fetcher,
            llm=None,
            http=http,
            throttle=throttle,
        )

    async with session_factory() as s:
        d = await s.get(Domain, domain_row.id)
        assert d is not None
        assert d.status == DomainStatus.DONE.value
        assert d.total_captures == 3
        # No verdict in best mode.
        assert d.verdict is None
        await s.refresh(d, attribute_names=["epochs"])
        labels = sorted(e.category for e in d.epochs)
        assert labels == ["2018", "2020"]  # one window per calendar year
        for e in d.epochs:
            assert e.best_snapshot_url  # every year got a best snapshot
            assert e.best_snapshot_score is not None
            detail = e.best_snapshot_detail or {}
            # css+js+img all reported archived → full coverage
            assert detail.get("resources_archived") == detail.get("resources_total")
            assert detail.get("resources_total", 0) >= 1

        run = await s.get(Run, run_id)
        assert run.processed_domains == 1
