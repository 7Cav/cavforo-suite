# Plates plus typesetting

2026-11-06. Decided in [#274](https://github.com/7Cav/cavforo-suite/issues/274) and [#341](https://github.com/7Cav/cavforo-suite/issues/341).

S1 exports each design's artwork from GIMP as a plate, with the fixed text baked in and the signature block left out. The generator sets the typed fields on the plate with Imagick. It never opens a design file and never redraws a design.

The addon stores a citation as data and draws it when someone opens it, so the renderer runs on prod on every cache miss for as long as the citation exists. Driving the `.xcf` files would keep GIMP on the prod box for good and make the layer names in S1's working files something production depends on. S1 saves over those files in place, and two have gone to 0 bytes. A plate and a set of boxes need only Imagick, which the re-encode already requires. A redraw in HTML or SVG would throw away S1's seals and ribbons and gain nothing. Matching today's files pixel for pixel was never on offer, because the portrait layout lays every design out again whichever route wins.

## Templates

The generator keeps its own frozen copy of every template version, plate and font files included, and reads nothing from the Drive at render time. A version freezes on publish, and a citation pins the version that was current at issue. A new version moves no issued citation by itself. S1 can move chosen citations onto a corrected version, and each move is a logged correction.

S1 owns the designs, so S1 builds templates in the template manager, typing each field's box beside a preview render. There is no drag-to-place. The developer owns the renderer and the font list. An award with no published template offers nothing on the grant form, and there is no fallback plate.

A field's box is in plate pixels and a render comes out at plate size, so a box lined up on the preview lands in the same place on every citation.

## Fonts

Typed fields use only OFL fonts shipped with the addon, with Tinos in place of Times New Roman. Almost no face in the design files may go on a server under its licence, while an image made on a licensed desktop is fine. So fixed text stays as S1's pixels in the plate. There is no font upload, and adding a font takes a release.

## Text that won't fit

The form always saves. The typesetter sets text at the largest size that fits, with no floor. The typesetting flags a field below its legibility threshold, and S1 fixes it by publishing a version with a bigger box and moving the citation there. Blocking the save would be wrong, because by the time an S1 Citations clerk sees the text it has been reviewed, approved and posted as awarded.

## Stored typesetting

A release or an image rebuild never changes the words, type sizes or line breaks on an issued citation. It can change only how glyphs rasterise and how the JPEG encodes. The fit measures character widths through ImageMagick and FreeType, so an image rebuild alone would re-wrap text if opening a citation fitted it again. So the addon stores each typesetting, one row per field, and opening a citation only draws what's stored.

The addon makes a typesetting at issue, when a member is added, for the fields a correction changes, and on a retypeset. Nothing else makes one. Fields that use nothing about the member are set once for the grant and shared, so a member added later gets the same line breaks in the citation text as everyone else. Each row records the addon, ImageMagick and FreeType versions that made it, since one grant can hold rows from several releases.

The cost is that a fit fix reaches only typesettings made after it ships. An operator's retypeset brings it to chosen citations already issued. The developer decides when, and S1 does nothing.

A stored line that measures more than 1px past its box after a rebuild still serves, and the addon logs it once per template version until the library versions change. Failing the render would take every certificate on the version offline over a few pixels. The fix is a retypeset.

The render URL carries no renderer version, because one would change every member's URL on every release. The prod image stays unpinned, because a rebuild can't reach a typesetting, and pinned Alpine packages break the build once old versions leave the repository.

## Considered options

- Fitting text on every view, behind a release gate that re-renders every issued grant on a dev stack and compares. It costs a dev-stack run per release and never sees an image rebuild, since rebuilds happen outside releases.
- Pinning a renderer version to each template version. The libraries still change underneath it, and the addon carries every old fit forever.
