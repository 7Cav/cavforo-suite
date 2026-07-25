<?php

/**
 * Issue #187 — runs the code that sits in the real call path.
 *
 * `AuthorshipLogging` is the only part of this addon XenForo actually calls, and
 * until this file existed nothing executed it: `WiringTest` matches its source text,
 * which holds the shape of the method still but says nothing about what it answers.
 * A one-line insertion above the `if ($withheld)` was enough to start logging every
 * member editing their own post again while both test runs stayed green. The same
 * was true of `ContentAuthor::userId()`, which nothing ran at all, and of
 * `HandlerCoverage`, the predicate every line the verification command prints rests
 * on.
 *
 * What makes it runnable without a stack: the trait needs no XFCP proxy, only a base
 * class declaring the two methods it overrides. So this file predefines a stub
 * `XF\Mvc\Entity\Entity`, a stub `XF\Entity\User`, and a spy base handler that counts
 * how often the addon delegates to it, then composes the real trait over the spy. The
 * questions no source-text check can ask are the ones about delegating: that a
 * withheld decision never reaches the handler underneath, and that a handler
 * answering "no" is still obeyed.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/HandlerBehaviourTest.php
 */

// ---------------------------------------------------------------------------
// Stub XenForo. Only what the trait and the author reader touch.
// ---------------------------------------------------------------------------

namespace XF\Mvc\Entity {
    class Entity
    {
        /** @var array<string, mixed> the entity's declared columns and their values */
        public array $stubColumns = [];

        public function isValidColumn($name): bool
        {
            return array_key_exists($name, $this->stubColumns);
        }

        public function get($name)
        {
            return $this->stubColumns[$name] ?? null;
        }
    }
}

namespace XF\Entity {
    class User
    {
        /** @var int */
        public $user_id = 0;

        /** @var bool */
        public $is_moderator = false;
    }
}

// ---------------------------------------------------------------------------
// The handler shapes. `SpyHandler` stands in for XF\ModeratorLog\AbstractHandler:
// it declares both methods the trait overrides, with the vendor's untyped
// signatures and the vendor's `user_id && is_moderator` answer, and it records
// whether the addon delegated to it.
// ---------------------------------------------------------------------------

namespace Cav7\ModeratorLogPatch\Tests\Fixture {

    use Cav7\ModeratorLogPatch\AuthorshipLogging;
    use XF\Entity\User;
    use XF\Mvc\Entity\Entity;

    require __DIR__ . '/../AuthorshipRule.php';
    require __DIR__ . '/../ContentAuthor.php';
    require __DIR__ . '/../AuthorshipLogging.php';
    require __DIR__ . '/../HandlerCoverage.php';
    require __DIR__ . '/../ContentScope.php';

    class SpyHandler
    {
        /** @var int how many times the addon handed the question on */
        public int $delegations = 0;

        /** @var mixed what the handler underneath answers when it is asked */
        public $answer = true;

        public function isLoggable(Entity $content, $action, User $actor)
        {
            $this->delegations++;

            return $this->answer;
        }

        public function isLoggableUser(User $actor)
        {
            return ($actor->user_id && $actor->is_moderator);
        }
    }

    /** The shape every registered handler has once the extension lands: trait on top. */
    class PatchedHandler extends SpyHandler
    {
        use AuthorshipLogging;
    }

    /** No extension. Stands for a content type nothing registered a handler for. */
    class UnpatchedHandler extends SpyHandler
    {
    }

    /** Another addon extending the same handler after this one: trait on a grandparent. */
    class LaterAddonMiddle extends PatchedHandler
    {
    }

    class LaterAddonHandler extends LaterAddonMiddle
    {
    }

    /** The trait reached through another trait, which `class_uses()` cannot see. */
    trait ComposedRule
    {
        use AuthorshipLogging;
    }

    class IndirectHandler extends SpyHandler
    {
        use ComposedRule;
    }

    /** A vendor upgrade that adds a user gate of its own, underneath ours. */
    class VendorGateHandler extends SpyHandler
    {
        public function isLoggableUser(User $actor)
        {
            return false;
        }
    }

    class PatchedOverVendorGate extends VendorGateHandler
    {
        use AuthorshipLogging;
    }
}

namespace Cav7\ModeratorLogPatch\Tests {

use Cav7\ModeratorLogPatch\ContentAuthor;
use Cav7\ModeratorLogPatch\ContentScope;
use Cav7\ModeratorLogPatch\HandlerCoverage;
use Cav7\ModeratorLogPatch\Tests\Fixture\IndirectHandler;
use Cav7\ModeratorLogPatch\Tests\Fixture\LaterAddonHandler;
use Cav7\ModeratorLogPatch\Tests\Fixture\PatchedHandler;
use Cav7\ModeratorLogPatch\Tests\Fixture\PatchedOverVendorGate;
use Cav7\ModeratorLogPatch\Tests\Fixture\UnpatchedHandler;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;

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

function member(int $userId, bool $holdsModeratorRecord = false): User
{
    $user = new User();
    $user->user_id = $userId;
    $user->is_moderator = $holdsModeratorRecord;

    return $user;
}

/** Content with an author. Pass null for guest-written content, or omit for no column. */
function content(?int $authorId): Entity
{
    $entity = new Entity();
    if ($authorId !== null) {
        $entity->stubColumns['user_id'] = $authorId;
    }

    return $entity;
}

// =========================================================================
// the user gate — the one the whole issue is about
// =========================================================================
$handler = new PatchedHandler();

check(
    'a guest fails the user gate',
    $handler->isLoggableUser(member(0)) === false,
    'the cron runner and the system actors run as a guest, and admitting them logs automated work as somebody\'s moderation'
);
check(
    'a member holding no moderator record passes the user gate',
    $handler->isLoggableUser(member(41)) === true,
    'this is the gate the addon opens: on the handler underneath the same member answers false and the entry is discarded with no exception and no warning'
);
check(
    'a member holding a moderator record still passes it',
    $handler->isLoggableUser(member(41, true)) === true,
    'nobody who was logged before this addon may stop being logged'
);
check(
    'the handler underneath would have refused the same member',
    (new UnpatchedHandler())->isLoggableUser(member(41)) === false,
    'if the base answers true already then this fixture is not the gate the addon replaces, and the assertion above proves nothing'
);

// =========================================================================
// the per-action check — withholding, and delegating
// =========================================================================

// Withholding is the only answer the addon produces on its own, and it produces it
// without asking. Asserted through the spy rather than by reading the source,
// because "returns false" and "returns false without consulting the handler" are the
// same string and different behaviours.
$handler = new PatchedHandler();
check(
    'an author-reachable action by the content\'s author is withheld',
    $handler->isLoggable(content(41), 'edit', member(41)) === false,
    'a member editing their own post is tidying up after themselves, and the log would otherwise fill with it'
);
check(
    'a withheld decision never reaches the handler underneath',
    $handler->delegations === 0,
    'the addon answers this case itself; asking as well would let a handler override a decision the addon has already made'
);

// The property the whole design rests on, and the one no source-text check can
// assert: the addon defers rather than returning true, so a handler saying "no"
// is still obeyed. Seven of the eight registered handlers have rules of their own.
$handler = new PatchedHandler();
$handler->answer = false;
check(
    'a deferred decision obeys a handler underneath that answers no',
    $handler->isLoggable(content(41), 'stick', member(77)) === false,
    'returning true instead of deferring is the single most damaging edit available here: it discards every rule the handlers underneath already apply, and the entries it then writes look correct'
);
check(
    'and it did ask',
    $handler->delegations === 1,
    'a false answer that came from the addon rather than from the handler would look identical here'
);

$handler = new PatchedHandler();
$handler->answer = true;
check(
    'a deferred decision obeys a handler underneath that answers yes',
    $handler->isLoggable(content(41), 'stick', member(77)) === true,
    'an action outside the author-reachable set is moderation whoever wrote the content'
);

// An action outside the set is not withheld even from the author. This is the bug
// the issue was reported on: sticking your own thread is moderation.
$handler = new PatchedHandler();
check(
    'an action outside the set is deferred even for the author',
    $handler->isLoggable(content(41), 'stick', member(41)) === true
        && $handler->delegations === 1,
    'withholding here reproduces the reported bug, with the addon in place'
);

// A member who holds a moderator record is decided entirely by the handler
// underneath, whatever the action and whoever wrote the content.
$handler = new PatchedHandler();
check(
    'a moderator-record holder editing their own content is deferred, not withheld',
    $handler->isLoggable(content(41), 'edit', member(41, true)) === true
        && $handler->delegations === 1,
    'today\'s behaviour for record holders is preserved by handing them to the handler underneath, not by re-deciding them'
);

// Guest-written content carries user_id 0, and an actor with no id is 0 as well. A
// bare equality would read "nobody" as a match and withhold every entry about it.
$handler = new PatchedHandler();
check(
    'guest-written content is not treated as authored by the acting member',
    $handler->isLoggable(content(0), 'edit', member(41)) === true,
    'user_id 0 is the absence of an author, not a member who happens to be id 0'
);
$handler = new PatchedHandler();
check(
    'content with no author column is deferred rather than withheld',
    $handler->isLoggable(content(null), 'edit', member(41)) === true,
    'a handler registered by a later addon is under no obligation to carry the column, and withholding on a value it could not read is a silent drop'
);

// The trait declares `: bool` because NF/Calendar's handler does. A handler
// underneath answering with something truthy has to come back as a real bool.
$handler = new PatchedHandler();
$handler->answer = 1;
check(
    'a truthy answer from the handler underneath comes back as a real bool',
    $handler->isLoggable(content(41), 'stick', member(77)) === true,
    'the declared return type is bool because the calendar handler declares one, and a subclass may not drop it'
);

// =========================================================================
// the author reader — three branches, none of them previously executed
// =========================================================================
check(
    'no user_id column reads as no author',
    ContentAuthor::userId(content(null)) === null,
    'reading a column an entity does not declare throws, from inside a save'
);
check(
    'user_id 0 reads as no author, never as member 0',
    ContentAuthor::userId(content(0)) === null,
    'handing 0 back as an author lets 0 match an actor with no id and silently withholds every entry about guest-written content'
);
check(
    'a real author reads back as that member',
    ContentAuthor::userId(content(41)) === 41,
    'the rule compares this against the actor, so anything else compares the actor against something that is not the author'
);

// =========================================================================
// the coverage predicates — what the verification command's every line rests on
// =========================================================================
check(
    'a handler composing the trait carries the rule',
    HandlerCoverage::carriesRule(new PatchedHandler()) === true
);
check(
    'a handler with no extension does not',
    HandlerCoverage::carriesRule(new UnpatchedHandler()) === false,
    'this is the answer the command reports as an uncovered content type, and it is the report worth having'
);
check(
    'the trait is found on a grandparent, not only on the object\'s own class',
    HandlerCoverage::carriesRule(new LaterAddonHandler()) === true,
    'another addon extending the same handler after this one puts its class last and ours in the middle, which is a working install and not a gap'
);
check(
    'the trait is found through an intermediate trait',
    HandlerCoverage::carriesRule(new IndirectHandler()) === true,
    'class_uses() is not transitive, so a rule composed through another trait reads as absent and the command reports a coverage gap that does not exist'
);

// The gate nothing else can see. Replacing isLoggableUser outright is correct while
// the abstract handler is its only declaration, which is true today and is an
// assumption about somebody else's code. A vendor override would be discarded here
// with no error, and every other check in the command still passes.
// The root of every fixture chain here is `SpyHandler`, which is deliberately not
// named after XenForo's abstract handler. That makes this check the behavioural form
// of "the entitled classes are read off the chain rather than named": a predicate that
// hardcoded the vendor name would not find SpyHandler entitled, and would report the
// healthy chain as discarding a gate. `WiringTest` keeps the source-text pin.
check(
    'the healthy chain has no discarded user gate',
    HandlerCoverage::discardedUserGates(new PatchedHandler()) === [],
    'the two classes entitled to declare it are the one composing our trait and the root of the chain, whatever that root is called; flagging either would make this check useless'
);
check(
    'a vendor gate underneath ours is reported',
    HandlerCoverage::discardedUserGates(new PatchedOverVendorGate())
        === ['Cav7\ModeratorLogPatch\Tests\Fixture\VendorGateHandler'],
    'this addon replaces the method rather than deferring, so a rule a vendor adds here is thrown away without a word: the exact failure the addon exists to remove'
);
// =========================================================================
// where a content type is filed, and how honestly its sample was found
//
// The verification command labels every line it prints with the sample's
// provenance, and an operator reads `scoped` as "this is the content I named". So
// the label has to be earned. The content types with no scope column, which is most
// of them, earned it for a round by being handed `scoped` unconditionally, which made
// a PASS against an arbitrary board-wide row byte-identical to one against the
// operator's own content — the exact defect the labels were added to remove.
// =========================================================================

check(
    'a type filed under a node is narrowed by the node column',
    ContentScope::of(['thread_id', 'node_id', 'title', 'user_id'])
        === ['scope' => ContentScope::NODE, 'column' => 'node_id'],
    'the node argument is the one validated as a forum before any phase runs, so a type carrying the column has to be narrowed by it'
);
check(
    'a type filed under a plainly named category is narrowed by it',
    ContentScope::of(['resource_id', 'category_id', 'user_id'])
        === ['scope' => ContentScope::CATEGORY, 'column' => 'category_id'],
    'this is the column name the argument was written for'
);
check(
    'a type filed under a prefixed category column is narrowed by it too',
    ContentScope::of(['event_id', 'event_category_id', 'user_id'])
        === ['scope' => ContentScope::CATEGORY, 'column' => 'event_category_id'],
    'the two content types filed under a category use unrelated id spaces and unrelated column names; matching only the bare name would leave one of them unscoped'
);
check(
    'a node column wins over a category column whatever the declaration order',
    ContentScope::of(['x_category_id', 'node_id'])
        === ['scope' => ContentScope::NODE, 'column' => 'node_id'],
    'walking the column list in declaration order would narrow an entity carrying both by whichever it happened to declare first, which is a fact about somebody else\'s entity and not a decision'
);
check(
    'a type filed under neither has no column to narrow on',
    ContentScope::of(['post_id', 'thread_id', 'user_id', 'message'])
        === ['scope' => ContentScope::UNSCOPED, 'column' => null],
    'a post, a profile post, its comments, a member and a ticket message are all filed under neither, so most of the registered types land here'
);
check(
    'a column merely ending in the category suffix without the separator is not one',
    ContentScope::of(['subcategory_id', 'user_id'])
        === ['scope' => ContentScope::UNSCOPED, 'column' => null],
    'narrowing on a column that is not the type\'s category would read the wrong rows and call them the operator\'s'
);

check(
    'a row found in the scope named is the only thing called scoped',
    ContentScope::provenance('node_id', true, true) === ContentScope::FROM_SCOPE,
    'this is the label a bare PASS rests on, and it means the check ran against content the operator pointed at'
);
check(
    'a type with a scope column and nothing in it falls back, and says so',
    ContentScope::provenance('node_id', false, true) === ContentScope::FROM_BOARD,
    'an id from the wrong space produces this, and an all-PASS run against content nobody asked about is what it used to produce silently'
);
check(
    'a type with no scope column is never called scoped',
    ContentScope::provenance(null, false, true) !== ContentScope::FROM_SCOPE,
    'it cannot be: there is no column to narrow on, so the sample is the newest row of that type anywhere on the board however the command was invoked'
);
check(
    'a type with no scope column is called unscoped',
    ContentScope::provenance(null, false, true) === ContentScope::FROM_ANYWHERE,
    'the label has to say how the row was actually found, and "unscoped" is both what the type is and how the sample was reached'
);
check(
    'no content of the type anywhere is fabricated, whether the type is scoped or not',
    ContentScope::provenance('node_id', false, false) === ContentScope::FABRICATED
        && ContentScope::provenance(null, false, false) === ContentScope::FABRICATED,
    'an unsaved entity runs the handler\'s real code but cannot say the content exists, and that is true of an unscoped type as much as a scoped one'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
}
