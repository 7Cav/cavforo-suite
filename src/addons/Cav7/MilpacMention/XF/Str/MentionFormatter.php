<?php

namespace Cav7\MilpacMention\XF\Str;

use Cav7\MilpacMention\MilpacResolver;

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
     * The XF:User finder the batched typed-$name lookup runs its single IN(…) query through,
     * injectable so the query dependency is a seam rather than a hard-wired \XF::finder call
     * (#132). Null in production — userFinder() then hands the batch a FRESH
     * \XF::finder('XF:User') for each query, exactly as the inlined lookup did, so behaviour
     * and the query count are byte-for-byte unchanged; setUserFinder is the seam a
     * live-XenForo integration test would drive this shell with. The pure
     * re-key/cache/numeric/link-building logic is exercised without XenForo in
     * TypedMilpacBatchLookupTest via MilpacResolver::buildTypedMilpacLinks.
     *
     * @var \XF\Finder\UserFinder|null
     */
    protected $userFinder;

    /**
     * Inject the finder the batched lookup queries (#132). Production never calls this —
     * userFinder() defaults to a fresh \XF::finder('XF:User') per query.
     *
     * @param \XF\Finder\UserFinder $userFinder
     */
    public function setUserFinder($userFinder): void
    {
        $this->userFinder = $userFinder;
    }

    /**
     * The injected \XF:User finder, or a fresh real one by default (#132). A XenForo finder
     * accumulates its where()/with() constraints by mutation, and the inlined lookup built a
     * new finder on each call that queried, so the default stays per-call fresh rather than
     * memoised — an un-injected formatter queries exactly as it did before.
     *
     * @return \XF\Finder\UserFinder
     */
    protected function userFinder()
    {
        return $this->userFinder ?? \XF::finder('XF:User');
    }

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
        // returns the member's post untouched. setupPlaceholders is preg_replace_callback
        // under the hood and returns null (not throws) on a PCRE backtrack/recursion limit;
        // that null then flows into resolveTypedMilpacs' typed string param as a TypeError.
        // restorePlaceholders, by contrast, is strtr-based in the parent and never returns
        // null — but it runs in the finally, so any throw from it still propagates out to
        // this same catch. Without this capture the catch would `return $message` — now null
        // or half-masked — blanking the post, contradicting the "message left unchanged"
        // log. Returning $original makes that guarantee total: it covers the setupPlaceholders
        // null path and any \Throwable raised anywhere in the try/finally.
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
     * A thin delegate (#132): the re-keying, the hit-and-miss caching, the numeric-core
     * handling and the link-building are the pure MilpacResolver::buildTypedMilpacLinks,
     * unit-tested with a stub lookup and no live XenForo. This XenForo-coupled shell supplies
     * only the two things that need a live XenForo — the batched finder call
     * (findMilpacOwnersByUsernames over the injected \XF:User finder) as the owner lookup, and
     * this formatter's per-message cache. The finder still runs its single IN(…) query here in
     * the closure, memoised per message (only uncached cores are queried, hits AND misses
     * cached), so a $name repeated in a message never re-queries and a second getMentionsBbCode
     * call on this formatter adds no query. The cache dies with the per-message formatter, so
     * nothing leaks across messages. Output and the query count are byte-for-byte what the
     * inlined logic produced before the extraction.
     *
     * @param list<string> $cores
     *
     * @return array<string, array{url: string, text: string}>
     */
    protected function lookupMilpacLinks(array $cores): array
    {
        return MilpacResolver::buildTypedMilpacLinks(
            $cores,
            function (array $usernames): array {
                return MilpacResolver::findMilpacOwnersByUsernames($this->userFinder(), $usernames);
            },
            $this->milpacLinkCache
        );
    }
}
