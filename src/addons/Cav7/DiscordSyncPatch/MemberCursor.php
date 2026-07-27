<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Issue #157 — advances the bulk guild member fetch.
 *
 * Discord pages `guilds/{id}/members` with an `after` cursor rather than an offset:
 * each request returns the members following a given id, and a page shorter than the
 * requested limit is the last one. (The vendor's own getGuildMembers() sends an
 * `offset` Discord does not honour, and sends it in a GET body besides, so this
 * addon walks the endpoint itself.)
 *
 * Pure: no XenForo, no database, no I/O. The sweep makes the requests; where the
 * next one starts and whether to make it are decided here.
 */
class MemberCursor
{
    /**
     * @param string[] $memberIds The Discord user ids in the page just read.
     * @param int      $limit     The page size that was requested.
     *
     * @return string|null The id to pass as `after`, or null when the walk is over.
     */
    public static function next(array $memberIds, int $limit): ?string
    {
        // Short page, last page. Discord has no other end-of-list signal.
        if (count($memberIds) < $limit) {
            return null;
        }

        // max() over numeric strings, not sort()-and-take-last: PHP compares numeric
        // strings as integers, which is exact for every id Discord issues this
        // century, where a text comparison ranks a shorter id above a longer one.
        // Deciding it here rather than reading the page's last element also keeps the
        // rule independent of the order Discord happens to return.
        return (string) max(array_map('strval', $memberIds));
    }
}
