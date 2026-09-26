"""PROTOTYPE build: inline src/ and assets/ into one self-contained HTML file.

    python3 build.py      ->  citation-workflow-prototype.html   (open it in a browser)
                              citation-workflow-artifact.html    (publish it as a claude.ai Artifact)
"""
import base64
import json
import re
from pathlib import Path

HERE = Path(__file__).parent
SRC, ASSETS = HERE / 'src', HERE / 'assets'

# A claude.ai Artifact wraps the page in its own document, so the artifact copy drops these lines
# and opens on the <title>. The title has to fall in the first 8 KB, ahead of the inlined fonts.
WRAPPER = re.compile(r'^(<!doctype html>|<html[^>]*>|</?head>|<meta [^>]*>|</?body>|</html>)\n', re.M | re.I)


def data_url(path: Path) -> str:
    raw = path.read_bytes()
    mime = ('image/png' if raw[:8] == b'\x89PNG\r\n\x1a\n'
            else 'image/jpeg' if raw[:3] == b'\xff\xd8\xff'
            else 'font/woff2' if raw[:4] == b'wOF2'
            else 'application/octet-stream')
    return f'data:{mime};base64,{base64.b64encode(raw).decode()}'


def images() -> dict:
    return {p.stem: data_url(p) for p in sorted(ASSETS.iterdir()) if p.suffix in ('.png', '.jpg')}


def fonts() -> str:
    f = ASSETS / 'fonts'
    faces = [
        ('Roboto', 'normal', '100 900', 'Roboto-var.woff2'),
        ('Tinos', 'normal', '400', 'Tinos-400-normal.woff2'),
        ('Tinos', 'normal', '700', 'Tinos-700-normal.woff2'),
        ('Tinos', 'italic', '400', 'Tinos-400-italic.woff2'),
    ]
    return '\n'.join(
        f"@font-face{{font-family:'{fam}';font-style:{style};font-weight:{weight};font-display:block;"
        f"src:url({data_url(f / name)}) format('woff2')}}"
        for fam, style, weight, name in faces)


def icons() -> dict:
    out = {}
    for p in sorted((ASSETS / 'icons').glob('*.svg')):
        svg = re.sub(r'<!--.*?-->', '', p.read_text(), flags=re.S)
        if p.stem.startswith('brand-'):
            d = re.search(r'<path d="([^"]+)"', svg).group(1)
            out[p.stem] = {'svg': f'<path d="{d}"/>', 'brand': True}
        else:
            inner = re.search(r'<svg[^>]*>(.*)</svg>', svg, flags=re.S).group(1)
            out[p.stem] = {'svg': re.sub(r'\s+', ' ', inner).strip()}
    return out


def main() -> None:
    shell = (SRC / 'shell.html').read_text()
    css = '\n'.join((SRC / n).read_text() for n in ('forum.css', 'addon.css', 'guide.css'))
    js = '\n'.join((SRC / n).read_text() for n in ('model.js', 'render.js', 'ui.js', 'guide.js'))
    assert '</script' not in js.lower(), 'a literal </script> would end the inline script early'
    parts = {
        '{{FONTS}}': fonts(),
        '{{CSS}}': css,
        '{{ASSETS}}': json.dumps(images()),
        '{{ICONS}}': json.dumps(icons()),
        '{{JS}}': js,
    }
    for name, template in (('citation-workflow-prototype.html', shell),
                           ('citation-workflow-artifact.html', WRAPPER.sub('', shell))):
        for k, v in parts.items():
            template = template.replace(k, v)
        out = HERE / name
        out.write_text(template)
        print(f'{out.name}: {out.stat().st_size / 1024:.0f} KiB')
    art = (HERE / 'citation-workflow-artifact.html').read_text()
    assert art.startswith('<title>') and art.index('</title>') < 8192, 'the artifact must open on its <title>'


if __name__ == '__main__':
    main()
