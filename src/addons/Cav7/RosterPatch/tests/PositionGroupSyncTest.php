<?php

/**
 * Pins the shape of the position group-sync fix so a regression fails CI rather
 * than silently shipping. The behaviour itself needs a live XenForo + NF/Rosters
 * to exercise; this is the part we can hold in place with no stack: the class
 * extension is registered, the hook is guarded on the right column and chains
 * the parent, and the holder query and change-set key match the vendor's.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/PositionGroupSyncTest.php
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

// --- class extension is registered ---
$extXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $extXml !== false);

$positionExtended = false;
if ($extXml !== false) {
    foreach ($extXml->extension as $ext) {
        if ((string) $ext['from_class'] === 'NF\Rosters\Entity\Position'
            && (string) $ext['to_class'] === 'Cav7\RosterPatch\NF\Rosters\Entity\Position'
            && (string) $ext['active'] === '1'
        ) {
            $positionExtended = true;
        }
    }
}
check('NF\Rosters\Entity\Position is extended and active', $positionExtended);

// --- the hook is guarded and chains the parent ---
$position = file_get_contents("$root/NF/Rosters/Entity/Position.php");
check('Position extension reads', $position !== false);
check('Position::_postSave is defined', (bool) preg_match('/function\s+_postSave/', $position));
check('Position::_postSave chains parent::_postSave()', str_contains($position, 'parent::_postSave()'));
check(
    "the re-sync is guarded on isChanged('extra_group_ids')",
    (bool) preg_match("/isChanged\\(\\s*'extra_group_ids'\\s*\\)/", $position),
    'an unguarded re-sync would fire on every unrelated position edit'
);

// --- holder query covers primary and secondary, key matches the vendor ---
$repo = file_get_contents("$root/Repository/PositionGroupSync.php");
check('PositionGroupSync repository reads', $repo !== false);
check(
    'holder query matches primary holders (position_id = ?)',
    (bool) preg_match('/position_id\s*=\s*\?/', $repo)
);
check(
    'holder query matches secondary holders (FIND_IN_SET on secondary_position_ids)',
    (bool) preg_match('/FIND_IN_SET\(\?,\s*(?:\w+\.)?secondary_position_ids\)/', $repo),
    'dropping the secondary arm would leave secondary-position holders unsynced'
);
check(
    'orphaned roster rows are excluded (INNER JOIN xf_user)',
    (bool) preg_match('/INNER JOIN\s+xf_user/i', $repo),
    'a roster row for a deleted user would make the group-change service throw mid-save'
);
check(
    "grants use the vendor change-set key prefix 'nfRostersPosition-'",
    str_contains($repo, "'nfRostersPosition-'"),
    'a different key would not overwrite the vendor snapshot, doubling grants'
);
check('reconcile applies via addUserGroupChange', str_contains($repo, 'addUserGroupChange'));
check(
    'a failed grant is captured, not ignored',
    (bool) preg_match('/if\s*\(\s*!\s*\$userGroupChange->addUserGroupChange/', $repo),
    'ignoring the service return reports a holder reconciled when its save failed'
);
check(
    'drift is measured against the recorded snapshot (xf_user_group_change)',
    str_contains($repo, 'xf_user_group_change') && str_contains($repo, 'getDriftedHolderUserIds'),
    'reporting/skip-no-op depends on comparing each holder to their recorded grant'
);
check(
    'comparison goes through the dependency-free GroupIdList primitive',
    str_contains($repo, 'GroupIdList::normalize'),
    'the equality primitive is extracted so it can be unit-tested without XF'
);

// --- the reconcile command honours its contract ---
$cli = file_get_contents("$root/Cli/Command/SyncPositionGroups.php");
check('SyncPositionGroups command reads', $cli !== false);
check(
    'command name matches the one the hook docblock points at',
    str_contains($cli, "'cav7-rosterpatch:sync-position-groups'")
        && str_contains($position, 'cav7-rosterpatch:sync-position-groups'),
    'the Position hook references this command by name; they must not drift'
);
check(
    'dry-run never applies (apply is gated on !$dryRun)',
    (bool) preg_match('/\$dryRun\s*\?\s*\[\]\s*:\s*\$repo->applyPositionGroups/', $cli),
    'a dry run that writes would mutate groups while claiming to preview'
);
check(
    'the command exits non-zero when any holder could not be reconciled',
    str_contains($cli, '$failedUserIds') && (bool) preg_match('/return 1;/', $cli),
    'a silent exit 0 on partial failure hides unreconciled members from cron'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
