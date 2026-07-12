<?php

/**
 * Issue #88 — pins the vendor-coupled wiring of the $name completer's find
 * endpoint (spec §4.3) so a regression fails CI rather than shipping silently.
 * The pure logic (q-guard, display text, insert-value anchor) is exercised for
 * real in FindQueryTest; this holds the parts that need a live XenForo +
 * NF\Rosters to actually run: the route that resolves milpac-mention/find to the
 * controller, the finder query (username LIKE + isValidUser(true) + the inner
 * join to NF\Rosters:RosterUser, before the limit), and the {q, results}
 * envelope whose rows carry rank/name/roster and the value to insert.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/FindWiringTest.php
 */

namespace Cav7\MilpacMention\Tests;

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

/** All _output item files of a type, excluding the _metadata.json index. */
function outputItems(string $root, string $type): array
{
    return array_values(array_filter(
        glob("$root/_output/$type/*") ?: [],
        fn ($f) => basename($f) !== '_metadata.json'
    ));
}

// =========================================================================
// the route — milpac-mention/find resolves to the new controller action (§4.3)
// =========================================================================
$routesXml = @simplexml_load_file("$root/_data/routes.xml");
check('_data/routes.xml could be read', $routesXml !== false);

$route = null;
if ($routesXml !== false) {
    foreach ($routesXml->route as $r) {
        if ((string) $r['route_prefix'] === 'milpac-mention') {
            $route = $r;
            break;
        }
    }
}
check(
    'a public route with prefix milpac-mention is declared',
    $route !== null && (string) $route['route_type'] === 'public',
    'the completer GETs index.php?milpac-mention/find, so the prefix routes to our controller'
);
check(
    "the milpac-mention route points at the Cav7\\MilpacMention:MilpacMention controller",
    $route !== null && (string) $route['controller'] === 'Cav7\MilpacMention:MilpacMention',
    'milpac-mention/find -> Cav7\MilpacMention\Pub\Controller\MilpacMention::actionFind'
);

// check-data-consistency count-checks routes: one <route> in _data must have one
// item file in _output/routes/. Pin the same invariant here, and that the item
// describes our route (prefix + controller), so a hand-edit of one side fails.
$routeItems = outputItems($root, 'routes');
check(
    '_output/routes has exactly one item file per _data route',
    count($routeItems) === ($routesXml !== false ? count($routesXml->route) : -1)
);
$routeExport = null;
foreach ($routeItems as $f) {
    $decoded = json_decode((string) @file_get_contents($f), true);
    if (is_array($decoded) && ($decoded['route_prefix'] ?? '') === 'milpac-mention') {
        $routeExport = $decoded;
    }
}
check(
    'the _output route export describes the milpac-mention public route to our controller',
    is_array($routeExport)
        && ($routeExport['route_type'] ?? '') === 'public'
        && ($routeExport['controller'] ?? '') === 'Cav7\MilpacMention:MilpacMention',
    '_data and _output must agree on the route (check-data-consistency count-checks; this checks content)'
);
// The _metadata.json index must list the exported route file (the export always
// writes it; a missing entry means the export was hand-faked).
$routeMeta = json_decode((string) @file_get_contents("$root/_output/routes/_metadata.json"), true);
check(
    'the _output/routes/_metadata.json indexes the exported route file',
    is_array($routeMeta) && isset($routeMeta['public_milpac-mention_.json'])
);

// =========================================================================
// the controller — actionFind mirrors MemberController::actionFind (§4.3)
// =========================================================================
$controllerSrc = (string) @file_get_contents("$root/Pub/Controller/MilpacMention.php");
check('Pub/Controller/MilpacMention.php exists', $controllerSrc !== '');
check(
    'the controller lives in the Pub\Controller namespace so the route short-name resolves',
    (bool) preg_match('/namespace\s+Cav7\\\\MilpacMention\\\\Pub\\\\Controller\s*;/', $controllerSrc)
);
check(
    'MilpacMention extends the public AbstractController',
    (bool) preg_match('/class\s+MilpacMention\s+extends\s+AbstractController/', $controllerSrc)
);
check(
    'actionFind is defined',
    (bool) preg_match('/function\s+actionFind\s*\(/', $controllerSrc)
);
check(
    'actionFind reads q with the same filter as MemberController (str, no-trim, then ltrim)',
    (bool) preg_match("/ltrim\(\s*\\\$this->filter\(\s*'q'\s*,\s*'str'\s*,\s*\['no-trim'\]\s*\)\s*\)/", $controllerSrc)
);
check(
    'actionFind guards on the shared q-length check (a short q returns the empty set)',
    str_contains($controllerSrc, 'MilpacResolver::isFindQueryLongEnough'),
    'a q shorter than 2 characters must not run the query (§4.3)'
);
check(
    "actionFind blanks q and returns no users when the guard fails, mirroring MemberController",
    (bool) preg_match('/\$users\s*=\s*\[\]\s*;\s*\$q\s*=\s*\'\'\s*;/s', $controllerSrc),
    'XF returns {q:"", results:[]} for a too-short query'
);
check(
    'actionFind builds the milpac-owning finder through the shared resolver (reuse, not a second impl)',
    str_contains($controllerSrc, 'MilpacResolver::findMilpacOwningUsers'),
    'the roster join is reused from MilpacResolver (§4.3)'
);
check(
    'actionFind renders the find view with the q and users params (the {q, results} envelope)',
    (bool) preg_match('/->view\(\s*\'Cav7\\\\MilpacMention:MilpacMention\\\\Find\'/', $controllerSrc)
        && str_contains($controllerSrc, "'q' => \$q")
        && str_contains($controllerSrc, "'users' => \$users"),
);

// =========================================================================
// the finder query — the inner join sits inside the query, before the limit (§4.3)
// =========================================================================
$resolverSrc = (string) @file_get_contents("$root/MilpacResolver.php");
check(
    'findMilpacOwningUsers matches usernames by prefix via escapeLike($q, "?%")',
    (bool) preg_match("/->where\(\s*'username'\s*,\s*'like'\s*,\s*\\\$userFinder->escapeLike\(\s*\\\$q\s*,\s*'\?%'\s*\)\s*\)/", $resolverSrc),
    'mirrors MemberController::actionFind (§4.3)'
);
check(
    'findMilpacOwningUsers filters to isValidUser(true) — unbanned, valid, active within 180 days',
    (bool) preg_match('/->isValidUser\(\s*true\s*\)/', $resolverSrc),
    'banned, dormant and memorial members fall out (§4.3)'
);
check(
    'the roster join is an INNER join to NF\Rosters:RosterUser (with the mustExist flag)',
    (bool) preg_match("/->with\(\s*'Milpac'\s*,\s*true\s*\)/", $resolverSrc)
        && (bool) preg_match("/'entity'\s*=>\s*'NF\\\\Rosters:RosterUser'/", $resolverSrc),
    "members with no milpac are dropped by the join (with('Milpac', true) forces INNER, §4.3)"
);
check(
    'the ad-hoc milpac relation is TO_ONE (one user = one milpac = one relation_id, §4.4)',
    (bool) preg_match("/'type'\s*=>\s*\\\\XF\\\\Mvc\\\\Entity\\\\Entity::TO_ONE/", $resolverSrc)
);
// The join must sit INSIDE the finder before fetch() applies the limit, so the
// result is $limit milpac owners, not $limit actives then filtered. Structurally:
// the with('Milpac', true) join is chained before the terminating ->fetch($limit).
$withPos = strpos($resolverSrc, "->with('Milpac', true)");
$fetchPos = strpos($resolverSrc, '->fetch($limit)');
check(
    'the inner join is applied before the fetch limit (join inside the query, not post-filtering)',
    $withPos !== false && $fetchPos !== false && $withPos < $fetchPos,
    'ten milpac-owning actives, not ten actives then filtered down (§4.3)'
);

// =========================================================================
// the view — the {q, results} envelope; rows carry rank/name/roster + insert value
// =========================================================================
$viewSrc = (string) @file_get_contents("$root/Pub/View/MilpacMention/Find.php");
check('Pub/View/MilpacMention/Find.php exists', $viewSrc !== '');
check(
    'the view renders JSON (the autocomplete endpoint returns the {q, results} envelope)',
    (bool) preg_match('/function\s+renderJson\s*\(/', $viewSrc)
);
check(
    'the JSON envelope is {results, q}, mirroring XF:Member\Find / find-emoji',
    (bool) preg_match("/return\s*\[\s*'results'\s*=>\s*\\\$results\s*,\s*'q'\s*=>\s*\\\$this->params\['q'\]\s*,?\s*\]\s*;/s", $viewSrc)
);
check(
    'each row carries the display fields rank, name and roster (acceptance criteria)',
    str_contains($viewSrc, "'rank'")
        && str_contains($viewSrc, "'name'")
        && str_contains($viewSrc, "'roster'"),
    'the dropdown shows rank+name primary, roster secondary (§4.5)'
);
check(
    'each row carries the value to insert as a named anchor via the shared builder',
    str_contains($viewSrc, "'html'")
        && str_contains($viewSrc, 'MilpacResolver::milpacLinkHtml'),
    'the value to insert is the named roster link, not a bare URL (§4.2)'
);
check(
    'the display text is built once via the shared builder and reused for text and anchor',
    str_contains($viewSrc, 'MilpacResolver::milpacDisplayText'),
    'primary line and anchor text are the same "Rank Name" string (§4.2/§4.5)'
);
check(
    'the insert value links the canonical rosters/profile route for the joined milpac row',
    (bool) preg_match("/buildLink\(\s*'canonical:rosters\/profile'\s*,\s*\\\$milpac\s*\)/", $viewSrc),
    'the href must contain /rosters/profile/<relation_id>/ so the engine detects it (§2.2)'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
