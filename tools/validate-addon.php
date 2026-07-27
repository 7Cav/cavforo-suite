<?php

/**
 * validate-addon.php — static checks on one addon, no XenForo required.
 *
 *   php tools/validate-addon.php src/addons/Cav7/SteamChecker
 *
 * Checks addon.json against what docs/addon-format.md requires, that every
 * _data/*.xml is well-formed, and that _data/class_extensions.xml holds its rows
 * in the canonical order ADR 0004 defines. Exits non-zero on any problem. This
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

/**
 * Decode a version_id the way XenForo does, returning '' when it is not in
 * XenForo's scheme at all.
 *
 * This is a faithful offline port of
 * XF\Repository\AddOnRepository::inferVersionStringFromId(), working on the
 * reversed id with XenForo's own pattern so the two cannot drift apart through
 * a difference in backtracking. The scheme is documented in XF.php:
 *
 *     abbccde = a.b.c d (alpha: 1, beta: 3, RC: 5, stable: 7, PL: 9) e
 *
 * so the last two digits are two separate fields — a release-state digit and a
 * build number — not a single two-digit state code. 1010431 is 1.1.4 Beta 1,
 * not a malformed 1.1.4.
 *
 * XenForo uses this exact function to fill in a version_string when
 * xf-addon:bump-version is run without one, so an id that decodes to something
 * other than the declared version_string is not cosmetic: the next bump
 * silently renames the addon's version.
 *
 * Only the mainline path is exercised by tests. The '90' legacy prefix (XFMG's
 * offset) and the status 2/4/6 build-offset branches are ported to mirror
 * XenForo, deliberately uncovered, and used by no addon in this suite — do not
 * add fixtures enumerating them, which would turn XenForo internals we do not
 * depend on into local contracts.
 */
function inferVersionStringFromId(string $versionId): string
{
    $revVersionId = strrev($versionId);
    if (!preg_match('/^(\d)(\d)(\d{2})(\d{2})(\d{1,2})(?:09){0,4}$/', $revVersionId, $matches)) {
        return '';
    }

    $matches = array_map('strrev', $matches);
    [, $build, $status, $patch, $minor, $major] = $matches;

    $versionString = intval($major) . '.' . intval($minor) . '.' . intval($patch);
    switch ($status) {
        case 1:
        case 2:
            $versionString .= ' Alpha';
            if ($status == 2) {
                $build += 10;
            }
            break;

        case 3:
        case 4:
            $versionString .= ' Beta';
            if ($status == 4) {
                $build += 10;
            }
            break;

        case 5:
        case 6:
            $versionString .= ' Release Candidate';
            if ($status == 6) {
                $build += 10;
            }
            break;

        case 7:
        case 8:
            if ($status == 8) {
                $build += 10;
            }
            if ($build > 0) {
                $versionString .= ".$build";
                $build = 0;
            }
            break;

        case 9:
            $versionString .= ' Patch Level';
            break;
    }

    if ($build) {
        $versionString .= ' ' . intval($build);
    }

    return $versionString;
}

/**
 * The inverse: the version_id a version_string calls for, or null when the
 * string is not in a shape XenForo's numbering can express.
 *
 * Callers must confirm the result decodes back to the string before showing it
 * as a remedy — the arithmetic silently overflows into the next field once a
 * component outgrows its digits (a patch of 100, a major of 100), and a remedy
 * that does not round-trip is worse than none.
 */
function versionIdFromString(string $versionString): ?int
{
    $pattern = '/^(\d+)\.(\d+)\.(\d+)(?:\.(\d+))?'
        . '(?: (Alpha|Beta|Release Candidate|Patch Level)(?: (\d+))?)?$/';
    if (!preg_match($pattern, $versionString, $m)) {
        return null;
    }

    [, $major, $minor, $patch] = $m;
    $stableBuild = $m[4] ?? '';
    $state = $m[5] ?? '';
    $build = intval($m[6] ?? 0);

    switch ($state) {
        case 'Alpha':
            $status = 1;
            break;
        case 'Beta':
            $status = 3;
            break;
        case 'Release Candidate':
            $status = 5;
            break;
        case 'Patch Level':
            $status = 9;
            break;
        default:
            $status = 7;
            $build = $stableBuild === '' ? 0 : intval($stableBuild);
            break;
    }

    // A build of 10 or more is carried by the odd/even pair of the state digit,
    // except for Patch Level, which has no paired digit to carry it.
    if ($build >= 10 && $status != 9) {
        $status++;
        $build -= 10;
    }
    if ($build >= 10) {
        return null;
    }

    return intval($major) * 1000000
        + intval($minor) * 10000
        + intval($patch) * 100
        + $status * 10
        + $build;
}

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

        // version_id is what XenForo compares to decide a board needs this
        // addon's data, and what it decodes to show the installed version. If
        // it disagrees with version_string, the board is telling operators it
        // is at a version nobody released.
        if (isset($json['version_id'], $json['version_string'])
            && is_int($json['version_id'])
            && is_string($json['version_string'])
        ) {
            $declared = $json['version_string'];
            $decoded = inferVersionStringFromId((string) $json['version_id']);

            // An id XenForo cannot decode is always wrong, even in the corner
            // where version_string is empty too and the two would compare equal.
            if ($decoded === '' || $decoded !== $declared) {
                $error = $decoded === ''
                    ? "addon.json version_id {$json['version_id']} is not a version XenForo can "
                        . 'decode; the scheme is abbccde — major, minor, patch, release state '
                        . '(alpha 1, beta 3, RC 5, stable 7, patch level 9) and build'
                    : "addon.json version_id {$json['version_id']} reads as version '$decoded', "
                        . "but version_string says '$declared'";

                // Name the remedy, but only one that survives a round trip: the
                // arithmetic overflows silently once a component outgrows its
                // digits, and some versions XenForo's scheme cannot express at
                // all (a major of 0). A remedy that does not decode back to the
                // declared string would send the author somewhere worse.
                $expected = versionIdFromString($declared);
                if ($expected !== null && inferVersionStringFromId((string) $expected) === $declared) {
                    $error .= "; version_string '$declared' calls for version_id $expected";
                }

                $errors[] = $error;
            }
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

// --- _data/class_extensions.xml canonical row order ---
// The rule and the evidence for it: docs/adr/0004-class-extension-order-is-
// case-folded.md. In short, XenForo sorts these rows in SQL over
// utf8mb4_general_ci columns, and that collation is case-insensitive, so the
// order is case-folded rather than a byte comparison. execute_order is not a
// tiebreaker; UNIQUE KEY (from_class, to_class) makes the pair unique.
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
