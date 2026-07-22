<?php

/**
 * Pins the shape of the milpac date fix (issue #43) so a regression fails CI
 * rather than silently shipping. The behaviour of the date logic itself is
 * exercised for real in MilpacDateTest; this holds the vendor-coupled wiring in
 * place with no stack: the class extensions are registered, the entity hooks
 * floor the date and chain the parent, the controller rejects a bad day and
 * defers the save to the vendor, and the profile template renders the date in
 * UTC.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/MilpacDateWiringTest.php
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

// --- class extensions are registered and active ----------------------------
$extXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $extXml !== false);

$active = [];
if ($extXml !== false) {
    foreach ($extXml->extension as $ext) {
        if ((string) $ext['active'] === '1') {
            $active[(string) $ext['from_class']] = (string) $ext['to_class'];
        }
    }
}

$expectedExtensions = [
    'NF\Rosters\Entity\Position'          => 'Cav7\RosterPatch\NF\Rosters\Entity\Position',
    'NF\Rosters\Entity\RosterUserAward'   => 'Cav7\RosterPatch\NF\Rosters\Entity\RosterUserAward',
    'NF\Rosters\Entity\ServiceRecord'     => 'Cav7\RosterPatch\NF\Rosters\Entity\ServiceRecord',
    'NF\Rosters\Pub\Controller\Roster'    => 'Cav7\RosterPatch\NF\Rosters\Pub\Controller\Roster',
];
foreach ($expectedExtensions as $from => $to) {
    check(
        "$from is extended and active",
        ($active[$from] ?? null) === $to,
        'expected ' . $to . ', got ' . ($active[$from] ?? 'none')
    );
}

// The _output class-extension index must agree with the _data count, so the
// consistency check passes and the install registers every extension.
$extOutput = glob("$root/_output/class_extensions/*.json");
$extOutput = array_filter($extOutput, fn ($f) => basename($f) !== '_metadata.json');
check(
    '_output has one class-extension file per _data extension',
    count($extOutput) === ($extXml !== false ? count($extXml->extension) : -1),
    'output: ' . count($extOutput) . ', data: ' . ($extXml !== false ? count($extXml->extension) : 'n/a')
);

// --- entity hooks floor the date to midnight UTC and chain the parent -------
// Each entity also overrides the vendor's date getter to render the stored value
// in UTC, so display no longer follows the PHP process timezone (issue #47).
$dateGetters = ['award_date' => 'getAwardDate', 'record_date' => 'getRecordDate'];
foreach (['RosterUserAward' => 'award_date', 'ServiceRecord' => 'record_date'] as $entity => $column) {
    $src = file_get_contents("$root/NF/Rosters/Entity/$entity.php");
    check("$entity extension reads", $src !== false);
    check("$entity::_preSave is defined", (bool) preg_match('/function\s+_preSave/', (string) $src));
    check("$entity::_preSave chains parent::_preSave()", str_contains((string) $src, 'parent::_preSave()'));
    check(
        "$entity floors $column via MilpacDate::floorToMidnightUtc",
        str_contains((string) $src, 'MilpacDate::floorToMidnightUtc')
            && (bool) preg_match('/\\$this->' . $column . '\s*=/', (string) $src),
        'the entity must normalise its own date column'
    );
    check(
        "$entity floor is guarded on insert or a changed $column",
        (bool) preg_match('/isInsert\(\)\s*\|\|\s*\$this->isChanged\(\s*\'' . $column . '\'\s*\)/', (string) $src),
        'an unguarded floor would rewrite the date on every unrelated save'
    );
    $getter = $dateGetters[$column];
    // Bind MilpacDate::render to the getter's BODY reading the raw column, not
    // the class docblock (which also names MilpacDate::render). [^}]*? cannot
    // cross the getter's closing brace, so a body reverted to the vendor
    // date('Y-m-d', $this->column) form fails this even with the docblock intact.
    check(
        "$entity overrides $getter() to render $column in UTC via MilpacDate::render",
        (bool) preg_match(
            '/function\s+' . $getter . '\b[^}]*?MilpacDate::render\(\s*\(int\)\s*\\$this->' . $column . '\b/',
            (string) $src
        ),
        'the getter body must render the stored date via MilpacDate::render((int) $this->' . $column . '), so display no longer follows the process timezone'
    );
}

// --- controller rejects a bad day on save and defers the save to the vendor -
$ctrl = file_get_contents("$root/NF/Rosters/Pub/Controller/Roster.php");
check('Roster controller extension reads', $ctrl !== false);
$ctrl = (string) $ctrl;

foreach (['actionProfileAwardsSave', 'actionProfileServiceRecordSave'] as $action) {
    check("controller overrides $action", (bool) preg_match('/function\s+' . $action . '/', $ctrl));
    check("$action defers the save to the vendor (parent::$action)", str_contains($ctrl, "parent::$action("));
}
check(
    'a submitted day is validated via MilpacDate::parseEnteredDay',
    str_contains($ctrl, 'MilpacDate::parseEnteredDay'),
    'rejection must reuse the round-trip parser, not a second lenient parse'
);
check(
    'an invalid day returns the vendor invalid-date error',
    str_contains($ctrl, "\\XF::phrase('please_enter_valid_date')"),
    'a bad value must be rejected with the vendor message, not rolled over'
);

// --- controller prefills a new entry with the editor's own today -----------
foreach (['actionProfileAwardsAdd', 'actionProfileServiceRecordAdd'] as $action) {
    check("controller overrides $action", (bool) preg_match('/function\s+' . $action . '/', $ctrl));
}
check(
    "the prefill uses the editor's timezone (\\XF::language()->getTimeZone())",
    str_contains($ctrl, '\XF::language()->getTimeZone()'),
    "defaulting to UTC's today would reopen the wrong-day-for-non-UTC-staff bug"
);
check(
    'the prefill value comes from MilpacDate::editorTodayTimestamp',
    str_contains($ctrl, 'MilpacDate::editorTodayTimestamp')
);
check(
    'the prefill only touches a brand-new entry (isInsert)',
    str_contains($ctrl, '->isInsert()'),
    'prefilling an existing entry on edit would overwrite its stored date'
);

// --- the profile template renders both dates in UTC -------------------------
$tmodXml = @simplexml_load_file("$root/_data/template_modifications.xml");
check('_data/template_modifications.xml could be read', $tmodXml !== false);

$mods = [];
if ($tmodXml !== false) {
    foreach ($tmodXml->modification as $mod) {
        $mods[(string) $mod['modification_key']] = [
            'type'     => (string) $mod['type'],
            'template' => (string) $mod['template'],
            'enabled'  => (string) $mod['enabled'],
            'action'   => (string) $mod['action'],
            'find'     => (string) $mod->find,
            'replace'  => (string) $mod->replace,
        ];
    }
}

// Each profile date cell must move from the viewer-timezone date() function to
// the vendor's UTC getter, so the profile reads the same for everyone and agrees
// with the edit form (which already uses the getter). What the finds actually
// match is checked against real markup in MilpacDateTemplateModificationTest;
// this only holds the records themselves in shape.
$expectedMods = [
    'cav7RosterPatchRecordDateUtc' => [
        'column'          => '$record.record_date',
        'replaceContains' => '{$record.getRecordDate()}',
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'column'          => '$award.award_date',
        'replaceContains' => '{$award.getAwardDate()}',
    ],
];

foreach ($expectedMods as $key => $want) {
    $match = $mods[$key] ?? null;
    check(
        "a modification named $key exists",
        $match !== null
    );
    if ($match !== null) {
        // XenForo looks a modification up by type *and* template. An admin-type
        // record against a public template never fires, and nothing reports it:
        // the add-on still installs and still shows as active.
        check(
            "$key targets the public profile template (nf_rosters_user_view) and is enabled",
            $match['type'] === 'public'
                && $match['template'] === 'nf_rosters_user_view'
                && $match['enabled'] === '1',
            'got type: ' . $match['type'] . ', template: ' . $match['template']
                . ', enabled: ' . $match['enabled']
        );
        // 'date' on its own is satisfied by the 'date' inside 'record_date', so
        // require the call: 'date' followed by an escaped opening paren, with
        // the pattern's own \s* between them dropped first.
        $findNoSpacePattern = str_replace('\s*', '', $match['find']);
        check(
            "$key finds a date() call on the vendor's " . $want['column'],
            str_contains($findNoSpacePattern, 'date\(') && str_contains(
                str_replace('\\', '', $match['find']),
                $want['column']
            ),
            'got find: ' . $match['find']
        );
        check(
            "$key matches by pattern, not by exact vendor markup",
            $match['action'] === 'preg_replace',
            'got action: ' . $match['action']
                . ' — an exact find misses any style that edited the call, and misses silently'
        );
        check(
            "$key is replaced with the UTC getter",
            str_contains($match['replace'], $want['replaceContains']),
            'got replace: ' . $match['replace']
        );
        check(
            "$key no longer renders via the viewer-timezone date() function",
            !str_contains($match['replace'], 'date('),
            'leaving date() in place keeps the per-viewer shift'
        );
    }
}

// _output is what a dev-stack install imports, and what an export round-trips
// back into _data, so a count alone leaves every field inside those files free
// to drift: an item reverted to an exact str_replace find, pointed at another
// template, or switched off passes a count check and ships. Content-check each
// one against its _data record instead. _output carries the type in the
// directory name rather than in the file, and stores enabled as a JSON bool, so
// those two are compared through the shape _output uses.
$tmodDir = "$root/_output/template_modifications";
$tmodOutput = glob("$tmodDir/*/*.json");
check(
    '_output has one template-modification file per _data modification',
    count($tmodOutput) === ($tmodXml !== false ? count($tmodXml->modification) : -1),
    'output: ' . count($tmodOutput) . ', data: ' . ($tmodXml !== false ? count($tmodXml->modification) : 'n/a')
);

$tmodMeta = json_decode((string) @file_get_contents("$tmodDir/_metadata.json"), true);
check('_output/template_modifications/_metadata.json is valid JSON', is_array($tmodMeta));

foreach ($mods as $key => $want) {
    // The type is the directory: a record exported as admin/ (or renamed) has
    // no file here, and XenForo would never fire it against a public template.
    $file = $want['type'] . "/$key.json";
    $raw = @file_get_contents("$tmodDir/$file");
    $decoded = json_decode((string) $raw, true);

    check(
        "the _output export $file describes the same modification as _data",
        is_array($decoded)
            && ($decoded['template'] ?? null) === $want['template']
            && ($decoded['enabled'] ?? null) === ($want['enabled'] === '1')
            && ($decoded['action'] ?? null) === $want['action']
            && ($decoded['find'] ?? null) === $want['find']
            && ($decoded['replace'] ?? null) === $want['replace'],
        '_data and _output must agree on every field the install reads; got: '
            . var_export($decoded, true)
    );
    check(
        "_output/template_modifications/_metadata.json indexes $file",
        is_array($tmodMeta) && isset($tmodMeta[$file]),
        'an item file the index does not name was added by hand'
    );
    check(
        "_output/template_modifications/_metadata.json carries $file's current hash",
        is_array($tmodMeta) && ($tmodMeta[$file]['hash'] ?? null) === md5(str_replace("\r", '', (string) $raw)),
        'XenForo hashes an item as md5 of its contents with CRs stripped; a stale or blanked hash means the export was hand-edited'
    );
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
