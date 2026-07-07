"""HTTP routes — HTML pages + JSON/SSE API.

Dashboard structure (spec §15):
- 1.  / — runs list + launch
- 1a. /runs/{id}/stream — SSE progress
- 2.  /runs/{id} — domain canvas
- 3.  /domains/{id} — domain card
"""

from __future__ import annotations

import asyncio
import json
from datetime import datetime
from typing import Annotated

from fastapi import APIRouter, File, Form, HTTPException, Query, Request, UploadFile
from fastapi.responses import (
    HTMLResponse,
    JSONResponse,
    PlainTextResponse,
    RedirectResponse,
    StreamingResponse,
)
from sqlalchemy import desc, select
from sqlalchemy.orm import selectinload

from webarhive.config import get_settings, save_overrides
from webarhive.db.engine import get_session
from webarhive.db.models import Domain, DomainStatus, Drop, Epoch, LlmCall, Redirect, Run, RunStatus
from webarhive.db.repo import unzip_text
from webarhive.domains.loader import load_from_bytes, load_from_text
from webarhive.orchestrator.runner import run_pipeline, start_run
from webarhive.web.deps import get_templates

html_router = APIRouter()
api_router = APIRouter(prefix="/api")


# ----- HTML -----

class CanvasFilters:
    """Lightweight helper that the canvas template uses to build
    chip-link query strings. Exposes qs(), qs_with(k,v), qs_without(k),
    qs_toggle(k) plus attribute access to the current values."""

    def __init__(self, *, q: str = "", verdict: str | None = None,
                 risky_only: bool = False, review_only: bool = False,
                 sort: str = "id"):
        self.q = q
        self.verdict = verdict
        self.risky_only = risky_only
        self.review_only = review_only
        self.sort = sort

    def _as_dict(self) -> dict[str, str]:
        d: dict[str, str] = {}
        if self.q: d["q"] = self.q
        if self.verdict: d["verdict"] = self.verdict
        if self.risky_only: d["risky_only"] = "true"
        if self.review_only: d["review_only"] = "true"
        if self.sort and self.sort != "id": d["sort"] = self.sort
        return d

    @staticmethod
    def _encode(d: dict[str, str]) -> str:
        from urllib.parse import urlencode
        return urlencode(d)

    def qs(self) -> str:
        return self._encode(self._as_dict())

    def qs_with(self, key: str, value: str) -> str:
        d = self._as_dict()
        d[key] = value
        return self._encode(d)

    def qs_without(self, key: str) -> str:
        d = self._as_dict()
        d.pop(key, None)
        return self._encode(d)

    def qs_toggle(self, key: str) -> str:
        d = self._as_dict()
        if key in d:
            d.pop(key)
        else:
            d[key] = "true"
        return self._encode(d)


def _active_llm_key(settings) -> str:
    """Возвращает API-ключ для текущего LLM-провайдера. Если провайдер
    «openai» — берём openai_api_key, иначе openrouter_api_key."""
    if (settings.llm_provider or "openrouter").lower() == "openai":
        return settings.openai_api_key
    return settings.openrouter_api_key


@html_router.get("/", response_class=HTMLResponse)
async def main_page(request: Request):
    async with get_session() as s:
        runs = (await s.execute(select(Run).order_by(desc(Run.started_at)).limit(100))).scalars().all()
        running_run = next((r for r in runs if r.status == RunStatus.RUNNING.value), None)
        # current domain for the live header
        current_domain = None
        if running_run is not None:
            current_domain = (await s.execute(
                select(Domain.domain).where(
                    Domain.run_id == running_run.id,
                    Domain.status == DomainStatus.RUNNING.value,
                ).limit(1)
            )).scalar_one_or_none()
    return get_templates(request).TemplateResponse(
        request,
        "main.html",
        {"runs": runs, "settings": get_settings(),
         "running_run": running_run, "current_domain": current_domain,
         "active_tab": "runs"},
    )


def _is_best_run(run: Run) -> bool:
    return (run.settings_snapshot or {}).get("mode") == "best"


@html_router.get("/best", response_class=HTMLResponse)
async def best_page(request: Request):
    """Отдельный инструмент «лучший слепок» — своя вкладка. Тот же ввод
    доменов, но запускает облегчённый режим (mode=best): только поиск
    самой полной копии главной по годам, без LLM/тем/редиректов/вердикта."""
    async with get_session() as s:
        all_runs = (await s.execute(
            select(Run).order_by(desc(Run.started_at)).limit(200))).scalars().all()
        runs = [r for r in all_runs if _is_best_run(r)]
        running_run = next(
            (r for r in runs if r.status == RunStatus.RUNNING.value), None)
        current_domain = None
        if running_run is not None:
            current_domain = (await s.execute(
                select(Domain.domain).where(
                    Domain.run_id == running_run.id,
                    Domain.status == DomainStatus.RUNNING.value,
                ).limit(1)
            )).scalar_one_or_none()
    return get_templates(request).TemplateResponse(
        request,
        "best.html",
        {"runs": runs, "settings": get_settings(),
         "running_run": running_run, "current_domain": current_domain,
         "active_tab": "best"},
    )


@html_router.get("/runs/{run_id}", response_class=HTMLResponse)
async def run_canvas(
    request: Request,
    run_id: int,
    q: str = Query(""),
    verdict: str | None = Query(None),
    risky_only: bool = Query(False),
    review_only: bool = Query(False),
    sort: str = Query("id"),
):
    async with get_session() as s:
        run = await s.get(Run, run_id)
        if run is None:
            raise HTTPException(404, "run not found")

        # counts for filter chips (unfiltered, just the totals)
        all_domains = (await s.execute(
            select(Domain).where(Domain.run_id == run_id)
        )).scalars().all()
        counts = {
            "all": len(all_domains),
            "clean":   sum(1 for d in all_domains if d.verdict == "clean"),
            "nuanced": sum(1 for d in all_domains if d.verdict == "nuanced"),
            "dirty":   sum(1 for d in all_domains if d.verdict == "dirty"),
        }

        # Pull epochs + redirects in one go so the flag_stack macro
        # can iterate them without triggering lazy loads after the
        # session is closed.
        stmt = (
            select(Domain)
            .where(Domain.run_id == run_id)
            .options(selectinload(Domain.epochs), selectinload(Domain.redirects))
        )
        if q:
            stmt = stmt.where(Domain.domain.contains(q.lower()))
        if verdict:
            stmt = stmt.where(Domain.verdict == verdict)
        if risky_only:
            stmt = stmt.where(Domain.risky_flag_count > 0)
        if review_only:
            stmt = stmt.where(Domain.review_flag_count > 0)
        sort_map = {
            "age": Domain.age_days.desc().nullslast(),
            "verdict": Domain.verdict.asc().nullslast(),
            "flags": (Domain.risky_flag_count + Domain.review_flag_count).desc(),
            "id": Domain.id.asc(),
        }
        stmt = stmt.order_by(sort_map.get(sort, Domain.id.asc()))
        domains = (await s.execute(stmt)).scalars().all()

    filters = CanvasFilters(
        q=q, verdict=verdict, risky_only=risky_only,
        review_only=review_only, sort=sort,
    )
    crumbs = [{"label": f"Прогон #{run.id}", "sub": run.started_at.strftime('%Y-%m-%d %H:%M'), "mono": True}]
    return get_templates(request).TemplateResponse(
        request,
        "canvas.html",
        {"run": run, "domains": domains, "counts": counts,
         "filters": filters, "crumbs": crumbs},
    )


@html_router.get("/domains/{domain_id}", response_class=HTMLResponse)
async def domain_card(request: Request, domain_id: int):
    async with get_session() as s:
        d = await s.get(Domain, domain_id)
        if d is None:
            raise HTTPException(404)
        epochs = (await s.execute(select(Epoch).where(Epoch.domain_id == domain_id)
                                  .order_by(Epoch.period_from))).scalars().all()
        redirects = (await s.execute(select(Redirect).where(Redirect.domain_id == domain_id)
                                     .order_by(Redirect.captured_at))).scalars().all()
        drops = (await s.execute(select(Drop).where(Drop.domain_id == domain_id)
                                 .order_by(Drop.gap_from))).scalars().all()
        llm_calls = (await s.execute(select(LlmCall).where(LlmCall.domain_id == domain_id)
                                     .order_by(LlmCall.created_at))).scalars().all()
        run = await s.get(Run, d.run_id)

    # Агрегация внешних редиректов по целевому домену (spec §7) — чтобы
    # «весь сайт 301 → newsite.com» показывался ОДНОЙ строкой «N страниц»,
    # а не полотном из сотен внутренних URL. Технические (свой домен/схема/
    # www/маркетплейсы) сворачиваются отдельно.
    from webarhive.analysis.redirects import _MARKETPLACE_ROOTS, _SOCIAL_ROOTS
    from webarhive.analysis.best_snapshot import _is_home_page

    def _effective_cls(r) -> str:
        # Старые прогоны (до появления класса marketplace) могли сохранить
        # редирект на dropcatch/godaddy как review/technical — подменяем на
        # display-уровне, чтобы и они показывались зелёным «маркетплейс».
        if r.target_domain and r.target_domain in _MARKETPLACE_ROOTS:
            return "marketplace"
        # Старые прогоны (до отнесения соцсетей к техническим) — редирект на
        # facebook/twitter/pinterest сворачиваем в «технические» на display.
        if r.target_domain and r.target_domain in _SOCIAL_ROOTS:
            return "technical"
        return r.classification

    ext_redirects = [r for r in redirects
                     if _effective_cls(r) not in ("technical",)]
    tech_redirects = [r for r in redirects
                      if _effective_cls(r) == "technical"]
    _cls_priority = {"review": 0, "company_move": 1, "same_site": 2, "marketplace": 3}

    def _group_redirects(items):
        groups: dict[str, dict] = {}
        for r in items:
            key = r.target_domain or "—"
            g = groups.get(key)
            if g is None:
                g = {"target": key, "count": 0, "classes": set(),
                     "first": r.captured_at, "last": r.captured_at,
                     "members": []}
                groups[key] = g
            g["count"] += 1
            g["classes"].add(_effective_cls(r))
            if r.captured_at and (g["first"] is None or r.captured_at < g["first"]):
                g["first"] = r.captured_at
            if r.captured_at and (g["last"] is None or r.captured_at > g["last"]):
                g["last"] = r.captured_at
            g["members"].append(r)
        out = []
        for g in groups.values():
            g["cls"] = sorted(g["classes"], key=lambda c: _cls_priority.get(c, 9))[0]
            g["mixed"] = len(g["classes"]) > 1
            # «Основной» редирект группы (что видно ДО разворота) — с главной
            # страницы: у неё высший приоритет. Редирект с robots.txt или
            # случайной внутренней страницы — не репрезентативен. members уже
            # в порядке captured_at (запрос отсортирован), поэтому берём
            # первый home-page, иначе — самый ранний из группы.
            members = g["members"]
            home_members = [m for m in members if _is_home_page(m.from_url, d.domain)]
            rep = (home_members or members)[0]
            g["reason"] = rep.reason
            g["snapshot_url"] = rep.snapshot_url or next(
                (m.snapshot_url for m in members if m.snapshot_url), None)
            g["rep_from"] = rep.from_url
            out.append(g)
        # Сначала самые «громкие» (review), затем по числу страниц.
        out.sort(key=lambda g: (_cls_priority.get(g["cls"], 9), -g["count"]))
        return out

    redirect_groups = _group_redirects(ext_redirects)

    crumbs = [
        {"label": f"Прогон #{run.id}",
         "sub": run.started_at.strftime('%Y-%m-%d %H:%M'),
         "href": f"/runs/{run.id}", "mono": True},
        {"label": d.domain, "mono": True},
    ]
    return get_templates(request).TemplateResponse(
        request,
        "card.html",
        {"domain": d, "epochs": epochs, "redirects": redirects,
         "redirect_groups": redirect_groups,
         "ext_redirect_count": len(ext_redirects),
         "tech_redirect_count": len(tech_redirects),
         "drops": drops, "llm_calls": llm_calls, "run": run, "crumbs": crumbs},
    )


@html_router.get("/runs/{run_id}/log.txt", response_class=PlainTextResponse)
async def run_log(run_id: int):
    """Объединённый лог всего прогона: trace каждого домена с префиксом."""
    from webarhive.db.repo import aggregate_run_log
    async with get_session() as s:
        run = await s.get(Run, run_id)
        if run is None:
            raise HTTPException(404)
        text = await aggregate_run_log(s, run_id)
    headers = {"Content-Disposition": f'attachment; filename="run_{run_id}.log.txt"'}
    return PlainTextResponse(text or "(пусто)", headers=headers)


@html_router.get("/runs/{run_id}/all_logs.txt", response_class=PlainTextResponse)
async def run_all_logs(run_id: int):
    """Один файл со ВСЕМ по прогону: шапка + полный snapshot настроек,
    применённых именно к этому прогону + сводка по доменам + трасса
    каждого домена + объединённый лог. Для отправки/архива одной кнопкой.
    """
    from webarhive.db.repo import aggregate_run_log

    async with get_session() as s:
        run = await s.get(Run, run_id)
        if run is None:
            raise HTTPException(404)
        domains = (await s.execute(
            select(Domain).where(Domain.run_id == run_id).order_by(Domain.id)
        )).scalars().all()
        merged_log = await aggregate_run_log(s, run_id)

    SEP = "=" * 78

    def section(title: str) -> str:
        return f"\n{SEP}\n{title}\n{SEP}\n"

    parts: list[str] = []
    parts.append(SEP)
    parts.append(f"WEBARHIVE — ПОЛНЫЙ ОТЧЁТ ПРОГОНА #{run.id}")
    parts.append(SEP)
    parts.append(f"Старт:    {run.started_at.strftime('%Y-%m-%d %H:%M:%S') if run.started_at else '—'}")
    parts.append(f"Финиш:    {run.finished_at.strftime('%Y-%m-%d %H:%M:%S') if run.finished_at else '—'}")
    parts.append(f"Статус:   {run.status}")
    parts.append(f"Доменов:  {run.total_domains} (обработано {run.processed_domains})")
    parts.append(
        f"Вердикты: чистых {run.clean_count} · нюансов {run.nuanced_count} · "
        f"грязных {run.dirty_count} · ошибок {run.error_count}"
    )
    parts.append(f"Заметка:  {run.note or '—'}")

    parts.append(section("НАСТРОЙКИ ПРОГОНА (snapshot — применялись именно к этому прогону)"))
    parts.append(json.dumps(run.settings_snapshot or {}, ensure_ascii=False, indent=2, sort_keys=True))

    parts.append(section("ДОМЕНЫ — СВОДКА"))
    parts.append(f"{'домен':<40} {'статус':<9} {'вердикт':<9} {'возраст':>8} {'версий':>7} {'флаги':>6}")
    parts.append("-" * 78)
    for d in domains:
        age = f"{d.age_days}д" if d.age_days is not None else "—"
        flags = d.risky_flag_count + d.review_flag_count
        parts.append(
            f"{d.domain[:39]:<40} {d.status:<9} {(d.verdict or '—'):<9} "
            f"{age:>8} {d.total_versions:>7} {flags:>6}"
        )
        if d.error_message:
            parts.append(f"    ↳ ошибка: {d.error_message}")

    for d in domains:
        parts.append(section(f"ТРАССА ДОМЕНА: {d.domain}  [{d.status}]"))
        if d.error_message:
            parts.append(f"ОШИБКА: {d.error_message}\n")
        parts.append(d.trace or "(трасса пуста)")

    parts.append(section("ОБЪЕДИНЁННЫЙ ЛОГ ПРОГОНА (все домены, по времени)"))
    parts.append(merged_log or "(пусто)")

    text = "\n".join(parts) + "\n"
    headers = {"Content-Disposition": f'attachment; filename="run_{run_id}_all_logs.txt"'}
    return PlainTextResponse(text, headers=headers)


@api_router.get("/runs/{run_id}/log", response_class=PlainTextResponse)
async def api_run_log(run_id: int):
    """То же что .txt-эндпоинт, но без Content-Disposition — для
    встроенного просмотра на странице прогона (полл/SSE)."""
    from webarhive.db.repo import aggregate_run_log
    async with get_session() as s:
        run = await s.get(Run, run_id)
        if run is None:
            raise HTTPException(404)
        text = await aggregate_run_log(s, run_id)
    return PlainTextResponse(text or "(пусто)")


@html_router.get("/domains/{domain_id}/trace.txt", response_class=PlainTextResponse)
async def domain_trace(domain_id: int):
    async with get_session() as s:
        d = await s.get(Domain, domain_id)
        if d is None:
            raise HTTPException(404)
    headers = {"Content-Disposition": f'attachment; filename="trace_{d.domain}.txt"'}
    return PlainTextResponse(d.trace or "(пусто)", headers=headers)


@html_router.get("/help", response_class=HTMLResponse)
async def help_page(request: Request):
    return get_templates(request).TemplateResponse(request, "help.html", {})


@html_router.get("/settings", response_class=HTMLResponse)
async def settings_page(request: Request, saved: bool = False):
    s = get_settings()
    return get_templates(request).TemplateResponse(
        request, "settings.html",
        {"settings": s, "fields": s.editable_fields(), "saved": saved},
    )


# Editable field types — for parsing the form values.
_BOOL_FIELDS = {
    "enable_verdict", "enable_smart_drop", "enable_redirect_llm",
    "check_subdomains",
    "whois_enabled",
    "enable_best_snapshot",
    "cdx_cache_enabled",
}
_INT_FIELDS = {
    "max_llm_calls_per_domain", "text_limit", "title_shift_threshold",
    "concurrency", "ia_max_retries", "light_fetch_cap",
    "redirect_cap", "redirect_llm_review_cap", "per_domain_timeout_sec",
    "whois_cache_ttl_days", "whois_monthly_floor",
    "best_snapshot_top_n", "best_snapshot_max_resources",
    "best_snapshot_per_epoch_timeout_sec",
    "best_snapshot_min_epoch_days", "best_snapshot_max_epochs",
    "llm_parallelism", "best_snapshot_epoch_parallelism",
    "cdx_cache_ttl_hours",
}
_FLOAT_FIELDS = {
    "ia_rate_limit", "ia_backoff",
    "whois_rate_limit",
}
# Свободные строки (модели, API-ключи).
_STR_FIELDS = (
    "model_classification", "model_verdict", "model_smart_drop", "model_redirect",
    "openrouter_api_key", "openai_api_key", "whois_api_key",
    "llm_provider",
)
# Поля-«секреты»: если оператор отправил пустое значение, ИГНОРИРУЕМ —
# не затираем уже сохранённый ключ пустотой.
_SECRET_FIELDS = ("openrouter_api_key", "openai_api_key", "whois_api_key")


@html_router.post("/settings", response_class=HTMLResponse)
async def settings_save(request: Request):
    """Persist UI edits to data/settings.json (spec §11, §15).

    Booleans come from checkboxes (present=true / absent=false).
    Validation: numeric fields cast or rejected; string fields trimmed.
    Empty secret fields are silently ignored (don't clobber a stored key
    with an empty submission).
    """
    form = await request.form()
    updates: dict = {}

    # Booleans: any value present = true, absent = false
    for f in _BOOL_FIELDS:
        updates[f] = f in form

    # Numbers
    for f in _INT_FIELDS:
        if f in form:
            try:
                updates[f] = int(str(form[f]).strip())
            except ValueError:
                pass
    for f in _FLOAT_FIELDS:
        if f in form:
            try:
                updates[f] = float(str(form[f]).strip())
            except ValueError:
                pass

    # Strings (model ids, api keys)
    for f in _STR_FIELDS:
        if f in form:
            v = str(form[f]).strip()
            if v:
                updates[f] = v
            elif f not in _SECRET_FIELDS:
                # Не-секретные поля можно очищать через пустую строку
                updates[f] = ""

    save_overrides(updates)
    return RedirectResponse(url="/settings?saved=1", status_code=303)


# ----- API: upload + launch -----

@api_router.post("/upload")
async def api_upload(
    request: Request,
    text: Annotated[str, Form()] = "",
    file: Annotated[UploadFile | None, File()] = None,
):
    settings = get_settings()
    if file is not None and file.filename:
        data = await file.read()
        report = load_from_bytes(file.filename, data,
                                 check_subdomains=settings.check_subdomains)
    else:
        report = load_from_text(text, check_subdomains=settings.check_subdomains)
    return JSONResponse({
        "raw_lines": report.raw_lines,
        "valid_unique": report.valid_unique,
        "dropped": report.dropped,
        "rejected": [{"original": r.original, "reason": r.reason}
                     for r in report.rejected],
    })


@api_router.post("/runs")
async def api_create_run(
    request: Request,
    domains: Annotated[str, Form()],  # newline-separated
    note: Annotated[str, Form()] = "",
    mode: Annotated[str, Form()] = "full",
):
    settings = get_settings()
    names = [ln.strip() for ln in domains.splitlines() if ln.strip()]
    if not names:
        raise HTTPException(400, "no domains")
    mode = (mode or "full").strip().lower()
    if mode not in ("full", "best"):
        mode = "full"
    snap = settings.snapshot()
    # Режим прогона едет в снапшоте — так он честно фиксируется в истории
    # и переиспользуется при «повторить прогон».
    snap["mode"] = mode
    note_final = note or None
    if mode == "best":
        note_final = ("лучший слепок" + (f" · {note}" if note else ""))
    run_id = await start_run(domains=names, settings_snapshot=snap, note=note_final)

    # Kick off the pipeline in the background.
    task = asyncio.create_task(run_pipeline(
        run_id=run_id, settings_snapshot=snap,
        api_key=_active_llm_key(settings),
        whois_api_key=settings.whois_api_key,
    ))
    request.app.state.pipeline_tasks[run_id] = task
    return JSONResponse({"run_id": run_id, "redirect": f"/runs/{run_id}"})


@api_router.post("/runs/{run_id}/rerun")
async def api_rerun(request: Request, run_id: int):
    """Создать новый прогон с теми же доменами и тем же snapshot настроек,
    что был у указанного прогона. Удобно после ручной остановки или
    если хочется повторить тест с теми же параметрами модели."""
    async with get_session() as s:
        run = await s.get(Run, run_id)
        if run is None:
            raise HTTPException(404, "run not found")
        domains = (await s.execute(
            select(Domain.domain).where(Domain.run_id == run_id).order_by(Domain.id)
        )).scalars().all()
        snap = dict(run.settings_snapshot or {})
        original_note = run.note or ""

    if not domains:
        raise HTTPException(400, "у исходного прогона нет доменов")

    note = f"повтор прогона #{run_id}" + (f" · {original_note}" if original_note else "")
    new_run_id = await start_run(domains=domains, settings_snapshot=snap, note=note)
    s = get_settings()
    task = asyncio.create_task(run_pipeline(
        run_id=new_run_id, settings_snapshot=snap,
        api_key=_active_llm_key(s),
        whois_api_key=s.whois_api_key,
    ))
    request.app.state.pipeline_tasks[new_run_id] = task
    return JSONResponse({"run_id": new_run_id, "redirect": f"/runs/{new_run_id}"})


@api_router.get("/runs/{run_id}/stream")
async def api_run_stream(run_id: int):
    """SSE — push run + per-domain progress every second until done."""

    async def gen():
        last_state = None
        while True:
            async with get_session() as s:
                run = await s.get(Run, run_id)
                if run is None:
                    yield "event: error\ndata: not_found\n\n"
                    return
                state = {
                    "status": run.status,
                    "processed": run.processed_domains,
                    "total": run.total_domains,
                    "clean": run.clean_count,
                    "nuanced": run.nuanced_count,
                    "dirty": run.dirty_count,
                    "errors": run.error_count,
                }
                # Show "current" — pick latest running domain if any.
                running = (await s.execute(
                    select(Domain.domain).where(
                        Domain.run_id == run_id,
                        Domain.status == DomainStatus.RUNNING.value,
                    ).limit(1)
                )).scalar_one_or_none()
                state["current"] = running

            if state != last_state:
                yield f"data: {json.dumps(state, ensure_ascii=False)}\n\n"
                last_state = state
            if state["status"] in (RunStatus.FINISHED.value, RunStatus.ABORTED.value,
                                   RunStatus.ERROR.value) and state["processed"] >= state["total"]:
                return
            await asyncio.sleep(1.0)

    return StreamingResponse(gen(), media_type="text/event-stream")


@api_router.post("/runs/{run_id}/abort")
async def api_abort_run(request: Request, run_id: int):
    """Остановить прогон. Не дошедшие до него домены остаются с
    отметкой «не проверен» (status=no_data) — чтобы было видно, что
    их остановили, а не успешно проверили."""
    from sqlalchemy import update as sa_update
    task = request.app.state.pipeline_tasks.pop(run_id, None)
    if task is not None and not task.done():
        task.cancel()
    async with get_session() as s:
        run = await s.get(Run, run_id)
        if run is None:
            raise HTTPException(404)
        run.status = RunStatus.ABORTED.value
        run.finished_at = datetime.utcnow()
        # сбрасываем все висящие pending/running домены
        await s.execute(
            sa_update(Domain)
            .where(
                Domain.run_id == run_id,
                Domain.status.in_([
                    DomainStatus.PENDING.value,
                    DomainStatus.RUNNING.value,
                ]),
            )
            .values(
                status=DomainStatus.NO_DATA.value,
                error_message="прогон остановлен оператором",
                finished_at=datetime.utcnow(),
            )
        )
        await s.commit()
    return JSONResponse({"status": "aborted"})


@api_router.get("/runs/{run_id}/export.csv", response_class=PlainTextResponse)
async def api_export_csv(
    run_id: int,
    verdict: str | None = Query(None),
    risky_only: bool = Query(False),
    review_only: bool = Query(False),
):
    """Export current filtered selection as CSV (spec §15 экран 2)."""
    import csv
    import io

    async with get_session() as s:
        stmt = select(Domain).where(Domain.run_id == run_id)
        if verdict:
            stmt = stmt.where(Domain.verdict == verdict)
        if risky_only:
            stmt = stmt.where(Domain.risky_flag_count > 0)
        if review_only:
            stmt = stmt.where(Domain.review_flag_count > 0)
        domains = (await s.execute(stmt.order_by(Domain.id))).scalars().all()

    buf = io.StringIO()
    w = csv.writer(buf)
    w.writerow([
        "domain", "verdict", "age_days", "first_capture", "last_capture",
        "epochs", "risky_flags", "review_flags", "status", "partial",
    ])
    for d in domains:
        w.writerow([
            d.domain, d.verdict or "", d.age_days or "",
            d.first_capture_at.isoformat() if d.first_capture_at else "",
            d.last_capture_at.isoformat() if d.last_capture_at else "",
            "",  # epochs count needs join; left blank in fast export
            d.risky_flag_count, d.review_flag_count, d.status,
            "1" if d.status == DomainStatus.PARTIAL.value else "0",
        ])
    return PlainTextResponse(
        buf.getvalue(),
        headers={"Content-Disposition": f'attachment; filename="run_{run_id}.csv"',
                 "Content-Type": "text/csv; charset=utf-8"},
    )


@api_router.post("/llm/check")
async def api_llm_check(
    provider: Annotated[str, Form()] = "",
    api_key: Annotated[str, Form()] = "",
):
    """Проверить валидность LLM-ключа без расхода токенов.

    Если оператор ничего не ввёл в поле ключа (api_key пустой) — берём
    сохранённый ключ выбранного провайдера. Так кнопка работает и для
    «только что введённого, ещё не сохранённого» ключа, и для уже
    сохранённого ранее.
    """
    from webarhive.llm.client import check_api_key

    s = get_settings()
    prov = (provider or s.llm_provider or "openrouter").strip().lower()
    key = api_key.strip() or (
        s.openai_api_key if prov == "openai" else s.openrouter_api_key
    )
    ok, message = await check_api_key(provider=prov, api_key=key)
    return JSONResponse({"ok": ok, "message": message})


@api_router.get("/llm_calls/{call_id}/input.txt", response_class=PlainTextResponse)
async def api_llm_input(call_id: int):
    async with get_session() as s:
        call = await s.get(LlmCall, call_id)
        if call is None:
            raise HTTPException(404)
    return PlainTextResponse(unzip_text(call.input_text_gz) or "(пусто)")
