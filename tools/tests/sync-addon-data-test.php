<?php

/**
 * sync-addon-data-test.php — pins tools/sync-addon-data.php (issue #164), with no
 * XenForo and no test framework. Self-contained: copies committed add-ons into
 * the system temp dir, derives one tree from the other with the real tool, and
 * asserts on the bytes that come back. Exits non-zero on any failure.
 *
 * The oracle is the repo itself: the committed trees came out of XenForo's own
 * exporters, so asserting that the tool reproduces them byte for byte compares
 * against an independent source of truth rather than against a second copy of
 * the tool's own encoder. That is the whole claim the tool makes, and nothing
 * weaker would catch a pretty-printer that drifts from XenForo's.
 *
 * One qualification, because it matters for how much these cases prove.
 * EnlistmentDefaults' options, phrases and template_modifications bundles were
 * not purely exporter output when this test was written: commit 148f0a4 appended
 * two options — and their phrases and modifications — to the end of each file
 * instead of into the order the exporter emits. Those three files were
 * reconciled by running this tool, so for them the oracle is the rule (XenForo's
 * ORDER BY, and the column types behind it) rather than untouched exporter
 * output. Every other file in every other add-on is untouched, and the rule was
 * settled against those before it was applied here.
 *
 * Case 1 is the acceptance criterion: every committed add-on, both directions.
 * It covers all three record shapes at once, because the add-ons between them
 * hold every type this repo commits — pretty-printed JSON records, phrases as
 * .txt beside a _metadata.json, and templates as raw markup under a style-type
 * subfolder — plus the two generated hint files.
 *
 * Case 2 is the workflow the tool exists for: change one tree, derive the other,
 * and have the pair agree again.
 *
 * Case 3 pins the lossy edge. XenForo's _data export omits any attribute whose
 * value is the empty string, so a field that is legitimately '' survives only if
 * the reader treats an absent attribute as '' rather than as missing.
 *
 * Assertions are on file bytes and exit codes only. No test here asserts on a
 * message the tool prints: rewording a diagnostic is not a behaviour change.
 *
 * Run:
 *   php tools/tests/sync-addon-data-test.php
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

$toolsDir = dirname(__DIR__);
$repoRoot = dirname($toolsDir);
$tool = $toolsDir . '/sync-addon-data.php';
$consistencyTool = $toolsDir . '/check-data-consistency.php';
$addonsRoot = $repoRoot . '/src/addons/Cav7';

/**
 * Every file under $dir as relative path => contents. Returns [] when absent.
 */
function treeOf(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($dir) + 1)] = file_get_contents($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

function copyTree(string $src, string $dst): void
{
    mkdir($dst, 0777, true);
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($walk as $file) {
        $target = $dst . '/' . substr($file->getPathname(), strlen($src) + 1);
        $file->isDir() ? mkdir($target, 0777, true) : copy($file->getPathname(), $target);
    }
}

/**
 * Describe the first difference between two trees, for a failure message.
 */
function firstDiff(array $expected, array $actual): string
{
    foreach ($expected as $rel => $contents) {
        if (!array_key_exists($rel, $actual)) {
            return "missing $rel";
        }
        if ($actual[$rel] !== $contents) {
            return "$rel differs (expected " . strlen($contents) . " bytes, got " . strlen($actual[$rel]) . ")";
        }
    }
    foreach ($actual as $rel => $contents) {
        if (!array_key_exists($rel, $expected)) {
            return "unexpected $rel";
        }
    }

    return '';
}

function run(string $script, string ...$args): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    exec($cmd . ' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

$tmpBase = sys_get_temp_dir() . '/cav7-sync-addon-data-' . getmypid();
rmTree($tmpBase);
mkdir($tmpBase, 0777, true);

function scratchCopy(string $addonId, string $label): string
{
    global $addonsRoot, $tmpBase;

    $scratch = $tmpBase . '/' . $label;
    rmTree($scratch);
    copyTree($addonsRoot . '/' . $addonId, $scratch);

    return $scratch;
}

// ---------------------------------------------------------------------------
// Case 1: every committed add-on reproduces byte for byte, in both directions.
// ---------------------------------------------------------------------------

$addonIds = [];
foreach (glob($addonsRoot . '/*', GLOB_ONLYDIR) as $dir) {
    if (is_dir($dir . '/_data')) {
        $addonIds[] = basename($dir);
    }
}

check('case 1: found add-ons with a committed _data bundle to check', $addonIds !== [], 'none found');

foreach ($addonIds as $addonId) {
    foreach (['--to-output' => '_output', '--to-data' => '_data'] as $direction => $tree) {
        $scratch = scratchCopy($addonId, 'case1');
        rmTree($scratch . '/' . $tree);

        [$exit, $out] = run($tool, $scratch, $direction);
        $got = treeOf($scratch . '/' . $tree);
        $want = treeOf($addonsRoot . '/' . $addonId . '/' . $tree);

        check("case 1: $addonId $direction exits 0", $exit === 0, "exit $exit: $out");
        check("case 1: $addonId $direction reproduces the committed $tree", $got === $want, firstDiff($want, $got));
    }
}

// ---------------------------------------------------------------------------
// Case 2: a data-only change in one tree is carried into the other, and the
// consistency check agrees the pair is in step afterwards.
// ---------------------------------------------------------------------------

$scratch = scratchCopy('RosterSearch', 'case2');
$modPath = $scratch . '/_data/template_modifications.xml';
$marker = 'CAV7-SYNC-TEST-MARKER';

$data = file_get_contents($modPath);
$edited = str_replace('<xf:pageaction', '<xf:pageaction data-test="' . $marker . '"', $data, $replacements);

check('case 2: the fixture edit changed the committed _data', $replacements > 0, 'nothing replaced');
file_put_contents($modPath, $edited);

[$exit, $out] = run($tool, $scratch, '--to-output');
check('case 2: tool exits 0 after a _data edit', $exit === 0, "exit $exit: $out");

$outputRecords = glob($scratch . '/_output/template_modifications/*/*.json');
$carried = false;
foreach ($outputRecords as $record) {
    if (strpos(file_get_contents($record), $marker) !== false) {
        $carried = true;
    }
}
check('case 2: the edited find string reaches the _output record', $carried);

[$exit, $out] = run($consistencyTool, $scratch);
check('case 2: check-data-consistency accepts the derived pair', $exit === 0, "exit $exit: $out");

// The metadata index has to move with the record it indexes, or the next
// import would compare a stale hash.
$metadata = json_decode(file_get_contents($scratch . '/_output/template_modifications/_metadata.json'), true);
$hashesAgree = true;
foreach ($metadata as $relativePath => $entry) {
    $contents = file_get_contents($scratch . '/_output/template_modifications/' . $relativePath);
    if ($entry['hash'] !== md5(str_replace("\r", '', $contents))) {
        $hashesAgree = false;
    }
}
check('case 2: _metadata.json hashes match the records they index', $hashesAgree);

// ---------------------------------------------------------------------------
// Case 2b: removing a record removes its _output file too.
//
// An export is a rewrite of the whole tree, not an overlay on the last one. A
// writer that only ever writes leaves the deleted record's file behind, and
// _output then claims a record _data no longer has — the drift this tool is
// supposed to close.
// ---------------------------------------------------------------------------

$scratch = scratchCopy('RosterSearch', 'case2b');
$routesPath = $scratch . '/_data/routes.xml';

$routesBefore = count(glob($scratch . '/_output/routes/*.json')) - 1; // less _metadata.json
$routes = file_get_contents($routesPath);
$trimmed = preg_replace('#\n  <route [^>]*/>#', '', $routes, 1, $removed);

check('case 2b: the fixture removed a record from _data', $removed === 1, 'nothing removed');
file_put_contents($routesPath, $trimmed);

[$exit, $out] = run($tool, $scratch, '--to-output');
check('case 2b: tool exits 0 after a record is removed', $exit === 0, "exit $exit: $out");

$routesAfter = count(glob($scratch . '/_output/routes/*.json')) - 1;
check(
    'case 2b: the removed record no longer has an _output file',
    $routesAfter === $routesBefore - 1,
    "expected " . ($routesBefore - 1) . " records, found $routesAfter"
);

[$exit, $out] = run($consistencyTool, $scratch);
check('case 2b: check-data-consistency accepts the pair after a removal', $exit === 0, "exit $exit: $out");

// Emptying a type takes its directory with it, and a hint file goes when the
// last record it described does.
$scratch = scratchCopy('DotTokenFix', 'case2c');
file_put_contents(
    $scratch . '/_data/class_extensions.xml',
    "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<class_extensions/>\n"
);

[$exit, $out] = run($tool, $scratch, '--to-output');
check('case 2c: tool exits 0 when a type is emptied', $exit === 0, "exit $exit: $out");
check(
    'case 2c: the emptied type leaves no _output directory behind',
    !is_dir($scratch . '/_output/class_extensions')
);
check(
    'case 2c: extension_hint.php goes with the last class extension',
    !file_exists($scratch . '/_output/extension_hint.php')
);

// ---------------------------------------------------------------------------
// Case 2d: an add-on that commits no XenForo data at all is refused, rather
// than being handed a tree of empty containers it never had.
// ---------------------------------------------------------------------------

$bare = $tmpBase . '/case2d';
mkdir($bare, 0777, true);
file_put_contents($bare . '/addon.json', "{}\n");

[$exit, $out] = run($tool, $bare, '--to-data');
check('case 2d: deriving _data with no _output tree is refused', $exit !== 0);
check('case 2d: nothing was written', !is_dir($bare . '/_data'), 'a _data directory appeared');

[$exit, $out] = run($tool, $bare, '--to-output');
check('case 2d: deriving _output with no _data bundle is refused', $exit !== 0);
check('case 2d: still nothing was written', !is_dir($bare . '/_output'), 'an _output directory appeared');

// ---------------------------------------------------------------------------
// Case 2e: a tie in XenForo's ORDER BY keeps the order already committed.
//
// ApiKeyManager registers two code event listeners that agree on event_id,
// callback_class and callback_method — every key the exporter sorts on — and
// differ only in hint. The database returned them in insertion order and nothing
// in _output records that, so the committed _data file is the only evidence of
// it. Deriving must not reorder them, and must not depend on the order the
// filesystem happened to list _output in, which differs between machines.
// ---------------------------------------------------------------------------

$scratch = scratchCopy('ApiKeyManager', 'case2e');
$listenersPath = $scratch . '/_data/code_event_listeners.xml';

preg_match_all('#hint="([^"]*)"#', file_get_contents($listenersPath), $matches);
$hintsBefore = $matches[1];

check('case 2e: the fixture ties on every sort key', count($hintsBefore) === 2, 'expected 2 listeners');

[$exit, $out] = run($tool, $scratch, '--to-data');
check('case 2e: tool exits 0 on the tied fixture', $exit === 0, "exit $exit: $out");

preg_match_all('#hint="([^"]*)"#', file_get_contents($listenersPath), $matches);
check(
    'case 2e: the tied records keep the committed order',
    $matches[1] === $hintsBefore,
    'got ' . implode(', ', $matches[1]) . ' — expected ' . implode(', ', $hintsBefore)
);

// Deriving twice must not drift either.
[$exit, $out] = run($tool, $scratch, '--to-data');
preg_match_all('#hint="([^"]*)"#', file_get_contents($listenersPath), $matches);
check('case 2e: a second derivation is stable', $matches[1] === $hintsBefore);

// ---------------------------------------------------------------------------
// Case 3: a field whose value is the empty string survives the round trip.
//
// RosterAudit's admin navigation entry carries icon: "". XenForo's _data export
// omits an empty attribute entirely, so this value exists in _output and has no
// representation at all in _data.
// ---------------------------------------------------------------------------

$scratch = scratchCopy('RosterAudit', 'case3');
$navRecord = $scratch . '/_output/admin_navigation/cav7RosterAudit.json';

$before = json_decode(file_get_contents($navRecord), true);
check('case 3: the fixture record holds an empty-string field', ($before['icon'] ?? null) === '');

[$exit, $out] = run($tool, $scratch, '--to-data');
check('case 3: tool exits 0 deriving _data', $exit === 0, "exit $exit: $out");

$dataXml = file_get_contents($scratch . '/_data/admin_navigation.xml');
check('case 3: _data omits the empty attribute entirely', strpos($dataXml, 'icon=') === false);

rmTree($scratch . '/_output');
[$exit, $out] = run($tool, $scratch, '--to-output');
check('case 3: tool exits 0 deriving _output back', $exit === 0, "exit $exit: $out");

$after = json_decode(file_get_contents($navRecord), true);
check('case 3: the empty-string field comes back as an empty string', ($after['icon'] ?? null) === '');
check('case 3: the round trip changes nothing else in the record', $after === $before);

// ---------------------------------------------------------------------------
// Case 4: class extensions order case-folded, not by bytes.
//
// No committed add-on can tell the two rules apart. ADR 0004 says why: every
// Cav7 add-on extends only XF, NF or XFES, all uppercase, so a byte comparison
// agrees with the collation on our data by luck. Deriving _data with the wrong
// rule therefore reproduces every committed bundle and still emits the wrong
// order the first time an add-on extends a mixed-case namespace.
//
// The oracle is ADR 0004, which measured all 587 xf_class_extension rows on a
// dev stack: the columns are varchar under utf8mb4_general_ci, which folds case,
// so XenAddons\ (folding to XENADDONS\) sorts ahead of XF\. Raw bytes put XF\
// first, because 'F' (0x46) beats 'e' (0x65).
// ---------------------------------------------------------------------------

$scratch = $tmpBase . '/case4';
mkdir($scratch . '/_output/class_extensions', 0777, true);

$fixture = [
    'XF-Bar_Cav7-Fixture-Bar.json' => [
        'from_class' => 'XF\\Bar',
        'to_class' => 'Cav7\\Fixture\\Bar',
        'execute_order' => 10,
        'active' => true,
    ],
    'XenAddons-Foo_Cav7-Fixture-Foo.json' => [
        'from_class' => 'XenAddons\\Foo',
        'to_class' => 'Cav7\\Fixture\\Foo',
        'execute_order' => 10,
        'active' => true,
    ],
];

$fixtureMetadata = [];
foreach ($fixture as $fileName => $record) {
    $contents = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    file_put_contents($scratch . '/_output/class_extensions/' . $fileName, $contents);
    $fixtureMetadata[$fileName] = ['hash' => md5($contents)];
}
file_put_contents(
    $scratch . '/_output/class_extensions/_metadata.json',
    json_encode($fixtureMetadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

[$exit, $out] = run($tool, $scratch, '--to-data');
check('case 4: tool exits 0 on the mixed-case fixture', $exit === 0, "exit $exit: $out");

$derived = file_get_contents($scratch . '/_data/class_extensions.xml');
$xenAddonsAt = strpos($derived, 'XenAddons\\Foo');
$xfAt = strpos($derived, 'XF\\Bar');

check(
    'case 4: XenAddons\\ sorts ahead of XF\\, as the collation orders it',
    $xenAddonsAt !== false && $xfAt !== false && $xenAddonsAt < $xfAt,
    'a byte comparison would put XF\\ first'
);

// ---------------------------------------------------------------------------

rmTree($tmpBase);

if ($failures > 0) {
    echo "\n$failures check(s) failed.\n";
    exit(1);
}

echo "\nAll sync-addon-data checks passed.\n";
exit(0);
