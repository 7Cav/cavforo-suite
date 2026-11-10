<?php

/**
 * What a render URL answers, and the name it serves under. The route, headers and cookies are
 * checked through nginx on a dev stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/RenderRouteTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/../Grant/RenderAnswer.php';
require __DIR__ . '/../Grant/RenderFilename.php';

use Cav7\Citations\Grant\RenderAnswer;
use Cav7\Citations\Grant\RenderFilename;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

foreach ([
    ['the current URL serves', ['tok', 'tok', 0, 3, 3], RenderAnswer::Serve],
    ['an old revision redirects', ['tok', 'tok', 0, 3, 2], RenderAnswer::Redirect],
    ['so does a revision from the future', ['tok', 'tok', 0, 3, 4], RenderAnswer::Redirect],
    ['a missing id is not found', [null, 'tok', 0, 0, 3], RenderAnswer::NotFound],
    ['a wrong token reads the same', ['tok', 'other', 0, 3, 3], RenderAnswer::NotFound],
    ['a wrong token never learns the revision', ['tok', 'other', 0, 3, 2], RenderAnswer::NotFound],
    ['a withdrawn grant is not found', ['tok', 'tok', 900, 3, 3], RenderAnswer::NotFound],
    ['nor does an old revision of one redirect', ['tok', 'tok', 900, 3, 2], RenderAnswer::NotFound],
] as [$label, $facts, $expected]) {
    check("answer: $label", RenderAnswer::for(...$facts) === $expected);
}

// Close to the router's slug, without its transliteration.
$slug = fn (string $value): string => urlencode(preg_replace('/ +/', '-', trim(strtolower(strtr($value, './?#', '    ')))));
$day = gmmktime(0, 0, 0, 5, 16, 2021);

foreach ([
    ['name, kind, date', ['Silas Castellan', 'Bronze Star', $day, 7], 'silas-castellan-bronze-star-2021-05-16.jpg'],
    ['no dot survives', ['S. Castellan Jr.', 'Bronze Star', $day, 7], 's-castellan-jr-bronze-star-2021-05-16.jpg'],
    ['no slash, query or hash survives', ['A/B?C#D', 'Bronze Star', $day, 7], 'a-b-c-d-bronze-star-2021-05-16.jpg'],
    ['an empty name falls back to the id', ['', 'Bronze Star', $day, 7], '7-bronze-star-2021-05-16.jpg'],
    ['so does a name that slugs to nothing', ['ÉÉÉ', 'Bronze Star', $day, 7], '7-bronze-star-2021-05-16.jpg'],
    ['a title that slugs to nothing drops out', ['Silas Castellan', '???', $day, 7], 'silas-castellan-2021-05-16.jpg'],
] as [$label, [$name, $kind, $date, $id], $expected]) {
    $actual = RenderFilename::forMember($name, $kind, $date, $id, $slug);
    check("filename: $label", $actual === $expected, $actual);
}

check(
    'filename: a regiment grant has no name',
    RenderFilename::forRegiment('Presidential Unit Citation', $day, $slug) === '7th-cavalry-regiment-presidential-unit-citation-2021-05-16.jpg'
);
check(
    'filename: a regiment title that slugs to nothing drops out',
    RenderFilename::forRegiment('???', $day, $slug) === '7th-cavalry-regiment-2021-05-16.jpg'
);

$capped = RenderFilename::forMember(str_repeat('Long Name ', 30), 'Bronze Star', $day, 7, $slug);
check('filename: capped at 150, keeping the date', strlen($capped) <= 150 && str_ends_with($capped, '-2021-05-16.jpg'), $capped);
check('filename: the cap leaves no doubled hyphen', !str_contains($capped, '--'), $capped);

exit($failures === 0 ? 0 : 1);
