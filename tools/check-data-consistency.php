<?php

/**
 * check-data-consistency.php — cross-check an addon's _output/ tree against its
 * _data/ bundle, with no XenForo. Both are exports of the same database state,
 * so they should describe the same set of items. A mismatch usually means
 * someone edited one side without re-running xf-addon:export, or hand-edited a
 * file. Exits non-zero on a mismatch.
 *
 *   php tools/check-data-consistency.php src/addons/Cav7/SteamChecker
 *
 * Each _output/<type>/ directory is matched to its _data/<type>.xml. Item files
 * are counted recursively, since some types (templates) nest under subfolders by
 * style type. For every type but class_extensions the record counts must agree.
 * For options, phrases and option_groups the item ids are compared exactly on
 * top of that count, since the _output filename is the id — the count is what
 * catches a duplicated record, which comparing ids alone cannot see.
 * class_extensions is matched row by row on the (from_class, to_class) pair
 * instead of counted: xf_class_extension carries a UNIQUE KEY over exactly
 * those two columns, so one from_class may hold several extensions and the pair
 * is what identifies a row. A row present on only one side, or a disagreeing
 * execute_order or active, fails even when the file count is unchanged. An
 * absent execute_order or active is a mismatch too, named by the side it is
 * missing from, since 0 and false are both legitimate values — and so is a
 * value in a shape no export writes, which is refused rather than cast into
 * one of those two.
 * (docs/adr/0003-canonical-class-extension-order.md rests on the same pair
 * identity: it fixes the row order of a committed _data file as a byte
 * comparison of from_class then to_class, and leaves execute_order out of that
 * rule because the UNIQUE KEY over the pair means the exporter never reaches it
 * as a tiebreaker.)
 * Other types are count-checked only; the report says which is which, so
 * nothing is skipped silently.
 *
 * That walk runs from _output, which can only see a type that has been exported
 * at least once, since _output/<type>/ appears only when the type holds a record.
 * A second pass runs from _data to cover the rest: any _data file holding records
 * that no _output directory claimed was never exported, and fails. It is what
 * catches a type going from zero records to its first with xf-addon:export run
 * and xf-dev:export not, and an addon that lost its whole _output tree while
 * _data still holds records. An empty _data file is the normal state of an
 * unused type and is not a finding, so an addon with no records to miss still
 * skips.
 *
 * This is a structural heuristic, not a re-implementation of xf-addon:export. It
 * catches the realistic mistakes (forgot to export, hand-edited one side); the
 * gold-standard check is running the real export in a stack and diffing _data/.
 */

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php tools/check-data-consistency.php <addon-dir>\n");
    exit(2);
}
$name = basename($dir);
$outRoot = "$dir/_output";
$dataRoot = "$dir/_data";

// A code-only addon has no _output tree. That is not a blanket pass: the _data
// side is still walked below, so an addon that lost its whole export tree while
// _data still holds records fails rather than skipping. The SKIP is only reached
// at the end, once nothing has been found to report.
$hasOutputTree = is_dir($outRoot);

// _output type dir -> _data file basename, for the cases where they differ.
//
// XenForo names every data type twice and the two names are not always the same
// string: the _output directory is getTypeDir() on the XF\DevelopmentOutput\*
// handler, the _data file is getContainerTag() on the matching XF\AddOn\DataType\*
// class. Regex those two methods out of the two class trees to re-derive this
// table against a newer XenForo — it is the only way to check it, since this
// tool must run without XenForo and so can never notice a type XenForo adds
// later. Across all 27 types in XenForo 2.3.11 exactly these two disagree.
$dataFileFor = [
    'admin_permissions' => 'admin_permission',
    'cron_entries'      => 'cron',
];

// Types whose ids we compare exactly: the _output filename (minus extension) is
// the id, and these attributes hold the same id in the _data record.
$exactTypes = [
    'options'       => ['ext' => 'json', 'attr' => 'option_id'],
    'phrases'       => ['ext' => 'txt',  'attr' => 'title'],
    'option_groups' => ['ext' => 'json', 'attr' => 'group_id'],
];

// All item files under an _output type dir, at any depth, minus the _metadata
// index files. Returns SplFileInfo objects.
$collectItems = static function (string $root): array {
    $items = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile() && $file->getFilename() !== '_metadata.json') {
            $items[] = $file;
        }
    }
    return $items;
};

$errors = [];
$report = [];

// Every _data basename some _output type dir claimed, filled in by the walk
// below and read by the _data-side pass after it.
$dataFilesSeen = [];

foreach ($hasOutputTree ? glob("$outRoot/*", GLOB_ONLYDIR) : [] as $typeDir) {
    $type = basename($typeDir);
    $items = $collectItems($typeDir);
    $countOutput = count($items);

    $dataBase = $dataFileFor[$type] ?? $type;
    $dataFilesSeen[$dataBase] = true;
    $xmlFile = "$dataRoot/$dataBase.xml";
    if (!is_file($xmlFile)) {
        $errors[] = "_output/$type/ has $countOutput item(s) but _data/$dataBase.xml is missing";
        continue;
    }
    $doc = @simplexml_load_file($xmlFile);
    if ($doc === false) {
        $errors[] = "_data/$dataBase.xml is not readable as XML";
        continue;
    }
    $records = $doc->children();
    $countData = count($records);

    // class_extensions: content-checked, not just counted. A row's identity is
    // the (from_class, to_class) pair — xf_class_extension's UNIQUE KEY covers
    // exactly those two columns, and XenForo builds the _output filename from
    // both — so an addon may register several extensions against one from_class
    // and they stay distinct here (issue #150). Each _output item is matched to
    // its _data <extension> on that pair, then the two columns that are left,
    // execute_order and active, are compared; between them the four cover every
    // field either side exports. _data spells both as XML strings ("1", "20")
    // where _output has a JSON bool and a JSON int, so normalise before
    // comparing (CalendarPatch's JoinerServiceSetupWiringTest pins the same
    // comparison for its extension).
    if ($type === 'class_extensions') {
        $pairKey = static fn (string $from, string $to): string => "$from\0$to";
        $describePair = static fn (string $from, string $to): string
            => "from_class '$from' to_class '$to'";
        // Names every side the field is actually absent from, rather than the
        // first one asked: a field missing from both sides is a worse export
        // than one missing from either, and reads as neither if the message
        // stops at the first.
        $describeMissing = static function (string $field, bool $noOutput, bool $noData): string {
            $sides = [];
            if ($noOutput) {
                $sides[] = '_output';
            }
            if ($noData) {
                $sides[] = '_data';
            }
            return "$field missing from " . implode(' and ', $sides);
        };

        // The two content-checked fields are one comparison, so they are written
        // once: each side declares the shapes its export actually writes, and
        // anything else is refused rather than cast. Casting was how a value
        // that went missing kept comparing equal to a legitimate one — false
        // and 0 are what a cast of nothing lands on, and they are also real
        // values of these two columns. Reading the shape instead closes that
        // for a JSON null, a quoted "false" (truthy, so it used to read as
        // enabled) and any other value no exporter produces alike.
        // A normaliser returns null for a shape it does not recognise; absence
        // is settled before they run, so null here only ever means malformed.
        $fieldChecks = [
            // _output json_encodes the tinyint as a JSON bool; _data spells the
            // same column as the XML string "1" or "0".
            'active' => [
                'output' => static fn ($raw) => is_bool($raw) ? $raw : null,
                'data' => static function (string $raw): ?bool {
                    return $raw === '1' ? true : ($raw === '0' ? false : null);
                },
                'render' => static fn (bool $value): string => $value ? 'true' : 'false',
            ],
            // execute_order decides which extension wraps which when several
            // share a from_class, so a drift here changes what runs first
            // without changing any count or any pair. _output holds a JSON int,
            // _data the same number as an XML string.
            'execute_order' => [
                'output' => static fn ($raw) => is_int($raw) ? $raw : null,
                'data' => static function (string $raw): ?int {
                    return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null;
                },
                'render' => static fn (int $value): string => (string) $value,
            ],
        ];

        // Pair-keyed both ways, so this branch answers the count question itself
        // and skips the generic count guard below. That only holds while each
        // pair appears at most once per side, hence the two duplicate guards:
        // without them a duplicated row on either side would hide a missing one.
        $dataByPair = [];
        $mismatches = [];
        foreach ($records as $record) {
            $from = (string) $record['from_class'];
            $to = (string) $record['to_class'];
            $key = $pairKey($from, $to);
            if (isset($dataByPair[$key])) {
                $mismatches[] = '_data has more than one <extension> for '
                    . $describePair($from, $to);
                continue;
            }
            $dataByPair[$key] = ['record' => $record, 'matched' => false];
        }

        foreach ($items as $file) {
            $itemName = $file->getFilename();
            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($decoded)) {
                $mismatches[] = "$itemName: not readable as a JSON object";
                continue;
            }
            $from = (string) ($decoded['from_class'] ?? '');
            $to = (string) ($decoded['to_class'] ?? '');
            $key = $pairKey($from, $to);
            if (!isset($dataByPair[$key])) {
                $mismatches[] = "$itemName: " . $describePair($from, $to)
                    . ' has no matching _data <extension>';
                continue;
            }
            if ($dataByPair[$key]['matched']) {
                $mismatches[] = "$itemName: " . $describePair($from, $to)
                    . ' is claimed by more than one _output item';
                continue;
            }
            $dataByPair[$key]['matched'] = true;
            $record = $dataByPair[$key]['record'];

            foreach ($fieldChecks as $field => $check) {
                // One absence test for both fields and both sides. `?? null`
                // rather than array_key_exists(): a key whose value is a JSON
                // null is a value that went missing, not a value of false, and
                // an export that wrote the column would not have left it there.
                $rawOutput = $decoded[$field] ?? null;
                $rawData = isset($record[$field]) ? (string) $record[$field] : null;
                if ($rawOutput === null || $rawData === null) {
                    $mismatches[] = "$itemName: "
                        . $describeMissing($field, $rawOutput === null, $rawData === null);
                    continue;
                }

                $outValue = $check['output']($rawOutput);
                $dataValue = $check['data']($rawData);
                if ($outValue === null || $dataValue === null) {
                    $sides = [];
                    if ($outValue === null) {
                        $sides[] = '_output=' . json_encode($rawOutput);
                    }
                    if ($dataValue === null) {
                        $sides[] = '_data=' . json_encode($rawData);
                    }
                    $mismatches[] = "$itemName: $field " . implode(' and ', $sides)
                        . ' is not a value any export writes';
                    continue;
                }

                if ($outValue !== $dataValue) {
                    $mismatches[] = "$itemName: $field _output=" . $check['render']($outValue)
                        . ' vs _data=' . $check['render']($dataValue);
                }
            }
        }

        // The reverse direction, reported per row rather than left to be inferred
        // from a count: a _data record no _output item claimed is named outright.
        foreach ($dataByPair as $entry) {
            if ($entry['matched']) {
                continue;
            }
            $record = $entry['record'];
            $mismatches[] = '_data <extension> '
                . $describePair((string) $record['from_class'], (string) $record['to_class'])
                . ' has no matching _output item';
        }

        if ($mismatches) {
            $errors[] = "$type: content mismatch (" . implode('; ', $mismatches) . ')';
            continue;
        }

        $report[] = "  $type: $countOutput item(s), content matches (content-checked)";
        continue;
    }

    // Every type but class_extensions is count-guarded first. For the count-only
    // types this is the whole check; for the exact-id types below it is what
    // catches a duplicated record, which the id comparison cannot see because
    // array_diff collapses duplicates.
    if ($countOutput !== $countData) {
        $errors[] = "$type: _output has $countOutput item(s), _data has $countData (run xf-addon:export?)";
        continue;
    }

    if (!isset($exactTypes[$type])) {
        $report[] = "  $type: $countOutput item(s), counts match (count-checked)";
        continue;
    }

    $ext = '.' . $exactTypes[$type]['ext'];
    $attr = $exactTypes[$type]['attr'];

    $outputIds = array_map(static function ($file) use ($ext) {
        $base = $file->getFilename();
        return str_ends_with($base, $ext) ? substr($base, 0, -strlen($ext)) : $base;
    }, $items);

    // Built by foreach, not iterator_to_array(): SimpleXML keys every same-named
    // sibling (<option>, <phrase>, ...) under one array key, which would collapse
    // them to a single record.
    $dataIds = [];
    foreach ($records as $record) {
        $dataIds[] = (string) $record[$attr];
    }

    sort($outputIds);
    sort($dataIds);
    $onlyOutput = array_diff($outputIds, $dataIds);
    $onlyData = array_diff($dataIds, $outputIds);

    if ($onlyOutput || $onlyData) {
        $detail = [];
        if ($onlyOutput) {
            $detail[] = 'only in _output: ' . implode(', ', $onlyOutput);
        }
        if ($onlyData) {
            $detail[] = 'only in _data: ' . implode(', ', $onlyData);
        }
        $errors[] = "$type: id mismatch (" . implode('; ', $detail) . ')';
        continue;
    }

    $report[] = "  $type: $countOutput item(s), ids match";
}

// The walk above only ever reaches a type that has been exported at least once,
// because _output/<type>/ appears only once that type holds a record. So it is
// blind to the first-record case: a type going from zero records to its first
// with xf-addon:export run and xf-dev:export not. Reconcile from the other side.
//
// This is a set difference over the same forward mapping, deliberately not an
// inversion of it. Inverting means guessing a directory name from a file name,
// and a wrong guess reports a type as never exported while its directory sits
// right there — a failure over nothing. Here only directory names that really
// exist are ever resolved, so there is nothing to guess: whatever the walk did
// not claim was not exported. $outputDirFor below names the absent directory in
// the message and decides nothing, so an incomplete map costs a wrong suggestion
// rather than a wrong verdict.
$outputDirFor = array_flip($dataFileFor);

foreach (is_dir($dataRoot) ? glob("$dataRoot/*.xml") : [] as $xmlFile) {
    $dataBase = basename($xmlFile, '.xml');
    if (isset($dataFilesSeen[$dataBase])) {
        continue;
    }
    $doc = @simplexml_load_file($xmlFile);
    if ($doc === false) {
        $errors[] = "_data/$dataBase.xml is not readable as XML";
        continue;
    }
    // _data carries a file for every type whether or not it holds rows, so an
    // empty one here is the normal state of an unused type, not a finding.
    $countData = count($doc->children());
    if ($countData === 0) {
        continue;
    }
    $type = $outputDirFor[$dataBase] ?? $dataBase;
    // xf-dev:export, not xf-addon:export: _output is the side that is missing,
    // and that is the command that writes it.
    $errors[] = "$dataBase: _data has $countData record(s) but _output/$type/ is missing"
        . ' (run xf-dev:export?)';
}

if ($errors) {
    fwrite(STDERR, "FAIL $name\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

// Reached only once the _data side has been walked and found nothing to report,
// so this says "no _output tree and no _data records to miss", not "no _output
// tree, so nothing was looked at".
if (!$hasOutputTree) {
    echo "SKIP $name: no _output/ tree\n";
    exit(0);
}

echo "OK $name\n";
foreach ($report as $line) {
    echo "$line\n";
}
exit(0);
