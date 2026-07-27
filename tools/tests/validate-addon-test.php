<?php

/**
 * validate-addon-test.php — pins the checks in tools/validate-addon.php that
 * nothing else in the repo covers, with no XenForo and no test framework.
 * Self-contained: builds throwaway fixture addon dirs in the system temp dir,
 * runs the real tool against them, asserts on its exit code and output, then
 * cleans up. Exits non-zero on any failure.
 *
 * Cases 1-5 pin the canonical class-extension order check (issue #152,
 * ADR 0004). The gap that one guards: nothing in the repo checked the order of
 * _data/class_extensions.xml. check-data-consistency.php compares class
 * extensions as an unordered set, so a file whose rows had drifted from what an
 * export produces still validated, and the drift only surfaced as churn the
 * next time somebody re-exported. Two committed files had drifted that way.
 *
 * Case 6 pins the version_id / version_string agreement check (issue #217).
 * The gap that one guards: version_id is what XenForo compares to decide a
 * board needs this addon's data, nothing hashes _data, and nothing tied the
 * integer to the human version — so an addon could ship data under a version_id
 * that decoded to a version nobody released. Four committed manifests had.
 *
 * Run:
 *   php tools/tests/validate-addon-test.php
 *
 * Note: CI runs the tools/ tests via the tools-test job, which invokes
 * tools/run-tools-tests.sh.
 */

namespace Cav7\Tools\Tests;

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

$tool = dirname(__DIR__) . '/validate-addon.php';

/**
 * Build a throwaway fixture addon under $base holding a valid addon.json and a
 * _data/class_extensions.xml with $rows, each row a [from_class, to_class] pair.
 * Passing null for $rows writes no class_extensions.xml at all.
 *
 * $versionId and $versionString default to an agreeing pair, so fixtures aimed
 * at other checks do not trip the version-agreement one. 1000070 is 1.0.0 in
 * XenForo's scheme; see XF's own upgrade file 1000170-101.php for 1.0.1.
 */
function makeFixture(
    string $base,
    string $name,
    ?array $rows,
    int $versionId = 1000070,
    string $versionString = '1.0.0'
): string {
    $dir = "$base/$name";
    mkdir("$dir/_data", 0777, true);
    file_put_contents("$dir/addon.json", json_encode([
        'title' => $name,
        'version_id' => $versionId,
        'version_string' => $versionString,
    ], JSON_PRETTY_PRINT));

    if ($rows === null) {
        return $dir;
    }

    $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<class_extensions>\n";
    foreach ($rows as [$from, $to]) {
        $xml .= sprintf(
            "  <extension from_class=\"%s\" to_class=\"%s\" execute_order=\"10\" active=\"1\"/>\n",
            htmlspecialchars($from, ENT_XML1),
            htmlspecialchars($to, ENT_XML1)
        );
    }
    $xml .= "</class_extensions>\n";
    file_put_contents("$dir/_data/class_extensions.xml", $xml);

    return $dir;
}

/** Run the real tool against a fixture; return [exitCode, combinedOutput]. */
function runTool(string $tool, string $addonDir): array
{
    $cmd = 'php ' . escapeshellarg($tool) . ' ' . escapeshellarg($addonDir) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

/** Recursively remove a directory tree. */
function rmrf(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            rmrf("$path/$entry");
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

$base = sys_get_temp_dir() . '/cav7-validate-addon-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);

try {
    // --- Case 1: rows out of canonical order are refused ------------------
    // MilpacTooltip's committed shape: N sorts before X under every collation,
    // so this file is in no sorted order at all and no export produces it.
    $dir = makeFixture($base, 'Misordered', [
        ['XF\\BbCode\\Renderer\\Html', 'Cav7\\Misordered\\XF\\BbCode\\Renderer\\Html'],
        ['NF\\Rosters\\Pub\\Controller\\Roster', 'Cav7\\Misordered\\NF\\Rosters\\Pub\\Controller\\Roster'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('a misordered class_extensions.xml exits non-zero', $code !== 0, $out);
    check('the failure names the addon', str_contains($out, 'FAIL Misordered'), $out);
    check(
        'the failure names the row that is listed too early',
        str_contains($out, 'XF\\BbCode\\Renderer\\Html'),
        $out
    );
    check(
        'the failure names the row it should have followed',
        str_contains($out, 'NF\\Rosters\\Pub\\Controller\\Roster'),
        $out
    );

    // --- Case 2: the check does not fire on files that are in order -------
    // MilpacMention's shape, reconciled. ProfilePostComment before ProfilePost
    // is correct: 'C' (0x43) sorts ahead of the '\' separator (0x5C).
    $dir = makeFixture($base, 'Ordered', [
        ['NF\\Tickets\\Alert\\Message', 'Cav7\\Ordered\\NF\\Tickets\\Alert\\Message'],
        ['XF\\Alert\\PostHandler', 'Cav7\\Ordered\\XF\\Alert\\PostHandler'],
        ['XF\\Service\\ProfilePostComment\\NotifierService', 'Cav7\\Ordered\\XF\\Service\\ProfilePostComment\\NotifierService'],
        ['XF\\Service\\ProfilePost\\NotifierService', 'Cav7\\Ordered\\XF\\Service\\ProfilePost\\NotifierService'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('a file in canonical order passes', $code === 0, $out);

    $dir = makeFixture($base, 'SingleRow', [
        ['XF\\Str\\MentionFormatter', 'Cav7\\SingleRow\\XF\\Str\\MentionFormatter'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('a single-row file passes', $code === 0, $out);

    $dir = makeFixture($base, 'ZeroRows', []);
    [$code, $out] = runTool($tool, $dir);
    check('a zero-row file passes', $code === 0, $out);

    $dir = makeFixture($base, 'NoExtensions', null);
    [$code, $out] = runTool($tool, $dir);
    check('an addon with no class_extensions.xml passes', $code === 0, $out);

    // --- Case 3: canonical order is case-folded, not a byte comparison ----
    // Why: docs/adr/0004-class-extension-order-is-case-folded.md. This pair is
    // the one the database really emits and a byte comparison would reject, so
    // it is what keeps the rule honest.
    $dir = makeFixture($base, 'CaseFolded', [
        ['XenAddons\\AMS\\Entity\\ArticleItem', 'Cav7\\CaseFolded\\XenAddons\\AMS\\Entity\\ArticleItem'],
        ['XF\\Str\\MentionFormatter', 'Cav7\\CaseFolded\\XF\\Str\\MentionFormatter'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('export order with a case-folded namespace passes', $code === 0, $out);

    // The inverse is what a byte comparison would have accepted, and no export
    // produces it.
    $dir = makeFixture($base, 'ByteOrdered', [
        ['XF\\Str\\MentionFormatter', 'Cav7\\ByteOrdered\\XF\\Str\\MentionFormatter'],
        ['XenAddons\\AMS\\Entity\\ArticleItem', 'Cav7\\ByteOrdered\\XenAddons\\AMS\\Entity\\ArticleItem'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('byte order with a case-folded namespace is refused', $code !== 0, $out);

    // --- Case 4: to_class breaks a tie on from_class ----------------------
    // XenForo allows two extensions against one from_class, and runs them in
    // sequence, so to_class is the second sort key and the last one that
    // decides: UNIQUE KEY (from_class, to_class) makes the pair unique.
    $dir = makeFixture($base, 'TiedFrom', [
        ['XF\\Entity\\User', 'Cav7\\TiedFrom\\Second\\Entity\\User'],
        ['XF\\Entity\\User', 'Cav7\\TiedFrom\\First\\Entity\\User'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('a tie on from_class is refused when to_class is out of order', $code !== 0, $out);

    $dir = makeFixture($base, 'TiedFromOk', [
        ['XF\\Entity\\User', 'Cav7\\TiedFromOk\\First\\Entity\\User'],
        ['XF\\Entity\\User', 'Cav7\\TiedFromOk\\Second\\Entity\\User'],
    ]);
    [$code, $out] = runTool($tool, $dir);
    check('a tie on from_class passes when to_class is in order', $code === 0, $out);

    // --- Case 5: malformed XML is still reported as malformed -------------
    // The order check reads the same parse, so it must not crash or mask the
    // well-formedness error when the parse failed.
    $dir = makeFixture($base, 'Malformed', []);
    file_put_contents("$dir/_data/class_extensions.xml", "<class_extensions><extension\n");
    [$code, $out] = runTool($tool, $dir);
    check('a malformed class_extensions.xml exits non-zero', $code !== 0, $out);
    check(
        'a malformed class_extensions.xml is reported as malformed',
        str_contains($out, 'malformed XML in _data/class_extensions.xml'),
        $out
    );
    check(
        'a malformed class_extensions.xml raises no order error',
        !str_contains($out, 'out of canonical order'),
        $out
    );

    // --- Case 6: version_id must agree with version_string ----------------
    // XenForo decides whether a board needs an addon's data by version_id
    // alone, and derives the displayed version_string from it (see
    // XF\Repository\AddOnRepository::inferVersionStringFromId). A version_id
    // that decodes to a different version than version_string claims makes the
    // board believe it is at a version nobody released.
    //
    // This is RosterSearch's committed shape: 1000200 decodes to 1.0.2 while
    // the addon calls itself 1.2.0 and shipped under tag RosterSearch-v1.2.0.
    // 1020070 is 1.2.0 in XenForo's own numbering — see XF's shipped upgrade
    // file 1020070-120.php.
    $dir = makeFixture($base, 'Transposed', [], 1000200, '1.2.0');
    [$code, $out] = runTool($tool, $dir);
    check('a version_id that disagrees with version_string is refused', $code !== 0, $out);
    check(
        'the failure names the version_id that version_string calls for',
        str_contains($out, '1020070'),
        $out
    );

    // The stable happy path, on SteamChecker's corrected value. 1010470 is
    // 1.1.4 in XenForo's numbering — see XF's shipped upgrade file
    // 1010470-114.php.
    $dir = makeFixture($base, 'Agreeing', [], 1010470, '1.1.4');
    [$code, $out] = runTool($tool, $dir);
    check('a version_id that agrees with version_string passes', $code === 0, $out);

    // One deliberate compatibility pin to a published XenForo release: a
    // pre-release version_id decodes to its release state rather than being
    // refused. Corroborated by XF's own shipped upgrade file
    // 1010031-110b1.php, so this pins XenForo's published numbering, not our
    // implementation of it.
    //
    // The other status codes are ported to mirror XenForo but are
    // intentionally uncovered. Do not expand this into a per-status matrix:
    // that would turn XenForo internals no addon in this suite uses into local
    // contracts.
    $dir = makeFixture($base, 'PreRelease', [], 1010031, '1.1.0 Beta 1');
    [$code, $out] = runTool($tool, $dir);
    check(
        'a pre-release version_id round-trips (compatibility pin: XF ships 1010031 as 1.1.0 Beta 1)',
        $code === 0,
        $out
    );

    // Core's committed shape before #218: an integer that is not a version at
    // all. It has to be refused rather than silently decoded. No remedy is
    // asserted here — XenForo's scheme cannot express a major of 0, so there
    // is no version_id that would satisfy '0.0.1' and naming one would be a
    // lie.
    $dir = makeFixture($base, 'Unscheme', [], 1, '0.0.1');
    [$code, $out] = runTool($tool, $dir);
    check('a version_id outside XenForo\'s scheme is refused', $code !== 0, $out);

    // An undecodable version_id still has to name the remedy when the declared
    // version_string is one XenForo can express. Same 1020070 = 1.2.0 as above.
    $dir = makeFixture($base, 'UnschemeWithRemedy', [], 12345, '1.2.0');
    [$code, $out] = runTool($tool, $dir);
    check(
        'an undecodable version_id names the version_id version_string calls for',
        str_contains($out, '1020070'),
        $out
    );

    // A hand-edited version_string with a component missing must fail as a
    // validation error, not as a PHP fatal. Exit 1 is the tool's clean-failure
    // contract (it uses 2 for usage); an unguarded parse would exit 255. No
    // assertion on the message: the remedy here is genuinely ambiguous, since
    // either field could be the wrong one.
    $dir = makeFixture($base, 'ShortString', [], 1020070, '1.2');
    [$code, $out] = runTool($tool, $dir);
    check('a version_string with a component missing fails cleanly', $code === 1, $out);
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
