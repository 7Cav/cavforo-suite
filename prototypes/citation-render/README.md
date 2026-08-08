# PROTOTYPE — generated citation

**Throwaway.** No tests, no error handling, no abstractions. Do not merge to `develop`.

**Question:** what does a citation *generated from typed text* actually look like, and must it
still read as a signed certificate? Raised out of band to give
[#262](https://github.com/7Cav/cavforo-suite/issues/262) something concrete to react to, since
that ticket asks leadership a taste-and-authority question they cannot answer in the abstract.

**This is not the workflow.** Storage schema, read path and the upload UI's fate are all still
open on the map. This answers the *look* only.

## Run it

```sh
python3 build.py     # inlines the art assets -> citation-prototype.html (one file)
open citation-prototype.html
node shoot.mjs       # re-render out-{faithful,typeset,page}.png (needs: npm i playwright)
node state.mjs       # print the fit state per variant
node measure.mjs     # print font size, leading and chars-per-line (source of the table below)
```

Switch variants with the bottom bar or `?variant=faithful|typeset|page`.

## Variants

| | |
|---|---|
| **A · Faithful** | The template's own geometry, to the pixel. `out-faithful.png` |
| **B · Typeset** | Same idiom, taller plate so the prose is not crushed. `out-typeset.png` |
| **C · Page content** | No certificate at all — forum-native HTML. `out-page.png` |

Variant A was checked side by side against a real Bronze Star citation pulled from the live
forum, and the two are hard to tell apart. **That comparison image is deliberately not
committed** — the real citation names a serving member and carries their citation prose, and
this repo is public. Rebuild it locally if you need it: fetch any award citation from
`/data/roster_award_citations/{floor(id/1000)}/{id}.jpg` and append `out-faithful.png` beside it.

## What the .xcf gave us

Source: `BSM-YYMMDD.xcf` from the Citations Department Drive archive (see #266). The archive is
**not** referenced by URL here, per the standing rule about this repo being public.

The template is 640×500 at 100 DPI, and GIMP's `font-size-unit` is **pixels**, so every layer
box maps 1:1 onto CSS. Layer `DO NOT EDIT` is the fixed chrome; `BACKGROUND` is clean parchment.
The plate here is composited from the **art layers only** — background, watermark, ribbon, two
seals — so no member data and no real signature is baked in.

| Field | Box (W×H @ x,y) | Font | Size |
|---|---|---|---|
| `THE UNITED STATES ARMY` | 235×20 @ 202,138 | Sitka Banner Bold | 20px |
| `TO ALL WHO SHALL SEE…` | 539×27 @ 51,168 | Sitka Banner | 12px |
| award name | 607×27 @ 16,195 | (inferred Times) | ~26px |
| `TO` | 15×12 @ 312,227 | Sitka Banner | 12px |
| rank + member | 608×19 @ 17,245 | Times New Roman Bold | 20px, `#3a3a3a` |
| citation prose | 607×100 @ 16,271 | Times New Roman | **per-citation** |
| `GIVEN UNDER MY HAND…` | 610×12 @ 15,377 | Times New Roman | 12px |
| signatory | 169×73 @ 236,392 | — | **a `.png`, not text** |

Every box is centred on the canvas midline (319.5–321 of 640).

## Findings worth carrying back to the map

1. **The layout is fully mechanisable.** Ten typed fields plus one per-award plate reproduce the
   certificate closely enough that the side-by-side is hard to fault. Nothing needed an image
   model — this is deterministic typesetting.
2. **The clerk's hand-fitting is a real algorithm, and the size it lands on is unpredictable.**
   The template's prose layer carries a per-citation size override (7372/1024 pt off a 12px
   base) — the clerk shrinks text until it fits the 607×100 box. `autofitHeight()` reproduces
   that in ~20 lines. Two citations measured through it:

   | prose | variant A (607×100) | variant B (500×330) |
   |---|---|---|
   | 880 chars, 1 para | 11.8px | fits at the 15px design cap |
   | 1,378 chars, 3 paras | **8.6px** | 12.7px |

   So body size in variant A swings by a third on citation length alone, and a long one drops
   to a size that is genuinely hard to read. That is not a prototype artefact — it is what
   today's box does. It is the strongest argument for variant B and worth putting to leadership
   directly, but note the shorter citation *does* fit today's box acceptably, so this is an
   argument about the tail, not about every citation.
3. **The signatory is a bitmap, not text — confirmed by lifting it.** Layers `Kleinman - XO`,
   `Chance - GOA`, `Kleinman - GOA.png` are toggled signature *images*, and `sig-block.png`
   here is the block extracted from the chrome layer: the script signature, the rule **and all
   three printed lines** are one 176×80 image, not text over an image. A generator therefore
   needs a per-signatory asset and a rule for which one applies — exactly the "who owns
   sign-off" half of #262, showing up as a concrete artifact requirement. It also means the
   printed name/title/unit cannot be re-typeset without re-cutting the asset.
4. **Award variants are toggled layers** (`BRONZE STAR` vs `BRONZE STAR VALOR` share one box),
   so award name is a field, not a template — one plate serves several awards.
5. **Rendering engine is not the hard part.** This is HTML/CSS through headless Chromium at
   `deviceScaleFactor: 2`. Whether the real thing uses that, Imagick, or SVG is a downstream
   call; none of the open questions on the map hang on it.

## Known infidelities

- **Sitka Banner is a Windows font** and is not installed here; it falls back to Charter. That
  is the visible difference in the branch line against a real citation.
- **The signatory is real** — it is the template's own signature block, kept deliberately,
  because a fabricated signing officer reads as wrong to anyone in the unit.
- The **member, operation, award prose and date are fabricated**. "Private First Class John
  Sandwich" and operation Midnight Snack are not real; the citation is a joke written to give
  leadership something to look at without putting a real member's award on a sample.
