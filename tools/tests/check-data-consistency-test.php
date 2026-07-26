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
 * Cases 19 to 22 pin absence itself, for both content-checked fields. Absent is
 * not the same as false or 0, and the casts that normalise "1"/true land on the
 * same falsy value either way, so an unexported row used to compare equal to a
 * disabled one and the tool called it clean. Each case names the side, or sides,
 * the field went missing from.
 *
 * Cases 23 to 26 close the same hole where JSON leaves a second door open. A key
 * present with a null value is a value that went missing, but an absence test
 * that only asks whether the key exists says otherwise and the cast behind it
 * lands on false — the disabled row again. Cases 24 to 26 are the shapes either
 * side of a cast: "false" is a truthy string, so it read as enabled, and an
 * empty array read as disabled. Neither is a shape an export writes, so both are
 * refused rather than guessed at.
 *
 * Cases 27 and 28 carry that refusal over to execute_order, where the stakes are
 * higher than on active: a cast of any unwritten shape lands on 0, and 0 is a
 * legitimate execute_order, so a cast agrees with any _data row holding one.
 * Case 29 pins the malformed-item report. class_extensions is the one type that
 * answers the count question itself and so has no generic count guard behind it;
 * an item whose bytes fail json_decode claims no pair, which leaves the reverse
 * walk silent about it too, and this report the only thing between a half-written
 * file and a clean run.
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
 *            execute_order and active default to '10' and '1' when the key is
 *            left out. Passing null for either writes the record without that
 *            attribute at all, which is the only way to fixture an absent value:
 *            an empty attribute is a different thing, and the tool has to tell
 *            absence from a legitimate '0'/'false'.
 * $outputItems: filename => decoded item array (from_class/to_class/active...).
 *               An absent value here is simply a key the array does not hold.
 */
function makeFixture(string $base, string $name, array $dataExts, array $outputItems): string
{
    $dir = "$base/$name";
    mkdir("$dir/_data", 0777, true);
    mkdir("$dir/_output/class_extensions", 0777, true);

    $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<class_extensions>\n";
    foreach ($dataExts as $e) {
        $attrs = [
            'from_class' => $e['from_class'],
            'to_class' => $e['to_class'],
            'execute_order' => array_key_exists('execute_order', $e) ? $e['execute_order'] : '10',
            'active' => array_key_exists('active', $e) ? $e['active'] : '1',
        ];
        $xml .= '  <extension';
        foreach ($attrs as $attr => $value) {
            if ($value === null) {
                continue;
            }
            $xml .= sprintf(' %s="%s"', $attr, htmlspecialchars((string) $value, ENT_QUOTES));
        }
        $xml .= "/>\n";
    }
    $xml .= "</class_extensions>\n";
    file_put_contents("$dir/_data/class_extensions.xml", $xml);

    foreach ($outputItems as $filename => $item) {
        file_put_contents(
            "$dir/_output/class_extensions/$filename",
            json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
    writeMetadata("$dir/_output/class_extensions");
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
    writeDataFile($dir, $dataBase ?? $type, $recordTag, $dataRecords);

    foreach ($outputFiles as $relative => $contents) {
        $target = "$dir/_output/$type/$relative";
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($target, $contents);
    }
    writeMetadata("$dir/_output/$type");
    return $dir;
}

/**
 * Write a _data/<dataBase>.xml, creating _data/ if it is not there yet and
 * touching no _output/ counterpart. Called directly to build the shape the
 * _output-driven walk cannot see — a type holding records on the _data side that
 * was never exported — and by makeTypeFixture for the _data half of a pair.
 *
 * $records: list of attribute maps, one per record element. An empty list writes
 *           the self-closed empty file XenForo exports for a type with no rows.
 *           A record may carry the reserved key '#body', whose value becomes the
 *           element's CDATA text rather than an attribute — the shape the types
 *           whose payload is the element body (phrases, templates) export.
 *           It may instead carry '#children', a list of ['tag' => ..., 'body' =>
 *           ..., 'attrs' => [...]] maps written as child elements — the shape
 *           the types whose payload does not fit an attribute export, such as a
 *           modification's <find>/<replace> or an option's <relation>.
 */
function writeDataFile(string $addonDir, string $dataBase, string $recordTag, array $records): void
{
    if (!is_dir("$addonDir/_data")) {
        mkdir("$addonDir/_data", 0777, true);
    }
    $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    if (!$records) {
        $xml .= "<$dataBase/>\n";
        file_put_contents("$addonDir/_data/$dataBase.xml", $xml);
        return;
    }
    $xml .= "<$dataBase>\n";
    foreach ($records as $attrs) {
        $body = $attrs['#body'] ?? null;
        $children = $attrs['#children'] ?? null;
        unset($attrs['#body'], $attrs['#children']);
        $xml .= "  <$recordTag";
        foreach ($attrs as $attr => $value) {
            $xml .= sprintf(' %s="%s"', $attr, htmlspecialchars((string) $value, ENT_QUOTES));
        }
        if ($body === null && $children === null) {
            $xml .= "/>\n";
            continue;
        }
        $xml .= '>';
        if ($body !== null) {
            $xml .= '<![CDATA[' . $body . ']]>';
        }
        foreach ($children ?? [] as $child) {
            $xml .= "\n    <" . $child['tag'];
            foreach ($child['attrs'] ?? [] as $attr => $value) {
                $xml .= sprintf(' %s="%s"', $attr, htmlspecialchars((string) $value, ENT_QUOTES));
            }
            if (!array_key_exists('body', $child)) {
                $xml .= '/>';
                continue;
            }
            $xml .= '><![CDATA[' . $child['body'] . ']]></' . $child['tag'] . '>';
        }
        $xml .= "</$recordTag>\n";
    }
    $xml .= "</$dataBase>\n";
    file_put_contents("$addonDir/_data/$dataBase.xml", $xml);
}

/**
 * Write the _metadata.json XenForo keeps beside every exported type, indexing
 * each item file by its path under the type dir against a hash of its contents.
 * Fixtures call this so they hold the shape a real export leaves behind; the
 * cases that exercise the metadata check corrupt the result afterwards.
 *
 * The hash is md5 of the contents with carriage returns stripped, which is
 * XF\DevelopmentOutput::hashContents(). Plain md5_file() agrees with it only
 * while no file holds a \r, so it is not a substitute.
 */
function writeMetadata(string $typeDir): void
{
    $entries = [];
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($typeDir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (!$file->isFile() || $file->getFilename() === '_metadata.json') {
            continue;
        }
        $relative = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($typeDir) + 1)
        );
        $contents = (string) file_get_contents($file->getPathname());
        $entries[$relative] = ['hash' => md5(str_replace("\r", '', $contents))];
    }
    ksort($entries);
    file_put_contents("$typeDir/_metadata.json", json_encode($entries, JSON_PRETTY_PRINT));
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
    check(
        'correct fixture passes (exit 0): _data "1" equals _output true and _data "0" equals _output false',
        $code === 0,
        "exit=$code\n$out"
    );
    check(
        'correct fixture is reported as content-checked (report distinguishes)',
        str_contains($out, 'content-checked'),
        $out
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
    // The whole line, not just the filename: the message renders each side back
    // as the word it stands for, and a renderer that swapped them would still
    // fail the run while telling the reader to fix the wrong file.
    check(
        'flipped active names the item and reports each side as the value it holds',
        str_contains($out, "$fileA: active _output=false vs _data=true"),
        $out
    );

    // --- 4. corrupted from_class: _output item matches no _data <extension> -----
    $bogusFrom = 'Test\\Vendor\\Entity\\BOGUS';
    $badFrom = makeFixture(
        $base,
        'bad-from-class',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '1'],
        ],
        [
            // from_class mangled: no matching _data record.
            $fileA => ['from_class' => $bogusFrom, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $badFrom);
    check('corrupted from_class fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    // The whole line, not just the filename: this is the message the pair
    // matching rewrote, and the to_class half of it is what tells a mangled
    // from_class apart from an extension that was never exported at all.
    check(
        'corrupted from_class names the item and both halves of the pair that matched nothing',
        str_contains(
            $out,
            "$fileA: from_class '$bogusFrom' to_class '$toA' has no matching _data <extension>"
        ),
        $out
    );

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
    check(
        'both pairs are reported as matched, not passed over',
        str_contains($out, 'class_extensions: 2 item(s), content matches'),
        $out
    );

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
    check(
        'the extra _output item is named with the pair nothing matched',
        str_contains(
            $out,
            "$dupFileTwo: from_class '$dupFrom' to_class '$dupToTwo' has no matching _data <extension>"
        ),
        $out
    );

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

    // --- 10. nested type, both sides agree ------------------------------------
    // templates is the nesting case: _output items sit under a style-type
    // subfolder, and the tool walks them recursively. The count-only path this
    // case used to stand for is covered by cron_entries in case 18, which is
    // still counted; templates is content-checked now, so the fixture carries
    // the bodies a real export would.
    $templatesOk = makeTypeFixture(
        $base,
        'templates-ok',
        'templates',
        'template',
        [
            ['type' => 'public', 'title' => 'cav7_one', '#body' => "<div>one</div>\n"],
            ['type' => 'public', 'title' => 'cav7_two', '#body' => "<div>two</div>\n"],
        ],
        [
            'public/cav7_one.html' => "<div>one</div>\n",
            'public/cav7_two.html' => "<div>two</div>\n",
        ]
    );
    [$code, $out] = runTool($tool, $templatesOk);
    check('a nested type passes when both sides agree', $code === 0, "exit=$code\n$out");
    check(
        'the passing nested type is accounted for by name',
        str_contains($out, 'templates'),
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
            ['title' => 'cav7_p1', '#body' => "One\n"],
            ['title' => 'cav7_p2', '#body' => "Two\n"],
        ],
        [
            'cav7_p1.txt' => "One\n",
            'cav7_p2.txt' => "Two\n",
        ]
    );
    [$code, $out] = runTool($tool, $phrasesOk);
    check('an exact-id type passes when the ids agree', $code === 0, "exit=$code\n$out");
    // Asserts the report accounts for the type by name, not how it words the
    // verdict: the wording is prose a person maintains, and pinning it makes a
    // reworded message look like a regression.
    check('the passing exact-id type is accounted for by name', str_contains($out, 'phrases'), $out);

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
    // cron_entries is one of the two entries in the tool's $dataFileFor map:
    // items live in _output/cron_entries/ while the records are in _data/cron.xml.
    // Lose the mapping and the tool looks for a _data/cron_entries.xml that never
    // exists. Case 35 covers the other entry, admin_permissions.
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

    // --- 19. execute_order missing from a _data record ------------------------
    // The mirror of case 17. Only the _output half was pinned, so the _data arm
    // of the same guard could be deleted with the suite still green.
    $dataOrderMissing = makeFixture(
        $base,
        'data-execute-order-missing',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => null, 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 0, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dataOrderMissing);
    check('a _data record with no execute_order fails', $code !== 0, "exit=$code\n$out");
    check(
        'the missing _data execute_order names _data as the side that dropped it',
        str_contains($out, "$fileA: execute_order missing from _data"),
        $out
    );

    // --- 20. active missing from an _output item ------------------------------
    // false is a legitimate active, exactly as 0 is a legitimate execute_order,
    // so an absent key must not read as the disabled row _data happens to hold.
    $outActiveMissing = makeFixture(
        $base,
        'output-active-missing',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '0'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10],
        ]
    );
    [$code, $out] = runTool($tool, $outActiveMissing);
    check('an _output item with no active fails', $code !== 0, "exit=$code\n$out");
    check(
        'the missing _output active says which side dropped it',
        str_contains($out, "$fileA: active missing from _output"),
        $out
    );

    // --- 21. active missing from a _data record -------------------------------
    $dataActiveMissing = makeFixture(
        $base,
        'data-active-missing',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => null],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => false],
        ]
    );
    [$code, $out] = runTool($tool, $dataActiveMissing);
    check('a _data record with no active fails', $code !== 0, "exit=$code\n$out");
    check(
        'the missing _data active says which side dropped it',
        str_contains($out, "$fileA: active missing from _data"),
        $out
    );

    // --- 22. active missing from both sides -----------------------------------
    // Absent on both sides is the shape that used to compare equal, and the
    // report has to name every side that is actually missing the field rather
    // than the first one the check looked at.
    $bothActiveMissing = makeFixture(
        $base,
        'both-active-missing',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => null],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10],
        ]
    );
    [$code, $out] = runTool($tool, $bothActiveMissing);
    check('active missing from both sides fails', $code !== 0, "exit=$code\n$out");
    check(
        'active missing from both sides names both',
        str_contains($out, "$fileA: active missing from _output and _data"),
        $out
    );

    // --- 23. active present as a JSON null in _output --------------------------
    // The absence case again, through the door JSON leaves open: the key is
    // there, its value is gone. Asking only whether the key exists and then
    // casting lands on false, which is exactly the disabled row _data holds, so
    // this used to read as a clean match.
    $outActiveNull = makeFixture(
        $base,
        'output-active-null',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '0'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => null],
        ]
    );
    [$code, $out] = runTool($tool, $outActiveNull);
    check('an _output item whose active is a JSON null fails', $code !== 0, "exit=$code\n$out");
    check(
        'a JSON-null active is reported as missing from _output, not as false',
        str_contains($out, "$fileA: active missing from _output"),
        $out
    );

    // --- 24. active as the string "false" in _output --------------------------
    // No export writes a string here, and "false" is a truthy one, so casting it
    // read a disabled row as enabled. The shape is rejected outright instead.
    $outActiveString = makeFixture(
        $base,
        'output-active-string',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => 'false'],
        ]
    );
    [$code, $out] = runTool($tool, $outActiveString);
    check('an _output active holding the string "false" fails', $code !== 0, "exit=$code\n$out");
    check(
        'the string active is named as a shape no export writes',
        str_contains($out, "$fileA: active _output=\"false\" is not a value any export writes"),
        $out
    );

    // --- 25. active as an array in _output ------------------------------------
    $outActiveArray = makeFixture(
        $base,
        'output-active-array',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '0'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => []],
        ]
    );
    [$code, $out] = runTool($tool, $outActiveArray);
    check('an _output active holding an array fails', $code !== 0, "exit=$code\n$out");
    check(
        'the array active is named as a shape no export writes',
        str_contains($out, "$fileA: active _output=[] is not a value any export writes"),
        $out
    );

    // --- 26. active holding something other than "1"/"0" in _data -------------
    // The _data mirror of cases 24 and 25: the column is exported as "1" or "0",
    // and anything else is a hand-edit rather than a value to guess at.
    $dataActiveOdd = makeFixture(
        $base,
        'data-active-odd',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => 'true'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dataActiveOdd);
    check('a _data active outside "1"/"0" fails', $code !== 0, "exit=$code\n$out");
    check(
        'the odd _data active is named as a shape no export writes',
        str_contains($out, "$fileA: active _data=\"true\" is not a value any export writes"),
        $out
    );

    // --- 27. execute_order in shapes _output never writes ----------------------
    // The same refusal as cases 24 and 25, on the field where it matters most:
    // every cast of a shape the exporter never writes lands on 0, and 0 is a
    // legitimate execute_order, so a cast would quietly agree with any _data row
    // holding it. A quoted number and a fraction are the two shapes a hand-edit
    // reaches for, and both cast to the 10 that _data holds here.
    $orderShapes = makeFixture(
        $base,
        'output-execute-order-shapes',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => '10', 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => '10', 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => '10', 'active' => true],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10.5, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $orderShapes);
    check('an _output execute_order outside a JSON int fails', $code !== 0, "exit=$code\n$out");
    check(
        'both refused _output execute_order shapes are named as shapes no export writes',
        str_contains($out, "$fileA: execute_order _output=\"10\" is not a value any export writes")
            && str_contains($out, "$fileB: execute_order _output=10.5 is not a value any export writes"),
        $out
    );

    // --- 28. execute_order holding a non-number in _data ----------------------
    // The _data mirror of case 27, and the sharpest case for refusing over
    // casting: (int) 'first' is 0, and the _output item here legitimately holds
    // 0, so a cast reads a garbage row as a clean match.
    $dataOrderOdd = makeFixture(
        $base,
        'data-execute-order-odd',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 'first', 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 0, 'active' => true],
        ]
    );
    [$code, $out] = runTool($tool, $dataOrderOdd);
    check('a _data execute_order that is not a number fails', $code !== 0, "exit=$code\n$out");
    check(
        'the non-numeric _data execute_order is named as a shape no export writes',
        str_contains($out, "$fileA: execute_order _data=\"first\" is not a value any export writes"),
        $out
    );

    // --- 29. an _output item whose bytes are not valid JSON -------------------
    // class_extensions is the one type with no generic count guard behind it, so
    // this report is the only thing standing between a half-written _output item
    // and a clean run: an item that fails json_decode claims no pair, which
    // leaves the reverse walk with nothing to say about it either. Two _data rows
    // against three _output items, the third cut off mid-write.
    $truncatedFile = 'Test-Vendor-Entity-Baz_Cav7-Fixture-Vendor-Entity-Baz.json';
    $malformed = makeFixture(
        $base,
        'output-item-malformed',
        [
            ['from_class' => $fromA, 'to_class' => $toA, 'active' => '1'],
            ['from_class' => $fromB, 'to_class' => $toB, 'active' => '1'],
        ],
        [
            $fileA => ['from_class' => $fromA, 'to_class' => $toA, 'execute_order' => 10, 'active' => true],
            $fileB => ['from_class' => $fromB, 'to_class' => $toB, 'execute_order' => 10, 'active' => true],
        ]
    );
    file_put_contents(
        "$malformed/_output/class_extensions/$truncatedFile",
        "{\n    \"from_class\": \"Test\\\\Vendor\\\\Entity\\\\Baz\",\n    \"to_cl"
    );
    // Re-index after adding the item, so the half-written file is the only thing
    // wrong with this tree. A truncated export writes its index too; leaving the
    // item unindexed would make this a metadata case instead of a JSON one.
    writeMetadata("$malformed/_output/class_extensions");
    [$code, $out] = runTool($tool, $malformed);
    check('an _output item that is not valid JSON fails', $code !== 0, "exit=$code\n$out");
    check(
        'the unreadable _output item is named rather than passed over',
        str_contains($out, "$truncatedFile: not readable as a JSON object"),
        $out
    );

    // --- 30. a _data type holding records that was never exported -------------
    // The walk starts from _output, so a _data type with no _output/<type>/ at
    // all was never reached and passed silently. This is the first-record case:
    // _data carries a file for every type whether or not it holds records, but
    // an _output/<type>/ appears only once that type has one, so a type going
    // from zero records to its first with xf-addon:export run and xf-dev:export
    // not is exactly this shape. Once a type has records the count guard has it.
    $neverExported = makeTypeFixture(
        $base,
        'never-exported',
        'routes',
        'route',
        [['route_id' => 'fixture', 'route_prefix' => 'fixture']],
        ['fixture.json' => "{}\n"]
    );
    writeDataFile($neverExported, 'options', 'option', [
        ['option_id' => 'cav7FixtureOne'],
        ['option_id' => 'cav7FixtureTwo'],
    ]);
    [$code, $out] = runTool($tool, $neverExported);
    check('a _data type with records and no _output dir fails', $code !== 0, "exit=$code\n$out");
    check(
        'the un-exported type is named with its record count',
        str_contains($out, 'options: _data has 2 record(s) but _output/options/ is missing'),
        $out
    );

    // --- 31. the same shape with an empty _data file --------------------------
    // Every addon commits all 27 _data files and most hold no rows, so an empty
    // file with no _output dir is the normal state of an unused type. If case 30
    // fired on absence rather than on records, every addon in the repo would
    // fail with two dozen findings apiece.
    $emptyUnexported = makeTypeFixture(
        $base,
        'empty-unexported',
        'routes',
        'route',
        [['route_id' => 'fixture', 'route_prefix' => 'fixture']],
        ['fixture.json' => "{}\n"]
    );
    writeDataFile($emptyUnexported, 'options', 'option', []);
    [$code, $out] = runTool($tool, $emptyUnexported);
    check('an empty _data type with no _output dir passes', $code === 0, "exit=$code\n$out");
    check(
        'the empty un-exported type is not reported at all',
        !str_contains($out, 'options'),
        $out
    );

    // --- 32. no _output tree at all, with records still in _data --------------
    // The old short-circuit printed SKIP and exited 0 before looking at _data,
    // so an addon that lost its whole export tree passed. Losing every type at
    // once is the same mistake as losing one, and reads as a clean run.
    $lostTree = "$base/lost-output-tree";
    mkdir("$lostTree/_data", 0777, true);
    writeDataFile($lostTree, 'options', 'option', [['option_id' => 'cav7Fixture']]);
    [$code, $out] = runTool($tool, $lostTree);
    check('no _output tree with records in _data fails', $code !== 0, "exit=$code\n$out");
    check(
        'the missing tree is reported per type rather than as a skip',
        str_contains($out, 'options: _data has 1 record(s) but _output/options/ is missing')
            && !str_contains($out, 'SKIP'),
        $out
    );

    // --- 33. no _output tree, and nothing in _data to miss --------------------
    // A code-only addon still commits its empty _data files. There is genuinely
    // nothing to cross-check, so it stays a SKIP rather than becoming noise.
    $codeOnly = "$base/code-only";
    mkdir("$codeOnly/_data", 0777, true);
    writeDataFile($codeOnly, 'options', 'option', []);
    writeDataFile($codeOnly, 'routes', 'route', []);
    [$code, $out] = runTool($tool, $codeOnly);
    check('no _output tree and only empty _data files still skips', $code === 0, "exit=$code\n$out");
    check(
        'the skip still says why it skipped',
        str_contains($out, 'SKIP code-only: no _output/ tree'),
        $out
    );

    // --- 34. neither tree at all ---------------------------------------------
    // Cav7/Core's shape: it carries addon.json, so CI runs this check on it, but
    // it has no _data/ and no _output/. The _data-side pass must tolerate the
    // directory being absent rather than erroring on the glob.
    $noTrees = "$base/no-trees";
    mkdir($noTrees, 0777, true);
    [$code, $out] = runTool($tool, $noTrees);
    check('an addon with neither tree still exits 0', $code === 0, "exit=$code\n$out");
    check(
        'the addon with neither tree is reported as a skip, not an error',
        str_contains($out, 'SKIP no-trees: no _output/ tree'),
        $out
    );

    // --- 35. the second type whose _data file is not named after its dir ------
    // admin permissions export to _output/admin_permissions/ while their records
    // live in _data/admin_permission.xml, singular. The map carried only the
    // cron pairing, so a correctly exported admin permission was told its _data
    // file was missing while it sat right there under the other name. Nothing in
    // the repo registers one yet, which is the only reason this never fired.
    $adminPermOk = makeTypeFixture(
        $base,
        'admin-perm-ok',
        'admin_permissions',
        'admin_permission',
        [['admin_permission_id' => 'cav7Fixture', 'display_order' => '10']],
        ['cav7Fixture.json' => "{}\n"],
        'admin_permission'
    );
    [$code, $out] = runTool($tool, $adminPermOk);
    check(
        'an exported admin permission is matched to its singular _data file',
        $code === 0,
        "exit=$code\n$out"
    );
    check(
        'the admin permission type is not reported as a missing _data file',
        !str_contains($out, 'admin_permissions.xml is missing'),
        $out
    );

    // --- 36. the same pairing seen from the _data side ------------------------
    // The _data-side pass has to resolve the pairing the other way to name the
    // directory it expected. Getting this wrong points the reader at an
    // _output/admin_permission/ that XenForo never writes.
    $adminPermUnexported = makeTypeFixture(
        $base,
        'admin-perm-unexported',
        'routes',
        'route',
        [['route_id' => 'fixture', 'route_prefix' => 'fixture']],
        ['fixture.json' => "{}\n"]
    );
    writeDataFile($adminPermUnexported, 'admin_permission', 'admin_permission', [
        ['admin_permission_id' => 'cav7Fixture', 'display_order' => '10'],
    ]);
    [$code, $out] = runTool($tool, $adminPermUnexported);
    check('an un-exported admin permission fails', $code !== 0, "exit=$code\n$out");
    check(
        'the un-exported admin permission names the plural _output directory',
        str_contains(
            $out,
            'admin_permission: _data has 1 record(s) but _output/admin_permissions/ is missing'
        ),
        $out
    );

    // --- 37. the cron pairing from the _data side ----------------------------
    // Case 18 covers cron in the _output direction only. Both non-identity
    // pairings have to resolve from _data too, or the report names a directory
    // XenForo never writes and sends the reader looking for the wrong thing.
    $cronUnexported = makeTypeFixture(
        $base,
        'cron-unexported',
        'routes',
        'route',
        [['route_id' => 'fixture', 'route_prefix' => 'fixture']],
        ['fixture.json' => "{}\n"]
    );
    writeDataFile($cronUnexported, 'cron', 'entry', [['entry_id' => 'cav7Fixture']]);
    [$code, $out] = runTool($tool, $cronUnexported);
    check('an un-exported cron entry fails', $code !== 0, "exit=$code\n$out");
    check(
        'the un-exported cron entry names the _output/cron_entries/ directory',
        str_contains($out, 'cron: _data has 1 record(s) but _output/cron_entries/ is missing'),
        $out
    );

    // --- 38. a _data file no _output dir claims, whose dir exists anyway ------
    // Only a file XenForo never writes gets here: one named after an _output
    // directory rather than after its own container tag. Reporting it as
    // "_output/cron_entries/ is missing" would name a directory sitting on disk,
    // so the file itself is named as the anomaly instead.
    $strayData = makeTypeFixture(
        $base,
        'stray-data-file',
        'cron_entries',
        'entry',
        [['entry_id' => 'cav7Fixture']],
        ['cav7Fixture.json' => "{}\n"],
        'cron'
    );
    writeDataFile($strayData, 'cron_entries', 'entry', [['entry_id' => 'cav7Stray']]);
    [$code, $out] = runTool($tool, $strayData);
    check('a _data file that no _output dir claims fails', $code !== 0, "exit=$code\n$out");
    check(
        'the stray _data file is not reported as a missing directory that exists',
        !str_contains($out, '_output/cron_entries/ is missing')
            && str_contains($out, 'no _output type dir claims _data/cron_entries.xml'),
        $out
    );

    // --- 39. an unreadable _data file that no _output dir claims --------------
    // The _output-driven pass already fails on a _data file it cannot parse. The
    // _data-side pass reaches files the other one never opens, and records that
    // cannot be counted cannot be cleared, so it refuses them the same way
    // rather than passing over them.
    $unreadable = makeTypeFixture(
        $base,
        'unreadable-data-file',
        'routes',
        'route',
        [['route_id' => 'fixture', 'route_prefix' => 'fixture']],
        ['fixture.json' => "{}\n"]
    );
    file_put_contents("$unreadable/_data/options.xml", "<options><option \n");
    [$code, $out] = runTool($tool, $unreadable);
    check('an unclaimed _data file that is not valid XML fails', $code !== 0, "exit=$code\n$out");
    check(
        'the unreadable unclaimed _data file is named',
        str_contains($out, '_data/options.xml is not readable as XML'),
        $out
    );
    // Phrase text is the payload, and it lived outside the check entirely: the
    // ids were compared and the bytes behind them were not, so re-exporting one
    // tree after an edit and forgetting the other left two trees describing
    // different wording under one id, and the tool called it clean. _output
    // holds the text as the whole .txt file; _data holds it as the element body.
    $phraseDrift = makeTypeFixture(
        $base,
        'phrase-text-drift',
        'phrases',
        'phrase',
        [['title' => 'fixture_greeting', '#body' => 'Hello there.']],
        ['fixture_greeting.txt' => 'Something else entirely.']
    );
    [$code, $out] = runTool($tool, $phraseDrift);
    check('a phrase whose _output text differs from _data fails', $code !== 0, "exit=$code\n$out");
    check(
        'the drifted phrase is named',
        str_contains($out, 'fixture_greeting'),
        $out
    );

    // The other direction, and the reason the comparison has to be byte-exact
    // rather than trimmed: a phrase really can be exported with leading or
    // trailing whitespace, and two trees that agree must still pass.
    $phraseClean = makeTypeFixture(
        $base,
        'phrase-text-clean',
        'phrases',
        'phrase',
        [['title' => 'fixture_spaced', '#body' => "  padded\ttext\n"]],
        ['fixture_spaced.txt' => "  padded\ttext\n"]
    );
    [$code, $out] = runTool($tool, $phraseClean);
    check('matching phrase text passes, whitespace and all', $code === 0, "exit=$code\n$out");
    // A template body is the largest payload either tree carries, and it was
    // count-checked only — the titles were never compared, let alone the markup.
    // _output nests templates under their type, and the file name is the title
    // with .html appended unless the title already carries an extension. That
    // rule is XF\DevelopmentOutput\Template::convertTemplateNameToFile(); the
    // check re-derives the path from _data rather than guessing at the tree.
    $templateDrift = makeTypeFixture(
        $base,
        'template-body-drift',
        'templates',
        'template',
        [['title' => 'cav7_fixture', 'type' => 'public', '#body' => "<div>original</div>\n"]],
        ['public/cav7_fixture.html' => "<div>rewritten</div>\n"]
    );
    [$code, $out] = runTool($tool, $templateDrift);
    check('a template whose _output body differs from _data fails', $code !== 0, "exit=$code\n$out");
    check('the drifted template is named', str_contains($out, 'cav7_fixture'), $out);

    // The type is not stored in the _output record at all — it is the directory
    // the file sits in — so a _data type flip moves where the record should be
    // without changing any count. Nothing could see it while templates were
    // counted; deriving the path from _data is what makes it visible.
    $templateTypeFlip = makeTypeFixture(
        $base,
        'template-type-flip',
        'templates',
        'template',
        [['title' => 'cav7_fixture', 'type' => 'admin', '#body' => "<div>same</div>\n"]],
        ['public/cav7_fixture.html' => "<div>same</div>\n"]
    );
    [$code, $out] = runTool($tool, $templateTypeFlip);
    check('a _data template type flip fails though the count is unchanged', $code !== 0, "exit=$code\n$out");

    // A title that already carries an extension keeps it, and one that does not
    // gains .html. Both live in one add-on in this repo (cav7_milpac and
    // cav7_milpac.less), so a rule that handled only one of them would fail a
    // clean tree — and matching on the extension-stripped name would collide
    // the two titles onto one file.
    $templateExtensions = makeTypeFixture(
        $base,
        'template-extensions',
        'templates',
        'template',
        [
            ['title' => 'cav7_fixture', 'type' => 'public', '#body' => "<div>markup</div>\n"],
            ['title' => 'cav7_fixture.less', 'type' => 'public', '#body' => ".a { color: red; }\n"],
        ],
        [
            'public/cav7_fixture.html' => "<div>markup</div>\n",
            'public/cav7_fixture.less' => ".a { color: red; }\n",
        ]
    );
    [$code, $out] = runTool($tool, $templateExtensions);
    check(
        'a dotted title keeps its extension while a plain one gains .html',
        $code === 0,
        "exit=$code\n$out"
    );
    // The mutation this whole check exists for: an _output modification rewritten
    // to a find string that matches nothing is the complete undoing of whatever
    // the modification did, and it left every count and every id alone. _output
    // is the tree a dev-mode install imports, so this is the copy a developer
    // actually runs while the shipped _data stays correct.
    $modificationFixture = static fn (array $overrides = [], string $dataType = 'public'): array => [
        [
            'type' => $dataType,
            'template' => 'account_wrapper',
            'modification_key' => 'cav7_fixture_mod',
            'description' => 'Adds a link',
            'execution_order' => '10',
            'enabled' => '1',
            'action' => 'str_replace',
            '#children' => [
                ['tag' => 'find', 'body' => '<h3>{{ phrase(\'settings\') }}</h3>'],
                ['tag' => 'replace', 'body' => "<h3>replaced</h3>\n\$0"],
            ],
        ],
    ];
    $modificationOutput = static fn (array $overrides = []): string => json_encode($overrides + [
        'template' => 'account_wrapper',
        'description' => 'Adds a link',
        'execution_order' => 10,
        'enabled' => true,
        'action' => 'str_replace',
        'find' => '<h3>{{ phrase(\'settings\') }}</h3>',
        'replace' => "<h3>replaced</h3>\n\$0",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $modFindDrift = makeTypeFixture(
        $base,
        'modification-find-drift',
        'template_modifications',
        'modification',
        $modificationFixture(),
        ['public/cav7_fixture_mod.json' => $modificationOutput(['find' => '#NEVER_MATCHES#'])]
    );
    [$code, $out] = runTool($tool, $modFindDrift);
    check('an _output modification with a drifted find string fails', $code !== 0, "exit=$code\n$out");
    check('the drifted modification is named', str_contains($out, 'cav7_fixture_mod'), $out);

    // An unchanged tree still has to pass, or the check above is worthless.
    $modClean = makeTypeFixture(
        $base,
        'modification-clean',
        'template_modifications',
        'modification',
        $modificationFixture(),
        ['public/cav7_fixture_mod.json' => $modificationOutput()]
    );
    [$code, $out] = runTool($tool, $modClean);
    check('an unchanged modification pair passes', $code === 0, "exit=$code\n$out");

    // The type is the subdirectory, never a key in the record, so flipping it in
    // _data disables the modification outright without moving a count. Same
    // structural blind spot as the template type flip, on the type where the
    // payload is a regex nobody can eyeball.
    $modTypeFlip = makeTypeFixture(
        $base,
        'modification-type-flip',
        'template_modifications',
        'modification',
        $modificationFixture([], 'admin'),
        ['public/cav7_fixture_mod.json' => $modificationOutput()]
    );
    [$code, $out] = runTool($tool, $modTypeFlip);
    check('a _data modification type flip fails though the count is unchanged', $code !== 0, "exit=$code\n$out");

    // XenForo omits a _data attribute when, and only when, its value is the
    // empty string. An absent attribute is therefore a positive claim that the
    // value is '' — not an unknown to be skipped — so a record with no
    // description has to pass against an _output description of "", and fail
    // against anything else. Skipping absent attributes instead would rebuild
    // the weak check this replaces, quietly.
    $modEmptyDescription = makeTypeFixture(
        $base,
        'modification-empty-description',
        'template_modifications',
        'modification',
        [
            [
                'type' => 'public',
                'template' => 'account_wrapper',
                'modification_key' => 'cav7_fixture_mod',
                'execution_order' => '10',
                'enabled' => '1',
                'action' => 'str_replace',
                '#children' => [
                    ['tag' => 'find', 'body' => 'find me'],
                    ['tag' => 'replace', 'body' => 'replaced'],
                ],
            ],
        ],
        ['public/cav7_fixture_mod.json' => json_encode([
            'template' => 'account_wrapper',
            'description' => '',
            'execution_order' => 10,
            'enabled' => true,
            'action' => 'str_replace',
            'find' => 'find me',
            'replace' => 'replaced',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]
    );
    [$code, $out] = runTool($tool, $modEmptyDescription);
    check('an attribute absent from _data matches an empty _output value', $code === 0, "exit=$code\n$out");

    // The same absence against a non-empty _output value is a real mismatch.
    $modAbsentVsSet = makeTypeFixture(
        $base,
        'modification-absent-vs-set',
        'template_modifications',
        'modification',
        [
            [
                'type' => 'public',
                'template' => 'account_wrapper',
                'modification_key' => 'cav7_fixture_mod',
                'execution_order' => '10',
                'enabled' => '1',
                'action' => 'str_replace',
                '#children' => [
                    ['tag' => 'find', 'body' => 'find me'],
                    ['tag' => 'replace', 'body' => 'replaced'],
                ],
            ],
        ],
        ['public/cav7_fixture_mod.json' => json_encode([
            'template' => 'account_wrapper',
            'description' => 'a description _data never carried',
            'execution_order' => 10,
            'enabled' => true,
            'action' => 'str_replace',
            'find' => 'find me',
            'replace' => 'replaced',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]
    );
    [$code, $out] = runTool($tool, $modAbsentVsSet);
    check('an attribute absent from _data fails a non-empty _output value', $code !== 0, "exit=$code\n$out");
    // An option's default_value drifting in _output alone is the one gap in this
    // family with a demonstrated live consequence: a wrong prefix id set in the
    // copy a dev-mode install imports reads the whole queue as already handled,
    // while the shipped _data stays correct. Ids matched, so nothing saw it.
    $optionRecord = static fn (array $overrides = []): array => $overrides + [
        'option_id' => 'cav7FixtureOption',
        'edit_format' => 'textbox',
        'data_type' => 'string',
        'advanced' => '0',
    ];
    $optionOutput = static fn (array $overrides = []): string => json_encode($overrides + [
        'edit_format' => 'textbox',
        'edit_format_params' => '',
        'data_type' => 'string',
        'sub_options' => [],
        'validation_class' => '',
        'validation_method' => '',
        'advanced' => false,
        'default_value' => '53,54,55',
        'relations' => ['cav7FixtureGroup' => 100],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $optionChildren = static fn (string $defaultValue = '53,54,55'): array => [
        ['tag' => 'default_value', 'body' => $defaultValue],
        ['tag' => 'relation', 'attrs' => ['group_id' => 'cav7FixtureGroup', 'display_order' => '100']],
    ];

    $optionDefaultDrift = makeTypeFixture(
        $base,
        'option-default-drift',
        'options',
        'option',
        [$optionRecord(['#children' => $optionChildren()])],
        ['cav7FixtureOption.json' => $optionOutput(['default_value' => '53,54,55,57'])]
    );
    [$code, $out] = runTool($tool, $optionDefaultDrift);
    check('an option whose _output default_value drifts fails', $code !== 0, "exit=$code\n$out");
    check('the drifted option is named', str_contains($out, 'cav7FixtureOption'), $out);

    $optionClean = makeTypeFixture(
        $base,
        'option-clean',
        'options',
        'option',
        [$optionRecord(['#children' => $optionChildren()])],
        ['cav7FixtureOption.json' => $optionOutput()]
    );
    [$code, $out] = runTool($tool, $optionClean);
    check('an unchanged option pair passes', $code === 0, "exit=$code\n$out");

    // relations is the shape that differs most between the two sides: _output
    // holds a JSON map of group to display order, _data one <relation> child per
    // entry. A drifted display order moves where the option appears in its group
    // and changes no id and no count.
    $optionRelationDrift = makeTypeFixture(
        $base,
        'option-relation-drift',
        'options',
        'option',
        [$optionRecord(['#children' => $optionChildren()])],
        ['cav7FixtureOption.json' => $optionOutput(['relations' => ['cav7FixtureGroup' => 250]])]
    );
    [$code, $out] = runTool($tool, $optionRelationDrift);
    check('an option whose _output relation display order drifts fails', $code !== 0, "exit=$code\n$out");

    // A relation pointing at a different group entirely.
    $optionRelationGroupDrift = makeTypeFixture(
        $base,
        'option-relation-group-drift',
        'options',
        'option',
        [$optionRecord(['#children' => $optionChildren()])],
        ['cav7FixtureOption.json' => $optionOutput(['relations' => ['someOtherGroup' => 100]])]
    );
    [$code, $out] = runTool($tool, $optionRelationGroupDrift);
    check('an option whose _output relation names another group fails', $code !== 0, "exit=$code\n$out");

    // sub_options is a JSON list on one side and a newline-joined element body
    // on the other. The empty list is the omitted case and has to keep passing,
    // which the two clean fixtures above already cover; this pins the drift.
    $optionSubOptionsDrift = makeTypeFixture(
        $base,
        'option-sub-options-drift',
        'options',
        'option',
        [
            $optionRecord([
                '#children' => array_merge(
                    [['tag' => 'sub_options', 'body' => "alpha\nbeta"]],
                    $optionChildren()
                ),
            ]),
        ],
        ['cav7FixtureOption.json' => $optionOutput(['sub_options' => ['alpha', 'gamma']])]
    );
    [$code, $out] = runTool($tool, $optionSubOptionsDrift);
    check('an option whose _output sub_options drift fails', $code !== 0, "exit=$code\n$out");

    // The same list, matching, across the join.
    $optionSubOptionsClean = makeTypeFixture(
        $base,
        'option-sub-options-clean',
        'options',
        'option',
        [
            $optionRecord([
                '#children' => array_merge(
                    [['tag' => 'sub_options', 'body' => "alpha\nbeta"]],
                    $optionChildren()
                ),
            ]),
        ],
        ['cav7FixtureOption.json' => $optionOutput(['sub_options' => ['alpha', 'beta']])]
    );
    [$code, $out] = runTool($tool, $optionSubOptionsClean);
    check('a matching sub_options list passes across the newline join', $code === 0, "exit=$code\n$out");
    // _metadata.json is the exporter's own record of what it wrote, and nothing
    // read it. It is what separates "this tree came out of xf-dev:export" from
    // "somebody typed this", which is the one drift the _data comparison cannot
    // see: edit both sides to agree and they are consistent with each other and
    // with nothing else. CONTRIBUTING.md's "do not hand-edit either" rests on it.
    $metadataStale = makeTypeFixture(
        $base,
        'metadata-stale-hash',
        'phrases',
        'phrase',
        [['title' => 'cav7_meta', '#body' => "Text\n"]],
        ['cav7_meta.txt' => "Text\n"]
    );
    file_put_contents(
        "$metadataStale/_output/phrases/_metadata.json",
        json_encode(['cav7_meta.txt' => ['hash' => str_repeat('0', 32)]], JSON_PRETTY_PRINT)
    );
    [$code, $out] = runTool($tool, $metadataStale);
    check('a _metadata.json hash that is not its file fails', $code !== 0, "exit=$code\n$out");
    check('the file with the stale hash is named', str_contains($out, 'cav7_meta.txt'), $out);

    // An item the exporter never indexed — the shape a hand-added file leaves.
    $metadataUnindexed = makeTypeFixture(
        $base,
        'metadata-unindexed-item',
        'phrases',
        'phrase',
        [
            ['title' => 'cav7_meta', '#body' => "Text\n"],
            ['title' => 'cav7_extra', '#body' => "More\n"],
        ],
        ['cav7_meta.txt' => "Text\n", 'cav7_extra.txt' => "More\n"]
    );
    $index = json_decode(
        (string) file_get_contents("$metadataUnindexed/_output/phrases/_metadata.json"),
        true
    );
    unset($index['cav7_extra.txt']);
    file_put_contents(
        "$metadataUnindexed/_output/phrases/_metadata.json",
        json_encode($index, JSON_PRETTY_PRINT)
    );
    [$code, $out] = runTool($tool, $metadataUnindexed);
    check('an item absent from _metadata.json fails', $code !== 0, "exit=$code\n$out");
    check('the unindexed item is named', str_contains($out, 'cav7_extra.txt'), $out);

    // The reverse: an index entry naming a file that is not there.
    $metadataGhost = makeTypeFixture(
        $base,
        'metadata-ghost-entry',
        'phrases',
        'phrase',
        [['title' => 'cav7_meta', '#body' => "Text\n"]],
        ['cav7_meta.txt' => "Text\n"]
    );
    $index = json_decode(
        (string) file_get_contents("$metadataGhost/_output/phrases/_metadata.json"),
        true
    );
    $index['cav7_vanished.txt'] = ['hash' => str_repeat('a', 32)];
    file_put_contents(
        "$metadataGhost/_output/phrases/_metadata.json",
        json_encode($index, JSON_PRETTY_PRINT)
    );
    [$code, $out] = runTool($tool, $metadataGhost);
    check('a _metadata.json entry with no file fails', $code !== 0, "exit=$code\n$out");
    check('the ghost entry is named', str_contains($out, 'cav7_vanished.txt'), $out);

    // Deleting the index outright is not a way out of the check.
    $metadataDeleted = makeTypeFixture(
        $base,
        'metadata-deleted',
        'phrases',
        'phrase',
        [['title' => 'cav7_meta', '#body' => "Text\n"]],
        ['cav7_meta.txt' => "Text\n"]
    );
    unlink("$metadataDeleted/_output/phrases/_metadata.json");
    [$code, $out] = runTool($tool, $metadataDeleted);
    check('a missing _metadata.json fails', $code !== 0, "exit=$code\n$out");

    // A file holding a carriage return still verifies, because the exporter
    // hashes the contents with \r stripped. Plain md5 of the bytes would fail
    // this, and would fail it only on the files where it matters.
    $metadataCarriageReturn = makeTypeFixture(
        $base,
        'metadata-carriage-return',
        'phrases',
        'phrase',
        [['title' => 'cav7_crlf', '#body' => "Line one\r\nLine two\r\n"]],
        ['cav7_crlf.txt' => "Line one\r\nLine two\r\n"]
    );
    [$code, $out] = runTool($tool, $metadataCarriageReturn);
    check('a file holding carriage returns verifies against its hash', $code === 0, "exit=$code\n$out");
    // A type this tool still only counts has to be visible as such in the
    // report, not left to be inferred from its absence. The assertions name the
    // types and the fields — both data the tool is given — and deliberately do
    // not pin how the verdict is worded, which is prose a maintainer owns.
    $mixedReport = makeTypeFixture(
        $base,
        'mixed-report',
        'template_modifications',
        'modification',
        $modificationFixture(),
        ['public/cav7_fixture_mod.json' => $modificationOutput()]
    );
    mkdir("$mixedReport/_output/routes", 0777, true);
    writeDataFile($mixedReport, 'routes', 'route', [['route_id' => 'fixture', 'route_prefix' => 'fixture']]);
    file_put_contents("$mixedReport/_output/routes/fixture.json", "{}\n");
    writeMetadata("$mixedReport/_output/routes");
    [$code, $out] = runTool($tool, $mixedReport);
    check('the mixed-report fixture passes', $code === 0, "exit=$code\n$out");
    check('the report accounts for the content-checked type', str_contains($out, 'template_modifications'), $out);
    check('the report accounts for the counted type too', str_contains($out, 'routes'), $out);
    // The point of the criterion: a reader can tell which fields were actually
    // compared, rather than trusting that "checked" covered the payload.
    foreach (['template', 'description', 'execution_order', 'enabled', 'action', 'find', 'replace'] as $field) {
        check("the report names $field as compared", str_contains($out, $field), $out);
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
