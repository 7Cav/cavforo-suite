<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\PatternResolver;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\TemplateChoices;
use Cav7\Citations\Template\VersionLifecycle;

/**
 * What refuses an issue, a correction, a move, an addition, a removal or a withdrawal.
 * A problem's `rule` names its phrase, and `field` is the slot, input or milpac it's about.
 */
final class GrantRules
{
    public const REGIMENT_NEEDS_PERMISSION = 'regiment_needs_permission';
    public const NO_SUCH_KIND = 'no_such_kind';
    public const VERSION_NOT_PUBLISHED = 'version_not_published';
    public const VERSION_NOT_CURRENT = 'version_not_current';
    public const KIND_NOT_SERVED = 'kind_not_served';
    public const NO_MEMBERS = 'no_members';
    public const DUPLICATE_MEMBER = 'duplicate_member';
    public const REGIMENT_TAKES_NO_MEMBERS = 'regiment_takes_no_members';
    public const VERSION_NEEDS_MEMBER = 'version_needs_member';
    public const REGIMENT_DUPLICATE = 'regiment_duplicate';
    public const BAD_DATE = 'bad_date';
    public const CITATION_TEXT_REQUIRED = 'citation_text_required';
    public const SLOT_EMPTY = 'slot_empty';
    public const SIGNATURE_WRONG_BILLET = 'signature_wrong_billet';
    public const INPUT_REQUIRED = 'input_required';
    public const NAME_REQUIRED = 'name_required';
    public const RANK_REQUIRED = 'rank_required';
    public const NO_SUCH_RANK = 'no_such_rank';
    public const RANK_WITHOUT_ART = 'rank_without_art';
    public const REASON_REQUIRED = 'reason_required';
    public const NO_SUCH_MEMBER = 'no_such_member';
    public const WRONG_TEMPLATE = 'wrong_template';
    public const NOT_A_MEMBER_GRANT = 'not_a_member_grant';
    public const NOT_A_REGIMENT_GRANT = 'not_a_regiment_grant';
    public const ALREADY_WITHDRAWN = 'already_withdrawn';

    /** @param list<int> $regimentAwardIds */
    public static function isRegiment(string $kindType, int $kindId, array $regimentAwardIds): bool
    {
        return $kindType === 'award' && in_array($kindId, $regimentAwardIds, true);
    }

    /**
     * What the form may list, the same rule the save holds to.
     *
     * @param list<string> $kinds As `award:<id>` or `record_type:<id>`.
     * @param list<int> $regimentAwardIds
     * @return list<string>
     */
    public static function listableKinds(array $kinds, array $regimentAwardIds, Actor $actor): array
    {
        return array_values(array_filter($kinds, function (string $kind) use ($regimentAwardIds, $actor): bool {
            [$type, $id] = explode(':', $kind);

            return $actor->canIssue(self::isRegiment($type, (int) $id, $regimentAwardIds));
        }));
    }

    /**
     * The midnight-UTC epoch of an entered day, or null. createFromFormat rolls an
     * out-of-range date over, so a value must format back to itself.
     */
    public static function day(string $entered): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $entered, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $entered ? $date->getTimestamp() : null;
    }

    /**
     * The regiment-award option's text as ids, or null when it isn't a comma list of ids.
     *
     * @return list<int>|null
     */
    public static function awardList(string $text): ?array
    {
        $ids = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!ctype_digit($part)) {
                return null;
            }
            $ids[(int) $part] = (int) $part;
        }
        return array_values($ids);
    }

    /**
     * @param string $versionState The state of the version the form showed.
     * @param list<string> $kinds Kinds the version serves, as `award:<id>` or `record_type:<id>`.
     * @param int|null $currentVersionId The template's current version, which a submit pins.
     * @param string|null $kindTitle Null when the award or record type doesn't exist.
     * @param bool $liveDuplicate A live regiment grant already holds this award and date.
     * @param array<int, int> $billets Billet id by signature id.
     * @param array<string, list<int>> $artRanks Rank ids with art, by field key.
     * @return list<array{field: string, rule: string}>
     */
    public static function issueProblems(
        IssueRequest $request,
        GrantValues $values,
        Version $version,
        string $versionState,
        array $kinds,
        ?int $currentVersionId,
        ?string $kindTitle,
        bool $liveDuplicate,
        Actor $actor,
        array $billets,
        array $artRanks,
    ): array {
        $regiment = self::isRegiment($request->kindType, $request->kindId, $request->regimentAwardIds);
        $problems = [];

        if ($regiment && !$actor->canIssue(true)) {
            $problems[] = self::problem(self::REGIMENT_NEEDS_PERMISSION);
        }
        if ($kindTitle === null) {
            $problems[] = self::problem(self::NO_SUCH_KIND);
        }
        if ($versionState !== VersionLifecycle::PUBLISHED) {
            $problems[] = self::problem(self::VERSION_NOT_PUBLISHED);
        } elseif ($request->templateVersionId !== $currentVersionId) {
            $problems[] = self::problem(self::VERSION_NOT_CURRENT);
        }
        if (!in_array("{$request->kindType}:{$request->kindId}", $kinds, true)) {
            $problems[] = self::problem(self::KIND_NOT_SERVED);
        }

        return array_merge(
            $problems,
            $regiment ? self::regimentProblems($values, $version, $liveDuplicate) : self::memberListProblems($values),
            self::valueProblems($version, $values, $billets, $artRanks),
        );
    }

    /**
     * Whether these values make a whole grant on this version. Issuing, correcting and
     * moving all end here, and for a move these problems are the missing list.
     *
     * @param array<int, int> $billets Billet id by signature id.
     * @param array<string, list<int>> $artRanks Rank ids with art, by field key.
     * @return list<array{field: string, rule: string}>
     */
    public static function valueProblems(Version $version, GrantValues $values, array $billets, array $artRanks): array
    {
        $problems = [];

        if (self::day($values->date) === null) {
            $problems[] = self::problem(self::BAD_DATE);
        }
        if (FieldDependencies::used($version, 'citation_text') && !PatternResolver::filled($values->citationText ?? '')) {
            $problems[] = self::problem(self::CITATION_TEXT_REQUIRED);
        }

        foreach ($version->fields as $field) {
            if ($field instanceof SignatureSlot) {
                $signatureId = $values->signatures[$field->key] ?? null;
                if ($signatureId === null) {
                    $problems[] = self::problem(self::SLOT_EMPTY, $field->key);
                } elseif (($billets[$signatureId] ?? null) !== $field->billet_id) {
                    $problems[] = self::problem(self::SIGNATURE_WRONG_BILLET, $field->key);
                }
            }
        }

        foreach (self::missing($version, 'grant', $values->inputs) as $key) {
            $problems[] = self::problem(self::INPUT_REQUIRED, $key);
        }

        if ($version->rank_scope === 'grant') {
            array_push($problems, ...self::grantRankProblems($version, $values, $artRanks));
        }

        foreach ($values->members as $member) {
            array_push($problems, ...self::memberProblems($version, $member));
        }

        return $problems;
    }

    /** @return list<array{field: string, rule: string}> */
    public static function memberProblems(Version $version, MemberValues $member): array
    {
        $who = (string) $member->relationId;
        $problems = [];

        if (FieldDependencies::used($version, 'name') && !PatternResolver::filled($member->name)) {
            $problems[] = self::problem(self::NAME_REQUIRED, $who);
        }
        if ($version->rank_scope === 'member') {
            if ($member->rankId === null) {
                $problems[] = self::problem(self::RANK_REQUIRED, $who);
            } elseif ($member->rank === null) {
                $problems[] = self::problem(self::NO_SUCH_RANK, $who);
            }
        }
        foreach (self::missing($version, 'member', $member->inputs) as $key) {
            $problems[] = self::problem(self::INPUT_REQUIRED, "$who: $key");
        }

        return $problems;
    }

    /**
     * @param array<int, int> $billets Billet id by signature id.
     * @param array<string, list<int>> $artRanks
     * @param bool $liveDuplicate A date change meets another live regiment grant.
     * @return list<array{field: string, rule: string}>
     */
    public static function correctionProblems(
        Version $version,
        GrantValues $new,
        GrantChanges $changes,
        string $reason,
        int $withdrawnDate,
        bool $liveDuplicate,
        array $billets,
        array $artRanks,
    ): array {
        $problems = self::reasonProblems($reason);
        if ($withdrawnDate > 0) {
            $problems[] = self::problem(self::ALREADY_WITHDRAWN);
        }
        foreach (array_diff_key($changes->members, $new->members) as $id => $change) {
            $problems[] = self::problem(self::NO_SUCH_MEMBER, (string) $id);
        }
        if ($liveDuplicate) {
            $problems[] = self::problem(self::REGIMENT_DUPLICATE);
        }
        return array_merge($problems, self::valueProblems($version, $new, $billets, $artRanks));
    }

    /**
     * What stops a move outright, as opposed to the values it can ask for.
     *
     * @param string|null $targetState Null when the version doesn't exist.
     * @param list<string> $targetKinds
     * @return list<array{field: string, rule: string}>
     */
    public static function moveProblems(
        string $reason,
        int $withdrawnDate,
        bool $regiment,
        string $kind,
        int $templateId,
        ?int $targetTemplateId,
        ?string $targetState,
        array $targetKinds,
        ?Version $target,
    ): array {
        $problems = self::reasonProblems($reason);
        if ($withdrawnDate > 0) {
            $problems[] = self::problem(self::ALREADY_WITHDRAWN);
        }

        if ($targetState !== VersionLifecycle::PUBLISHED || $target === null) {
            $problems[] = self::problem(self::VERSION_NOT_PUBLISHED);
            return $problems;
        }
        if ($targetTemplateId !== $templateId) {
            $problems[] = self::problem(self::WRONG_TEMPLATE);
        }
        if (!in_array($kind, $targetKinds, true)) {
            $problems[] = self::problem(self::KIND_NOT_SERVED);
        }
        if ($regiment && !TemplateChoices::servesRegiment($target)) {
            $problems[] = self::problem(self::VERSION_NEEDS_MEMBER);
        }
        return $problems;
    }

    /**
     * @param list<int> $relationIds Milpacs already on the grant.
     * @return list<array{field: string, rule: string}>
     */
    public static function addProblems(bool $regiment, array $relationIds, Version $version, MemberValues $member): array
    {
        if ($regiment) {
            return [self::problem(self::NOT_A_MEMBER_GRANT)];
        }
        if (in_array($member->relationId, $relationIds, true)) {
            return [self::problem(self::DUPLICATE_MEMBER, (string) $member->relationId)];
        }
        return self::memberProblems($version, $member);
    }

    /** @return list<array{field: string, rule: string}> */
    public static function withdrawProblems(bool $regiment, int $withdrawnDate, string $reason): array
    {
        $problems = self::reasonProblems($reason);
        if (!$regiment) {
            $problems[] = self::problem(self::NOT_A_REGIMENT_GRANT);
        } elseif ($withdrawnDate > 0) {
            $problems[] = self::problem(self::ALREADY_WITHDRAWN);
        }
        return $problems;
    }

    /** @return list<array{field: string, rule: string}> */
    public static function reasonProblems(string $reason): array
    {
        return trim($reason) === '' ? [self::problem(self::REASON_REQUIRED)] : [];
    }

    /** @return list<array{field: string, rule: string}> */
    private static function regimentProblems(GrantValues $values, Version $version, bool $liveDuplicate): array
    {
        $problems = [];
        if ($values->members !== []) {
            $problems[] = self::problem(self::REGIMENT_TAKES_NO_MEMBERS);
        }
        if (!TemplateChoices::servesRegiment($version)) {
            $problems[] = self::problem(self::VERSION_NEEDS_MEMBER);
        }
        if ($liveDuplicate) {
            $problems[] = self::problem(self::REGIMENT_DUPLICATE);
        }
        return $problems;
    }

    /** @return list<array{field: string, rule: string}> */
    private static function memberListProblems(GrantValues $values): array
    {
        if ($values->members === []) {
            return [self::problem(self::NO_MEMBERS)];
        }

        $problems = [];
        $seen = [];
        foreach ($values->members as $member) {
            if (isset($seen[$member->relationId])) {
                $problems[] = self::problem(self::DUPLICATE_MEMBER, (string) $member->relationId);
            }
            $seen[$member->relationId] = true;
        }
        return $problems;
    }

    /**
     * @param array<string, list<int>> $artRanks
     * @return list<array{field: string, rule: string}>
     */
    private static function grantRankProblems(Version $version, GrantValues $values, array $artRanks): array
    {
        if ($values->rankId === null) {
            return [self::problem(self::RANK_REQUIRED)];
        }
        if ($values->rank === null) {
            return [self::problem(self::NO_SUCH_RANK)];
        }
        return TemplateChoices::pickableRanks($version, [$values->rankId], $artRanks) === []
            ? [self::problem(self::RANK_WITHOUT_ART)]
            : [];
    }

    /**
     * @param 'grant'|'member' $scope
     * @param array<string, string> $inputs
     * @return list<string>
     */
    private static function missing(Version $version, string $scope, array $inputs): array
    {
        return array_values(array_intersect($version->missingInputs($inputs), $version->inputKeys($scope)));
    }

    /** @return array{field: string, rule: string} */
    private static function problem(string $rule, string $field = ''): array
    {
        return ['field' => $field, 'rule' => $rule];
    }
}
