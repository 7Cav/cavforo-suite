<?php

/**
 * Issue #187 — pins the wiring CI cannot run, because running it needs a live
 * XenForo plus NF/Tickets and NF/Calendar.
 *
 * Everything this addon does reaches the moderator log through class extensions
 * against handler classes it does not own. A registration that goes missing, gains
 * a typo, or loses its `active` flag produces no error anywhere: the addon is
 * enabled, the log goes quiet again, and that is the exact fault the addon exists
 * to remove. Those registrations, the two overrides, and the eight subclasses that
 * carry them are what this file holds still.
 *
 * The decision itself is not here. It lives in AuthorshipRule and is exercised for
 * real in AuthorshipRuleTest.
 *
 * What this cannot see: the other side of every seam. It reads only files inside
 * this addon, so a renamed vendor handler, a changed return type, or a different
 * answer from XenForo's class aliasing all leave it green. That is the verification
 * command's job, and why the command is a shipped file rather than a note.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/WiringTest.php
 */

namespace Cav7\ModeratorLogPatch\Tests;

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
    if (preg_match('~\n    (?:/\*\*|(?:private|protected|public)\s+(?:static\s+)?function\s)~', $body, $m, PREG_OFFSET_CAPTURE)) {
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
    check('title is "7Cav - Moderator Log Patch"', ($addon['title'] ?? '') === '7Cav - Moderator Log Patch');
    check('version_string is 1.0.0', ($addon['version_string'] ?? '') === '1.0.0');
    check(
        'version_id is a positive integer',
        is_int($addon['version_id'] ?? null) && $addon['version_id'] > 0
    );
    check(
        'requires XF 2.3.0+ (2030070)',
        (int) ($addon['require']['XF'][0] ?? 0) === 2030070
    );
    // Half the extensions target vendor handlers, and it is tempting to declare
    // those addons. It would be wrong: XenForo validates that the EXTENDING class
    // file exists and not the extended one, so a record against an absent vendor
    // handler installs and sits inert. Requiring them would make this addon
    // uninstallable on a forum that runs neither, for no gain.
    check(
        'requires XenForo and nothing else',
        array_keys($addon['require'] ?? []) === ['XF'],
        'the vendor extensions install inert when the vendor is absent, so requiring the vendors only narrows where this can be installed'
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
// the class extensions — one per registered moderator log handler
// =========================================================================
$classExtXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $classExtXml !== false);

$extByFrom = [];
$committedPairs = [];
if ($classExtXml !== false) {
    foreach ($classExtXml->extension as $ext) {
        $extByFrom[(string) $ext['from_class']] = [
            'to' => (string) $ext['to_class'],
            'active' => (string) $ext['active'],
            'order' => (string) $ext['execute_order'],
        ];
        $committedPairs[] = [(string) $ext['from_class'], (string) $ext['to_class']];
    }
}

// The eight handlers registered on the target install: five from XenForo core, two
// from NF/Tickets, one from NF/Calendar. Written down here and nowhere in the
// addon's code, which reads the list off the install instead — a hardcoded list is
// how a content type added by a later addon goes uncovered silently.
//
// Two of these from_class names have no file behind them.
// NF\Tickets\ModeratorLog\TicketHandler and MessageHandler are aliases XenForo's
// own autoloader creates when it loads Ticket.php and Message.php, and the
// extension map is keyed on what XF::getClassForAlias() answers rather than on what
// the content-type field says. Registered against the names on the files, those two
// records install, validate, export, and never load. Spelled out per handler rather
// than derived from the to_class, because deriving it would reproduce whatever
// mistake the source made. See docs/adr/0003-register-the-name-xenforo-resolves-to.md.
$expectedExtensions = [
    'NF\Calendar\ModeratorLog\EventHandler' => 'Cav7\ModeratorLogPatch\NF\Calendar\ModeratorLog\EventHandler',
    'NF\Tickets\ModeratorLog\MessageHandler' => 'Cav7\ModeratorLogPatch\NF\Tickets\ModeratorLog\Message',
    'NF\Tickets\ModeratorLog\TicketHandler' => 'Cav7\ModeratorLogPatch\NF\Tickets\ModeratorLog\Ticket',
    'XF\ModeratorLog\PostHandler' => 'Cav7\ModeratorLogPatch\XF\ModeratorLog\PostHandler',
    'XF\ModeratorLog\ProfilePostCommentHandler' => 'Cav7\ModeratorLogPatch\XF\ModeratorLog\ProfilePostCommentHandler',
    'XF\ModeratorLog\ProfilePostHandler' => 'Cav7\ModeratorLogPatch\XF\ModeratorLog\ProfilePostHandler',
    'XF\ModeratorLog\ThreadHandler' => 'Cav7\ModeratorLogPatch\XF\ModeratorLog\ThreadHandler',
    'XF\ModeratorLog\UserHandler' => 'Cav7\ModeratorLogPatch\XF\ModeratorLog\UserHandler',
];

foreach ($expectedExtensions as $from => $to) {
    check(
        "$from is extended to this addon's handler and is active",
        isset($extByFrom[$from])
            && $extByFrom[$from]['to'] === $to
            && $extByFrom[$from]['active'] === '1',
        'a registration that goes missing takes that content type out of the log again, and writes nothing to say so'
    );
}
check(
    'those eight are the whole set',
    ($classExtXml !== false ? count($classExtXml->extension) : -1) === count($expectedExtensions),
    'a ninth registration means a content type was covered without this list, and one of these missing means a content type lost its cover'
);

// ADR 0003 at the suite level: the committed rows are ordered by a byte comparison
// of from_class, then to_class, which is the order our own exports emit. Pinned here
// so this addon's own file cannot drift out of it, whatever else in the repo does or
// does not check the rule. Worth pinning because a re-export producing a diff on rows
// a change had nothing to do with is what trained people to revert those hunks by
// hand, which is how the drift the ADR describes happened in the first place.
//
// Keyed on the pair rather than on from_class alone. from_class is unique in this
// addon's file today, so a from_class sort would pass on rows the rule considers
// unordered; the pair is the identity the ADR defines and the one XenForo's own
// UNIQUE KEY uses.
$sortedPairs = $committedPairs;
usort(
    $sortedPairs,
    fn (array $a, array $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1])
);
check(
    'the committed rows are in canonical order (byte comparison of from_class, then to_class)',
    $committedPairs === $sortedPairs,
    'docs/adr/0003-canonical-class-extension-order.md: re-export rather than hand-sorting, and do not revert the reordering hunks'
);

check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions')) === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);
// Dev mode imports _output, production imports _data, so the two copies of a
// registration have to say the same thing. check-data-consistency compares
// from_class, to_class and active and stops there, which leaves execute_order free
// to drift: chain position decides whether this addon's overrides run inside or
// outside another addon's, and a dev-stack run would then exercise an order
// production never ships.
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
// the eight subclasses — each one composes the trait and holds nothing else
// =========================================================================
// The behaviour is in the trait precisely so these stay empty. A method written
// into one of them is behaviour that applies to one content type and not the other
// seven, which is the shape of the bug this addon fixes.
foreach ($expectedExtensions as $from => $to) {
    $relative = str_replace('\\', '/', substr($to, strlen('Cav7\ModeratorLogPatch\\'))) . '.php';
    $shortName = basename($relative, '.php');
    $classSrc = (string) @file_get_contents("$root/$relative");
    $classCode = stripComments($classSrc);

    check(
        "$relative exists and extends the XFCP proxy",
        (bool) preg_match('/class\s+' . preg_quote($shortName, '/') . '\s+extends\s+XFCP_' . preg_quote($shortName, '/') . '\b/', $classCode),
        'extending the vendor class directly instead of the proxy shadows the handler rather than chaining with it, and drops whatever the vendor does'
    );
    check(
        "$relative composes the shared rule",
        (bool) preg_match('/^\s*use\s+AuthorshipLogging\s*;/m', $classCode),
        'a subclass registered but not composing the trait is a class extension that chains and changes nothing'
    );
    check(
        "$relative declares no methods of its own",
        !preg_match('/\bfunction\s+\w+\s*\(/', $classCode),
        'behaviour written here applies to one content type and leaves the other seven on the old rule, which is the bug this addon fixes'
    );
}

// =========================================================================
// the trait — both overrides, and what each one does
// =========================================================================
$traitSrc = (string) @file_get_contents("$root/AuthorshipLogging.php");
$traitCode = stripComments($traitSrc);

check(
    'the behaviour is a trait, not a base class',
    (bool) preg_match('/trait\s+AuthorshipLogging\b/', $traitCode),
    'the extension chain fixes each subclass\'s parent to the XFCP proxy, so there is no parent slot a shared base class could occupy'
);

// --- the user-level gate ---
// The one this addon exists to open. It is consulted once, ahead of all three of
// the logger's entry points, and a false answer discards the entry with no
// exception and no warning.
check(
    'isLoggableUser is overridden with the vendor signature',
    (bool) preg_match('/public\s+function\s+isLoggableUser\(\s*User\s+\$actor\s*\)\s*:\s*bool/', $traitCode),
    'the parameter is typed on the base class, so a mismatch fatals the first time the logger asks'
);
$loggableUserBody = methodBody($traitCode, 'isLoggableUser');
check(
    'the user gate accepts any member with a user id, and asks nothing else',
    (bool) preg_match('/return\s*\(bool\)\s*\$actor->user_id\s*;/', $loggableUserBody)
        && !str_contains($loggableUserBody, 'is_moderator'),
    'reading is_moderator here is the gate being removed; the whole issue is that it decides whether an action is recorded at all'
);
// Guests, and the cron and system actors that run as one, have to keep failing it.
// A truthiness test on user_id is what does that, and it is the same test XenForo
// already applied. Returning a bare `true` would log every automated save on the
// forum as somebody's moderation.
check(
    'the user gate is a test on the actor, never an unconditional yes',
    !preg_match('/return\s+true\s*;/', $loggableUserBody),
    'the cron runner and the system actors run as a guest; admitting them attributes automated work to nobody and floods the log'
);

// --- the per-action check ---
check(
    'isLoggable is overridden with the vendor signature and a bool return',
    (bool) preg_match('/public\s+function\s+isLoggable\(\s*Entity\s+\$content\s*,\s*\$action\s*,\s*User\s+\$actor\s*\)\s*:\s*bool/', $traitCode),
    'NF/Calendar\'s handler declares `: bool` on this method. A subclass may add a return type its parent lacks but may not drop one its parent declared, and the trait composition is checked when the class loads — so leaving it off fatals on the first calendar moderation instead of failing a test'
);

$loggableBody = methodBody($traitCode, 'isLoggable(');
// The decision lives in the pure unit. This method fetches three values and
// delegates; every question about which actions and which actors is answered where
// the ordinary test run can see it.
check(
    'the per-action check asks AuthorshipRule for the decision',
    str_contains($loggableBody, 'AuthorshipRule::withholdsEntry('),
    'which actions are author-reachable, and how "nobody" is handled, are covered by AuthorshipRuleTest only while the rule lives in the unit'
);
check(
    'it passes the action, the actor\'s id, whether the actor holds a moderator record, and the content\'s author',
    (bool) preg_match(
        '/AuthorshipRule::withholdsEntry\(\s*\(string\)\s*\$action\s*,\s*\(int\)\s*\$actor->user_id\s*,\s*\(bool\)\s*\$actor->is_moderator\s*,\s*ContentAuthor::userId\(\s*\$content\s*\)\s*,?\s*\)/s',
        $loggableBody
    ),
    'the four inputs are the whole rule. Drop the moderator-record argument and every existing moderator starts being re-decided by a rule that never applied to them; swap the two ids and self-actions and moderation change places'
);
// The rule is asked about the RESOLVED action name, which is what the handler
// receives. Keying on an entity field instead would miss every action whose name
// differs from the field it came from: a prefix change is logged as `prefix` from a
// `prefix_id` change, and a custom-field change as `custom_fields_edit`. XenForo's
// own thread handler has that bug — its switch names `prefix_id` and
// `custom_fields`, neither of which is ever the action — so the mistake is right
// there to copy.
check(
    'the check reads the action it was handed, not a field off the entity',
    !preg_match('/\$content->(get\()?\s*[\'"](prefix_id|custom_fields|message|title|status_id)/', $loggableBody),
    'the resolved action name is the argument; picking fields off the content instead means a rename in the vendor\'s field-to-action mapping silently changes which actions are withheld'
);

// Deferral, pinned as one pattern: only a withheld decision short-circuits, and
// everything else goes to the handler underneath. `return true` in place of the
// delegation is the single most damaging edit available here — it would silently
// undo the authorship rules seven of the eight registered handlers already apply
// (XenForo's thread, post and both profile-post handlers, both ticket handlers, and
// the calendar handler; only XenForo's user handler has none), and start logging
// every member retitling their own thread.
check(
    'a withheld decision returns false, and every other case delegates to the handler underneath',
    (bool) preg_match(
        '/if\s*\(\s*\$withheld\s*\)\s*\{\s*return\s+false\s*;\s*\}\s*return\s*\(bool\)\s*parent::isLoggable\(\s*\$content\s*,\s*\$action\s*,\s*\$actor\s*\)\s*;/s',
        $loggableBody
    ),
    'returning true instead of delegating discards the rules seven of the eight handlers underneath already apply, and the entries it then writes look correct'
);
check(
    'the per-action check has exactly one delegation and no second return path',
    substr_count($loggableBody, 'parent::isLoggable(') === 1
        && substr_count($loggableBody, 'return') === 2,
    'a third return is a branch none of the checks above read'
);

// --- reading the content's author ---
// One reader, shared by the rule on the handler and by the verification command.
// Two copies is how the two ends came to disagree about what "no author" is, and
// only one of the two answers is safe: see the zero check below.
$authorSrc = (string) @file_get_contents("$root/ContentAuthor.php");
$authorCode = stripComments($authorSrc);
$authorBody = methodBody($authorCode, 'userId');
check(
    'the author reader is a static method anything can call, not a method on the trait',
    (bool) preg_match('/class\s+ContentAuthor\b/', $authorCode)
        && (bool) preg_match('/public\s+static\s+function\s+userId\(\s*Entity\s+\$content\s*\)\s*:\s*\?int/', $authorCode),
    'the verification command cannot compose the trait — it is not a log handler — so a reader living on the trait is a reader the command has to copy'
);
check(
    'the trait reads the author through it rather than walking the entity itself',
    str_contains($traitCode, 'ContentAuthor::userId(')
        && !str_contains($traitCode, "isValidColumn('user_id')"),
    'a second walk is a second answer about what "no author" is, and 0 there withholds every entry about guest-written content'
);
check(
    'the author is read from the column the handlers themselves treat as the author',
    (bool) preg_match('/\$content->get\(\s*\'user_id\'\s*\)/', $authorBody),
    'every registered handler fills content_user_id from this column; reading anything else would compare the actor against something that is not the author'
);
// A handler registered by a later addon is under no obligation to have the column,
// and reading one an entity does not declare throws — inside a save, from a
// _postSave hook. Asked for rather than assumed.
check(
    'the column is checked before it is read',
    (bool) preg_match('/if\s*\(\s*!\s*\$content->isValidColumn\(\s*\'user_id\'\s*\)\s*\)\s*\{\s*return\s+null\s*;/s', $authorBody),
    'a content type without the column would throw from inside a save rather than fail a check'
);
// Zero is not a member. Guest-authored content carries user_id 0, and the log's own
// actor column falls back to 0, so returning 0 here would let the rule read
// "nobody" as a match and withhold every entry about guest-written content. The
// unit guards this too; both ends are pinned because either one alone is enough to
// reintroduce it.
check(
    'an absent or zero author comes back as null, never as 0',
    (bool) preg_match('/return\s+\$userId\s*>\s*0\s*\?\s*\$userId\s*:\s*null\s*;/', $authorBody),
    'guest-authored content carries user_id 0; handing that back as an author lets 0 match 0 and silently withholds those entries'
);

// =========================================================================
// the pure unit stays pure — it is only covered by the ordinary test run while
// it needs nothing from XenForo
// =========================================================================
$ruleSrc = (string) @file_get_contents("$root/AuthorshipRule.php");
check(
    'AuthorshipRule has no XenForo dependency',
    $ruleSrc !== ''
        && !str_contains($ruleSrc, '\XF::')
        && !preg_match('/\buse\s+XF\\\\/', $ruleSrc)
        && !str_contains($ruleSrc, 'SV\StandardLib'),
    'the decision is only covered by the ordinary test run for as long as it runs without a stack'
);
// The list is the decision, and it is the one thing in this addon a reader is most
// likely to "tidy". Pinned here as well as in AuthorshipRuleTest: the unit test
// asserts each name behaves author-reachably, this asserts the set is exactly the
// twelve the two ADRs settled on, so a thirteenth added without a decision fails the
// build.
$ruleCode = stripComments($ruleSrc);
preg_match('/AUTHOR_REACHABLE_ACTIONS\s*=\s*\[(.*?)\]\s*;/s', $ruleCode, $listMatch);
preg_match_all('/\'([^\']+)\'/', $listMatch[1] ?? '', $actionNames);
check(
    'the author-reachable set is exactly the twelve actions ADR 0001 and ADR 0004 settled on',
    ($actionNames[1] ?? []) === [
        'edit',
        'attachment_deleted',
        'title',
        'prefix',
        'custom_fields_edit',
        'delete_soft',
        'status',
        'priority',
        'poll_create',
        'poll_edit',
        'poll_delete',
        'poll_reset',
    ],
    'adding a name stops an action being logged for the member who wrote the content, and removing one starts logging members tidying up after themselves; either is a change to the ADRs and not a tidy-up'
);
check(
    'the action match is exact, not a prefix or a substring',
    (bool) preg_match('/in_array\(\s*\$action\s*,\s*self::AUTHOR_REACHABLE_ACTIONS\s*,\s*true\s*\)/', $ruleCode)
        && !preg_match('/str_starts_with|str_contains|preg_match/', $ruleCode),
    'a loose match swallows actions nobody reviewed, and swallowing is the fault this addon removes'
);

// Rule 1, pinned positionally. The moderator-record branch has to come first and
// has to return false, which is what makes "no existing moderator can regress" true
// by construction rather than by argument: for a record holder the rule steps aside
// entirely and the handler underneath decides, exactly as it did before this addon.
$withholdsBody = methodBody($ruleCode, 'withholdsEntry');
$recordPos = strpos($withholdsBody, '$actorHoldsModeratorRecord');
$reachablePos = strpos($withholdsBody, 'isAuthorReachable');
$comparePos = strpos($withholdsBody, '$actorUserId === $contentUserId');
check(
    'a moderator-record holder is answered first, and always with "not withheld"',
    $recordPos !== false && $reachablePos !== false && $comparePos !== false
        && $recordPos < $reachablePos && $recordPos < $comparePos
        && (bool) preg_match('/if\s*\(\s*\$actorHoldsModeratorRecord\s*\)\s*\{\s*return\s+false\s*;\s*\}/s', $withholdsBody),
    'below the authorship comparison, a record holder editing their own content would be withheld here — which is a behaviour change for the five people who were being logged correctly all along'
);
check(
    'the authorship comparison requires both sides to name a member',
    (bool) preg_match(
        '/return\s+\$actorUserId\s*>\s*0\s*&&\s*\$contentUserId\s*!==\s*null\s*&&\s*\$actorUserId\s*===\s*\$contentUserId\s*;/s',
        $withholdsBody
    ),
    'a bare equality reads user_id 0 on guest-authored content as a match against an actor with no id, and withholds those entries; a loose === on mixed types would match "41" to 41 by accident in the other direction'
);

// =========================================================================
// the verification command — the only check that can see the install
// =========================================================================
$verifySrc = (string) @file_get_contents("$root/Cli/Command/VerifyCoverage.php");
$verifyCode = stripComments($verifySrc);

// It has to ship. package-addon.sh drops tests/ and docs/ from the zip, so a
// verification script placed in either is not in the released addon — and the whole
// point of it is being runnable on the install it is verifying.
check(
    'the verification command lives outside tests/ and docs/, so it ships in the release zip',
    is_file("$root/Cli/Command/VerifyCoverage.php"),
    'package-addon.sh excludes tests/ and docs/; a verifier that does not ship cannot be run where it matters'
);
// XenForo finds an addon's commands by scanning <addon>/Cli/Command for
// instantiable subclasses of the Symfony Command class. A class outside that
// directory, or one that is abstract, is simply never registered — `cmd.php list`
// would not show it and nothing would say why.
check(
    'it is an instantiable Symfony command in the directory XenForo scans',
    (bool) preg_match('/^namespace\s+Cav7\\\\ModeratorLogPatch\\\\Cli\\\\Command\s*;/m', $verifyCode)
        && (bool) preg_match('/class\s+VerifyCoverage\s+extends\s+Command\b/', $verifyCode)
        && !preg_match('/abstract\s+class/', $verifyCode),
    'XenForo scans <addon>/Cli/Command and registers instantiable Command subclasses; anything else is silently absent from cmd.php'
);
check(
    'the command name is namespaced to this addon',
    (bool) preg_match('/->setName\(\s*\'cav7-moderator-log-patch:verify\'\s*\)/', $verifyCode),
    'an unprefixed name can collide with another addon\'s command, and the loser is whichever registers second'
);

$configureBody = methodBody($verifyCode, 'configure');
foreach (['node', 'user', 'category'] as $argument) {
    check(
        "the command takes '$argument' as a required argument",
        (bool) preg_match(
            '/addArgument\(\s*\'' . $argument . '\'\s*,\s*InputArgument::REQUIRED/',
            $configureBody
        ),
        'the forum, member and category a run exercises are the operator\'s to name; baking any of them in makes the command useless on another install and puts our own board in a shipped file'
    );
}

$executeBody = methodBody($verifyCode, 'execute');
// A member holding a moderator record was already logged before this addon
// existed, so a run as one exercises nothing and passes. Refused rather than
// warned about: a verification that can go green without testing anything is worse
// than no verification.
check(
    'the command refuses to run as a member who holds a moderator record',
    (bool) preg_match('/if\s*\(\s*\$actor->is_moderator\s*\)\s*\{.*?return\s+1\s*;/s', $executeBody),
    'such a member was logged before this addon existed, so the run would pass while proving nothing about the gate the addon opens'
);

// The list of content types comes off the install. A hardcoded list is how a
// content type registered by a later addon goes uncovered with nothing to say so,
// which is the failure mode this whole addon is about.
$coverageBody = methodBody($verifyCode, 'checkCoverage');
check(
    'the content types are read from the install\'s content-type field',
    str_contains($coverageBody, "getContentTypeField('moderator_log_handler_class')"),
    'a hardcoded list cannot report the one thing worth reporting: a content type nobody registered an extension for'
);
check(
    'the command names no vendor class, and keys no lookup table by content type',
    !preg_match('/\bNF\\\\(Tickets|Calendar|Rosters|Discord)\b/', $verifyCode)
        && !preg_match('/[\'"](nf_tickets_\w+|nf_calendar_\w+|thread|post|profile_post)[\'"]\s*=>/', $verifyCode),
    'naming a vendor class here would make the command fail to load on a forum without that addon, and a table keyed by content type is the hardcoded list the coverage check is supposed to discover'
);
// Coverage is "is the rule anywhere in this handler's chain", not "is the class name
// ours". Another addon extending the same handler after this one puts its class last
// and ours in the middle, which is a working install; a name check would call it a gap.
check(
    'coverage is decided by the trait being in the handler\'s class chain',
    str_contains($coverageBody, '$this->carriesRule(')
        && (bool) preg_match('/class_uses\(\s*\$class\s*\)/', methodBody($verifyCode, 'carriesRule'))
        && (bool) preg_match('/class_parents\(\s*\$handler\s*\)/', methodBody($verifyCode, 'carriesRule')),
    'a check on the resolved class name reports a false gap the moment another addon extends the same handler after this one'
);

// The run creates a thread and has to leave nothing behind. Deleting a thread is
// itself a logged action, so the log rows have to go after the thread, not before.
$cleanupBody = methodBody($verifyCode, 'removeProbeThread');
$threadDeletePos = strpos($cleanupBody, '$thread->delete()');
$logDeletePos = strpos($cleanupBody, 'xf_moderator_log');
check(
    'cleanup deletes the throwaway thread before the log rows about it',
    $threadDeletePos !== false && $logDeletePos !== false && $threadDeletePos < $logDeletePos,
    'a hard delete writes its own delete_hard entry, so clearing the rows first leaves that one behind on every run'
);
check(
    'the end-to-end phase cleans up even when a check throws',
    (bool) preg_match('/\}\s*finally\s*\{\s*if\s*\(\s*\$threadId\s*\)\s*\{\s*\$this->removeProbeThread\(/s', $executeBody . methodBody($verifyCode, 'checkEndToEnd')),
    'without the finally, a failed run leaves a stuck thread and its log rows on the forum it was verifying'
);
// The synthetic actors exist for the length of a predicate call. Saving one would
// put a member on the forum, so the check is that nothing here ever does.
$syntheticBody = methodBody($verifyCode, 'syntheticUser');
check(
    'the synthetic actors are never saved',
    !str_contains($syntheticBody, '->save()'),
    'both gates read only user_id and is_moderator off an actor, so there is no reason to persist one and every reason not to'
);
// The sample-content phase is read-only, which is what makes it safe to point at a
// real forum. The gates are predicates; asking them costs nothing and changes
// nothing. Authorship is varied by changing who is asking, never by editing content
// that belongs to a member.
$sampleBody = methodBody($verifyCode, 'sampleContent');
check(
    'the sample-content phase writes nothing',
    !preg_match('/->save\(\)|->delete\(\)|->update\(|->insert\(/', $sampleBody),
    'this phase reads real content in the scopes the operator named; a write here would edit a member\'s thread to test a predicate'
);
$ruleCheckBody = methodBody($verifyCode, 'checkRule');
// The reason to ask about more than one action. Where the handler underneath has its
// own rule about the action being probed, that rule answers, the check passes, and
// this addon's rule was never consulted — the vendor ticket handlers withhold `edit`
// for its author on their own, so a probe on `edit` alone says nothing about the
// ticket AC's `status`. Reading the whole set off the rule also stops the probe
// falling behind a name added to it.
check(
    'the rule phase asks about every author-reachable action, not one of them',
    (bool) preg_match(
        '/foreach\s*\(\s*AuthorshipRule::AUTHOR_REACHABLE_ACTIONS\s+as\s+\$action\s*\)/',
        $ruleCheckBody
    )
        && str_contains($ruleCheckBody, '$handler->isLoggable($content, $action, $author)')
        && str_contains($ruleCheckBody, '$handler->isLoggable($content, $action, $stranger)'),
    'a single action can be answered by a coincident rule on the handler underneath, which passes the check while proving nothing about this addon\'s'
);
check(
    'the rule phase reads the author through the shared reader',
    str_contains($ruleCheckBody, 'ContentAuthor::userId(')
        && !str_contains($ruleCheckBody, 'isValidColumn'),
    'the copy this replaced returned 0 where the reader returns null, which is the difference between "no author" and a member who matches nobody'
);
check(
    'the rule phase varies who is asking rather than who wrote the content',
    !preg_match('/setTrusted|->save\(\)|->update\(/', $ruleCheckBody)
        && str_contains($ruleCheckBody, '$this->syntheticUser(')
        && substr_count($ruleCheckBody, '$this->syntheticUser(') >= 3,
    'rewriting the content\'s author to test the other branch would edit real content, and leave it edited if a later check throws'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
