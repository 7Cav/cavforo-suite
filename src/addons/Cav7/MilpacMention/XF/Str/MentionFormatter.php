<?php

namespace Cav7\MilpacMention\XF\Str;

use Cav7\MilpacMention\MilpacResolver;
use XF\Util\Str;

/**
 * The typed-$name server-side seam (issue #123). This is the exact class where
 * XenForo resolves a bare typed @username on the server — getMentionsBbCode(), run by
 * the mentions BBCode filterer during message preparation (and preview) for EVERY
 * mention surface — and it is class-extensible through Formatter::getMentionFormatter's
 * \XF::extendClass(). Extending it puts $ resolution in the same pipeline stage as @,
 * so $ inherits @'s word-boundary matching and its no-parse-context exclusions by
 * construction, and — because the pass runs inside prepare(), before the shared
 * detection hook reads the message — a typed $name becomes a roster link that the
 * existing engine (extractRelationIds -> milpac_mention) already detects and fires. No
 * new alert path is added; the change lands on all five surfaces at once.
 *
 * The one intended difference from @ is the target, not the mechanism: @name becomes a
 * [USER=…] chip and pings the mention system, while $name becomes the member's named
 * roster link ("Rank Name" -> /rosters/profile/<relation_id>/) that fires milpac_mention.
 * Everything about WHEN a token resolves matches @; only what it resolves TO differs.
 *
 * This class is the thin XenForo-coupled shell: it reuses the parent's placeholder
 * masking to exclude [CODE]/[PLAIN]/[URL=…]/quote (so the exclusion is XenForo's
 * machinery, not a hand-rolled regex that could drift), and delegates the $-boundary
 * decision and the owner shaping to the pure MilpacResolver methods, which unit-test in
 * plain PHP.
 */
class MentionFormatter extends XFCP_MentionFormatter
{
    /**
     * @var array<string, array{url: string, text: string}|null> lower-cased username =>
     *      the shaped roster link, or null for a non-holder. One instance per message
     *      (getMentionFormatter builds a fresh formatter each call), so the batched $ pass
     *      queries only cores it has not already resolved and a $name repeated across
     *      messages never leaks — the cache dies with the per-message formatter.
     */
    protected $milpacLinkCache = [];

    /**
     * Run the stock @ pass first (unchanged: @username -> [USER=…], mentionedUsers
     * populated), then the $ pass over the result, so the two are independent stages and
     * @ behaviour is never touched.
     */
    public function getMentionsBbCode($message)
    {
        $message = parent::getMentionsBbCode($message);

        return $this->applyMilpacMentions($message);
    }

    /**
     * The $ pass: mask the parse-disabled BBCode regions with the SAME placeholder
     * machinery @ uses (getMentionDisabledBbCodeTags + setupPlaceholders/restore), then
     * resolve typed $username tokens in the unmasked text to their named roster links.
     *
     * Contained: getMentionsBbCode runs on the save/preview path inside BBCode
     * rendering, so a transient DB fault or a vendor schema drift in the lookup must not
     * break a member's post — any failure is logged and the message is returned with the
     * @ pass intact and $ tokens simply left literal (mirrors the engine's other
     * contained hooks). restorePlaceholders runs in a finally so a mid-pass failure can
     * never leave the internal placeholder markers in the stored message.
     */
    protected function applyMilpacMentions($message)
    {
        // Capture the good, @-resolved input BEFORE any masking, so every throw path
        // returns the member's post untouched. setupPlaceholders/restorePlaceholders are
        // preg_replace_callback under the hood and return null (not throw) on a PCRE
        // backtrack/recursion limit; a null then flows into resolveTypedMilpacs' typed
        // string param as a TypeError, or leaves $message null after the finally. Without
        // this the catch would `return $message` — now null/half-masked — blanking the
        // post, contradicting the "message left unchanged" log. Returning $original in the
        // catch makes that guarantee total: it also covers a restorePlaceholders throw
        // inside the finally, whose exception propagates out to this same catch.
        $original = $message;

        try {
            $disabledTags = array_map(
                function ($v) { return preg_quote($v, '#'); },
                $this->getMentionDisabledBbCodeTags()
            );
            $message = $this->setupPlaceholders(
                $message,
                '#\[(' . implode('|', $disabledTags) . ')([= ][^\]]*)?](.*)\[/\\1]#siU'
            );

            try {
                $message = MilpacResolver::resolveTypedMilpacs(
                    $message,
                    function (array $cores): array {
                        return $this->lookupMilpacLinks($cores);
                    }
                );
            } finally {
                $message = $this->restorePlaceholders($message);
            }
        } catch (\Throwable $e) {
            \XF::logException($e, false, '[Cav7/MilpacMention] typed-$name resolution failed; message left unchanged: ');

            return $original;
        }

        return $message;
    }

    /**
     * Resolve a batch of distinct typed-$name token cores to their shaped named-roster
     * links in ONE username query (#125), the $-sigil analogue of stock @'s single
     * `WHERE username IN (…)`. Given the distinct cores the pure pass collected, return a
     * map keyed by the exact core string => the ['url', 'text'] it inserts, for each core
     * that is a current milpac holder; a core that is not (a non-member, a banned/dormant
     * member, or a member who owns no milpac — the INNER join drops them) is absent from
     * the map, so its typed $username stays literal.
     *
     * Memoised per message: only cores not already resolved are queried, and hits AND
     * misses are cached, so a $name repeated in a message never re-queries and a second
     * getMentionsBbCode call on this formatter adds no query. The cache dies with the
     * per-message formatter, so nothing leaks across messages.
     *
     * @param list<string> $cores
     *
     * @return array<string, array{url: string, text: string}>
     */
    protected function lookupMilpacLinks(array $cores): array
    {
        // Usernames match case-insensitively at the DB collation (as @'s lookup does), so
        // key the cache on the lower-cased core: "$Markel.Z" and "$markel.z" share one
        // cache slot and one IN(…) entry. Collect the cores not yet resolved.
        $uncached = []; // lower-cased key => the core to query (first-seen casing)
        foreach ($cores as $core) {
            $core = (string) $core; // a purely-numeric core (e.g. "$5") arrives as an int key
            $key = Str::strtolower($core);
            if (!array_key_exists($key, $this->milpacLinkCache)) {
                $uncached[$key] = $core;
            }
        }

        if ($uncached) {
            /** @var \XF\Finder\UserFinder $userFinder */
            $userFinder = \XF::finder('XF:User');
            $found = MilpacResolver::findMilpacOwnersByUsernames($userFinder, array_values($uncached));

            // The finder keys its map by the stored username casing; re-key to lower-case
            // so a typed core matches its holder regardless of case.
            $foundByKey = [];
            foreach ($found as $username => $shaped) {
                $foundByKey[Str::strtolower((string) $username)] = $shaped;
            }

            // Cache every queried core — hit or miss (null) — so a repeat never re-queries.
            foreach ($uncached as $key => $core) {
                $this->milpacLinkCache[$key] = $foundByKey[$key] ?? null;
            }
        }

        // Build the core => link map the pure pass consumes: only cores that resolved.
        $links = [];
        foreach ($cores as $core) {
            $core = (string) $core;
            $shaped = $this->milpacLinkCache[Str::strtolower($core)] ?? null;
            if ($shaped !== null) {
                $links[$core] = $shaped;
            }
        }

        return $links;
    }
}
