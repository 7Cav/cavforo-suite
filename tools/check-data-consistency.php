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
 * style type. For most types the record counts must agree. For options, phrases
 * and option_groups the item ids are also compared exactly, since the _output
 * filename is the id. class_extensions is matched row by row on the
 * (from_class, to_class) pair instead of counted, so an addon may register
 * several extensions against one from_class (see
 * docs/adr/0003-canonical-class-extension-order.md for why that pair is the
 * identity); a row present on only one side, or a flipped active, fails even
 * when the file count is unchanged. Other types are count-checked only; the
 * report says which is which, so nothing is skipped silently.
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

// A code-only addon has no _output tree; there is nothing to cross-check.
if (!is_dir($outRoot)) {
    echo "SKIP $name: no _output/ tree\n";
    exit(0);
}

// _output type dir -> _data file basename, for the cases where they differ.
$dataFileFor = [
    'cron_entries' => 'cron',
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

foreach (glob("$outRoot/*", GLOB_ONLYDIR) as $typeDir) {
    $type = basename($typeDir);
    $items = $collectItems($typeDir);
    $countOutput = count($items);

    $dataBase = $dataFileFor[$type] ?? $type;
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
    // the (from_class, to_class) pair — XenForo groups extension rows by both
    // columns and builds the _output filename from both — so an addon may
    // register several extensions against one from_class and they stay distinct
    // here (issue #150). Each _output item is matched to its _data <extension>
    // on that pair; the remaining content, active, is then compared. _data
    // stores active as the string "1"; _output as the JSON bool true, so
    // normalise active before comparing (CalendarPatch's
    // JoinerServiceSetupWiringTest pins the same comparison for its extension).
    if ($type === 'class_extensions') {
        $pairKey = static fn (string $from, string $to): string => "$from\0$to";
        $describePair = static fn (string $from, string $to): string
            => "from_class '$from' to_class '$to'";

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

            // "1" and true are equal; a genuine true-vs-false difference is not.
            $outActive = (bool) ($decoded['active'] ?? null);
            $dataActive = ((string) $record['active'] === '1');
            if ($outActive !== $dataActive) {
                $mismatches[] = "$itemName: active _output=" . ($outActive ? 'true' : 'false')
                    . ' vs _data=' . ($dataActive ? 'true' : 'false');
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

if ($errors) {
    fwrite(STDERR, "FAIL $name\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

echo "OK $name\n";
foreach ($report as $line) {
    echo "$line\n";
}
exit(0);
