<?php

namespace Cav7\Citations\Template;

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

    /**
     * The legibility list before its join to grants, which is what leaves out withdrawn
     * grants and removed members.
     *
     * @return list<array{grant_id: int|string, grant_member_id: int|string, field_key: string, size: string}>
     */
    public function belowLegibility(): array
    {
        return $this->db->fetchAll(
            'SELECT grant_id, grant_member_id, field_key, size FROM ' . self::TABLE . '
            WHERE below_legibility = 1 ORDER BY grant_id, grant_member_id, field_key'
        );
    }
}
