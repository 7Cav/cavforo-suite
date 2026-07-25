<?php

/**
 * Issue #167 — proves the resync cooldown refuses a second press inside the
 * window for BOTH kinds of member: one holding XenForo's
 * general:bypassFloodCheck permission and one without it.
 *
 * This cannot live in the addon's tests/ directory. Those run under
 * tools/run-tests.sh with bare `php` and no XenForo, and this needs a live
 * XenForo with NF/Discord installed. It is run by hand against the dev stack;
 * see tools/discord-resync-cooldown-check.sh, which feeds it over stdin so
 * nothing is ever written into the served webroot.
 *
 * Everything it needs it builds and then removes:
 *
 *  - a user group granting general:bypassFloodCheck, and two throwaway members,
 *    one in that group and one not. The permission cache is BUILT by XenForo
 *    from the group's permission entry, on the member save — never hand-edited.
 *    That is issue #167's fourth acceptance criterion, and hand-editing the
 *    cache would test the harness rather than the forum.
 *  - dummy NF/Discord credentials and one active guild. The bundle ships the
 *    integration unconfigured, and the action's own preconditions turn a press
 *    away before either guard when it is. Nothing calls out: the fpm container
 *    has no route off the machine.
 *
 * The queue is drained by hand between the two presses. Without that the
 * pending guard answers press #2 and the cooldown is never reached, so the
 * check would pass whatever the cooldown does.
 *
 * Exits non-zero on any failure.
 */

$dir = '/var/www/html';
require $dir . '/src/XF.php';

XF::start($dir);
$app = XF::setupApp('XF\Pub\App');
$app->setup();

const COOLDOWN_SECONDS = 300;
const FLOOD_ACTION = 'cav7_discord_resync';
const FIXTURE_GUILD = '000000000000000167';
const FIXTURE_TAG = 'issue167check';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $failures;

    if ($ok) {
        echo "PASS: $label\n";
    }
    else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Posts to the resync action as $user and reports what came back, as both the
 * phrase names the reply carries and the rendered text.
 *
 * Goes through the real router and the real dispatcher rather than calling the
 * method: the action's name is derived from the route's action prefix, so a
 * direct call would skip the one piece of wiring most likely to break.
 *
 * The phrase NAMES are what the checks below assert on. Rendered text is carried
 * only so a failure says what happened; asserting on it would tie this to
 * XenForo's English wording, and a phrase reworded upstream would read here as
 * the cooldown having stopped working.
 *
 * @return array{phrases: string[], text: string}
 */
function press(XF\App $app, XF\Entity\User $user): array {
    $csrfCookie = FIXTURE_TAG;
    $csrfValidator = $app['csrf.validator'];
    $token = XF::$time . ',' . $csrfValidator($csrfCookie, XF::$time);

    $request = new XF\Http\Request(
        $app->inputFilterer(),
        ['_xfToken' => $token],
        [],
        ['csrf' => $csrfCookie],
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/account/connected-accounts/discord-resync',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ]
    );

    XF::setVisitor($user);

    $dispatcher = new XF\Mvc\Dispatcher($app, $request);
    // No leading slash. With one the router matches nothing and hands back a
    // 404, which a looser reading of the reply would score as a refusal.
    $reply = $dispatcher->dispatchLoop($dispatcher->route('account/connected-accounts/discord-resync'));

    if ($reply instanceof XF\Mvc\Reply\Redirect) {
        $messages = array_filter([$reply->getMessage()]);
    } else if ($reply instanceof XF\Mvc\Reply\Error) {
        $messages = $reply->getErrors();
    } else {
        return ['phrases' => [], 'text' => 'unexpected reply ' . get_class($reply)];
    }

    $phrases = [];
    $text = [];
    foreach ($messages as $message) {
        if ($message instanceof XF\Phrase) {
            $phrases[] = $message->getName();
        }
        $text[] = strval($message);
    }

    return ['phrases' => $phrases, 'text' => implode(' | ', $text)];
}

/** Whether a press was answered by a named phrase, whatever that phrase says. */
function answeredBy(array $reply, string $phraseName): bool {
    return in_array($phraseName, $reply['phrases'], true);
}

// ------------------------------------------------------------------- fixtures

$db = XF::db();
$em = $app->em();

// Anything a previous run aborted before its teardown, so a crash costs one run
// rather than every run after it.
$createdUserIds = $db->fetchAllColumn(
    'SELECT user_id FROM xf_user WHERE username LIKE ?',
    FIXTURE_TAG . '\_%'
);
$groupId = $db->fetchOne(
    'SELECT user_group_id FROM xf_user_group WHERE title LIKE ?',
    '[' . FIXTURE_TAG . ']%'
) ?: null;
$originalProviderOptions = $db->fetchOne(
    'SELECT options FROM xf_connected_account_provider WHERE provider_id = ?',
    'nfDiscord'
);

// A run killed between writing the dummy credentials and restoring them leaves them
// in the database. Captured naively, the NEXT run would take those dummies for the
// original and write them back forever, and the stack would sit there looking
// configured — which is the one state the bundle is deliberately kept out of, because
// a configured NF/Discord is one that tries to sync real members' roles. Refuse
// instead: restoring the real value is not something to guess at.
if (str_contains(strval($originalProviderOptions), FIXTURE_TAG)) {
    fwrite(STDERR, "The nfDiscord provider still holds this check's dummy credentials, so a\n");
    fwrite(STDERR, "previous run did not finish its teardown. Restore the real options before\n");
    fwrite(STDERR, "re-running — on the shipped bundle every value is the empty string:\n");
    fwrite(STDERR, "  {\"discord_server_id\": \"\", \"client_id\": \"\", \"client_secret\": \"\", \"token\": \"\"}\n");
    exit(2);
}

// Called three ways — once up front to clear a previous run's leftovers, once from
// the finally below, and once from a shutdown handler in case of a fatal. Guarded so
// the ordinary path runs it exactly once and the handler is a backstop rather than a
// second pass.
$tornDown = false;
$teardown = function () use ($db, &$createdUserIds, &$groupId, &$tornDown, $originalProviderOptions) {
    if ($tornDown) {
        return;
    }
    $tornDown = true;

    // The integration first, and outside the loop below. It is the part that must
    // not survive a half-finished teardown: dummy credentials left behind make the
    // stack look configured to everything that reads them, where a stranded fixture
    // member is inert. A failure in here is worth hearing about, so nothing is
    // swallowed — but it can no longer be reached by one further down.
    $db->update(
        'xf_connected_account_provider',
        ['options' => $originalProviderOptions],
        'provider_id = ?',
        'nfDiscord'
    );
    $db->delete('xf_nf_discord_server', 'guild_id = ?', FIXTURE_GUILD);

    XF::registry()->delete('nfDiscordServers');
    XF::registry()->delete('nfDiscordConfigured');

    foreach ($createdUserIds as $userId) {
        $db->delete('xf_user_connected_account', 'user_id = ?', $userId);
        $db->delete('xf_flood_check', 'user_id = ?', $userId);
        $db->delete('xf_nf_discord_queue', 'user_id = ?', $userId);
        $db->delete('xf_user_group_relation', 'user_id = ?', $userId);
        $db->delete('xf_user_authenticate', 'user_id = ?', $userId);
        $db->delete('xf_user_option', 'user_id = ?', $userId);
        $db->delete('xf_user_privacy', 'user_id = ?', $userId);
        $db->delete('xf_user_profile', 'user_id = ?', $userId);
        $db->delete('xf_user', 'user_id = ?', $userId);
    }

    if ($groupId) {
        $db->delete('xf_permission_entry', 'user_group_id = ?', $groupId);
        $db->delete('xf_user_group', 'user_group_id = ?', $groupId);
    }
};

// Leave nothing behind on a fatal either — a stranded fixture group granting a
// flood bypass is exactly the kind of thing this check exists to catch.
register_shutdown_function($teardown);

try {
    // Clear any leftovers found above before building this run's fixtures, then
    // re-arm the guard so the finally below still tears THIS run down.
    $teardown();
    $tornDown = false;
    $createdUserIds = [];
    $groupId = null;
    $em->clearEntityCache();

    // The integration, configured just enough that the action's preconditions
    // let a press reach the guards.
    $db->update('xf_connected_account_provider', [
        'options' => json_encode([
            'token' => FIXTURE_TAG . '-token',
            'client_id' => FIXTURE_TAG . '-client-id',
            'client_secret' => FIXTURE_TAG . '-client-secret',
            'discord_server_id' => FIXTURE_GUILD,
        ]),
    ], 'provider_id = ?', 'nfDiscord');

    $db->insert('xf_nf_discord_server', [
        'guild_id' => FIXTURE_GUILD,
        'name' => '[' . FIXTURE_TAG . ']',
        'active' => 1,
        'joined_server' => 1,
        'announce_channel_id' => '',
        'allowed_user_group_ids' => '-1',
        'auto_join_user_group_ids' => '-1',
    ]);

    XF::registry()->delete('nfDiscordServers');
    XF::registry()->delete('nfDiscordConfigured');
    $em->clearEntityCache();

    // The group that grants the bypass, and its permission entry. Written
    // before either member is created, so the combination XenForo builds on
    // their save already carries it.
    $db->insert('xf_user_group', [
        'title' => '[' . FIXTURE_TAG . '] flood bypass',
        'display_style_priority' => 0,
        'username_css' => '',
        'user_title' => '',
    ]);
    $groupId = $db->lastInsertId();

    $db->insert('xf_permission_entry', [
        'user_group_id' => $groupId,
        'user_id' => 0,
        'permission_group_id' => 'general',
        'permission_id' => 'bypassFloodCheck',
        'permission_value' => 'allow',
        'permission_value_int' => 0,
    ]);

    $makeMember = function (string $suffix, array $secondaryGroupIds) use ($app, $em, $db, &$createdUserIds): XF\Entity\User {
        /** @var XF\Entity\User $user */
        $user = $em->create(XF\Entity\User::class);
        $user->username = FIXTURE_TAG . '_' . $suffix;
        $user->email = FIXTURE_TAG . '_' . $suffix . '@example.invalid';
        $user->user_group_id = XF\Entity\User::GROUP_REG;
        $user->secondary_group_ids = $secondaryGroupIds;
        $user->user_state = 'valid';

        // Creating the entity alone leaves the satellite rows unwritten, and a
        // visitor with a null Option relation fatals in XenForo's own
        // pre-dispatch before the action is reached. The admin user controller
        // defaults the same four.
        $user->getRelationOrDefault('Option');
        $user->getRelationOrDefault('Profile');
        $user->getRelationOrDefault('Privacy');

        // A default Auth holds no password and refuses to validate. Nothing
        // logs in as these members — the check sets the visitor directly — so
        // the value only has to satisfy the entity.
        $user->getRelationOrDefault('Auth')->setPassword(FIXTURE_TAG . '-' . XF::generateRandomString(16));

        $user->save();

        $createdUserIds[] = $user->user_id;

        // The link the action's first precondition asks for.
        $db->insert('xf_user_connected_account', [
            'user_id' => $user->user_id,
            'provider' => 'nfDiscord',
            'provider_key' => FIXTURE_TAG . '-' . $user->user_id,
            'extra_data' => '',
        ]);

        return $user;
    };

    $holder = $makeMember('holder', [$groupId]);
    $ordinary = $makeMember('ordinary', []);

    $em->clearEntityCache();

    // ---------------------------------------------------------------- checks

    $cases = [
        'a member holding general:bypassFloodCheck' => $holder->user_id,
        'a member without general:bypassFloodCheck' => $ordinary->user_id,
    ];

    foreach ($cases as $who => $userId) {
        /** @var XF\Entity\User $user */
        $user = $em->find(XF\Entity\User::class, $userId);

        // The fixture is only meaningful if the permission landed the way the
        // group says it should, so assert that before leaning on it.
        $expectBypass = ($userId === $holder->user_id);
        check(
            "$who really does " . ($expectBypass ? 'hold' : 'not hold') . ' the permission',
            $user->hasPermission('general', 'bypassFloodCheck') === $expectBypass,
            'the built permission cache disagrees with the fixture group, so the case below would prove nothing'
        );

        $db->delete('xf_flood_check', 'user_id = ? AND flood_action = ?', [$userId, FLOOD_ACTION]);
        $db->delete('xf_nf_discord_queue', 'user_id = ?', $userId);

        $first = press($app, $user);
        check(
            "$who can queue a resync",
            answeredBy($first, 'cav7_discord_resync_queued'),
            'first press answered: ' . $first['text']
        );

        // Stand in for the queue draining. The pending guard sits in front of
        // the cooldown, so without this it answers press #2 and the cooldown is
        // never reached.
        $db->delete('xf_nf_discord_queue', 'user_id = ?', $userId);

        $second = press($app, $user);
        // XenForo's own phrase, reached through responseFlooding(). Naming it is
        // what separates the cooldown refusing from any of the action's four
        // other refusals, all of which name a cav7_ phrase of their own.
        check(
            "$who is refused a second resync inside the " . COOLDOWN_SECONDS . '-second window',
            answeredBy($second, 'must_wait_x_seconds_before_performing_this_action'),
            'second press answered: ' . $second['text']
        );

        $db->delete('xf_flood_check', 'user_id = ? AND flood_action = ?', [$userId, FLOOD_ACTION]);
        $db->delete('xf_nf_discord_queue', 'user_id = ?', $userId);
    }
}
finally {
    $teardown();
}

echo "\n";

if ($failures > 0) {
    echo "$failures check(s) failed.\n";
    exit(1);
}

echo "All checks passed.\n";
