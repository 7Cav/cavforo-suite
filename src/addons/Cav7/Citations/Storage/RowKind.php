<?php

namespace Cav7\Citations\Storage;

/**
 * The two vendor tables a citation row lives in.
 *
 * Assumes NF/Rosters' legacy layout: {tree}/{floor(record_id/1000)}/{record_id}.jpg
 * from getAbstractedImagePath() on both row entities. Re-check after a vendor upgrade.
 */
enum RowKind: string
{
    case Award = 'award';
    case Record = 'record';

    public function table(): string
    {
        return match ($this) {
            self::Award => 'xf_nf_rosters_user_award',
            self::Record => 'xf_nf_rosters_service_record',
        };
    }

    public function legacyTree(): string
    {
        return match ($this) {
            self::Award => 'roster_award_citations',
            self::Record => 'roster_record_citations',
        };
    }

    public function legacyKey(int $recordId): string
    {
        return sprintf('%s/%d/%d.jpg', $this->legacyTree(), BlobFile::shard($recordId), $recordId);
    }
}
