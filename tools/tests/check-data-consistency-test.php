<?php

/**
 * check-data-consistency-test.php — pins the class_extensions content check in
 * tools/check-data-consistency.php (issues #57 and #150), with no XenForo and no test
 * framework. Self-contained: builds throwaway fixture addon dirs in the system
 * temp dir, runs the real tool against them, asserts on its exit code and
 * output, then cleans up. Exits non-zero on any failure.
 *
 * The gap this guards: the tool count-checks class_extensions but never read
 * inside an _output item, so a corrupted to_class / from_class, or a flipped
 * active, in an _output/class_extensions/*.json passed as long as the file count
 * was unchanged. RED proof (before the fix): the corrupted fixtures below make
 * the tool exit 0. After the fix they exit non-zero and name the offending item,
 * while a correct fixture still exits 0 and the _data string "1" compares equal
 * to the _output bool true.
 *
 * Cases 5 to 9 guard issue #150. The tool used to key its _data lookup on
 * from_class alone, so two extensions registered against one from_class (which
 * XenForo allows, and runs in sequence) collapsed to a single record and the
 * second _output item was compared against the wrong row. RED proof: case 5
 * exits 1 on valid data before the fix. Matching on the (from_class, to_class)
 * pair is what makes the rows distinct, and the one-sided cases pin that each
 * unmatched row is named on whichever side it went missing from.
 *
 * Run:
 *   php tools/tests/check-data-consistency-test.php
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

$tool = dirname(__DIR__) . '/check-data-consistency.php';

/**
 * Build a throwaway fixture addon under $base with a _data/class_extensions.xml
 * and matching _output/class_extensions/*.json items.
 *
 * $dataExts: list of ['from_class'=>..., 'to_class'=>..., 'active'=>'1'|'0'].
 * $outputItems: filename => decoded item array (from_class/to_class/active...).
 */
function makeFixture(string $base, string $name, array $dataExts, array $outputItems): string
{
    $dir = "$base/$name";
    mkdir("$dir/_data", 0777, true);
    mkdir("$dir/_output/class_extensions", 0777, true);

    $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<class_extensions>\n";
    foreach ($dataExts as $e) {
        $xml .= sprintf(
            "  <extension from_class=\"%s\" to_class=\"%s\" execute_order=\"%s\" active=\"%s\"/>\n",
            htmlspecialchars($e['from_class'], ENT_QUOTES),
            htmlspecialchars($e['to_class'], ENT_QUOTES),
            $e['execute_order'] ?? '10',
            $e['active']
        );
    }
    $xml .= "</class_extensions>\n";
    file_put_contents("$dir/_data/class_extensions.xml", $xml);

    foreach ($outputItems as $filename => $item) {
        file_put_contents(
            "$dir/_output/class_extensions/$filename",
            json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
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

$base = sys_get_temp_dir() . '/cav7-consistency-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);

// A realistic pair of extensions. _data holds active as the string "1"/"0";
// _output holds it as the JSON bool true/false. from_class carries backslashes.
$fromA = 'Test\\Vendor\\Entity\\Foo';
$toA = 'Cav7\\Fixture\\Vendor\\Entity\\Foo';
$fromB = 'Test\\Vendor\\Entity\\Bar';
$toB = 'Cav7\\Fixture\\Vendor\\Entity\\Bar';
$fileA = 'Test-Vendor-Entity-Foo_Cav7-Fixture-Vendor-Entity-Foo.json';
$fileB = 'Test-Vendor-Entity-Bar_Cav7-Fixture-Vendor-Entity-Bar.json';

try {
    // --- 1. correct fixture: content agrees, "1"<->true and "0"<->false --------
    $ok = makeFixture(
        $base,
        'correct',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '0'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10, 'active' => false],
        ]
    );
    [$code, $out] = runTool($tool, $ok);
    check('correct fixture passes (exit 0)', $code === 0, "exit=$code\n$out");
    check(
        'correct fixture is reported as content-checked (report distinguishes)',
        str_contains($out, 'content-checked'),
        $out
    );
    check(
        'normalisation: _data "1" equals _output true and _data "0" equals _output false',
        $code === 0,
        "exit=$code\n$out"
    );

    // --- 2. corrupted to_class: _output disagrees with _data -------------------
    $badTo = makeFixture(
        $base,
        'bad-to-class',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '1'],
        ],
        [
            // to_class hand-mangled: points at the wrong target class.
            $fileA => ['from_class' => $fromA, 'to_class' => 'Cav7\\Fixture\\WRONG\\Target', 'execute_order' => 10, 'active' => true],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $badTo);
    check('corrupted to_class fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check('corrupted to_class names the offending item', str_contains($out, $fileA), $out);

    // --- 3. flipped active: _data "1" vs _output false is a real mismatch -------
    $flipped = makeFixture(
        $base,
        'flipped-active',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '1'],
        ],
        [
            // active flipped to false while _data still says "1".
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => false],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $flipped);
    check('flipped active fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check('flipped active names the offending item', str_contains($out, $fileA), $out);

    // --- 4. corrupted from_class: _output item matches no _data <extension> -----
    $badFrom = makeFixture(
        $base,
        'bad-from-class',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '1'],
        ],
        [
            // from_class mangled: no matching _data record.
            $fileA => ['from_class' => 'Test\\Vendor\\Entity\\BOGUS', 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $badFrom);
    check('corrupted from_class fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check('corrupted from_class names the offending item', str_contains($out, $fileA), $out);

    // --- 5. two extensions on one from_class, both matching (issue #150) --------
    // XenForo lets an addon register several extensions against the same
    // from_class and runs them in sequence; the row identity is the
    // (from_class, to_class) pair, which is also what the _output filename is
    // built from. Both rows here agree across the two sides, so the check passes.
    $dupFrom = 'Test\\Vendor\\Controller\\Login';
    $dupToOne = 'Cav7\\Fixture\\Vendor\\Controller\\LoginA';
    $dupToTwo = 'Cav7\\Fixture\\Vendor\\Controller\\LoginB';
    $dupFileOne = 'Test-Vendor-Controller-Login_Cav7-Fixture-Vendor-Controller-LoginA.json';
    $dupFileTwo = 'Test-Vendor-Controller-Login_Cav7-Fixture-Vendor-Controller-LoginB.json';

    $dupOk = makeFixture(
        $base,
        'dup-from-ok',
        [
            ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => '10', 'active' => '1'],
            ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => '20', 'active' => '1'],
        ],
        [
            $dupFileOne => ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => 10, 'active' => true],
            $dupFileTwo => ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => 20, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dupOk);
    check('two extensions on one from_class pass when both sides agree', $code === 0, "exit=$code\n$out");

    // --- 6. one of the duplicated rows drifted in _data, no re-export ----------
    // Counts still agree, and the surviving row still shares its from_class with
    // the drifted one, so nothing but pair matching catches this.
    $dupDrift = makeFixture(
        $base,
        'dup-from-drift',
        [
            ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => '10', 'active' => '1'],
            // Hand-edited in _data while _output still names LoginB.
            ['from_class' => $dupFrom, 'to_class' => $dupToTwo . 'Drifted', 'execute_order' => '20', 'active' => '1'],
        ],
        [
            $dupFileOne => ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => 10, 'active' => true],
            $dupFileTwo => ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => 20, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dupDrift);
    check('a drifted _data to_class fails even when the counts agree', $code !== 0, "exit=$code\n$out");
    check('the drift report names the _output item that lost its record', str_contains($out, $dupFileTwo), $out);
    check('the drift report names the _data row nothing claimed', str_contains($out, $dupToTwo . 'Drifted'), $out);
    check('the drift report leaves the intact row out of it', !str_contains($out, $dupFileOne), $out);

    // --- 7. an _output item with no _data record, sharing a from_class ---------
    $dupExtraOutput = makeFixture(
        $base,
        'dup-from-extra-output',
        [
            ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => '10', 'active' => '1'],
        ],
        [
            $dupFileOne => ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => 10, 'active' => true],
            $dupFileTwo => ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => 20, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dupExtraOutput);
    check('an _output item with no _data record fails', $code !== 0, "exit=$code\n$out");
    check('the extra _output item is named', str_contains($out, $dupFileTwo), $out);

    // --- 8. a _data record with no _output item, sharing a from_class ----------
    $dupExtraData = makeFixture(
        $base,
        'dup-from-extra-data',
        [
            ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => '10', 'active' => '1'],
            ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => '20', 'active' => '1'],
        ],
        [
            $dupFileOne => ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dupExtraData);
    check('a _data record with no _output item fails', $code !== 0, "exit=$code\n$out");
    check(
        'the unclaimed _data row is named, not left to be read off a count',
        str_contains($out, $dupToTwo) && str_contains($out, 'no matching _output item'),
        $out
    );

    // --- 9. the same one-sided cases on a unique from_class -------------------
    $uniqueExtraData = makeFixture(
        $base,
        'unique-extra-data',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $uniqueExtraData);
    check('a unique-from_class _data record with no _output item fails', $code !== 0, "exit=$code\n$out");
    check('that unclaimed unique row is named too', str_contains($out, $toB), $out);
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
