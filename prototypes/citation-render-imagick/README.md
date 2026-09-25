# PROTOTYPE: a citation render through Imagick

Throwaway. No tests, no error handling beyond what makes it run. Do not merge to `develop`.

The question comes from [#303](https://github.com/7Cav/cavforo-suite/issues/303). Can Imagick, as prod runs it, set a citation's typed fields well enough and fast enough to render on view? And does a render route ending in `.jpg` get through prod's nginx and Cloudflare?

It renders one BSM grant member: variant B of the Chromium prototype on `prototype/citation-render` (the taller layout leadership preferred), scaled from its 640px plate to 1275px wide. The member, the operation, the citation text and the signatory are all made up. The ink image is a synthetic squiggle, not anyone's signature.

## Run it

Everything runs inside a container of the PHP image under test. Point `--fonts` at a directory holding `Tinos-Regular.ttf` and `Tinos-Bold.ttf` from [google/fonts `ofl/tinos`](https://github.com/google/fonts/tree/main/ofl/tinos). Fonts aren't committed.

```sh
img=xenforo-2-php-fpm:custom     # the staging mirror's PHP image
run() { docker run --rm --network none -u "$(id -u):$(id -g)" -v "$PWD:/p" -v "$FONTS:/fonts:ro" -w /p "$img" "$@"; }

run php make-assets.php --fonts=/fonts                 # plate-1275.png and ink.png, already committed
run php render.php --fonts=/fonts --text=short --out=out/short.jpg   # prints the fit and a timing per step
run php render.php --fonts=/fonts --text=long --bench=30 --json      # warm timings
run php render.php --env                                             # the image's Imagick build and limits
```

The renders and timings below were made with Liberation Serif 2.1.5 in place of Tinos. Liberation 2 is drawn from Tinos and has the same advance widths, so sizes and line breaks carry over. Glyph shapes differ in small details.

`--text` is `short` (880 characters), `long` (1,429 characters over 3 paragraphs) or `tail` (2,090 characters, deliberately too long).

To measure an image on its own hardware without touching the running forum, pack a bundle and run `probe.sh` in a throwaway container of that image:

```sh
./make-bundle.sh "$FONTS"        # citation-probe.tgz
# on the box:  tar xzf citation-probe.tgz && cd citation-probe
#              docker run --rm --network none -v "$PWD:/p" -w /p <php-fpm image> sh probe.sh > probe.txt
```

The XenForo route goes in as a throwaway addon, `Cav7/CitationRenderPrototype`:

```sh
./install-xf.sh /path/to/xenforo/app "$FONTS"
php cmd.php xf-addon:install Cav7/CitationRenderPrototype     # in the fpm container
curl -sD- -o /dev/null http://<forum>/citation-render-prototype/1/abc/1/john-sandwich-bronze-star-medal-2026-08-08.jpg
php cmd.php xf-addon:uninstall Cav7/CitationRenderPrototype
```

`compare.php` puts a render next to a 1275px screenshot of the Chromium prototype. The outputs are in `compare/`.

## What it found

### The text looks right

The Imagick render and Chromium's break the citation text into the same 11 lines at the same 15px design cap. Justified lines end within 0.1px of the box edge, and the last line of each paragraph is centred. FreeType sets Tinos a shade lighter than Chrome does on macOS, and both read cleanly at 1:1. See `compare/citation-text-1to1.png` and `compare/pages-half.png`.

Fitted sizes for the legibility warning, in 1275px render pixels, 640px plate pixels and printed points on Letter at 150 dpi:

| text | characters | size | lines |
|---|---|---|---|
| short | 880 in 1 paragraph | 29.88px, 15.00px, 14.3pt, the design cap | 11 |
| long | 1,429 in 3 paragraphs | 24.75px, 12.42px, 11.9pt | 15 |
| tail | 2,090 in 4 paragraphs | 20.50px, 10.29px, 9.8pt | 19 |

### The first version was too slow, and why

Every Imagick text call costs about 0.6ms, measuring or drawing, and a warm process is no faster than a cold one. ImageMagick opens the font file afresh on each call. Measuring word by word and bisecting the size took 420ms on the short text and 1.4s on the long one.

So the fit runs on a table of character advances. Imagick measures each distinct character once, at 100px, and the whole text once, and the ratio between the two corrects the table for kerning. The bisection and line breaking then run in PHP with no Imagick calls. Imagick measures each final line once and draws it once. That's about 80 text calls whatever the length, and render time stays near 200ms.

Warm, 30 renders in one process on Apple silicon (arm64, Docker, PHP 8.3.33, ImageMagick 7.1.2-30):

| text | p50 | p95 |
|---|---|---|
| short | 186ms | 201ms |
| long | 213ms | 233ms |
| tail | 242ms | 253ms |

A fresh process per render lands within 10ms of those. The plate's PNG decode takes about 50ms, drawing the text 50 to 80ms, the fit about 40ms, and the encode about 20ms.

### Two ImageMagick behaviours the spec should know

`interword-spacing` replaces the space's own width, plus about half a pixel, rather than adding to it. The width it produces is linear in the value, so the renderer aims from its estimate, measures once and corrects once. It doesn't hard-code the half pixel.

Below q91 ImageMagick uses the fast integer DCT unless told otherwise. Leaving `jpeg:dct-method` unset gives bytes different from `islow`, so the pinned profile has to set it, as #268 said. Optimised Huffman tables save 3.5% on a render.

### The encode matches the profile #268 pins

The served file is baseline JPEG, q85, sampling 1x1 on all three components, sRGB, with no ICC profile, EXIF or comment.

### The staging mirror's Imagick

Imagick 3.8.1 on ImageMagick 7.1.2-30 Q16-HDRI, with FreeType 2.14.3, libjpeg-turbo 3.1.3, HarfBuzz 13.2.1 and raqm 0.10.2. `imagick.set_single_thread` is on, so a render uses one core.

The effective ImageMagick policy is the stock open one. Alpine's `policy.xml` has every rule commented out, so any coder may read anything and there are no resource caps beyond what ImageMagick derives from the machine. The renderer only reads files S1 uploads through the template manager, plates and ink images. With an open policy, a file that claims to be a PNG but isn't would reach whichever coder its bytes select. The spec should either check signature bytes on upload, as #270 does for blobs, or read those files with an explicit `png:` prefix.

### XenForo routing

XenForo's router strips a trailing `.jpg` from the path and treats it as a response type, then takes the last segment as the action name. So a readable filename in the last segment, as #298 decides, arrives as an action like `johnSandwichBronzeStarMedal20260808`. The format's parameters need their trailing slash, so a `:str<filename>` at the end never matches. The prototype's controller takes every action through `__call`, since the dispatcher only asks `is_callable()`.

### Cookies and cache headers

A render must not set a cookie, or Cloudflare won't cache it. Two things set one:

- XenForo sends `Expires: Thu, 19 Nov 1981` on any response that has none. `max-age` outranks it, but the route sets its own `Expires` anyway.
- A visitor with no session cookie gets a new guest session. Advanced Forms (Snog) writes `snogFormsCount` into it in its `app_pub_start_end` listener, on every request, so XenForo saves it and sends `Set-Cookie: xf_session`. The route drops a session that was never saved. It must not call `expunge()` on a stored one, because that deletes it and signs the member out.

With both handled, the staging route answers `200 image/jpeg`, `Cache-Control: public, max-age=691200`, `X-Robots-Tag: noindex`, and no `Set-Cookie`, with or without a guest cookie.

### Prod nginx and Cloudflare, from outside

Three GETs against paths that don't exist on 7cav.us:

| path | answered by | `cf-cache-status` |
|---|---|---|
| `/data/roster_award_citations/0/0.jpg` | XenForo's 404 page | `BYPASS` |
| `/citation-render-probe/1-abc/example-bsm-2026-08-08.jpg` | XenForo's 404 page | `BYPASS` |
| `/citation-render-probe/1-abc/` | XenForo's 404 page | `DYNAMIC` |

Prod's nginx hands a `.jpg` path that isn't a file to PHP, inside the static citation tree and outside it. Cloudflare treats a `.jpg` path as cacheable by extension and bypassed these only because XenForo sent `private, no-cache` and cookies. A path without the extension is `DYNAMIC`, never considered.

## Files

- `render.php`: the renderer and its CLI
- `grant.php`: the fixture grant member
- `make-assets.php`: builds `plate-1275.png` and `ink.png`
- `compare.php`: side-by-side crops against the Chromium prototype
- `probe.sh`, `make-bundle.sh`: the image-and-hardware probe
- `install-xf.sh`, `xf/`: the throwaway route addon
