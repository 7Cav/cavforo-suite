<?php

/**
 * The text pattern language: parse errors, resolving, casing, optional segments
 * and the date line shapes.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/render-pattern.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    $prefix = 'Cav7\\Citations\\Render\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../Render/' . substr($class, strlen($prefix)) . '.php';
    }
});

use Cav7\Citations\Render\Literal;
use Cav7\Citations\Render\Optional;
use Cav7\Citations\Render\PatternError;
use Cav7\Citations\Render\PatternParser;
use Cav7\Citations\Render\PatternResolver;
use Cav7\Citations\Render\Placeholder;
use Cav7\Citations\Render\RankSnapshot;
use Cav7\Citations\Render\Values;

// Date formatting must not depend on the zone.
date_default_timezone_set('Pacific/Kiritimati');

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

function values(array $overrides = []): Values
{
    $rank = $overrides['rank'] ?? new RankSnapshot('Staff Sergeant', $overrides['pay_grade'] ?? 'E-6', 'SSG');
    return new Values(
        $overrides['name'] ?? 'Ewan McQuill',
        $rank,
        $overrides['date'] ?? '2026-03-21',
        $overrides['citation_text'] ?? '',
        $overrides['inputs'] ?? [],
    );
}

function resolved(string $pattern, bool $caps, array $overrides = []): string
{
    return PatternResolver::resolve(PatternParser::parse($pattern), values($overrides), $caps);
}

// The spec's test table.
foreach ([
    ['{rank} {name}', true, [], 'STAFF SERGEANT EWAN MCQUILL'],
    ['{rank} {name}', false, [], 'Staff Sergeant Ewan McQuill'],
    ['{rank} [({pay_grade}) ]{name}', true, [], 'STAFF SERGEANT (E-6) EWAN MCQUILL'],
    ['{rank} [({pay_grade}) ]{name}', true, ['pay_grade' => ''], 'STAFF SERGEANT EWAN MCQUILL'],
    ['{rank} [({pay_grade})]', true, ['pay_grade' => ''], 'STAFF SERGEANT'],
    ['[{input:unit_line}]', true, ['inputs' => ['unit_line' => '']], ''],
    ['[{input:unit_line}]', true, ['inputs' => ['unit_line' => '   ']], ''],
    ['[{input:unit_line}]', true, [], ''],
    ['Class {input:class_number}', false, ['inputs' => ['class_number' => '005/02/19']], 'Class 005/02/19'],
    ['\[DRAFT\] {name}', false, [], '[DRAFT] Ewan McQuill'],
    ['{citation_text}', false, ['citation_text' => '{name}'], '{name}'],
    ['[{rank_abbr}: {pay_grade}]', false, ['pay_grade' => ''], ''],
    ['{rank} {name}', true, ['rank' => new RankSnapshot('Korporal', '', ''), 'name' => 'Zoë Ångström'], 'KORPORAL ZOË ÅNGSTRÖM'],
    ['{day} {month} {year}', false, ['date' => '2026-03-08'], '8 March 2026'],
    ['{month}', true, ['date' => '2026-12-01'], 'DECEMBER'],
] as [$pattern, $caps, $overrides, $expected]) {
    $got = resolved($pattern, $caps, $overrides);
    check("`$pattern` resolves to `$expected`", $got === $expected, $got);
}

foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 11 => '11th', 12 => '12th', 13 => '13th',
    21 => '21st', 22 => '22nd', 23 => '23rd', 30 => '30th', 31 => '31st'] as $day => $expected) {
    $got = resolved('{day_ordinal}', false, ['date' => sprintf('2026-03-%02d', $day)]);
    check("day $day is $expected", $got === $expected, $got);
}

$citationDate = ['date' => '2026-03-21'];
foreach ([
    'D1' => ['GIVEN UNDER MY HAND ON THIS {day_ordinal} DAY OF {month}, {year} AT FORT HOOD, TEXAS', true,
        'GIVEN UNDER MY HAND ON THIS 21ST DAY OF MARCH, 2026 AT FORT HOOD, TEXAS'],
    'D2' => ["GIVEN UNDER MY HAND IN THE CITY OF WASHINGTON, D.C.\nON THIS THE {day_ordinal} DAY OF {month} {year}", true,
        "GIVEN UNDER MY HAND IN THE CITY OF WASHINGTON, D.C.\nON THIS THE 21ST DAY OF MARCH 2026"],
    'D3' => ["GIVEN UNDER MY HAND AT 7TH CAVALRY HEADQUARTERS\nON THIS THE {day_ordinal} DAY OF {month}, {year}", true,
        "GIVEN UNDER MY HAND AT 7TH CAVALRY HEADQUARTERS\nON THIS THE 21ST DAY OF MARCH, 2026"],
    'D4' => ['GIVEN UNDER MY HAND THIS {day_ordinal} DAY OF {month}, {year}', true,
        'GIVEN UNDER MY HAND THIS 21ST DAY OF MARCH, 2026'],
    'D5' => ['Given on this {day_ordinal} day of {month}, {year}', false, 'Given on this 21st day of March, 2026'],
    'D6' => ['On this {day_ordinal} day of {month} {year}', false, 'On this 21st day of March 2026'],
    'D7' => ["GIVEN BY MY HAND\nON THIS THE {day_ordinal} DAY OF {month} {year}", true,
        "GIVEN BY MY HAND\nON THIS THE 21ST DAY OF MARCH 2026"],
    'D8' => ['ON THIS THE {day_ordinal} OF {month} {year}', true, 'ON THIS THE 21ST OF MARCH 2026'],
    'D9' => ['ON THIS {day_ordinal} DAY OF {month}, {year}', true, 'ON THIS 21ST DAY OF MARCH, 2026'],
    'D0' => ['PRESENTED BY THE REGIMENT', false, 'PRESENTED BY THE REGIMENT'],
] as $code => [$pattern, $caps, $expected]) {
    $got = resolved($pattern, $caps, $citationDate);
    check("date line $code", $got === $expected, $got);
}

// Normalising.
foreach ([
    'CRLF and CR become LF' => ["one\r\ntwo\rthree", "one\ntwo\nthree"],
    'other C0 controls and DEL go' => ["a\x01b\x1Fc\x7Fd", 'abcd'],
    'a tab becomes a space' => ["a\tb", 'a b'],
    'runs of spaces collapse' => ['a  b   c', 'a b c'],
    'lines are trimmed, a run of blank lines becomes one' => ["\n  a  \n\n   \n b \n", "a\n\nb"],
    'a control between spaces leaves one space' => ["a \x01 b", 'a b'],
    'U+00A0 survives' => ["a\u{A0}b", "a\u{A0}b"],
    'a percent and a quote are untouched' => ['100% of "targets"', '100% of "targets"'],
] as $label => [$text, $expected]) {
    $got = resolved('{citation_text}', false, ['citation_text' => $text]);
    check($label, $got === $expected, json_encode($got));
}

check(
    'an optional holding only control characters is not filled',
    resolved('{rank}[ ({input:note})]', false, ['inputs' => ['note' => "\x01"]]) === 'Staff Sergeant'
);

// The parsed shape stage 2 and the publish checks read.
check(
    'a pattern parses to literal, placeholder and optional nodes',
    PatternParser::parse('Rank {rank} [({pay_grade}) ]') == [
        new Literal('Rank '),
        new Placeholder('rank'),
        new Literal(' '),
        new Optional([new Literal('('), new Placeholder('pay_grade'), new Literal(') ')]),
    ]
);
check('an empty pattern has no nodes', PatternParser::parse('') === []);
check(
    'a newline in a literal stays in the literal',
    PatternParser::parse("a\nb") == [new Literal("a\nb")]
);

// Parse errors, with the character offset counted in characters.
foreach ([
    'unknown placeholder' => ['Hi {nope}', 3],
    'an input key with a capital' => ['{input:Unit}', 0],
    'an input key too long' => ['{input:' . str_repeat('a', 51) . '}', 0],
    'an empty placeholder' => ['{}', 0],
    'unclosed {' => ['abc {name', 4],
    'unclosed [' => ['ab [{name}', 3],
    'stray }' => ['ab}', 2],
    'stray ]' => ['ab]', 2],
    '[ inside a segment' => ['[{name}[{rank}]]', 7],
    'a segment with no placeholder' => ['ab [literal]', 3],
    'an empty segment' => ['[]', 0],
    'a backslash before another character' => ['ab\\n', 2],
    'a backslash at the end' => ['ab\\', 2],
    'an offset counts characters, not bytes' => ['Éé{nope}', 2],
] as $label => [$pattern, $offset]) {
    try {
        PatternParser::parse($pattern);
        check("$label is refused", false, 'parsed');
    } catch (PatternError $e) {
        check("$label is refused at $offset", $e->charOffset === $offset, (string) $e->charOffset);
    }
}

foreach (['2026-13-01', '2026-02-30', '21 March 2026', ''] as $date) {
    try {
        resolved('{day}', false, ['date' => $date]);
        check("date `$date` is refused", false);
    } catch (\InvalidArgumentException) {
        check("date `$date` is refused", true);
    }
}

exit($failures === 0 ? 0 : 1);
