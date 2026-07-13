"""Numbered booklet fixtures: big 'P<n>' text + head arrow per page."""
import fitz, os
OUT = os.path.dirname(os.path.abspath(__file__))
PT = 72; BLEED = 0.125

def booklet(path, pages, w_in, h_in, with_bleed=True):
    doc = fitz.open()
    bw = w_in + (2*BLEED if with_bleed else 0)
    bh = h_in + (2*BLEED if with_bleed else 0)
    for i in range(1, pages+1):
        p = doc.new_page(width=bw*PT, height=bh*PT)
        r = p.rect
        shade = 0.75 + 0.2*((i % 2))
        p.draw_rect(r, fill=(shade, 0.95, 1.0 - 0.03*i))
        fs = min(bw, bh) * 28
        p.insert_text(fitz.Point(r.width*0.28, r.height*0.55), f"P{i}", fontsize=fs, color=(0,0,0))
        # head arrow at visual top
        cx = r.width/2
        p.draw_line(fitz.Point(cx, r.height*0.25), fitz.Point(cx, r.height*0.08), width=3)
        p.draw_line(fitz.Point(cx, r.height*0.08), fitz.Point(cx-6, r.height*0.14), width=3)
        p.draw_line(fitz.Point(cx, r.height*0.08), fitz.Point(cx+6, r.height*0.14), width=3)
        if with_bleed:
            p.set_trimbox(fitz.Rect(BLEED*PT, BLEED*PT, (BLEED+w_in)*PT, (BLEED+h_in)*PT))
    doc.save(path); doc.close()

booklet(f"{OUT}/bk_8p_85x11.pdf", 8, 8.5, 11)          # imp 2 → 1 spread/side, S=2
booklet(f"{OUT}/bk_16p_55x85.pdf", 16, 5.5, 8.5)       # imp 4 → 2 spreads/side, S=4
booklet(f"{OUT}/bk_8p_6x4.pdf", 8, 6, 4)               # landscape, imp 8 → 4 spreads/side
booklet(f"{OUT}/bk_10p_85x11.pdf", 10, 8.5, 11)        # odd → pad to 12, S=3
booklet(f"{OUT}/bk_8p_12x9.pdf", 8, 12, 9)             # imp 1 → 13x27.5 oversize
print("booklet fixtures written")
