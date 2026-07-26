<?php

/**
 * check-readme-catalog-test.php — pins tools/check-readme-catalog.php, the
 * check that the repo-root README's addon catalog lists exactly the addons that
 * ship (issue #175), with no XenForo and no test framework. Self-contained:
 * builds throwaway fixture repos in the system temp dir, runs the real tool
 * against them, asserts on its exit code and output, then cleans up. Exits
 * non-zero on any failure.
 *
 * The gap this guards: the catalog was maintained by hand with nothing behind
 * it, and four of the eight addons added after the initial scaffold shipped
 * without their row, drifting for as long as 36 days before #80 backfilled
 * them. The backfill fixed the state and not the cause.
 *
 * Run:
 *   php tools/tests/check-readme-catalog-test.php
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

$tool = dirname(__DIR__) . '/check-readme-catalog.php';

/**
 * Build a throwaway fixture repo under $base holding a README whose catalog
 * lists $catalogIds, and an addon root holding $dirs. Each entry in $dirs is a
 * [directory name, whether it carries an addon.json] pair. Ids are written into
 * the table the way the real README writes them: backticked and vendor-prefixed.
 */
function makeRepo(string $base, string $name, array $catalogIds, array $dirs): string
{
    $root = "$base/$name";

    $readme = "# fixture\n\n## What lives here\n\n| Addon | What it does |\n|---|---|\n";
    foreach ($catalogIds as $id) {
        $readme .= "| `Cav7/$id` | what $id does |\n";
    }
    $readme .= "\nProse after the table.\n";

    mkdir($root, 0777, true);
    file_put_contents("$root/README.md", $readme);

    foreach ($dirs as [$dir, $hasManifest]) {
        mkdir("$root/src/addons/Cav7/$dir", 0777, true);
        if ($hasManifest) {
            file_put_contents("$root/src/addons/Cav7/$dir/addon.json", json_encode([
                'title' => $dir,
                'version_id' => 1000010,
                'version_string' => '1.0.1',
            ], JSON_PRETTY_PRINT));
        }
    }

    return $root;
}

/** Run the real tool against a repo root; return [exitCode, combinedOutput]. */
function runTool(string $tool, string $repoRoot): array
{
    $cmd = 'php ' . escapeshellarg($tool) . ' ' . escapeshellarg($repoRoot) . ' 2>&1';
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

$base = sys_get_temp_dir() . '/cav7-check-readme-catalog-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);

try {
    // --- Case 1: a shipped addon with no catalog row is refused -----------
    // The drift that actually happened four times: the PR adds the addon and
    // leaves the table alone.
    $root = makeRepo($base, 'MissingRow', ['Core'], [
        ['Core', true],
        ['RosterPatch', true],
    ]);
    [$code, $out] = runTool($tool, $root);
    check('an addon with no catalog row exits non-zero', $code !== 0, $out);
    check('the failure names the addon missing from the table', str_contains($out, 'RosterPatch'), $out);
    check('the failure leaves the correctly-listed addon out of it', !str_contains($out, 'Core'), $out);

    // --- Case 2: a catalog row with no addon behind it is refused ---------
    // The other direction: an addon is removed and its row survives, so the
    // table advertises something nobody can install.
    $root = makeRepo($base, 'StrandedRow', ['Core', 'Departed'], [
        ['Core', true],
    ]);
    [$code, $out] = runTool($tool, $root);
    check('a catalog row with no addon behind it exits non-zero', $code !== 0, $out);
    check('the failure names the stranded row', str_contains($out, 'Departed'), $out);
    check('the stranded-row failure leaves the shipped addon out of it', !str_contains($out, 'Core'), $out);

    // --- Case 3: a directory that does not ship is not expected in the table
    // Manifest presence does not merely describe what ships, it decides it:
    // CI's discover job requires one to build a directory at all, so a
    // directory without an addon.json is never linted, validated or packaged
    // and cannot reach a board. Expecting a catalog row for one would invite a
    // row for an addon that does not exist.
    $root = makeRepo($base, 'UnshippedDir', ['Core'], [
        ['Core', true],
        ['Groundwork', false],
    ]);
    [$code, $out] = runTool($tool, $root);
    check('a directory with no addon.json needs no catalog row', $code === 0, $out);
    check('a directory with no addon.json is not named at all', !str_contains($out, 'Groundwork'), $out);

    // --- Case 4: the two sets matching is a pass --------------------------
    // Row order is not the table's contract, so a table listing the same ids in
    // a different order than the directories sort in still passes.
    $root = makeRepo($base, 'InStep', ['RosterPatch', 'Core', 'SteamChecker'], [
        ['Core', true],
        ['RosterPatch', true],
        ['SteamChecker', true],
    ]);
    [$code, $out] = runTool($tool, $root);
    check('a catalog matching the shipped addons passes', $code === 0, $out);

    // --- Case 5: losing the table is its own failure, not drift -----------
    // A reformat that keeps every addon but drops the table shape must not be
    // reported as sixteen addons going missing. That would be a false drift
    // report fired by an edit that changed no addon at all.
    $root = makeRepo($base, 'NoTable', [], [
        ['Core', true],
        ['RosterPatch', true],
    ]);
    file_put_contents(
        "$root/README.md",
        "# fixture\n\n## What lives here\n\n- `Cav7/Core` — what Core does\n- `Cav7/RosterPatch` — what RosterPatch does\n"
    );
    [$code, $out] = runTool($tool, $root);
    check('a README with no catalog table exits non-zero', $code !== 0, $out);
    check(
        'an unreadable catalog is not reported as a missing row',
        !str_contains($out, 'has no catalog row'),
        $out
    );

    // --- Case 6: a second table in the README is not part of the catalog --
    // The catalog ends where its rows end. A later table is somebody else's,
    // and reading its rows as addon ids would report stranded rows for addons
    // nobody ever claimed shipped.
    $root = makeRepo($base, 'SecondTable', ['Core'], [
        ['Core', true],
    ]);
    file_put_contents(
        "$root/README.md",
        file_get_contents("$root/README.md")
        . "\n## Supported versions\n\n| XenForo | Status |\n|---|---|\n| 2.3 | supported |\n| 2.2 | untested |\n"
    );
    [$code, $out] = runTool($tool, $root);
    check('a second table elsewhere in the README is not read as catalog rows', $code === 0, $out);

    // --- Case 7: both directions at once are both reported ----------------
    // Reporting only the first side found would send a contributor round the
    // loop twice for one edit.
    $root = makeRepo($base, 'BothWays', ['Core', 'Departed'], [
        ['Core', true],
        ['Arrived', true],
    ]);
    [$code, $out] = runTool($tool, $root);
    check('both directions failing at once exits non-zero', $code !== 0, $out);
    check('both directions failing at once names the unlisted addon', str_contains($out, 'Arrived'), $out);
    check('both directions failing at once names the stranded row', str_contains($out, 'Departed'), $out);

    // --- Case 8: a name CI cannot build is neither shipped nor ignored ----
    // discover builds its matrix from a manifest AND a name of [A-Za-z0-9_],
    // so a manifest under a name outside that set is never linted, validated
    // or packaged. Demanding a catalog row for it would document an addon no
    // board can install; ignoring it would bury a directory that silently
    // gets no CI at all. It is its own failure.
    $root = makeRepo($base, 'UnsafeName', ['Core'], [
        ['Core', true],
        ['Not-Buildable', true],
    ]);
    [$code, $out] = runTool($tool, $root);
    check('a manifest under a name CI cannot build exits non-zero', $code !== 0, $out);
    check('the failure names the unbuildable directory', str_contains($out, 'Not-Buildable'), $out);
    check(
        'an unbuildable directory is not reported as a missing catalog row',
        !str_contains($out, 'has no catalog row'),
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
