#!/usr/bin/env python3
"""PROTOTYPE build step: inline the art assets so the result is one double-clickable file."""
import base64, pathlib

here = pathlib.Path(__file__).parent
b64 = lambda n: base64.b64encode((here / n).read_bytes()).decode()

html = (here / "template.html").read_text()
for token, fname in {
    "{{RIBBON}}": "ribbon.png",
    "{{SEALL}}": "seal-left.png",
    "{{SEALR}}": "seal-right.png",
    "{{PARCHMENT}}": "parchment-tile.png", "{{PLATE}}": "plate.png", "{{SIGBLOCK}}": "sig-block.png", "{{PLATETALL}}": "plate-tall.png",
}.items():
    html = html.replace(token, b64(fname))

out = here / "citation-prototype.html"
out.write_text(html)
print(f"{out}  {out.stat().st_size/1024:.0f} KiB")
