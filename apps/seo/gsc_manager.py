"""
Мультиаккаунт Google Search Console.

Хранит несколько подключённых Google-аккаунтов (токены в БД), строит на каждый
свой googleapiclient-сервис и даёт «сервис по сайту» — чтобы запрос к любому
сайту уходил через тот аккаунт, которому этот сайт принадлежит.

Реестр (email -> service, site_url -> email) держится в памяти и
перестраивается из БД (rebuild_registry). Токены при истечении обновляются и
сохраняются обратно в БД.
"""

import json
import logging

from apiclient.discovery import build
from google.oauth2.credentials import Credentials
from google.auth.transport.requests import Request

from db import Account, Site, session_scope

log = logging.getLogger('seo.gsc')

# webmasters (полный, а не readonly) — чтение данных GSC + добавление ресурсов;
# siteverification — авто-верификация добавляемых сайтов; openid+email — узнать
# аккаунт. ВНИМАНИЕ: расширение scope относительно readonly — ранее выданные
# токены нужно переавторизовать (кнопка «Добавить аккаунт Google»).
GSC_SCOPES = [
    'https://www.googleapis.com/auth/webmasters',
    'https://www.googleapis.com/auth/siteverification',
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
]

# email -> searchconsole service; site_url -> email
_services = {}
_site_index = {}


# ─── низкоуровневые помощники ───────────────────────────────────────────────────
def creds_from_json(token_json):
    return Credentials.from_authorized_user_info(json.loads(token_json), GSC_SCOPES)


def build_service(creds):
    return build('searchconsole', 'v1', credentials=creds, cache_discovery=False)


def get_account_email(creds):
    """Определить email аккаунта по токену (нужен scope userinfo.email)."""
    oauth2 = build('oauth2', 'v2', credentials=creds, cache_discovery=False)
    info = oauth2.userinfo().get().execute()
    return info.get('email')


def fetch_sites(service):
    """Список верифицированных сайтов аккаунта: [(site_url, permission_level)]."""
    resp = service.sites().list().execute()
    out = []
    for e in resp.get('siteEntry', []):
        url = e.get('siteUrl', '')
        perm = e.get('permissionLevel')
        if perm != 'siteUnverifiedUser' and str(url).startswith('http'):
            out.append((url, perm))
    return out


def _refresh_and_persist(email, creds):
    """Обновить токен при истечении и сохранить обратно в БД."""
    if creds and creds.expired and creds.refresh_token:
        log.info("Refreshing token for %s", email)
        creds.refresh(Request())
        with session_scope() as s:
            acc = s.query(Account).filter_by(email=email).first()
            if acc:
                acc.token_json = creds.to_json()
    return creds


# ─── операции с аккаунтами ──────────────────────────────────────────────────────
def add_or_update_account(creds):
    """Добавить/обновить аккаунт по токену, синхронизировать его сайты."""
    email = get_account_email(creds)
    if not email:
        raise RuntimeError("Не удалось определить email аккаунта (нет scope email?)")

    with session_scope() as s:
        acc = s.query(Account).filter_by(email=email).first()
        if acc:
            acc.token_json = creds.to_json()
            log.info("Updated account %s", email)
        else:
            acc = Account(email=email, token_json=creds.to_json())
            s.add(acc)
            s.flush()
            log.info("Added account %s (id=%s)", email, acc.id)
        account_id = acc.id

    n = sync_account_sites(account_id, build_service(creds))
    log.info("Account %s: synced %s sites", email, n)
    rebuild_registry()
    return email


def sync_account_sites(account_id, service):
    """Пересобрать список сайтов аккаунта в БД."""
    sites = fetch_sites(service)
    with session_scope() as s:
        s.query(Site).filter_by(account_id=account_id).delete()
        for url, perm in sites:
            s.add(Site(account_id=account_id, site_url=url, permission_level=perm))
    return len(sites)


def rebuild_registry():
    """Перестроить in-memory реестр сервисов и индекс сайтов из БД."""
    _services.clear()
    _site_index.clear()

    with session_scope() as s:
        accounts = [(a.id, a.email, a.token_json) for a in s.query(Account).all()]
        sites_by_acc = {}
        for row in s.query(Site).all():
            sites_by_acc.setdefault(row.account_id, []).append(row.site_url)

    for acc_id, email, token_json in accounts:
        try:
            creds = _refresh_and_persist(email, creds_from_json(token_json))
            _services[email] = build_service(creds)
            for url in sites_by_acc.get(acc_id, []):
                _site_index[url] = email
        except Exception as e:  # noqa: BLE001
            log.warning("rebuild_registry: account %s failed: %s", email, e)

    log.info("Registry rebuilt: %s account(s), %s site(s)",
             len(_services), len(_site_index))
    return _site_index


def refresh_all_sites():
    """Перетянуть сайты по всем аккаунтам (ручное/авто обновление)."""
    with session_scope() as s:
        accounts = [(a.id, a.email, a.token_json) for a in s.query(Account).all()]
    total = 0
    for acc_id, email, token_json in accounts:
        try:
            creds = _refresh_and_persist(email, creds_from_json(token_json))
            total += sync_account_sites(acc_id, build_service(creds))
        except Exception as e:  # noqa: BLE001
            log.warning("refresh_all_sites: account %s failed: %s", email, e)
    rebuild_registry()
    return total


def delete_account(email):
    with session_scope() as s:
        acc = s.query(Account).filter_by(email=email).first()
        if not acc:
            return False
        s.delete(acc)  # каскадно удалит сайты
    log.info("Deleted account %s", email)
    rebuild_registry()
    return True


def list_accounts():
    with session_scope() as s:
        out = []
        for a in s.query(Account).all():
            cnt = s.query(Site).filter_by(account_id=a.id).count()
            out.append({
                "email": a.email,
                "sites": cnt,
                "created_at": a.created_at.isoformat() if a.created_at else None,
            })
        return out


# ─── доступ к сервисам ──────────────────────────────────────────────────────────
def get_service_for_site(site_url):
    """Сервис аккаунта, которому принадлежит сайт. None, если не найден."""
    if not _site_index and not _services:
        rebuild_registry()
    email = _site_index.get(site_url)
    if email:
        return _services.get(email)
    # запасной вариант: единственный аккаунт (или None)
    if len(_services) == 1:
        return next(iter(_services.values()))
    return None


def all_site_urls():
    if not _site_index and not _services:
        rebuild_registry()
    return sorted(_site_index.keys())


def account_email_for_site(site_url):
    return _site_index.get(site_url)


def has_any_account():
    if not _services:
        rebuild_registry()
    return bool(_services)


def get_creds(email):
    """Валидные (обновлённые) Credentials аккаунта по email или None."""
    with session_scope() as s:
        acc = s.query(Account).filter_by(email=email).first()
        if not acc:
            return None
        token_json = acc.token_json
    try:
        return _refresh_and_persist(email, creds_from_json(token_json))
    except Exception as e:  # noqa: BLE001
        log.warning("get_creds failed for %s: %s", email, e)
        return None
