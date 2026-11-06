<?php

namespace Cav7\Citations\Storage;

/**
 * The sweep's decisions, on plain values.
 */
final class SweepRules
{
    public const BRAKE_LIMIT = 50;
    public const MIN_FILE_AGE = 3600;

    public const TAKE_IN = 'take_in';
    public const ORPHAN_ROWLESS = 'orphan_rowless';
    public const ORPHAN_GRANT_LINKED = 'orphan_grant_linked';
    public const DAMAGE = 'damage';

    /**
     * The record id a legacy file's name claims, or null unless the name is the
     * vendor's own for that shard.
     */
    public static function legacyRecordId(string $name, int $shard): ?int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,17})\.jpg$/', $name, $match)) {
            return null;
        }

        $id = (int) $match[1];

        return BlobFile::shard($id) === $shard ? $id : null;
    }

    public static function isSettled(int $mtime, int $now): bool
    {
        return $now - $mtime >= self::MIN_FILE_AGE;
    }

    /**
     * What a legacy file is, given its row as it stands. Only zero and non-zero mean
     * anything in citation_date, and award rows hold it signed.
     *
     * @param array{citation_date: int, grant_linked: bool}|null $row
     */
    public static function classify(?array $row): string
    {
        if ($row === null) {
            return self::ORPHAN_ROWLESS;
        }
        if ($row['grant_linked']) {
            return self::ORPHAN_GRANT_LINKED;
        }

        return $row['citation_date'] === 0 ? self::DAMAGE : self::TAKE_IN;
    }

    /**
     * @return list<string> what is wrong with this row, if anything
     */
    public static function rowDamage(
        int $citationDate,
        ?int $blobId,
        bool $grantLinked,
        bool $blobFound,
        bool $legacyFileExists
    ): array {
        $damage = [];

        if ($citationDate !== 0 && $blobId === null && !$grantLinked && !$legacyFileExists) {
            $damage[] = 'row has a citation but no blob, grant link or legacy file';
        }
        if ($blobId !== null && !$blobFound) {
            $damage[] = 'reference to a blob with no row';
        }
        if ($blobId !== null && $citationDate === 0) {
            $damage[] = 'reference on a row with no citation';
        }
        if ($blobId !== null && $grantLinked) {
            $damage[] = 'reference on a grant-linked row';
        }

        return $damage;
    }

    public static function brakeHolds(int $count, bool $lifted): bool
    {
        return !$lifted && $count > self::BRAKE_LIMIT;
    }
}
