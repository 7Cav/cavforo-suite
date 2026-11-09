<?php

namespace Cav7\Citations\Render;

/** A stored typesetting joined to its version: everything the draw needs, and nothing it must look up. */
final class DrawPlan
{
    /**
     * @param list<PlannedLine|PlannedImage> $steps In draw order, which is field order.
     * @param list<PreviewBox> $previewBoxes
     */
    public function __construct(
        public readonly Plate $plate,
        public readonly array $steps,
        public readonly array $previewBoxes = [],
    ) {
    }

    /**
     * @param array<string, SetField> $rows By field key. A member's row and the shared rows together.
     * @param array<string, string> $fontPaths Font key => path, pinned or catalog.
     * @param \Closure(string): string $imagePath Image hash => path.
     * @param list<PreviewBox> $previewBoxes
     * @throws RenderFailed A field with no row, or a line with no font path.
     */
    public static function build(Version $version, array $rows, Plate $plate, array $fontPaths, \Closure $imagePath, array $previewBoxes = []): self
    {
        $steps = [];

        foreach ($version->fields as $field) {
            $row = $rows[$field->key] ?? throw new RenderFailed("No typesetting row for field {$field->key}");

            // A slot's ink goes under its name lines, as in the prototype.
            foreach ([$row->ink, $row->art] as $image) {
                if ($image !== null) {
                    $steps[] = new PlannedImage($imagePath($image->hash), $image);
                }
            }

            foreach ($row->lines ?? [] as $line) {
                $steps[] = new PlannedLine(
                    $field->key,
                    $fontPaths[$field->font] ?? throw new RenderFailed("No path for font {$field->font}"),
                    $row->size,
                    $field->colour,
                    $line,
                    $field->x + $field->w,
                );
            }
        }

        return new self($plate, $steps, $previewBoxes);
    }
}
