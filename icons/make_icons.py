#!/usr/bin/env python3
"""Generate line-style WebP icons (color #450073) for Vira Agency service pages."""
import math
from PIL import Image, ImageDraw

C = (0x45, 0x00, 0x73, 255)   # #450073
S = 1024                       # supersampled canvas
OUT = 512                      # final size
W = 34                         # stroke width at supersampled scale


def new():
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    return img, ImageDraw.Draw(img)


def cap(d, xy, w=W):
    """round cap helper"""
    x, y = xy
    r = w / 2
    d.ellipse([x - r, y - r, x + r, y + r], fill=C)


def line(d, pts, w=W, close=False, caps=True):
    p = list(pts)
    if close:
        p = p + [p[0]]
    d.line(p, fill=C, width=int(w), joint="curve")
    if caps:
        for pt in p:
            cap(d, pt, w)


def rrect(d, box, r, w=W):
    x0, y0, x1, y1 = box
    d.rounded_rectangle([x0, y0, x1, y1], radius=r, outline=C, width=int(w))


def circle(d, cx, cy, r, w=W):
    d.ellipse([cx - r, cy - r, cx + r, cy + r], outline=C, width=int(w))


def dot(d, cx, cy, r):
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=C)


def arc(d, cx, cy, r, a0, a1, w=W):
    d.arc([cx - r, cy - r, cx + r, cy + r], a0, a1, fill=C, width=int(w))


def arrow_head(d, tip, ang, size=90, w=W):
    """V shaped arrow head pointing along ang (degrees)."""
    tx, ty = tip
    for delta in (150, -150):
        a = math.radians(ang + delta)
        line(d, [(tx, ty), (tx + size * math.cos(a), ty + size * math.sin(a))], w)


def save(img, name):
    img = img.resize((OUT, OUT), Image.LANCZOS)
    img.save(f"webp/{name}.webp", "WEBP", quality=95, method=6, lossless=True)
    print("webp/%s.webp" % name)


# ---------------------------------------------------------------- 1. Online store
def store():
    img, d = new()
    # shopping bag body
    rrect(d, (240, 350, 784, 880), 70)
    # handle
    arc(d, 512, 372, 132, 180, 360)
    line(d, [(380, 372), (380, 400)])
    line(d, [(644, 372), (644, 400)])
    # tag / price lines inside
    line(d, [(400, 640), (624, 640)], w=28)
    line(d, [(440, 740), (584, 740)], w=28)
    save(img, "01-online-store")


# ---------------------------------------------------------------- 2. Corporate
def corporate():
    img, d = new()
    # tall tower
    rrect(d, (200, 300, 560, 860), 40)
    # side building
    rrect(d, (600, 470, 850, 860), 40)
    # windows in tower
    for yy in (400, 520, 640):
        for xx in (290, 420):
            rrect(d, (xx, yy, xx + 80, yy + 70), 16, w=24)
    # windows in side building
    for yy in (560, 680):
        rrect(d, (680, yy, 770, yy + 70), 16, w=24)
    # ground line
    line(d, [(140, 880), (884, 880)])
    save(img, "02-corporate")


# ---------------------------------------------------------------- 3. Medical
def medical():
    img, d = new()
    # shield
    d.line([(512, 170), (840, 300)], fill=C, width=W, joint="curve")
    line(d, [(512, 170), (840, 300), (840, 545)], caps=True)
    line(d, [(512, 170), (184, 300), (184, 545)], caps=True)
    # bottom curves of shield
    arc(d, 512, 545, 328, 0, 90)
    arc(d, 512, 545, 328, 90, 180)
    # medical cross
    line(d, [(512, 340), (512, 640)], w=54)
    line(d, [(362, 490), (662, 490)], w=54)
    save(img, "03-medical")


# ---------------------------------------------------------------- 4. Education
def education():
    img, d = new()
    # graduation cap
    line(d, [(512, 250), (900, 420), (512, 590), (124, 420)], close=True)
    # side bands
    line(d, [(270, 480), (270, 700)])
    line(d, [(754, 480), (754, 700)])
    arc(d, 512, 560, 242, 0, 180)
    # tassel
    line(d, [(900, 420), (900, 640)], w=26)
    dot(d, 900, 672, 44)
    save(img, "04-education")


# ---------------------------------------------------------------- 5. Restaurant
def restaurant():
    img, d = new()
    # fork (left): three tines joined by a rounded base and a handle
    for x in (240, 340, 440):
        line(d, [(x, 170), (x, 330)], w=26)
    line(d, [(240, 330), (440, 330)], w=26)
    line(d, [(340, 330), (340, 870)])
    # knife (right): curved blade tapering into the handle
    line(d, [(700, 500), (700, 870)])
    d.line([(700, 500), (700, 170)], fill=C, width=W, joint="curve")
    arc(d, 620, 335, 183, -60, 60)
    cap(d, (700, 170))
    save(img, "05-restaurant")


# ---------------------------------------------------------------- 6. Redesign
def redesign():
    img, d = new()
    # browser window
    rrect(d, (150, 220, 874, 804), 60)
    line(d, [(150, 360), (874, 360)])
    for i, x in enumerate((240, 320, 400)):
        dot(d, x, 290, 22)
    # circular refresh arrow inside
    cx, cy, r = 512, 590, 150
    arc(d, cx, cy, r, 40, 330)
    arrow_head(d, (cx + r * math.cos(math.radians(35)),
                   cy + r * math.sin(math.radians(35))), 125, 82)
    save(img, "06-redesign")


if __name__ == "__main__":
    store()
    corporate()
    medical()
    education()
    restaurant()
    redesign()
