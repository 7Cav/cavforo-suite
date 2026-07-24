<?php

/**
 * Issue #187 — exercises AuthorshipRule, the decision this addon adds to the
 * moderator log's per-action check, as a pure function.
 *
 * The rule answers one question: does this addon withhold the entry, or does it
 * hand the question on to the handler underneath? Withholding is the only answer
 * it produces on its own. Everything else defers, which is what keeps a vendor
 * handler's own rule in force.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/AuthorshipRuleTest.php
 */

namespace Cav7\ModeratorLogPatch\Tests;

require __DIR__ . '/../AuthorshipRule.php';

use Cav7\ModeratorLogPatch\AuthorshipRule;

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

// The headline case, and the whole reason the addon exists. Sticking a thread is
// not something a member can do to their own content with own-content permissions,
// so authorship never enters into it and the entry is written.
check(
    'a member with no moderator record sticking a thread is logged, even their own',
    AuthorshipRule::withholdsEntry('stick', 41, false, 41) === false,
    'this is the action the issue was reported on; withholding here reproduces the bug'
);

// The other half of ADR 0001. A member editing their own post is a member tidying
// up after themselves, not moderation, and opening the identity gate must not turn
// every self-edit on the forum into a log row.
check(
    'a member editing their own post is not logged',
    AuthorshipRule::withholdsEntry('edit', 41, false, 41) === true,
    'the log would otherwise fill with members editing their own content'
);

// The same action against somebody else's content is moderation by definition:
// reaching it needed a permission over another member's content.
check(
    'the same edit against another member\'s content is logged',
    AuthorshipRule::withholdsEntry('edit', 41, false, 77) === false,
    'an author-reachable action performed on content the actor did not write is moderation'
);

// Rule 1, and the promise that nothing regresses. For a member who holds a
// moderator record the rule never withholds, whatever the action or the authorship,
// so what they log is decided entirely by the handler underneath — which is what
// XenForo logged for them before this addon existed.
check(
    'a moderator-record holder acting on their own content is never withheld here',
    AuthorshipRule::withholdsEntry('edit', 41, true, 41) === false,
    'today\'s behaviour for record holders is preserved by deferring, not by re-deciding'
);

// The full author-reachable set from ADR 0001, each one withheld from its own
// author. Asserted item by item rather than as a list comparison, so a name dropped
// from the constant fails with the action that went missing.
foreach ([
    'edit',
    'title',
    'prefix',
    'custom_fields_edit',
    'delete_soft',
    'status',
    'priority',
    'poll_create',
    'poll_edit',
    'poll_delete',
] as $action) {
    check(
        "'$action' is author-reachable, so its author is not logged",
        AuthorshipRule::withholdsEntry($action, 41, false, 41) === true,
        'ADR 0001 lists this action as one a member can produce on their own content'
    );
}

// The other side of the same list. These are the actions that cannot be reached
// without authority over another member's content, so the actor being the author is
// beside the point and every one of them logs.
foreach ([
    'stick',
    'unstick',
    'lock',
    'unlock',
    'move',
    'discussion_type',
    'index_state',
    'approve',
    'unapprove',
    'undelete',
    'delete_hard',
    'spam_clean',
    'approved',
    'rejected',
    'warning_given',
    'reassign',
    'merge',
    'feature',
] as $action) {
    check(
        "'$action' is logged even when the actor wrote the content",
        AuthorshipRule::withholdsEntry($action, 41, false, 41) === false,
        'nothing outside the author-reachable set can be reached on your own content alone'
    );
}

// The names are exact, not prefixes. XenForo resolves a prefix change to the action
// 'prefix' and a custom-field change to 'custom_fields_edit'; a rule matching on a
// substring would also swallow, say, a future 'prefix_reset', and swallowing is the
// failure mode this addon was written to remove.
check(
    'an action that merely starts with an author-reachable name is still logged',
    AuthorshipRule::withholdsEntry('edit_history_deleted', 41, false, 41) === false,
    'matching loosely re-introduces silent drops for actions nobody reviewed'
);
check(
    'an unknown action is logged rather than withheld',
    AuthorshipRule::withholdsEntry('some_action_added_by_a_later_upgrade', 41, false, 41) === false,
    'a new logged action from an upgrade must appear in the log until somebody decides otherwise'
);

// Authorship is a comparison of two identities, and "nobody" is not an identity.
// Guest-authored content carries user_id 0, and the log's own actor column defaults
// to 0 for an actor with no id, so an equality test that accepted zero would call a
// guest the author of every guest-written post and withhold the entry.
check(
    'guest-authored content is not treated as authored by the acting member',
    AuthorshipRule::withholdsEntry('edit', 41, false, 0) === false,
    'user_id 0 is the absence of an author, not a member who happens to be id 0'
);
check(
    'content whose author cannot be read is logged rather than withheld',
    AuthorshipRule::withholdsEntry('edit', 41, false, null) === false,
    'an entity with no readable author is exactly the case where withholding would be a silent drop'
);
check(
    'an actor with no user id never matches an authorless piece of content',
    AuthorshipRule::withholdsEntry('edit', 0, false, 0) === false,
    'the user-level gate already excludes guests; this stops 0 === 0 withholding if it ever changes'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
