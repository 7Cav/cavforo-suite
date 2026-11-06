<?php

namespace Cav7\Citations\Storage;

/**
 * Where a row's citation image comes from. A grant link outranks any image, and a zero
 * citation_date means no image whatever a stale reference says.
 */
enum ImageSource
{
    case Grant;
    case None;
    case Blob;
    case Legacy;

    public static function for(bool $hasGrantLink, int $citationDate, ?int $blobId): self
    {
        return match (true) {
            $hasGrantLink => self::Grant,
            $citationDate === 0 => self::None,
            $blobId !== null => self::Blob,
            default => self::Legacy,
        };
    }
}
