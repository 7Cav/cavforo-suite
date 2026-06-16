<?php

/**
 * validate-addon.php — static checks on one addon, no XenForo required.
 *
 *   php tools/validate-addon.php src/addons/Cav7/SteamChecker
 *
 * Checks addon.json against what docs/addon-format.md requires, and that every
 * _data/*.xml is well-formed. Exits non-zero on any problem. This is the part
 * of xf-addon:build-release we can reproduce without the database: the manifest
 * shape and the XML, not the export itself.
 */

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php tools/validate-addon.php <addon-dir>\n");
    exit(2);
}
$dir = rtrim($dir, '/');
$name = basename($dir);

$errors = [];

// --- addon.json ---
$jsonPath = "$dir/addon.json";
if (!is_file($jsonPath)) {
    $errors[] = 'addon.json is missing';
} else {
    $json = json_decode(file_get_contents($jsonPath), true);
    if (!is_array($json)) {
        $errors[] = 'addon.json is not valid JSON: ' . json_last_error_msg();
    } else {
        foreach (['title', 'version_id', 'version_string'] as $key) {
            if (!array_key_exists($key, $json)) {
                $errors[] = "addon.json is missing required key: $key";
            }
        }
        // XenForo derives these from the directory path; addon-format.md says
        // they must not be present (xf-addon:validate-json rewrites the file).
        foreach (['addon_id', 'namespace', 'setup'] as $key) {
            if (array_key_exists($key, $json)) {
                $errors[] = "addon.json has key '$key', which XenForo derives and must not be set";
            }
        }
        if (isset($json['version_id']) && !is_int($json['version_id'])) {
            $errors[] = 'addon.json version_id must be an integer';
        }
        if (isset($json['version_string']) && !is_string($json['version_string'])) {
            $errors[] = 'addon.json version_string must be a string';
        }
    }
}

// --- _data/*.xml well-formedness ---
$dataDir = "$dir/_data";
if (is_dir($dataDir)) {
    $prev = libxml_use_internal_errors(true);
    foreach (glob("$dataDir/*.xml") as $xmlFile) {
        libxml_clear_errors();
        if (simplexml_load_file($xmlFile) === false) {
            $msgs = array_map(fn ($e) => trim($e->message), libxml_get_errors());
            $errors[] = 'malformed XML in _data/' . basename($xmlFile) . ': ' . implode('; ', $msgs);
        }
    }
    libxml_use_internal_errors($prev);
}

if ($errors) {
    fwrite(STDERR, "FAIL $name\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

echo "OK $name\n";
exit(0);
