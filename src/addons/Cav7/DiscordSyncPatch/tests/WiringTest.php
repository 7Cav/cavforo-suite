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
 * public account controller, the action the button posts to, its two guards, and
 * the template modification and phrases that put it on the page.
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
// actions and XenForo chains extensions, so both apply. The modern class name is
// the one to register against: XF 2.3 renamed the controller, and Cav7/ApiKeyManager
// registers its own extension of it under the same name.
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
// issue #158 — the resync action, and the two guards in front of it
// =========================================================================
$accountSrc = (string) @file_get_contents("$root/XF/Pub/Controller/Account.php");
$accountCode = stripComments($accountSrc);

check(
    'the account extension extends the XFCP proxy, so it chains with the vendor\'s own extension of that controller',
    (bool) preg_match('/class\s+Account\s+extends\s+XFCP_Account/', $accountSrc)
);
check(
    'the resync action is named for the path account/connected-accounts/discord-resync',
    (bool) preg_match('/function\s+actionConnectedAccountDiscordResync\s*\(/', $accountSrc),
    'XenForo resolves the nested path segments to this method name, which is why no route entry is needed; renaming it unroutes the button'
);

$resyncBody = methodBody($accountCode, 'actionConnectedAccountDiscordResync');
$assertPostPos = strpos($resyncBody, 'assertPostOnly()');
$queuePos = strpos($resyncBody, 'queueSyncJobsForUser(');
check(
    'the resync action asserts a POST before it does anything else',
    $assertPostPos !== false && $queuePos !== false && $assertPostPos < $queuePos,
    'the button is a form so prefetchers and link-preview bots cannot resync on a member\'s behalf; the assertion is what makes that true'
);
check(
    'the resync queues the vendor\'s per-user sync for the visitor, across every configured guild',
    (bool) preg_match('/queueSyncJobsForUser\(\s*\$visitor\s*\)/', $resyncBody),
    'one press has to cover every guild the forum syncs, and it may never target another member'
);

// --- first guard: nothing is queued while a sync is already pending ---
$pendingCalls = substr_count($resyncBody, 'hasPendingDiscordSync(');
$firstPendingPos = strpos($resyncBody, 'hasPendingDiscordSync(');
$lastPendingPos = strrpos($resyncBody, 'hasPendingDiscordSync(');
check(
    'a pending sync is looked for before queueing, and looked for again after it',
    $pendingCalls === 2 && $firstPendingPos < $queuePos && $lastPendingPos > $queuePos,
    'the vendor queueing call returns nothing and cannot fail loudly, so a row landing is the only evidence the resync worked'
);
$pendingBody = methodBody($accountCode, 'hasPendingDiscordSync');
check(
    'the pending lookup is the vendor\'s own: its queue table, filtered to this member\'s per-user sync messages',
    str_contains($pendingBody, 'xf_nf_discord_queue')
        && str_contains($pendingBody, '\NF\Discord\ApiMessage\SyncUser::class')
        && str_contains($pendingBody, 'user_id = ?'),
    'the vendor stores the root class name for an extended message, and the connected-account renderer builds its pending indicator from exactly this lookup'
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
    'XenForo\'s own rules then exempt staff holding the flood-bypass permission, which is why the pending check exists independently'
);
preg_match('/const\s+RESYNC_FLOOD_ACTION\s*=\s*\'([^\']*)\'/', $accountCode, $floodKeyMatch);
check(
    'the flood key fits xf_flood_check.flood_action',
    isset($floodKeyMatch[1]) && $floodKeyMatch[1] !== '' && strlen($floodKeyMatch[1]) <= 25,
    'the column is varchar(25); a longer key is truncated on write and stops matching on read'
);
check(
    'a resync that queued nothing releases the cooldown instead of spending it, and says so',
    (bool) preg_match('/releaseResyncCooldown\([^)]*\)\s*;\s*return\s+\$this->error\(/s', $resyncBody),
    'a member whose resync could not be queued must be able to retry once the problem is fixed'
);
$releaseBody = methodBody($accountCode, 'releaseResyncCooldown');
check(
    'the release clears this member\'s flood entry for this action only',
    str_contains($releaseBody, 'xf_flood_check')
        && str_contains($releaseBody, 'self::RESYNC_FLOOD_ACTION')
        && str_contains($releaseBody, 'user_id = ?'),
    'clearing more than the one entry would hand back cooldowns the member is still owed elsewhere'
);

// =========================================================================
// issue #158 — the surface: one template modification, four phrases, no options
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
check(
    'the button is a form posting to the resync action, not a link to it',
    str_contains($modReplace, '<xf:form')
        && str_contains($modReplace, 'account/connected-accounts/discord-resync'),
    'a link would let prefetchers and link-preview bots queue a resync on a member\'s behalf'
);
check(
    'a pending sync is noted beside the button, off the vendor\'s own pending indicator',
    str_contains($modReplace, '$syncingServers')
        && str_contains($modReplace, 'cav7_discord_resync_pending'),
    'the member has to be able to see that the first press registered without pressing again'
);
check(
    'the modification ships its _output export',
    is_file("$root/_output/template_modifications/public/cav7DiscordSyncPatchResyncButton.json")
);

$phrasesXml = @simplexml_load_file("$root/_data/phrases.xml");
check('_data/phrases.xml could be read', $phrasesXml !== false);
$phraseTitles = [];
if ($phrasesXml !== false) {
    foreach ($phrasesXml->phrase as $phrase) {
        $phraseTitles[] = (string) $phrase['title'];
    }
}
foreach ([
    'cav7_discord_resync',
    'cav7_discord_resync_queued',
    'cav7_discord_resync_pending',
    'cav7_discord_resync_unavailable',
] as $title) {
    check(
        "the phrase '$title' ships in both _data and _output",
        in_array($title, $phraseTitles, true) && is_file("$root/_output/phrases/$title.txt")
    );
}
check(
    'those four phrases are the whole set',
    count($phraseTitles) === 4,
    'the button label, the queued message, the pending note and the unavailable message'
);

// The action reaches its own name through the account route's nested path segments,
// so there is nothing to register. An entry here would be a second way in.
$routesXml = @simplexml_load_file("$root/_data/routes.xml");
check(
    'the addon registers no route of its own',
    $routesXml !== false && count($routesXml->children()) === 0,
    'XenForo resolves account/connected-accounts/discord-resync to the action name on its own'
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
