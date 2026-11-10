<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Grant\Typesetting;
use Cav7\Citations\Render\LibraryVersions;
use Cav7\Citations\Render\SetField;
use XF\Db\AbstractAdapter;

/** The one reader and writer of the typesetting table. Member id 0 holds a grant's shared fields. */
final class TypesettingRows
{
    public const TABLE = 'xf_cav7_citation_typesetting';
    public const SHARED = 0;

    public function __construct(private AbstractAdapter $db)
    {
    }

    /** @param array<string, SetField> $fields */
    public function write(
        int $grantId,
        int $grantMemberId,
        array $fields,
        int $addonVersionId,
        LibraryVersions $libraries,
        int $date,
    ): void {
        foreach ($fields as $field) {
            $row = $field->row();
            $this->db->query(
                'REPLACE INTO ' . self::TABLE . ' (grant_id, grant_member_id, field_key, size, below_legibility,
                    set_data, addon_version_id, imagemagick_version, freetype_version, typeset_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $grantId, $grantMemberId, $row['field_key'], $row['size'], $row['below_legibility'], $row['set_data'],
                    $addonVersionId, $libraries->imagemagick_version, $libraries->freetype_version, $date,
                ]
            );
        }
    }

    /**
     * A member's whole typesetting in one primary-key range read.
     *
     * @return array<string, SetField> By field key.
     */
    public function read(int $grantId, int $grantMemberId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT field_key, size, below_legibility, set_data FROM ' . self::TABLE . '
            WHERE grant_id = ? AND grant_member_id IN (?, ?)',
            [$grantId, self::SHARED, $grantMemberId]
        );

        $fields = [];
        foreach ($rows as $row) {
            $fields[$row['field_key']] = SetField::fromRow($row);
        }
        return $fields;
    }

    /** Every row on the grant, for comparing a retypeset. */
    public function all(int $grantId): Typesetting
    {
        $rows = $this->db->fetchAll(
            'SELECT grant_member_id, field_key, size, below_legibility, set_data FROM ' . self::TABLE . ' WHERE grant_id = ?',
            [$grantId]
        );

        $shared = [];
        $members = [];
        foreach ($rows as $row) {
            $memberId = (int) $row['grant_member_id'];
            if ($memberId === self::SHARED) {
                $shared[$row['field_key']] = SetField::fromRow($row);
            } else {
                $members[$memberId][$row['field_key']] = SetField::fromRow($row);
            }
        }
        return new Typesetting($shared, $members);
    }

    /** @return list<array{addon_version_id: int|string, imagemagick_version: string, freetype_version: string}> */
    public function stamps(int $grantId): array
    {
        return $this->db->fetchAll(
            'SELECT DISTINCT addon_version_id, imagemagick_version, freetype_version FROM ' . self::TABLE . ' WHERE grant_id = ?',
            [$grantId]
        );
    }

    public function clearGrant(int $grantId): void
    {
        $this->db->delete(self::TABLE, 'grant_id = ?', $grantId);
    }

    public function clearMember(int $grantId, int $grantMemberId): void
    {
        $this->db->delete(self::TABLE, 'grant_id = ? AND grant_member_id = ?', [$grantId, $grantMemberId]);
    }

    /** @return list<int> Grants holding a row stamped with this addon version. */
    public function grantsStamped(int $addonVersionId): array
    {
        return array_map('intval', $this->db->fetchAllColumn(
            'SELECT DISTINCT grant_id FROM ' . self::TABLE . ' WHERE addon_version_id = ? ORDER BY grant_id',
            [$addonVersionId]
        ));
    }

    /**
     * The legibility list, reading the stored flag and measuring nothing. A shared field is
     * one entry. A removed member's rows went with them, so only withdrawn grants need leaving out.
     *
     * @return list<array{grant_id: int|string, grant_member_id: int|string, field_key: string, size: string, kind_title: string, grant_date: int|string, is_regiment: int|string, member_name: string|null, template_version_id: int|string, template_id: int|string, template_title: string, version_number: int|string}>
     */
    public function belowLegibility(): array
    {
        return $this->db->fetchAll(
            'SELECT t.grant_id, t.grant_member_id, t.field_key, t.size, g.kind_title, g.grant_date, g.is_regiment,
                m.name AS member_name, g.template_version_id, v.template_id, tpl.title AS template_title, v.version_number
            FROM ' . self::TABLE . ' AS t
            INNER JOIN xf_cav7_citation_grant AS g ON g.grant_id = t.grant_id
            INNER JOIN xf_cav7_citation_template_version AS v ON v.template_version_id = g.template_version_id
            INNER JOIN xf_cav7_citation_template AS tpl ON tpl.template_id = v.template_id
            LEFT JOIN xf_cav7_citation_grant_member AS m ON m.grant_member_id = t.grant_member_id
            WHERE t.below_legibility = 1 AND g.withdrawn_date = 0
            ORDER BY t.grant_id, t.grant_member_id, t.field_key'
        );
    }
}
