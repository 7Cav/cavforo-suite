<?php

/**
 * Pins the one job build.json has here: dropping the staged tests/ directory —
 * the NF/Rosters markup the fixtures quote — out of what
 * xf-addon:build-release zips. The README says neither build path ships it, and
 * this is the only thing holding up the half of that claim about XenForo's own
 * builder.
 *
 * It needs pinning because the guard cannot report its own failure. XF's
 * ReleaseBuilderService::execCmds() runs each entry through passthru() and
 * throws the exit status away, and `rm -rf` on a path that is not there exits 0,
 * so a renamed add-on directory or a changed staging layout would leave the
 * fixtures in the zip without a word. Nothing else covers it: the no-XenForo
 * path (tools/package-addon.sh) has its own tests/ exclusion and never reads
 * exec at all.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/BuildJsonWiringTest.php
 */

namespace Cav7\RosterPatch\Tests;

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

$root = dirname(__DIR__);

// XenForo takes the add-on id from where the add-on sits, not from addon.json,
// so this test does too: move or rename the directory and the id below moves
// with it, and a build.json still naming the old path fails here.
$addOnId = basename(dirname($root)) . '/' . basename($root);

$buildJson = json_decode((string) @file_get_contents("$root/build.json"), true);
check('build.json is valid JSON', is_array($buildJson), json_last_error_msg());

$exec = $buildJson['exec'] ?? null;
check(
    'build.json declares an exec command',
    is_array($exec) && $exec !== [],
    'without one, xf-addon:build-release ships tests/ and its vendor fixtures'
);

/**
 * One exec entry as XenForo would run it. execCmds() chdir()s to the add-on
 * directory and expands {placeholder} tokens from the AddOn's own properties
 * through escapeshellarg() before handing the command to passthru(), so a
 * relative path is relative to the add-on root and {addon_id} arrives quoted.
 */
$asXenForoRunsIt = static function (string $cmd) use ($addOnId): string {
    $expanded = (string) preg_replace_callback(
        '/\{([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\}/',
        static fn (array $m) => $m[1] === 'addon_id' ? escapeshellarg($addOnId) : $m[0],
        $cmd
    );

    // The shell strips the quoting escapeshellarg() adds before rm sees a path.
    return str_replace(["'", '"'], '', $expanded);
};

$commands = array_map($asXenForoRunsIt, is_array($exec) ? $exec : []);
$wanted = "rm -rf _build/upload/src/addons/$addOnId/tests";

check(
    "an exec command deletes this add-on's own staged tests directory",
    in_array($wanted, $commands, true),
    'expected: ' . $wanted . ' — got: ' . (implode(' | ', $commands) ?: 'nothing')
);

// The guard is silent about a target that is not there, so say here that there
// is one, and that it holds the vendor markup the README promises stays out.
check(
    'there is a tests/ directory for it to delete',
    is_dir("$root/tests")
);
check(
    'the vendor fixtures it keeps out of the zip live under it',
    is_dir("$root/tests/fixtures")
        && glob("$root/tests/fixtures/*.html") !== [],
    'tests/fixtures/ is the NF/Rosters markup that must not reach an installed board'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
