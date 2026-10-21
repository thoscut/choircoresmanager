#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 choircoresmanager contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Build outreach material (flyer, business card, banners) from the templates.

Project texts live in content.json (maintained in this repo, next to the
roadmap). Everything that belongs to the choir handing the material out --
name, logo, contact, colours, fonts -- lives in a separate sender file, so
every choir can build its own edition without touching the templates.

    python build.py --sender example/sender.json --out out/

Requires weasyprint, jinja2 and segno; pypdfium2 (or poppler's pdftoppm)
for the PNG previews.
"""

import argparse
import json
import pathlib
import shutil
import subprocess
import sys

HERE = pathlib.Path(__file__).resolve().parent

# name: (template, kind, width, height) -- mm for print, px for screen
PRODUCTS = {
    "flyer-dl": ("flyer-dl.html", "print", 99, 210),
    "business-card": ("business-card.html", "print", 85, 55),
    "banner-social": ("banner-social.html", "screen", 1280, 640),
    "banner-web": ("banner-web.html", "screen", 1500, 500),
}
PREVIEW_DPI = 150


def load_json(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def resolve_sender(sender, base):
    """Turn the relative logo and font paths of a sender file into file URIs."""
    sender = json.loads(json.dumps(sender))
    for key in ("logo", "logo_on_dark"):
        if sender.get(key):
            sender[key] = (base / sender[key]).resolve().as_uri()
    for font in sender.get("fonts", {}).values():
        for face in font.get("files", []):
            face["src"] = (base / face["src"]).resolve().as_uri()
    return sender


def qr_svg(url, colour):
    try:
        import segno
    except ImportError:
        sys.exit("segno is missing: pip install segno")
    qr = segno.make(url, error="m")
    return qr.svg_inline(scale=1, border=4, dark=colour, light="#ffffff", omitsize=True)


def rasterize(pdf, stem, scale):
    """Render every page of pdf to stem.png (or stem-N.png for several pages)."""
    try:
        import pypdfium2 as pdfium
    except ImportError:
        pdfium = None
    if pdfium:
        doc = pdfium.PdfDocument(str(pdf))
        names = [stem.with_suffix(".png")] if len(doc) == 1 else [
            stem.with_name(f"{stem.name}-{i + 1}.png") for i in range(len(doc))]
        for page, name in zip(doc, names):
            page.render(scale=scale).to_pil().save(name, optimize=True)
        doc.close()
        return names
    if shutil.which("pdftoppm"):
        subprocess.run(["pdftoppm", "-png", "-r", str(round(scale * 72)), str(pdf), str(stem)], check=True)
        return sorted(stem.parent.glob(f"{stem.name}*.png"))
    sys.exit("neither pypdfium2 nor pdftoppm found: pip install pypdfium2")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--sender", required=True, help="sender JSON (name, logo, contact, colours, fonts)")
    ap.add_argument("--content", default=str(HERE / "content.json"), help="project texts (default: content.json)")
    ap.add_argument("--out", required=True, help="output folder (screen PDFs and PNGs)")
    ap.add_argument("--print-out", help="separate folder for the print PDFs (default: --out)")
    ap.add_argument("--bleed", type=float, default=3.0, help="bleed in mm for the print PDFs (default 3)")
    ap.add_argument("--crop-marks", action="store_true", help="add crop marks to the print PDFs")
    ap.add_argument("--only", nargs="*", choices=sorted(PRODUCTS), help="build only these products")
    args = ap.parse_args()

    try:
        import jinja2
        from markupsafe import Markup
        from weasyprint import HTML
    except ImportError as err:
        sys.exit(f"{err.name} is missing: pip install weasyprint jinja2")

    sender_path = pathlib.Path(args.sender).resolve()
    sender = resolve_sender(load_json(sender_path), sender_path.parent)
    content = load_json(args.content)
    out = pathlib.Path(args.out)
    print_out = pathlib.Path(args.print_out or args.out)
    for folder in (out, print_out):
        folder.mkdir(parents=True, exist_ok=True)

    env = jinja2.Environment(loader=jinja2.FileSystemLoader(HERE / "templates"),
                             autoescape=jinja2.select_autoescape(["html"]),
                             trim_blocks=True, lstrip_blocks=True)
    qr = Markup(qr_svg(content["project"]["url"], sender["colors"]["ink"]))

    for name in args.only or PRODUCTS:
        template, kind, width, height = PRODUCTS[name]
        variants = [("", 0.0, False)]
        if kind == "print":
            variants.append(("-print", args.bleed, args.crop_marks))
        for suffix, bleed, marks in variants:
            html = env.get_template(template).render(
                c=content, s=sender, k=sender["colors"], qr=qr, W=width, H=height,
                unit="mm" if kind == "print" else "px", bleed=bleed, marks=marks)
            pdf = (print_out if suffix else out) / f"{name}{suffix}.pdf"
            HTML(string=html, base_url=str(HERE / "templates")).write_pdf(pdf)
            print(pdf)
        scale = PREVIEW_DPI / 72 if kind == "print" else 4 / 3
        for png in rasterize(out / f"{name}.pdf", out / name, scale):
            print(png)
        if kind == "screen":
            (out / f"{name}.pdf").unlink()


if __name__ == "__main__":
    main()
