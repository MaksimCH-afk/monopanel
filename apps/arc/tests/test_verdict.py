from datetime import datetime

from webarhive.analysis.redirects import RedirectInfo
from webarhive.analysis.topics import TopicEpoch
from webarhive.analysis.verdict import _aggregate_flags, _coerce_verdict, make_verdict
from webarhive.db.models import RedirectClass, Verdict
from webarhive.llm.client import LlmResponse


def _ep(cat, year):
    return TopicEpoch(datetime(year, 1, 1), datetime(year, 12, 31), cat, 0.9, "", "u")


def _redir(cls, target_domain="bar.com", from_url="http://foo.com/"):
    return RedirectInfo(
        captured_at=datetime(2020, 1, 1), from_url=from_url,
        to_url=f"http://{target_domain}/", target_domain=target_domain,
        classification=cls, reason="", snapshot_url="u",
    )


def test_review_flags_count_distinct_targets_not_pages():
    # 3 внутренние страницы одного сайта → один целевой домен → один флаг.
    redirs = [
        _redir(RedirectClass.REVIEW, "newsite.com", "http://foo.com/a"),
        _redir(RedirectClass.REVIEW, "newsite.com", "http://foo.com/b"),
        _redir(RedirectClass.REVIEW, "newsite.com", "http://foo.com/c"),
    ]
    _, review, flags = _aggregate_flags(epochs=[], redirects=redirs)
    assert review == 1
    assert "review_redirects:1" in flags


def test_review_flags_count_multiple_distinct_targets():
    redirs = [
        _redir(RedirectClass.REVIEW, "a.com"),
        _redir(RedirectClass.REVIEW, "a.com"),
        _redir(RedirectClass.REVIEW, "b.com"),
    ]
    _, review, _ = _aggregate_flags(epochs=[], redirects=redirs)
    assert review == 2


def test_flag_aggregation_counts():
    epochs = [_ep("информационный_контентный", 2010), _ep("гемблинг_казино", 2015)]
    redirs = [_redir(RedirectClass.REVIEW), _redir(RedirectClass.TECHNICAL)]
    risky, review, flags = _aggregate_flags(epochs=epochs, redirects=redirs)
    assert risky == 1
    assert review == 1
    assert "risky:гемблинг_казино" in flags
    assert "review_redirects:1" in flags


def test_coerce_verdict_handles_russian_and_english():
    v, r, _ = _coerce_verdict({"verdict": "чистый", "reason": "ok"})
    assert v is Verdict.CLEAN and r == "ok"
    v, _, _ = _coerce_verdict({"verdict": "dirty"})
    assert v is Verdict.DIRTY
    v, _, _ = _coerce_verdict({"verdict": "нюансы"})
    assert v is Verdict.NUANCED
    v, _, _ = _coerce_verdict({"verdict": "garbage"})
    assert v is None


async def test_make_verdict_disabled_returns_flags_only():
    epochs = [_ep("гемблинг_казино", 2015)]
    redirs = [_redir(RedirectClass.REVIEW)]
    r = await make_verdict(
        enabled=False, domain="foo.com", age_days=100,
        epochs=epochs, redirects=redirs, drops=[], partial=False,
    )
    assert r.verdict is None
    assert r.risky_flag_count == 1
    assert r.review_flag_count == 1


class StubLlm:
    def __init__(self, response):
        self._response = response

    async def chat_json(self, **kw):
        return self._response


async def test_make_verdict_enabled_parses_response():
    fake = LlmResponse(
        raw_text='{"verdict":"грязный","reason":"casino in history","key_flags":["casino"]}',
        parsed={"verdict": "грязный", "reason": "casino in history", "key_flags": ["casino"]},
        prompt_tokens=100, completion_tokens=20, cost_usd=0.001,
        latency_ms=200, model="m",
    )
    epochs = [_ep("гемблинг_казино", 2015)]
    r = await make_verdict(
        enabled=True, domain="foo.com", age_days=200,
        epochs=epochs, redirects=[], drops=[], partial=False,
        llm=StubLlm(fake), model="m",
    )
    assert r.verdict is Verdict.DIRTY
    assert "casino" in r.key_flags
    assert "risky:гемблинг_казино" in r.key_flags  # baseline preserved


async def test_make_verdict_invalid_llm_falls_back_to_none():
    fake = LlmResponse("garbage", None, None, None, None, 10, "m", error="parse")
    r = await make_verdict(
        enabled=True, domain="foo.com", age_days=100,
        epochs=[], redirects=[], drops=[], partial=False,
        llm=StubLlm(fake), model="m",
    )
    assert r.verdict is None
