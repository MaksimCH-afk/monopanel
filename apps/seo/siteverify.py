"""
Авто-добавление сайтов в Google Search Console + верификация.

Добавляет ресурс в GSC (searchconsole sites().add) и пытается подтвердить право
собственности через Site Verification API. Полностью автоматическое
подтверждение возможно только когда токен уже размещён (например, DNS-TXT для
домена уже настроен). Иначе возвращаем токен и способ — чтобы разместить вручную,
после чего повторить.

Требует OAuth-scope webmasters + siteverification (см. gsc_manager.GSC_SCOPES).
Ранее выданные readonly-токены нужно переавторизовать.
"""

import logging

from apiclient.discovery import build

import gsc_manager as gscm

log = logging.getLogger('seo.siteverify')


def _site_spec(site_url):
    """Определить тип и идентификатор ресурса для Site Verification."""
    if site_url.startswith('sc-domain:'):
        return "INET_DOMAIN", site_url.split(':', 1)[1], "DNS_TXT"
    return "SITE", site_url, "META"


def add_and_verify(email, site_url, verification_method=None):
    """
    Добавить сайт в GSC аккаунта email и попытаться верифицировать.
    Возвращает подробный dict со статусом, токеном и инструкцией.
    """
    creds = gscm.get_creds(email)
    if not creds:
        return {"ok": False, "error": f"Аккаунт {email} не найден или требует переавторизации"}

    site_type, identifier, default_method = _site_spec(site_url)
    method = verification_method or default_method
    result = {"ok": False, "site_url": site_url, "email": email,
              "added": False, "verified": False, "token": None,
              "method": method, "error": None, "instruction": None}

    try:
        sv = build('siteVerification', 'v1', credentials=creds, cache_discovery=False)
        body = {"site": {"type": site_type, "identifier": identifier},
                "verificationMethod": method}

        # 1) Получить токен подтверждения
        try:
            token_resp = sv.webResource().getToken(body=body).execute()
            result["token"] = token_resp.get("token")
            result["method"] = token_resp.get("method", method)
            log.info("SiteVerification token for %s (%s): got", site_url, method)
        except Exception as e:  # noqa: BLE001
            log.warning("getToken failed for %s: %s", site_url, e)
            result["error"] = f"getToken: {e}"

        # 2) Попытка верификации (сработает, если токен уже размещён)
        try:
            sv.webResource().insert(verificationMethod=method,
                                    body={"site": {"type": site_type, "identifier": identifier}}).execute()
            result["verified"] = True
            log.info("Site %s verified for %s", site_url, email)
        except Exception as e:  # noqa: BLE001
            log.info("Verify not completed for %s (нужно разместить токен): %s", site_url, e)
            if result["token"]:
                if method == "DNS_TXT":
                    result["instruction"] = (
                        f"Добавьте DNS TXT-запись для {identifier} со значением: {result['token']}, "
                        f"затем повторите.")
                elif method == "META":
                    result["instruction"] = (
                        f"Разместите meta-тег на главной странице: {result['token']}, затем повторите.")
                else:
                    result["instruction"] = (
                        f"Разместите токен ({method}): {result['token']}, затем повторите.")

        # 3) Добавить ресурс в GSC (после верификации он станет доступен)
        try:
            wm = build('searchconsole', 'v1', credentials=creds, cache_discovery=False)
            wm.sites().add(siteUrl=site_url).execute()
            result["added"] = True
            log.info("Site %s added to GSC for %s", site_url, email)
        except Exception as e:  # noqa: BLE001
            log.warning("sites().add failed for %s: %s", site_url, e)
            if not result["error"]:
                result["error"] = f"add: {e}"

        # Обновим сайты аккаунта в реестре/БД
        try:
            gscm.refresh_all_sites()
        except Exception:  # noqa: BLE001
            pass

        result["ok"] = result["added"] or result["verified"]
        return result

    except Exception as e:  # noqa: BLE001
        log.exception("add_and_verify failed: %s", e)
        result["error"] = str(e)
        return result
