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
 * style type. Every type's record counts must agree, and on top of that count
 * each type is checked at one of three strengths, which the report names per
 * type so a weakly-checked type is visible rather than implied:
 *
 *   content-checked  the records are compared field by field, or byte for byte
 *                    where the payload is the file body. class_extensions,
 *                    phrases, templates, options and template_modifications.
 *   id-checked       the _output filename is compared to the id in _data and
 *                    nothing inside the record is read. option_groups.
 *   count-checked    only the record count agrees. Everything else: routes,
 *                    code_event_listeners, cron_entries, admin_navigation,
 *                    api_scopes and the types no add-on here uses yet.
 *
 * The count is what catches a duplicated record, which comparing ids alone
 * cannot see, so it is kept even where content is compared on top of it.
 *
 * The two trees are not key-for-key, which is why each content-checked type
 * states its own mapping rather than diffing whatever keys happen to match.
 * _data omits an attribute whose value is the empty string and only then; some
 * payloads live in a child element rather than an attribute; some take a
 * different shape entirely (an option's relations); and some fields exist on one
 * side only, either because _output encodes them structurally (a modification's
 * type is its directory, its key is its filename) or because _data simply does
 * not carry them. Comparing only the keys present on both sides would look
 * strict and quietly skip the payload, so the mapping is written out per type
 * and the report says which fields it covered.
 *
 * Each type directory's _metadata.json is verified too: every item indexed,
 * every index entry present, every hash the md5 of the file it names with
 * carriage returns stripped (XF\DevelopmentOutput::hashContents). That is the
 * one drift comparing the two trees cannot see — hand-edit both sides to agree
 * and they are consistent with each other and with nothing else.
 *
 * class_extensions is matched row by row on the (from_class, to_class) pair
 * instead of counted: xf_class_extension carries a UNIQUE KEY over exactly
 * those two columns, so one from_class may hold several extensions and the pair
 * is what identifies a row. A row present on only one side, or a disagreeing
 * execute_order or active, fails even when the file count is unchanged. An
 * absent execute_order or active is a mismatch too, named by the side it is
 * missing from, since 0 and false are both legitimate values — and so is a
 * value in a shape no export writes, which is refused rather than cast into
 * one of those two.
 *
 * The identity columns are held to that same standard, in both halves, before
 * they are used to match anything. Each must name a class on each side: absent,
 * JSON null or empty is a mismatch named by the column and the side it is
 * missing from, and a value in a shape no export writes is refused rather than
 * cast, exactly as for the two compared columns. The first three are one
 * condition because they are one claim — _data omits an attribute if and only if
 * its value is '', so an omitted attribute and an empty one cannot be told apart
 * there by construction, and XenForo marks both columns required on
 * XF\Entity\ClassExtension, so no row it would persist reaches any of them. The
 * shape half is not decoration: (string) false is '', so refusing emptiness
 * while casting whatever else turns up walks the column straight back to the
 * empty identity the check exists to catch.
 *
 * Checking identity only after using it as a key is what let a row with no
 * identity through: both sides read a column that names nothing as '', '' is a
 * key like any other so '' matched '', the two compared columns agreed, and the
 * run exited 0 saying content matches on a row identifying nothing (issue #200).
 * (docs/adr/0003-canonical-class-extension-order.md rests on the same pair
 * identity: it fixes the row order of a committed _data file as a byte
 * comparison of from_class then to_class, and leaves execute_order out of that
 * rule because the UNIQUE KEY over the pair means the exporter never reaches it
 * as a tiebreaker.)
 *
 * That walk runs from _output, which only ever reaches a type that has been
 * exported at least once (see docs/addon-format.md on what each tree holds). A
 * second pass runs from _data to cover the rest: any _data file holding records
 * that no _output directory claimed was never exported, and fails. It is what
 * catches a type going from zero records to its first with xf-addon:export run
 * and xf-dev:export not, and an addon that lost its whole _output tree while
 * _data still holds records. An empty _data file is not a finding, so an addon
 * with no records to miss still skips.
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

// Types whose whole payload is the file body: _output holds it as the entire
// file, _data as the record's element text. Comparing the two is a byte
// comparison with no field mapping in between, which is what makes these the
// cheapest types to check and the largest by volume. Listed here as a set of
// exact-id types that carry a body on top of the id comparison above.
$bodyTypes = ['phrases' => true];

// Types compared field by field. The two sides are not key-for-key, so each
// type states its own mapping rather than trusting the key names to line up:
//
//   dirAttr  a _data attribute that _output encodes as the subdirectory rather
//            than storing in the record, so a _data edit to it moves where the
//            file belongs while leaving every count alone.
//   keyAttr  the _data attribute _output encodes as the file name.
//   attrs    _data attributes, against the _output key of the same name, each
//            with the JSON shape that side is expected to hold.
//   children _data child elements, against the _output key of the same name.
//
// Only the fields named here are compared. Anything either side holds that is
// not in this table is not checked, which is the honest reading of the report
// line these types print.
$fieldTypes = [
    'template_modifications' => [
        'ext' => 'json',
        'dirAttr' => 'type',
        'keyAttr' => 'modification_key',
        'attrs' => [
            'template' => 'string',
            'description' => 'string',
            'execution_order' => 'int',
            'enabled' => 'bool',
            'action' => 'string',
        ],
        'children' => ['find' => 'string', 'replace' => 'string'],
    ],
    // XF\AddOn\DataType\Option::exportAddOnData(): the mapped attributes, then
    // <default_value> always, <edit_format_params> and <sub_options> only when
    // non-empty, then one <relation> child per group. _output spells the same
    // record as a flat JSON object, so six of its nine keys are child elements
    // on the _data side and one is a repeated element.
    'options' => [
        'ext' => 'json',
        'keyAttr' => 'option_id',
        'attrs' => [
            'edit_format' => 'string',
            'data_type' => 'string',
            'validation_class' => 'string',
            'validation_method' => 'string',
            'advanced' => 'bool',
        ],
        'children' => [
            'default_value' => 'string',
            'edit_format_params' => 'string',
            'sub_options' => 'lines',
        ],
        'repeated' => [
            'relations' => ['tag' => 'relation', 'key' => 'group_id', 'value' => 'display_order'],
        ],
    ],
];

// Text as the two sides can both actually represent it. XML normalises line
// endings on parse — \r\n and a lone \r both arrive as \n, in CDATA as much as
// anywhere else — so a carriage return cannot survive a round trip through
// _data, while _output holds the bytes as written. Comparing raw would report a
// drift on every file holding a \r and nowhere else, which is noise, not a
// finding. XenForo reaches the same conclusion for its own hashes:
// XF\DevelopmentOutput::hashContents() strips \r before md5 for this reason.
$normaliseText = static fn (string $text): string => str_replace("\r", '', $text);

// A _data attribute is written if and only if its value is not the empty
// string: XF\AddOn\DataType\AbstractDataType::exportMappedAttributes() skips on
// `$value !== ''` and casts a bool to 1/0 after that test, so false and 0 are
// both written and only '' goes missing. That makes an absent attribute a
// positive statement — the value is '' — rather than an unknown, and it is why
// this comparison can be strict without a table of per-field defaults.
$dataAttrValue = static function (SimpleXMLElement $record, string $attr): string {
    return isset($record[$attr]) ? (string) $record[$attr] : '';
};

// The _data spelling of an _output value. _data is XML, so every value reaches
// it as a string; returning null means _output held a shape no exporter writes,
// which is refused rather than cast — a cast is how a value that went missing
// used to compare equal to a legitimate false or 0.
$renderOutputValue = static function ($raw, string $shape) use ($normaliseText): ?string {
    switch ($shape) {
        // Normalised on this side too: a modification's find or replace can hold
        // a carriage return, and the _data half it is compared against has
        // already lost hers to the XML parser.
        case 'string':
            return is_string($raw) ? $normaliseText($raw) : null;
        case 'int':
            return is_int($raw) ? (string) $raw : null;
        case 'bool':
            return is_bool($raw) ? ($raw ? '1' : '0') : null;
        // A JSON list that _data flattens into one newline-joined element body
        // (an option's sub_options). The empty list is the omitted case, and
        // joining it lands on '' — the same value an absent element reads as —
        // so the two agree without a special case.
        case 'lines':
            if (!is_array($raw)) {
                return null;
            }
            foreach ($raw as $line) {
                if (!is_string($line)) {
                    return null;
                }
            }
            return $normaliseText(implode("\n", $raw));
    }
    return null;
};

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

// An item's path under its type directory, in the spelling _metadata.json and
// the _data-derived paths both use: forward slashes, no leading separator. Three
// places need it — the metadata index, the templates walk and the field-checked
// walk — and they have to agree on it exactly, so it is derived once.
$relativePath = static function (string $typeDir, SplFileInfo $file): string {
    return str_replace(
        DIRECTORY_SEPARATOR,
        '/',
        substr($file->getPathname(), strlen($typeDir) + 1)
    );
};

$errors = [];
$report = [];

// Open a _data file, recording the same failure whichever pass asked for it.
// Returns false once the error is recorded, so callers only skip.
$loadDataDoc = static function (string $xmlFile, string $dataBase, array &$errors) {
    $doc = @simplexml_load_file($xmlFile);
    if ($doc === false) {
        $errors[] = "_data/$dataBase.xml is not readable as XML";
    }
    return $doc;
};

// Every _data basename some _output type dir claimed, filled in by the walk
// below and read by the _data-side pass after it.
$dataFilesSeen = [];

$typeDirs = $hasOutputTree ? glob("$outRoot/*", GLOB_ONLYDIR) : [];

// The exporter's own index of a type directory, verified against what is
// actually on disk. Every _output type dir carries one, written by
// XF\DevelopmentOutput, mapping each item's path under the dir to a hash of its
// contents. It answers a question the _data comparison structurally cannot:
// _data-vs-_output says the two trees agree with each other, and two hand-edited
// trees agree with each other perfectly while agreeing with the database not at
// all. A stale hash is the only trace that leaves.
//
// The hash is md5 of the contents with carriage returns stripped —
// XF\DevelopmentOutput::hashContents(). md5_file() matches it only until a file
// holds a \r, at which point it disagrees on exactly the files that have one.
$checkMetadata = static function (string $typeDir, array $items) use ($normaliseText, $relativePath): array {
    $indexFile = "$typeDir/_metadata.json";
    if (!is_file($indexFile)) {
        return ['_metadata.json is missing'];
    }
    $index = json_decode((string) file_get_contents($indexFile), true);
    if (!is_array($index)) {
        return ['_metadata.json is not readable as a JSON object'];
    }

    $problems = [];
    foreach ($items as $file) {
        $relative = $relativePath($typeDir, $file);
        if (!isset($index[$relative])) {
            $problems[] = "$relative is not indexed in _metadata.json";
            continue;
        }
        $recorded = $index[$relative]['hash'] ?? null;
        unset($index[$relative]);
        if (!is_string($recorded)) {
            $problems[] = "$relative has no hash in _metadata.json";
            continue;
        }
        $actual = md5($normaliseText((string) file_get_contents($file->getPathname())));
        if ($recorded !== $actual) {
            $problems[] = "$relative has hash $recorded in _metadata.json but hashes to $actual";
        }
    }

    // Whatever the index still claims after every item has been struck off.
    foreach ($index as $relative => $ignored) {
        $problems[] = "_metadata.json indexes $relative, which is not there";
    }

    return $problems;
};

foreach ($typeDirs as $typeDir) {
    $type = basename($typeDir);
    $items = $collectItems($typeDir);
    $countOutput = count($items);

    // Claimed before anything about this type can fail. Bailing out earlier left
    // the _data-side pass below reporting that no _output directory claimed the
    // file, which is a plain untruth when the directory is sitting right there —
    // one real finding dragging a false one behind it.
    $dataBase = $dataFileFor[$type] ?? $type;
    $dataFilesSeen[$dataBase] = true;

    // Recorded, not returned on. A stale index and a genuine content drift are
    // separate findings about separate things, and stopping at the first would
    // hide the second until someone re-exported and ran the tool again.
    $metadataProblems = $checkMetadata($typeDir, $items);
    if ($metadataProblems) {
        $errors[] = "$type: export index mismatch (" . implode('; ', $metadataProblems) . ')';
    }

    $xmlFile = "$dataRoot/$dataBase.xml";
    if (!is_file($xmlFile)) {
        $errors[] = "_output/$type/ has $countOutput item(s) but _data/$dataBase.xml is missing";
        continue;
    }
    $doc = $loadDataDoc($xmlFile, $dataBase, $errors);
    if ($doc === false) {
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
    // comparing. This is the one place the comparison lives; every add-on gets it
    // from here rather than restating it in its own tests.
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

        // Whether an identity column names a class, as one rule both trees are
        // read through. Writing the test twice is how the two sides drifted
        // apart once already: each looked right beside its own tree, and only
        // one of them refused a shape no export writes.
        //
        // 'missing' — absent, JSON null and '' are one answer because they are
        // one claim. _data omits an attribute if and only if its value is '',
        // so it cannot tell those apart by construction, and XenForo marks both
        // columns required on XF\Entity\ClassExtension, so no row it persists
        // reaches any of them.
        // 'shape'   — anything else, refused rather than cast, for the reason
        // the compared columns refuse one: (string) false is '', so casting
        // walks a column that names nothing right back to the empty identity
        // this check exists to catch. Only _output can answer this, since _data
        // arrives from the XML parser as a string either way.
        $identityFault = static function ($raw): ?string {
            if ($raw === null || $raw === '') {
                return 'missing';
            }
            return is_string($raw) ? null : 'shape';
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
        $recordNumber = 0;
        foreach ($records as $record) {
            // The _output side of this test is below; the reasoning is written
            // out there. The standard has to be the same on both, or the check
            // is strict about identity in one tree and not the other. There is
            // no file name to name the offender by on this side, so the record
            // is named by its position among the <extension> elements — the
            // pair that would otherwise identify it is the thing that is gone.
            $recordNumber++;
            $identityAbsent = false;
            foreach (['from_class', 'to_class'] as $idField) {
                $raw = isset($record[$idField]) ? (string) $record[$idField] : null;
                if ($identityFault($raw) !== null) {
                    $mismatches[] = "_data <extension> #$recordNumber: "
                        . $describeMissing($idField, false, true);
                    $identityAbsent = true;
                }
            }
            if ($identityAbsent) {
                continue;
            }

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
            // The identity columns, held to the standard the compared columns
            // are already held to. A row has to name both classes, and reading
            // them straight through a cast turns a column that names nothing
            // into the empty string — which is a key like any other, matching
            // whatever else lost the same column. Settling that before the pair
            // is built is what stops a row identifying nothing from matching
            // another one, and what makes the report say the column is not
            // there rather than blaming a class name for failing to match.
            //
            // Absent, JSON null and '' are one condition rather than three,
            // because they are one claim: the column names no class. `?? ''`
            // collapses the first two onto the third, and the same rule reads
            // the _data side above, where the exporter's own omit-iff-empty
            // makes an absent attribute and an empty one indistinguishable by
            // construction.
            $identityAbsent = false;
            foreach (['from_class', 'to_class'] as $idField) {
                $fault = $identityFault($decoded[$idField] ?? null);
                if ($fault === 'missing') {
                    $mismatches[] = "$itemName: " . $describeMissing($idField, true, false);
                    $identityAbsent = true;
                } elseif ($fault === 'shape') {
                    $mismatches[] = "$itemName: $idField _output="
                        . json_encode($decoded[$idField])
                        . ' is not a value any export writes';
                    $identityAbsent = true;
                }
            }
            if ($identityAbsent) {
                continue;
            }

            $from = (string) $decoded['from_class'];
            $to = (string) $decoded['to_class'];
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

        $report[] = "  $type: $countOutput item(s), content matches"
            . ' (content-checked: from_class, to_class, execute_order, active)';
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

    // templates: content-checked, on a path derived from _data rather than on a
    // filename read back off the tree. A template record's identity is the
    // (type, title) pair, and only the title survives into the _output file
    // name — the type is the directory. Rebuilding the path from both is what
    // lets a _data type flip fail: it moves where the record should be while
    // leaving every count alone, so nothing downstream of a count can see it.
    if ($type === 'templates') {
        // XF\DevelopmentOutput\Template::convertTemplateNameToFile(): .html is
        // appended unless the title already holds a dot. strpos(), not
        // str_contains() — a leading dot sits at offset 0, which is falsy, and
        // XenForo appends .html in that case too. Matching its quirk matters
        // more than tidying it: this has to name the file XenForo actually
        // wrote, not the one it arguably should have.
        $templateFile = static fn (string $title): string
            => strpos($title, '.') ? $title : "$title.html";

        $dataByPath = [];
        $mismatches = [];
        foreach ($records as $record) {
            $title = (string) $record['title'];
            $templateType = (string) $record['type'];
            $path = $templateType . '/' . $templateFile($title);
            if (isset($dataByPath[$path])) {
                $mismatches[] = "_data has more than one <template> for $path";
                continue;
            }
            $dataByPath[$path] = $normaliseText((string) $record);
        }

        foreach ($items as $file) {
            $path = $relativePath($typeDir, $file);
            if (!array_key_exists($path, $dataByPath)) {
                $mismatches[] = "$path: no _data <template> claims this path";
                continue;
            }
            $outputText = $normaliseText((string) file_get_contents($file->getPathname()));
            if ($outputText !== $dataByPath[$path]) {
                $mismatches[] = "$path: _output body differs from _data ("
                    . strlen($outputText) . ' vs ' . strlen($dataByPath[$path]) . ' bytes)';
            }
            unset($dataByPath[$path]);
        }

        // Whatever no _output file claimed. With the counts already equal this
        // only fires alongside an unclaimed _output path, but naming both ends
        // of the swap is what makes a type flip readable as a move.
        foreach ($dataByPath as $path => $ignored) {
            $mismatches[] = "$path: _data <template> has no _output file";
        }

        if ($mismatches) {
            $errors[] = "$type: content mismatch (" . implode('; ', $mismatches) . ')';
            continue;
        }

        $report[] = "  $type: $countOutput item(s), paths and bodies match (content-checked)";
        continue;
    }

    // Field-checked types. Same shape as the templates branch above — the path
    // each record belongs at is rebuilt from _data and then the fields behind it
    // are compared — but the payload is a JSON object rather than a body.
    if (isset($fieldTypes[$type])) {
        $spec = $fieldTypes[$type];
        $mismatches = [];

        $dataByPath = [];
        foreach ($records as $record) {
            $key = (string) $record[$spec['keyAttr']];
            $path = isset($spec['dirAttr'])
                ? ((string) $record[$spec['dirAttr']]) . "/$key." . $spec['ext']
                : "$key." . $spec['ext'];
            if (isset($dataByPath[$path])) {
                $mismatches[] = "_data has more than one record for $path";
                continue;
            }
            $dataByPath[$path] = $record;
        }

        foreach ($items as $file) {
            $path = $relativePath($typeDir, $file);
            if (!isset($dataByPath[$path])) {
                $mismatches[] = "$path: no _data record claims this path";
                continue;
            }
            $record = $dataByPath[$path];
            unset($dataByPath[$path]);

            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($decoded)) {
                $mismatches[] = "$path: not readable as a JSON object";
                continue;
            }

            // Attributes and child elements differ only in where the _data half
            // is read from, so they are compared by one loop over both.
            $fields = [];
            foreach ($spec['attrs'] as $field => $shape) {
                $fields[$field] = [$shape, $dataAttrValue($record, $field)];
            }
            foreach ($spec['children'] ?? [] as $field => $shape) {
                $fields[$field] = [
                    $shape,
                    isset($record->$field) ? $normaliseText((string) $record->$field) : '',
                ];
            }

            foreach ($fields as $field => [$shape, $dataValue]) {
                if (!array_key_exists($field, $decoded)) {
                    $mismatches[] = "$path: $field missing from _output";
                    continue;
                }
                $outputValue = $renderOutputValue($decoded[$field], $shape);
                if ($outputValue === null) {
                    $mismatches[] = "$path: $field _output=" . json_encode($decoded[$field])
                        . ' is not a value any export writes';
                    continue;
                }
                if ($outputValue !== $dataValue) {
                    $mismatches[] = "$path: $field _output=" . json_encode($outputValue)
                        . ' vs _data=' . json_encode($dataValue);
                }
            }

            // Repeated child elements against the JSON map holding the same
            // pairs. Both sides are reduced to a key => value map of strings and
            // sorted, so the comparison answers "the same relations, with the
            // same display orders" without resting on document order, which
            // neither side promises.
            foreach ($spec['repeated'] ?? [] as $field => $repeat) {
                if (!array_key_exists($field, $decoded)) {
                    $mismatches[] = "$path: $field missing from _output";
                    continue;
                }
                if (!is_array($decoded[$field])) {
                    $mismatches[] = "$path: $field _output=" . json_encode($decoded[$field])
                        . ' is not a value any export writes';
                    continue;
                }

                $dataPairs = [];
                foreach ($record->{$repeat['tag']} as $child) {
                    $dataPairs[(string) $child[$repeat['key']]] = (string) $child[$repeat['value']];
                }
                $outputPairs = [];
                foreach ($decoded[$field] as $childKey => $childValue) {
                    $outputPairs[(string) $childKey] = is_scalar($childValue)
                        ? (string) $childValue
                        : json_encode($childValue);
                }
                ksort($dataPairs);
                ksort($outputPairs);

                if ($outputPairs !== $dataPairs) {
                    $mismatches[] = "$path: $field _output=" . json_encode($outputPairs)
                        . ' vs _data=' . json_encode($dataPairs);
                }
            }
        }

        foreach ($dataByPath as $path => $ignored) {
            $mismatches[] = "$path: _data record has no _output item";
        }

        if ($mismatches) {
            $errors[] = "$type: content mismatch (" . implode('; ', $mismatches) . ')';
            continue;
        }

        $compared = implode(', ', array_merge(
            array_keys($spec['attrs']),
            array_keys($spec['children'] ?? []),
            array_keys($spec['repeated'] ?? [])
        ));
        $report[] = "  $type: $countOutput item(s), content matches (content-checked: $compared)";
        continue;
    }

    // Says what it did not do as well as what it did. A type listed here has had
    // nothing inside its records compared, and the difference between that and a
    // content-checked type is the whole point of the line.
    if (!isset($exactTypes[$type])) {
        $report[] = "  $type: $countOutput item(s), counts match"
            . ' (count-checked: no field inside a record is compared)';
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

    // Ids agree as a set and the counts agree, so each id names exactly one
    // record on each side and the two can be paired by id alone. For a body
    // type that pairing is the whole mapping: compare the bytes.
    if (isset($bodyTypes[$type])) {
        $dataById = [];
        foreach ($records as $record) {
            $dataById[(string) $record[$attr]] = $record;
        }

        $drift = [];
        foreach ($items as $file) {
            $base = $file->getFilename();
            $id = str_ends_with($base, $ext) ? substr($base, 0, -strlen($ext)) : $base;
            $outputText = $normaliseText((string) file_get_contents($file->getPathname()));
            $dataText = $normaliseText((string) $dataById[$id]);
            if ($outputText !== $dataText) {
                $drift[] = "$id: _output text differs from _data ("
                    . strlen($outputText) . ' vs ' . strlen($dataText) . ' bytes)';
            }
        }

        if ($drift) {
            $errors[] = "$type: content mismatch (" . implode('; ', $drift) . ')';
            continue;
        }

        $report[] = "  $type: $countOutput item(s), ids and text match (content-checked)";
        continue;
    }

    $report[] = "  $type: $countOutput item(s), ids match"
        . ' (id-checked: no field inside a record is compared)';
}

// The second pass described at the top of this file. It is a set difference over
// the forward mapping rather than an inversion of it: whatever the walk above did
// not claim, nothing exported. Inverting the map would mean deciding the verdict
// from a guessed directory name. $outputDirFor only phrases the message, so a row
// missing from the map costs a wrong suggestion rather than a wrong verdict.
$outputDirFor = array_flip($dataFileFor);

$dataFiles = is_dir($dataRoot) ? glob("$dataRoot/*.xml") : [];

foreach ($dataFiles as $xmlFile) {
    $dataBase = basename($xmlFile, '.xml');
    if (isset($dataFilesSeen[$dataBase])) {
        continue;
    }
    $doc = $loadDataDoc($xmlFile, $dataBase, $errors);
    if ($doc === false) {
        continue;
    }
    // _data carries a file for every type whether or not it holds rows, so an
    // empty one here is the normal state of an unused type, not a finding.
    $countData = count($doc->children());
    if ($countData === 0) {
        continue;
    }
    // The directory this file's records belong in. It is normally absent, which
    // is the whole finding — but a _data file XenForo never writes (a stale
    // hand-made one, or one named after the _output directory rather than after
    // its own container tag) resolves to a directory that does exist, and saying
    // it is missing would be a lie. Name the file as the anomaly instead.
    $type = $outputDirFor[$dataBase] ?? $dataBase;
    if ($hasOutputTree && is_dir("$outRoot/$type")) {
        $errors[] = "$dataBase: _data has $countData record(s) but no _output type dir claims"
            . " _data/$dataBase.xml (_output/$type/ is matched to _data/"
            . ($dataFileFor[$type] ?? $type) . '.xml)';
        continue;
    }
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
