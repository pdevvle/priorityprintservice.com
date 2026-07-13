"""Generate asymmetric vector test-art PDFs so orientation/mirroring bugs are visible."""
import fitz, os

OUT = os.path.dirname(os.path.abspath(__file__))
PT = 72
BLEED = 0.125

def art_page(doc, w_in, h_in, label, color, trimbox=None):
    page = doc.new_page(width=w_in*PT, height=h_in*PT)
    r = page.rect
    # full-bleed background wash
    page.draw_rect(r, color=None, fill=color, overlay=True)
    # asymmetric markers: solid square TOP-LEFT, ring BOTTOM-RIGHT
    m = 0.35*PT
    page.draw_rect(fitz.Rect(r.x0+m, r.y0+m, r.x0+m+0.5*PT, r.y0+m+0.5*PT), fill=(0,0,0))
    page.draw_circle(fitz.Point(r.x1-m-0.25*PT, r.y1-m-0.25*PT), 0.25*PT, color=(0,0,0), width=3)
    page.insert_text(fitz.Point(r.x0+1.1*PT, r.y0+0.7*PT), label, fontsize=18, color=(0,0,0))
    # arrow pointing "up" (toward y0 / visual top)
    cx = (r.x0+r.x1)/2
    page.draw_line(fitz.Point(cx, r.y0+1.5*PT), fitz.Point(cx, r.y0+0.5*PT), width=4)
    page.draw_line(fitz.Point(cx, r.y0+0.5*PT), fitz.Point(cx-0.15*PT, r.y0+0.8*PT), width=4)
    page.draw_line(fitz.Point(cx, r.y0+0.5*PT), fitz.Point(cx+0.15*PT, r.y0+0.8*PT), width=4)
    if trimbox:
        page.set_trimbox(trimbox)
    return page

# 1. Bleed-inclusive 2-page brochure art for 11x8.5 trim → 11.25x8.75 pages, TrimBox set
doc = fitz.open()
tb = fitz.Rect(BLEED*PT, BLEED*PT, (11+BLEED)*PT, (8.5+BLEED)*PT)
art_page(doc, 11.25, 8.75, "FRONT 11x8.5+bleed", (0.85, 0.93, 1.0), tb)
art_page(doc, 11.25, 8.75, "BACK 11x8.5+bleed", (1.0, 0.9, 0.85), tb)
doc.save(f"{OUT}/art_11x85_bleed_trimbox.pdf"); doc.close()

# 2. Same art but with /Rotate 90 on page 1 (portrait media rotated to display landscape)
doc = fitz.open()
p = art_page(doc, 8.75, 11.25, "ROT90 FRONT", (0.85, 1.0, 0.88))
p.set_rotation(90)
doc.save(f"{OUT}/art_rot90.pdf"); doc.close()

# 3. Exact-trim postcard art, no bleed, no TrimBox: 6x4
doc = fitz.open()
art_page(doc, 6, 4, "PC 6x4 noblood", (1.0, 1.0, 0.85))
doc.save(f"{OUT}/art_6x4_trim.pdf"); doc.close()

# 4. Off-spec art (needs fit-to-trim): 10x7 art for an 11x8.5 job
doc = fitz.open()
art_page(doc, 10, 7, "OFFSPEC 10x7", (0.95, 0.85, 1.0))
doc.save(f"{OUT}/art_offspec.pdf"); doc.close()

# 5. Sticker art 3x3+bleed (3.25 sq)
doc = fitz.open()
art_page(doc, 3.25, 3.25, "STK", (0.85, 1.0, 1.0))
doc.save(f"{OUT}/art_sticker_3x3.pdf"); doc.close()

print("fixtures written")
