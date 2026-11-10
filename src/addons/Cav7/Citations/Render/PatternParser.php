<?php

namespace Cav7\Citations\Render;

/**
 * Turns a text pattern into Literal, Placeholder and Optional nodes, or throws
 * a PatternError carrying the character offset.
 */
final class PatternParser
{
    public const NAMES = [
        'name', 'rank', 'pay_grade', 'rank_abbr',
        'day', 'day_ordinal', 'month', 'year',
        'citation_text',
    ];

    private const INPUT_NAME = '/^input:[a-z][a-z0-9_]{0,49}$/';

    /** @return list<Literal|Placeholder|Optional> */
    public static function parse(string $pattern): array
    {
        $chars = mb_str_split($pattern, 1, 'UTF-8');
        $nodes = [];
        $segment = null;
        $segmentAt = 0;
        $literal = '';

        $flush = function () use (&$nodes, &$segment, &$literal): void {
            if ($literal === '') {
                return;
            }
            if ($segment === null) {
                $nodes[] = new Literal($literal);
            } else {
                $segment[] = new Literal($literal);
            }
            $literal = '';
        };

        for ($i = 0, $count = count($chars); $i < $count; $i++) {
            $char = $chars[$i];
            switch ($char) {
                case '\\':
                    $next = $chars[$i + 1] ?? '';
                    if ($next === '' || !str_contains('\\{}[]', $next)) {
                        throw new PatternError('A backslash escapes only \\ { } [ ]', $i);
                    }
                    $literal .= $next;
                    $i++;
                    break;

                case '{':
                    $length = array_search('}', array_slice($chars, $i + 1), true);
                    if ($length === false) {
                        throw new PatternError('Unclosed {', $i);
                    }
                    $name = implode('', array_slice($chars, $i + 1, $length));
                    if (!self::known($name)) {
                        throw new PatternError("Unknown placeholder {{$name}}", $i);
                    }
                    $flush();
                    if ($segment === null) {
                        $nodes[] = new Placeholder($name);
                    } else {
                        $segment[] = new Placeholder($name);
                    }
                    $i += $length + 1;
                    break;

                case '[':
                    if ($segment !== null) {
                        throw new PatternError('[ inside an optional segment', $i);
                    }
                    $flush();
                    $segment = [];
                    $segmentAt = $i;
                    break;

                case ']':
                    if ($segment === null) {
                        throw new PatternError('Stray ]', $i);
                    }
                    $flush();
                    if (!array_filter($segment, fn (object $node): bool => $node instanceof Placeholder)) {
                        throw new PatternError('An optional segment holds no placeholder', $segmentAt);
                    }
                    $nodes[] = new Optional($segment);
                    $segment = null;
                    break;

                case '}':
                    throw new PatternError('Stray }', $i);

                default:
                    $literal .= $char;
            }
        }

        if ($segment !== null) {
            throw new PatternError('Unclosed [', $segmentAt);
        }
        $flush();

        return $nodes;
    }

    /** @return list<string> Every placeholder name in the pattern, in order. */
    public static function placeholders(string $pattern): array
    {
        $names = [];
        foreach (self::parse($pattern) as $node) {
            foreach ($node instanceof Optional ? $node->nodes : [$node] as $element) {
                if ($element instanceof Placeholder) {
                    $names[] = $element->name;
                }
            }
        }
        return $names;
    }

    private static function known(string $name): bool
    {
        return in_array($name, self::NAMES, true) || preg_match(self::INPUT_NAME, $name) === 1;
    }
}
