"""Best snapshot — pure helpers (no IA, no fetcher)."""

from datetime import datetime

from webarhive.analysis.best_snapshot import (
    _classify_resource,
    _extract_resources,
    _is_home_page,
    _select_candidates,
    epoch_candidates,
    filter_home_page_rows,
    year_windows,
)
from webarhive.cdx.client import CdxRow


def _row(ts: str, url: str, status: str = "200") -> CdxRow:
    return CdxRow(urlkey="x", timestamp=ts, original=url,
                  mimetype="text/html", statuscode=status,
                  digest="d", length="100")


def test_is_home_page():
    assert _is_home_page("https://foo.com/", "foo.com")
    assert _is_home_page("https://www.foo.com/", "foo.com")
    assert _is_home_page("https://foo.com/index.html", "foo.com")
    assert _is_home_page("http://foo.com", "foo.com")
    assert not _is_home_page("https://foo.com/about", "foo.com")
    assert not _is_home_page("https://blog.foo.com/", "foo.com")
    assert not _is_home_page("https://bar.com/", "foo.com")


def test_is_home_page_all_forms():
    """Главная во всех формах: протокол, www, слеш, index, без схемы."""
    home_forms = [
        "http://foo.com", "http://www.foo.com", "http://foo.com/", "http://www.foo.com/",
        "https://foo.com", "https://www.foo.com", "https://foo.com/", "https://www.foo.com/",
        "foo.com", "www.foo.com", "foo.com/", "www.foo.com/",
        "https://www.foo.com/index.html", "http://foo.com/?utm=x", "foo.com/#top",
    ]
    for f in home_forms:
        assert _is_home_page(f, "foo.com"), f

    not_home = [
        "http://foo.com/blog", "foo.com/robots.txt", "https://sub.foo.com/",
        "http://otherfoo.com/", "http://notfoo.com/", "",
    ]
    for f in not_home:
        assert not _is_home_page(f, "foo.com"), f

    # www в самом source_domain тоже нормализуется
    assert _is_home_page("http://foo.com/", "www.foo.com")


def test_filter_home_page_rows_status_and_path():
    rows = [
        _row("20100101000000", "http://foo.com/"),       # ✓
        _row("20100201000000", "http://foo.com/about"),  # path → skip
        _row("20100301000000", "http://foo.com/", "404"),  # status → skip
        _row("20100401000000", "https://www.foo.com/index.html"),  # ✓
    ]
    kept = filter_home_page_rows(rows, "foo.com")
    assert [r.timestamp for r in kept] == ["20100101000000", "20100401000000"]


def test_epoch_candidates_time_window():
    rows = [
        _row("20180601000000", "http://foo.com/"),
        _row("20200601000000", "http://foo.com/"),
        _row("20220601000000", "http://foo.com/"),
    ]
    out = epoch_candidates(rows, datetime(2019, 1, 1), datetime(2021, 1, 1))
    assert len(out) == 1 and out[0].timestamp == "20200601000000"


def test_select_candidates_keeps_first_last():
    rows = [_row(f"2020010{i}000000", "http://x") for i in range(1, 10)]
    picked = _select_candidates(rows, 3)
    assert picked[0] is rows[0]
    assert picked[-1] is rows[-1]


def test_year_windows_one_per_year():
    rows = [
        _row("20100101000000", "http://foo.com/"),
        _row("20100615000000", "http://foo.com/"),
        _row("20120101000000", "http://foo.com/"),
        _row("20180101000000", "http://foo.com/"),
    ]
    ws = year_windows(rows)
    assert [w.label for w in ws] == ["2010", "2012", "2018"]
    # 2010 window spans first→last capture of that year, counts both.
    y2010 = ws[0]
    assert y2010.versions == 2
    assert y2010.period_from == datetime(2010, 1, 1)
    assert y2010.period_to == datetime(2010, 6, 15)


def test_year_windows_max_keeps_most_recent():
    rows = [_row(f"{y}0101000000", "http://foo.com/")
            for y in ("2010", "2012", "2018", "2020")]
    ws = year_windows(rows, max_windows=2)
    assert [w.label for w in ws] == ["2018", "2020"]


def test_year_windows_empty():
    assert year_windows([]) == []


def test_classify_resource_buckets():
    assert _classify_resource("https://x/y/style.css?v=1") == "css"
    assert _classify_resource("/static/app.js") == "js"
    assert _classify_resource("//cdn/picture.JPG") == "img"
    assert _classify_resource("https://x/font.woff2") == "font"
    assert _classify_resource("https://x/api/data") == "other"


def test_extract_resources_picks_css_js_img():
    html = b"""<html><head>
      <link rel="stylesheet" href="/static/app.css">
      <script src="//cdn.example.com/lib.js"></script>
    </head><body>
      <img src="/img/logo.png">
      <a href="javascript:void(0)">x</a>
      <img src="data:image/png;base64,AAA">
    </body></html>"""
    res = _extract_resources(html, "utf-8", "http://foo.com/")
    paths = sorted(r.split("://", 1)[-1] for r in res)
    assert any("app.css" in p for p in paths)
    assert any("lib.js" in p for p in paths)
    assert any("logo.png" in p for p in paths)
    # data: and javascript: skipped
    assert not any("base64" in p for p in paths)
    assert not any("void(0)" in p for p in paths)
