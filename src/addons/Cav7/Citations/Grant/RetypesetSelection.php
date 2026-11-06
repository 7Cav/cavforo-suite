<?php

namespace Cav7\Citations\Grant;

/** The retypeset command's flags, refused before anything runs. */
final class RetypesetSelection
{
    public const GRANT = 'grant';
    public const TEMPLATE_VERSION = 'template-version';
    public const ADDON_VERSION = 'addon-version';

    /**
     * @param self::GRANT|self::TEMPLATE_VERSION|self::ADDON_VERSION $kind
     * @param list<int> $ids
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $ids,
        public readonly bool $dryRun,
        public readonly string $reason,
    ) {
    }

    /**
     * @param list<string> $grants
     * @throws \InvalidArgumentException
     */
    public static function parse(array $grants, ?string $templateVersion, ?string $addonVersion, bool $dryRun, ?string $reason): self
    {
        $chosen = array_filter([
            self::GRANT => $grants,
            self::TEMPLATE_VERSION => $templateVersion === null ? [] : [$templateVersion],
            self::ADDON_VERSION => $addonVersion === null ? [] : [$addonVersion],
        ]);

        if (count($chosen) !== 1) {
            throw new \InvalidArgumentException('Give exactly one of --grant, --template-version or --addon-version.');
        }

        $reason = trim($reason ?? '');
        if (!$dryRun && $reason === '') {
            throw new \InvalidArgumentException('Give --dry-run, or --reason to write.');
        }

        $kind = array_key_first($chosen);
        $ids = [];
        foreach ($chosen[$kind] as $id) {
            if (!ctype_digit($id)) {
                throw new \InvalidArgumentException("--$kind takes an id, not '$id'.");
            }
            $ids[] = (int) $id;
        }

        return new self($kind, $ids, $dryRun, $reason);
    }

    /** The selector as an operator typed it, for the log. */
    public function label(): string
    {
        return implode(' ', array_map(fn (int $id): string => "--{$this->kind}=$id", $this->ids));
    }
}
