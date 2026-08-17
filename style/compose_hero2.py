#!/usr/bin/env python3
"""
بنر «طراحی سایت حرفه‌ای در سال ۱۴۰۵» — نسخهٔ اختصاصی ویرا
ماکاپ سه‌بعدی پنل‌های شناور + تایپوگرافی فارسی سمت راست
"""
import os
import arabic_reshaper
from bidi.algorithm import get_display
from PIL import Image, ImageDraw, ImageFont, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.path.join(HERE, "hero2-base.png")
OUT_PNG = os.path.join(HERE, "hero-vira-1405.png")
OUT_WEBP = os.path.join(HERE, "hero-vira-1405.webp")

F_BOLD = os.path.join(HERE, "fonts", "Vazirmatn-FD-Bold.ttf")
F_MED = os.path.join(HERE, "fonts", "Vazirmatn-FD-Medium.ttf")

W, H = 1920, 1080
PURPLE = (0x45, 0x00, 0x73)
PURPLE_L = (0x7A, 0x3F, 0xC4)
LILAC = (0xC7, 0x9B, 0xFF)
DARK = (0x1E, 0x0C, 0x2E)
GREY = (0x60, 0x56, 0x6D)


def fa(t):
    return get_display(arabic_reshaper.reshape(t))


def F(p, s):
    return ImageFont.truetype(p, s)


def rtl(d, x_right, y, s, f, fill):
    """راست‌چین: متن طوری کشیده می‌شود که لبهٔ راستش روی x_right بنشیند"""
    t = fa(s)
    b = d.textbbox((0, 0), t, font=f)
    d.text((x_right - (b[2] - b[0]) - b[0], y - b[1]), t, font=f, fill=fill)
    return b[3] - b[1]


def rtl_w(d, s, f):
    t = fa(s)
    b = d.textbbox((0, 0), t, font=f)
    return b[2] - b[0]


def blend(canvas, fn):
    """draw translucent shapes on a temp layer, then alpha-composite"""
    layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    fn(ImageDraw.Draw(layer))
    canvas.alpha_composite(layer)


def main():
    # ---------- بوم و پس‌زمینه
    canvas = Image.new("RGB", (W, H), (0xFA, 0xF8, 0xFD))

    # هالهٔ نرم رنگی
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    g = ImageDraw.Draw(glow)
    g.ellipse([-300, 150, 900, 1150], fill=(0x7A, 0x3F, 0xC4, 26))
    g.ellipse([1150, -250, 2150, 650], fill=(0x45, 0x00, 0x73, 14))
    canvas = Image.alpha_composite(
        canvas.convert("RGBA"), glow.filter(ImageFilter.GaussianBlur(180)))

    # ---------- ماکاپ سه‌بعدی سمت چپ
    m = Image.open(BASE).convert("RGBA")
    tw = 1420
    m = m.resize((tw, int(m.height * tw / m.width)), Image.LANCZOS)
    # محو کردن نرم لبه‌ها تا درز تصویر پایه دیده نشود
    fade = Image.new("L", m.size, 255)
    fd = ImageDraw.Draw(fade)
    edge = 190
    for i in range(edge):
        v = int(255 * i / edge)
        fd.line([(m.width - 1 - i, 0), (m.width - 1 - i, m.height)], fill=v)
        fd.line([(0, i), (m.width, i)], fill=min(255, v + 40))
        fd.line([(0, m.height - 1 - i), (m.width, m.height - 1 - i)],
                fill=min(255, v + 40))
    m.putalpha(fade)
    canvas.alpha_composite(m, (-130, (H - m.height) // 2))

    d = ImageDraw.Draw(canvas)
    RX = W - 120                       # لبهٔ راست ستون متن

    # ---------- برچسب بالا (pill)
    f_pill = F(F_MED, 30)
    pt = fa("ویرا  |  آژانس دیجیتال")
    b = d.textbbox((0, 0), pt, font=f_pill)
    pw, ph = b[2] - b[0], b[3] - b[1]
    x0, y0 = RX - pw - 56, 128
    d.rounded_rectangle([x0, y0, RX, y0 + ph + 38],
                        radius=(ph + 38) // 2, fill=(0xFF, 255, 255, 255),
                        outline=(0xE3, 0xD4, 0xF2), width=2)
    d.ellipse([x0 + 24, y0 + ph // 2 + 8, x0 + 38, y0 + ph // 2 + 22], fill=PURPLE_L)
    d.text((x0 + 50, y0 + 19 - b[1]), pt, font=f_pill, fill=PURPLE)

    # ---------- تیتر
    y = 232
    f_h1 = F(F_BOLD, 88)
    rtl(d, RX, y, "طراحی سایت حرفه‌ای", f_h1, DARK)
    y += 118

    # «در سال ۱۴۰۵» با هایلایت پشت عدد
    t2 = "در سال ۱۴۰۵"
    w2 = rtl_w(d, t2, f_h1)
    blend(canvas, lambda dd: dd.rounded_rectangle(
        [RX - w2 - 26, y - 20, RX + 16, y + 92], radius=22,
        fill=(0x7A, 0x3F, 0xC4, 30)))
    d = ImageDraw.Draw(canvas)
    rtl(d, RX, y, t2, f_h1, PURPLE)
    y += 138

    # ---------- متن توضیحی
    f_p = F(F_MED, 35)
    for ln in ["خدمات طراحی سایت شرکتی، فروشگاهی و اختصاصی",
               "با تمرکز بر سرعت، طراحی واکنش‌گرا، زیرساخت مناسب",
               "سئو و قابلیت توسعه متناسب با اهداف هر کسب‌وکار"]:
        rtl(d, RX, y, ln, f_p, GREY)
        y += 58

    # ---------- کارت پیشنهاد ماهانه (گرادیان بنفش)
    y += 34
    cw, chh = 800, 236
    cx0, cy0 = RX - cw, y
    card = Image.new("RGBA", (cw, chh), (0, 0, 0, 0))
    cg = ImageDraw.Draw(card)
    for i in range(cw):
        t = i / cw
        cg.line([(i, 0), (i, chh)],
                fill=(int(0x45 + (0x7A - 0x45) * t),
                      int(0x00 + (0x3F - 0x00) * t),
                      int(0x73 + (0xC4 - 0x73) * t), 255))
    mask = Image.new("L", (cw, chh), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, cw - 1, chh - 1], radius=36, fill=255)
    card.putalpha(mask)

    # سایهٔ کارت
    sh = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle(
        [cx0 + 12, cy0 + 22, cx0 + cw + 12, cy0 + chh + 22],
        radius=36, fill=(0x45, 0x00, 0x73, 60))
    canvas.alpha_composite(sh.filter(ImageFilter.GaussianBlur(28)))
    canvas.alpha_composite(card, (cx0, cy0))
    d = ImageDraw.Draw(canvas)

    # نشان درصد گوشهٔ چپ کارت
    blend(canvas, lambda dd: dd.ellipse(
        [cx0 + 34, cy0 + chh // 2 - 60, cx0 + 154, cy0 + chh // 2 + 60],
        fill=(255, 255, 255, 46)))
    d = ImageDraw.Draw(canvas)
    f_pc = F(F_BOLD, 52)
    pc = fa("۲۰٪")
    pb = d.textbbox((0, 0), pc, font=f_pc)
    d.text((cx0 + 94 - (pb[2] - pb[0]) / 2 - pb[0],
            cy0 + chh // 2 - (pb[3] - pb[1]) / 2 - pb[1]),
           pc, font=f_pc, fill=(255, 255, 255))

    tx = RX - 46
    rtl(d, tx, cy0 + 44, "تخفیف طراحی سایت", F(F_BOLD, 46), (255, 255, 255))
    rtl(d, tx, cy0 + 112, "از ۱ تا ۵ هر ماه", F(F_BOLD, 38), LILAC)
    rtl(d, tx, cy0 + 174, "به همراه مشاوره رایگان انتخاب ساختار پروژه",
        F(F_MED, 26), (0xEA, 0xDD, 0xF8))

    # ---------- دکمهٔ CTA
    y = cy0 + chh + 40
    f_c = F(F_BOLD, 35)
    ct = fa("ثبت درخواست مشاوره رایگان")
    cb = d.textbbox((0, 0), ct, font=f_c)
    ctw, cth = cb[2] - cb[0], cb[3] - cb[1]
    bx0, bx1 = RX - ctw - 132, RX
    by0, by1 = y, y + cth + 48
    d.rounded_rectangle([bx0, by0, bx1, by1], radius=(by1 - by0) // 2, fill=PURPLE)
    d.text((bx0 + 86, by0 + 24 - cb[1]), ct, font=f_c, fill=(255, 255, 255))
    ax, ay = bx0 + 34, (by0 + by1) // 2
    d.line([(ax + 14, ay - 11), (ax, ay), (ax + 14, ay + 11)],
           fill=(255, 255, 255), width=5, joint="curve")
    d.line([(ax + 2, ay), (ax + 30, ay)], fill=(255, 255, 255), width=5)

    out = canvas.convert("RGB")
    out.save(OUT_PNG, quality=96)
    out.save(OUT_WEBP, "WEBP", quality=92, method=6)
    print(OUT_PNG, OUT_WEBP)


if __name__ == "__main__":
    main()
