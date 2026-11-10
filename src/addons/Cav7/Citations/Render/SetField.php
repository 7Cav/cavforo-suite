<?php

namespace Cav7\Citations\Render;

/**
 * One field of a typesetting, and one row of xf_cav7_citation_typesetting less
 * its ids and version stamps. `lines` is null on rank art, which is what keeps
 * `{"art":null}` apart from a text field's `{"lines":[]}`.
 */
final class SetField
{
    /** @param list<Line>|null $lines */
    public function __construct(
        public readonly string $key,
        public readonly float $size,
        public readonly bool $belowLegibility,
        public readonly ?array $lines,
        public readonly ?PlacedImage $ink = null,
        public readonly ?PlacedImage $art = null,
    ) {
    }

    /** @return array{field_key: string, size: float, below_legibility: int, set_data: string} */
    public function row(): array
    {
        return [
            'field_key' => $this->key,
            'size' => $this->size,
            'below_legibility' => (int) $this->belowLegibility,
            'set_data' => json_encode(
                $this->setData(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ];
    }

    /** @param array{field_key: string, size: float|string, below_legibility: int|bool, set_data: string} $row */
    public static function fromRow(array $row): self
    {
        $data = json_decode($row['set_data'], true, 8, JSON_THROW_ON_ERROR);

        return new self(
            $row['field_key'],
            (float) $row['size'],
            (bool) $row['below_legibility'],
            isset($data['lines'])
                ? array_map(fn (array $line): Line => new Line(...$line), $data['lines'])
                : null,
            isset($data['ink']) ? new PlacedImage(...$data['ink']) : null,
            isset($data['art']) ? new PlacedImage(...$data['art']) : null,
        );
    }

    /** @return array<string, mixed> */
    private function setData(): array
    {
        if ($this->lines === null) {
            return ['art' => $this->art];
        }
        return ($this->ink !== null ? ['ink' => $this->ink] : []) + ['lines' => $this->lines];
    }
}
