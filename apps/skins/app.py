# -*- coding: utf-8 -*-
"""
Brandskins control panel — управление НЕСКОЛЬКИМИ шаблонами.

Структура репозитория:
  template1/   — статический сайт «Шаблон 1» (css/styles.css + css/brand.css + страницы)
  template2/   — лендинг «Шаблон 2» (css/styles.css + css/brand.css + assets)
  themes/      — ОБЩАЯ библиотека тем (brand.<name>.css в едином контракте переменных)
  tools/       — экстрактор цветов

Дашборд:
  /        -> активный шаблон (превью/выдача), служит из его папки
  /admin   -> панель: выбор шаблона (Шаблон 1/2), темы, экстрактор,
              превью (320/700/1440), «Скачать макет» активного шаблона
"""
import os
import re
import io
import json
import zipfile
import shutil
import subprocess
import sys
import tempfile
import mimetypes

from flask import Flask, request, jsonify, send_file, abort, Response
from PIL import Image

try:
    import pytesseract
    _HAS_OCR = True
except Exception:
    pytesseract = None
    _HAS_OCR = False

SITE_ROOT = os.environ.get("SITE_ROOT", "/site")
THEMES_DIR = os.path.join(SITE_ROOT, "themes")
OUT_DIR = os.path.join(SITE_ROOT, "out")
EXTRACTOR = os.path.join(SITE_ROOT, "tools", "extract_brand.py")
STATE_FILE = os.path.join(SITE_ROOT, ".active_template")

TEMPLATES = {
    "t1": {"label": "Шаблон 1", "dir": "template1"},
    "t2": {"label": "Шаблон 2", "dir": "template2"},
}
DEFAULT_TEMPLATE = "t1"

ALLOWED_EXT = {"png", "jpg", "jpeg", "webp"}
MAX_IMAGES = 10

BUILD_EXCLUDE_TOP = {
    ".gitignore", ".dockerignore",
    "favicon.ico", "favicon.svg", "favicon-32x32.png", "apple-touch-icon.png",
    "robots.txt", "sitemap.xml", ".htaccess",
}
BUILD_EXCLUDE_DIRS = {".git", "__pycache__"}

OCR_STOP = {
    "logo", "review", "bonuses", "deposits", "app", "login", "log", "in",
    "withdraw", "register", "now", "menu", "placeholder", "section", "heading",
    "label", "column", "row", "provider", "game", "games", "title", "offer", "bonus",
    "terms", "apply", "all", "tab", "hot", "new", "footer", "casino", "legal",
    "privacy", "policy", "cookies", "responsible", "gaming", "conditions",
    "main", "page", "goes", "here", "stat", "the", "and", "for", "your", "sign",
    "up", "support", "promotions", "banking", "welcome", "bonus", "spins",
    "subsection", "frequently", "asked", "question", "lorem", "ipsum",
}

app = Flask(__name__)


# ----------------------------------------------------------------------------- state
def get_active():
    try:
        k = open(STATE_FILE, encoding="utf-8").read().strip()
        if k in TEMPLATES:
            return k
    except Exception:
        pass
    return DEFAULT_TEMPLATE


def set_active(key):
    if key not in TEMPLATES:
        return False
    try:
        with open(STATE_FILE, "w", encoding="utf-8") as f:
            f.write(key)
        return True
    except Exception:
        return False


def tdir(key=None):
    return os.path.join(SITE_ROOT, TEMPLATES[key or get_active()]["dir"])


def active_brand_css():
    return os.path.join(tdir(), "css", "brand.css")


# ----------------------------------------------------------------------------- helpers
def slugify(name):
    s = re.sub(r"[^a-z0-9]+", "-", (name or "").lower()).strip("-")
    return s or "brand"


def brand_key_from_filename(fn):
    m = re.match(r"^brand\.(.+)\.css$", fn)
    return m.group(1) if m else ""


def parse_theme_colors(path):
    colors = {}
    try:
        txt = open(path, encoding="utf-8", errors="replace").read()
    except Exception:
        return colors
    for var in ("--c-accent", "--c-cta", "--c-bg", "--surface"):
        m = re.search(re.escape(var) + r"\s*:\s*([^;]+);", txt)
        if m:
            colors[var.lstrip("-")] = m.group(1).strip()
    return colors


def list_brands():
    active_bytes = None
    ab = active_brand_css()
    if os.path.isfile(ab):
        with open(ab, "rb") as f:
            active_bytes = f.read()
    brands = []
    if os.path.isdir(THEMES_DIR):
        for fn in sorted(os.listdir(THEMES_DIR)):
            if not fn.startswith("brand.") or not fn.endswith(".css") or fn == "brand.css":
                continue
            key = brand_key_from_filename(fn)
            if not key:
                continue
            path = os.path.join(THEMES_DIR, fn)
            try:
                with open(path, "rb") as f:
                    is_active = (f.read() == active_bytes)
            except Exception:
                is_active = False
            brands.append({
                "key": key, "file": fn, "label": key.replace(".", " · "),
                "active": is_active, "colors": parse_theme_colors(path),
            })
    return brands, any(b["active"] for b in brands)


def safe_path(base, rel):
    rel = rel.lstrip("/")
    full = os.path.realpath(os.path.join(base, rel))
    root = os.path.realpath(base)
    if full != root and not full.startswith(root + os.sep):
        return None
    return full


def detect_brand_name(paths):
    if not _HAS_OCR:
        return ""
    best = None
    for p in paths:
        try:
            im = Image.open(p).convert("RGB")
            data = pytesseract.image_to_data(im, output_type=pytesseract.Output.DICT)
        except Exception:
            continue
        for i in range(len(data["text"])):
            w = (data["text"][i] or "").strip()
            if not w:
                continue
            try:
                conf = float(data["conf"][i])
            except (ValueError, TypeError):
                conf = -1
            try:
                h = int(data["height"][i])
            except (ValueError, TypeError):
                h = 0
            wl = re.sub(r"[^A-Za-z0-9]", "", w)
            if len(wl) < 2 or len(wl) > 20 or not re.search(r"[A-Za-z]", wl):
                continue
            if wl.lower() in OCR_STOP or conf < 45:
                continue
            score = h * (conf / 100.0)
            if best is None or score > best[0]:
                best = (score, wl)
    return best[1] if best else ""


def next_auto_name():
    i = 1
    while os.path.isfile(os.path.join(THEMES_DIR, "brand.brand%d.css" % i)):
        i += 1
    return "brand%d" % i


# ----------------------------------------------------------------------------- API: templates
@app.get("/admin/api/templates")
def api_templates():
    return jsonify({
        "active": get_active(),
        "templates": [{"key": k, "label": v["label"]} for k, v in TEMPLATES.items()],
    })


@app.post("/admin/api/templates")
def api_templates_set():
    data = request.get_json(silent=True) or request.form
    key = (data.get("key") or "").strip()
    if set_active(key):
        return jsonify({"ok": True, "active": key})
    return jsonify({"ok": False, "error": "Неизвестный шаблон: %s" % key}), 400


# ----------------------------------------------------------------------------- API: themes
@app.get("/admin/api/brands")
def api_brands():
    brands, any_active = list_brands()
    return jsonify({"brands": brands, "custom": not any_active,
                    "ocr": _HAS_OCR, "active_template": get_active()})


@app.post("/admin/api/switch")
def api_switch():
    data = request.get_json(silent=True) or request.form
    key = (data.get("key") or "").strip()
    src = os.path.join(THEMES_DIR, "brand.%s.css" % key)
    if not key or not os.path.isfile(src):
        return jsonify({"ok": False, "error": "Тема не найдена: %s" % key}), 400
    try:
        os.makedirs(os.path.dirname(active_brand_css()), exist_ok=True)
        shutil.copyfile(src, active_brand_css())
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500
    return jsonify({"ok": True, "active": key, "template": get_active()})


@app.post("/admin/api/extract")
def api_extract():
    if not os.path.isfile(EXTRACTOR):
        return jsonify({"ok": False, "error": "Экстрактор не найден: %s" % EXTRACTOR}), 500
    files = request.files.getlist("images")
    if not files:
        return jsonify({"ok": False, "error": "Не загружены скриншоты"}), 400
    if len(files) > MAX_IMAGES:
        files = files[:MAX_IMAGES]

    enforce = request.form.get("enforce_contrast") in ("1", "true", "on", "yes")
    try:
        variants = max(0, int(request.form.get("variants") or 0))
    except ValueError:
        variants = 0

    tmpdir = tempfile.mkdtemp(prefix="refs_")
    img_paths = []
    try:
        for i, f in enumerate(files):
            base = os.path.basename(f.filename or "shot%d" % i)
            ext = base.rsplit(".", 1)[-1].lower() if "." in base else ""
            if ext not in ALLOWED_EXT:
                return jsonify({"ok": False,
                                "error": "Формат не поддержан: %s (нужно png/jpg/jpeg/webp)" % base}), 400
            dst = os.path.join(tmpdir, "%02d_%s" % (i, base))
            f.save(dst)
            try:
                Image.open(dst).verify()
            except Exception:
                return jsonify({"ok": False, "error": "Битый файл: %s" % base}), 400
            img_paths.append(dst)

        name = (request.form.get("name") or "").strip()
        detected = ""
        if not name:
            detected = detect_brand_name(img_paths)
            name = detected or next_auto_name()
        slug = slugify(name)

        os.makedirs(THEMES_DIR, exist_ok=True)
        os.makedirs(OUT_DIR, exist_ok=True)
        out_css = os.path.join(THEMES_DIR, "brand.%s.css" % slug)
        report = os.path.join(OUT_DIR, "%s-report.md" % slug)
        cmd = [sys.executable, EXTRACTOR, *img_paths,
               "--name", name, "-o", out_css, "--report", report]
        if enforce:
            cmd.append("--enforce-contrast")
        if variants > 0:
            cmd += ["--variants", str(variants)]

        proc = subprocess.run(cmd, cwd=SITE_ROOT, capture_output=True, text=True, timeout=300)
        report_text = ""
        if os.path.isfile(report):
            report_text = open(report, encoding="utf-8", errors="replace").read()
        ok = proc.returncode == 0 and os.path.isfile(out_css)
        return jsonify({
            "ok": ok, "name": name, "detected": detected, "slug": slug,
            "file": "brand.%s.css" % slug, "returncode": proc.returncode,
            "stdout": proc.stdout[-4000:], "stderr": proc.stderr[-4000:],
            "report": report_text[:20000],
        }), (200 if ok else 500)
    except subprocess.TimeoutExpired:
        return jsonify({"ok": False, "error": "Экстрактор не успел за 300с"}), 504
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


@app.get("/admin/api/build")
def api_build():
    """Готовый макет АКТИВНОГО шаблона (zip) с применённой темой."""
    root = os.path.realpath(tdir())
    mem = io.BytesIO()
    with zipfile.ZipFile(mem, "w", zipfile.ZIP_DEFLATED) as z:
        for dirpath, dirs, files in os.walk(root):
            dirs[:] = [d for d in dirs if d not in BUILD_EXCLUDE_DIRS]
            rel_dir = os.path.relpath(dirpath, root)
            for fn in files:
                if rel_dir == "." and fn in BUILD_EXCLUDE_TOP:
                    continue
                if fn.endswith((".pyc", ".pyo")):
                    continue
                if rel_dir == "css" and re.match(r"^brand\..+\.css$", fn) and fn != "brand.css":
                    continue
                full = os.path.join(dirpath, fn)
                z.write(full, os.path.relpath(full, root))
    mem.seek(0)
    brands, _ = list_brands()
    theme = next((b["key"] for b in brands if b["active"]), "build")
    dname = "%s-%s.zip" % (TEMPLATES[get_active()]["dir"], slugify(theme))
    return send_file(mem, mimetype="application/zip", as_attachment=True, download_name=dname)


@app.get("/admin")
@app.get("/admin/")
def admin():
    return Response(DASHBOARD_HTML, mimetype="text/html; charset=utf-8")


# ----------------------------------------------------------------------------- static (active template)
def _send_no_store(full):
    ctype, _ = mimetypes.guess_type(full)
    resp = send_file(full, mimetype=ctype) if ctype else send_file(full)
    resp.headers["Cache-Control"] = "no-store"
    return resp


@app.get("/")
def root_index():
    idx = os.path.join(tdir(), "index.html")
    return _send_no_store(idx) if os.path.isfile(idx) else abort(404)


@app.get("/<path:relpath>")
def static_site(relpath):
    if relpath == "admin" or relpath.startswith("admin/"):
        abort(404)
    base = tdir()
    full = safe_path(base, relpath)
    if not full:
        abort(404)
    if os.path.isfile(full):
        return _send_no_store(full)
    idx = os.path.join(full, "index.html")
    if os.path.isfile(idx):
        return _send_no_store(idx)
    abort(404)


DASHBOARD_HTML = r"""<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Brandskins · панель управления</title>
<style>
  :root{
    --bg:#0e1411;--panel:#151d18;--panel-2:#1b251f;--line:#27332b;--txt:#e8efe9;
    --muted:#8fa394;--gold:#e8b84b;--gold-hi:#f7d579;--gold-deep:#b6862c;
    --ok:#46c98a;--err:#e06464;--radius:14px;--sb:#0b100d;--sbthumb:#33433a;
  }
  *{box-sizing:border-box}
  body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
       background:radial-gradient(1200px 600px at 80% -10%,#16201a,transparent),var(--bg);color:var(--txt);min-height:100vh}
  a{color:var(--gold)}
  header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 20px;
         border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5;
         background:rgba(14,20,17,.85);backdrop-filter:blur(8px);flex-wrap:wrap}
  .brand{display:flex;align-items:center;gap:12px;font-weight:700;letter-spacing:.3px}
  .dot{width:10px;height:10px;border-radius:50%;background:var(--gold);box-shadow:0 0 12px var(--gold)}
  .hactions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  /* segmented toggle шаблонов */
  .tpl{display:inline-flex;background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:3px}
  .tpl button{border:0;background:transparent;color:var(--muted);padding:8px 16px;border-radius:9px;font-weight:700;cursor:pointer;transition:.15s}
  .tpl button.on{background:linear-gradient(180deg,var(--gold-hi),var(--gold) 55%,var(--gold-deep));color:#1a130a}
  .wrap{display:grid;grid-template-columns:minmax(0,320px) 1fr;gap:16px;padding:14px 16px;max-width:1760px;margin:0 auto}
  @media (max-width:980px){.wrap{grid-template-columns:1fr}}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px}
  .card h2{margin:0 0 4px;font-size:15px;letter-spacing:.4px;text-transform:uppercase;color:var(--gold)}
  .card .sub{margin:0 0 16px;color:var(--muted);font-size:13px}
  .mt{margin-top:14px}.muted{color:var(--muted)}
  code{font-family:ui-monospace,Menlo,monospace}
  .brands{display:flex;flex-direction:column;gap:10px}
  .brow{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--panel-2);
        border:1px solid var(--line);border-radius:12px;padding:10px 12px}
  .brow .meta{display:flex;flex-direction:column;gap:5px;min-width:0}
  .brow .name{font-weight:600}
  .brow .file{color:var(--muted);font-size:12px;font-family:ui-monospace,Menlo,monospace}
  .brow .apply{padding:7px 13px;font-size:13px}
  .sw{display:flex;gap:5px;margin-top:1px}
  .sw i{width:16px;height:16px;border-radius:5px;border:1px solid rgba(255,255,255,.12);display:inline-block}
  .badge{font-size:11px;padding:3px 9px;border-radius:999px;background:rgba(70,201,138,.15);
         color:var(--ok);border:1px solid rgba(70,201,138,.35);white-space:nowrap}
  button{font:inherit;cursor:pointer;border-radius:10px;border:1px solid var(--line);
         background:var(--panel-2);color:var(--txt);padding:9px 14px;transition:.15s}
  button:hover{border-color:var(--gold-deep)}
  button.apply{background:linear-gradient(180deg,var(--gold-hi),var(--gold) 55%,var(--gold-deep));
               color:#1a130a;border:none;font-weight:700}
  button.apply:disabled{filter:grayscale(.4) brightness(.8);cursor:default}
  .field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
  .field label{font-size:13px;color:var(--muted)}
  .hint{font-size:12px;color:var(--muted);margin-top:4px;line-height:1.45}
  input[type=text],input[type=number]{background:var(--panel-2);border:1px solid var(--line);
       border-radius:10px;color:var(--txt);padding:10px 12px;font:inherit}
  input[type=file]{font-size:13px;color:var(--muted)}
  .row{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
  .check{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px}
  pre{white-space:pre-wrap;word-break:break-word;background:#0b100d;border:1px solid var(--line);
      border-radius:10px;padding:12px;max-height:280px;overflow:auto;font-size:12px;color:var(--muted)}
  details summary{cursor:pointer;color:var(--muted);font-size:13px}
  .preview-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
  .preview-head h2{margin:0}
  .devrow{display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap}
  .dev{display:flex;flex-direction:column;gap:8px;min-width:0}
  .devlabel{font-size:12px;color:var(--muted);letter-spacing:.3px}
  .frame{border:1px solid var(--line);border-radius:12px;background:#000}
  .frame.fit{overflow-y:scroll;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:var(--sbthumb) var(--sb)}
  .frame.fit::-webkit-scrollbar{width:10px}
  .frame.fit::-webkit-scrollbar-track{background:var(--sb);border-radius:0 12px 12px 0}
  .frame.fit::-webkit-scrollbar-thumb{background:var(--sbthumb);border-radius:8px;border:3px solid var(--sb)}
  .frame.fit iframe{border:0;display:block;background:#000}
  .frame.scale{position:relative;width:100%;overflow:hidden}
  .frame.scale .screen{position:absolute;top:0;left:0;transform-origin:top left}
  .frame.scale iframe{width:100%;height:100%;border:0;display:block;background:#000}
  .toast{position:fixed;right:20px;bottom:20px;background:var(--panel-2);border:1px solid var(--line);
         border-left:3px solid var(--gold);border-radius:10px;padding:12px 16px;max-width:380px;
         box-shadow:0 8px 30px rgba(0,0,0,.4);opacity:0;transform:translateY(8px);transition:.2s;z-index:50}
  .toast.show{opacity:1;transform:none}
  .toast.ok{border-left-color:var(--ok)} .toast.err{border-left-color:var(--err)}
</style>
</head>
<body>
<header>
  <div class="brand"><span class="dot"></span> Brandskins · панель управления</div>
  <div class="hactions">
    <div class="tpl" id="tpl"></div>
    <button id="dl-build" class="apply" style="padding:8px 14px">Скачать макет</button>
    <a href="/" target="_blank" rel="noopener">Открыть сайт ↗</a>
  </div>
</header>

<div class="wrap">
  <div class="col">
    <div class="card">
      <h2>Темы брендов</h2>
      <p class="sub">Тема применяется к <b id="cur-tpl">активному шаблону</b> (копируется в его <code>css/brand.css</code>) — без пересборки.</p>
      <div class="brands" id="brands">загрузка…</div>
    </div>

    <div class="card mt">
      <h2>Экстрактор цветов</h2>
      <p class="sub">Загрузите до 10 скриншотов бренда — тема соберётся и попадёт в общую библиотеку (работает для обоих шаблонов).</p>
      <div class="field">
        <label>Название бренда <span class="muted">(необязательно)</span></label>
        <input type="text" id="ex-name" placeholder="оставьте пустым — распознаю с логотипа">
        <div class="hint" id="ocr-hint">Если поле пустое — название считывается с логотипа на скриншотах (OCR). Не распозналось — авто-имя.</div>
      </div>
      <div class="field">
        <label>Скриншоты — до 10 файлов (png, jpg, jpeg, webp)</label>
        <input type="file" id="ex-files" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" multiple>
      </div>
      <div class="row mt">
        <label class="check"><input type="checkbox" id="ex-contrast" checked> проверка контраста (WCAG)</label>
        <label class="check">варианты акцента
          <input type="number" id="ex-variants" value="0" min="0" max="8" style="width:64px">
        </label>
      </div>
      <div class="hint">
        <b>Акцент</b> — главный выделяющий цвет (кнопки, активные пункты, цифры, рамки).
        <b>Варианты акцента = N</b> создаёт N запасных тем с другими акцентами (<code>…alt1.css</code>…);
        фактическое число ограничено числом разных цветов на скриншотах.
      </div>
      <div class="mt"><button id="ex-run" class="apply">Сгенерировать тему</button></div>
      <div id="ex-result" class="mt"></div>
    </div>
  </div>

  <div class="col">
    <div class="card">
      <div class="preview-head">
        <h2>Превью · <span id="pv-tpl">…</span></h2>
        <button id="reload">Обновить ⟳</button>
      </div>
      <div class="devrow">
        <div class="dev">
          <div class="devlabel">📱 Мобильная · 320px (1:1)</div>
          <div class="frame fit" data-w="320" data-vh="640"><iframe id="pv-mobile" src="/"></iframe></div>
        </div>
        <div class="dev">
          <div class="devlabel">📲 Планшет · 700px (1:1)</div>
          <div class="frame fit" data-w="700" data-vh="900"><iframe id="pv-tablet" src="/"></iframe></div>
        </div>
      </div>
      <div class="dev" style="margin-top:18px">
        <div class="devlabel">🖥 ПК · 1440px (по ширине)</div>
        <div class="frame scale" data-w="1440"><div class="screen"><iframe id="pv-desktop" src="/"></iframe></div></div>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const $=s=>document.querySelector(s);
const $$=s=>Array.from(document.querySelectorAll(s));
function toast(m,k){const t=$("#toast");t.textContent=m;t.className="toast show "+(k||"");setTimeout(()=>t.className="toast",3400);}
function escapeHtml(s){return (s||"").replace(/[&<>]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;"}[c]));}

let TEMPLATES=[], ACTIVE_TPL="t1";
function tplLabel(k){const t=TEMPLATES.find(x=>x.key===k);return t?t.label:k;}

function pageHeight(f){try{const d=f.contentDocument;return Math.max(d.documentElement.scrollHeight,d.body?d.body.scrollHeight:0)||0;}catch(e){return 0;}}
function measureFit(f){f.style.height=(pageHeight(f)||2600)+"px";}
function sizeScale(fr){
  const w=+fr.dataset.w, avail=fr.clientWidth||1, k=avail/w;
  const sc=fr.querySelector(".screen"), f=fr.querySelector("iframe");
  const h=pageHeight(f)||2200;
  sc.style.width=w+"px"; sc.style.height=h+"px"; sc.style.transform="scale("+k+")"; fr.style.height=(h*k)+"px";
}
function initPreview(){
  $$(".frame.fit").forEach(fr=>{
    const w=+fr.dataset.w, vh=+fr.dataset.vh, sb=18;
    fr.style.width=(w+sb)+"px"; fr.style.height=vh+"px";
    const f=fr.querySelector("iframe"); f.style.width=w+"px";
    f.addEventListener("load",()=>measureFit(f)); measureFit(f);
  });
  $$(".frame.scale").forEach(fr=>{const f=fr.querySelector("iframe");f.addEventListener("load",()=>sizeScale(fr));sizeScale(fr);});
}
let rt;window.addEventListener("resize",()=>{clearTimeout(rt);rt=setTimeout(()=>$$(".frame.scale").forEach(sizeScale),120);});
function reloadPreview(){const t=Date.now();["pv-mobile","pv-tablet","pv-desktop"].forEach(id=>{const f=document.getElementById(id);if(f)f.src="/?_t="+t;});}
$("#reload").onclick=reloadPreview;
$("#dl-build").onclick=()=>{toast("Собираю макет "+tplLabel(ACTIVE_TPL)+"…","ok");window.location.href="/admin/api/build";};

function renderTplToggle(){
  const box=$("#tpl");box.innerHTML="";
  TEMPLATES.forEach(t=>{
    const b=document.createElement("button");b.textContent=t.label;
    if(t.key===ACTIVE_TPL)b.className="on";
    b.onclick=()=>switchTemplate(t.key);box.appendChild(b);
  });
  $("#cur-tpl").textContent=tplLabel(ACTIVE_TPL);
  $("#pv-tpl").textContent=tplLabel(ACTIVE_TPL);
}
async function loadTemplates(){
  const d=await (await fetch("/admin/api/templates")).json();
  TEMPLATES=d.templates;ACTIVE_TPL=d.active;renderTplToggle();
}
async function switchTemplate(key){
  if(key===ACTIVE_TPL)return;
  const r=await fetch("/admin/api/templates",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({key})});
  const d=await r.json();
  if(d.ok){ACTIVE_TPL=key;renderTplToggle();toast("Шаблон: "+tplLabel(key),"ok");await loadBrands();reloadPreview();}
  else toast(d.error||"Ошибка","err");
}

async function loadBrands(){
  const box=$("#brands");
  try{
    const d=await (await fetch("/admin/api/brands")).json();
    if(!d.ocr)$("#ocr-hint").innerHTML="OCR недоступен в этом контейнере — название берётся из поля или авто-имя.";
    box.innerHTML="";
    if(d.custom){const n=document.createElement("div");n.className="muted";n.style.marginBottom="10px";
      n.textContent="Активная brand.css шаблона изменена вручную и не совпадает ни с одной темой.";box.appendChild(n);}
    d.brands.forEach(b=>{
      const row=document.createElement("div");row.className="brow";
      const meta=document.createElement("div");meta.className="meta";
      const nm=document.createElement("div");nm.className="name";nm.textContent=b.label;
      const fl=document.createElement("div");fl.className="file";fl.textContent=b.file;
      const sw=document.createElement("div");sw.className="sw";
      ["c-accent","c-cta","c-bg","surface"].forEach(c=>{if(b.colors&&b.colors[c]){const i=document.createElement("i");i.style.background=b.colors[c];i.title=c+": "+b.colors[c];sw.appendChild(i);}});
      meta.appendChild(nm);meta.appendChild(fl);if(sw.children.length)meta.appendChild(sw);
      const right=document.createElement("div");right.className="row";
      if(b.active){const bd=document.createElement("span");bd.className="badge";bd.textContent="активна";right.appendChild(bd);}
      else{const bt=document.createElement("button");bt.className="apply";bt.textContent="Применить";bt.onclick=()=>switchBrand(b.key,bt);right.appendChild(bt);}
      row.appendChild(meta);row.appendChild(right);box.appendChild(row);
    });
    if(!d.brands.length)box.innerHTML="<div class='muted'>Тем не найдено в themes/</div>";
  }catch(e){box.innerHTML="<div class='muted'>Ошибка загрузки: "+e+"</div>";}
}
async function switchBrand(key,btn){
  if(btn){btn.disabled=true;btn.textContent="…";}
  try{
    const d=await (await fetch("/admin/api/switch",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({key})})).json();
    if(d.ok){toast("Тема «"+key+"» → "+tplLabel(ACTIVE_TPL),"ok");await loadBrands();reloadPreview();}else toast(d.error||"Ошибка","err");
  }catch(e){toast(String(e),"err");}
  finally{if(btn){btn.disabled=false;btn.textContent="Применить";}}
}
$("#ex-run").onclick=async()=>{
  const files=$("#ex-files").files;
  if(!files.length){toast("Выберите скриншоты","err");return;}
  if(files.length>10){toast("Не больше 10 файлов","err");return;}
  const fd=new FormData();
  fd.append("name",$("#ex-name").value||"");
  fd.append("enforce_contrast",$("#ex-contrast").checked?"1":"0");
  fd.append("variants",$("#ex-variants").value||"0");
  for(const f of files)fd.append("images",f);
  const btn=$("#ex-run");btn.disabled=true;btn.textContent="Обработка…";
  const res=$("#ex-result");res.innerHTML="<span class='muted'>Запуск экстрактора…</span>";
  try{
    const d=await (await fetch("/admin/api/extract",{method:"POST",body:fd})).json();
    if(d.ok){
      toast("Создана тема "+d.file,"ok");
      let html="<p class='muted'>Тема: <b style='color:var(--txt)'>"+escapeHtml(d.name)+"</b> → <code>themes/"+escapeHtml(d.file)+"</code> (нажмите «Применить» в списке).</p>";
      if(d.detected)html+="<p class='muted'>Название распознано с логотипа: <b style='color:var(--txt)'>"+escapeHtml(d.detected)+"</b></p>";
      if(d.report)html+="<details open><summary>Отчёт по палитре</summary><pre>"+escapeHtml(d.report)+"</pre></details>";
      res.innerHTML=html;await loadBrands();
    }else{res.innerHTML="<pre>"+escapeHtml((d.error||"")+"\n"+(d.stderr||"")+"\n"+(d.stdout||""))+"</pre>";toast("Ошибка экстрактора","err");}
  }catch(e){res.innerHTML="<pre>"+escapeHtml(String(e))+"</pre>";toast(String(e),"err");}
  finally{btn.disabled=false;btn.textContent="Сгенерировать тему";}
};

(async()=>{await loadTemplates();await loadBrands();initPreview();})();
window.addEventListener("load",()=>setTimeout(()=>{$$(".frame.fit").forEach(fr=>measureFit(fr.querySelector("iframe")));$$(".frame.scale").forEach(sizeScale);},150));
</script>
</body>
</html>
"""

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 8888)))
