<?php

/**
 * validate-addon.php — static checks on one addon, no XenForo required.
 *
 *   php tools/validate-addon.php src/addons/Cav7/SteamChecker
 *
 * Checks addon.json against what docs/addon-format.md requires, that every
 * _data/*.xml is well-formed, and that _data/class_extensions.xml holds its rows
 * in the canonical order ADR 0003 defines. Exits non-zero on any problem. This
 * is the part of xf-addon:build-release we can reproduce without the database:
 * the manifest shape and the XML, not the export itself.
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
$classExtensions = null;
$dataDir = "$dir/_data";
if (is_dir($dataDir)) {
    $prev = libxml_use_internal_errors(true);
    foreach (glob("$dataDir/*.xml") as $xmlFile) {
        libxml_clear_errors();
        $xml = simplexml_load_file($xmlFile);
        if ($xml === false) {
            $msgs = array_map(fn ($e) => trim($e->message), libxml_get_errors());
            $errors[] = 'malformed XML in _data/' . basename($xmlFile) . ': ' . implode('; ', $msgs);
        } elseif (basename($xmlFile) === 'class_extensions.xml') {
            $classExtensions = $xml;
        }
    }
    libxml_use_internal_errors($prev);
}

// --- _data/class_extensions.xml canonical row order (ADR 0003) ---
// XenForo exports these rows with ORDER BY from_class, to_class over
// utf8mb4_general_ci columns, so the canonical order is that collation's, not a
// byte comparison's. general_ci is case-insensitive, which is where the two
// part company: 'XenAddons\' folds to 'XENADDONS\' and sorts ahead of 'XF\'
// ('E' 0x45 < 'F' 0x46), while raw bytes put 'XF\' first ('F' 0x46 < 'e' 0x65).
// strtoupper models that fold: it is ASCII-only and locale-independent as of
// PHP 8.2, and every class name is ASCII. execute_order is not a tiebreaker —
// the schema's UNIQUE KEY (from_class, to_class) makes the pair unique.
if ($classExtensions !== null) {
    $rows = [];
    foreach ($classExtensions->extension as $extension) {
        $rows[] = [(string) $extension['from_class'], (string) $extension['to_class']];
    }

    for ($i = 0; $i < count($rows) - 1; $i++) {
        [$from, $to] = $rows[$i];
        [$nextFrom, $nextTo] = $rows[$i + 1];
        $order = strcmp(strtoupper($from), strtoupper($nextFrom))
            ?: strcmp(strtoupper($to), strtoupper($nextTo));
        if ($order > 0) {
            $errors[] = '_data/class_extensions.xml is out of canonical order: '
                . "'$from' => '$to' is listed before '$nextFrom' => '$nextTo', but sorts after it";
        }
    }
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
