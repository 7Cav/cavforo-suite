<?php

namespace Cav7\Citations\Grant;

/**
 * A grant form's post as plain values, and the small edits a form makes to itself. One shape
 * serves every mode: members are a list when issuing, and by grant member id once stored.
 *
 * @phpstan-type Post array{kind: string, template_version_id: int, date: string, citation_text: string,
 *     signatures: array<string, int>, inputs: array<string, string>, rank_id: int, members: array<int|string, mixed>,
 *     show_retired: array<string, string>, fill_all: array<string, string>}
 */
final class GrantForm
{
    /** @return array{string, int}|null `award` or `record_type`, and the id. */
    public static function kind(string $posted): ?array
    {
        return preg_match('/^(award|record_type):(\d+)$/', $posted, $parts) === 1
            ? [$parts[1], (int) $parts[2]]
            : null;
    }

    /** @param Post $post */
    public static function values(array $post): GrantValues
    {
        $members = [];
        foreach (self::postedMembers($post) as $key => $member) {
            if ($member['relation_id'] !== 0) {
                $members[$key] = new MemberValues($member['relation_id'], $member['name'], $member['rank_id'], $member['inputs']);
            }
        }

        return new GrantValues(
            $post['date'],
            $post['citation_text'],
            array_filter($post['signatures']),
            array_map('trim', $post['inputs']),
            $post['rank_id'] ?: null,
            $members,
        );
    }

    /** @param Post $post */
    public static function changes(array $post): GrantChanges
    {
        $members = [];
        foreach (self::postedMembers($post) as $id => $member) {
            $members[$id] = new MemberChanges($member['name'], $member['rank_id'], $member['inputs']);
        }

        return new GrantChanges(
            $post['date'],
            $post['citation_text'],
            array_filter($post['signatures']),
            array_map('trim', $post['inputs']),
            $post['rank_id'] ?: null,
            $members,
        );
    }

    /**
     * The post a correction opens on.
     *
     * @return Post
     */
    public static function fromValues(GrantValues $values): array
    {
        $members = [];
        foreach ($values->members as $id => $member) {
            $members[$id] = [
                'relation_id' => $member->relationId,
                'name' => $member->name,
                'rank_id' => $member->rankId,
                'inputs' => $member->inputs,
            ];
        }

        return [
            'date' => $values->date,
            'citation_text' => (string) $values->citationText,
            'signatures' => $values->signatures,
            'inputs' => $values->inputs,
            'rank_id' => (int) $values->rankId,
            'members' => $members,
        ] + self::blank();
    }

    /** @return Post */
    public static function blank(): array
    {
        return [
            'kind' => '',
            'template_version_id' => 0,
            'date' => '',
            'citation_text' => '',
            'signatures' => [],
            'inputs' => [],
            'rank_id' => 0,
            'members' => [],
            'show_retired' => [],
            'fill_all' => [],
        ];
    }

    /**
     * What the form's own buttons do: `remove:<member key>` takes a member off, and
     * `apply:<input key>` copies that input's fill-all value to every member.
     *
     * @param Post $post
     * @return Post
     */
    public static function acted(array $post, string $action): array
    {
        [$verb, $key] = explode(':', $action, 2) + [1 => ''];

        if ($verb === 'remove') {
            unset($post['members'][$key]);
        }
        if ($verb === 'apply') {
            foreach (self::postedMembers($post) as $id => $member) {
                $post['members'][$id]['inputs'][$key] = $post['fill_all'][$key] ?? '';
            }
        }
        return $post;
    }

    /**
     * Members arrive as a raw nested array, so nothing here trusts their shape.
     *
     * @param Post $post
     * @return array<int, array{relation_id: int, name: string, rank_id: int|null, inputs: array<string, string>}>
     */
    private static function postedMembers(array $post): array
    {
        $members = [];
        foreach ($post['members'] as $key => $member) {
            if (!is_array($member)) {
                continue;
            }
            $inputs = is_array($member['inputs'] ?? null) ? $member['inputs'] : [];

            $members[(int) $key] = [
                'relation_id' => (int) ($member['relation_id'] ?? 0),
                'name' => trim((string) ($member['name'] ?? '')),
                'rank_id' => (int) ($member['rank_id'] ?? 0) ?: null,
                'inputs' => array_map(fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $inputs),
            ];
        }
        return $members;
    }
}
