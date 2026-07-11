<?php

/**
 * check-data-consistency-test.php — pins the class_extensions content check in
 * tools/check-data-consistency.php (issue #57), with no XenForo and no test
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
 * Run:
 *   php tools/tests/check-data-consistency-test.php
 *
 * Note: nothing in CI runs tools/ tests yet (run-tests.sh only runs addon
 * tests/*.php). This is the local completion proof for #57; wiring a tools-test
 * lane into CI is a separate follow-up.
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
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
