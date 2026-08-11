# Media prep for the WordPress media library — resize, strip, minify, rename.
#
# The point of committing this rather than re-deriving it each session is that the
# defaults ARE the house style. A photo processed in March and one processed in
# September should come out the same, or the media library slowly turns into a
# museum of whatever seemed reasonable that day.
#
# Three things here are less obvious than they look, and all three are colour or
# transparency bugs that ship silently — the file looks fine locally and is wrong
# on the site:
#
#   1. A photo tagged with a wide-gamut profile (Display P3, Adobe RGB) must be
#      CONVERTED to sRGB, not merely relabelled. Browsers assume sRGB for untagged
#      images, so dropping the profile without converting makes every saturated
#      colour go flat. We convert, then drop.
#   2. CMYK JPEGs (common straight out of print-side tooling, which this shop has
#      plenty of) cannot go to the web as-is and convert badly without an explicit
#      profile-aware step.
#   3. Anything with alpha must not become a JPEG. JPEG has no alpha, so the
#      transparent region composites to black. Alpha images go to PNG or WebP.
#
# Metadata is stripped by rebuilding the pixel buffer into a fresh image rather
# than by asking the encoder not to write EXIF — the latter leaves the door open
# for format-specific side channels. Phone photos carry GPS coordinates of the
# place they were taken, which is not something to publish by accident.
#
# Usage:
#   python3 tools-media-prep.py <file-or-dir> [...] [options]
#
#   --max N        cap the long edge, in px           (default 2000)
#   --quality N    JPEG/WebP quality                  (default 82)
#   --webp         also emit a .webp beside each file
#   --out DIR      output directory                   (default ./media-out)
#   --prefix S     slug prefix for output filenames
#
# Never upscales: an image already under --max keeps its dimensions, because
# inventing pixels to hit a target is worse than serving a smaller file.

import argparse
import io
import json
import os
import re
import subprocess
import sys

try:
    from PIL import Image, ImageCms, ImageOps
except ImportError:
    subprocess.run([sys.executable, "-m", "pip", "install", "--quiet", "pillow"], check=True)
    from PIL import Image, ImageCms, ImageOps

Image.MAX_IMAGE_PIXELS = 400_000_000  # generous, but still a decompression-bomb guard

EXTS = {".jpg", ".jpeg", ".png", ".webp", ".tif", ".tiff", ".bmp", ".gif"}
SRGB = ImageCms.createProfile("sRGB")


def slugify(name):
    """WordPress-friendly filename. IMG_4821.JPG is not a filename, it is a barcode."""
    stem = os.path.splitext(os.path.basename(name))[0]
    stem = re.sub(r"[^a-zA-Z0-9]+", "-", stem).strip("-").lower()
    return stem or "image"


def to_srgb(im):
    """Convert into sRGB, honouring any embedded profile. Returns (image, note)."""
    icc = im.info.get("icc_profile")

    if im.mode == "CMYK":
        # CMYK with a profile converts properly; without one, Pillow's naive
        # conversion is the only option and is worth flagging in the manifest.
        if icc:
            try:
                src = ImageCms.getOpenProfile(io.BytesIO(icc))
                return ImageCms.profileToProfile(im, src, SRGB, outputMode="RGB"), "cmyk->srgb (profiled)"
            except Exception:
                pass
        return im.convert("RGB"), "cmyk->srgb (no profile, approximate)"

    if icc and im.mode in ("RGB", "RGBA"):
        # Convert unconditionally rather than trying to detect "is this already
        # sRGB?" from the profile description. Description strings are unreliable
        # (a 5000K variant still calls itself "sRGB built-in"), and an sRGB->sRGB
        # conversion was measured at zero channel drift — so the defensive path
        # costs nothing and the clever path silently skips real P3 conversions.
        try:
            src = ImageCms.getOpenProfile(io.BytesIO(icc))
            desc = (ImageCms.getProfileDescription(src) or "profile").strip()
            out_mode = "RGBA" if im.mode == "RGBA" else "RGB"
            return ImageCms.profileToProfile(im, src, SRGB, outputMode=out_mode), f"{desc}->sRGB"
        except Exception as e:
            return im, f"profile conversion failed ({type(e).__name__}), left as-is"

    return im, ""


def has_alpha(im):
    return im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info)


def strip_metadata(im):
    """Rebuild pixels into a clean image so nothing rides along in the container."""
    return Image.frombytes(im.mode, im.size, im.tobytes())


# Output format mirrors input rather than defaulting everything to JPEG. The
# source format usually encodes intent: someone saved a PNG because it is flat
# artwork with hard edges, and re-encoding that as JPEG both adds ringing and,
# as the fixtures proved, frequently produces a LARGER file.
FORMAT_FOR_EXT = {
    ".jpg": "JPEG", ".jpeg": "JPEG", ".png": "PNG", ".webp": "WEBP",
    ".tif": "PNG", ".tiff": "PNG", ".bmp": "PNG", ".gif": "PNG",
}


def encode(im, fmt, path, quality):
    if fmt == "JPEG":
        im.save(path, "JPEG", quality=quality, optimize=True, progressive=True, subsampling=2)
    elif fmt == "PNG":
        im.save(path, "PNG", optimize=True)
    else:
        im.save(path, "WEBP", quality=quality, method=6)
    return os.path.getsize(path)


def process(path, args):
    orig_bytes = os.path.getsize(path)
    im = Image.open(path)
    src_size, src_mode = im.size, im.mode

    im = ImageOps.exif_transpose(im)  # bake in rotation before discarding EXIF
    im, note = to_srgb(im)

    alpha = has_alpha(im)
    if alpha:
        if im.mode == "P":
            im = im.convert("RGBA")
    elif im.mode != "RGB":
        im = im.convert("RGB")

    if max(im.size) > args.max:
        im.thumbnail((args.max, args.max), Image.LANCZOS)

    im = strip_metadata(im)

    slug = slugify(path)
    if args.prefix:
        slug = f"{slugify(args.prefix)}-{slug}"
    os.makedirs(args.out, exist_ok=True)

    ext = os.path.splitext(path)[1].lower()
    fmt = FORMAT_FOR_EXT.get(ext, "JPEG")
    if alpha and fmt == "JPEG":
        fmt = "PNG"  # JPEG has no alpha; transparency would composite to black
    suffix = {"JPEG": ".jpg", "PNG": ".png", "WEBP": ".webp"}[fmt]

    outputs = []
    primary = os.path.join(args.out, slug + suffix)
    encode(im, fmt, primary, args.quality)
    outputs.append(primary)

    # Occasionally the cleaned file is LARGER than the source — typically a tiny,
    # already-optimised graphic where the metadata we removed was a rounding error
    # and the re-encode costs more than it saves. We deliberately keep the cleaned
    # version anyway and merely flag it: falling back to the original bytes would
    # quietly reinstate the EXIF/GPS this tool exists to remove, which is a bad
    # trade for a few hundred bytes.
    grew = os.path.getsize(primary) > orig_bytes

    if args.webp:
        p = os.path.join(args.out, slug + ".webp")
        if p != primary:
            encode(im, "WEBP", p, args.quality)
            outputs.append(p)
    return {
        "source": path,
        "source_bytes": orig_bytes,
        "source_size": list(src_size),
        "source_mode": src_mode,
        "outputs": [{"path": o, "bytes": os.path.getsize(o)} for o in outputs],
        "out_size": list(im.size),
        "saved_pct": round(100 - 100 * os.path.getsize(primary) / orig_bytes, 1),
        "colour_note": note,
        "alpha": alpha,
        "grew": grew,
        # Filled in per job — the pipeline cleans pixels, a human or a vision pass
        # writes the words. Empty alt text is an accessibility and SEO own-goal.
        "title": "",
        "alt": "",
    }


def main():
    ap = argparse.ArgumentParser(description="Prep photos for the WordPress media library.")
    ap.add_argument("inputs", nargs="+")
    ap.add_argument("--max", type=int, default=2000)
    ap.add_argument("--quality", type=int, default=82)
    ap.add_argument("--webp", action="store_true")
    ap.add_argument("--out", default="media-out")
    ap.add_argument("--prefix", default="")
    args = ap.parse_args()

    files = []
    for item in args.inputs:
        if os.path.isdir(item):
            for root, _, names in os.walk(item):
                files += [os.path.join(root, n) for n in sorted(names)
                          if os.path.splitext(n)[1].lower() in EXTS]
        elif os.path.splitext(item)[1].lower() in EXTS:
            files.append(item)
        else:
            print(f"  skip (unsupported): {item}", file=sys.stderr)

    if not files:
        print("No images found.", file=sys.stderr)
        return 1

    results, failures = [], 0
    for f in files:
        try:
            r = process(f, args)
            results.append(r)
            outs = ", ".join(f"{os.path.basename(o['path'])} {o['bytes']/1024:.0f}KB"
                             for o in r["outputs"])
            pct = r["saved_pct"]
            delta = f"{pct:.1f}% smaller" if pct >= 0 else f"{-pct:.1f}% LARGER"
            notes = [n for n in (r["colour_note"],
                                 "kept clean copy despite growth" if r["grew"] else "") if n]
            flag = f"  [{'; '.join(notes)}]" if notes else ""
            print(f"  {os.path.basename(f):<34} {r['source_bytes']/1024:>7.0f}KB -> {outs}"
                  f"  ({delta}){flag}")
        except Exception as e:
            failures += 1
            print(f"  {os.path.basename(f):<34} FAILED: {type(e).__name__}: {e}", file=sys.stderr)

    manifest = os.path.join(args.out, "manifest.json")
    with open(manifest, "w") as fh:
        json.dump(results, fh, indent=2)

    total_in = sum(r["source_bytes"] for r in results)
    total_out = sum(r["outputs"][0]["bytes"] for r in results)
    print(f"\n{len(results)} processed, {failures} failed. "
          f"{total_in/1e6:.2f}MB -> {total_out/1e6:.2f}MB "
          f"({100 - 100*total_out/max(total_in,1):.1f}% smaller). Manifest: {manifest}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
