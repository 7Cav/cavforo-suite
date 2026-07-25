<?php

/**
 * check-data-consistency-test.php — pins the class_extensions content check in
 * tools/check-data-consistency.php (issues #57 and #150), with no XenForo and no test
 * framework. Self-contained: builds throwaway fixture addon dirs in the system
 * temp dir, runs the real tool against them, asserts on its exit code and
 * output, then cleans up. Exits non-zero on any failure.
 *
 * The gap this guards: the tool used to count class_extensions without ever
 * reading inside an _output item, so a corrupted to_class / from_class, or a
 * flipped active, in an _output/class_extensions/*.json passed as long as the
 * file count was unchanged. RED proof (before the fix): the corrupted fixtures
 * below make the tool exit 0. After the fix they exit non-zero and name the
 * offending item, while a correct fixture still exits 0 and the _data string "1"
 * compares equal to the _output bool true.
 *
 * Cases 5 to 9 guard issue #150. The tool used to key its _data lookup on
 * from_class alone, so two extensions registered against one from_class (which
 * XenForo allows, and runs in sequence) collapsed to a single record and the
 * second _output item was compared against the wrong row. RED proof: case 5
 * exits 1 on valid data before the fix. Matching on the (from_class, to_class)
 * pair is what makes the rows distinct, and the one-sided cases pin that each
 * unmatched row is named on whichever side it went missing from.
 *
 * Cases 10 to 13 hold down the generic record-count guard that every type except
 * class_extensions still relies on. Nothing covered it before, so deleting it
 * went unnoticed: a count-only type (templates) stopped reporting an unexported
 * item, and on an exact-id type (phrases) a duplicated _data record slipped
 * through, since array_diff collapses duplicates and the count was the only
 * thing counting them.
 *
 * class_extensions is not count-checked at all any more — pair matching answers
 * the count question itself — and that trade only holds while a pair appears at
 * most once per side. Cases 14 and 15 pin the two guards that keep it honest:
 * without them a repeated pair on either side hides a row that went missing on
 * the other, and the tool reports "content matches" on genuinely duplicated
 * data. Cases 16 and 17 cover execute_order, which decides which extension wraps
 * which when several share a from_class; case 18 pins the $dataFileFor mapping
 * for types whose _data file is not named after their _output directory.
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

/**
 * Build a throwaway fixture addon under $base for any non-class_extensions type:
 * a _data/<dataBase>.xml holding $dataRecords, and _output/<type>/ holding
 * $outputFiles.
 *
 * $dataRecords: list of attribute maps, one per record element.
 * $outputFiles: path relative to _output/<type>/ => file contents. A path may
 *               contain a subdirectory, since some types nest (templates).
 */
function makeTypeFixture(
    string $base,
    string $name,
    string $type,
    string $recordTag,
    array $dataRecords,
    array $outputFiles,
    ?string $dataBase = null
): string {
    $dir = "$base/$name";
    mkdir("$dir/_data", 0777, true);
    mkdir("$dir/_output/$type", 0777, true);

    // The real _data files name their root element after the file, not after the
    // _output directory, so cron_entries items sit under a <cron> root.
    $root = $dataBase ?? $type;
    $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<$root>\n";
    foreach ($dataRecords as $attrs) {
        $xml .= "  <$recordTag";
        foreach ($attrs as $attr => $value) {
            $xml .= sprintf(' %s="%s"', $attr, htmlspecialchars((string) $value, ENT_QUOTES));
        }
        $xml .= "/>\n";
    }
    $xml .= "</$root>\n";
    file_put_contents("$dir/_data/$root.xml", $xml);

    foreach ($outputFiles as $relative => $contents) {
        $target = "$dir/_output/$type/$relative";
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($target, $contents);
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

    // --- 10. count-checked type, counts agree ---------------------------------
    // templates is the nesting case: _output items sit under a style-type
    // subfolder, and the tool counts them recursively.
    $templatesOk = makeTypeFixture(
        $base,
        'templates-ok',
        'templates',
        'template',
        [
            ['type' => 'public', 'title' => 'cav7_one'],
            ['type' => 'public', 'title' => 'cav7_two'],
        ],
        [
            'public/cav7_one.html' => "<div>one</div>\n",
            'public/cav7_two.html' => "<div>two</div>\n",
        ]
    );
    [$code, $out] = runTool($tool, $templatesOk);
    check('a count-checked type passes when the counts agree', $code === 0, "exit=$code\n$out");
    check(
        'the passing count-checked type is reported as count-checked',
        str_contains($out, 'count-checked'),
        $out
    );

    // --- 11. count-checked type, counts disagree ------------------------------
    // The guard that catches "someone added a template and forgot to re-export".
    // class_extensions answers the count question with its own pair matching, but
    // every other type has nothing else to fall back on.
    $templatesShort = makeTypeFixture(
        $base,
        'templates-short',
        'templates',
        'template',
        [
            ['type' => 'public', 'title' => 'cav7_one'],
        ],
        [
            'public/cav7_one.html' => "<div>one</div>\n",
            'public/cav7_two.html' => "<div>two</div>\n",
            'public/cav7_three.html' => "<div>three</div>\n",
        ]
    );
    [$code, $out] = runTool($tool, $templatesShort);
    check(
        'a count-checked type fails when _output holds more items than _data',
        $code !== 0,
        "exit=$code\n$out"
    );
    check(
        'the count mismatch reports both counts',
        str_contains($out, '_output has 3 item(s), _data has 1'),
        $out
    );

    // --- 12. exact-id type with a duplicated _data record ---------------------
    // array_diff collapses duplicates, so id comparison alone calls this clean;
    // only the count guard sees the extra record.
    $phrasesDup = makeTypeFixture(
        $base,
        'phrases-dup',
        'phrases',
        'phrase',
        [
            ['title' => 'cav7_p1'],
            ['title' => 'cav7_p1'],
            ['title' => 'cav7_p2'],
        ],
        [
            'cav7_p1.txt' => "One\n",
            'cav7_p2.txt' => "Two\n",
        ]
    );
    [$code, $out] = runTool($tool, $phrasesDup);
    check(
        'a duplicated _data record on an exact-id type fails',
        $code !== 0,
        "exit=$code\n$out"
    );
    check(
        'the duplicated-record failure reports the differing counts',
        str_contains($out, '_output has 2 item(s), _data has 3'),
        $out
    );

    // --- 13. exact-id type, ids agree ----------------------------------------
    $phrasesOk = makeTypeFixture(
        $base,
        'phrases-ok',
        'phrases',
        'phrase',
        [
            ['title' => 'cav7_p1'],
            ['title' => 'cav7_p2'],
        ],
        [
            'cav7_p1.txt' => "One\n",
            'cav7_p2.txt' => "Two\n",
        ]
    );
    [$code, $out] = runTool($tool, $phrasesOk);
    check('an exact-id type passes when the ids agree', $code === 0, "exit=$code\n$out");
    check('the passing exact-id type is reported as ids match', str_contains($out, 'ids match'), $out);

    // --- 14. the same pair twice in _data -------------------------------------
    // class_extensions buys its way out of the generic count guard by keying
    // both sides on the pair, and that only works while a pair appears once per
    // side. Two _data records for one pair against a single _output item is the
    // shape that would otherwise read as a clean one-to-one match while a row
    // went missing.
    $dataPairDup = makeFixture(
        $base,
        'data-pair-dup',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dataPairDup);
    check('a pair repeated in _data fails', $code !== 0, "exit=$code\n$out");
    check(
        'the repeated _data pair is named',
        str_contains($out, "_data has more than one <extension> for from_class '$fromA' to_class '$toA'"),
        $out
    );

    // --- 15. the same pair in two _output files -------------------------------
    // The mirror of case 14: two files claiming one _data record.
    $outputPairDup = makeFixture(
        $base,
        'output-pair-dup',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
            "copy-of-$fileA" => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $outputPairDup);
    check('a pair repeated across two _output items fails', $code !== 0, "exit=$code\n$out");
    check(
        'the doubly-claimed pair is named',
        str_contains($out, "from_class '$fromA' to_class '$toA' is claimed by more than one _output item"),
        $out
    );

    // --- 16. execute_order drifted between the two sides ----------------------
    // execute_order decides which extension wraps which when several share a
    // from_class, so swapping it between two rows changes what runs first while
    // every pair still matches and every count still agrees.
    $orderSwap = makeFixture(
        $base,
        'execute-order-swap',
        [
            ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => '10', 'active' => '1'],
            ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => '20', 'active' => '1'],
        ],
        [
            $dupFileOne => ['from_class' => $dupFrom, 'to_class' => $dupToOne, 'execute_order' => 20, 'active' => true],
            $dupFileTwo => ['from_class' => $dupFrom, 'to_class' => $dupToTwo, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $orderSwap);
    check('a swapped execute_order fails', $code !== 0, "exit=$code\n$out");
    check(
        'the swapped execute_order names both sides of the disagreement',
        str_contains($out, "$dupFileOne: execute_order _output=20 vs _data=10")
            && str_contains($out, "$dupFileTwo: execute_order _output=10 vs _data=20"),
        $out
    );

    // --- 17. execute_order missing from an _output item -----------------------
    // A real export always writes execute_order; a hand-edited file that drops
    // it must not read as the 0 that _data happens to hold.
    $orderMissing = makeFixture(
        $base,
        'execute-order-missing',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => '0', 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $orderMissing);
    check('an _output item with no execute_order fails', $code !== 0, "exit=$code\n$out");
    check(
        'the missing execute_order says which side dropped it',
        str_contains($out, "$fileA: execute_order missing from _output"),
        $out
    );

    // --- 18. a type whose _data file is not named after its _output dir --------
    // cron_entries is the sole entry in the tool's $dataFileFor map: items live
    // in _output/cron_entries/ while the records are in _data/cron.xml. Lose the
    // mapping and the tool looks for a _data/cron_entries.xml that never exists.
    $cronOk = makeTypeFixture(
        $base,
        'cron-ok',
        'cron_entries',
        'entry',
        [
            [
                'entry_id' => 'cav7Fixture',
                'cron_class' => 'Cav7\\Fixture\\Cron\\Run',
                'cron_method' => 'run',
                'active' => '1',
            ],
        ],
        ['cav7Fixture.json' => "{}\n"],
        'cron'
    );
    [$code, $out] = runTool($tool, $cronOk);
    check(
        'a type whose _data file is named differently is matched to that file',
        $code === 0,
        "exit=$code\n$out"
    );
    check(
        'the differently-named type is count-checked like any other',
        str_contains($out, 'cron_entries: 1 item(s), counts match'),
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
