<?php

/**
 * package-addon-test.php — pins the one thing tools/package-addon.sh must never
 * stop doing: keeping tests/ out of the release zip. That zip is what CI builds
 * and what the release workflow uploads, and tests/ is where an add-on is
 * allowed to quote vendor markup verbatim (RosterPatch's tests/fixtures/ holds
 * NF/Rosters template lines), so a zip carrying it redistributes that markup to
 * every board that installs from the archive.
 *
 * The exclusion is one word in a plain bash array. Delete it and the whole PHP
 * suite still passes, because nothing in it reads the packaging script: the
 * add-on-local BuildJsonWiringTest covers the other build path,
 * xf-addon:build-release, which needs XenForo and so never runs in CI at all.
 *
 * Self-contained: runs the real script over every committed add-on into a
 * throwaway output directory, reads back the zip's entry list, and cleans up.
 * Exits non-zero on any failure.
 *
 * Run:
 *   php tools/tests/package-addon-test.php
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

/** Run a command from the repo root; return [exitCode, combinedOutput lines]. */
function run(string $repoRoot, string $cmd): array
{
    $out = [];
    $code = 0;
    exec('cd ' . escapeshellarg($repoRoot) . ' && ' . $cmd . ' 2>&1', $out, $code);

    return [$code, $out];
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

$repoRoot = dirname(__DIR__, 2);
$script = escapeshellarg("$repoRoot/tools/package-addon.sh");

// The script packages a git ref, not the working tree, so what counts as
// "this add-on ships tests/" is what the ref carries. Reading the same ref for
// both sides keeps a locally uncommitted tests/ from turning the check vacuous
// (or, the other way, from failing a zip that never could have held it).
[$code, $tracked] = run($repoRoot, 'git ls-tree -r --name-only HEAD -- src/addons/Cav7');
check('the add-on tree could be listed at HEAD', $code === 0, implode("\n", $tracked));

$addOns = [];
foreach ($tracked as $path) {
    if (preg_match('#^src/addons/Cav7/([^/]+)/#', $path, $m) !== 1) {
        continue;
    }
    $addOns[$m[1]] ??= false;
    if (str_starts_with($path, "src/addons/Cav7/{$m[1]}/tests/")) {
        $addOns[$m[1]] = true;
    }
}
ksort($addOns);

check('HEAD carries at least one add-on to package', $addOns !== []);
check(
    'at least one add-on commits a tests/ directory',
    in_array(true, $addOns, true),
    'with none, "the zip carries no tests/" holds for every add-on by accident and pins nothing'
);

$base = sys_get_temp_dir() . '/cav7-package-addon-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);

try {
    foreach ($addOns as $addOn => $hasTests) {
        $zip = "$base/$addOn.zip";
        [$code, $out] = run($repoRoot, "$script " . escapeshellarg($addOn) . ' --out ' . escapeshellarg($zip));
        check("$addOn: package-addon.sh exits 0", $code === 0, "exit=$code\n" . implode("\n", $out));
        if ($code !== 0 || !is_file($zip)) {
            check("$addOn: a zip was written", is_file($zip), implode("\n", $out));
            continue;
        }

        [$code, $entries] = run($repoRoot, 'unzip -Z1 ' . escapeshellarg($zip));
        check("$addOn: the zip's entry list could be read", $code === 0, implode("\n", $entries));

        // A build that produced an empty (or wrong-layout) zip would satisfy the
        // tests/ check by carrying nothing at all, so pin that the add-on is
        // actually in there first.
        check(
            "$addOn: the zip stages the add-on at upload/src/addons/Cav7/$addOn/",
            in_array("upload/src/addons/Cav7/$addOn/addon.json", $entries, true),
            'entries: ' . (implode(', ', array_slice($entries, 0, 20)) ?: 'none')
        );

        $testEntries = array_values(array_filter(
            $entries,
            static fn (string $entry) => preg_match('#(^|/)tests/#', $entry) === 1
        ));
        check(
            "$addOn: the zip carries no tests/ entry"
                . ($hasTests ? '' : ' (this add-on commits none)'),
            $testEntries === [],
            'shipped: ' . implode(', ', $testEntries)
                . ' — tests/ is where vendor markup may be quoted verbatim; it must not reach a board'
        );
    }
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
