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
        $mods[] = [
            'template' => (string) $mod['template'],
            'enabled'  => (string) $mod['enabled'],
            'find'     => (string) $mod->find,
            'replace'  => (string) $mod->replace,
        ];
    }
}

// Each profile date cell must move from the viewer-timezone date() function to
// the vendor's UTC getter, so the profile reads the same for everyone and agrees
// with the edit form (which already uses the getter).
$expectedMods = [
    [
        'findContains'    => "date(\$record.record_date, 'Y-m-d')",
        'replaceContains' => '{$record.getRecordDate()}',
    ],
    [
        'findContains'    => "date(\$award.award_date, 'Y-m-d')",
        'replaceContains' => '{$award.getAwardDate()}',
    ],
];

foreach ($expectedMods as $want) {
    $match = null;
    foreach ($mods as $mod) {
        if (str_contains($mod['find'], $want['findContains'])) {
            $match = $mod;
            break;
        }
    }
    check(
        'a modification targets ' . $want['findContains'],
        $match !== null
    );
    if ($match !== null) {
        check(
            $want['findContains'] . ' targets the profile template (nf_rosters_user_view) and is enabled',
            $match['template'] === 'nf_rosters_user_view' && $match['enabled'] === '1'
        );
        check(
            $want['findContains'] . ' is replaced with the UTC getter',
            str_contains($match['replace'], $want['replaceContains']),
            'got replace: ' . $match['replace']
        );
        check(
            $want['findContains'] . ' no longer renders via the viewer-timezone date() function',
            !str_contains($match['replace'], 'date('),
            'leaving date() in place keeps the per-viewer shift'
        );
    }
}

// _output template-modification files must agree with the _data count.
$tmodOutput = glob("$root/_output/template_modifications/public/*.json");
check(
    '_output has one template-modification file per _data modification',
    count($tmodOutput) === ($tmodXml !== false ? count($tmodXml->modification) : -1),
    'output: ' . count($tmodOutput) . ', data: ' . ($tmodXml !== false ? count($tmodXml->modification) : 'n/a')
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
