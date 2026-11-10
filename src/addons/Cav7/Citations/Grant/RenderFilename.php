<?php

namespace Cav7\Citations\Grant;

/**
 * The download name, which is also the URL's last segment. The router takes a dot as a
 * response type and the rest of a path as more path, so it holds neither.
 */
final class RenderFilename
{
    private const LIMIT = 150;

    /** @param \Closure(string): string $slug */
    public static function forMember(string $name, string $kindTitle, int $grantDate, int $grantMemberId, \Closure $slug): string
    {
        $who = self::part($name, $slug);

        return self::join([$who !== '' ? $who : (string) $grantMemberId, self::part($kindTitle, $slug)], $grantDate);
    }

    /** @param \Closure(string): string $slug */
    public static function forRegiment(string $kindTitle, int $grantDate, \Closure $slug): string
    {
        return self::join(['7th-cavalry-regiment', self::part($kindTitle, $slug)], $grantDate);
    }

    /** @param \Closure(string): string $slug */
    private static function part(string $value, \Closure $slug): string
    {
        return trim(preg_replace('/%[0-9A-Fa-f]{2}|[^a-z0-9-]/', '', $slug($value)), '-');
    }

    /** @param list<string> $parts The date survives the cap, so one citation's files stay apart. */
    private static function join(array $parts, int $grantDate): string
    {
        $tail = '-' . gmdate('Y-m-d', $grantDate) . '.jpg';
        $head = substr(implode('-', array_filter($parts, 'strlen')), 0, self::LIMIT - strlen($tail));

        return rtrim($head, '-') . $tail;
    }
}
