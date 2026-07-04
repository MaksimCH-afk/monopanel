"""
Планировщик фоновой автоматизации seo.

Фоновый поток раз в минуту проверяет расписание и запускает задачи, у которых
подошёл срок: автообновление сводных метрик дашборда и списка сайтов по всем
аккаунтам. Интервалы и вкл/выкл настраиваются из UI; отметки последних запусков
персистятся в /data/seo/automation_state.json (переживают перезапуск).

Расширяемо: новые задачи добавляются в _loop() по тому же принципу «_due → run».
"""

import json
import logging
import os
import threading
import time
from datetime import datetime

import dashboard as seo_dashboard
import gsc_manager as gscm

log = logging.getLogger('seo.scheduler')

DATA_DIR = os.environ.get('SEO_DATA_DIR', os.path.dirname(__file__))
STATE_FILE = os.path.join(DATA_DIR, 'automation_state.json')

DEFAULT_CONFIG = {
    "enabled": False,
    "dashboardRefreshHours": 6,
    "sitesRefreshHours": 24,
    "dashboardPeriod": 28,
}

_config = dict(DEFAULT_CONFIG)
_state = {"lastDashboard": None, "lastSites": None}
_lock = threading.Lock()
_thread = None


def _load_state():
    try:
        if os.path.exists(STATE_FILE):
            with open(STATE_FILE, encoding='utf-8') as f:
                _state.update(json.load(f))
    except Exception as e:  # noqa: BLE001
        log.warning("load automation state failed: %s", e)


def _save_state():
    try:
        with open(STATE_FILE, 'w', encoding='utf-8') as f:
            json.dump(_state, f)
    except Exception as e:  # noqa: BLE001
        log.warning("save automation state failed: %s", e)


def configure(cfg):
    """Обновить конфиг планировщика (из сохранённых настроек)."""
    if not cfg:
        return
    with _lock:
        for k in DEFAULT_CONFIG:
            if k in cfg and cfg[k] is not None:
                _config[k] = cfg[k]
    log.info("Automation configured: %s", _config)


def get_status():
    with _lock:
        return {
            "config": dict(_config),
            "state": dict(_state),
            "running": bool(_thread and _thread.is_alive()),
        }


def _due(last_iso, hours):
    if not last_iso:
        return True
    try:
        last = datetime.fromisoformat(last_iso)
    except Exception:  # noqa: BLE001
        return True
    return (datetime.utcnow() - last).total_seconds() >= float(hours) * 3600


def run_dashboard():
    log.info("Auto task: dashboard refresh")
    seo_dashboard.refresh_all(_config.get("dashboardPeriod", 28))
    _state["lastDashboard"] = datetime.utcnow().isoformat()
    _save_state()


def run_sites():
    log.info("Auto task: sites refresh")
    gscm.refresh_all_sites()
    _state["lastSites"] = datetime.utcnow().isoformat()
    _save_state()


def run_now(task):
    """Запустить задачу немедленно (из кнопки «Запустить сейчас»)."""
    if task == "dashboard":
        run_dashboard()
    elif task == "sites":
        run_sites()
    else:
        raise ValueError(f"неизвестная задача: {task}")
    return True


def _loop():
    _load_state()
    log.info("Scheduler loop started")
    while True:
        try:
            with _lock:
                enabled = _config["enabled"]
                dh = _config["dashboardRefreshHours"]
                sh = _config["sitesRefreshHours"]
            if enabled:
                if _due(_state.get("lastSites"), sh):
                    run_sites()
                if _due(_state.get("lastDashboard"), dh):
                    run_dashboard()
        except Exception as e:  # noqa: BLE001
            log.exception("scheduler tick failed: %s", e)
        time.sleep(60)


def start():
    """Запустить фоновый поток планировщика (идемпотентно)."""
    global _thread
    if _thread and _thread.is_alive():
        return
    _load_state()
    _thread = threading.Thread(target=_loop, daemon=True)
    _thread.start()
    log.info("Scheduler thread started")
