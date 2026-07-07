"""Template + dependency wiring."""

from __future__ import annotations

from pathlib import Path

from fastapi import Request
from fastapi.templating import Jinja2Templates

from webarhive.config.categories import (
    CATEGORIES,
    CATEGORY_BY_KEY,
    is_risky,
)
from webarhive.db.engine import get_session

# Версия деплоя — увеличиваем при каждой правке кода. Шапка показывает
# это значение справа от «настройки» — чтобы оператор видел, что
# именно крутится в Docker'е, и не путался при пересборках.
APP_VERSION = "4.0"


def _age_human(days) -> str:
    """Дни → «15 л 10 мес 16 д». Приближённо: год=365д, месяц=30д.
    Нулевые старшие части опускаются; 0 дней → «0 д»."""
    try:
        days = int(days or 0)
    except (TypeError, ValueError):
        return "—"
    if days <= 0:
        return "0 д"
    years, rem = divmod(days, 365)
    months, d = divmod(rem, 30)
    parts = []
    if years:
        parts.append(f"{years} л")
    if months:
        parts.append(f"{months} мес")
    if d or not parts:
        parts.append(f"{d} д")
    return " ".join(parts)


def templates_for(directory: Path) -> Jinja2Templates:
    t = Jinja2Templates(directory=str(directory))
    # Expose category metadata + helpers to templates.
    t.env.globals["CATEGORIES"] = CATEGORIES
    t.env.globals["CATEGORY_BY_KEY"] = CATEGORY_BY_KEY
    t.env.globals["is_risky"] = is_risky
    t.env.filters["category_icon"] = lambda key: (CATEGORY_BY_KEY.get(key).icon if key in CATEGORY_BY_KEY else "help-circle")
    t.env.filters["category_label"] = lambda key: (CATEGORY_BY_KEY.get(key).label_ru if key in CATEGORY_BY_KEY else key)
    t.env.filters["category_group"] = lambda key: (CATEGORY_BY_KEY.get(key).group.value if key in CATEGORY_BY_KEY else "unknown")
    t.env.globals["app_version"] = APP_VERSION
    t.env.filters["age_human"] = _age_human
    return t


def get_templates(request: Request) -> Jinja2Templates:
    return request.app.state.templates


# Re-export session ctx manager for convenience.
__all__ = ["get_session", "templates_for", "get_templates"]
