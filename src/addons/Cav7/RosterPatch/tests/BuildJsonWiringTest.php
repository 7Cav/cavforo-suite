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
 * so a build.json that names the wrong path leaves the fixtures in the zip
 * without a word. Nothing else covers it: the no-XenForo path
 * (tools/package-addon.sh) has its own tests/ exclusion and never reads exec at
 * all.
 *
 * What that comes down to here: the exec list holds the delete, spelled exactly,
 * with {addon_id} written bare rather than inside quotes of build.json's own,
 * and no later entry restages the directory in a spelling this can see. That
 * delete is compared against one canonical spelling, literally, so any quoting
 * of the token fails here — including the quoting a shell would have forgiven.
 * Double quotes break the prune, not the build: execCmds() expands the token
 * through escapeshellarg(), so rm goes looking for a directory whose name
 * carries apostrophes, finds none, and exits 0 — after which the build carries
 * on and zips the fixtures. Single quotes collapse back to the right path, and
 * that spelling is rejected here anyway.
 *
 * Two things it holds less tightly than it looks. The add-on's own id is read
 * from where the add-on sits, so a rename is covered; the
 * `_build/upload/src/addons/` prefix is not — it is a literal here compared
 * against the literal in build.json, and a XenForo release that restaged
 * elsewhere would pass both. And the restager scan below is a substring test for
 * that same staged path, so it sees every entry that spells the path flat. It
 * does not see one that reaches the directory another way: `cd
 * _build/upload/src/addons && cp -R ../../../../tests {addon_id}/` restages the
 * fixtures and mentions the staged path nowhere. Both are the sort of entry
 * nobody has needed yet; if one arrives, this is the check that has to grow.
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

$rawExec = is_array($exec) ? $exec : [];

// passthru() takes a string, so a non-string entry is a TypeError inside the
// build rather than a command. Named here, because an uncaught one below would
// take this whole file down with no FAIL line and no remaining checks.
$notStrings = array_keys(array_filter($rawExec, static fn ($entry) => !is_string($entry)));
check(
    'every exec entry is a string',
    $notStrings === [],
    'entries at ' . (implode(', ', $notStrings) ?: 'none') . ' are not strings'
);

// execCmds() chdir()s to the add-on directory and expands {placeholder} tokens
// from the AddOn's own properties before handing the command to passthru(), so a
// relative path is relative to the add-on root. Only the token is substituted
// here, not the escapeshellarg() quoting execCmds() puts around the expansion,
// so what the comparison below holds is one canonical spelling: an entry that
// quotes the token itself does not match it, whether or not a shell would have
// forgiven the quotes. "{addon_id}" it would not — the expansion is already
// quoted, and rm goes looking for a path whose name carries apostrophes.
// '{addon_id}' it would, and that one is rejected here regardless: one spelling
// compared literally is cheaper to hold than a rule about which quoting survives
// which shell.
$commands = array_map(
    static fn (string $entry) => str_replace('{addon_id}', $addOnId, $entry),
    array_values(array_filter($rawExec, 'is_string'))
);

$stagedRoot = "_build/upload/src/addons/$addOnId";
$staged = "$stagedRoot/tests";
$wanted = "rm -rf $staged";
$deleteAt = array_search($wanted, $commands, true);

check(
    "an exec command deletes this add-on's own staged tests directory, unquoted",
    $deleteAt !== false,
    'expected: ' . $wanted . ' — got: ' . (implode(' | ', $commands) ?: 'nothing')
);

// exec is an ordered list run top to bottom, so a later entry can stage the
// directory straight back — a `cp -R tests <staged>` after the delete ships the
// fixtures again, and the delete above still reads as present. The staged add-on
// root is what gets matched, not the staged tests path: `cp -R tests` naming the
// parent directory puts tests/ back just as surely, and mentions the tests path
// nowhere.
//
// A later `rm -rf` under the staged root is exempt. It takes things out of the
// staged tree rather than putting them back, and a second prune — `rm -rf
// _build/upload/src/addons/{addon_id}/_data`, say — is a plausible edit that has
// nothing to do with what this check is for. Reported, it would name the wrong
// thing: the entry is not a restager, and the fixtures are still out of the zip.
$afterDelete = $deleteAt === false ? [] : array_slice($commands, (int) $deleteAt + 1);
$restagers = array_values(array_filter(
    $afterDelete,
    static fn (string $cmd) => str_contains($cmd, $stagedRoot)
        && !str_starts_with(ltrim($cmd), 'rm -rf ')
));
check(
    "no later exec command writes into this add-on's staged directory again",
    $restagers === [],
    'after the delete: ' . implode(' | ', $restagers)
);

// The guard is silent about a target that is not there, so say here that there
// is one, and that it holds the vendor markup the README promises stays out.
check(
    'there is a tests/ directory for it to delete',
    is_dir("$root/tests")
);
// glob() returns false on error rather than an empty array, so "not empty" alone
// would pass on the one input it cannot read.
$fixtureFiles = glob("$root/tests/fixtures/*.html");
check(
    'the vendor fixtures it keeps out of the zip live under it',
    is_dir("$root/tests/fixtures")
        && is_array($fixtureFiles)
        && $fixtureFiles !== [],
    'tests/fixtures/ is the NF/Rosters markup that must not reach a board installed from a release zip'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
