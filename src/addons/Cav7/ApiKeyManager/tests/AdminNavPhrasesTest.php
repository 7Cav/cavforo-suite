<?php

/**
 * Issue #29 — the ACP navigation entries this addon adds (cav7ApiKeys,
 * cav7ApiScopes, in _data/admin_navigation.xml) showed their raw phrase keys
 * because no matching title phrases shipped. XenForo renders each entry's label
 * from a phrase titled admin_navigation.<navigation_id>, so both titles must be
 * present in _data/phrases.xml or the ACP prints the key instead of a label.
 *
 * This pins the two title phrases so a stray re-export that drops them fails CI
 * rather than silently regressing the nav labels.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/AdminNavPhrasesTest.php
 */

namespace Cav7\ApiKeyManager\Tests;

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

$phrasesXml = simplexml_load_file(__DIR__ . '/../_data/phrases.xml');
check('phrases.xml could be read', $phrasesXml !== false);

$titles = [];
if ($phrasesXml !== false) {
    foreach ($phrasesXml->phrase as $phrase) {
        $titles[(string) $phrase['title']] = trim((string) $phrase);
    }
}

// --- Each nav entry has a matching admin_navigation.<id> title phrase --------
$expected = [
    'admin_navigation.cav7ApiKeys' => 'API Keys',
    'admin_navigation.cav7ApiScopes' => 'API Scopes',
];
foreach ($expected as $title => $label) {
    check(
        "$title is present in _data/phrases.xml",
        isset($titles[$title]),
        'available: ' . implode(', ', array_keys($titles))
    );
    if (isset($titles[$title])) {
        check(
            "$title reads \"$label\"",
            $titles[$title] === $label,
            'got: ' . var_export($titles[$title], true)
        );
    }
}

// --- Every admin_navigation.xml entry has a matching title phrase ------------
// Pin the link itself, so adding a nav entry without its label fails here.
$navXml = simplexml_load_file(__DIR__ . '/../_data/admin_navigation.xml');
check('admin_navigation.xml could be read', $navXml !== false);
if ($navXml !== false) {
    foreach ($navXml->admin_navigation_entry as $entry) {
        $navId = (string) $entry['navigation_id'];
        check(
            "nav entry $navId has a title phrase admin_navigation.$navId",
            isset($titles["admin_navigation.$navId"]),
            'missing label phrase'
        );
    }
}

// --- Summary ----------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
