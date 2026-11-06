<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;

/** Which fields to set, on the shared row and on each member's. */
final class RemakePlan
{
    /**
     * @param list<TextField|SignatureSlot|RankArtField> $shared
     * @param array<int, list<TextField|SignatureSlot|RankArtField>> $members By the key in GrantValues::$members.
     */
    public function __construct(
        public readonly array $shared,
        public readonly array $members,
    ) {
    }

    /** @param list<int> $memberKeys */
    public static function everything(Version $version, array $memberKeys): self
    {
        return new self(
            FieldDependencies::sharedFields($version),
            array_fill_keys($memberKeys, FieldDependencies::memberFields($version)),
        );
    }

    /** One member's rows, with the shared rows reused as stored. */
    public static function forMember(Version $version, int $memberKey): self
    {
        return new self([], [$memberKey => FieldDependencies::memberFields($version)]);
    }
}
