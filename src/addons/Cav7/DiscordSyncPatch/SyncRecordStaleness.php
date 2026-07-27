<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Issue #157 — decides, from the forum side alone, whether a member's sync log row
 * still describes a correct, successful sync on one guild.
 *
 * Pure: no XenForo, no database, no I/O. The sweep fetches the rows and acts on the
 * answer; the rule for what counts as stale lives here.
 */
class SyncRecordStaleness
{
    /**
     * @param int[]|string[] $recordedGroupIds The group set stored on the row by the
     *                                         last successful sync.
     * @param int[]|string[] $currentGroupIds  The member's groups now.
     * @param bool           $active           The row's active flag.
     * @param string|null    $errorPhrase      The phrase key the last failure stored,
     *                                         or null if none was.
     */
    public static function isStale(
        array $recordedGroupIds,
        array $currentGroupIds,
        bool $active,
        ?string $errorPhrase
    ): bool
    {
        // A row the vendor left inactive describes a sync that did not finish. It is
        // tested first because it is the broadest of the three: SyncLog::setInvalid
        // clears the flag and stores the phrase together, so every errored row is
        // also an inactive one, and the disconnect path leaves the flag clear with no
        // phrase at all.
        if (!$active) {
            return true;
        }

        // Kept separate rather than folded into the flag above. The two are written
        // together today, but a phrase on an otherwise active row would still name a
        // member whose last sync failed, and reading only the flag would settle them.
        if ($errorPhrase !== null && $errorPhrase !== '') {
            return true;
        }

        return self::normalize($recordedGroupIds) !== self::normalize($currentGroupIds);
    }

    /**
     * A group set is a set: order carries no meaning, and the two sides reach this
     * from different places (a stored column against the user entity) so they can
     * disagree on type while naming the same groups.
     *
     * @param int[]|string[] $groupIds
     * @return int[]
     */
    private static function normalize(array $groupIds): array
    {
        $normalized = array_unique(array_map('intval', $groupIds));
        sort($normalized);

        return $normalized;
    }
}
