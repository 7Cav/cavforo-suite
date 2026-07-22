<?php

/**
 * Pins the vendor-coupled wiring that CI cannot run because it needs a live XenForo
 * plus NF/Discord.
 *
 * Issue #148 — the class extension against the vendor's per-user sync message, both
 * method overrides on that extension, and the properties of the adapter that a
 * dev-stack run confirmed and a later edit could quietly undo. The decision itself
 * is exercised for real in RoleClaimTest.
 *
 * Issue #158 — the member-facing resync button: the class extension against the
 * public account controller, the action the button posts to, the three preconditions
 * and two guards in front of it, and the template modification and phrases that put
 * it on the page.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/WiringTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

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

/**
 * The source of one method, from its `function <name>` declaration up to the next
 * method's docblock or declaration, so a check stays anchored to the method that
 * owns it.
 */
function methodBody(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name);
    if ($start === false) {
        return '';
    }
    $body = substr($src, $start);
    if (preg_match('~\n    (?:/\*\*|(?:private|protected|public)\s+function\s)~', $body, $m, PREG_OFFSET_CAPTURE)) {
        $body = substr($body, 0, $m[0][1]);
    }
    return $body;
}

/**
 * The source with every comment removed, rebuilt through the PHP tokenizer so a
 * comment marker inside a string literal survives. Positional pins run against
 * this, so a docblock quoting the thing being pinned cannot satisfy the check.
 */
function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

// =========================================================================
// addon.json — identity and dependencies
// =========================================================================
$addon = json_decode((string) @file_get_contents("$root/addon.json"), true);
check('addon.json is valid JSON', is_array($addon));
if (is_array($addon)) {
    check('title is "7Cav - Discord Sync Patch"', ($addon['title'] ?? '') === '7Cav - Discord Sync Patch');
    check('version_string is 1.0.0', ($addon['version_string'] ?? '') === '1.0.0');
    check(
        'version_id is a positive integer',
        is_int($addon['version_id'] ?? null) && $addon['version_id'] > 0
    );
    check(
        'requires XF 2.3.0+ (2030070)',
        (int) ($addon['require']['XF'][0] ?? 0) === 2030070
    );
    check(
        'hard-requires NF/Discord',
        isset($addon['require']['NF/Discord']),
        'every seam this addon hangs on belongs to NF/Discord; installing without it is a broken state'
    );
    foreach (['addon_id', 'namespace', 'setup'] as $derived) {
        check(
            "addon.json omits the XenForo-derived key '$derived'",
            !array_key_exists($derived, $addon)
        );
    }
}

// Reverting is disabling the addon, which only holds while there is nothing to
// unwind. A Setup.php would mean install state, and install state means a revert
// needs more than a toggle.
check(
    'no Setup.php (class-extension-only addon)',
    !is_file("$root/Setup.php"),
    'this addon creates no tables, options or fields; reverting must stay a single toggle'
);

// =========================================================================
// the class extensions — the vendor's per-user sync message, and the public
// account controller the resync button posts to
// =========================================================================
$classExtXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $classExtXml !== false);

$extByFrom = [];
if ($classExtXml !== false) {
    foreach ($classExtXml->extension as $ext) {
        $extByFrom[(string) $ext['from_class']] = [
            'to' => (string) $ext['to_class'],
            'active' => (string) $ext['active'],
            'order' => (string) $ext['execute_order'],
        ];
    }
}

check(
    'NF\\Discord\\ApiMessage\\SyncUser is extended to the addon\'s SyncUser and is active',
    isset($extByFrom['NF\Discord\ApiMessage\SyncUser'])
        && $extByFrom['NF\Discord\ApiMessage\SyncUser']['to'] === 'Cav7\DiscordSyncPatch\NF\Discord\ApiMessage\SyncUser'
        && $extByFrom['NF\Discord\ApiMessage\SyncUser']['active'] === '1',
    'a registration that goes missing must fail the build rather than ship silently'
);
// Issue #158 — the button is a form posting to an action on the public account
// controller. NF/Discord extends the same controller for its own connected-account
// actions and XenForo chains extensions, so both apply — the vendor registering
// against XF\Pub\Controller\Account and this addon against the XF 2.3 name
// XF\Pub\Controller\AccountController is not a conflict, because
// XF\Extension::addClassExtension() resolves both through XF::getClassForAlias()
// and Controller is an aliasable namespace, so they land on one chain. Pinning the
// name we actually ship keeps a rename here visible; it is not a claim that the
// other spelling would fail. Cav7/ApiKeyManager registers under the same name.
check(
    'XF\\Pub\\Controller\\AccountController is extended to the addon\'s Account and is active',
    isset($extByFrom['XF\Pub\Controller\AccountController'])
        && $extByFrom['XF\Pub\Controller\AccountController']['to'] === 'Cav7\DiscordSyncPatch\XF\Pub\Controller\Account'
        && $extByFrom['XF\Pub\Controller\AccountController']['active'] === '1',
    'the button posts to an action on this extension; a registration that goes missing takes the button with it'
);
check(
    'those two vendor classes are the only ones this addon extends',
    ($classExtXml !== false ? count($classExtXml->extension) : -1) === 2,
    'the sync message carries the claim, the account controller carries the resync action; a third seam would be a change of design'
);
check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions')) === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);
check(
    'the sync-message extension ships its _output export',
    is_file("$root/_output/class_extensions/NF-Discord-ApiMessage-SyncUser_Cav7-DiscordSyncPatch-NF-Discord-ApiMessage-SyncUser.json"),
    'a registration without its export count-mismatches in check-data-consistency instead of failing clearly here'
);
check(
    'the account-controller extension ships its _output export',
    is_file("$root/_output/class_extensions/XF-Pub-Controller-AccountController_Cav7-DiscordSyncPatch-XF-Pub-Controller-Account.json"),
    'a registration without its export count-mismatches in check-data-consistency instead of failing clearly here'
);
// Dev mode imports _output, production imports _data, so the two copies of a
// registration have to say the same thing. check-data-consistency compares
// from_class, to_class and active and stops there, which leaves execute_order free
// to drift: chain position decides whether this addon's overrides run inside or
// outside another addon's, and a dev-stack run would then exercise an order
// production never ships. Compared here field for field, the same way the template
// modification and the phrases are.
$extDrift = [];
foreach (outputItems($root, 'class_extensions') as $extOutFile) {
    $extOut = json_decode((string) @file_get_contents($extOutFile), true);
    $from = is_array($extOut) ? (string) ($extOut['from_class'] ?? '') : '';
    if (!isset($extByFrom[$from])) {
        $extDrift[] = basename($extOutFile) . ': from_class has no matching _data <extension>';
        continue;
    }
    if ((string) ($extOut['to_class'] ?? '') !== $extByFrom[$from]['to']) {
        $extDrift[] = basename($extOutFile) . ': to_class differs from _data';
    }
    if ((string) (int) ($extOut['execute_order'] ?? -1) !== $extByFrom[$from]['order']) {
        $extDrift[] = basename($extOutFile) . ': execute_order differs from _data';
    }
    if (((bool) ($extOut['active'] ?? false)) !== ($extByFrom[$from]['active'] === '1')) {
        $extDrift[] = basename($extOutFile) . ': active differs from _data';
    }
}
check(
    'each _output class extension is the same registration as its _data one, execute_order included',
    $extDrift === [],
    $extDrift === []
        ? ''
        : 'check-data-consistency compares from_class, to_class and active only, so this is the field it cannot see drift in: ' . implode('; ', $extDrift)
);

// =========================================================================
// the extension class — both overrides, both delegating
// =========================================================================
$src = (string) @file_get_contents("$root/NF/Discord/ApiMessage/SyncUser.php");
$code = stripComments($src);

check(
    'the extension extends the XFCP proxy, so it augments the vendor message rather than shadowing it',
    (bool) preg_match('/class\s+SyncUser\s+extends\s+XFCP_SyncUser/', $src)
);

// --- first half: eviction at the message entry point ---
check(
    'dispatch() is overridden and matches the vendor signature',
    (bool) preg_match('/public\s+function\s+dispatch\(\)\s*:\s*bool/', $src),
    'a mismatched signature would fatal the moment the queue worker calls dispatch()'
);
$dispatchBody = methodBody($code, 'dispatch');
check(
    'the entry point evicts the member entity before delegating',
    str_contains($dispatchBody, 'clearEntityCache(\XF\Entity\User::class)'),
    'without this a message can act on a copy of the member loaded earlier in the worker run'
);
check(
    "the entry point evicts the integration's per-member record before delegating",
    str_contains($dispatchBody, 'clearEntityCache(\NF\Discord\Entity\SyncLog::class)'),
    'evicting the member alone leaves the stale record the sync reads its own history from'
);
// The eviction is scoped on purpose: a bare clearEntityCache() drops everything the
// request holds, which is heavier per message and widens the blast radius for no
// benefit.
check(
    'the eviction is scoped by entity type, never a full identity-map clear',
    !preg_match('/clearEntityCache\(\s*\)/', $dispatchBody),
    'a full clear works but is heavier per message and widens the blast radius for no benefit'
);
check(
    'the entry point delegates to the vendor and returns its answer',
    (bool) preg_match('/return\s+parent::dispatch\(\)\s*;/', $dispatchBody),
    'the override is an eviction, not a reimplementation of the vendor entry point'
);

// --- second half: the claim ---
check(
    'syncRoles() is overridden and matches the vendor signature',
    (bool) preg_match('/protected\s+function\s+syncRoles\(\)\s*:\s*bool/', $src)
);
$syncBody = methodBody($code, 'syncRoles(');
check(
    'the role-sync override applies the claim and then delegates, in that order',
    (bool) preg_match('/applyRoleClaim\(\).*?return\s+parent::syncRoles\(\)\s*;/s', $syncBody),
    'the vendor reads the record it is allowed to remove from at the top of its own body, so the widening has to land first'
);

// The decision lives in the pure unit. The adapter fetches, assigns and delegates.
$applyBody = methodBody($code, 'applyRoleClaim');
check(
    'the adapter calls RoleClaim for the decision',
    str_contains($applyBody, 'RoleClaim::claim('),
    'which roles get claimed is decided in the unit the ordinary test run covers'
);
check(
    'the adapter passes the record, the mappings, the server being synced and the default server',
    (bool) preg_match(
        '/RoleClaim::claim\(\s*\$syncLog->discord_role_ids\s*,\s*\$this->getMappedRoleIds\(\)\s*,\s*\(int\)\s*\$serverId\s*,\s*\$serverRepo->getDefaultServerId\(\)\s*\)/s',
        $applyBody
    ),
    'guild scoping depends on the server ids reaching the unit; dropping either would claim another guild\'s roles'
);
check(
    'the claim is assigned back to the record the vendor reads',
    (bool) preg_match('/\$syncLog->discord_role_ids\s*=\s*RoleClaim::claim\(/', $applyBody)
);
// Issue #148 — an unsaved entity is not in the identity map, so the vendor's own
// findOrCreateSyncLogForGuild would build a second instance that never sees the
// claim. Saving first also keeps the insert's change-log entry empty. Pin this
// positionally: the existence guard and its save both precede the assignment.
$savePos = strpos($applyBody, '$syncLog->save()');
$existsPos = strpos($applyBody, '$syncLog->exists()');
$assignPos = strpos($applyBody, '$syncLog->discord_role_ids = RoleClaim::claim(');
check(
    'a record that does not exist yet is persisted BEFORE the claim is applied',
    $existsPos !== false && $savePos !== false && $assignPos !== false
        && $existsPos < $savePos && $savePos < $assignPos,
    'widening before the save writes a change-log entry announcing every managed role, and leaves the vendor looking up a second, unwidened instance'
);
check(
    'the adapter bails out when the guild is not one the server map knows',
    (bool) preg_match('/\$serverId\s*=\s*\$serverRepo->getServerIdFromGuildId\(\$guildId\)\s*;\s*if\s*\(\s*!\$serverId\s*\)/s', $applyBody),
    'the vendor lookup returns int(0), not false or null, for an unknown guild; a === null or === false guard would both let server 0 through'
);
check(
    'the adapter holds no decision logic of its own',
    !preg_match('/\bexplode\(\s*\'":"\'|\bexplode\(\s*\':\'/', $applyBody),
    'splitting the server prefix here would put guild scoping outside the tested unit'
);

// The mapped-role set is one small query per message, deliberately uncached: a
// mapping change then takes effect on the next message rather than the next worker
// run.
$mappedBody = methodBody($code, 'getMappedRoleIds');
check(
    'the mappings come from the user-group column, in one query',
    (bool) preg_match('/SELECT\s+nfd_server_group_ids\s+FROM\s+xf_user_group/i', $mappedBody)
        && substr_count(strtolower($mappedBody), 'select') === 1,
    'one small query per message is the whole budget for this half'
);
check(
    'the mapped-role set is not cached',
    !preg_match('/registry\(\)|\bcache\(\)/', $mappedBody),
    'caching it would delay a mapping change to the next worker run'
);
check(
    'every group with a mapping is read, not just the member\'s own groups',
    !preg_match('/user_group_id\s+IN|current_user_group_ids/i', $mappedBody),
    'a role granted by a group the member is NOT in is exactly the role that has to become removable'
);

// =========================================================================
// issue #158 — the resync action, and the three preconditions and two guards in
// front of it
// =========================================================================
$accountSrc = (string) @file_get_contents("$root/XF/Pub/Controller/Account.php");
$accountCode = stripComments($accountSrc);

check(
    'the account extension extends the XFCP proxy, so it chains with the vendor\'s own extension of that controller',
    (bool) preg_match('/class\s+Account\s+extends\s+XFCP_Account/', $accountCode)
);
check(
    'the resync action is public, which is what makes it reachable at all',
    (bool) preg_match('/public\s+function\s+actionConnectedAccountDiscordResync\s*\(/', $accountCode),
    'the account/connected-accounts route carries action_prefix=connectedAccount, which the router prepends to the "discord-resync" trail to reach this name without a route entry — but XF\\Mvc\\Dispatcher only dispatches through is_callable(), so a protected action 404s the button'
);

$resyncBody = methodBody($accountCode, 'actionConnectedAccountDiscordResync');
$assertPostPos = strpos($resyncBody, 'assertPostOnly()');
$queuePos = strpos($resyncBody, 'queueSyncJobsForUser(');
$floodPos = strpos($resyncBody, 'assertNotFlooding(');
$linkGuardPos = strpos($resyncBody, 'ConnectedAccounts[\'nfDiscord\']');
$pendingCalls = substr_count($resyncBody, 'hasPendingDiscordSync(');
$firstPendingPos = strpos($resyncBody, 'hasPendingDiscordSync(');
$lastPendingPos = strrpos($resyncBody, 'hasPendingDiscordSync(');

// Both redirects go to $connectedAccountsLink, which pins them to each other and to
// nothing else. What that variable is built from is the whole point: the member is
// being sent back to the page the button is on, to read the message and see the
// pending note. Any other route lands them somewhere with no Discord anything on it.
check(
    'both redirects land the member back on the page the button is on',
    (bool) preg_match(
        '/\$connectedAccountsLink\s*=\s*\$this->buildLink\(\s*\'account\/connected-accounts\'\s*\)\s*;/',
        $resyncBody
    ),
    'the queued message and the already-pending note are only useful next to the button and the pending indicator; sent to account/preferences instead, the member reads a confirmation about Discord on a page that mentions none'
);
check(
    'the resync action asserts a POST before it queues anything',
    $assertPostPos !== false && $queuePos !== false && $assertPostPos < $queuePos,
    'the button is a form so prefetchers and link-preview bots cannot resync on a member\'s behalf; the assertion is what makes that true'
);
// $visitor is assigned once, from the session, and never reassigned. Counted by
// pattern rather than by literal text: CI runs php -l and nothing else, so a second
// assignment written with different spacing would be invisible to a substring count
// while pointing the queueing call at another member.
preg_match_all('/\$visitor\s*=(?!=)/', $resyncBody, $visitorAssignments);
// Rooted through the repository class the call is made on, not just the method
// name: queueSyncJobsForUser() lives on Repository\Sync and getServerMap() on
// Repository\Server, and the two are fetched two lines apart here, so swapping them
// is an easy edit and a fatal call to an undefined method on whichever branch runs.
check(
    'the resync queues the vendor\'s per-user sync for the visitor, and for nobody it was handed',
    (bool) preg_match('/\$visitor\s*=\s*\\\\XF::visitor\(\)\s*;/', $resyncBody)
        && (bool) preg_match(
            '~\$syncRepo\s*=\s*\$this->repository\(\s*\\\\NF\\\\Discord\\\\Repository\\\\Sync::class\s*\)\s*;\s*\$syncRepo->queueSyncJobsForUser\(\s*\$visitor\s*\)\s*;~s',
            $resyncBody
        )
        && count($visitorAssignments[0]) === 1,
    'one press has to cover every guild the forum syncs, and it may never target another member — which holds only while $visitor is the session visitor and stays that way. Naming Repository\\Server here instead reaches a class with no queueSyncJobsForUser() on it, so every press fatals'
);

// --- the precondition: a member with no Discord account linked ---
// The template only draws the button for a linked member, but that is markup, not a
// guard: the endpoint takes a post from any member with a CSRF token. Ask here, and
// ask before the cooldown so an unlinked member does not spend one either.
check(
    'a member with no linked Discord account is turned away, ahead of both guards',
    $linkGuardPos !== false && $firstPendingPos !== false && $floodPos !== false
        && $linkGuardPos < $firstPendingPos && $linkGuardPos < $floodPos
        && (bool) preg_match(
            '/if\s*\(\s*empty\(\s*\$visitor->ConnectedAccounts\[\'nfDiscord\'\]\s*\)\s*\)\s*\{\s*return\s+\$this->error\(\s*\\\\XF::phrase\(\'cav7_discord_resync_not_linked\'\)\s*\)\s*;/s',
            $resyncBody
        ),
    'without a link the vendor still queues one row per guild — setupFromUser() returns a Noop before it records a user id and the repository queues the original message anyway — and each row carries a null user_id no lookup here can see, so both guards go blind and every press releases the cooldown'
);

// --- the precondition: the forum has no ACTIVE Discord server ---
// The fan-out iterates the server map, so an empty map queues nothing however many
// times it is pressed. What an empty map means is narrower than "no server exists":
// getServerMap() falls through to updateServerCache(), which selects
// findServersForList()->isActive(), so a row with a guild id and active = 0 lands
// here too. Answered up front rather than after the queueing call. Not because the
// answer is free — an empty map is what sends getServerMap() through
// updateServerCache(), so every press pays a Finder query and a rewrite of two
// registry keys — but because those writes overwrite rather than accumulate, where
// the alternative spends a cooldown and appends an xf_error_log row per press for a
// standing fault staff can see in the admin panel. Rooted as one pattern: the
// repository class, the lookup on it, the negated test, and the return that ends the
// action, in that order. The map is kept, not discarded: the unexplained branch below
// logs it.
$serverMapPos = strpos($resyncBody, 'getServerMap(');
check(
    'a server map with nothing active in it ends the action up front, before the cooldown is spent',
    $serverMapPos !== false && $floodPos !== false && $queuePos !== false
        && $serverMapPos < $floodPos && $serverMapPos < $queuePos
        && (bool) preg_match(
            '~\$serverRepo\s*=\s*\$this->repository\(\s*\\\\NF\\\\Discord\\\\Repository\\\\Server::class\s*\)\s*;\s*\$serverMap\s*=\s*\$serverRepo->getServerMap\(\)\s*;\s*if\s*\(\s*!\s*\$serverMap\s*\)\s*\{\s*return\s+\$this->error\(\s*\\\\XF::phrase\(\'cav7_discord_resync_no_servers\'\)\s*\)\s*;\s*\}~s',
            $resyncBody
        ),
    'getServerMap() is what the vendor fan-out iterates, so an empty one is a failure this action can name; asking after the fact instead spends the member\'s cooldown and appends an unbounded xf_error_log row per press, because assertNotFlooding() writes nothing at all for a general:bypassFloodCheck holder and there is then no flood entry to withhold'
);
check(
    'the server map is read once, and only as the precondition',
    substr_count($resyncBody, 'getServerMap(') === 1
        && substr_count($resyncBody, '\NF\Discord\Repository\Server::class') === 1,
    'a second read after the queueing call is the shape this branch was restructured out of'
);

// --- the precondition: the integration itself has no working credentials ---
// An empty server map is not the only standing fault, and it is not the one that
// wedges. Api::getDiscordConfiguration() returns null when the token, the client id,
// the client secret or the discord_server_id option is empty, independently of the
// server rows — so a rotated bot token left blank leaves a full map behind a dead
// integration. The fan-out then still queues: Api::__construct() only assigns the
// guild id inside its `if ($provider !== null)`, Api::factory($guildId, false)
// returns the Api for any non-null guild id, and Queue::queueMessage() inserts a row
// with a null guild_id and the right user_id. The pending lookup below finds that
// row, so the member is told their resync is queued — while Queue::run() opens with
// Api::factory(null, false), the one call that does return null with no
// configuration, and exits before touching the queue. Nothing removes the row,
// including run()'s own age-out branch, because run() never gets that far. Every
// later press is then refused by the pending guard, forever, with nothing in
// xf_error_log. Asked here, the member gets told the truth on the first press.
$configPos = strpos($resyncBody, 'getDiscordConfiguration(');
check(
    'an unconfigured Discord integration ends the action up front, before the cooldown is spent',
    $configPos !== false && $floodPos !== false && $firstPendingPos !== false && $queuePos !== false
        && $configPos < $firstPendingPos && $configPos < $floodPos && $configPos < $queuePos
        && (bool) preg_match(
            '~if\s*\(\s*\\\\NF\\\\Discord\\\\Api::getDiscordConfiguration\(\)\s*===\s*null\s*\)\s*\{\s*return\s+\$this->error\(\s*\\\\XF::phrase\(\'cav7_discord_resync_not_configured\'\)\s*\)\s*;\s*\}~s',
            $resyncBody
        ),
    'without this the fan-out queues a row the queue runner can never reach, the member is told the resync is queued, and the pending guard above refuses every press after it for as long as the row sits there — a permanent, silent lockout that no log row records'
);

// --- first guard: nothing is queued while a sync is already pending ---
check(
    'the pending check runs before the cooldown, not after it',
    $firstPendingPos !== false && $floodPos !== false && $firstPendingPos < $floodPos,
    'the other order charges a member five minutes of cooldown only to tell them a sync was already pending'
);
check(
    'a pending sync ends the action there, and tells the member one is already on its way',
    (bool) preg_match(
        '/if\s*\(\s*\$this->hasPendingDiscordSync\(\s*\$visitor->user_id\s*\)\s*\)\s*\{\s*return\s+\$this->redirect\(\s*\$connectedAccountsLink\s*,\s*\\\\XF::phrase\(\'cav7_discord_resync_pending\'\)\s*,?\s*\)\s*;\s*\}/s',
        $resyncBody
    ),
    'the test is positive and it returns: negate it and the button only ever queues when a sync is already pending, drop the return and every press stacks another set of messages'
);
check(
    'a pending sync is looked for before queueing, and looked for again after it',
    $pendingCalls === 2 && $firstPendingPos < $queuePos && $lastPendingPos > $queuePos,
    'the vendor queueing call returns nothing and cannot fail loudly, so a row landing is the only evidence the resync worked'
);
$pendingBody = methodBody($accountCode, 'hasPendingDiscordSync');
// One pattern over the whole statement, not three substrings, because the pieces
// only mean anything together. It pins the table, both conditions, the AND between
// them, and the bind values in the order the placeholders take them. Rooted on the
// opening bracket of the bind array so the class name has to be the vendor's root
// name and not this addon's subclass of it: NF\Discord\Repository\Queue::queueMessage()
// runs the message through resolveExtendedClassToRoot() before the insert, so the
// row never carries the extended name. "We extend that class, so surely it should
// say ours" is the likeliest edit anyone makes here, and it would make both lookups
// permanently false — the pending guard would never fire, and every successful
// resync would report itself unavailable.
check(
    'the pending lookup reads the vendor\'s live queue table, filtered to this member\'s per-user sync messages',
    (bool) preg_match(
        '~FROM\s+xf_nf_discord_queue\s+WHERE\s+class_name\s*=\s*\?\s+AND\s+user_id\s*=\s*\?\s+LIMIT\s+1\s*\'\s*,\s*\[\s*\\\\NF\\\\Discord\\\\ApiMessage\\\\SyncUser::class\s*,\s*\$userId\s*\]~',
        $pendingBody
    ),
    'OR in place of AND lets any member\'s queued sync satisfy the guard, so nobody can ever resync; the binds swapped round match nothing, so the guard goes permanently blind and every success reports failure. The vendor stores the ROOT class name for an extended message — Queue::queueMessage() resolves it before the insert — and the connected-account renderer builds its pending indicator from the same predicate; xf_nf_discord_queue_archive holds messages that have already run, so pointing at it would blind both guards at once'
);

// Polarity, pinned inside the helper as well as at the two call sites. The call-site
// pins read `if ($this->hasPendingDiscordSync(...))` and
// `if (!$this->hasPendingDiscordSync(...))`, so a `!` added to the return here flips
// both at once in the one place neither of them looks, and the suite stays green.
// What that ships: the first guard fires when nothing is pending, so every ordinary
// press is refused with cav7_discord_resync_pending, the button never queues
// anything, and no error-log row is written on the way.
check(
    'the pending lookup answers yes when a row is there, not when one is missing',
    (bool) preg_match('/return\s+\(bool\)\s*\\\\XF::db\(\)->fetchOne\(/', $pendingBody)
        && !preg_match('/return\s+!/', $pendingBody),
    'negated here, both call sites invert together: the guard refuses every press for a member with nothing pending, and the confirmation step calls a resync that queued nothing a success'
);

// --- second guard: one resync per member per five minutes ---
check(
    'the cooldown is 300 seconds, held as a class constant rather than an option',
    (bool) preg_match('/const\s+RESYNC_COOLDOWN_SECONDS\s*=\s*300\s*;/', $accountCode),
    'the addon gains no options, so that reverting it stays a single toggle'
);
check(
    'the cooldown runs through XenForo\'s flood check, keyed to this action and limited to that constant',
    (bool) preg_match('/assertNotFlooding\(\s*self::RESYNC_FLOOD_ACTION\s*,\s*self::RESYNC_COOLDOWN_SECONDS\s*\)/', $resyncBody),
    'XenForo\'s own rules then exempt anyone holding general:bypassFloodCheck, which is why the pending check exists independently'
);
check(
    'the cooldown is checked before the queueing call, not after it',
    $floodPos !== false && $queuePos !== false && $floodPos < $queuePos,
    'the check refuses by throwing, so behind the queueing call it throws having already fanned the whole thing out: the member is refused for work that was just queued, and the cooldown stops bounding queue volume at all — the one job ADR-0005 gives it'
);
preg_match('/const\s+RESYNC_FLOOD_ACTION\s*=\s*\'([^\']*)\'/', $accountCode, $floodKeyMatch);
check(
    'the flood key fits xf_flood_check.flood_action',
    isset($floodKeyMatch[1]) && $floodKeyMatch[1] !== '' && strlen($floodKeyMatch[1]) <= 25,
    'the column is varchar(25); a longer key is truncated on write and stops matching on read'
);

// --- after queueing: the vendor's session flag, and the confirmation step ---
check(
    'the vendor\'s just-associated session flag is cleared once the queueing is done',
    (bool) preg_match('/\\\\XF::session\(\)->remove\(\s*\'nfDiscordJustAssociated\'\s*\)\s*;/', $resyncBody)
        && strpos($resyncBody, 'nfDiscordJustAssociated') > $queuePos,
    'the vendor sets it whenever it queues for the visitor themselves, which a resync always is, and its connected-account renderer reads it as a fresh link: left set, every joinable server claims a join is pending and loses its Join link, on the page this action redirects the member straight to. Clearing it settles the flag and not the page — the vendor tests $justAssociated || ($isSyncing && $server.canAutoJoinUser($user)), and the right arm comes off the rows this action just wrote — so the note beside the button is what tells the member a resync does not rejoin anything'
);
check(
    'no row after queueing is the failure case: it is logged, the cooldown is handed straight back, and the member is told plainly',
    (bool) preg_match(
        '/if\s*\(\s*!\s*\$this->hasPendingDiscordSync\(\s*\$visitor->user_id\s*\)\s*\)\s*\{.*?\\\\XF::logError\(.*?\$this->releaseResyncCooldown\(\s*\$visitor->user_id\s*\)\s*;\s*return\s+\$this->error\(\s*\\\\XF::phrase\(\'cav7_discord_resync_unavailable\'\)\s*\)\s*;\s*\}/s',
        $resyncBody
    )
        && substr_count($resyncBody, 'releaseResyncCooldown(') === 1,
    'drop the ! and every success reports failure while every failure reports success; a member who is told to go to staff needs staff to have something to read, and a press that queued nothing must not cost them five minutes'
);
// The release is a statement of its own, not a conditional one. The pattern above
// counts the call once and lets `.*?` run from logError( to it, so a braceless
// `if (!$visitor->hasPermission('general', 'bypassFloodCheck')) $this->release...`
// is absorbed whole and stays green, and CI runs php -l with no style linter behind
// it. Anchored on the semicolon that ends the statement before it, so anything but a
// plain statement fails: a bare `if (...)` leaves a `)` there and a braced one leaves
// a `{`. Both are the conditional release that two earlier commits removed, and both
// leave the press that queued nothing costing the member five minutes.
check(
    'the cooldown is handed back unconditionally, not to whoever the code thinks paid one',
    (bool) preg_match('/;\s*\$this->releaseResyncCooldown\(\s*\$visitor->user_id\s*\)\s*;/', $resyncBody)
        && !preg_match('/bypassFloodCheck/', $resyncBody),
    'the delete is already the no-op it needs to be for a member who paid no cooldown, so guarding it buys nothing and costs the member five minutes whenever the guard is wrong'
);
// With the three preconditions answered up front, this branch has no cause anyone
// has an explanation for: the map held an active server, the integration had its
// credentials, the fan-out ran, and no row is there. The log has to say that rather
// than offer a theory, and it has to name the member, because the phrase the member
// is shown sends them to staff and this row is the only thing staff get to read.
// Anchored inside the logError( argument list — an unrooted pattern is satisfied by
// the $visitor->user_id in releaseResyncCooldown() two lines further down, which
// leaves a log row naming nobody free to pass.
preg_match('/\\\\XF::logError\(\s*sprintf\(\s*\'([^\']*)\'\s*,(.*?)\)\s*\)\s*;/s', $resyncBody, $logCall);
check(
    'the failure log names the member it is about',
    isset($logCall[2]) && (bool) preg_match('/\A\s*\$visitor->user_id\s*,/', $logCall[2]),
    'staff are the member\'s next step, so a row that does not say who it is about strands them: it is the only record of a press the member was told to report'
);
// The map is the state that narrows this. It was already fetched for the
// precondition, so carrying it into the log costs nothing, and the difference between
// "the map held three guilds" and "the map held one stale guild" is most of the
// diagnosis staff would otherwise have to reconstruct from a bare user id.
check(
    'the failure log carries the server map that the precondition read',
    isset($logCall[2])
        && (bool) preg_match('/count\(\s*\$serverMap\s*\)/', $logCall[2])
        && (bool) preg_match('/implode\([^)]*\$serverMap\s*\)/', $logCall[2]),
    'this branch tells staff to investigate from one row, and the only state that narrows it is the map the action fetched and then threw away'
);
check(
    'the failure log says the cause is unknown rather than explaining it away',
    isset($logCall[1])
        && str_contains($logCall[1], 'Cav7/DiscordSyncPatch:')
        && (bool) preg_match('/\bunknown\b/i', $logCall[1])
        && !preg_match('/\bno action needed\b|\bmost likely\b/i', $logCall[1]),
    'the theory to resist is that the queue drained in between. It is not impossible — Entity\\Server::_postDelete() archives and deletes every row for a deleted server\'s guild, and staff deleting the last server inside this window would empty the rows and make the server-map precondition retroactively the cause — but for a row inserted milliseconds ago it is a guess, and a log row that offers it talks staff out of looking'
);
$logPos = strpos($resyncBody, '\XF::logError(');
check(
    'the log is written on the unexplained branch alone',
    substr_count($resyncBody, '\XF::logError(') === 1
        && $logPos !== false && $lastPendingPos !== false && $logPos > $lastPendingPos,
    'the empty server map returns before this point and writes nothing; a log call anywhere a member can reach without a fault is an unbounded xf_error_log write, because XF\\Error::logException inserts every call with no dedupe'
);
$queuedPhrasePos = strpos($resyncBody, 'cav7_discord_resync_queued');
check(
    'the queued message is only reached once a row has been confirmed',
    $queuedPhrasePos !== false && $lastPendingPos < $queuedPhrasePos
        && (bool) preg_match(
            '/return\s+\$this->redirect\(\s*\$connectedAccountsLink\s*,\s*\\\\XF::phrase\(\'cav7_discord_resync_queued\'\)\s*,?\s*\)\s*;/s',
            $resyncBody
        ),
    'reporting this phrase anywhere else tells a member a sync is coming that nothing queued'
);
$releaseBody = methodBody($accountCode, 'releaseResyncCooldown');
check(
    'the release deletes this member\'s flood entry for this action only',
    (bool) preg_match(
        '/\\\\XF::db\(\)->delete\(\s*\'xf_flood_check\'\s*,\s*\'user_id = \? AND flood_action = \?\'\s*,\s*\[\s*\$userId\s*,\s*self::RESYNC_FLOOD_ACTION\s*\]\s*,?\s*\)\s*;/s',
        $releaseBody
    ),
    'the verb is half the method: any other db() call over the same table and binds still reads as scoped and still leaves the cooldown spent. Narrowing the WHERE to user_id alone leaves the flood action in the bind array and still looks scoped, while wiping every cooldown the member holds — their posting cooldown included'
);

// =========================================================================
// issue #158 — the surface: one template modification, the phrases behind it, and no
// options. How many of each is asserted below rather than counted here, so the
// inventory a reader checks against cannot disagree with the checks themselves.
// =========================================================================
$tmXml = @simplexml_load_file("$root/_data/template_modifications.xml");
check('_data/template_modifications.xml could be read', $tmXml !== false);

$mods = [];
if ($tmXml !== false) {
    foreach ($tmXml->modification as $mod) {
        $mods[(string) $mod['modification_key']] = $mod;
    }
}
$resyncMod = $mods['cav7DiscordSyncPatchResyncButton'] ?? null;

check(
    'the button is added by one enabled public modification of the vendor\'s associated-account template',
    $resyncMod !== null
        && (string) $resyncMod['type'] === 'public'
        && (string) $resyncMod['template'] === 'connected_account_associated_nfDiscord'
        && (string) $resyncMod['enabled'] === '1',
    'that template renders exactly when a Discord account is linked, which is when the button should be offered and never otherwise'
);
check(
    'the addon modifies no other template',
    count($mods) === 1,
    'the button is the whole surface; a second modification would be a change of design'
);
check(
    'the modification is anchored at the end of the template rather than inside the vendor markup',
    $resyncMod !== null
        && (string) $resyncMod['action'] === 'preg_replace'
        && trim((string) $resyncMod->find) === '/\z/',
    'an anchor inside the joinable-servers block would take the button away whenever that list is empty'
);

$modReplace = $resyncMod !== null ? (string) $resyncMod->replace : '';
// connected_account_associated_nfDiscord has two callers, not one. The member's own
// account/connected-accounts page is the intended one. The other is the ADMIN
// template user_extra, which loops the viewed member's associated providers and
// calls {$provider.renderAssociated($user)|raw} — and for this provider that lands
// in NF\Discord\ConnectedAccount\Provider\Discord::renderAssociated(), which
// overrides the abstract one and renders this same public template with the VIEWED
// user bound to $user. That override is also where the two other vendor facts these
// checks lean on live: it is where $syncingServers is built, and where
// nfDiscordJustAssociated is read and unset. Ungated, an admin
// looking at a Discord-linked member is shown "Resync my Discord roles"; the link
// builds a public URL, the admin's own CSRF token is valid there and assertPostOnly()
// passes, so pressing it resyncs the ADMIN and not the member on screen. An admin
// with no Discord link of their own is told they have none while looking at a member
// who does. Both callers put $user in scope, and $xf.visitor is always available, so
// the gate is the one thing separating them.
//
// The gate does leave the button visible on one ACP screen: an admin's own user
// record, where the viewed user IS the visitor. That is harmless and correct.
// renderAssociated() renders this template as public:, so Templater::getRouter()
// picks router.public, link() emits the public URL, the CSRF cookie is shared
// between the two, and pressing it resyncs the admin — which is exactly what the
// label on it promises.
check(
    'the button renders only for the member whose account it is, never for an admin viewing them',
    (bool) preg_match(
        '~<xf:if is="\$user\.user_id ===? \$xf\.visitor\.user_id">\s*<xf:form~',
        $modReplace
    ),
    'the admin template user_extra renders this same public template for the VIEWED member, so without the gate the ACP shows a button that resyncs whoever pressed it — the admin — and tells an unlinked admin they have no Discord account while a linked member is on screen (user story 4)'
);
check(
    'the button is a form posting to the resync action, not a link to it',
    str_contains($modReplace, '<xf:form')
        && str_contains($modReplace, 'account/connected-accounts/discord-resync'),
    'a link would let prefetchers and link-preview bots queue a resync on a member\'s behalf'
);
// XF\Template\Templater::form() defaults an absent method to post, so the form is
// only a POST for as long as nothing says otherwise. Written as method="get" it
// still renders, still carries the button, and every press comes back as the
// refusal from assertPostOnly() — a control that is present, gated and inert.
preg_match('~<xf:form\b[^>]*>~', $modReplace, $formTag);
check(
    'the form posts',
    isset($formTag[0])
        && (!preg_match('~\bmethod\s*=~i', $formTag[0])
            || (bool) preg_match('~\bmethod\s*=\s*"post"~i', $formTag[0])),
    'the action asserts POST, so a GET form is refused on every press; the default is post, which makes this a pin on nothing having overridden it'
);
// The two messages the action redirects with only exist if the reply is rendered as
// JSON. XF\Mvc\Renderer\Html::renderRedirect() sets the response code and the
// Location header and does nothing with $message; XF\Mvc\Renderer\Json
// ::renderRedirect() is the only one that returns it, and that renderer is reached
// only for an XHR. XF\Template\Templater::form() adds data-xf-init="ajax-submit"
// only when ajax is set, and nothing in js/xf/core.js binds plain forms, so without
// this attribute a press is an ordinary POST and the member is told nothing at all
// about the press they just made. data-force-flash-message is the other half:
// XF.AjaxSubmit defaults forceFlashMessage to false, and on that default the
// data.message branch in js/xf/form.js calls XF.redirect() without flashing
// anything, so the phrase is fetched and thrown away one layer further out.
check(
    'the form submits over XHR and flashes the reply before it redirects',
    isset($formTag[0])
        && (bool) preg_match('~\bajax\s*=\s*"true"~i', $formTag[0])
        && (bool) preg_match('~\bdata-force-flash-message\s*=\s*"true"~i', $formTag[0]),
    'these two attributes are the whole delivery path for cav7_discord_resync_queued and cav7_discord_resync_pending. Drop ajax and the reply renders through XF\\Mvc\\Renderer\\Html, which discards the message entirely; keep ajax and drop the flash attribute and js/xf/form.js redirects on data.redirect without ever showing data.message. Either way the button works, nothing errors, and a member who presses it is told nothing'
);
// XF\Template\Templater::button() falls back to type="button" when the attribute is
// absent or empty, and a type="button" inside a form submits nothing. The mutation
// leaves a button that is present, correctly gated and correctly labelled, and does
// nothing at all when pressed — which no other check here would notice.
check(
    'the button submits the form it sits in',
    (bool) preg_match('~<xf:button\b[^>]*\btype="submit"~', $modReplace),
    'the templater defaults an absent or empty type to "button", which renders a control that looks right and submits nothing'
);
// The label is pinned to the button element, not to the modification as a whole.
// Loose, any phrase key this addon ships satisfies the phrase-reference check below
// — including cav7_discord_resync_queued, which would have the button reading "Your
// Discord resync is queued" to a member who has not pressed it yet.
check(
    'the button is labelled with the button phrase',
    (bool) preg_match('~<xf:button\b[^>]*>.*?phrase\(\s*\'cav7_discord_resync\'\s*\).*?</xf:button>~s', $modReplace),
    'every other phrase this addon ships reports on something that already happened, so any of them in the label describes a press nobody has made'
);
// Polarity, as one rooted pattern over the whole condition. Flipped to "is empty",
// the note reads "A Discord sync is already queued for your account" exactly when
// none is, and disappears the moment one is — so the page and the endpoint tell the
// member opposite things.
check(
    'a pending sync is noted beside the button, off the vendor\'s own pending indicator',
    (bool) preg_match(
        '~<xf:if is="\$syncingServers is not empty">\s*<span[^>]*>\{\{\s*phrase\(\s*\'cav7_discord_resync_pending\'\s*\)\s*\}\}</span>~',
        $modReplace
    ),
    'the member has to be able to see that the first press registered without pressing again — and the endpoint refuses a press on exactly this condition, so a negated note contradicts the refusal the member gets'
);
// Clearing nfDiscordJustAssociated only defuses one arm of the vendor's condition.
// The template tests `$justAssociated || ($isSyncing && $server.canAutoJoinUser($user))`,
// and the right arm is built from $syncingServers, which
// NF\Discord\ConnectedAccount\Provider\Discord::renderAssociated() derives from the
// very rows this action just wrote. So on an auto-join server, for a member in an
// auto-join group, the page still says "Join pending" and still withholds the
// Join/Rejoin link, while the resync queues with asNew false and SyncUser::dispatch()
// never enters the join path. Those rows are the vendor's to read and there is no
// suppressing them from here. What is ours is the note beside the button, so the note
// is where the member gets told the truth: roles yes, rejoining no.
check(
    'the button carries a note saying what a resync does and does not do',
    (bool) preg_match('~phrase\(\s*\'cav7_discord_resync_note\'\s*\)~', $modReplace),
    'the page the action redirects to renders the vendor\'s own join-pending state off the rows this action wrote, so a member who left a guild is told a join is pending by a resync that will never join them; the note is the only surface here that can say so'
);

$modOutFile = "$root/_output/template_modifications/public/cav7DiscordSyncPatchResyncButton.json";
check(
    'the modification ships its _output export',
    is_file($modOutFile)
);
// Dev mode imports _output, production imports _data. Let the two drift and the
// dev-stack run verifies bytes production never ships — a different anchor, or an
// enabled flag off on one side. check-data-consistency only counts modifications, so
// the two copies are compared here, field for field.
$modOut = json_decode((string) @file_get_contents($modOutFile), true);
check(
    'the _output copy of the modification is the same modification as the _data one',
    is_array($modOut) && $resyncMod !== null
        && ($modOut['template'] ?? null) === (string) $resyncMod['template']
        && ($modOut['description'] ?? null) === (string) $resyncMod['description']
        && (int) ($modOut['execution_order'] ?? -1) === (int) $resyncMod['execution_order']
        && ((bool) ($modOut['enabled'] ?? false)) === ((string) $resyncMod['enabled'] === '1')
        && ($modOut['action'] ?? null) === (string) $resyncMod['action']
        && ($modOut['find'] ?? null) === (string) $resyncMod->find
        && ($modOut['replace'] ?? null) === (string) $resyncMod->replace,
    'every other check on this modification reads _data only, so an anchor or a flag edited on one side alone would stay green here and change what the dev stack renders'
);

$phrasesXml = @simplexml_load_file("$root/_data/phrases.xml");
check('_data/phrases.xml could be read', $phrasesXml !== false);
$phraseText = [];
if ($phrasesXml !== false) {
    foreach ($phrasesXml->phrase as $phrase) {
        $phraseText[(string) $phrase['title']] = (string) $phrase;
    }
}
foreach ([
    'cav7_discord_resync',
    'cav7_discord_resync_not_linked',
    'cav7_discord_resync_no_servers',
    'cav7_discord_resync_not_configured',
    'cav7_discord_resync_queued',
    'cav7_discord_resync_pending',
    'cav7_discord_resync_note',
    'cav7_discord_resync_unavailable',
] as $title) {
    $phraseOutFile = "$root/_output/phrases/$title.txt";
    check(
        "the phrase '$title' ships in both _data and _output, saying the same thing",
        isset($phraseText[$title]) && is_file($phraseOutFile)
            && (string) @file_get_contents($phraseOutFile) === $phraseText[$title],
        'check-data-consistency compares phrase ids only, so wording edited on one side alone changes what the dev stack reads and not what production shows'
    );
}
check(
    'those eight phrases are the whole set',
    ($phrasesXml !== false ? count($phrasesXml->phrase) : -1) === 8,
    'the button label, the note beside it, the queued message, the pending note, the unavailable message, and the three refusals that name their own cause — no account linked, no active Discord server, and an integration with no working credentials'
);
// The three refusals a member can hit have to tell them apart, because they lead to
// different next steps: one is the member's own account, one is the forum's Discord
// server list, one is the credentials on the integration itself, and the last two are
// staff's to fix. Compared pairwise, because any two of them sharing wording sends a
// member to staff about a fault that is not theirs — or, for the not-linked one,
// sends a member who simply never linked Discord to staff about a forum-wide fault
// that does not exist. The unavailable message is kept out of the no-servers wording
// for the same reason.
$memberRefusals = [
    'cav7_discord_resync_not_linked',
    'cav7_discord_resync_no_servers',
    'cav7_discord_resync_not_configured',
];
$refusalClashes = [];
foreach ($memberRefusals as $i => $refusal) {
    if (($phraseText[$refusal] ?? '') === '') {
        $refusalClashes[] = "$refusal says nothing at all";
        continue;
    }
    foreach (array_slice($memberRefusals, $i + 1) as $other) {
        if (($phraseText[$refusal] ?? '') === ($phraseText[$other] ?? null)) {
            $refusalClashes[] = "$refusal and $other say the same thing";
        }
    }
}
check(
    'each refusal a member can hit says something the other two do not',
    $refusalClashes === [] && ($phraseText['cav7_discord_resync_no_servers'] ?? '') !== ($phraseText['cav7_discord_resync_unavailable'] ?? ''),
    $refusalClashes === []
        ? 'the no-servers refusal repeats the unavailable wording, which is the branch that says the cause is unknown'
        : 'these are the only diagnosis anyone gets, and each one has a different next step: ' . implode('; ', $refusalClashes)
);
// The server-map guard tests getServerMap(), which falls through to
// updateServerCache() and selects findServersForList()->isActive(). So an empty map
// means no ACTIVE server, not no configured server: a server row that exists with a
// guild id and active = 0 lands here too. Telling that member to have staff set a
// server up sends staff to add a duplicate row against the guild_id unique
// constraint, when the fix is to switch on the row already there.
check(
    'the no-servers refusal says what the guard actually tested, which is that no server is ACTIVE',
    (bool) preg_match('/\bactive\b/i', $phraseText['cav7_discord_resync_no_servers'] ?? '')
        && (bool) preg_match('/discord server/i', $phraseText['cav7_discord_resync_no_servers'] ?? '')
        && (bool) preg_match('/\bstaff\b/i', $phraseText['cav7_discord_resync_no_servers'] ?? '')
        && !preg_match('/set one up/i', $phraseText['cav7_discord_resync_no_servers'] ?? ''),
    'this is the whole diagnosis staff receive, and the dev stack is already in the state it describes wrongly: one server row, with a guild id, inactive. "Ask staff to set one up" then invites a second row the guild_id unique constraint refuses'
);
// The credentials refusal has to name the credentials, because the member carries it
// to staff and the two forum-wide faults are fixed in different places: this one on
// the connected-account provider, the other on the server list.
check(
    'the not-configured refusal points at the integration\'s credentials, not at the server list',
    (bool) preg_match('/credential/i', $phraseText['cav7_discord_resync_not_configured'] ?? '')
        && (bool) preg_match('/\bstaff\b/i', $phraseText['cav7_discord_resync_not_configured'] ?? ''),
    'getDiscordConfiguration() is null on an empty token, client id, client secret or discord_server_id option, none of which live on the server rows; a member sent to staff about servers has staff looking at the wrong screen'
);
// The note is the member's only warning that the page they are about to be sent back
// to may claim a join is pending. It has to name both halves: what a resync covers,
// and what it leaves them to do themselves.
check(
    'the note beside the button says a resync covers roles and does not rejoin a server',
    (bool) preg_match('/\brole/i', $phraseText['cav7_discord_resync_note'] ?? '')
        && (bool) preg_match('/\bnot\b/i', $phraseText['cav7_discord_resync_note'] ?? '')
        && (bool) preg_match('/\bserver\b/i', $phraseText['cav7_discord_resync_note'] ?? ''),
    'the README calls out the member who left a guild as the case this button does not fix, and the vendor renders "Join pending" at them anyway off the rows this action wrote; a note that only advertises the roles half leaves that contradiction unexplained'
);
// A phrase key the addon does not ship renders as the raw key, so the button would
// read "cav7_discord_resync" to every member. Nothing else here connects the
// modification's markup to the phrase set, and the dev stack is the only other
// place it would show.
preg_match_all('/phrase\(\s*\'([^\']+)\'/', $modReplace, $phraseRefs);
$missingPhraseRefs = array_values(array_diff(
    array_unique($phraseRefs[1]),
    array_keys($phraseText)
));
check(
    'every phrase the modification renders is one this addon ships',
    $phraseRefs[1] !== [] && $missingPhraseRefs === [],
    $missingPhraseRefs === []
        ? 'the modification renders no phrases at all, so the button has no label'
        : 'a key with no phrase behind it renders as the key itself: ' . implode(', ', $missingPhraseRefs)
);

// The core account/connected-accounts route already reaches the action, by prefixing
// its action_prefix onto the path that trails it, so there is nothing to register.
// An entry here would be a second way in.
$routesXml = @simplexml_load_file("$root/_data/routes.xml");
check(
    'the addon registers no route of its own',
    $routesXml !== false && count($routesXml->children()) === 0,
    'the core route already carries account/connected-accounts/discord-resync to this action'
);
$optionsXml = @simplexml_load_file("$root/_data/options.xml");
check(
    'the addon still has no options',
    $optionsXml !== false && count($optionsXml->children()) === 0,
    'the cooldown is a constant on purpose; an option would give the addon install state to unwind'
);

// =========================================================================
// the pure unit stays pure — it is only covered by the ordinary test run while
// it needs nothing from XenForo
// =========================================================================
$claimSrc = (string) @file_get_contents("$root/RoleClaim.php");
check(
    'RoleClaim has no XenForo dependency',
    $claimSrc !== ''
        && !str_contains($claimSrc, '\XF::')
        && !preg_match('/\buse\s+XF\\\\/', $claimSrc)
        && !str_contains($claimSrc, 'SV\StandardLib'),
    'the decision is only covered by the ordinary test run for as long as it runs without a stack'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
