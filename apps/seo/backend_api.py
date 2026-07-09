#!/usr/bin/env python3
"""
GSC Dashboard Backend API
Flask API to serve Google Search Console data to the Next.js frontend
"""

from flask import Flask, jsonify, request, redirect
from flask_cors import CORS
import sys
import os
import argparse
import datetime
import time
import httplib2
from urllib.parse import quote
from apiclient.discovery import build
from oauth2client import client, file, tools
import pandas as pd
from dateutil.relativedelta import relativedelta
import json
from openai import OpenAI
import requests as http_requests
from google.oauth2.credentials import Credentials
from google.auth.transport.requests import Request as GoogleRequest
from google_auth_oauthlib.flow import Flow

# OAuth через веб-редирект работает по http на localhost (не https) и Google
# может вернуть чуть иной набор scope — ослабляем требования oauthlib, иначе
# fetch_token падает. setdefault, чтобы не переопределять при внешней настройке.
os.environ.setdefault('OAUTHLIB_INSECURE_TRANSPORT', '1')
os.environ.setdefault('OAUTHLIB_RELAX_TOKEN_SCOPE', '1')

# ─── Логирование / БД / кэш (фундамент) ────────────────────────────────────────
import logging
from logging_config import setup_logging
log = setup_logging()

import db as seo_db
import cache as seo_cache
import gsc_manager as gscm
import dashboard as seo_dashboard
import backlinks as seo_backlinks
import indexation as seo_indexation
import scheduler as seo_scheduler
import siteverify as seo_siteverify

app = Flask(__name__)

# Подробное логирование каждого запроса к API: метод, путь, статус, длительность.
@app.before_request
def _log_request_start():
    request._start_ts = time.time()
    log.info("→ %s %s", request.method, request.path)

@app.after_request
def _log_request_end(response):
    try:
        dur_ms = (time.time() - getattr(request, '_start_ts', time.time())) * 1000
        log.info("← %s %s %s (%.0f ms)", request.method, request.path,
                 response.status_code, dur_ms)
    except Exception:  # noqa: BLE001 — логи не должны ломать ответ
        pass
    return response

# Адрес фронта seo (для CORS и для возврата после OAuth). По умолчанию :3332,
# как в docker-compose; можно переопределить через env для другого хоста.
FRONTEND_URL = os.environ.get('SEO_FRONTEND_URL', 'http://localhost:3332').rstrip('/')

# Enable CORS for Next.js frontend with explicit configuration.
# Фронт живёт на :3332 (docker-compose), :3000 оставлен для локальной разработки.
_cors_origins = list(dict.fromkeys([
    FRONTEND_URL,
    "http://localhost:3332", "http://127.0.0.1:3332",
    "http://localhost:3000", "http://127.0.0.1:3000",
]))
CORS(app, resources={
    r"/api/*": {
        "origins": _cors_origins,
        "methods": ["GET", "POST", "OPTIONS"],
        "allow_headers": ["Content-Type", "Authorization"]
    }
})

# [monopanel] Постоянный каталог для кред/токенов Google — переживает пересборку
# образа. В контейнере монтируется том /data/seo (env SEO_DATA_DIR); локально без
# переменной работает как раньше (рядом с кодом).
DATA_DIR = os.environ.get('SEO_DATA_DIR', os.path.dirname(__file__))
os.makedirs(DATA_DIR, exist_ok=True)

# Config file path
CONFIG_FILE = os.path.join(DATA_DIR, 'dashboard_config.json')

# Global variables
webmasters_service = None
verified_sites = []
openai_client = None

# Default OpenAI model used for insights when none is configured
DEFAULT_OPENAI_MODEL = "gpt-4o"

# ─── GSC OAuth (веб-флоу, совместимый с Docker) ────────────────────────────────
# Scope'ы берём из менеджера (там же webmasters + email для мультиаккаунта).
GSC_SCOPES = gscm.GSC_SCOPES
# Legacy single-token файл прежнего одно-аккаунтного флоу (для миграции на старте).
GSC_TOKEN_FILE = os.path.join(DATA_DIR, 'authorized_user_gsc.json')
# Куда Google возвращает пользователя после подтверждения. Должен быть добавлен
# в «Authorized redirect URIs» OAuth-клиента в Google Cloud Console.
OAUTH_REDIRECT_URI = os.environ.get(
    'SEO_OAUTH_REDIRECT_URI', 'http://localhost:5001/api/oauth/google/callback')
# state -> путь к client_secret.json
_oauth_flows = {}

# Default settings
DEFAULT_SETTINGS = {
    "openaiApiKey": "",
    "openaiModel": DEFAULT_OPENAI_MODEL,
    "credentialsPath": os.path.join(DATA_DIR, "client_secret.json"),
    "trendsCredentialsPath": "",
    "isAuthorized": False,
    "overviewSites": [],
    # Внешние сервисы мониторинга беклинков
    "xmlriverUser": "",
    "xmlriverKey": "",
    "twoindexKey": "",
}


def get_openai_model():
    """Return the OpenAI model configured in settings (falls back to default)."""
    config = load_config()
    model = config.get('openaiModel', '') or DEFAULT_OPENAI_MODEL
    return model

# ─── Google Trends helpers ────────────────────────────────────────────────────

TRENDS_SCOPES = ["https://www.googleapis.com/auth/searchtrends"]
TRENDS_BASE_URLS = [
    "https://searchtrends.googleapis.com",
    "https://trends.googleapis.com",
]


def load_trends_creds(token_file: str, client_secrets: str) -> Credentials:
    creds = None
    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, TRENDS_SCOPES)
    if creds and creds.expired and creds.refresh_token:
        creds.refresh(GoogleRequest())
        with open(token_file, "w") as f:
            f.write(creds.to_json())
        return creds
    if not creds or not creds.valid:
        from google_auth_oauthlib.flow import InstalledAppFlow
        flow = InstalledAppFlow.from_client_secrets_file(client_secrets, TRENDS_SCOPES)
        try:
            creds = flow.run_local_server(port=0)
        except Exception:
            creds = flow.run_console()
        with open(token_file, "w") as f:
            f.write(creds.to_json())
    return creds


def trends_auth_headers(creds: Credentials, token_file: str) -> dict:
    if creds.expired and creds.refresh_token:
        creds.refresh(GoogleRequest())
        with open(token_file, "w") as f:
            f.write(creds.to_json())
    return {
        "Authorization": f"Bearer {creds.token}",
        "Content-Type": "application/json",
    }


def fetch_trends_for_query(query: str, creds: Credentials, token_file: str,
                           start_dt: datetime.datetime, end_dt: datetime.datetime,
                           geo_code: str, time_resolution: str) -> list:
    headers = trends_auth_headers(creds, token_file)
    request_body = {
        "spec": {
            "expression": {"terms": [{"value": query, "type": "BROAD"}]},
            "geo": {"type": "GEO_TYPE_COUNTRY_OR_REGION", "code": geo_code},
            "timeRange": {
                "startTime": {"seconds": int(start_dt.timestamp())},
                "endTime": {"seconds": int(end_dt.timestamp())},
            },
            "timeResolution": time_resolution,
        }
    }

    final = None
    for base in TRENDS_BASE_URLS:
        resp = http_requests.post(
            f"{base}/v1alpha:fetchTimeSeries",
            headers=headers,
            json=request_body,
        )
        if resp.status_code == 404:
            continue
        if not resp.ok:
            resp.raise_for_status()
        op = resp.json()
        op_name = op.get("name")
        if not op_name:
            final = op
            break
        if not op_name.startswith("operations/"):
            op_name = "operations/" + op_name
        while True:
            r = http_requests.get(f"{base}/v1alpha/{op_name}", headers=headers)
            r.raise_for_status()
            result = r.json()
            if result.get("done"):
                final = result
                break
            time.sleep(1)
        break

    if final is None:
        raise RuntimeError("No valid Trends endpoint responded.")

    resp_payload = final.get("response", final)
    points = []
    for pt in resp_payload.get("timeSeries", {}).get("points", []):
        tr = pt.get("timeRange", {})
        raw = tr.get("startTime")
        if raw is None:
            continue
        if isinstance(raw, dict) and "seconds" in raw:
            dt = pd.Timestamp.fromtimestamp(int(raw["seconds"]), tz="UTC").tz_localize(None)
        else:
            dt = pd.to_datetime(raw, utc=True).tz_localize(None)
        val = float(pt.get("scaledSearchInterest", pt.get("searchInterest", 0)))
        points.append({"date": dt.strftime("%Y-%m-%d"), "value": val})
    return points

def load_config():
    """Load configuration from file"""
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, 'r') as f:
                config = json.load(f)
                # Merge with defaults to ensure all keys exist
                return {**DEFAULT_SETTINGS, **config}
        except Exception as e:
            print(f"Error loading config: {e}")
            return DEFAULT_SETTINGS.copy()
    return DEFAULT_SETTINGS.copy()

def save_config(config):
    """Save configuration to file"""
    try:
        with open(CONFIG_FILE, 'w') as f:
            json.dump(config, f, indent=2)
        return True
    except Exception as e:
        print(f"Error saving config: {e}")
        return False

def initialize_openai_client():
    """Initialize OpenAI client from config"""
    global openai_client
    config = load_config()
    api_key = config.get('openaiApiKey', '')
    if api_key:
        try:
            openai_client = OpenAI(api_key=api_key)
            print("OpenAI client initialized")
        except Exception as e:
            print(f"Error initializing OpenAI client: {e}")
            openai_client = None
    else:
        openai_client = None

def authorize_creds(creds_path, authorized_creds_path=os.path.join(DATA_DIR, 'authorizedcreds.dat'),
                    allow_interactive=True):
    """
    Authorize and return the Webmasters API service.

    allow_interactive=False — только подхватить уже сохранённые креды
    (authorizedcreds.dat), НЕ запускать интерактивный OAuth-флоу. Это критично
    для старта сервера: tools.run_flow() блокирует поток до завершения OAuth,
    а в headless-контейнере (без браузера) висит навсегда — тогда app.run()
    не выполняется и порт 5001 не открывается (Connection reset by peer).
    """
    try:
        SCOPES = ['https://www.googleapis.com/auth/webmasters.readonly']

        parser = argparse.ArgumentParser(
            formatter_class=argparse.RawDescriptionHelpFormatter,
            parents=[tools.argparser])
        flags = parser.parse_args([])

        flow = client.flow_from_clientsecrets(
            creds_path, scope=SCOPES,
            message=tools.message_if_missing(creds_path))

        storage = file.Storage(authorized_creds_path)
        credentials = storage.get()

        if credentials is None or credentials.invalid:
            if not allow_interactive:
                # Нет сохранённой авторизации — на старте молча выходим,
                # авторизацию пользователь запустит кнопкой «Авторизовать».
                print("GSC not authorized yet (no stored credentials); "
                      "skipping interactive flow at startup.")
                return None
            credentials = tools.run_flow(flow, storage, flags)

        http = httplib2.Http()
        http = credentials.authorize(http=http)
        webmasters_service = build('searchconsole', 'v1', http=http)
        
        return webmasters_service
    except Exception as e:
        print(f"Error in authorize_creds: {str(e)}")
        return None

def get_verified_sites(webmasters_service):
    """Get list of verified sites from Google Search Console"""
    try:
        site_list = webmasters_service.sites().list().execute()
        
        verified_sites_urls = [s['siteUrl'] for s in site_list['siteEntry']
                              if s['permissionLevel'] != 'siteUnverifiedUser'
                              and s['siteUrl'][:4] == 'http']
        
        return verified_sites_urls
    except Exception as e:
        print(f"Error getting verified sites: {str(e)}")
        return []

def get_data(webmasters_service, site, start_date, end_date, dimensions=None, search_type="web", 
             dimension_filters=None, aggregation_type="auto", row_limit=25000, start_row=0):
    """Request data from the GSC API"""
    if dimensions is None:
        dimensions = ['date']
    
    request_body = {
        'startDate': start_date,
        'endDate': end_date,
        'dimensions': dimensions,
        'type': search_type,
        'aggregationType': aggregation_type,
        'rowLimit': row_limit,
        'startRow': start_row
    }
    
    if dimension_filters:
        request_body['dimensionFilterGroups'] = dimension_filters
    
    try:
        response = webmasters_service.searchanalytics().query(siteUrl=site, body=request_body).execute()
        
        if 'rows' in response:
            return response['rows']
        else:
            return []
    except Exception as e:
        print(f"Error fetching data: {str(e)}")
        return []

def clean_and_export_data(rows, dimensions):
    """Clean data and return as dictionary"""
    data = {
        "rows": []
    }
    
    print(f"DEBUG: Processing {len(rows)} rows with dimensions: {dimensions}")
    
    total_clicks = 0
    total_impressions = 0
    total_ctr = 0
    total_position = 0
    
    # For daily aggregation
    daily_data = {}
    
    # For query aggregation (across all dates)
    query_data = {}
    
    for row in rows:
        row_data = {
            "keys": row.get('keys', []),
            "clicks": row.get('clicks', 0),
            "impressions": row.get('impressions', 0),
            "ctr": row.get('ctr', 0),
            "position": row.get('position', 0)
        }
        data["rows"].append(row_data)
        
        if len(row_data["keys"]) > 0:
            print(f"DEBUG: Row keys: {row_data['keys']}, clicks: {row_data['clicks']}")
        
        total_clicks += row_data["clicks"]
        total_impressions += row_data["impressions"]
        total_ctr += row_data["ctr"]
        total_position += row_data["position"]
        
        # Aggregate by date if date dimension is present
        if 'date' in dimensions and row_data["keys"]:
            date_key = row_data["keys"][0]  # First dimension is typically date
            if date_key not in daily_data:
                daily_data[date_key] = {
                    "clicks": 0,
                    "impressions": 0,
                    "ctr": 0,
                    "position": 0,
                    "count": 0
                }
            daily_data[date_key]["clicks"] += row_data["clicks"]
            daily_data[date_key]["impressions"] += row_data["impressions"]
            daily_data[date_key]["ctr"] += row_data["ctr"]
            daily_data[date_key]["position"] += row_data["position"]
            daily_data[date_key]["count"] += 1
        
        # Aggregate by query (across all dates)
        if 'query' in dimensions and len(row_data["keys"]) > 1:
            query_key = row_data["keys"][1]  # Second dimension is typically query when date is first
            print(f"DEBUG: Aggregating query '{query_key}' with {row_data['clicks']} clicks")
            if query_key not in query_data:
                query_data[query_key] = {
                    "clicks": 0,
                    "impressions": 0,
                    "ctr": 0,
                    "position": 0,
                    "count": 0
                }
            query_data[query_key]["clicks"] += row_data["clicks"]
            query_data[query_key]["impressions"] += row_data["impressions"]
            query_data[query_key]["ctr"] += row_data["ctr"]
            query_data[query_key]["position"] += row_data["position"]
            query_data[query_key]["count"] += 1
        
        # Aggregate by country (across all dates)
        if 'country' in dimensions and len(row_data["keys"]) > 1:
            country_key = row_data["keys"][1]  # Second dimension is typically country when date is first
            print(f"DEBUG: Aggregating country '{country_key}' with {row_data['clicks']} clicks")
            if country_key not in query_data:  # Reuse query_data structure for country aggregation
                query_data[country_key] = {
                    "clicks": 0,
                    "impressions": 0,
                    "ctr": 0,
                    "position": 0,
                    "count": 0
                }
            query_data[country_key]["clicks"] += row_data["clicks"]
            query_data[country_key]["impressions"] += row_data["impressions"]
            query_data[country_key]["ctr"] += row_data["ctr"]
            query_data[country_key]["position"] += row_data["position"]
            query_data[country_key]["count"] += 1
    
    # Calculate averages for daily data
    daily_chart_data = []
    for date, values in sorted(daily_data.items()):
        daily_chart_data.append({
            "date": date,
            "clicks": values["clicks"],
            "impressions": values["impressions"],
            "ctr": values["ctr"] / values["count"] if values["count"] > 0 else 0,
            "position": values["position"] / values["count"] if values["count"] > 0 else 0
        })
    
    # Calculate aggregated top queries (or countries if country dimension is used)
    top_queries_data = []
    for query, values in query_data.items():
        top_queries_data.append({
            "keys": [query],
            "clicks": values["clicks"],
            "impressions": values["impressions"],
            "ctr": values["ctr"] / values["count"] if values["count"] > 0 else 0,
            "position": values["position"] / values["count"] if values["count"] > 0 else 0
        })
    
    # Sort top queries by clicks and take top 10 (or more for countries)
    top_queries_data.sort(key=lambda x: x["clicks"], reverse=True)
    # For countries, return more results; for queries, keep top 10
    limit = 100 if 'country' in dimensions else 10
    top_queries_data = top_queries_data[:limit]
    
    # Calculate overall averages
    row_count = len(rows)
    data["totalClicks"] = total_clicks
    data["totalImpressions"] = total_impressions
    data["avgCtr"] = total_ctr / row_count if row_count > 0 else 0
    data["avgPosition"] = total_position / row_count if row_count > 0 else 0
    data["dailyData"] = daily_chart_data
    data["topQueries"] = top_queries_data
    
    print(f"DEBUG: Returning data with {len(top_queries_data)} top queries and {len(daily_chart_data)} daily data points")
    
    return data

def build_gsc_service(creds):
    """Собрать сервис Search Console из google-auth Credentials (веб-флоу)."""
    return build('searchconsole', 'v1', credentials=creds, cache_discovery=False)


def load_gsc_user_creds():
    """
    Загрузить сохранённый токен пользователя GSC (веб-флоу) и, при необходимости,
    обновить его. Возвращает валидные Credentials или None. Ничего не блокирует.
    """
    if not os.path.exists(GSC_TOKEN_FILE):
        return None
    try:
        creds = Credentials.from_authorized_user_file(GSC_TOKEN_FILE, GSC_SCOPES)
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(GoogleRequest())
            with open(GSC_TOKEN_FILE, 'w') as f:
                f.write(creds.to_json())
        return creds if creds and creds.valid else None
    except Exception as e:
        print(f"Error loading GSC user creds: {e}")
        return None


# Initialize GSC service
def init_gsc():
    global webmasters_service, verified_sites

    # 0) Миграция: если остался single-token файл прежнего флоу и в БД ещё нет
    #    аккаунтов — попробовать импортировать его как аккаунт (нужен email-scope;
    #    старый токен мог его не иметь — тогда просто пропускаем).
    try:
        if os.path.exists(GSC_TOKEN_FILE) and not gscm.list_accounts():
            creds = load_gsc_user_creds()
            if creds:
                try:
                    email = gscm.add_or_update_account(creds)
                    log.info("Migrated legacy token to account %s", email)
                    os.remove(GSC_TOKEN_FILE)
                except Exception as e:  # noqa: BLE001
                    log.warning("Legacy token migration skipped: %s", e)
    except Exception as e:  # noqa: BLE001
        log.warning("Legacy migration check failed: %s", e)

    # Основной путь: собрать реестр из всех аккаунтов в БД.
    try:
        gscm.rebuild_registry()
        verified_sites = gscm.all_site_urls()
        webmasters_service = (gscm.get_service_for_site(verified_sites[0])
                              if verified_sites else None)
        config = load_config()
        config['isAuthorized'] = gscm.has_any_account()
        save_config(config)
        log.info("GSC init: %s account(s), %s site(s)",
                 len(gscm.list_accounts()), len(verified_sites))
    except Exception as e:  # noqa: BLE001
        log.exception("init_gsc failed: %s", e)

# API Routes
@app.route('/api/sites', methods=['GET'])
def get_sites():
    """Список верифицированных сайтов, агрегированный по всем аккаунтам."""
    global verified_sites
    verified_sites = gscm.all_site_urls()
    return jsonify({"sites": verified_sites})


@app.route('/api/accounts', methods=['GET'])
def list_accounts_endpoint():
    """Подключённые Google-аккаунты (email, число сайтов, дата)."""
    return jsonify({"accounts": gscm.list_accounts()})


@app.route('/api/accounts/delete', methods=['POST'])
def delete_account_endpoint():
    """Отключить аккаунт по email (удаляет его сайты из БД)."""
    global verified_sites
    data = request.get_json(silent=True) or {}
    email = (data.get('email') or '').strip()
    if not email:
        return jsonify({"error": "email не задан"}), 400
    ok = gscm.delete_account(email)
    verified_sites = gscm.all_site_urls()
    if not ok:
        return jsonify({"error": f"Аккаунт {email} не найден"}), 404
    return jsonify({"success": True, "accounts": gscm.list_accounts()})


@app.route('/api/accounts/refresh', methods=['POST'])
def refresh_accounts_endpoint():
    """Перетянуть список сайтов по всем аккаунтам."""
    global verified_sites
    total = gscm.refresh_all_sites()
    verified_sites = gscm.all_site_urls()
    return jsonify({"success": True, "sites": total, "accounts": gscm.list_accounts()})


@app.route('/api/accounts/add-site', methods=['POST'])
def add_site_endpoint():
    """
    Добавить сайт в консоль аккаунта и попытаться верифицировать.
    {email, siteUrl, method?}. Для sc-domain:... используется DNS_TXT, иначе META.
    """
    global verified_sites
    data = request.get_json(silent=True) or {}
    email = (data.get('email') or '').strip()
    site_url = (data.get('siteUrl') or '').strip()
    method = (data.get('method') or '').strip() or None
    if not email or not site_url:
        return jsonify({"error": "email и siteUrl обязательны"}), 400
    result = seo_siteverify.add_and_verify(email, site_url, method)
    verified_sites = gscm.all_site_urls()
    return jsonify(result)


# ─── Главный дашборд (агрегация по всем сайтам, ленивая подгрузка) ──────────────
@app.route('/api/dashboard/summary', methods=['GET'])
def dashboard_summary():
    """
    Готовый кэш сводных метрик по всем сайтам за период + статус фонового прогона.
    Если кэш пуст и обновление не идёт — сразу запускаем фоновое обновление.
    """
    period = int(request.args.get('period', 28))
    rows = seo_dashboard.get_summary(period)
    job = seo_dashboard.job_status()
    if not rows and not job.get('running'):
        seo_dashboard.refresh_all(period)
        job = seo_dashboard.job_status()
    return jsonify({"period": period, "sites": rows, "job": job})


@app.route('/api/dashboard/refresh', methods=['POST'])
def dashboard_refresh():
    """Запустить фоновое обновление сводных метрик."""
    period = int((request.get_json(silent=True) or {}).get('period',
                 request.args.get('period', 28)))
    started = seo_dashboard.refresh_all(period)
    return jsonify({"started": started, "job": seo_dashboard.job_status()})


@app.route('/api/dashboard/status', methods=['GET'])
def dashboard_status():
    """Статус фонового прогона (для прогресс-бара)."""
    return jsonify(seo_dashboard.job_status())


# ─── Заметки на графике (беклинки/работы/изменения) ────────────────────────────
@app.route('/api/annotations', methods=['GET'])
def list_annotations():
    """Заметки сайта. Опционально фильтр по url (плюс события по всему сайту)."""
    from db import Annotation
    site_url = request.args.get('siteUrl', '')
    url = request.args.get('url')
    if not site_url:
        return jsonify({"error": "siteUrl обязателен"}), 400
    with seo_db.session_scope() as s:
        q = s.query(Annotation).filter_by(site_url=site_url)
        if url:
            # события конкретной страницы + события по всему сайту (url IS NULL)
            q = q.filter((Annotation.url == url) | (Annotation.url.is_(None)))
        rows = q.order_by(Annotation.date.asc()).all()
        out = [{
            "id": a.id, "site_url": a.site_url, "url": a.url, "date": a.date,
            "text": a.text, "category": a.category,
        } for a in rows]
    return jsonify({"annotations": out})


@app.route('/api/annotations', methods=['POST'])
def create_annotation():
    """Создать заметку: {siteUrl, date, text, category?, url?}."""
    from db import Annotation
    data = request.get_json(silent=True) or {}
    site_url = (data.get('siteUrl') or '').strip()
    date_str = (data.get('date') or '').strip()
    text = (data.get('text') or '').strip()
    if not all([site_url, date_str, text]):
        return jsonify({"error": "siteUrl, date, text обязательны"}), 400
    category = (data.get('category') or 'note').strip()
    url = (data.get('url') or '').strip() or None
    with seo_db.session_scope() as s:
        a = Annotation(site_url=site_url, url=url, date=date_str, text=text, category=category)
        s.add(a); s.flush()
        new_id = a.id
    log.info("Annotation created id=%s site=%s date=%s cat=%s", new_id, site_url, date_str, category)
    return jsonify({"success": True, "id": new_id})


@app.route('/api/annotations/delete', methods=['POST'])
def delete_annotation():
    """Удалить заметку по id."""
    from db import Annotation
    data = request.get_json(silent=True) or {}
    ann_id = data.get('id')
    if ann_id is None:
        return jsonify({"error": "id обязателен"}), 400
    with seo_db.session_scope() as s:
        a = s.query(Annotation).filter_by(id=ann_id).first()
        if not a:
            return jsonify({"error": "Заметка не найдена"}), 404
        s.delete(a)
    return jsonify({"success": True})


# ─── Статистика по страницам (период + сравнение было/стало) ───────────────────
@app.route('/api/pages/summary', methods=['GET'])
def pages_summary():
    """
    По каждой странице сайта: клики/показы/CTR/позиция за период и за предыдущий
    (для сравнения было/стало). Кэшируется в Redis.
    """
    site_url = request.args.get('siteUrl', '')
    period = int(request.args.get('period', 28))
    limit = int(request.args.get('limit', 200))
    if not site_url:
        return jsonify({"error": "siteUrl обязателен"}), 400

    service = gscm.get_service_for_site(site_url)
    if not service:
        return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

    cache_key = f"pages:{site_url}:{period}:{limit}"
    cached = seo_cache.cache_get_json(cache_key)
    if cached is not None:
        return jsonify(cached)

    start, end, prev_start, prev_end = seo_dashboard.period_ranges(period)

    def by_page(s_date, e_date):
        rows = get_data(service, site_url, s_date, e_date, dimensions=['page'], row_limit=25000)
        d = {}
        for r in rows:
            keys = r.get('keys', [])
            if not keys:
                continue
            d[keys[0]] = {
                "clicks": r.get('clicks', 0), "impressions": r.get('impressions', 0),
                "ctr": r.get('ctr', 0), "position": r.get('position', 0),
            }
        return d

    cur = by_page(start, end)
    prev = by_page(prev_start, prev_end)

    pages = []
    for url, c in cur.items():
        p = prev.get(url, {"clicks": 0, "impressions": 0, "ctr": 0, "position": 0})
        pages.append({
            "url": url,
            "clicks": c["clicks"], "impressions": c["impressions"],
            "ctr": c["ctr"], "position": c["position"],
            "prev_clicks": p["clicks"], "prev_impressions": p["impressions"],
            "prev_ctr": p["ctr"], "prev_position": p["position"],
        })
    pages.sort(key=lambda x: x["clicks"], reverse=True)
    result = {"site_url": site_url, "period": period, "pages": pages[:limit]}
    seo_cache.cache_set_json(cache_key, result)
    return jsonify(result)


@app.route('/api/page/details', methods=['GET'])
def page_details():
    """
    Детальный разбор одного URL: дневной ряд кликов/показов + топ-запросы.
    """
    site_url = request.args.get('siteUrl', '')
    url = request.args.get('url', '')
    period = int(request.args.get('period', 28))
    if not site_url or not url:
        return jsonify({"error": "siteUrl и url обязательны"}), 400

    service = gscm.get_service_for_site(site_url)
    if not service:
        return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

    start, end, _, _ = seo_dashboard.period_ranges(period)
    page_filter = [{"filters": [{"dimension": "page", "operator": "equals", "expression": url}]}]

    ts_rows = get_data(service, site_url, start, end, dimensions=['date'],
                       dimension_filters=page_filter, row_limit=25000)
    timeseries = [{
        "date": r.get('keys', ['?'])[0],
        "clicks": r.get('clicks', 0), "impressions": r.get('impressions', 0),
        "ctr": r.get('ctr', 0), "position": r.get('position', 0),
    } for r in ts_rows]
    timeseries.sort(key=lambda x: x["date"])

    q_rows = get_data(service, site_url, start, end, dimensions=['query'],
                      dimension_filters=page_filter, row_limit=100)
    queries = [{
        "query": r.get('keys', ['?'])[0],
        "clicks": r.get('clicks', 0), "impressions": r.get('impressions', 0),
        "ctr": r.get('ctr', 0), "position": r.get('position', 0),
    } for r in q_rows]
    queries.sort(key=lambda x: x["clicks"], reverse=True)

    return jsonify({"url": url, "period": period,
                    "timeseries": timeseries, "queries": queries[:25]})


# ─── Мониторинг беклинков (404, наличие ссылки, XMLRIVER, 2index) ───────────────
def _backlink_dict(b):
    return {
        "id": b.id, "site_url": b.site_url,
        "source_url": b.source_url, "target_url": b.target_url,
        "http_status": b.http_status, "link_present": b.link_present,
        "index_status": b.index_status, "index_count": b.index_count,
        "submitted": bool(b.submitted),
        "submitted_at": b.submitted_at.isoformat() if b.submitted_at else None,
        "last_checked": b.last_checked.isoformat() if b.last_checked else None,
    }


@app.route('/api/backlinks', methods=['GET'])
def list_backlinks():
    """Список беклинков. Опционально фильтр по siteUrl."""
    from db import Backlink
    site_url = request.args.get('siteUrl')
    with seo_db.session_scope() as s:
        q = s.query(Backlink)
        if site_url:
            q = q.filter_by(site_url=site_url)
        rows = [_backlink_dict(b) for b in q.order_by(Backlink.created_at.desc()).all()]
    return jsonify({"backlinks": rows, "job": seo_backlinks.job_status()})


@app.route('/api/backlinks', methods=['POST'])
def add_backlinks():
    """
    Добавить беклинк(и). Форматы:
      {source_url, target_url, site_url?}  — один
      {items: [{source_url, target_url, site_url?}, ...]}  — пачкой
    """
    from db import Backlink
    data = request.get_json(silent=True) or {}
    items = data.get('items')
    if not items:
        items = [{"source_url": data.get('source_url'),
                  "target_url": data.get('target_url'),
                  "site_url": data.get('site_url')}]
    added, skipped = 0, 0
    with seo_db.session_scope() as s:
        for it in items:
            src = (it.get('source_url') or '').strip()
            tgt = (it.get('target_url') or '').strip()
            if not src or not tgt:
                skipped += 1
                continue
            exists = s.query(Backlink).filter_by(source_url=src, target_url=tgt).first()
            if exists:
                skipped += 1
                continue
            s.add(Backlink(source_url=src, target_url=tgt,
                           site_url=(it.get('site_url') or '').strip() or None))
            added += 1
    log.info("Backlinks added=%s skipped=%s", added, skipped)
    return jsonify({"success": True, "added": added, "skipped": skipped})


@app.route('/api/backlinks/delete', methods=['POST'])
def delete_backlinks():
    """Удалить беклинк(и): {id} или {ids:[...]}"""
    from db import Backlink
    data = request.get_json(silent=True) or {}
    ids = data.get('ids') or ([data['id']] if data.get('id') is not None else [])
    if not ids:
        return jsonify({"error": "id/ids обязателен"}), 400
    with seo_db.session_scope() as s:
        s.query(Backlink).filter(Backlink.id.in_(ids)).delete(synchronize_session=False)
    return jsonify({"success": True, "deleted": len(ids)})


@app.route('/api/backlinks/check', methods=['POST'])
def check_backlinks():
    """Фоновая проверка 404 + наличия ссылки для выбранных (или всех) беклинков."""
    data = request.get_json(silent=True) or {}
    ids = data.get('ids')
    started = seo_backlinks.start_check(ids)
    return jsonify({"started": started, "job": seo_backlinks.job_status()})


@app.route('/api/backlinks/index-check', methods=['POST'])
def index_check_backlinks():
    """Фоновая проверка индексации доноров через XMLRIVER."""
    data = request.get_json(silent=True) or {}
    ids = data.get('ids')
    config = load_config()
    user = config.get('xmlriverUser', '')
    key = config.get('xmlriverKey', '')
    if not user or not key:
        return jsonify({"error": "XMLRIVER не настроен (укажите user и key в Настройках)"}), 400
    started = seo_backlinks.start_index_check(ids, user, key)
    return jsonify({"started": started, "job": seo_backlinks.job_status()})


@app.route('/api/backlinks/submit-index', methods=['POST'])
def submit_index_backlinks():
    """Отправить доноров на индексацию через 2index Ninja."""
    data = request.get_json(silent=True) or {}
    ids = data.get('ids')
    config = load_config()
    key = config.get('twoindexKey', '')
    if not key:
        return jsonify({"error": "2index не настроен (укажите ключ в Настройках)"}), 400
    started = seo_backlinks.start_submit(ids, key)
    return jsonify({"started": started, "job": seo_backlinks.job_status()})


@app.route('/api/backlinks/status', methods=['GET'])
def backlinks_status():
    return jsonify(seo_backlinks.job_status())


# ─── Индексация (sitemap → страницы → статус/переобход/2index) ──────────────────
def _index_page_dict(p):
    return {
        "id": p.id, "site_url": p.site_url, "url": p.url,
        "coverage_state": p.coverage_state, "verdict": p.verdict,
        "last_crawl_time": p.last_crawl_time,
        "index_status": p.index_status, "index_count": p.index_count,
        "submitted": bool(p.submitted),
        "submitted_at": p.submitted_at.isoformat() if p.submitted_at else None,
        "last_checked": p.last_checked.isoformat() if p.last_checked else None,
    }


@app.route('/api/index/pages', methods=['GET'])
def index_pages():
    """Список страниц сайта для раздела индексации."""
    from db import IndexPage
    site_url = request.args.get('siteUrl')
    if not site_url:
        return jsonify({"error": "siteUrl обязателен"}), 400
    with seo_db.session_scope() as s:
        rows = [_index_page_dict(p) for p in
                s.query(IndexPage).filter_by(site_url=site_url)
                 .order_by(IndexPage.created_at.desc()).all()]
    return jsonify({"pages": rows, "job": seo_indexation.job_status()})


@app.route('/api/index/crawl', methods=['POST'])
def index_crawl():
    """Обойти sitemap и подтянуть реальные страницы. {siteUrl, sitemapUrl?}"""
    data = request.get_json(silent=True) or {}
    site_url = (data.get('siteUrl') or '').strip()
    sitemap_url = (data.get('sitemapUrl') or '').strip() or None
    if not site_url:
        return jsonify({"error": "siteUrl обязателен"}), 400
    started = seo_indexation.start_crawl(site_url, sitemap_url)
    return jsonify({"started": started, "job": seo_indexation.job_status()})


@app.route('/api/index/inspect', methods=['POST'])
def index_inspect():
    """Статус индексации из Google (URL Inspection) для выбранных/всех страниц."""
    data = request.get_json(silent=True) or {}
    started = seo_indexation.start_inspect(data.get('siteUrl'), data.get('ids'))
    return jsonify({"started": started, "job": seo_indexation.job_status()})


@app.route('/api/index/xmlriver', methods=['POST'])
def index_xmlriver():
    """Проверка индексации через XMLRIVER для выбранных/всех страниц."""
    data = request.get_json(silent=True) or {}
    config = load_config()
    user = config.get('xmlriverUser', '')
    key = config.get('xmlriverKey', '')
    if not user or not key:
        return jsonify({"error": "XMLRIVER не настроен (user/key в Настройках)"}), 400
    started = seo_indexation.start_xmlriver(data.get('siteUrl'), data.get('ids'), user, key)
    return jsonify({"started": started, "job": seo_indexation.job_status()})


@app.route('/api/index/submit', methods=['POST'])
def index_submit():
    """Отправить выбранные/все страницы на индекс через 2index."""
    data = request.get_json(silent=True) or {}
    config = load_config()
    key = config.get('twoindexKey', '')
    if not key:
        return jsonify({"error": "2index не настроен (ключ в Настройках)"}), 400
    started = seo_indexation.start_submit(data.get('siteUrl'), data.get('ids'), key)
    return jsonify({"started": started, "job": seo_indexation.job_status()})


@app.route('/api/index/delete', methods=['POST'])
def index_delete():
    """Удалить страницы: {ids:[...]} или {siteUrl} (все по сайту)."""
    from db import IndexPage
    data = request.get_json(silent=True) or {}
    ids = data.get('ids')
    site_url = data.get('siteUrl')
    with seo_db.session_scope() as s:
        q = s.query(IndexPage)
        if ids:
            q = q.filter(IndexPage.id.in_(ids))
        elif site_url:
            q = q.filter_by(site_url=site_url)
        else:
            return jsonify({"error": "ids или siteUrl обязателен"}), 400
        deleted = q.delete(synchronize_session=False)
    return jsonify({"success": True, "deleted": deleted})


@app.route('/api/index/status', methods=['GET'])
def index_status():
    return jsonify(seo_indexation.job_status())


# ─── Автоматизация (планировщик фоновых задач) ─────────────────────────────────
@app.route('/api/automation', methods=['GET'])
def get_automation():
    """Текущий конфиг автоматизации + отметки последних запусков."""
    return jsonify(seo_scheduler.get_status())


@app.route('/api/automation', methods=['POST'])
def save_automation():
    """Сохранить конфиг автоматизации (в dashboard_config.json) и применить."""
    data = request.get_json(silent=True) or {}
    config = load_config()
    autom = dict(config.get('automation') or {})
    for k in ('enabled', 'dashboardRefreshHours', 'sitesRefreshHours', 'dashboardPeriod'):
        if k in data:
            autom[k] = data[k]
    config['automation'] = autom
    save_config(config)
    seo_scheduler.configure(autom)
    log.info("Automation settings saved: %s", autom)
    return jsonify({"success": True, **seo_scheduler.get_status()})


@app.route('/api/automation/run-now', methods=['POST'])
def automation_run_now():
    """Запустить задачу автоматизации немедленно: {task: 'dashboard'|'sites'}."""
    data = request.get_json(silent=True) or {}
    task = (data.get('task') or '').strip()
    try:
        seo_scheduler.run_now(task)
        return jsonify({"success": True, **seo_scheduler.get_status()})
    except Exception as e:  # noqa: BLE001
        return jsonify({"error": str(e)}), 400

@app.route('/api/data', methods=['GET'])
def get_gsc_data():
    """Get GSC analytics data"""
    global webmasters_service
    
    site_url = request.args.get('siteUrl')
    start_date = request.args.get('startDate')
    end_date = request.args.get('endDate')
    dimensions = request.args.get('dimensions', 'date').split(',')
    device = request.args.get('device', 'all')
    
    # Build dimension filters
    dimension_filters = None
    filter_dimension = request.args.get('filterDimension')
    filter_type = request.args.get('filterType')
    filter_value = request.args.get('filterValue')
    
    # Create filter groups if filters are provided
    if device != 'all' or (filter_dimension and filter_value):
        filter_groups = [{
            'groupType': 'and',
            'filters': []
        }]
        
        # Add device filter if not 'all'
        if device != 'all':
            device_map = {
                'desktop': 'DESKTOP',
                'mobile': 'MOBILE',
                'tablet': 'TABLET'
            }
            device_value = device_map.get(device.lower(), device.upper())
            filter_groups[0]['filters'].append({
                'dimension': 'device',
                'operator': 'equals',
                'expression': device_value
            })
        
        # Add advanced filter if provided
        if filter_dimension and filter_value:
            operator_map = {
                'equals': 'equals',
                'notEquals': 'notEquals',
                'contains': 'contains',
                'notContains': 'notContains',
                'greaterThan': 'greaterThan',
                'smallerThan': 'smallerThan'
            }
            operator = operator_map.get(filter_type, 'equals')
            
            # Handle numeric comparisons
            if operator in ['greaterThan', 'smallerThan']:
                # For numeric comparisons, we need to handle them differently
                # GSC API doesn't support direct numeric comparisons on all dimensions
                # So we'll use 'equals' for now and filter client-side if needed
                operator = 'equals'
            
            filter_groups[0]['filters'].append({
                'dimension': filter_dimension,
                'operator': operator,
                'expression': filter_value
            })
        
        dimension_filters = filter_groups
    
    if not all([site_url, start_date, end_date]):
        return jsonify({"error": "Missing required parameters"}), 400

    service = gscm.get_service_for_site(site_url)
    if not service:
        return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

    # Кэш ответа в Redis (ленивая подгрузка/ускорение под нагрузкой).
    cache_key = "gscdata:" + ":".join(str(x) for x in [
        site_url, start_date, end_date, ",".join(dimensions), device,
        filter_dimension, filter_type, filter_value])
    cached = seo_cache.cache_get_json(cache_key)
    if cached is not None:
        return jsonify(cached)

    try:
        raw_data = get_data(
            service,
            site_url,
            start_date,
            end_date,
            dimensions=dimensions,
            dimension_filters=dimension_filters
        )

        cleaned_data = clean_and_export_data(raw_data, dimensions)
        seo_cache.cache_set_json(cache_key, cleaned_data)
        return jsonify(cleaned_data)

    except Exception as e:
        log.exception("Error in get_gsc_data (%s): %s", site_url, e)
        return jsonify({"error": str(e)}), 500

@app.route('/api/top-queries', methods=['GET'])
def get_top_queries():
    """Get top performing queries"""
    site_url = request.args.get('siteUrl')
    start_date = request.args.get('startDate')
    end_date = request.args.get('endDate')
    limit = int(request.args.get('limit', 10))

    if not all([site_url, start_date, end_date]):
        return jsonify({"error": "Missing required parameters"}), 400

    service = gscm.get_service_for_site(site_url)
    if not service:
        return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

    try:
        raw_data = get_data(
            service,
            site_url,
            start_date, 
            end_date, 
            dimensions=['query'],
            row_limit=limit
        )
        
        # Sort by clicks
        sorted_data = sorted(raw_data, key=lambda x: x.get('clicks', 0), reverse=True)
        
        return jsonify({"queries": sorted_data[:limit]})
        
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/api/status', methods=['GET'])
def get_status():
    """Get API status (плюс здоровье БД/кэша и число подключённых аккаунтов)."""
    global webmasters_service, verified_sites

    accounts_count = None
    try:
        from db import Account
        with seo_db.session_scope() as s:
            accounts_count = s.query(Account).count()
    except Exception as e:  # noqa: BLE001
        log.warning("status: accounts count failed: %s", e)

    return jsonify({
        "status": "running",
        "gsc_connected": webmasters_service is not None,
        "sites_count": len(verified_sites),
        "db": seo_db.db_healthy(),
        "cache": seo_cache.cache_healthy(),   # true / false / null(=отключён)
        "accounts": accounts_count
    })

@app.route('/api/openai/validate', methods=['POST'])
def validate_openai_key():
    """
    Проверка API-ключа OpenAI и доступности выбранной модели.
    Тело (опционально): {"apiKey": "...", "model": "..."}.
    Если поля не переданы — берутся из сохранённого конфига.
    """
    try:
        data = request.get_json(silent=True) or {}
    except Exception:
        data = {}

    config = load_config()
    api_key = (data.get('apiKey') or '').strip() or config.get('openaiApiKey', '')
    model = (data.get('model') or '').strip() or config.get('openaiModel', '') or DEFAULT_OPENAI_MODEL

    if not api_key:
        return jsonify({"valid": False, "message": "API-ключ не задан."}), 400

    try:
        client = OpenAI(api_key=api_key)
        # 1) Ключ рабочий? models.list() требует валидной аутентификации.
        models = client.models.list()
        available_ids = {m.id for m in getattr(models, 'data', [])}

        # 2) Доступна ли выбранная модель этому ключу?
        model_available = model in available_ids
        if not model_available:
            # Часть моделей может не попадать в общий список — проверяем точечно.
            try:
                client.models.retrieve(model)
                model_available = True
            except Exception:
                model_available = False

        if model_available:
            message = f"Ключ рабочий, модель «{model}» доступна."
        else:
            message = (f"Ключ рабочий, но модель «{model}» недоступна для этого ключа. "
                       f"Выберите другую модель.")

        return jsonify({
            "valid": True,
            "model": model,
            "model_available": model_available,
            "message": message
        })
    except Exception as e:
        msg = str(e)
        low = msg.lower()
        if any(s in low for s in ('auth', 'invalid', '401', 'incorrect api key', 'api key')):
            friendly = "Неверный API-ключ OpenAI."
        elif any(s in low for s in ('quota', 'insufficient', 'billing', '429')):
            friendly = "Ключ распознан, но превышена квота или недостаточно средств на балансе OpenAI."
        else:
            friendly = f"Не удалось проверить ключ: {msg}"
        return jsonify({"valid": False, "message": friendly})

def get_gpt_insights(content, analysis_type="general"):
    """
    Generate insights from OpenAI GPT model based on the provided content.
    
    Args:
        content (str): The data to analyze
        analysis_type (str): Type of analysis - "daily" for chart data, "queries" for table data
    
    Returns:
        str: GPT insights
    """
    global openai_client
    
    # Initialize OpenAI client if not already initialized
    if openai_client is None:
        initialize_openai_client()
    
    if openai_client is None:
        return "OpenAI API key not configured. Please set your API key in Settings."
    
    try:
        if analysis_type == "daily":
            system_content = (
                "You are an SEO expert analyzing daily Google Search Console performance data. "
                "Your task is to analyze daily traffic patterns and provide clear, data-driven insights. "
                "Focus on:\n\n"
                "**Daily Traffic Trends:**\n"
                "- **Clicks**: Identify daily patterns, spikes, drops, and overall trends\n"
                "- **Impressions**: Analyze impression trends and visibility changes\n"
                "- **CTR**: Examine click-through rate patterns and correlations\n"
                "- **Position**: Track ranking changes over time\n\n"
                "**Key Observations:**\n"
                "- Identify the best and worst performing days\n"
                "- Note any weekly patterns or seasonal trends\n"
                "- Highlight significant changes or anomalies\n"
                "- Suggest potential reasons for performance changes\n\n"
                "Keep insights concise and actionable. Use percentages and specific numbers when relevant."
            )
        else:  # queries analysis
            system_content = (
                "You are an SEO expert analyzing Google Search Console query performance data. "
                "Your task is to analyze search query performance and provide clear, data-driven insights. "
                "Focus on:\n\n"
                "**Query Performance:**\n"
                "- **Top Performers**: Identify highest-traffic queries and their characteristics\n"
                "- **CTR Analysis**: Highlight queries with exceptional or poor CTR\n"
                "- **Position Opportunities**: Find queries with good impressions but poor positions\n"
                "- **Content Gaps**: Identify potential content optimization opportunities\n\n"
                "**Key Recommendations:**\n"
                "- Suggest which queries to optimize for better rankings\n"
                "- Recommend content improvements based on query intent\n"
                "- Identify low-hanging fruit for quick wins\n"
                "- Highlight successful query patterns to replicate\n\n"
                "Keep insights concise and actionable. Focus on specific opportunities and improvements."
            )

        chat_completion = openai_client.chat.completions.create(
            messages=[
                {
                    "role": "system",
                    "content": system_content
                },
                {
                    "role": "user",
                    "content": content
                }
            ],
            model=get_openai_model()
        )
        
        response_message = chat_completion.choices[0].message.content
        return response_message
        
    except Exception as e:
        print(f"Error getting GPT insights: {str(e)}")
        return f"Error generating insights: {str(e)}"

@app.route('/api/insights/daily', methods=['POST'])
def get_daily_insights():
    """Get GPT insights for daily chart data"""
    try:
        data = request.get_json()
        daily_data = data.get('dailyData', [])
        
        if not daily_data:
            return jsonify({"error": "No daily data provided"}), 400
        
        # Format the data for GPT analysis
        content = f"Analyze this daily Google Search Console performance data:\n\n"
        content += "Date | Clicks | Impressions | CTR | Position\n"
        content += "-" * 50 + "\n"
        
        for day in daily_data:
            date = day.get('date', 'N/A')
            clicks = day.get('clicks', 0)
            impressions = day.get('impressions', 0)
            ctr = round((day.get('ctr', 0) * 100), 2)
            position = round(day.get('position', 0), 1)
            content += f"{date} | {clicks} | {impressions} | {ctr}% | {position}\n"
        
        content += f"\n\nTotal data points: {len(daily_data)} days"
        content += f"\nDate range: {daily_data[0].get('date', 'N/A')} to {daily_data[-1].get('date', 'N/A')}"
        
        insights = get_gpt_insights(content, "daily")
        
        return jsonify({"insights": insights})
        
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/api/insights/queries', methods=['POST'])
def get_query_insights():
    """Get GPT insights for filtered query data"""
    try:
        data = request.get_json()
        queries = data.get('queries', [])
        
        if not queries:
            return jsonify({"error": "No query data provided"}), 400
        
        # Format the data for GPT analysis
        content = f"Analyze this Google Search Console query performance data:\n\n"
        content += "Rank | Query | Clicks | Impressions | CTR | Position\n"
        content += "-" * 70 + "\n"
        
        for i, query in enumerate(queries, 1):
            query_text = query.get('keys', ['Unknown'])[0]
            clicks = query.get('clicks', 0)
            impressions = query.get('impressions', 0)
            ctr = round((query.get('ctr', 0) * 100), 2)
            position = round(query.get('position', 0), 1)
            content += f"#{i} | {query_text} | {clicks} | {impressions} | {ctr}% | {position}\n"
        
        content += f"\n\nTotal queries analyzed: {len(queries)}"
        
        # Add summary statistics
        total_clicks = sum(q.get('clicks', 0) for q in queries)
        total_impressions = sum(q.get('impressions', 0) for q in queries)
        avg_ctr = sum(q.get('ctr', 0) for q in queries) / len(queries) * 100 if queries else 0
        avg_position = sum(q.get('position', 0) for q in queries) / len(queries) if queries else 0
        
        content += f"\nSummary: {total_clicks} total clicks, {total_impressions} total impressions"
        content += f"\nAverage CTR: {avg_ctr:.2f}%, Average Position: {avg_position:.1f}"
        
        insights = get_gpt_insights(content, "queries")
        
        return jsonify({"insights": insights})
        
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/api/settings', methods=['GET'])
def get_settings():
    """Get current settings"""
    config = load_config()
    return jsonify({
        "openaiApiKey": config.get('openaiApiKey', ''),
        "openaiModel": config.get('openaiModel', '') or DEFAULT_OPENAI_MODEL,
        "credentialsPath": config.get('credentialsPath', ''),
        "hasClientSecret": bool(config.get('credentialsPath')) and os.path.exists(config.get('credentialsPath', '')),
        "trendsCredentialsPath": config.get('trendsCredentialsPath', ''),
        "isAuthorized": config.get('isAuthorized', False),
        "overviewSites": config.get('overviewSites', []),
        "xmlriverUser": config.get('xmlriverUser', ''),
        "xmlriverKey": config.get('xmlriverKey', ''),
        "twoindexKey": config.get('twoindexKey', ''),
    })

@app.route('/api/settings', methods=['POST'])
def save_settings():
    """Save settings"""
    try:
        data = request.get_json()
        config = load_config()
        
        # Update config with new values
        if 'openaiApiKey' in data:
            config['openaiApiKey'] = data['openaiApiKey']
            # Reinitialize OpenAI client with new key
            initialize_openai_client()

        if 'openaiModel' in data:
            config['openaiModel'] = data['openaiModel'] or DEFAULT_OPENAI_MODEL

        if 'credentialsPath' in data:
            config['credentialsPath'] = data['credentialsPath']
            config['isAuthorized'] = False

        if 'trendsCredentialsPath' in data:
            config['trendsCredentialsPath'] = data['trendsCredentialsPath']
        
        if 'overviewSites' in data:
            # Validate that we don't have more than 6 sites
            overview_sites = data['overviewSites']
            if len(overview_sites) > 6:
                return jsonify({"error": "Maximum 6 sites allowed for overview"}), 400
            config['overviewSites'] = overview_sites

        for key in ('xmlriverUser', 'xmlriverKey', 'twoindexKey'):
            if key in data:
                config[key] = data[key]

        # Save config
        if save_config(config):
            return jsonify({
                "success": True,
                "isAuthorized": config.get('isAuthorized', False),
                "openaiApiKey": config.get('openaiApiKey', ''),
                "openaiModel": config.get('openaiModel', '') or DEFAULT_OPENAI_MODEL,
                "credentialsPath": config.get('credentialsPath', ''),
                "trendsCredentialsPath": config.get('trendsCredentialsPath', ''),
                "overviewSites": config.get('overviewSites', []),
                "xmlriverUser": config.get('xmlriverUser', ''),
                "xmlriverKey": config.get('xmlriverKey', ''),
                "twoindexKey": config.get('twoindexKey', ''),
            })
        else:
            return jsonify({"error": "Failed to save settings"}), 500
            
    except Exception as e:
        return jsonify({"error": str(e)}), 500

def _oauth_return(ok, message):
    """Вернуть пользователя на страницу настроек фронта с результатом OAuth."""
    status = 'success' if ok else 'error'
    return redirect(f"{FRONTEND_URL}/settings?gscAuth={status}&msg={quote(message)}")


@app.route('/api/gsc/client-secret', methods=['POST'])
def save_gsc_client_secret():
    """
    Принять СОДЕРЖИМОЕ client_secret.json прямо из админки (вставкой) и сохранить
    его в том /data/seo. Больше не нужно класть файл руками через терминал —
    всё делается в интерфейсе. После сохранения путь прописывается в конфиг, и
    можно сразу нажимать «Добавить аккаунт Google».
    """
    data = request.get_json(silent=True) or {}
    raw = data.get('clientSecret', data.get('content', ''))

    # Допускаем как строку с JSON, так и уже разобранный объект.
    if isinstance(raw, dict):
        parsed = raw
    else:
        raw = (raw or '').strip()
        if not raw:
            return jsonify({"error": "Пустое содержимое client_secret.json"}), 400
        try:
            parsed = json.loads(raw)
        except Exception as e:  # noqa: BLE001
            return jsonify({"error": f"Это не похоже на JSON: {e}"}), 400

    # У валидного client_secret.json корневой ключ — 'web' или 'installed'.
    root = None
    client_type = None
    for key in ('web', 'installed'):
        section = parsed.get(key) if isinstance(parsed, dict) else None
        if isinstance(section, dict):
            root, client_type = section, key
            break
    if not root or not root.get('client_id'):
        return jsonify({
            "error": "Не похоже на client_secret.json: нет секции \"web\"/\"installed\" "
                     "с полем client_id. Скачайте JSON OAuth-клиента в Google Cloud Console."
        }), 400

    dest = os.path.join(DATA_DIR, 'client_secret.json')
    try:
        with open(dest, 'w', encoding='utf-8') as f:
            json.dump(parsed, f, ensure_ascii=False, indent=2)
    except Exception as e:  # noqa: BLE001
        return jsonify({"error": f"Не удалось сохранить файл: {e}"}), 500

    config = load_config()
    config['credentialsPath'] = dest
    config['isAuthorized'] = False
    save_config(config)

    redirect_uris = root.get('redirect_uris') or []
    return jsonify({
        "success": True,
        "credentialsPath": dest,
        "clientType": client_type,
        "redirectUri": OAUTH_REDIRECT_URI,
        # Для типа «Веб-приложение» redirect_uri должен быть в списке разрешённых.
        "redirectUriRegistered": (client_type != 'web') or (OAUTH_REDIRECT_URI in redirect_uris),
        "message": "client_secret.json сохранён. Теперь нажмите «Добавить аккаунт Google».",
    })


@app.route('/api/oauth/google/start', methods=['GET', 'POST'])
def oauth_google_start():
    """
    Начать веб-авторизацию GSC. Возвращает {authUrl} — ссылку на согласие Google.
    Фронт перенаправляет туда браузер. Работает в Docker (не нужен браузер на
    сервере, в отличие от старого tools.run_flow).
    """
    config = load_config()
    creds_path = config.get('credentialsPath', '')
    if request.method == 'POST':
        data = request.get_json(silent=True) or {}
        creds_path = (data.get('credentialsPath') or '').strip() or creds_path

    if not creds_path or not os.path.exists(creds_path):
        return jsonify({"error": f"Файл client_secret.json не найден: {creds_path or '(путь не задан)'}"}), 400

    # Запомним путь в конфиге, чтобы callback его нашёл.
    if config.get('credentialsPath') != creds_path:
        config['credentialsPath'] = creds_path
        save_config(config)

    try:
        flow = Flow.from_client_secrets_file(
            creds_path, scopes=GSC_SCOPES, redirect_uri=OAUTH_REDIRECT_URI)
        # select_account — всегда показывать выбор аккаунта Google, чтобы можно
        # было подключить несколько разных Gmail одним и тем же client_secret.json.
        auth_url, state = flow.authorization_url(
            access_type='offline', include_granted_scopes='true',
            prompt='select_account consent')
        _oauth_flows[state] = creds_path
        return jsonify({"authUrl": auth_url})
    except Exception as e:
        return jsonify({"error": f"Не удалось начать авторизацию: {e}"}), 500


@app.route('/api/oauth/google/callback', methods=['GET'])
def oauth_google_callback():
    """
    Google возвращает пользователя сюда с code. Меняем code на токен, сохраняем
    его в /data/seo и инициализируем сервис GSC. Затем — назад на фронт.
    """
    global webmasters_service, verified_sites

    err = request.args.get('error')
    if err:
        return _oauth_return(False, f"Google вернул ошибку: {err}")

    state = request.args.get('state', '')
    creds_path = _oauth_flows.pop(state, None) or load_config().get('credentialsPath', '')
    if not creds_path or not os.path.exists(creds_path):
        return _oauth_return(False, "Не найден client_secret.json или истекла сессия авторизации. Повторите.")

    try:
        flow = Flow.from_client_secrets_file(
            creds_path, scopes=GSC_SCOPES, redirect_uri=OAUTH_REDIRECT_URI, state=state)
        flow.fetch_token(authorization_response=request.url)
        creds = flow.credentials

        # Мультиаккаунт: сохраняем токен в БД под email аккаунта и тянем его сайты.
        email = gscm.add_or_update_account(creds)
        verified_sites = gscm.all_site_urls()
        webmasters_service = (gscm.get_service_for_site(verified_sites[0])
                              if verified_sites else None)

        config = load_config()
        config['isAuthorized'] = gscm.has_any_account()
        save_config(config)

        log.info("OAuth success for %s (%s sites total)", email, len(verified_sites))
        return _oauth_return(
            True, f"Аккаунт {email} подключён. Всего сайтов по всем аккаунтам: {len(verified_sites)}.")
    except Exception as e:
        log.exception("OAuth callback failed: %s", e)
        return _oauth_return(False, f"Не удалось завершить авторизацию: {e}")


@app.route('/api/authorize', methods=['POST'])
def authorize():
    """Authorize Google Search Console credentials"""
    global webmasters_service, verified_sites
    
    try:
        data = request.get_json()
        creds_path = data.get('credentialsPath', '')
        
        if not creds_path:
            config = load_config()
            creds_path = config.get('credentialsPath', '')
        
        if not creds_path:
            return jsonify({"error": "No credentials path provided"}), 400
        
        if not os.path.exists(creds_path):
            return jsonify({"error": f"Credentials file not found at {creds_path}"}), 400
        
        # Authorize credentials
        webmasters_service = authorize_creds(creds_path)
        
        if webmasters_service:
            verified_sites = get_verified_sites(webmasters_service)
            
            # Update config
            config = load_config()
            config['credentialsPath'] = creds_path
            config['isAuthorized'] = True
            save_config(config)
            
            return jsonify({
                "authorized": True,
                "message": f"Successfully authorized! Found {len(verified_sites)} verified sites.",
                "sitesCount": len(verified_sites)
            })
        else:
            # Update config
            config = load_config()
            config['isAuthorized'] = False
            save_config(config)
            
            return jsonify({
                "authorized": False,
                "message": "Failed to authorize credentials. Please check your credentials file."
            }), 400
            
    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

@app.route('/api/settings/clear', methods=['POST'])
def clear_settings():
    """Clear all authentication and credentials"""
    global webmasters_service, verified_sites, openai_client
    
    try:
        # Delete authorized credentials files (legacy oauth2client + web-flow token)
        for path in (os.path.join(DATA_DIR, 'authorizedcreds.dat'), GSC_TOKEN_FILE):
            if os.path.exists(path):
                try:
                    os.remove(path)
                    print(f"Deleted {path}")
                except Exception as e:
                    print(f"Error deleting {path}: {e}")
        
        # Удалить все подключённые аккаунты из БД и перестроить реестр
        try:
            for acc in gscm.list_accounts():
                gscm.delete_account(acc['email'])
        except Exception as e:  # noqa: BLE001
            log.warning("clear: deleting accounts failed: %s", e)

        # Reset global variables
        webmasters_service = None
        verified_sites = []
        openai_client = None

        # Clear config
        config = {
            "openaiApiKey": "",
            "openaiModel": DEFAULT_OPENAI_MODEL,
            "credentialsPath": "",
            "isAuthorized": False,
            "overviewSites": []
        }
        
        if save_config(config):
            # Reinitialize OpenAI client (will be None since no key)
            initialize_openai_client()
            
            return jsonify({
                "success": True,
                "message": "All credentials and authentication have been cleared successfully."
            })
        else:
            return jsonify({"error": "Failed to clear settings"}), 500
            
    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

@app.route('/api/url-inspect', methods=['POST'])
def inspect_url():
    """Inspect a URL using Google Search Console URL Inspection API"""
    try:
        data = request.get_json()
        inspection_url = data.get('inspectionUrl')
        site_url = data.get('siteUrl')
        language_code = data.get('languageCode', 'en-US')

        if not inspection_url:
            return jsonify({"error": "inspectionUrl is required"}), 400

        if not site_url:
            return jsonify({"error": "siteUrl is required"}), 400

        service = gscm.get_service_for_site(site_url)
        if not service:
            return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

        # Build the request body for the URL Inspection API
        request_body = {
            'inspectionUrl': inspection_url,
            'siteUrl': site_url,
            'languageCode': language_code
        }

        # Call the URL Inspection API
        # The API endpoint is urlInspection.index().inspect()
        try:
            # The method signature is: urlInspection().index().inspect(body={...}).execute()
            response = service.urlInspection().index().inspect(body=request_body).execute()
            return jsonify(response)
        except Exception as api_error:
            error_message = str(api_error)
            # Try to extract more detailed error information from the exception
            if hasattr(api_error, 'content'):
                try:
                    import json
                    error_content = json.loads(api_error.content.decode('utf-8'))
                    if 'error' in error_content:
                        error_message = error_content['error'].get('message', error_message)
                except:
                    pass
            elif hasattr(api_error, 'error_details'):
                # Some Google API exceptions have error_details
                try:
                    error_message = str(api_error.error_details) or error_message
                except:
                    pass
            
            return jsonify({"error": f"URL Inspection API error: {error_message}"}), 400
            
    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500

@app.route('/api/sitemaps', methods=['GET'])
def list_sitemaps():
    """List all sitemaps for a site"""
    global webmasters_service
    import json
    import sys
    
    print(f"\n{'='*60}", file=sys.stderr, flush=True)
    print(f"[SITEMAPS] Received request to list sitemaps", file=sys.stderr, flush=True)
    print(f"[SITEMAPS] Request method: {request.method}", file=sys.stderr, flush=True)
    print(f"[SITEMAPS] Request args: {dict(request.args)}", file=sys.stderr, flush=True)
    
    try:
        site_url = request.args.get('siteUrl')
        print(f"[SITEMAPS] Site URL from request: {site_url}", file=sys.stderr, flush=True)

        if not site_url:
            print(f"[SITEMAPS] ERROR: siteUrl is required", file=sys.stderr, flush=True)
            return jsonify({"error": "siteUrl is required"}), 400

        service = gscm.get_service_for_site(site_url)
        if not service:
            return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

        # List sitemaps
        print(f"[SITEMAPS] Calling GSC API: sitemaps().list(siteUrl={site_url})", file=sys.stderr, flush=True)
        response = service.sitemaps().list(siteUrl=site_url).execute()
        print(f"[SITEMAPS] API Response received:", file=sys.stderr, flush=True)
        print(json.dumps(response, indent=2), file=sys.stderr, flush=True)
        
        # Check if response has sitemap (singular) or sitemapEntry
        if 'sitemap' in response:
            print(f"[SITEMAPS] Found {len(response['sitemap'])} sitemaps", file=sys.stderr, flush=True)
            for idx, sitemap in enumerate(response['sitemap']):
                print(f"[SITEMAPS]   [{idx+1}] Path: {sitemap.get('path', 'N/A')}, Type: {sitemap.get('type', 'N/A')}", file=sys.stderr, flush=True)
        elif 'sitemapEntry' in response:
            print(f"[SITEMAPS] Found {len(response['sitemapEntry'])} sitemaps (sitemapEntry)", file=sys.stderr, flush=True)
            for idx, sitemap in enumerate(response['sitemapEntry']):
                print(f"[SITEMAPS]   [{idx+1}] Path: {sitemap.get('path', 'N/A')}, Type: {sitemap.get('type', 'N/A')}", file=sys.stderr, flush=True)
        else:
            print(f"[SITEMAPS] WARNING: No 'sitemap' or 'sitemapEntry' key in response. Response keys: {list(response.keys())}", file=sys.stderr, flush=True)
        
        print(f"{'='*60}\n", file=sys.stderr, flush=True)
        return jsonify(response)
    except Exception as e:
        import traceback
        print(f"[SITEMAPS] EXCEPTION occurred:", file=sys.stderr, flush=True)
        traceback.print_exc(file=sys.stderr)
        sys.stderr.flush()
        error_message = str(e)
        if hasattr(e, 'content'):
            try:
                import json
                error_content = json.loads(e.content.decode('utf-8'))
                print(f"[SITEMAPS] Error content:", file=sys.stderr, flush=True)
                print(json.dumps(error_content, indent=2), file=sys.stderr, flush=True)
                if 'error' in error_content:
                    error_message = error_content['error'].get('message', error_message)
            except Exception as parse_error:
                print(f"[SITEMAPS] Could not parse error content: {parse_error}", file=sys.stderr, flush=True)
        print(f"[SITEMAPS] Returning error: {error_message}", file=sys.stderr, flush=True)
        print(f"{'='*60}\n", file=sys.stderr, flush=True)
        return jsonify({"error": f"Sitemap API error: {error_message}"}), 400

@app.route('/api/sitemaps/get', methods=['GET'])
def get_sitemap():
    """Get information about a specific sitemap"""
    global webmasters_service
    import json
    import sys
    
    print(f"\n{'='*60}", file=sys.stderr, flush=True)
    print(f"[SITEMAPS GET] Received request to get sitemap details", file=sys.stderr, flush=True)
    print(f"[SITEMAPS GET] Request args: {dict(request.args)}", file=sys.stderr, flush=True)
    
    try:
        site_url = request.args.get('siteUrl')
        feedpath = request.args.get('feedpath')

        print(f"[SITEMAPS GET] Site URL: {site_url}", file=sys.stderr, flush=True)
        print(f"[SITEMAPS GET] Feedpath: {feedpath}", file=sys.stderr, flush=True)

        if not site_url:
            print(f"[SITEMAPS GET] ERROR: siteUrl is required", file=sys.stderr, flush=True)
            return jsonify({"error": "siteUrl is required"}), 400
        if not feedpath:
            print(f"[SITEMAPS GET] ERROR: feedpath is required", file=sys.stderr, flush=True)
            return jsonify({"error": "feedpath is required"}), 400

        service = gscm.get_service_for_site(site_url)
        if not service:
            return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

        # Get sitemap
        print(f"[SITEMAPS GET] Calling GSC API: sitemaps().get(siteUrl={site_url}, feedpath={feedpath})", file=sys.stderr, flush=True)
        response = service.sitemaps().get(siteUrl=site_url, feedpath=feedpath).execute()
        print(f"[SITEMAPS GET] API Response:", file=sys.stderr, flush=True)
        print(json.dumps(response, indent=2), file=sys.stderr, flush=True)
        print(f"{'='*60}\n", file=sys.stderr, flush=True)
        return jsonify(response)
    except Exception as e:
        import traceback
        print(f"[SITEMAPS GET] EXCEPTION occurred:", file=sys.stderr, flush=True)
        traceback.print_exc(file=sys.stderr)
        sys.stderr.flush()
        error_message = str(e)
        if hasattr(e, 'content'):
            try:
                import json
                error_content = json.loads(e.content.decode('utf-8'))
                print(f"[SITEMAPS GET] Error content:", file=sys.stderr, flush=True)
                print(json.dumps(error_content, indent=2), file=sys.stderr, flush=True)
                if 'error' in error_content:
                    error_message = error_content['error'].get('message', error_message)
            except Exception as parse_error:
                print(f"[SITEMAPS GET] Could not parse error content: {parse_error}", file=sys.stderr, flush=True)
        print(f"[SITEMAPS GET] Returning error: {error_message}", file=sys.stderr, flush=True)
        print(f"{'='*60}\n", file=sys.stderr, flush=True)
        return jsonify({"error": f"Sitemap API error: {error_message}"}), 400

@app.route('/api/sitemaps/submit', methods=['POST'])
def submit_sitemap():
    """Submit a sitemap for a site"""
    global webmasters_service
    import json
    
    print(f"[SITEMAPS SUBMIT] Received request to submit sitemap")
    
    try:
        data = request.get_json()
        print(f"[SITEMAPS SUBMIT] Request data: {json.dumps(data, indent=2)}")
        site_url = data.get('siteUrl')
        feedpath = data.get('feedpath')

        if not site_url:
            print(f"[SITEMAPS SUBMIT] ERROR: siteUrl is required")
            return jsonify({"error": "siteUrl is required"}), 400
        if not feedpath:
            print(f"[SITEMAPS SUBMIT] ERROR: feedpath is required")
            return jsonify({"error": "feedpath is required"}), 400

        service = gscm.get_service_for_site(site_url)
        if not service:
            return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

        print(f"[SITEMAPS SUBMIT] Calling GSC API: sitemaps().submit(siteUrl={site_url}, feedpath={feedpath})")
        # Submit sitemap
        response = service.sitemaps().submit(siteUrl=site_url, feedpath=feedpath).execute()
        print(f"[SITEMAPS SUBMIT] API Response: {json.dumps(response, indent=2)}")
        return jsonify({"success": True, "message": "Sitemap submitted successfully"})
    except Exception as e:
        import traceback
        print(f"[SITEMAPS SUBMIT] EXCEPTION occurred:")
        traceback.print_exc()
        error_message = str(e)
        if hasattr(e, 'content'):
            try:
                import json
                error_content = json.loads(e.content.decode('utf-8'))
                print(f"[SITEMAPS SUBMIT] Error content: {json.dumps(error_content, indent=2)}")
                if 'error' in error_content:
                    error_message = error_content['error'].get('message', error_message)
            except Exception as parse_error:
                print(f"[SITEMAPS SUBMIT] Could not parse error content: {parse_error}")
        print(f"[SITEMAPS SUBMIT] Returning error: {error_message}")
        return jsonify({"error": f"Sitemap API error: {error_message}"}), 400

@app.route('/api/sitemaps/delete', methods=['POST'])
def delete_sitemap():
    """Delete a sitemap from a site"""
    global webmasters_service
    import json
    
    print(f"[SITEMAPS DELETE] Received request to delete sitemap")
    
    try:
        data = request.get_json()
        print(f"[SITEMAPS DELETE] Request data: {json.dumps(data, indent=2)}")
        site_url = data.get('siteUrl')
        feedpath = data.get('feedpath')

        if not site_url:
            print(f"[SITEMAPS DELETE] ERROR: siteUrl is required")
            return jsonify({"error": "siteUrl is required"}), 400
        if not feedpath:
            print(f"[SITEMAPS DELETE] ERROR: feedpath is required")
            return jsonify({"error": "feedpath is required"}), 400

        service = gscm.get_service_for_site(site_url)
        if not service:
            return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

        print(f"[SITEMAPS DELETE] Calling GSC API: sitemaps().delete(siteUrl={site_url}, feedpath={feedpath})")
        # Delete sitemap
        service.sitemaps().delete(siteUrl=site_url, feedpath=feedpath).execute()
        print(f"[SITEMAPS DELETE] Sitemap deleted successfully")
        return jsonify({"success": True, "message": "Sitemap deleted successfully"})
    except Exception as e:
        import traceback
        print(f"[SITEMAPS DELETE] EXCEPTION occurred:")
        traceback.print_exc()
        error_message = str(e)
        if hasattr(e, 'content'):
            try:
                import json
                error_content = json.loads(e.content.decode('utf-8'))
                print(f"[SITEMAPS DELETE] Error content: {json.dumps(error_content, indent=2)}")
                if 'error' in error_content:
                    error_message = error_content['error'].get('message', error_message)
            except Exception as parse_error:
                print(f"[SITEMAPS DELETE] Could not parse error content: {parse_error}")
        print(f"[SITEMAPS DELETE] Returning error: {error_message}", file=sys.stderr, flush=True)
        return jsonify({"error": f"Sitemap API error: {error_message}"}), 400

@app.route('/api/trends/analyze', methods=['POST'])
def trends_analyze():
    """
    Run GSC + Google Trends combined analysis.
    Body JSON fields:
      siteUrl, startDate, endDate, urlFilter, queryFilter,
      device, country, topNQueries, trendsGeoCode, timeResolution
    """
    try:
        body = request.get_json() or {}
        site_url       = body.get('siteUrl', '')
        start_date_str = body.get('startDate', '')
        end_date_str   = body.get('endDate', '')
        url_filter     = body.get('urlFilter') or None
        query_filter   = body.get('queryFilter') or None
        device         = body.get('device') or None
        country        = body.get('country') or None
        top_n          = int(body.get('topNQueries', 15))
        geo_code       = body.get('trendsGeoCode', 'US')
        time_res       = body.get('timeResolution', 'WEEK').upper()

        if not all([site_url, start_date_str, end_date_str]):
            return jsonify({"error": "siteUrl, startDate, endDate are required"}), 400

        service = gscm.get_service_for_site(site_url)
        if not service:
            return jsonify({"error": f"Нет авторизованного аккаунта для сайта {site_url}"}), 400

        config = load_config()
        trends_creds_path = config.get('trendsCredentialsPath', '')
        if not trends_creds_path or not os.path.exists(trends_creds_path):
            return jsonify({"error": "Google Trends credentials path not set or file not found. Configure it in Settings."}), 400

        # Build dimension filters
        filters = []
        if url_filter:
            filters.append({"dimension": "page", "operator": "contains", "expression": url_filter})
        if query_filter:
            filters.append({"dimension": "query", "operator": "contains", "expression": query_filter})
        if device:
            device_map = {'desktop': 'DESKTOP', 'mobile': 'MOBILE', 'tablet': 'TABLET'}
            filters.append({"dimension": "device", "operator": "equals",
                            "expression": device_map.get(device.lower(), device.upper())})
        if country:
            filters.append({"dimension": "country", "operator": "equals", "expression": country})

        dim_filter_groups = [{"filters": filters}] if filters else None

        # ── Step 1: top queries by total clicks ──────────────────────────────
        top_q_request = {
            'startDate': start_date_str,
            'endDate': end_date_str,
            'dimensions': ['query'],
            'aggregationType': 'auto',
            'rowLimit': 25000,
        }
        if dim_filter_groups:
            top_q_request['dimensionFilterGroups'] = dim_filter_groups

        top_q_resp = service.searchanalytics().query(
            siteUrl=site_url, body=top_q_request).execute()
        top_q_rows = top_q_resp.get('rows', [])
        top_q_rows.sort(key=lambda r: r.get('clicks', 0), reverse=True)
        top_queries = [r['keys'][0] for r in top_q_rows[:top_n]]

        if not top_queries:
            return jsonify({"error": "No queries found for the selected parameters."}), 404

        # ── Step 2: daily/weekly/monthly clicks for those top queries ─────────
        kw_filters = (filters or []) + [{
            "dimension": "query",
            "operator": "includingRegex",
            "expression": "|".join(top_queries),
        }]
        kw_request = {
            'startDate': start_date_str,
            'endDate': end_date_str,
            'dimensions': ['query', 'date'],
            'aggregationType': 'auto',
            'rowLimit': 25000,
            'dimensionFilterGroups': [{"filters": kw_filters}],
        }
        kw_resp = service.searchanalytics().query(
            siteUrl=site_url, body=kw_request).execute()
        kw_rows = kw_resp.get('rows', [])

        # Build per-keyword per-date frame, then resample
        kw_data = []
        for row in kw_rows:
            q, d = row['keys'][0], row['keys'][1]
            if q in top_queries:
                kw_data.append({'query': q, 'date': pd.to_datetime(d),
                                'clicks': row['clicks'], 'impressions': row['impressions']})

        if kw_data:
            df_kw = pd.DataFrame(kw_data).set_index('date')
            freq = {'DAY': 'D', 'WEEK': 'W-MON'}.get(time_res, 'MS')
            df_totals = (df_kw.groupby('query')
                         .resample(freq)[['clicks', 'impressions']]
                         .sum()
                         .reset_index())
            df_period = (df_totals.groupby('date')[['clicks', 'impressions']]
                         .sum()
                         .reset_index())
            df_period['date'] = df_period['date'].dt.strftime('%Y-%m-%d')
            gsc_series = df_period.to_dict(orient='records')
        else:
            gsc_series = []

        # ── Step 3: Google Trends ─────────────────────────────────────────────
        token_file = os.path.join(DATA_DIR, 'authorized_trends_token.json')
        try:
            trends_creds = load_trends_creds(token_file, trends_creds_path)
        except Exception as e:
            return jsonify({"error": f"Trends auth failed: {str(e)}"}), 500

        start_dt = datetime.datetime.strptime(start_date_str, '%Y-%m-%d').replace(
            tzinfo=datetime.timezone.utc)
        cutoff = datetime.datetime.now(tz=datetime.timezone.utc) - datetime.timedelta(days=3)
        end_dt = min(
            datetime.datetime.strptime(end_date_str, '%Y-%m-%d').replace(
                tzinfo=datetime.timezone.utc),
            cutoff
        )

        trends_by_keyword = {}
        errors = []
        for q in top_queries:
            try:
                pts = fetch_trends_for_query(q, trends_creds, token_file,
                                             start_dt, end_dt, geo_code, time_res)
                trends_by_keyword[q] = pts
            except Exception as e:
                errors.append(f"{q}: {str(e)}")

        if not trends_by_keyword:
            return jsonify({
                "error": "Could not fetch any Trends data.",
                "details": errors
            }), 500

        # Average trends across top queries per period
        period_vals: dict = {}
        for pts in trends_by_keyword.values():
            for pt in pts:
                period_vals.setdefault(pt['date'], []).append(pt['value'])
        trends_avg = [
            {"date": d, "value": sum(vals) / len(vals)}
            for d, vals in sorted(period_vals.items())
        ]

        return jsonify({
            "topQueries": top_queries,
            "gscSeries": gsc_series,
            "trendsAvg": trends_avg,
            "trendsByKeyword": {
                q: pts for q, pts in trends_by_keyword.items()
            },
            "errors": errors,
        })

    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500


@app.route('/api/trends/insights', methods=['POST'])
def trends_insights():
    """Generate AI insights from combined GSC + Trends data."""
    global openai_client

    if openai_client is None:
        initialize_openai_client()
    if openai_client is None:
        return jsonify({"error": "OpenAI API key not configured. Please set it in Settings."}), 400

    try:
        body = request.get_json() or {}
        site_url      = body.get('siteUrl', '')
        start_date    = body.get('startDate', '')
        end_date      = body.get('endDate', '')
        time_res      = body.get('timeResolution', 'WEEK')
        top_queries   = body.get('topQueries', [])
        gsc_series    = body.get('gscSeries', [])   # [{date, clicks, trendsValue}]
        algo_updates  = body.get('algoUpdatesInRange', [])  # [{name, start_date, type}]

        if not gsc_series:
            return jsonify({"error": "No data provided."}), 400

        # Build the data table for the prompt
        data_rows = "\n".join(
            f"{r['date']} | {int(r.get('clicks', 0)):,} | {round(r.get('trendsValue', 0), 1)}"
            for r in gsc_series
        )

        algo_section = ""
        if algo_updates:
            algo_section = "\n\nGoogle Algorithm Updates that fall within this date range:\n"
            for u in algo_updates:
                algo_section += f"- {u['start_date']}: {u['name']} ({u['type']} update)\n"

        system_prompt = """You are a senior SEO analyst interpreting a dual-axis chart that overlays Google Search Console (GSC) click traffic with Google Trends search interest for a website.

Your job is to diagnose what is happening by comparing the two lines, using this diagnostic framework:

SCENARIOS:
1. Lines tracking together (both up, both down, same shape) → Demand and traffic move in sync. Likely seasonal or demand-driven. No structural problem — the site is capturing its fair share of available demand.
2. Traffic down, Trends flat or up → Search interest is intact but the site is losing share. Look for: algorithm updates (annotated in the data), ranking drops, technical regressions, content quality changes, or a competitor gaining ground.
3. Both lines flat or declining together → Genuine demand contraction. Seasonal, niche shift, or a broader market change. Not necessarily a site problem.
4. Traffic rises, Trends flat → The site is gaining share or getting more efficient. Positive structural signal.

ALGORITHM UPDATES:
If you see algorithm updates in the data, check whether a divergence in the two lines starts near one of those dates. A divergence that begins right at a known update is a strong signal the update affected this site specifically.

THE CORE HYPOTHESIS:
When traffic drops, three explanations are possible:
- Both GSC and Trends fall together → Seasonal demand drop — probably fine
- GSC traffic falls, Trends flat or rising → Something is wrong with the site (ranking loss, technical issue, algorithm hit, content quality signal)
- GSC traffic falls, Trends also rising elsewhere → Ranking loss — the site lost share to competitors

The key insight: if people are still searching for the topics but site traffic is falling, the problem is on the site's end.

OUTPUT FORMAT:
Write a clear, structured diagnosis with:
1. **Overall pattern** — what the two lines are doing relative to each other
2. **Key periods** — identify specific date ranges where the lines diverge or converge, and what that likely means
3. **Algorithm update impact** — if any updates fall near divergence points, call them out explicitly
4. **Diagnosis** — your best read of what is causing the traffic behavior
5. **Recommended next steps** — 2-3 concrete actions to investigate or fix

Be specific about dates and magnitudes. Be direct — do not hedge everything with "it could be". Make a call.
"""

        user_content = f"""Site: {site_url}
Date range: {start_date} to {end_date}
Time resolution: {time_res}
Top queries analyzed: {', '.join(top_queries[:10])}
{algo_section}

Data (Date | GSC Clicks | Google Trends scaled interest):
{data_rows}
"""

        chat_completion = openai_client.chat.completions.create(
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_content},
            ],
            model=get_openai_model(),
        )

        return jsonify({"insights": chat_completion.choices[0].message.content})

    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e)}), 500


@app.route('/api/algo-updates', methods=['GET'])
def get_algo_updates():
    """Return algorithm updates from algo_updates.json"""
    try:
        algo_file = os.path.join(os.path.dirname(__file__), 'algo_updates.json')
        if not os.path.exists(algo_file):
            return jsonify({"algo_updates": []})
        with open(algo_file, 'r') as f:
            data = json.load(f)
        return jsonify(data)
    except Exception as e:
        return jsonify({"error": str(e)}), 500


# Debug route to list all registered routes
@app.route('/api/debug/routes', methods=['GET'])
def list_routes():
    """List all registered routes for debugging"""
    routes = []
    for rule in app.url_map.iter_rules():
        routes.append({
            'endpoint': rule.endpoint,
            'methods': list(rule.methods),
            'path': str(rule)
        })
    return jsonify({'routes': routes})

if __name__ == '__main__':
    import warnings
    import os
    
    # Suppress multiprocessing resource tracker warnings (they're harmless)
    warnings.filterwarnings('ignore', category=UserWarning, module='multiprocessing.resource_tracker')
    
    # Set environment variable to prevent multiprocessing issues with Flask reloader
    os.environ['FLASK_ENV'] = 'development'
    
    log.info("Starting GSC Dashboard Backend...")

    # Инициализация БД (создаёт таблицы, если их нет)
    try:
        seo_db.init_db()
    except Exception as e:  # noqa: BLE001
        log.exception("DB init failed (продолжаем без БД): %s", e)

    # Прогрев подключения к кэшу (не критично, есть фолбэк)
    seo_cache.get_client()

    # Initialize OpenAI client
    initialize_openai_client()

    # Initialize GSC service
    init_gsc()

    # Планировщик автоматизации (интервалы из сохранённого конфига)
    try:
        seo_scheduler.configure((load_config() or {}).get('automation'))
        seo_scheduler.start()
    except Exception as e:  # noqa: BLE001
        log.exception("Scheduler start failed: %s", e)

    try:
        # Use use_reloader=False to prevent multiprocessing conflicts
        # This is safer when using pandas and other libraries that use multiprocessing
        log.info("Backend listening on 0.0.0.0:5001")
        app.run(debug=True, host='0.0.0.0', port=5001, use_reloader=False, threaded=True)
    except KeyboardInterrupt:
        log.info("Shutting down backend...")
    except Exception as e:
        log.exception("Error running backend: %s", e)