<?php

/**
 * Issue #132 — the batched typed-$name link builder, exercised for real with a stub
 * lookup and no live XenForo. MentionFormatter::lookupMilpacLinks is a XenForo
 * class-extension (it extends XFCP_MentionFormatter, a proxy that only exists under a
 * live XenForo), so it cannot be constructed in this pure harness. Its four load-bearing
 * behaviours were extracted to the pure MilpacResolver::buildTypedMilpacLinks so they can
 * be driven in plain PHP:
 *
 *   (a) it lower-cases the finder's stored-casing username map so it lines up with the
 *       typed token cores,
 *   (b) it caches BOTH hits and misses, so the whole message costs ONE query and a second
 *       pass on the same cache costs none (the one-query-per-message guarantee),
 *   (c) it casts a purely-numeric core — "$5" arrives from typedMilpacCores as the INT
 *       array key 5 — to the string "5", and
 *   (d) it builds the exact-core => ['url','text'] links map the pure rewrite consumes.
 *
 * The live finder-backed lookup (findMilpacOwnersByUsernames) is the injected collaborator,
 * so this whole re-key/cache/numeric/link-building decision runs with no XenForo.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/TypedMilpacBatchLookupTest.php
 */

namespace Cav7\MilpacMention\Tests;

require __DIR__ . '/../MilpacResolver.php';

use Cav7\MilpacMention\MilpacResolver;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * A stand-in for MentionFormatter's live finder-backed owner lookup
 * (MilpacResolver::findMilpacOwnersByUsernames): given the distinct username cores the
 * batch collected, it exact-matches them against a holder set case-insensitively (as the
 * DB collation does) and returns the map keyed by the STORED username casing => the
 * already-shaped ['url','text'] link — the exact contract buildTypedMilpacLinks re-keys.
 * Every call appends the queried username set to $queries, so a test can assert the batch
 * ran exactly once and carried the right distinct cores.
 *
 * @param array<string, array{url:string, text:string}> $holders stored-casing username => shaped link
 * @param list<list<string>>                             $queries records the set handed to each query
 */
function stubOwnerLookup(array $holders, array &$queries): callable
{
    return static function (array $usernames) use ($holders, &$queries): array {
        $queries[] = $usernames;

        $byLower = [];
        foreach ($holders as $stored => $shaped) {
            $byLower[strtolower((string) $stored)] = [(string) $stored, $shaped];
        }

        $map = [];
        foreach ($usernames as $username) {
            $hit = $byLower[strtolower((string) $username)] ?? null;
            if ($hit !== null) {
                [$stored, $shaped] = $hit;
                $map[$stored] = $shaped; // keyed by the STORED casing, exactly like the finder
            }
        }

        return $map;
    };
}

$markel   = ['url' => 'https://board.example/rosters/profile/42/', 'text' => 'Corporal Markel.Z'];
$treck    = ['url' => 'https://board.example/rosters/profile/7/',  'text' => 'Sergeant Treck.M'];
$recruit5 = ['url' => 'https://board.example/rosters/profile/9/',  'text' => 'Recruit 5'];

// Keyed by the STORED (DB) casing, deliberately different from the typed casing the tests
// feed in, so the stored-casing -> lowercase re-keying is exercised, not assumed.
$holders = [
    'markel.z' => $markel,   // typed as "Markel.Z" below
    'Treck.M'  => $treck,    // typed as "treck.m" below
    '5'        => $recruit5, // a member literally named "5" — the numeric-core case
];

// ---------------------------------------------------------------------------
// (a) + (d) Stored-casing -> lowercase re-keying and the final exact-core => link map.
// The typed cores arrive in casings that differ from the stored (DB) casing: "Markel.Z"
// (stored "markel.z") and "treck.m" (stored "Treck.M"). The builder lower-cases the
// finder's map to line each holder up with its typed core, then keys the returned map by
// the EXACT core the rewrite looks up. A non-holder core is absent.
// ---------------------------------------------------------------------------
$queries = [];
$cache = [];
$links = MilpacResolver::buildTypedMilpacLinks(
    ['Markel.Z', 'treck.m', 'notaholder'],
    stubOwnerLookup($holders, $queries),
    $cache
);
check(
    'the final links map is keyed by the exact typed core and holds only holders, in first-seen order',
    $links === ['Markel.Z' => $markel, 'treck.m' => $treck],
    var_export($links, true)
);
check(
    'stored casings "markel.z"/"Treck.M" re-key to lower-case and match the typed cores "Markel.Z"/"treck.m"',
    ($links['Markel.Z'] ?? null) === $markel && ($links['treck.m'] ?? null) === $treck
);
check(
    'a non-holder core is absent from the links map (its $token stays literal)',
    !array_key_exists('notaholder', $links)
);

// ---------------------------------------------------------------------------
// (b) Caching of BOTH hits and misses: the whole message is ONE query, a repeated core
// never adds a second, and a second pass on the same cache (a repeat getMentionsBbCode on
// the per-message formatter) adds no query at all.
// ---------------------------------------------------------------------------
$queries = [];
$cache = [];
$lookup = stubOwnerLookup($holders, $queries);

// First pass: a repeated holder ("markel.z" twice) and an absent core, all in one call.
$first = MilpacResolver::buildTypedMilpacLinks(
    ['markel.z', 'markel.z', 'notaholder', 'treck.m'],
    $lookup,
    $cache
);
check(
    'the first pass queries the lookup exactly once (one query per message)',
    count($queries) === 1,
    count($queries) . ' quer(y/ies)'
);
check(
    'the single query carries the distinct uncached cores in first-seen order (the repeat is deduped)',
    ($queries[0] ?? null) === ['markel.z', 'notaholder', 'treck.m'],
    isset($queries[0]) ? implode(',', $queries[0]) : '(no query)'
);
check(
    'the first pass resolves the holders (the repeat collapses to one map entry) and drops the non-holder',
    $first === ['markel.z' => $markel, 'treck.m' => $treck],
    var_export($first, true)
);

// Second pass on the SAME cache repeats the hits AND the miss: the cached miss means no
// re-query, so the query count is unchanged — misses are cached, not just hits.
$second = MilpacResolver::buildTypedMilpacLinks(
    ['markel.z', 'notaholder', 'treck.m'],
    $lookup,
    $cache
);
check(
    'a second pass on the same cache adds no query, so both hits AND the miss were cached',
    count($queries) === 1,
    count($queries) . ' quer(y/ies) after the second pass'
);
check(
    'the second pass returns the same resolved links straight from cache',
    $second === ['markel.z' => $markel, 'treck.m' => $treck],
    var_export($second, true)
);

// A brand-new core in a later pass queries ONLY that core: the cache narrows the batch to
// the not-yet-resolved cores (the "treck.m" here is already cached under stored "Treck.M").
$third = MilpacResolver::buildTypedMilpacLinks(
    ['markel.z', 'Treck.M', 'newcomer'],
    $lookup,
    $cache
);
check(
    'a later pass queries only the cores not already cached (hit or miss)',
    count($queries) === 2 && ($queries[1] ?? null) === ['newcomer'],
    isset($queries[1]) ? implode(',', $queries[1]) : '(no second query)'
);
check(
    'the later pass resolves the cached holders (including "Treck.M" from its lower-cased cache key) and drops the new non-holder',
    $third === ['markel.z' => $markel, 'Treck.M' => $treck],
    var_export($third, true)
);

// ---------------------------------------------------------------------------
// (c) A purely-numeric core: "$5" comes out of typedMilpacCores as the INT array key 5,
// so the builder must cast it to the string "5" before the lookup — otherwise mb_strtolower
// receives an int and the finder is queried with the int 5, missing a member named "5".
// ---------------------------------------------------------------------------
$numericCores = MilpacResolver::typedMilpacCores('bonus $5 earned');
check(
    'typedMilpacCores yields the numeric core "$5" as the INT key 5 (the case the builder must cast)',
    $numericCores === [5],
    var_export($numericCores, true)
);
$queries = [];
$cache = [];
$numericLinks = MilpacResolver::buildTypedMilpacLinks($numericCores, stubOwnerLookup($holders, $queries), $cache);
check(
    'the numeric core is cast to the string "5" for the query (the finder never sees the int 5)',
    ($queries[0] ?? null) === ['5'],
    isset($queries[0]) ? var_export($queries[0], true) : '(no query)'
);
check(
    'the numeric core resolves to its holder link in the final map',
    $numericLinks === ['5' => $recruit5],
    var_export($numericLinks, true)
);

// ---------------------------------------------------------------------------
// No cores at all -> no query. buildTypedMilpacLinks is only called when typedMilpacCores
// found something, but guard the empty batch anyway so the contract is total.
// ---------------------------------------------------------------------------
$queries = [];
$cache = [];
$none = MilpacResolver::buildTypedMilpacLinks([], stubOwnerLookup($holders, $queries), $cache);
check(
    'an empty core set never queries the lookup and returns an empty map',
    count($queries) === 0 && $none === []
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
