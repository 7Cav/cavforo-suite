<?php

/**
 * validate-addon-test.php — pins the canonical class-extension order check in
 * tools/validate-addon.php (issue #152, ADR 0003), with no XenForo and no test
 * framework. Self-contained: builds throwaway fixture addon dirs in the system
 * temp dir, runs the real tool against them, asserts on its exit code and
 * output, then cleans up. Exits non-zero on any failure.
 *
 * The gap this guards: nothing in the repo checked the order of
 * _data/class_extensions.xml. check-data-consistency.php compares class
 * extensions as an unordered set, so a file whose rows had drifted from what an
 * export produces still validated, and the drift only surfaced as churn the
 * next time somebody re-exported. Two committed files had drifted that way.
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
 */
function makeFixture(string $base, string $name, ?array $rows): string
{
    $dir = "$base/$name";
    mkdir("$dir/_data", 0777, true);
    file_put_contents("$dir/addon.json", json_encode([
        'title' => $name,
        'version_id' => 1000010,
        'version_string' => '1.0.1',
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
    // The export orders rows in SQL over utf8mb4_general_ci columns, which is
    // case-INsensitive, so a folded lowercase letter can outrank a byte that
    // would win strcmp. 'XenAddons\' folds to 'XENADDONS\' and sorts ahead of
    // 'XF\' ('E' 0x45 < 'F' 0x46) though raw bytes put 'XF\' first
    // ('F' 0x46 < 'e' 0x65). This pair is what the database really emits;
    // a byte comparison would reject legitimate export output.
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
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
