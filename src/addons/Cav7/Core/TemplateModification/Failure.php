<?php

namespace Cav7\Core\TemplateModification;

/**
 * One shipped modification that is not in force, and everything a reader needs
 * to decide what to do about it.
 *
 * Two kinds. A failure `atCopy()` is about one template copy that does not
 * carry the patch, and the copy is the thing to go and look at. A failure
 * `forModification()` is about the modification itself — disabled, uninstalled,
 * owned by an inactive add-on — where nothing was attempted against any copy;
 * it still names the copies it costs, so the reader can tell "every member sees
 * this" from "one inert style".
 */
class Failure
{
    /** One of the `Shape` constants. */
    public string $shape;

    public string $addOnId;

    public string $modificationKey;

    /** The template the modification targets: `public`, `email` or `admin`. */
    public string $type;

    public string $template;

    /** Why this shape was reached, in the words the operator needs. */
    public string $reason;

    /**
     * The copies in play, as `BoardFacts` describes them. Exactly one when
     * `$perCopy`; otherwise every copy the modification would have reached,
     * which is empty only when the template itself is gone.
     *
     * @var list<array{template_id: int, style_id: int, styles: list<string>}>
     */
    public array $copies;

    /** Whether this failure is about one copy, or about the modification. */
    public bool $perCopy;

    /**
     * @param list<array> $copies
     */
    protected function __construct(
        string $shape,
        array $modification,
        string $reason,
        array $copies,
        bool $perCopy
    )
    {
        $this->shape = $shape;
        $this->addOnId = $modification['addon_id'];
        $this->modificationKey = $modification['modification_key'];
        $this->type = $modification['type'];
        $this->template = $modification['template'];
        $this->reason = $reason;
        $this->copies = $copies;
        $this->perCopy = $perCopy;
    }

    public static function atCopy(string $shape, array $modification, array $copy, string $reason): self
    {
        return new self($shape, $modification, $reason, [$copy], true);
    }

    /**
     * @param list<array> $affectedCopies
     */
    public static function forModification(
        string $shape,
        array $modification,
        array $affectedCopies,
        string $reason
    ): self
    {
        return new self($shape, $modification, $reason, $affectedCopies, false);
    }

    /**
     * The whole failure on one line.
     *
     * One line because both callers need it that way: the command lists these
     * and the cron writes each into XenForo's error log, where a multi-line
     * entry is read as one wall of text.
     */
    public function line(): string
    {
        $line = sprintf(
            '%s — %s (%s), %s:%s',
            $this->shape,
            $this->modificationKey,
            $this->addOnId,
            $this->type,
            $this->template
        );

        if ($this->perCopy) {
            return $line . ', ' . self::describe($this->copies[0]) . ' — ' . $this->reason;
        }

        if ($this->copies) {
            $described = array_map([self::class, 'describe'], $this->copies);
            $line .= sprintf(', costing %d copy(ies): %s', count($this->copies), implode('; ', $described));
        }

        return $line . ' — ' . $this->reason;
    }

    /**
     * One copy, and who renders it.
     *
     * A master copy that no in-use style resolves to is still worth reporting,
     * and says so rather than reading as though nothing is affected: it is what
     * any style created without its own copy will inherit.
     */
    protected static function describe(array $copy): string
    {
        return sprintf(
            'copy %d (%s), rendered for %s',
            $copy['template_id'],
            $copy['style_id'] === 0 ? 'master' : 'style ' . $copy['style_id'],
            $copy['styles']
                ? implode(', ', $copy['styles'])
                : 'no in-use style, and inherited by any style created without its own copy'
        );
    }
}
