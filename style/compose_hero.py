#!/usr/bin/env python3
"""
Compose the Vira hero banner: 3D long-scroll mockup on the left,
Persian typography block on the right — in the reference visual style.
"""
import os
import arabic_reshaper
from bidi.algorithm import get_display
from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.path.join(HERE, "hero-website-design-1405-base.png")
OUT = os.path.join(HERE, "hero-website-design-1405.png")
OUT_WEBP = os.path.join(HERE, "hero-website-design-1405.webp")

F_BOLD = os.path.join(HERE, "fonts", "Vazirmatn-FD-Bold.ttf")
F_MED = os.path.join(HERE, "fonts", "Vazirmatn-FD-Medium.ttf")

W, H = 1920, 1080
BG = (255, 255, 255, 255)
PURPLE = (0x45, 0x00, 0x73)
DARK = (0x22, 0x10, 0x33)
GREY = (0x5A, 0x50, 0x66)
ACCENT = (0x7A, 0x3F, 0xC4)


def fa(text):
    """reshape + bidi so Persian renders connected and right-to-left"""
    return get_display(arabic_reshaper.reshape(text))


def font(path, size):
    return ImageFont.truetype(path, size)


def text_rtl(d, xy_right, s, f, fill, anchor_y="top"):
    """draw Persian text right-aligned at x = xy_right[0]"""
    x, y = xy_right
    t = fa(s)
    bbox = d.textbbox((0, 0), t, font=f)
    d.text((x - (bbox[2] - bbox[0]), y), t, font=f, fill=fill)
    return y + (bbox[3] - bbox[1])


def main():
    canvas = Image.new("RGBA", (W, H), BG)

    # ---------------- soft brand glow in the background (very subtle)
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    gd.ellipse([-200, 250, 760, 1100], fill=(0x7A, 0x3F, 0xC4, 16))
    gd.ellipse([1250, -180, 2100, 560], fill=(0x45, 0x00, 0x73, 12))
    from PIL import ImageFilter
    glow = glow.filter(ImageFilter.GaussianBlur(150))
    canvas.alpha_composite(glow)

    # ---------------- 3D mockup on the left
    mock = Image.open(BASE).convert("RGBA")
    # drop the near-white background so it blends with the canvas
    px = mock.load()
    mw, mh = mock.size
    for yy in range(mh):
        for xx in range(mw):
            r, g, b, a = px[xx, yy]
            if r > 246 and g > 246 and b > 246:
                px[xx, yy] = (r, g, b, 0)
    target_w = 1340
    ratio = target_w / mock.width
    mock = mock.resize((target_w, int(mock.height * ratio)), Image.LANCZOS)
    canvas.alpha_composite(mock, (-110, (H - mock.height) // 2 + 10))

    d = ImageDraw.Draw(canvas)
    RX = W - 110          # right margin (RTL baseline)

    # ---------------- eyebrow pill
    f_pill = font(F_MED, 30)
    pill_txt = fa("ویرا | آژانس دیجیتال")
    pb = d.textbbox((0, 0), pill_txt, font=f_pill)
    pw, ph = pb[2] - pb[0], pb[3] - pb[1]
    px0, py0 = RX - pw - 52, 112
    d.rounded_rectangle([px0, py0, RX + 6, py0 + ph + 36], radius=(ph + 36) // 2,
                        fill=(0xEE, 0xE4, 0xF7, 255))
    d.text((px0 + 26, py0 + 16 - pb[1]), pill_txt, font=f_pill, fill=PURPLE)

    # ---------------- headline
    y = 208
    f_h1 = font(F_BOLD, 82)
    text_rtl(d, (RX, y), "طراحی سایت حرفه‌ای", f_h1, DARK)
    y += 108
    text_rtl(d, (RX, y), "در سال ۱۴۰۵", f_h1, PURPLE)
    y += 124

    # underline accent
    d.rounded_rectangle([RX - 190, y, RX, y + 12], radius=6, fill=ACCENT)
    y += 52

    # ---------------- body copy
    f_p = font(F_MED, 34)
    for ln in ["خدمات طراحی سایت شرکتی، فروشگاهی و اختصاصی",
               "با تمرکز بر سرعت، طراحی واکنش‌گرا، زیرساخت",
               "مناسب سئو و قابلیت توسعه متناسب با هر کسب‌وکار"]:
        text_rtl(d, (RX, y), ln, f_p, GREY)
        y += 56

    # ---------------- offer card
    y += 34
    card_x0, card_x1 = RX - 760, RX + 6
    card_y0 = y
    card_y1 = y + 226
    d.rounded_rectangle([card_x0, card_y0, card_x1, card_y1], radius=32,
                        fill=(0x45, 0x00, 0x73, 255))

    f_off = font(F_BOLD, 46)
    text_rtl(d, (card_x1 - 44, card_y0 + 36),
             "۲۰٪ تخفیف طراحی سایت", f_off, (255, 255, 255))
    f_off2 = font(F_BOLD, 38)
    text_rtl(d, (card_x1 - 44, card_y0 + 104),
             "از ۱ تا ۵ هر ماه", f_off2, (0xD9, 0xB8, 0xFF))

    f_sm = font(F_MED, 28)
    text_rtl(d, (card_x1 - 44, card_y0 + 170),
             "مشاوره رایگان طراحی سایت — همین حالا ثبت کنید", f_sm,
             (0xE6, 0xD6, 0xF5))

    # ---------------- CTA button
    y = card_y1 + 40
    f_cta = font(F_BOLD, 34)
    cta = fa("ثبت درخواست مشاوره رایگان")
    cb = d.textbbox((0, 0), cta, font=f_cta)
    cw, ch = cb[2] - cb[0], cb[3] - cb[1]
    bx0, bx1 = RX - cw - 118, RX + 6
    by0, by1 = y, y + ch + 44
    d.rounded_rectangle([bx0, by0, bx1, by1], radius=(by1 - by0) // 2, fill=PURPLE)
    d.text((bx0 + 78, by0 + 22 - cb[1]), cta, font=f_cta, fill=(255, 255, 255))

    # arrow inside button (pointing left, RTL direction)
    ax, ay = bx0 + 26, (by0 + by1) // 2
    d.line([(ax + 13, ay - 10), (ax, ay), (ax + 13, ay + 10)],
           fill=(255, 255, 255), width=5, joint="curve")
    d.line([(ax + 2, ay), (ax + 26, ay)], fill=(255, 255, 255), width=5)

    out = canvas.convert("RGB")
    out.save(OUT, quality=95)
    out.save(OUT_WEBP, "WEBP", quality=92, method=6)
    print(OUT, OUT_WEBP)


if __name__ == "__main__":
    main()
