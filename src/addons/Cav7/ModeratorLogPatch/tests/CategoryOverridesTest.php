<?php

/**
 * Issue #187 — the `--category-id` overrides the verification command takes, read
 * and resolved.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/CategoryOverridesTest.php
 */

namespace Cav7\ModeratorLogPatch\Tests;

use Cav7\ModeratorLogPatch\CategoryOverrides;
use Cav7\ModeratorLogPatch\ContentScope;

require __DIR__ . '/../CategoryOverrides.php';
require __DIR__ . '/../ContentScope.php';

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

// =========================================================================
// reading the pairs
// =========================================================================
check(
    'a well-formed pair is read as a type and an id',
    CategoryOverrides::parse(['nf_tickets_ticket=17'])
        === ['overrides' => ['nf_tickets_ticket' => 17], 'errors' => []],
    'this is the whole point of the option: one id for one content type'
);

$read = CategoryOverrides::parse(['nf_tickets_ticket', 'nf_calendar_event=19']);
check(
    'a pair with no id is an error, and the pairs around it are still read',
    $read['overrides'] === ['nf_calendar_event' => 19] && count($read['errors']) === 1
        && str_contains($read['errors'][0], 'nf_tickets_ticket'),
    'a run refused for one bad pair should name that pair, and naming every fault at once beats one refusal per invocation'
);
check(
    'an id that is not a number is an error rather than a 0',
    CategoryOverrides::parse(['nf_tickets_ticket=all'])['overrides'] === [],
    'PHP casts a non-numeric string to 0, and category 0 is a lookup that matches nothing while looking deliberate'
);

// Symfony hands the option values over in the order they were typed, so the last
// one used to win with nothing said. Either number could be the one the operator
// meant; the run is refused instead of guessing.
$twice = CategoryOverrides::parse(['nf_tickets_ticket=17', 'nf_tickets_ticket=19']);
check(
    'the same content type named twice is an error',
    count($twice['errors']) === 1 && str_contains($twice['errors'][0], 'nf_tickets_ticket'),
    'last-write-wins picks one of two numbers the operator gave and reports neither, and the run then claims to have checked the id it discarded'
);

// =========================================================================
// naming a type the install does not have
//
// The shape check above says nothing about the key: any word left of the `=` used
// to be accepted, stored under itself, and looked up by nothing. The run then read
// the type's category from the positional argument and reported a scoped PASS
// against content the operator had not named — the defect the provenance labels
// were added to remove, arriving through the input parser instead of the search.
// Which types exist is a fact about the install, so the caller reads them off it
// and the comparison happens here, where CI can run it.
// =========================================================================
$registered = ['nf_calendar_event', 'nf_tickets_ticket', 'thread', 'user'];

check(
    'a key no registered content type matches is reported',
    CategoryOverrides::unknownKeys(['nf_tickets_tickets' => 999999], $registered)
        === ['nf_tickets_tickets'],
    'a trailing s used to be accepted in silence, and the run then said PASS for nf_tickets_ticket against the positional category'
);
check(
    'a key differing only in case is reported too',
    CategoryOverrides::unknownKeys(['NF_Tickets_Ticket' => 5], $registered)
        === ['NF_Tickets_Ticket'],
    'content types are matched by XenForo byte for byte, so a case-insensitive read parses a key that then matches nothing'
);
check(
    'keys the install registers are not reported',
    CategoryOverrides::unknownKeys(['nf_tickets_ticket' => 17, 'nf_calendar_event' => 19], $registered) === [],
    'a correct invocation has to pass, or the option is unusable'
);
check(
    'every unknown key is named, not the first',
    CategoryOverrides::unknownKeys(['nope' => 1, 'nf_tickets_ticket' => 2, 'nope_either' => 3], $registered)
        === ['nope', 'nope_either'],
    'one refusal per typo means an operator fixing three of them runs the command three times to find out about the third'
);

// =========================================================================
// which id a type is actually looked up in
//
// The aggregate category failure used to interpolate the positional argument
// whatever had been overridden, so a run given --category-id sent the operator to
// inspect a category that had nothing to do with the miss. One resolution, asked
// wherever the id is needed, is what stops the two from disagreeing.
// =========================================================================
check(
    'a category-filed type with an override is looked up in the override',
    CategoryOverrides::effectiveId('nf_tickets_ticket', ContentScope::CATEGORY, ['nf_tickets_ticket' => 17], 19) === 17,
    'this is what the option is for'
);
check(
    'a category-filed type with no override falls back to the argument',
    CategoryOverrides::effectiveId('nf_calendar_event', ContentScope::CATEGORY, ['nf_tickets_ticket' => 17], 19) === 19,
    'the positional argument stays the common case, and an override for one type must not move another'
);
check(
    'a node-filed type is never moved by a category override',
    CategoryOverrides::effectiveId('thread', ContentScope::NODE, ['thread' => 17], 2) === 2,
    'the node argument is the one validated as a forum; a category id substituted here would read rows from a node nobody named'
);
check(
    'a type filed under neither is never moved by one either',
    CategoryOverrides::effectiveId('post', ContentScope::UNSCOPED, ['post' => 17], 0) === 0,
    'there is no column to narrow such a type, so an override for it cannot be applied and must not look as though it was'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
