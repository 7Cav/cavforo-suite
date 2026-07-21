<?php

/**
 * Issue #148 — pins the vendor-coupled half of the fix, the part CI cannot run
 * because it needs a live XenForo plus NF/Discord. The decision itself is
 * exercised for real in RoleClaimTest; what is held here is the wiring that
 * carries it: the class extension against the vendor's per-user sync message,
 * both method overrides on that extension, and the properties of the adapter that
 * a dev-stack run confirmed and a later edit could quietly undo.
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
// the class extension — one seam, on the vendor's per-user sync message
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
check(
    'the per-user sync message is the only class this addon extends',
    ($classExtXml !== false ? count($classExtXml->extension) : -1) === 1,
    'both halves live on that one message; a second seam would be a change of design'
);
check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions')) === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);
check(
    'the extension ships its _output export',
    is_file("$root/_output/class_extensions/NF-Discord-ApiMessage-SyncUser_Cav7-DiscordSyncPatch-NF-Discord-ApiMessage-SyncUser.json"),
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
    'the vendor lookup answers false, not null, for an unknown guild; a === null guard would let server 0 through'
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
