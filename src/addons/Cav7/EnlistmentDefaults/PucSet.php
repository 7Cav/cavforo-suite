<?php

namespace Cav7\EnlistmentDefaults;

use function in_array;

/**
 * The standing PUC set: the fixed, bundled list of dated Presidential Unit
 * Citation grants (NF/Rosters award 61) every new milpac receives, and the
 * citation JPG that ships with each date.
 *
 * Pure, XenForo-free data. The set is bundled in the addon rather than derived
 * at runtime (see docs/adr/0001-bundle-citation-set.md): the dates are listed
 * here and the citation images live beside this file under
 * _assets/puc-citations/<YYYY-MM-DD>.jpg, the date being both the filename and
 * the award_date stamped on the grant.
 *
 * The set grows only when the unit earns another PUC, which means adding its
 * date here and its citation image to _assets/, then cutting a release.
 */
class PucSet
{
    /**
     * The six dates the unit has earned the PUC, in earned (ascending) order.
     * Each is an award_date for one RosterUserAward of the PUC award.
     */
    private const DATES = [
        '2003-03-18',
        '2004-09-01',
        '2009-08-10',
        '2010-09-18',
        '2011-06-02',
        '2021-05-16',
    ];

    /** Where the bundled citation JPGs live, relative to this file. */
    private const ASSET_DIR = '_assets/puc-citations';

    /**
     * The PUC dates, as 'Y-m-d' strings, in earned order.
     *
     * @return string[]
     */
    public static function dates(): array
    {
        return self::DATES;
    }

    /**
     * Absolute path to the bundled citation JPG for a PUC date.
     *
     * @throws \InvalidArgumentException if the date is not in the bundled set
     */
    public static function citationPath(string $date): string
    {
        if (!in_array($date, self::DATES, true)) {
            throw new \InvalidArgumentException(
                "Date '$date' is not in the bundled PUC set"
            );
        }

        return __DIR__ . '/' . self::ASSET_DIR . '/' . $date . '.jpg';
    }

    /**
     * The unix timestamp for a PUC date's award_date column: midnight UTC on
     * that calendar day. Stored as an int, it round-trips back to the date.
     *
     * @throws \InvalidArgumentException if the date is malformed
     */
    public static function awardDateTimestamp(string $date): int
    {
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new \DateTimeZone('UTC')
        );

        if ($dt === false) {
            throw new \InvalidArgumentException("Malformed PUC date '$date'");
        }

        return $dt->getTimestamp();
    }
}
