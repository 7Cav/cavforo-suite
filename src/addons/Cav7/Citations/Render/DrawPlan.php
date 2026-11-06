<?php

namespace Cav7\Citations\Render;

/** A stored typesetting joined to its version: everything the draw needs, and nothing it must look up. */
final class DrawPlan
{
    /**
     * @param list<PlannedLine> $lines
     * @param list<PlannedImage> $images
     */
    public function __construct(
        public readonly Plate $plate,
        public readonly array $lines,
        public readonly array $images,
    ) {
    }

    /**
     * @param array<string, SetField> $rows By field key. A member's row and the shared rows together.
     * @param array<string, string> $fontPaths Font key => path, pinned or catalog.
     * @param \Closure(string): string $imagePath Image hash => path.
     * @throws RenderFailed A field with no row, or a line with no font path.
     */
    public static function build(Version $version, array $rows, Plate $plate, array $fontPaths, \Closure $imagePath): self
    {
        $lines = [];
        $images = [];

        foreach ($version->fields as $field) {
            $row = $rows[$field->key] ?? throw new RenderFailed("No typesetting row for field {$field->key}");

            foreach ($row->lines ?? [] as $line) {
                $lines[] = new PlannedLine(
                    $field->key,
                    $fontPaths[$field->font] ?? throw new RenderFailed("No path for font {$field->font}"),
                    $row->size,
                    $field->colour,
                    $line,
                    $field->x + $field->w,
                );
            }

            foreach ([$row->ink, $row->art] as $image) {
                if ($image !== null) {
                    $images[] = new PlannedImage($imagePath($image->hash), $image);
                }
            }
        }

        return new self($plate, $lines, $images);
    }
}
