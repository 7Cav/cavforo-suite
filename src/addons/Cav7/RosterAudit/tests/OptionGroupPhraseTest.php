<?php

/**
 * Issue #28 — the option-group title and description must use the standard
 * XenForo phrase titles so Setup → Options shows readable text, not raw keys.
 *
 * XenForo renders an option group from phrases titled option_group.<group_id>
 * and option_group_description.<group_id>. This addon originally shipped them as
 * opt_group_title.<group_id> / opt_group_description.<group_id>, which the
 * option_group.* lookup never finds, so the ACP printed the bare key.
 *
 * This pins the shipped phrase titles: the standard option_group.* pair must be
 * present and the old opt_group_* pair must be gone, so a regression (or a copy
 * of the old wrong shape) fails CI instead of silently shipping raw keys.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/OptionGroupPhraseTest.php
 */

namespace Cav7\RosterAudit\Tests;

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

$groupId = 'cav7RosterAudit';

$xml = simplexml_load_file(__DIR__ . '/../_data/phrases.xml');
check('_data/phrases.xml could be read', $xml !== false);

$titles = [];
if ($xml !== false) {
    foreach ($xml->phrase as $phrase) {
        $titles[] = (string) $phrase['title'];
    }
}

check(
    "option_group.$groupId is present",
    in_array("option_group.$groupId", $titles, true)
);
check(
    "option_group_description.$groupId is present",
    in_array("option_group_description.$groupId", $titles, true)
);
check(
    "old opt_group_title.$groupId is gone",
    !in_array("opt_group_title.$groupId", $titles, true)
);
check(
    "old opt_group_description.$groupId is gone",
    !in_array("opt_group_description.$groupId", $titles, true)
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
