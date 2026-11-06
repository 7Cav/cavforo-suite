<?php

namespace Cav7\Citations\Grant;

/** What a grant form submits, plus the award list it read, so the caller holds the option. */
final class IssueRequest
{
    /**
     * @param 'award'|'record_type' $kindType
     * @param int $templateVersionId The version the form showed. A submit pins only that, if it's still current.
     * @param GrantValues $values Members as a list.
     * @param list<int> $regimentAwardIds
     */
    public function __construct(
        public readonly string $kindType,
        public readonly int $kindId,
        public readonly int $templateVersionId,
        public readonly GrantValues $values,
        public readonly array $regimentAwardIds,
    ) {
    }
}
