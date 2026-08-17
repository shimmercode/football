# آیکون‌های خدمات ویرا ایجنسی

آیکون‌های خطی (line-style) با رنگ برند `#450073`، خروجی **WebP** با پس‌زمینه شفاف، ابعاد **512×512** (بدون افت کیفیت / lossless).

| آیکون | فایل | عنوان | لینک |
|---|---|---|---|
| 🛍 | `webp/01-online-store.webp` | طراحی سایت فروشگاهی | https://viraagency.co/website-design/online-store-design/ |
| 🏢 | `webp/02-corporate.webp` | طراحی سایت شرکتی | https://viraagency.co/website-design/corporate-website-design/ |
| ➕ | `webp/03-medical.webp` | طراحی سایت پزشکی | https://viraagency.co/website-design/medical-website-design/ |
| 🎓 | `webp/04-education.webp` | طراحی سایت آموزشی | https://viraagency.co/website-design/طراحی-سایت-آموزشی/ |
| 🍴 | `webp/05-restaurant.webp` | طراحی سایت رستوران | https://viraagency.co/website-design/restaurant-website-design/ |
| 🔄 | `webp/06-redesign.webp` | ریدیزاین سایت | https://viraagency.co/ریدیزاین-سایت/ |

`preview.png` پیش‌نمایش کنار هم همهٔ آیکون‌هاست (فقط برای مرور، در سایت استفاده نکنید).

## تولید مجدد / تغییر رنگ و اندازه

```bash
pip install pillow
cd icons && python3 make_icons.py
```

در فایل `make_icons.py`:

- `C` → رنگ (پیش‌فرض `#450073`)
- `OUT` → اندازهٔ خروجی بر حسب پیکسل (پیش‌فرض 512)
- `W` → ضخامت خط
