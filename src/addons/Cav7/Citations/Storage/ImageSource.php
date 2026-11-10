<?php

namespace Cav7\Citations\Storage;

/**
 * Where a row's citation image comes from. A grant link outranks any image, and a zero
 * citation_date means no image whatever a stale reference says. Past those, a legacy file
 * outranks the blob, so a restored file serves before the sweep takes it in.
 */
enum ImageSource
{
    case Grant;
    case None;
    case Blob;
    case Legacy;

    /**
     * @param \Closure(): bool $hasLegacyFile asked only when the row holds a reference
     */
    public static function for(bool $hasGrantLink, int $citationDate, ?int $blobId, \Closure $hasLegacyFile): self
    {
        return match (true) {
            $hasGrantLink => self::Grant,
            $citationDate === 0 => self::None,
            $blobId !== null && !$hasLegacyFile() => self::Blob,
            default => self::Legacy,
        };
    }
}
