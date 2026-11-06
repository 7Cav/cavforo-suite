<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Storage\BlobFile;

/**
 * An uploaded plate, ink or rank art image, read the only safe way: format from the leading
 * bytes, then a decode with that format's coder. A bare path would let ImageMagick pick its
 * own coder from the content, SVG and MVG included.
 */
final class ImageUpload
{
    public const PLATE_FORMATS = ['png', 'jpeg'];
    public const ART_FORMATS = ['png'];

    private const MAX_SIDE = 4000;
    private const MAX_PIXELS = 12_000_000;
    private const PLATE_WIDTHS = [1200, 1350];

    public function __construct(
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /**
     * @param list<string> $accepted
     * @throws UploadRefused
     */
    public static function read(string $path, array $accepted): self
    {
        $format = BlobFile::format((string) @file_get_contents($path, false, null, 0, 8));
        if (!in_array($format, $accepted, true)) {
            throw new UploadRefused(UploadRefused::NOT_AN_ACCEPTED_IMAGE);
        }

        try {
            $image = new \Imagick();
            $image->readImage("$format:$path");
        } catch (\ImagickException $e) {
            throw new UploadRefused(UploadRefused::UNDECODABLE_IMAGE, 0, $e);
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        if (max($width, $height) > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new UploadRefused(UploadRefused::IMAGE_TOO_LARGE);
        }

        return new self($format, $width, $height);
    }

    /** A plate off this width still saves: the PUC originals are 511 and 600px wide. */
    public function plateWidthWarns(): bool
    {
        return $this->width < self::PLATE_WIDTHS[0] || $this->width > self::PLATE_WIDTHS[1];
    }
}
