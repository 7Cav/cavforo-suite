<?php

/**
 * check-readme-catalog.php — check that the repo-root README's addon catalog
 * lists exactly the addons that ship. What decides that, and why this reads it
 * off the CI discover job rather than keeping a second list, is in
 * tools/README.md; partitionAddonRoot() below carries the rule itself.
 *
 * Usage:
 *   php tools/check-readme-catalog.php [repo-root]
 *
 * Exits 0 when the two sets match, 1 otherwise, naming the offending ids.
 */

namespace Cav7\Tools;

const ADDON_ROOT = 'src/addons/Cav7';
const CATALOG_FILE = 'README.md';

$repoRoot = rtrim($argv[1] ?? dirname(__DIR__), '/');

/**
 * The addon ids the catalog table lists, in the order the table lists them.
 *
 * The table is found by its header row rather than by position, so another
 * table elsewhere in the README is not mistaken for it. Ids are written
 * backticked and vendor-prefixed (`Cav7/RosterPatch`) while the directory is
 * bare (RosterPatch); both spellings are stripped to the bare id here so the
 * two sets are comparable.
 */
function catalogIds(string $readme): ?array
{
    $lines = preg_split('/\R/', $readme);
    $ids = [];
    $inTable = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if (!$inTable) {
            if (preg_match('/^\|\s*Addon\s*\|/i', $line)) {
                $inTable = true;
            }
            continue;
        }

        if (!str_starts_with($line, '|')) {
            break;
        }

        $first = trim(explode('|', trim($line, '|'))[0]);
        if ($first === '' || preg_match('/^:?-{3,}:?$/', $first)) {
            continue;
        }

        $ids[] = preg_replace('#^Cav7/#', '', trim($first, '`'));
    }

    return $inTable ? $ids : null;
}

/**
 * Split the addon root into the ids that ship and the directories that hold a
 * manifest under a name CI cannot put in its build matrix.
 *
 * Both halves of the rule come from the discover job: a manifest, and a name of
 * [A-Za-z0-9_] only, which that job requires so a directory name can never
 * inject into the run: steps consuming it. A directory failing the second half
 * is skipped there silently, so it is never linted, validated or packaged and
 * cannot reach a board. It does not ship and gets no catalog row — but it is
 * reported rather than ignored, because a directory receiving no CI at all is
 * not something to discover by noticing its absence from a build log.
 *
 * @return array{0: string[], 1: string[]} the shipped ids, and the unbuildable names
 */
function partitionAddonRoot(string $addonRoot): array
{
    $shipped = [];
    $unbuildable = [];

    foreach (glob("$addonRoot/*", GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file("$dir/addon.json")) {
            continue;
        }
        $name = basename($dir);
        if (preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            $shipped[] = $name;
        } else {
            $unbuildable[] = $name;
        }
    }
    sort($shipped);
    sort($unbuildable);

    return [$shipped, $unbuildable];
}

$readmePath = "$repoRoot/" . CATALOG_FILE;
if (!is_file($readmePath)) {
    fwrite(STDERR, "error: no " . CATALOG_FILE . " at $repoRoot\n");
    exit(1);
}

$listed = catalogIds(file_get_contents($readmePath));

// Losing the table is its own failure, and not drift. Reporting every addon as
// unlisted here would be a false drift report caused by a reformat that changed
// no addon at all, so say what actually happened instead.
if ($listed === null) {
    fwrite(STDERR, "FAIL " . CATALOG_FILE . ": no addon catalog table found (no row starting `| Addon |`)\n");
    fwrite(STDERR, "  the catalog is checked against " . ADDON_ROOT . " and has to stay machine-readable;\n");
    fwrite(STDERR, "  if the table moved or was reformatted, teach this check the new shape.\n");
    exit(1);
}

[$shipped, $unbuildable] = partitionAddonRoot("$repoRoot/" . ADDON_ROOT);

$missing = array_values(array_diff($shipped, $listed));
$stranded = array_values(array_diff($listed, $shipped));

if ($unbuildable) {
    fwrite(STDERR, "FAIL " . ADDON_ROOT . ": a manifest sits under a name CI cannot build\n");
    fwrite(STDERR, "  outside [A-Za-z0-9_], so discover skips it: " . implode(', ', $unbuildable) . "\n");
    fwrite(STDERR, "  it gets no lint, no validation and no package, and needs no catalog row.\n");
}

if ($missing || $stranded) {
    fwrite(STDERR, "FAIL " . CATALOG_FILE . ": the addon catalog is out of step with " . ADDON_ROOT . "\n");
    if ($missing) {
        fwrite(STDERR, "  ships but has no catalog row: " . implode(', ', $missing) . "\n");
    }
    if ($stranded) {
        fwrite(STDERR, "  has a catalog row but does not ship: " . implode(', ', $stranded) . "\n");
    }
}

if ($unbuildable || $missing || $stranded) {
    exit(1);
}

echo "OK " . CATALOG_FILE . ": catalog lists all " . count($shipped) . " shipped addons\n";
exit(0);
