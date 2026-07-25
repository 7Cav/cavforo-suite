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

// Rule 1 (the three branches are numbered in AuthorshipRule's class docblock), and
// the promise that nothing regresses. For a member who holds a moderator record the
// rule never withholds, whatever the action or the authorship, so what they log is
// decided entirely by the handler underneath — which is what XenForo logged for them
// before this addon existed.
check(
    'a moderator-record holder acting on their own content is never withheld here',
    AuthorshipRule::withholdsEntry('edit', 41, true, 41) === false,
    'today\'s behaviour for record holders is preserved by deferring, not by re-deciding'
);

// Resetting a poll and deleting one are the same button behind the same single
// permission check: the poll controller asks canDelete() once and then branches on
// a form field to either the deleter or the resetter. So a thread starter who can
// delete their own poll can reset it. XenForo then treats the two differently — the
// deleter service skips its own log call for the author and the resetter service
// has no such guard — which is why poll_reset is the name this list is load-bearing
// for and poll_delete is belt-and-braces. Here they answer alike, which is the
// point: the rule does not inherit the vendor's inconsistency.
check(
    'resetting your own poll is not logged, exactly as deleting it is not',
    AuthorshipRule::withholdsEntry('poll_reset', 41, false, 41) === true
        && AuthorshipRule::withholdsEntry('poll_delete', 41, false, 41) === true,
    'both come out of one canDelete() check, which passes the thread author while nobody has voted'
);

// The spam check runs during the member's own save and sends their content back to
// the approval queue, which the handlers resolve to `unapprove`. So a member can
// produce it on their own content with no permission over anybody else's, and before
// this rule the log recorded them as having unapproved their own post.
check(
    'tripping the spam filter on your own content is not logged as unapproving it',
    AuthorshipRule::withholdsEntry('unapprove', 41, false, 41) === true,
    'the same save resolves the message change to `edit` and withholds it, so the entry appeared alone: an action the member never took'
);
check(
    'somebody else unapproving your content is still logged',
    AuthorshipRule::withholdsEntry('unapprove', 41, false, 77) === false,
    'sending another member\'s content back to the queue is moderation, and adding the name to the list must not touch that'
);

// XenForo's own post handler cases 'edit' and 'attachment_deleted' together and
// withholds both from the author, so core treats removing your own attachment as
// author-reachable. The profile-post and profile-post-comment handlers have no such
// rule, which is why the decision has to be made here rather than deferred.
check(
    'removing an attachment from your own content is not logged',
    AuthorshipRule::withholdsEntry('attachment_deleted', 41, false, 41) === true,
    'core withholds this for a post author; the profile-post handlers log it unguarded, so tidying your own profile post would be recorded'
);
check(
    'removing an attachment from somebody else\'s content is logged',
    AuthorshipRule::withholdsEntry('attachment_deleted', 41, false, 77) === false,
    'reaching another member\'s attachment took a permission over their content'
);

// The full author-reachable set, each one withheld from its own author.
// Asserted item by item rather than as a list comparison, so a name
// dropped from the constant fails with the action that went missing.
foreach ([
    'edit',
    'attachment_deleted',
    'title',
    'prefix',
    'custom_fields_edit',
    'delete_soft',
    'unapprove',
    'status',
    'priority',
    'poll_create',
    'poll_edit',
    'poll_delete',
    'poll_reset',
] as $action) {
    check(
        "'$action' is author-reachable, so its author is not logged",
        AuthorshipRule::withholdsEntry($action, 41, false, 41) === true,
        'this action is one a member can produce on their own content'
    );
}

// The other side of the same list. These are the actions that cannot be reached
// without authority over another member's content, so the actor being the author is
// beside the point and every one of them logs. `approve` is here on purpose and not
// beside `unapprove`: every path that puts content back to visible needs authority
// over it, so the pair is asymmetric because the spam check is.
foreach ([
    'stick',
    'unstick',
    'lock',
    'unlock',
    'move',
    'discussion_type',
    'index_state',
    'approve',
    'undelete',
    'delete_hard',
    'spam_clean',
    'approved',
    'rejected',
    'warning_given',
    'reassign',
    'merge_target',
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
