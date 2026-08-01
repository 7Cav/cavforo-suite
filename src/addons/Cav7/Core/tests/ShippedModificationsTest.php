<?php

/**
 * Issue #171 — reading what an add-on ships in its
 * `_data/template_modifications.xml`.
 *
 * The check reconciles the board's records against this file rather than the
 * other way round, so this read is where "the suite ships eleven modifications"
 * comes from. Everything downstream is scoped by it: a read that quietly
 * returns nothing turns the whole command into a green run that checked
 * nothing, which is the shape of silence the issue exists to remove.
 *
 * Only the refusal contract is covered here. Whether the returned modification
 * is in force on a board needs a live XenForo, and is verified by hand on the
 * dev stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ShippedModificationsTest.php
 */

namespace Cav7\Core\Tests;

use Cav7\Core\TemplateModification\DiscoveryFailed;
use Cav7\Core\TemplateModification\ShippedModifications;

require __DIR__ . '/../TemplateModification/DiscoveryFailed.php';
require __DIR__ . '/../TemplateModification/ShippedModifications.php';

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

// Fixtures are written here rather than pointed at a committed add-on's file:
// asserting against the suite's real data would break when somebody widens a
// find or renames a key, neither of which changes what this read does.
$dir = sys_get_temp_dir() . '/cav7-core-shipped-' . getmypid();
@mkdir($dir, 0777, true);
register_shutdown_function(function () use ($dir) {
    foreach (glob("$dir/*.xml") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
});

function fixture(string $dir, string $name, string $xml): string
{
    $path = "$dir/$name.xml";
    file_put_contents($path, $xml);
    return $path;
}

// =========================================================================
// the refusal contract
//
// An add-on that ships no modifications and an add-on whose file will not parse
// look identical to a reader that answers "nothing" to both, and they are
// opposite situations: eight of the suite's add-ons ship no modifications and
// are fine, while a file that will not parse means this run cannot know what
// that add-on ships and must not report on it either way.
// =========================================================================
check(
    'an add-on with no modifications file ships nothing',
    ShippedModifications::read("$dir/absent.xml", 'Cav7/Core') === [],
    'most of the suite has no such file, and that is not a fault'
);

check(
    'an empty container ships nothing',
    ShippedModifications::read(
        fixture($dir, 'empty', '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<template_modifications/>'),
        'Cav7/Core'
    ) === [],
    'this is what xf-addon:export writes for an add-on that owns no modifications'
);

$refused = false;
try {
    ShippedModifications::read(
        fixture($dir, 'malformed', '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<template_modifications><modification template="member_view">'),
        'Cav7/Core'
    );
} catch (DiscoveryFailed $e) {
    $refused = true;
}
check(
    'a file that will not parse is refused rather than read as empty',
    $refused,
    'read as empty it becomes an add-on that ships nothing, so every modification it does ship goes unchecked and the run still exits 0 — the command would then be reporting silence as health, which is the fault it exists to catch'
);

// =========================================================================
// that the read returns anything at all
//
// Without this the three checks above all pass against a reader that always
// answers "nothing", and such a reader scopes the entire command to nothing
// while every one of its runs looks green. The fixture is deliberately minimal:
// what is asserted is that a shipped modification survives the read and can be
// told apart from another, not how XenForo spells its export.
// =========================================================================
$read = ShippedModifications::read(
    fixture($dir, 'two', '<?xml version="1.0" encoding="utf-8"?>' . "\n"
        . '<template_modifications>' . "\n"
        . '  <modification type="public" template="member_view" modification_key="firstKey" action="str_replace">'
        . '<find><![CDATA[a]]></find><replace><![CDATA[b]]></replace></modification>' . "\n"
        . '  <modification type="email" template="watched_thread" modification_key="secondKey" action="str_replace">'
        . '<find><![CDATA[c]]></find><replace><![CDATA[d]]></replace></modification>' . "\n"
        . '</template_modifications>'),
    'Cav7/Example'
);

check(
    'every modification in the file is read',
    count($read) === 2,
    'a reader that drops one silently narrows what the board is asked about, and the dropped one is then reported in force by never being reported at all'
);

$keys = array_column($read, 'modification_key');
sort($keys);
check(
    'each is named by its own modification key',
    $keys === ['firstKey', 'secondKey'],
    'the key is what a shipped modification is matched to the board record by, so two modifications that cannot be told apart cannot be reconciled'
);

$first = null;
foreach ($read as $modification) {
    if ($modification['modification_key'] === 'firstKey') {
        $first = $modification;
    }
}
check(
    'a modification carries the template copy it targets, and the add-on shipping it',
    $first !== null
        && $first['type'] === 'public'
        && $first['template'] === 'member_view'
        && $first['addon_id'] === 'Cav7/Example',
    'the type and title are how the copies to check are found, and the add-on id is how an inactive owner is spotted; without them a modification can be read and still not be checkable'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
