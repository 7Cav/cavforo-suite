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
 * style type. For every type the record counts must agree. For options, phrases
 * and option_groups the item ids are also compared exactly, since the _output
 * filename is the id. For class_extensions the item content (from_class,
 * to_class, active) is compared against the matching _data <extension> record,
 * so a corrupted to_class or a flipped active fails even when the file count is
 * unchanged. Other types are count-checked only; the report says which is which,
 * so nothing is skipped silently.
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

    if ($countOutput !== $countData) {
        $errors[] = "$type: _output has $countOutput item(s), _data has $countData (run xf-addon:export?)";
        continue;
    }

    // class_extensions: content-checked, not just counted. Match each _output
    // item to its _data <extension> by from_class, then compare from_class,
    // to_class and active. _data stores active as the string "1"; _output as the
    // JSON bool true, so normalise active before comparing (CalendarPatch's
    // JoinerServiceSetupWiringTest pins the same comparison for its extension).
    if ($type === 'class_extensions') {
        $dataByFrom = [];
        foreach ($records as $record) {
            $dataByFrom[(string) $record['from_class']] = $record;
        }

        $mismatches = [];
        foreach ($items as $file) {
            $itemName = $file->getFilename();
            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($decoded)) {
                $mismatches[] = "$itemName: not readable as a JSON object";
                continue;
            }
            $from = (string) ($decoded['from_class'] ?? '');
            if (!isset($dataByFrom[$from])) {
                $mismatches[] = "$itemName: from_class '$from' has no matching _data <extension>";
                continue;
            }
            $record = $dataByFrom[$from];

            $diffs = [];
            $outTo = (string) ($decoded['to_class'] ?? '');
            $dataTo = (string) $record['to_class'];
            if ($outTo !== $dataTo) {
                $diffs[] = "to_class _output='$outTo' vs _data='$dataTo'";
            }
            // "1" and true are equal; a genuine true-vs-false difference is not.
            $outActive = (bool) ($decoded['active'] ?? null);
            $dataActive = ((string) $record['active'] === '1');
            if ($outActive !== $dataActive) {
                $diffs[] = 'active _output=' . ($outActive ? 'true' : 'false')
                    . ' vs _data=' . ($dataActive ? 'true' : 'false');
            }
            if ($diffs) {
                $mismatches[] = "$itemName: " . implode('; ', $diffs);
            }
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
