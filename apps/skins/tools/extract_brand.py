#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
extract_brand.py — авто-определение фирменных цветов из референс-скриншотов
и генерация готового css/brand.css под текущий шаблон.

Возможности:
  * кластеризация цветов (k-means) с учётом площади;
  * раскладка по ролям шаблона: акцентная палитра, фон/поверхности, текст,
    вторичный текст, заголовки, CTA/кнопки, вторичная кнопка, ссылки,
    маркеры, меню, RGB-триплеты для рамок/свечения;
  * ПРОВЕРКА КОНТРАСТА (WCAG): текст/фон, текст-на-CTA, вторичный текст —
    с авто-коррекцией светлоты до читаемого уровня;
  * ВАРИАНТЫ АКЦЕНТА: --variants N выдаёт доп. brand.css по другим
    заметным цветам, чтобы выбрать.

Запуск:
  python3 tools/extract_brand.py shot1.png shot2.png ... \
      --name Fraga -o css/brand.fraga.css \
      --report out/report.md --swatch out/palette.svg --variants 2
"""
import argparse, colorsys, glob, os, sys
from PIL import Image
import numpy as np

# ---------- colour helpers ----------
def to_hex(rgb):
    return "#{:02x}{:02x}{:02x}".format(*[max(0, min(255, int(round(c)))) for c in rgb])

def rgb_triplet(rgb):
    return ",".join(str(max(0, min(255, int(round(c))))) for c in rgb)

def srgb_lum(rgb):
    def lin(c):
        c /= 255.0
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = [lin(x) for x in rgb]
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def contrast(a, b):
    la, lb = srgb_lum(a), srgb_lum(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)

def hls_of(rgb):
    h, l, s = colorsys.rgb_to_hls(*[c / 255.0 for c in rgb])
    return h, l, s

def from_hls(h, l, s):
    return tuple(c * 255.0 for c in colorsys.hls_to_rgb(h, l, s))

def adjust_l(rgb, dl):
    h, l, s = hls_of(rgb)
    return from_hls(h, max(0.0, min(1.0, l + dl)), s)

def blend(a, b, t):
    return tuple(a[i] * (1 - t) + b[i] * t for i in range(3))

def hue_dist(h1, h2):
    d = abs(h1 - h2) % 1.0
    return min(d, 1.0 - d)

def best_text_on(bg):
    """#ffffff или тёмный — что контрастнее на данном цвете."""
    dark = (20, 21, 26)
    return "#ffffff" if contrast((255, 255, 255), bg) >= contrast(dark, bg) else "#14151a"

def ensure_contrast(fg, bg, target=4.5, max_steps=24):
    """Двигаем светлоту fg к лучшему контрасту с bg, пока не достигнем target."""
    step = +0.035 if srgb_lum(bg) < 0.5 else -0.035
    cur = tuple(fg)
    for _ in range(max_steps):
        if contrast(cur, bg) >= target:
            break
        nxt = adjust_l(cur, step)
        if nxt == cur:
            break
        cur = nxt
    return cur

# ---------- load + sample ----------
def gather_pixels(paths, max_side=200, bg_for_alpha=(0, 0, 0)):
    chunks, used = [], []
    for p in paths:
        try:
            im = Image.open(p)
        except Exception as e:
            print(f"  ! пропуск {p}: {e}", file=sys.stderr); continue
        if im.mode in ("RGBA", "LA", "P"):
            im = im.convert("RGBA")
            base = Image.new("RGBA", im.size, bg_for_alpha + (255,))
            im = Image.alpha_composite(base, im).convert("RGB")
        else:
            im = im.convert("RGB")
        w, h = im.size
        scale = max_side / max(w, h)
        if scale < 1:
            im = im.resize((max(1, int(w * scale)), max(1, int(h * scale))))
        chunks.append(np.asarray(im).reshape(-1, 3).astype(np.float64))
        used.append(os.path.basename(p))
    if not chunks:
        sys.exit("Нет валидных изображений.")
    return np.vstack(chunks), used

def palette(pixels, k=16):
    try:
        from sklearn.cluster import KMeans
        sample = pixels
        if len(pixels) > 80000:
            idx = np.random.default_rng(42).choice(len(pixels), 80000, replace=False)
            sample = pixels[idx]
        km = KMeans(n_clusters=k, n_init=4, random_state=42)
        labels = km.fit_predict(sample)
        centers, counts = km.cluster_centers_, np.bincount(labels, minlength=k).astype(float)
    except Exception:
        im = Image.fromarray(pixels.reshape(-1, 1, 3).astype("uint8"))
        q = im.quantize(colors=k, method=Image.MEDIANCUT)
        centers = np.array(q.getpalette()[: k * 3]).reshape(-1, 3)
        counts = np.bincount(np.asarray(q).reshape(-1), minlength=k).astype(float)
    total = counts.sum()
    items = [(centers[i], counts[i] / total) for i in range(len(centers)) if counts[i] > 0]
    items.sort(key=lambda x: -x[1])
    return items

# ---------- classify ----------
def classify(items):
    feats = []
    for rgb, cov in items:
        h, l, s = hls_of(rgb)
        feats.append(dict(rgb=tuple(rgb), hex=to_hex(rgb), cov=cov, h=h, l=l, s=s, lum=srgb_lum(rgb)))

    dark = [f for f in feats if f["lum"] < 0.18 and f["s"] < 0.45]
    bg = max(dark, key=lambda f: f["cov"]) if dark else min(feats, key=lambda f: f["lum"])

    light = [f for f in feats if f["lum"] > 0.6 and f["s"] < 0.25 and f["cov"] > 0.002]
    text = max(light, key=lambda f: f["lum"]) if light else max(feats, key=lambda f: f["lum"])

    cand = [f for f in feats if f["s"] >= 0.40 and 0.18 <= f["l"] <= 0.92
            and f["hex"] not in (bg["hex"], text["hex"])]
    for f in cand:
        f["score"] = f["s"] * (f["cov"] ** 0.5)
    cand.sort(key=lambda f: -f["score"])

    cta = cand[0] if cand else text
    alts = [f for f in cand[1:] if hue_dist(f["h"], cta["h"]) > 0.08]
    real = [f for f in alts if f["cov"] >= 0.9 * cta["cov"]]
    if real:
        accent, accent_real = real[0], True
    else:
        a = dict(rgb=adjust_l(cta["rgb"], +0.12)); a["hex"] = to_hex(a["rgb"])
        h, l, s = hls_of(a["rgb"]); a.update(h=h, l=l, s=s, cov=0, lum=srgb_lum(a["rgb"]), score=0)
        accent, accent_real = a, False
    return feats, bg, text, cta, accent, accent_real, cand, alts

# ---------- build tokens ----------
def build_tokens(bg, text, cta, accent, secondary, enforce=False):
    bg_rgb = bg["rgb"]
    # text: нейтрализуем оттенок, поднимаем и гарантируем контраст >= 4.5
    th, tl, ts = hls_of(text["rgb"])
    text_rgb = from_hls(th, max(tl, 0.93), min(ts, 0.04))
    text_rgb = ensure_contrast(text_rgb, bg_rgb, 4.5)
    text_hex = to_hex(text_rgb)
    # muted: смесь + контраст >= 3.0
    muted_rgb = ensure_contrast(blend(text_rgb, bg_rgb, 0.45), bg_rgb, 3.0)
    # accent family
    acc = accent["rgb"]
    acc_hi = adjust_l(acc, +0.12); acc_deep = adjust_l(acc, -0.16)
    sec = secondary["rgb"]
    t = {}
    # accent palette
    t["--gold"]            = to_hex(acc)
    t["--gold-hi"]         = to_hex(acc_hi)
    t["--gold-deep"]       = to_hex(acc_deep)
    t["--accent-rgb"]      = rgb_triplet(acc)
    t["--c-accent"]        = to_hex(acc)
    t["--c-list-marker"]   = to_hex(acc)
    # menu
    t["--c-menu"]          = text_hex
    t["--c-menu-active"]   = cta["hex"]
    # cta (+ опц. --enforce-contrast на паре «текст на CTA»)
    cta_rgb = tuple(cta["rgb"])
    adjustments = []
    DARK_BRAND = (20, 21, 26)   # #14151a — фирменный «тёмный» текст
    def best_on(bgc, fgs):
        return max(fgs, key=lambda fg: contrast(fg, bgc))
    branded = best_on(cta_rgb, [(255, 255, 255), DARK_BRAND])
    cta_text_rgb = branded
    if enforce and contrast(branded, cta_rgb) < 4.5:
        c_before = contrast(branded, cta_rgb)
        # шаг 1: довести ТОЛЬКО цвет текста до чистого чёрного/белого
        pure = best_on(cta_rgb, [(255, 255, 255), (0, 0, 0)])
        if contrast(pure, cta_rgb) >= 4.5:
            cta_text_rgb = pure
            adjustments.append(("--c-cta-text", to_hex(branded), to_hex(pure),
                                c_before, contrast(pure, cta_rgb)))
        else:
            # шаг 2 (страховка): минимально сдвинуть сам CTA до порога
            before_hex = to_hex(cta_rgb)
            step = -0.03 if tuple(pure) == (255, 255, 255) else +0.03  # бел.текст → темним фон; чёрн. → светлим
            for _ in range(40):
                if contrast(pure, cta_rgb) >= 4.5:
                    break
                cta_rgb = adjust_l(cta_rgb, step)
            cta_text_rgb = pure
            adjustments.append(("--c-cta", before_hex, to_hex(cta_rgb),
                                c_before, contrast(pure, cta_rgb)))
    # cta
    t["--c-cta"]           = to_hex(cta_rgb)
    t["--c-cta-hi"]        = to_hex(adjust_l(cta_rgb, +0.12))
    t["--c-cta-2"]         = to_hex(adjust_l(cta_rgb, -0.14))
    t["--c-cta-text"]      = to_hex(cta_text_rgb)
    # secondary
    t["--c-secondary"]     = to_hex(sec)
    t["--c-secondary-2"]   = to_hex(adjust_l(sec, -0.14))
    t["--c-secondary-text"]= best_text_on(sec)
    t["--secondary-rgb"]   = rgb_triplet(sec)
    # heading / text / links
    t["--c-heading"]       = text_hex
    t["--c-text"]          = text_hex
    t["--muted"]           = to_hex(muted_rgb)
    t["--c-link"]          = to_hex(acc)
    t["--c-link-hover"]    = to_hex(acc_hi)
    # bg + surfaces
    t["--c-bg"]            = bg["hex"]
    t["--bg"]              = bg["hex"]
    t["--bg-elev"]         = to_hex(adjust_l(bg_rgb, +0.03))
    t["--surface"]         = to_hex(adjust_l(bg_rgb, +0.05))
    t["--surface-2"]       = to_hex(adjust_l(bg_rgb, +0.08))
    # cta shadow tinted by (final) cta
    cr, cg, cb = [int(c) for c in cta_rgb]
    t["--cta-shadow"]      = f"0 10px 26px -12px rgba({cr},{cg},{cb},0.55)"
    # contrast diagnostics (по итоговым цветам)
    t["_contrast"] = dict(text_bg=contrast(text_rgb, bg_rgb),
                          cta_text=contrast(cta_text_rgb, cta_rgb),
                          muted_bg=contrast(muted_rgb, bg_rgb))
    t["_adjust"] = adjustments
    return t

# ---------- render ----------
def render_css(t, name, sources):
    src = ", ".join(sources)
    return f"""/* ============================================================
   BRAND THEME · {name}  (АВТО-СГЕНЕРИРОВАНО)
   Источники: {src}
   tools/extract_brand.py — цвета и контраст определены автоматически.
   Проверьте акцент/CTA (см. отчёт), типографику задайте по референсу.
   Подключается ПОСЛЕ css/styles.css.
   ============================================================ */
:root {{
  /* акцентная палитра (рамки, eyebrow, цифры, табы, свечение) */
  --gold:            {t['--gold']};
  --gold-hi:         {t['--gold-hi']};
  --gold-deep:       {t['--gold-deep']};
  --accent-rgb:      {t['--accent-rgb']};
  --c-accent:        {t['--c-accent']};
  --c-list-marker:   {t['--c-list-marker']};

  /* меню */
  --c-menu:          {t['--c-menu']};
  --c-menu-active:   {t['--c-menu-active']};

  /* кнопки / CTA */
  --c-cta:           {t['--c-cta']};
  --c-cta-hi:        {t['--c-cta-hi']};
  --c-cta-2:         {t['--c-cta-2']};
  --c-cta-text:      {t['--c-cta-text']};

  /* вторичная / success-кнопка */
  --c-secondary:     {t['--c-secondary']};
  --c-secondary-2:   {t['--c-secondary-2']};
  --c-secondary-text:{t['--c-secondary-text']};
  --secondary-rgb:   {t['--secondary-rgb']};

  /* заголовки / текст / ссылки */
  --c-heading:       {t['--c-heading']};
  --c-text:          {t['--c-text']};
  --muted:           {t['--muted']};
  --c-link:          {t['--c-link']};
  --c-link-hover:    {t['--c-link-hover']};

  /* фон + поверхности */
  --c-bg:            {t['--c-bg']};
  --bg:              {t['--bg']};
  --bg-elev:         {t['--bg-elev']};
  --surface:         {t['--surface']};
  --surface-2:       {t['--surface-2']};

  /* типографика (размеры/шрифт — поправьте по референсу) */
  --fs-content:      16px;
  --fs-menu:         14.5px;
  --fs-h1:           clamp(30px, 5vw, 56px);
  --fs-h2:           clamp(28px, 4vw, 44px);
  --fs-h3:           20px;

  /* форма */
  --radius:          16px;
  --radius-sm:       10px;
  --radius-lg:       24px;
  --btn-radius:      999px;
  --cta-radius:      999px;
  --cta-shadow:      {t['--cta-shadow']};
}}
"""

def render_report(name, sources, bg, text, cta, accent, cand, t):
    c = t["_contrast"]
    adj = t.get("_adjust", [])
    def verdict(v, thr): return "PASS ✅" if v >= thr else "FAIL ⚠️"
    L = [f"# Авто-палитра: {name}\n", "Источники: " + ", ".join(sources) + "\n",
         "## Назначенные роли\n",
         "| Роль | Цвет | Обоснование |", "|------|------|-------------|",
         f"| Фон | `{t['--bg']}` | самый тёмный заметный (lum={bg['lum']:.2f}, площадь={bg['cov']*100:.1f}%) |",
         f"| Текст/заголовки/меню | `{t['--c-text']}` | самый светлый, поджат под контраст |",
         f"| Вторичный текст | `{t['--muted']}` | смесь текста и фона |",
         f"| CTA/кнопки | `{t['--c-cta']}` | макс. насыщенный с площадью (S={cta['s']:.2f}) |",
         f"| Текст на CTA | `{t['--c-cta-text']}` | выбран по контрасту |",
         f"| Акцент/маркеры/рамки/ссылки | `{t['--c-accent']}` | акцентная палитра |",
         f"| Вторичная кнопка | `{t['--c-secondary']}` | второй цвет / производная CTA |",
         "",
         "## Контраст (WCAG)\n",
         "| Пара | Контраст | Норма AA | Итог |", "|------|----------|----------|------|",
         f"| Текст / фон | {c['text_bg']:.2f}:1 | ≥ 4.5 | {verdict(c['text_bg'],4.5)} |",
         f"| Текст на CTA | {c['cta_text']:.2f}:1 | ≥ 4.5 | {verdict(c['cta_text'],4.5)} |",
         f"| Вторичный текст / фон | {c['muted_bg']:.2f}:1 | ≥ 3.0 | {verdict(c['muted_bg'],3.0)} |",
         ""]
    if adj:
        L += ["## Коррекция контраста (--enforce-contrast)\n",
              "| Токен | Было | Стало | Контраст |", "|-------|------|-------|----------|"]
        names = {"--c-cta-text": "цвет текста на CTA", "--c-cta": "цвет CTA-кнопки"}
        for tok, before, after, cb, ca in adj:
            L.append(f"| `{tok}` ({names.get(tok, '')}) | `{before}` | `{after}` | {cb:.2f}:1 → {ca:.2f}:1 |")
        L.append("")
    else:
        if c["cta_text"] < 4.5:
            L.append("> Текст на CTA ниже AA. Запустите с `--enforce-contrast`, чтобы скрипт довёл пару до порога и записал коррекцию.\n")
    L += ["## Кандидаты в акцент (по заметности)\n",
          "| # | Цвет | S | L | Площадь |", "|---|------|---|---|---------|"]
    for i, f in enumerate(cand[:6], 1):
        L.append(f"| {i} | `{f['hex']}` | {f['s']:.2f} | {f['l']:.2f} | {f['cov']*100:.1f}% |")
    L.append("\n> Не тот акцент/CTA? Возьмите hex из таблицы и впишите в brand.css, либо используйте сгенерированные варианты (--variants).\n")
    return "\n".join(L)

def render_swatch_svg(t, name):
    order = [("Фон", t["--bg"]), ("Поверхность", t["--surface"]), ("Текст", t["--c-text"]),
             ("Втор. текст", t["--muted"]), ("CTA", t["--c-cta"]), ("Акцент", t["--c-accent"]),
             ("Вторичн.", t["--c-secondary"]), ("Ссылка", t["--c-link"])]
    w, cell, pad, top = 150, 150, 16, 56
    W = pad * 2 + len(order) * w; H = top + cell + 60
    p = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
         f'<rect width="{W}" height="{H}" fill="{t["--bg"]}"/>',
         f'<text x="{pad}" y="34" fill="{t["--c-text"]}" font-family="sans-serif" font-size="22" font-weight="700">Brand palette · {name}</text>']
    for i, (label, hexv) in enumerate(order):
        x = pad + i * w
        p.append(f'<rect x="{x}" y="{top}" width="{w-10}" height="{cell}" rx="12" fill="{hexv}" stroke="rgba(255,255,255,.12)"/>')
        p.append(f'<text x="{x+10}" y="{top+cell+22}" fill="{t["--c-text"]}" font-family="sans-serif" font-size="14" font-weight="600">{label}</text>')
        p.append(f'<text x="{x+10}" y="{top+cell+42}" fill="{t["--muted"]}" font-family="monospace" font-size="13">{hexv}</text>')
    p.append("</svg>")
    return "\n".join(p)

# ---------- main ----------
def expand(paths):
    out = []
    for p in paths:
        if os.path.isdir(p):
            for ext in ("png", "jpg", "jpeg", "webp"):
                out += sorted(glob.glob(os.path.join(p, f"*.{ext}")))
        else:
            out.append(p)
    return out

def main():
    ap = argparse.ArgumentParser(description="Авто-определение фирменных цветов из скриншотов → brand.css")
    ap.add_argument("images", nargs="+")
    ap.add_argument("--name", default="Brand")
    ap.add_argument("-o", "--output", default="brand.generated.css")
    ap.add_argument("--report", default=None)
    ap.add_argument("--swatch", default=None)
    ap.add_argument("-k", "--clusters", type=int, default=16)
    ap.add_argument("--variants", type=int, default=0, help="сколько доп. brand.css по другим акцентам")
    ap.add_argument("--enforce-contrast", action="store_true",
                    help="довести пару «текст на CTA» до AA (≥4.5): сначала чистым чёрным/белым текстом, в крайнем случае лёгким сдвигом самого CTA; коррекция пишется в отчёт")
    args = ap.parse_args()

    paths = expand(args.images)
    pixels, used = gather_pixels(paths)
    items = palette(pixels, k=args.clusters)
    feats, bg, text, cta, accent, accent_real, cand, alts = classify(items)
    secondary = accent if accent_real else cta
    tokens = build_tokens(bg, text, cta, accent, secondary, enforce=args.enforce_contrast)

    os.makedirs(os.path.dirname(args.output) or ".", exist_ok=True)
    open(args.output, "w", encoding="utf-8").write(render_css(tokens, args.name, used))
    if args.report:
        os.makedirs(os.path.dirname(args.report) or ".", exist_ok=True)
        open(args.report, "w", encoding="utf-8").write(render_report(args.name, used, bg, text, cta, accent, cand, tokens))
    if args.swatch:
        os.makedirs(os.path.dirname(args.swatch) or ".", exist_ok=True)
        open(args.swatch, "w", encoding="utf-8").write(render_swatch_svg(tokens, args.name))

    # варианты акцента
    stem, ext = os.path.splitext(args.output)
    made = []
    for i, alt in enumerate(alts[:args.variants], 1):
        tk = build_tokens(bg, text, cta, alt, alt, enforce=args.enforce_contrast)
        path = f"{stem}.alt{i}{ext}"
        open(path, "w", encoding="utf-8").write(render_css(tk, f"{args.name} (accent #{i}: {alt['hex']})", used))
        made.append(path)

    print(render_report(args.name, used, bg, text, cta, accent, cand, tokens))
    print(f"\n✓ brand.css -> {args.output}")
    if args.report: print(f"✓ отчёт   -> {args.report}")
    if args.swatch: print(f"✓ превью  -> {args.swatch}")
    for m in made: print(f"✓ вариант -> {m}")

if __name__ == "__main__":
    main()
