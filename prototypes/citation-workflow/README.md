# PROTOTYPE: issuing, attaching and opening a citation

**Throwaway.** No tests, no error handling beyond what makes it run. Do not merge to `develop`.

**Question** ([#309](https://github.com/7Cav/cavforo-suite/issues/309), on the map [#261](https://github.com/7Cav/cavforo-suite/issues/261)): when S1 walks through issuing, attaching and opening a citation, does each role's work stay the same, minus GIMP and the upload box?

## Run it

Open `citation-workflow-prototype.html` in a browser. It's one self-contained file, with no server and no network, so it can be sent as an attachment.

To change it, edit `src/` and rebuild:

```sh
python3 build.py     # inlines src/ and assets/ into citation-workflow-prototype.html
```

The build also writes `citation-workflow-artifact.html` for publishing as a claude.ai Artifact. It's the same page without the `<!doctype>`, `<html>`, `<head>` and `<body>` lines, because claude.ai adds its own, and it opens on the `<title>`. It isn't committed.

The page behaves the same in both files. The artifact frame can't open new tabs or windows, start downloads, show `confirm()` dialogs, or keep a route in `location.hash`, so the page does none of those. It keeps its route in memory and opens citations in an in-page viewer, and the guide confirms deletes inline.

## What's in it

A stand-in for the forum with a guide panel on the right. The reviewer picks one of three walkthroughs. Every step has a short scenario, what to do, what changed from today, and a feedback box. Notes stay in the reviewer's browser until they copy them from the guide. The copy is BB code, for pasting as a reply in the forum DM thread the link came from.

| Walkthrough | Steps |
|---|---|
| S1 Citations clerk | Issue a written MSM to two members, issue a generic EIB, correct a typo on the MSM, open it from a roster profile |
| S1 Milpacs clerk | Add an award and pick its citation, put one boot camp certificate on the Graduation record and the Army Service Ribbon, try to edit a PUC row, open a Disciplinary record, open a citation |
| S1 HQ | Place a field on a new Bronze Star plate, add a signature, publish version 2, retire version 1, open a citation issued on version 1 |

Each walkthrough starts from a fresh copy of the data. The guide checks each step against what the reviewer did, and can point at the next thing to click.

The live Citation links open the render in a new tab. Here they open it in a full-window viewer with the browser's dark backdrop, under the filename the read-path decision gives it. A click zooms the image to full size.

## Files

- `src/model.js`: the citation model as the map decided it. Plain data and pure functions (issue, correct, save a row with the picker, publish, retire, add a signature), with no DOM.
- `src/render.js`: the render, in canvas instead of Imagick. Single-line fields shrink to their box; the citation text takes the largest size that fits, justified with a centred last line; anything below a field's reading size is flagged, never refused.
- `src/ui.js`: the forum pages, the vendor's row forms with the picker in the upload box's place, and the new Citations and template manager pages.
- `src/guide.js`: the walkthroughs, the per-step feedback and the BB code export.
- `src/forum.css`, `src/addon.css`, `src/guide.css`: styles. `forum.css` recreates the live forum's dark style.

## How the forum look was matched

I wrote `forum.css` from scratch against computed styles measured on 7cav.us on 2026-09-26. That covers the colours, fonts, sizes, borders, radii and spacing of the nav, blocks, section headers, data lists, form rows, inputs, buttons, menus, overlays and messages. I measured the staff-only screens, the ••• menus, Add award and the edit overlays, on the local staging copy. The prototype copies no XenForo, NF/Rosters or theme CSS or template. The 7Cav logo, page background, award ribbons and rank insignia are the unit's own images from the live site.

## Known differences from the live forum

- Icons are Lucide (ISC) and Simple Icons (CC0), drawn at a light stroke to stand in for the forum's licensed icon set.
- The certificate plates are stand-ins built from the BSM design's art layers on `prototype/citation-render`. The MSM ribbon is the BSM ribbon recoloured, and the fixed wording is set in Tinos rather than S1's pixels. The look is approved separately before go-live.
- The editor toolbar is decoration, and only the Milpacs menu, the roster profile menus and the new pages respond. Everything else says it isn't part of the prototype.
- It leaves out the guest welcome notice and member avatars.

## Made up

Every member, officer, signature, citation text and record is invented. I checked every surname against the forum's user list before using it. The ink signatures are generated scribbles, and the disciplinary form is a blank placeholder page.
