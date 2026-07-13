"""Physical duplex simulator.

Models: press prints output page 1 on the sheet front, page 2 on the back such
that flipping the sheet about axis A shows page 2 registered (A='short' →
vertical axis / x-mirror; 'long' → horizontal axis / y-mirror).

For each piece (cell or booklet page slot):
 1. crop the front; find the rotation θ that makes the front design upright
 2. x-ray view of back ink under the same region = mirror_A(back render) crop
 3. reader holds piece front-upright (rotate by θ), turns it left-right
    (standard head-to-head turn / book turn about the spine):
    ViewB = mirror-x( rot(BP_crop, θ) )
 4. assert ViewB shows the expected design upright.

Fixture designs carry a solid black square in the TOP-LEFT corner region and a
ring (much less ink) BOTTOM-RIGHT, so orientation is detected by comparing
corner ink densities.
"""
import fitz, numpy as np, sys

def render(page, dpi=60):
    pix = page.get_pixmap(dpi=dpi, colorspace=fitz.csGRAY)
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width)
    return 255 - img  # ink = high

def mirror(img, axis):  # axis 'short' → x-mirror (flip columns), 'long' → y-mirror
    return img[:, ::-1] if axis == "short" else img[::-1, :]

def rot(img, deg):  # CCW image rotation in 90° steps
    return np.rot90(img, k=(deg // 90) % 4)

def corner_density(img, frac=0.30):
    h, w = img.shape
    ch, cw = max(1, int(h * frac)), max(1, int(w * frac))
    return {
        "TL": img[:ch, :cw].mean(), "TR": img[:ch, -cw:].mean(),
        "BL": img[-ch:, :cw].mean(), "BR": img[-ch:, -cw:].mean(),
    }

def orientation_of(img):
    """Return CCW rotation (0/90/180/270) that puts the densest corner at TL.
    Fixture square = TL when upright."""
    best, bestrot = -1, None
    for d in (0, 90, 180, 270):
        c = corner_density(rot(img, d))
        # upright signature: TL strong, BR weakest
        score = c["TL"] - c["BR"]
        if score > best:
            best, bestrot = score, d
    return bestrot

def crop(img, rect_in, sheet_w_in, sheet_h_in):
    h, w = img.shape
    x0 = int(rect_in[0] / sheet_w_in * w); x1 = int(rect_in[2] / sheet_w_in * w)
    # rect y given in TOP-left inches coords here; callers pass top-based rects
    y0 = int(rect_in[1] / sheet_h_in * h); y1 = int(rect_in[3] / sheet_h_in * h)
    return img[y0:y1, x0:x1]

def check_piece(front_img, back_img, rect, sheet_w, sheet_h, flip, label):
    """rect = (x0, y0_top, x1, y1_top) inches, top-left origin."""
    F = crop(front_img, rect, sheet_w, sheet_h)
    theta = orientation_of(F)
    BP = crop(mirror(back_img, flip), rect, sheet_w, sheet_h)
    viewB = rot(BP, theta)[:, ::-1]  # rotate with piece, then left-right turn
    thetaB = orientation_of(viewB)
    ok = thetaB == 0
    print(f"  {label}: front needs rot {theta}° → back reads {'UPRIGHT ✓' if ok else f'{(360-thetaB)%360}° OFF ✗'}")
    return ok

def flat(pdf, trim_long, trim_short, across, down, rotated, gutter, flip):
    d = fitz.open(pdf)
    front, back = render(d[0]), render(d[1])
    sw, sh = d[0].rect.width / 72, d[0].rect.height / 72
    cw = trim_short if rotated else trim_long
    ch = trim_long if rotated else trim_short
    tw = across * cw + (across - 1) * gutter
    th = down * ch + (down - 1) * gutter
    x0, y0 = (sw - tw) / 2, (sh - th) / 2
    ok = True
    for r in range(down):
        for c in range(across):
            x = x0 + c * (cw + gutter); y = y0 + r * (ch + gutter)
            ok &= check_piece(front, back, (x, y, x + cw, y + ch), sw, sh, flip, f"cell({c},{r})")
    return ok

allok = True
print("FLAT 11x8.5 2-sided, rotated cells, flip=short (shop default):")
allok &= flat(sys.argv[1] if len(sys.argv) > 1 else "out_flat_short.pdf", 11, 8.5, 2, 1, True, 0.25, "short")
