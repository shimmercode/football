#!/usr/bin/env python3
"""
اینفوگرافیک «خدمات طراحی سایت در ویرا»
چیدمان کارتی ۳×۲ با آیکون‌های ست ویرا، رنگ برند #450073
"""
import os
import arabic_reshaper
from bidi.algorithm import get_display
from PIL import Image, ImageDraw, ImageFont, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
ICONS = os.path.join(ROOT, "icons", "v2", "webp")
FONTS = os.path.join(ROOT, "style", "fonts")
F_BOLD = os.path.join(FONTS, "Vazirmatn-FD-Bold.ttf")
F_MED = os.path.join(FONTS, "Vazirmatn-FD-Medium.ttf")
F_MED_LAT = os.path.join(FONTS, "Vazirmatn-Medium.ttf")

OUT_PNG = os.path.join(HERE, "vira-website-services.png")
OUT_WEBP = os.path.join(HERE, "vira-website-services.webp")

W, H = 1600, 1880

PURPLE = (0x45, 0x00, 0x73)
PURPLE_L = (0x7A, 0x3F, 0xC4)
LILAC = (0xC7, 0x9B, 0xFF)
DARK = (0x1E, 0x0C, 0x2E)
GREY = (0x63, 0x59, 0x70)
BG = (0xFA, 0xF8, 0xFD)
CARD_BORDER = (0xE8, 0xDE, 0xF3)

SERVICES = [
    ("01", "02-corporate.webp", "طراحی سایت شرکتی و سازمانی",
     ["معرفی برند، خدمات و جذب مشتریان",
      "کسب‌وکارهای B2B و B2C"]),
    ("02", "01-online-store.webp", "طراحی سایت فروشگاهی",
     ["راه‌اندازی فروشگاه اینترنتی با ووکامرس",
      "یا توسعه اختصاصی متناسب با فرایند فروش"]),
    ("03", "03-medical.webp", "طراحی سایت پزشکی",
     ["وب‌سایت پزشکان و مراکز درمانی",
      "با معرفی خدمات و نوبت‌دهی آنلاین"]),
    ("04", "04-education.webp", "طراحی سایت آموزشی",
     ["آکادمی آنلاین با مدیریت دوره‌ها،",
      "کاربران و پرداخت اینترنتی"]),
    ("05", "07-custom.webp", "طراحی سایت اختصاصی",
     ["طراحی UI/UX و توسعه امکانات سفارشی",
      "با فناوری‌هایی مانند Laravel و React"]),
    ("06", "06-redesign.webp", "بازطراحی سایت",
     ["بهبود تجربه کاربری، ظاهر و عملکرد فنی",
      "با حفظ ساختار و ارزش فعلی سئو"]),
]


def fa(t):
    return get_display(arabic_reshaper.reshape(t))


def F(p, s):
    return ImageFont.truetype(p, s)


def rtl(d, x_right, y, s, f, fill):
    t = fa(s)
    b = d.textbbox((0, 0), t, font=f)
    d.text((x_right - (b[2] - b[0]) - b[0], y - b[1]), t, font=f, fill=fill)
    return b[3] - b[1]


def ctr(d, cx, y, s, f, fill):
    t = fa(s)
    b = d.textbbox((0, 0), t, font=f)
    d.text((cx - (b[2] - b[0]) / 2 - b[0], y - b[1]), t, font=f, fill=fill)
    return b[3] - b[1]


def blend(canvas, fn):
    layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    fn(ImageDraw.Draw(layer))
    canvas.alpha_composite(layer)


def shadow(canvas, box, radius, color, blur, offset=(0, 14)):
    s = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    ImageDraw.Draw(s).rounded_rectangle(
        [box[0] + offset[0], box[1] + offset[1],
         box[2] + offset[0], box[3] + offset[1]],
        radius=radius, fill=color)
    canvas.alpha_composite(s.filter(ImageFilter.GaussianBlur(blur)))


def tint_icon(path, size, color):
    ic = Image.open(path).convert("RGBA")
    ic = ic.resize((size, size), Image.LANCZOS)
    solid = Image.new("RGBA", ic.size, color + (255,))
    solid.putalpha(ic.split()[3])
    return solid


def main():
    canvas = Image.new("RGBA", (W, H), BG)

    # ---------------- هالهٔ پس‌زمینه
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    g = ImageDraw.Draw(glow)
    g.ellipse([-350, -400, 800, 500], fill=(0x7A, 0x3F, 0xC4, 30))
    g.ellipse([1000, 1450, 2050, 2350], fill=(0x45, 0x00, 0x73, 24))
    canvas.alpha_composite(glow.filter(ImageFilter.GaussianBlur(200)))

    d = ImageDraw.Draw(canvas)

    # ================= هدر =================
    cx = W // 2

    # برچسب بالا
    f_pill = F(F_MED, 30)
    pt = fa("ویرا  |  آژانس دیجیتال")
    pb = d.textbbox((0, 0), pt, font=f_pill)
    pw, ph = pb[2] - pb[0], pb[3] - pb[1]
    px0, py0 = cx - pw / 2 - 46, 96
    d.rounded_rectangle([px0, py0, px0 + pw + 92, py0 + ph + 34],
                        radius=(ph + 34) // 2, fill=(255, 255, 255),
                        outline=CARD_BORDER, width=2)
    d.ellipse([px0 + 26, py0 + ph / 2 + 6, px0 + 40, py0 + ph / 2 + 20],
              fill=PURPLE_L)
    d.text((px0 + 54, py0 + 17 - pb[1]), pt, font=f_pill, fill=PURPLE)

    # تیتر اصلی
    y = 206
    ctr(d, cx, y, "خدمات طراحی سایت در ویرا", F(F_BOLD, 74), DARK)
    y += 104

    # خط تزئینی زیر تیتر
    d.rounded_rectangle([cx - 90, y, cx + 90, y + 11], radius=6, fill=PURPLE_L)
    d.ellipse([cx - 130, y - 6, cx - 106, y + 18], fill=LILAC)
    d.ellipse([cx + 106, y - 6, cx + 130, y + 18], fill=LILAC)
    y += 62

    # زیرعنوان
    f_sub = F(F_MED, 33)
    f_subb = F(F_BOLD, 33)
    ctr(d, cx, y, "طراحی سایت شرکتی، فروشگاهی و اختصاصی", f_subb, PURPLE)
    y += 54
    for ln in ["با ساختار و فناوری متناسب با نیاز، بودجه",
               "و مسیر توسعه هر کسب‌وکار"]:
        ctr(d, cx, y, ln, f_sub, GREY)
        y += 50

    # ================= کارت‌ها =================
    top = 560
    gap = 34
    m = 70
    cw = (W - 2 * m - 2 * gap) // 3
    chh = 462

    f_num = F(F_BOLD, 26)
    f_title = F(F_BOLD, 34)
    f_body = F(F_MED, 26)

    for i, (num, icon, title, lines) in enumerate(SERVICES):
        col = i % 3
        row = i // 3
        # ستون‌ها از راست به چپ چیده می‌شوند (RTL)
        x0 = m + (2 - col) * (cw + gap)
        y0 = top + row * (chh + gap)
        x1, y1 = x0 + cw, y0 + chh

        shadow(canvas, (x0, y0, x1, y1), 34, (0x45, 0x00, 0x73, 34), 26)
        dd = ImageDraw.Draw(canvas)
        dd.rounded_rectangle([x0, y0, x1, y1], radius=34,
                             fill=(255, 255, 255), outline=CARD_BORDER, width=2)

        # نوار رنگی بالای کارت
        bar = Image.new("RGBA", (cw - 120, 9), (0, 0, 0, 0))
        bd = ImageDraw.Draw(bar)
        for px in range(bar.width):
            t = px / bar.width
            bd.line([(px, 0), (px, 9)],
                    fill=(int(0x45 + (0xC7 - 0x45) * t),
                          int(0x00 + (0x9B - 0x00) * t),
                          int(0x73 + (0xFF - 0x73) * t), 255))
        mk = Image.new("L", bar.size, 0)
        ImageDraw.Draw(mk).rounded_rectangle([0, 0, bar.width - 1, 8],
                                             radius=4, fill=255)
        bar.putalpha(mk)
        canvas.alpha_composite(bar, (x0 + 60, y0 - 4))
        dd = ImageDraw.Draw(canvas)

        # شمارهٔ کارت (گوشهٔ چپ-بالا)
        nb = dd.textbbox((0, 0), num, font=f_num)
        dd.text((x0 + 34, y0 + 34 - nb[1]), num, font=f_num,
                fill=(0xCB, 0xBD, 0xD8))

        # آیکون داخل دایرهٔ ملایم
        icx = x1 - 96
        icy = y0 + 92
        blend(canvas, lambda dd2, a=icx, b=icy: dd2.ellipse(
            [a - 54, b - 54, a + 54, b + 54], fill=(0x7A, 0x3F, 0xC4, 30)))
        ic = tint_icon(os.path.join(ICONS, icon), 74, PURPLE)
        canvas.alpha_composite(ic, (icx - 37, icy - 37))
        dd = ImageDraw.Draw(canvas)

        # عنوان (ممکن است دو سطر شود)
        ty = y0 + 190
        tx = x1 - 42
        words = title.split()
        line, out_lines = "", []
        for wd in words:
            trial = (line + " " + wd).strip()
            if dd.textbbox((0, 0), fa(trial), font=f_title)[2] > cw - 84:
                out_lines.append(line)
                line = wd
            else:
                line = trial
        out_lines.append(line)
        for ln in out_lines:
            rtl(dd, tx, ty, ln, f_title, DARK)
            ty += 48

        # متن توضیحی
        ty += 14
        f_body_lat = F(F_MED_LAT, 26)
        for ln in lines:
            has_lat = any(c.isascii() and c.isalpha() for c in ln)
            rtl(dd, tx, ty, ln, f_body_lat if has_lat else f_body, GREY)
            ty += 42

        # لینک پایین کارت
        ly = y1 - 62
        link = "دریافت مشاوره" if num == "05" else "مشاهده جزئیات"
        lw = dd.textbbox((0, 0), fa(link), font=f_body)[2]
        rtl(dd, tx, ly, link, f_body, PURPLE)
        ax = tx - lw - 40
        dd.line([(ax + 11, ly + 5), (ax, ly + 14), (ax + 11, ly + 23)],
                fill=PURPLE, width=4, joint="curve")
        dd.line([(ax + 2, ly + 14), (ax + 26, ly + 14)], fill=PURPLE, width=4)
        # زیرخط لینک
        dd.line([(tx - lw - 40, ly + 40), (tx, ly + 40)], fill=(0xD8, 0xC6, 0xEB),
                width=3)

    # ================= نوار پایانی (CTA) =================
    by0 = top + 2 * (chh + gap) + 34
    by1 = by0 + 170
    bx0, bx1 = m, W - m
    shadow(canvas, (bx0, by0, bx1, by1), 40, (0x45, 0x00, 0x73, 70), 34)

    band = Image.new("RGBA", (bx1 - bx0, by1 - by0), (0, 0, 0, 0))
    bdw = ImageDraw.Draw(band)
    for px in range(band.width):
        t = px / band.width
        bdw.line([(px, 0), (px, band.height)],
                 fill=(int(0x45 + (0x7A - 0x45) * t),
                       int(0x00 + (0x3F - 0x00) * t),
                       int(0x73 + (0xC4 - 0x73) * t), 255))
    mk = Image.new("L", band.size, 0)
    ImageDraw.Draw(mk).rounded_rectangle([0, 0, band.width - 1, band.height - 1],
                                         radius=40, fill=255)
    band.putalpha(mk)
    canvas.alpha_composite(band, (bx0, by0))
    d = ImageDraw.Draw(canvas)

    rtl(d, bx1 - 60, by0 + 42, "مشاوره رایگان انتخاب ساختار پروژه",
        F(F_BOLD, 42), (255, 255, 255))
    rtl(d, bx1 - 60, by0 + 104, "viraagency.co", F(F_MED, 30),
        (0xE2, 0xCF, 0xF7))

    # دکمهٔ سفید سمت چپ نوار
    f_cta = F(F_BOLD, 30)
    ct = fa("ثبت درخواست")
    cb = d.textbbox((0, 0), ct, font=f_cta)
    ctw, cth = cb[2] - cb[0], cb[3] - cb[1]
    ex1 = bx0 + 60 + ctw + 76
    ey0 = by0 + (by1 - by0) // 2 - (cth + 44) // 2
    d.rounded_rectangle([bx0 + 60, ey0, ex1, ey0 + cth + 44],
                        radius=(cth + 44) // 2, fill=(255, 255, 255))
    d.text((bx0 + 60 + 38, ey0 + 22 - cb[1]), ct, font=f_cta, fill=PURPLE)

    out = canvas.convert("RGB")
    out.save(OUT_PNG, quality=96)
    out.save(OUT_WEBP, "WEBP", quality=92, method=6)
    print(OUT_PNG, OUT_WEBP)


if __name__ == "__main__":
    main()
