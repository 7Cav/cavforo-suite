<?php

namespace Cav7\Citations\Render;

/**
 * Resolves parsed nodes to the final text of a field. Lines are separated by
 * LF, and an empty string means the field isn't drawn.
 */
final class PatternResolver
{
    /** @param list<Literal|Placeholder|Optional> $nodes */
    public static function resolve(array $nodes, Values $values, bool $caps): string
    {
        $text = '';
        foreach ($nodes as $node) {
            $text .= $node instanceof Optional
                ? self::optional($node, $values)
                : self::element($node, $values);
        }

        return self::normalise($caps ? mb_strtoupper($text, 'UTF-8') : $text);
    }

    /** Imagick stops drawing at a stray CR, so nothing reaches it unnormalised. */
    public static function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $text);
        $text = preg_replace('/ {2,}/', ' ', $text);

        $lines = array_map(fn (string $line): string => trim($line, ' '), explode("\n", $text));

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    public static function filled(string $value): bool
    {
        return self::normalise($value) !== '';
    }

    private static function optional(Optional $segment, Values $values): string
    {
        $text = '';
        foreach ($segment->nodes as $node) {
            $value = self::element($node, $values);
            if ($node instanceof Placeholder && !self::filled($value)) {
                return '';
            }
            $text .= $value;
        }
        return $text;
    }

    private static function element(Literal|Placeholder $node, Values $values): string
    {
        return $node instanceof Literal ? $node->text : $values->placeholder($node->name);
    }
}
