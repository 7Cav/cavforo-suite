<?php

namespace Cav7\ModeratorLogPatch;

/**
 * Issue #187 — the verification command's `--category-id <content_type>=<id>`
 * options, read and resolved.
 *
 * Pure: array and string functions, nothing else.
 */
final class CategoryOverrides
{
    /**
     * The overrides named on the command line, and everything wrong with them that
     * can be seen without the install.
     *
     * @param list<string> $pairs The raw option values, as Symfony hands them over.
     *
     * @return array{overrides: array<string, int>, errors: list<string>}
     */
    public static function parse(array $pairs): array
    {
        $overrides = [];
        $errors = [];

        foreach ($pairs as $pair) {
            $pair = (string) $pair;
            if (!preg_match('/^([A-Za-z0-9_]+)=(\d+)$/', $pair, $match)) {
                $errors[] = "--category-id expects <content_type>=<id>, got '$pair'";
                continue;
            }

            // Symfony hands the values over in the order they were typed, so a type
            // named twice used to be last-write-wins with nothing said. Either number
            // could be the one the operator meant, and the run would then report a
            // scope it did not read.
            if (isset($overrides[$match[1]])) {
                $errors[] = "--category-id names '{$match[1]}' more than once, so which id you meant is not decidable";
                continue;
            }

            $overrides[$match[1]] = (int) $match[2];
        }

        return ['overrides' => $overrides, 'errors' => $errors];
    }

    /**
     * The keys that name no registered content type.
     *
     * Matched byte for byte, deliberately: XenForo's content types are lowercase
     * and it compares them exactly, so a key differing only in case is a key
     * nothing will ever look up. Reading the pairs case-insensitively made
     * `NF_Tickets_Ticket=5` parse and then match nothing, which is the same silent
     * discard as a misspelling.
     *
     * Which types exist is a fact about the install, so the caller reads them off it
     * and hands them here. That keeps the comparison somewhere CI can run it.
     *
     * @param array<string, int> $overrides      As `parse()` answered.
     * @param list<string>       $registeredTypes The install's registered content types.
     *
     * @return list<string>
     */
    public static function unknownKeys(array $overrides, array $registeredTypes): array
    {
        return array_values(array_diff(array_keys($overrides), $registeredTypes));
    }

    /**
     * The id $type's sample content is actually looked up in.
     *
     * Only a type filed under a category can be moved by one of these options: a
     * node-filed type is narrowed by the node argument, which is validated as a
     * forum, and a type filed under neither has no column to narrow at all. Asked
     * wherever the id is needed — the search and the failure message both — because
     * a message that interpolates the argument while the search used an override
     * sends the operator to inspect a category that is innocent.
     *
     * @param string             $scope     As `ContentScope::of()` answered for $type.
     * @param array<string, int> $overrides As `parse()` answered.
     * @param int                $fallback  The scope id the arguments name for this type.
     */
    public static function effectiveId(string $type, string $scope, array $overrides, int $fallback): int
    {
        if ($scope !== ContentScope::CATEGORY) {
            return $fallback;
        }

        return $overrides[$type] ?? $fallback;
    }
}
