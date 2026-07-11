<?php

/**
 * Pins the wiring of the wait-list join fix (issues #49/#50) so a regression
 * fails CI rather than shipping quietly. The vendor's JoinerService throws on
 * every instantiation: XF's AbstractService constructor calls setup() before the
 * subclass has assigned its typed $user property, and the vendor setup() reads
 * $user, so PHP throws "Typed property ... must not be accessed before
 * initialization". Our extension guards that premature call.
 *
 * Vendor NF code is not committed to this repo, so CI cannot boot the real
 * service; the behaviour is exercised on the dev stack instead. This test holds
 * the vendor-coupled wiring in place with no stack: the class extension is
 * registered and active in the install bundle, the development-tree export
 * agrees with the bundle, and the extension keeps its load-bearing shape:
 * overrides setup(), returns early while the $user property is uninitialised,
 * and chains parent::setup().
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/JoinerServiceSetupWiringTest.php
 */

namespace Cav7\CalendarPatch\Tests;

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

// --- the class extension is registered and active in the install bundle ------
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
    'NF\Calendar\Service\EventWaitList\JoinerService'
        => 'Cav7\CalendarPatch\NF\Calendar\Service\EventWaitList\JoinerService',
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

// The count alone would still pass with a corrupted from_class/to_class/active
// inside the exported item: neither this test nor check-data-consistency.php
// reads inside a class_extensions JSON. Pin the content too, so an _output item
// that disagrees with _data reddens here. _data stores active as the string "1"
// while _output stores the bool true, so compare active normalised.
$pinnedFrom = 'NF\Calendar\Service\EventWaitList\JoinerService';

$dataExt = null;
if ($extXml !== false) {
    foreach ($extXml->extension as $ext) {
        if ((string) $ext['from_class'] === $pinnedFrom) {
            $dataExt = $ext;
            break;
        }
    }
}

$outputExt = null;
foreach ($extOutput as $file) {
    $decoded = json_decode((string) file_get_contents($file), true);
    if (is_array($decoded) && ($decoded['from_class'] ?? null) === $pinnedFrom) {
        $outputExt = $decoded;
        break;
    }
}

check(
    '_output class-extension item agrees with _data on from_class, to_class and active',
    $dataExt !== null
        && $outputExt !== null
        && ($outputExt['from_class'] ?? null) === (string) $dataExt['from_class']
        && ($outputExt['to_class'] ?? null) === (string) $dataExt['to_class']
        && (bool) ($outputExt['active'] ?? null) === ((string) $dataExt['active'] === '1'),
    '_data(to=' . ($dataExt !== null ? (string) $dataExt['to_class'] : 'none')
        . ', active=' . ($dataExt !== null ? (string) $dataExt['active'] : 'none') . ') '
        . '_output(to=' . ($outputExt['to_class'] ?? 'none')
        . ', active=' . ($outputExt !== null ? var_export($outputExt['active'] ?? null, true) : 'none') . ')'
);

// --- the extension keeps its load-bearing shape -----------------------------
// setup() must return early while the typed $user property is uninitialised
// (guarding the premature parent-constructor call) and otherwise chain the
// vendor's setup(). Each pinned property is checked on its own, so breaking any
// one of them fails a distinct check.
$joiner = file_get_contents("$root/NF/Calendar/Service/EventWaitList/JoinerService.php");
check('JoinerService extension reads', $joiner !== false);
$joiner = (string) $joiner;

check(
    'JoinerService extends the XFCP proxy (extends XFCP_JoinerService)',
    (bool) preg_match('/class\s+JoinerService\s+extends\s+XFCP_JoinerService/', $joiner),
    'without the XFCP base, parent::setup() no longer resolves to the vendor chain and the wiring is broken'
);
check(
    'JoinerService overrides setup()',
    (bool) preg_match('/function\s+setup\s*\(/', $joiner),
    'the fix lives in a setup() override; a renamed override (e.g. doSetup()) lets XFCP pass the premature call straight to the vendor'
);
check(
    'setup() guards on the uninitialised user property (!isset($this->user))',
    str_contains($joiner, '!isset($this->user)'),
    'guarding on $user specifically is the load-bearing choice: the crash is a read of the typed $user before assignment'
);
check(
    'the $user guard returns early before doing anything else',
    (bool) preg_match('/if\s*\(\s*!isset\(\s*\$this->user\s*\)\s*\)\s*\{?\s*return/', $joiner),
    'without the early return the premature setup() call reaches the vendor body and throws'
);
check(
    'setup() chains the vendor setup (parent::setup())',
    str_contains($joiner, 'parent::setup()'),
    'the vendor constructor calls setup() again after assigning its properties; that later call must do the real work'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
