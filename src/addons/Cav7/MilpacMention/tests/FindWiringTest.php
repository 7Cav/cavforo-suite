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
// The empty path assigns both $users = [] and $q = ''. Their order carries no
// behavioral meaning, so assert each statement exists independently rather than
// pinning one before the other with exact spacing.
check(
    "actionFind blanks q when the guard fails, mirroring MemberController",
    (bool) preg_match('/\$q\s*=\s*\'\'\s*;/', $controllerSrc),
    'XF returns {q:"", results:[]} for a too-short query'
);
check(
    "actionFind returns no users when the guard fails",
    (bool) preg_match('/\$users\s*=\s*\[\s*\]\s*;/', $controllerSrc),
    'XF returns {q:"", results:[]} for a too-short query'
);
check(
    'actionFind builds the milpac-owning finder through the shared resolver (reuse, not a second impl)',
    str_contains($controllerSrc, 'MilpacResolver::findMilpacOwningUsers'),
    'the roster join is reused from MilpacResolver (§4.3)'
);
check(
    'actionFind passes the limit 10 to the finder (spec §4.3 returns ten)',
    (bool) preg_match('/findMilpacOwningUsers\(\s*\$userFinder\s*,\s*\$q\s*,\s*10\s*\)/', $controllerSrc),
    'the dropdown returns ten milpac-owning actives, not the finder default (§4.3)'
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
    "the roster join is an INNER join via with('Milpac', true) (the mustExist flag)",
    (bool) preg_match("/->with\(\s*'Milpac'\s*,\s*true\s*\)/", $resolverSrc),
    "members with no milpac are dropped by the join; 'Milpac' is the declared relation now (#96, §4.3)"
);
// The dropdown row needs the rank and roster titles; eager-loading them via
// with('Milpac.Rank') / with('Milpac.Roster') keeps the render to zero per-row
// queries. Deleting either is an N+1 per keystroke that the runtime never fails
// on, so pin both here.
check(
    'the finder eager-loads Milpac.Rank (no N+1 for the rank title, §4.5)',
    (bool) preg_match("/->with\(\s*'Milpac\.Rank'\s*\)/", $resolverSrc)
);
check(
    'the finder eager-loads Milpac.Roster (no N+1 for the roster title, §4.5)',
    (bool) preg_match("/->with\(\s*'Milpac\.Roster'\s*\)/", $resolverSrc)
);
// #112 — one milpac per user is expected but NOT schema-enforced, so a member with
// two roster rows can match the join twice. XF's identity map keeps the first fetched
// row per user_id, so ordering the join by relation_id makes the surviving milpac the
// LOWEST (deterministic, matching #96's lazy $user->Milpac) rather than arbitrary. The
// relation's own 'order' does not reach this eager with('Milpac', true) join, so the
// finder must set it here.
check(
    "the finder orders the join by Milpac.relation_id (a duplicate member keeps its lowest milpac, §4.4 #112)",
    (bool) preg_match("/->order\(\s*'Milpac\.relation_id'\s*\)/", $resolverSrc),
    'without it the surviving milpac for a member with two roster rows is nondeterministic'
);
$orderPos = strpos($resolverSrc, "->order('Milpac.relation_id')");
$fetchLimitPos = strpos($resolverSrc, '->fetch($limit)');
check(
    'the deterministic order is applied before the fetch limit',
    $orderPos !== false && $fetchLimitPos !== false && $orderPos < $fetchLimitPos
);
// #96 — the relation is declared centrally now (the entity_structure listener
// above), so findMilpacOwningUsers must no longer poke it into the request-shared
// XF:User structure at query time. The ad-hoc registration and the getStructure()
// handle it mutated are both gone; the relation-shape pins moved to the listener
// section, and this pins that the query-time side effect is really removed.
check(
    'findMilpacOwningUsers no longer mutates the User structure (the ad-hoc block is gone)',
    !preg_match("/\\\$structure->relations\['Milpac'\]\s*=/", $resolverSrc)
        && !str_contains($resolverSrc, '->getStructure()'),
    'the Milpac relation is a first-class entity_structure listener, not a query-time side effect (#96)'
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
// the relation — 'Milpac' is declared centrally on XF:User via an entity_structure
// code-event listener, not poked into the request-shared structure at query time
// (#96). The relation-shape pins (target, TO_ONE, conditions => user_id, no
// primary) live here now, on the callback that owns the declaration.
// =========================================================================
$listenersXml = @simplexml_load_file("$root/_data/code_event_listeners.xml");
check('_data/code_event_listeners.xml could be read', $listenersXml !== false);

$structureListener = null;
if ($listenersXml !== false) {
    foreach ($listenersXml->listener as $l) {
        if ((string) $l['event_id'] === 'entity_structure'
            && (string) $l['hint'] === 'XF\Entity\User') {
            $structureListener = $l;
            break;
        }
    }
}
check(
    'an entity_structure listener hinted to XF\Entity\User is declared',
    $structureListener !== null,
    'the User->Milpac relation is registered once for the whole request, the idiomatic XF route (#96)'
);
check(
    'the listener is active and points at Cav7\MilpacMention\Listener::userEntityStructure',
    $structureListener !== null
        && (string) $structureListener['active'] === '1'
        && (string) $structureListener['callback_class'] === 'Cav7\MilpacMention\Listener'
        && (string) $structureListener['callback_method'] === 'userEntityStructure',
    'the callback that declares $structure->relations[Milpac]'
);

// check-data-consistency count-checks code_event_listeners: one <listener> in
// _data must have one item file in _output/code_event_listeners/. Pin the same
// invariant here, and that the export describes our listener, so a hand-edit of
// one side fails (mirrors the routes pins above).
$listenerItems = outputItems($root, 'code_event_listeners');
check(
    '_output/code_event_listeners has exactly one item file per _data listener',
    count($listenerItems) === ($listenersXml !== false ? count($listenersXml->listener) : -1)
);
$listenerExport = null;
foreach ($listenerItems as $f) {
    $decoded = json_decode((string) @file_get_contents($f), true);
    if (is_array($decoded)
        && ($decoded['event_id'] ?? '') === 'entity_structure'
        && ($decoded['hint'] ?? '') === 'XF\Entity\User') {
        $listenerExport = $decoded;
    }
}
check(
    'the _output listener export describes the entity_structure User listener to our callback',
    is_array($listenerExport)
        && ($listenerExport['active'] ?? null) === true
        && ($listenerExport['callback_class'] ?? '') === 'Cav7\MilpacMention\Listener'
        && ($listenerExport['callback_method'] ?? '') === 'userEntityStructure',
    '_data and _output must agree on the listener (check-data-consistency count-checks; this checks content)'
);
// The _metadata.json index must list the exported listener file (the export always
// writes it; a missing entry means the export was hand-faked). The filename suffix
// is md5("callback_class-callback_method-hint"), the same key XF's dev-output uses.
$listenerMeta = json_decode((string) @file_get_contents("$root/_output/code_event_listeners/_metadata.json"), true);
check(
    'the _output/code_event_listeners/_metadata.json indexes the exported listener file',
    is_array($listenerMeta)
        && isset($listenerMeta['entity_structure_400ad1257769a7d5559a4bc621a20eec.json'])
);

// the callback — Listener::userEntityStructure(Manager, Structure) declares the
// Milpac relation with the exact shape the resolver used to poke in by hand.
$listenerSrc = (string) @file_get_contents("$root/Listener.php");
check('Listener.php exists', $listenerSrc !== '');
check(
    'Listener lives in the addon-root Cav7\MilpacMention namespace',
    (bool) preg_match('/namespace\s+Cav7\\\\MilpacMention\s*;/', $listenerSrc)
);
check(
    'Listener imports Manager, Structure and Entity (the TO_ONE constant) from XF\Mvc\Entity',
    (bool) preg_match('/use\s+XF\\\\Mvc\\\\Entity\\\\Manager\s*;/', $listenerSrc)
        && (bool) preg_match('/use\s+XF\\\\Mvc\\\\Entity\\\\Structure\s*;/', $listenerSrc)
        && (bool) preg_match('/use\s+XF\\\\Mvc\\\\Entity\\\\Entity\s*;/', $listenerSrc)
);
check(
    'userEntityStructure is a static entity_structure callback (Manager $em, Structure &$structure)',
    (bool) preg_match('/static\s+function\s+userEntityStructure\s*\(\s*Manager\s+\$em\s*,\s*Structure\s+&\$structure\s*\)/', $listenerSrc),
    'the (Manager $em, Structure &$structure) signature XF hands an entity_structure listener'
);
check(
    'the callback declares the Milpac relation on the structure it is handed',
    str_contains($listenerSrc, "\$structure->relations['Milpac']"),
    'first-class, greppable relation on XF:User, not a query-time side effect (#96)'
);

// Isolate the declared relation array from the callback (code only, // comments
// stripped) so these target the registered relation, not the prose that explains it.
preg_match("/\\\$structure->relations\['Milpac'\]\s*=\s*\[(.*?)\];/s", $listenerSrc, $relMatch);
$relBlock = preg_replace('~//[^\n]*~', '', $relMatch[1] ?? '');
check(
    'the declared milpac relation targets NF\Rosters:RosterUser (the milpac row, §4.4)',
    (bool) preg_match("/'entity'\s*=>\s*'NF\\\\Rosters:RosterUser'/", $relBlock)
);
check(
    'the declared milpac relation is TO_ONE (models the expected one-milpac-per-user shape; not schema-enforced — see the \'order\' check below)',
    (bool) preg_match("/'type'\s*=>\s*Entity::TO_ONE/", $relBlock)
);
check(
    "the declared milpac relation joins on 'conditions' => 'user_id' (the inverse join key)",
    (bool) preg_match("/'conditions'\s*=>\s*'user_id'/", $relBlock)
);
// #96 — one milpac per user is expected but NOT schema-enforced: xf_nf_rosters_user
// has a non-unique user_id index and live data already carries a user with two rows.
// So a lazy $user->Milpac (no 'primary' → getRelationFinder->fetchOne → WHERE user_id
// = ? LIMIT 1) would return an arbitrary row. 'order' => 'relation_id' makes it
// deterministic (lowest relation_id). The INNER-join query in findMilpacOwningUsers
// is unaffected: with('Milpac', true) builds its join from 'conditions' and the
// finder sets its own ORDER BY — 'order' rides only the lazy fetchOne path.
check(
    "the declared milpac relation sets 'order' => 'relation_id' (deterministic lazy \$user->Milpac, #96)",
    (bool) preg_match("/'order'\s*=>\s*'relation_id'/", $relBlock),
    "without it the no-primary lazy TO_ONE returns a nondeterministic row when a user has duplicate roster rows (non-unique user_id index)"
);
check(
    "the declared milpac relation does NOT set 'primary' => true (Finding 1)",
    $relBlock !== '' && !preg_match("/'primary'\s*=>\s*true/", $relBlock),
    "primary is only correct on RosterUser.User, where user_id IS the target's PK; on the inverse "
        . "the target is RosterUser (PK relation_id), so primary would misresolve a lazy \$user->Milpac"
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
// The envelope must return both the results and q keys. Their order and the local
// variable name backing results carry no behavioral meaning, so assert both keys
// are present rather than pinning the exact order and local.
check(
    'the JSON envelope returns the results key, mirroring XF:Member\Find / find-emoji',
    (bool) preg_match("/'results'\s*=>/", $viewSrc)
);
check(
    'the JSON envelope returns the q key alongside results',
    (bool) preg_match("/'q'\s*=>\s*\\\$this->params\['q'\]/", $viewSrc)
);
check(
    'each row carries the display fields rank, name and roster (acceptance criteria)',
    str_contains($viewSrc, "'rank'")
        && str_contains($viewSrc, "'name'")
        && str_contains($viewSrc, "'roster'"),
    'the dropdown shows rank+name primary, roster secondary (§4.5)'
);
// The completer renders from the find-emoji fields, not the convenience
// decomposition: text is the primary "Rank Name" line, desc is the roster. Pin
// both, mapped to the values the row is built from.
check(
    "each row populates 'text' with the shared display text (the dropdown's primary line, §4.5)",
    (bool) preg_match("/'text'\s*=>\s*\\\$displayText/", $viewSrc)
);
check(
    "each row populates 'desc' with the roster (the dropdown's secondary line, §4.5)",
    (bool) preg_match("/'desc'\s*=>\s*\\\$roster/", $viewSrc)
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
// #112 — a member with two roster rows must render once, not twice. The view collapses
// through the shared MilpacResolver::dedupeMilpacOwners, which keeps the lowest
// relation_id and logs the duplicate as a data error, rather than an ad-hoc silent
// dedup — so the drop is deterministic and visible (§4.4).
check(
    'the view collapses duplicate milpac owners through the shared resolver (one entry per member, logged)',
    str_contains($viewSrc, 'MilpacResolver::dedupeMilpacOwners'),
    'a member with two roster rows yields one dropdown entry, and the duplicate is logged (#112)'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
