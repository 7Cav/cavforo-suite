<?php

/**
 * package-addon-test.php — pins what tools/package-addon.sh keeps out of a
 * release zip, for every addon at once. Self-contained: runs the real script
 * against every committed addon, reads each archive in-process, asserts on its
 * entries, then cleans up. Exits non-zero on any failure.
 *
 * The gap this guards: package-addon.sh's excludes array was the only thing
 * keeping dev-only files out of a released zip, and nothing asserted it.
 * Deleting one word from it put test files into eleven addons' zips with lint,
 * both static checks, all sixteen addon suites and the tools suite green. The
 * release workflow builds with this script, so that zip is what reaches a
 * board — and XenForo's only protection for src/ is an Apache-syntax
 * .htaccess that nginx ignores, which makes a shipped tests/*.php an
 * unauthenticated, executable endpoint.
 *
 * The dev-only list below is deliberately a second copy of what the script
 * excludes, not a read of it. A test that derived its expectations from the
 * excludes array would shrink with it and pass forever.
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

$repoRoot = dirname(__DIR__, 2);
$script = "$repoRoot/tools/package-addon.sh";

/**
 * Package one addon with the real script and return the zip's entry names that
 * fall inside the addon's own directory, relative to it. Entries elsewhere in
 * upload/ are the web root that _files/ assets are relocated to, which
 * package-web-assets-test.php covers; modelling that here twice would just give
 * the two models room to drift.
 */
function packageAddon(string $script, string $addonId, string $out): array
{
    $cmd = escapeshellarg($script)
        . ' ' . escapeshellarg($addonId)
        . ' --out ' . escapeshellarg($out)
        . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) {
        throw new \RuntimeException("package-addon.sh failed for $addonId: " . implode("\n", $output));
    }

    $zip = new \ZipArchive();
    if ($zip->open($out) !== true) {
        throw new \RuntimeException("could not open the zip built for $addonId");
    }

    $prefix = "upload/src/addons/Cav7/$addonId/";
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_starts_with($name, $prefix)) {
            $entries[] = substr($name, strlen($prefix));
        }
    }
    $zip->close();

    return $entries;
}

/**
 * The zip entries at or below $path inside the addon's own directory. Zip
 * directory entries carry a trailing slash, so a directory shows up both as
 * itself and as everything under it.
 */
function shippedUnder(array $entries, string $path): array
{
    return array_values(array_filter($entries, fn (string $e): bool =>
        $e === $path || $e === "$path/" || str_starts_with($e, "$path/")));
}

/**
 * Dev-only paths an addon carries that must never reach a board. Named here
 * rather than read out of package-addon.sh's excludes array: the test is the
 * specification, the array is one implementation of it, and the duplication is
 * what lets the two disagree.
 *
 * tests/ is the load-bearing member. A shipped tests/*.php is reachable and
 * executable over HTTP on an nginx-served board, because XenForo's only guard
 * for src/ is an Apache-syntax .htaccess. docs/ and CONTEXT.md are inert by
 * comparison and ride along because it is the same exclusion either way.
 */
const DEV_ONLY = ['tests', 'docs', 'CONTEXT.md'];

/**
 * All six directories XenForo itself keeps out of a build are underscore-
 * prefixed, so the prefix reads as "not addon code" to anyone working here.
 * The rule below holds that reading true: a prefixed top-level entry does not
 * ship, and the one exception is named. That is a rule rather than a list of
 * known names, so a new _scratch/ fails until somebody excludes it, and a new
 * shipping directory is expected to be named without the prefix.
 *
 * _data is the exception because it is the tree that ships — see the glossary.
 */
const SHIPPED_PREFIXED = '_data';

/** The distinct first path segments of a set of addon-relative zip entries. */
function topLevelNames(array $entries): array
{
    $names = [];
    foreach ($entries as $entry) {
        $names[explode('/', $entry, 2)[0]] = true;
    }
    return array_keys($names);
}

$addonIds = [];
foreach (glob("$repoRoot/src/addons/Cav7/*", GLOB_ONLYDIR) as $dir) {
    if (is_file("$dir/addon.json")) {
        $addonIds[] = basename($dir);
    }
}
sort($addonIds);

check('there are addons to package', $addonIds !== [], "none found under src/addons/Cav7");

$tmp = sys_get_temp_dir() . '/package-addon-test-' . getmypid();
mkdir($tmp, 0777, true);

foreach ($addonIds as $addonId) {
    try {
        $entries = packageAddon($script, $addonId, "$tmp/$addonId.zip");
    } catch (\RuntimeException $e) {
        check("$addonId packages", false, $e->getMessage());
        continue;
    }

    foreach (DEV_ONLY as $path) {
        $shipped = shippedUnder($entries, $path);
        check(
            "$addonId: the release zip carries no $path",
            $shipped === [],
            implode(', ', array_slice($shipped, 0, 4))
        );
    }

    $prefixed = array_values(array_diff(topLevelNames($entries), [SHIPPED_PREFIXED]));
    $prefixed = array_values(array_filter(
        $prefixed,
        fn (string $n): bool => str_starts_with($n, '.') || str_starts_with($n, '_')
    ));

    check(
        "$addonId: the release zip carries no dot- or underscore-prefixed directory but " . SHIPPED_PREFIXED,
        $prefixed === [],
        implode(', ', $prefixed)
    );

    // The other half of the exception. Nothing else reads a zip, so without
    // this an addon could be excluded into shipping no XenForo data at all.
    if (is_dir("$repoRoot/src/addons/Cav7/$addonId/" . SHIPPED_PREFIXED)) {
        check(
            "$addonId: the release zip carries its " . SHIPPED_PREFIXED,
            shippedUnder($entries, SHIPPED_PREFIXED) !== []
        );
    }
}

foreach (glob("$tmp/*.zip") as $zip) {
    unlink($zip);
}
rmdir($tmp);

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s)\n";
    exit(1);
}
echo "All package-addon checks passed.\n";
exit(0);
