<?php

namespace Cav7\ModeratorLogPatch;

/**
 * Issue #187 — where a content type is filed, and how honestly a sample of it was
 * found.
 *
 * The verification command reads one piece of sample content per registered content
 * type and asks the gates about it. A PASS is only worth what the sample is worth, so
 * every line the command prints about a type carries how that type's sample was
 * reached. Both decisions behind that are pure — one reads a column list, the other
 * reads two booleans — so they live here rather than on the command, which can only
 * be run by hand against a live forum. See `HandlerCoverage` for the same argument
 * about the class chain.
 *
 * Two vocabularies, and keeping them apart is the point. A **scope** is a property of
 * the content type: a thread is filed under a node, a calendar event under a
 * category, a post under neither. A **provenance** is a property of one sample: it
 * says how the row in hand was found. A type filed under nothing has no column to
 * narrow on, so its sample cannot be scoped however the command was invoked — the one
 * thing it must not be labelled is `scoped`, which is what an operator reads as "this
 * is the content I asked about".
 *
 * Pure: array and string functions, nothing else.
 */
final class ContentScope
{
    /** Filed under a node, so the node argument narrows it. */
    public const NODE = 'node';

    /** Filed under a category, so the category argument narrows it. */
    public const CATEGORY = 'category';

    /** Filed under neither, so neither argument narrows it. */
    public const UNSCOPED = 'unscoped';

    /** Real content from the scope the operator named. The only provenance worth a bare PASS. */
    public const FROM_SCOPE = 'scoped';

    /**
     * Real content of the right type from outside the scope named, which is what an
     * id from the wrong space produces.
     */
    public const FROM_BOARD = 'board';

    /**
     * Real content of a type that has no scope column at all, so the newest row of it
     * anywhere on the board is everything there is to read. Deliberately the same
     * name as the scope: the type being unscoped is the entire reason the sample is.
     */
    public const FROM_ANYWHERE = self::UNSCOPED;

    /** An unsaved entity, used only when the board holds no content of the type. */
    public const FABRICATED = 'fabricated';

    /**
     * The column that narrows $columns to the scope the operator named, and which
     * scope that is.
     *
     * Tested by name rather than by walking the list in declaration order: an entity
     * carrying both would otherwise be narrowed by whichever column it happened to
     * declare first. A node beats a category, because a content type declaring both
     * is a node-filed one and the node argument is the one validated.
     *
     * @param list<string> $columns The entity's declared column names.
     *
     * @return array{scope: string, column: string|null}
     */
    public static function of(array $columns): array
    {
        if (\in_array('node_id', $columns, true)) {
            return ['scope' => self::NODE, 'column' => 'node_id'];
        }

        foreach ($columns as $name) {
            if ($name === 'category_id' || substr($name, -12) === '_category_id') {
                return ['scope' => self::CATEGORY, 'column' => $name];
            }
        }

        return ['scope' => self::UNSCOPED, 'column' => null];
    }

    /**
     * How the sample in hand was found.
     *
     * `$scopeColumn` is what `of()` answered, so null means the content type has no
     * scope column. That case is the one this method exists for: the sample is the
     * newest row of its type anywhere on the board, which is a real row and not a
     * fabricated one, and is still not content anybody asked for. Calling it
     * `scoped` made a board-wide sample indistinguishable from the operator's own,
     * which is the failure the provenance labels were added to remove.
     *
     * @param string|null $scopeColumn  The column that narrows this type, or null when it has none.
     * @param bool        $foundInScope Whether a row was found in the scope named. Always false when there is no column.
     * @param bool        $foundOnBoard Whether a row of this type was found anywhere.
     */
    public static function provenance(?string $scopeColumn, bool $foundInScope, bool $foundOnBoard): string
    {
        if ($scopeColumn !== null && $foundInScope) {
            return self::FROM_SCOPE;
        }

        if (!$foundOnBoard) {
            return self::FABRICATED;
        }

        return $scopeColumn === null ? self::FROM_ANYWHERE : self::FROM_BOARD;
    }
}
