<?php

namespace Cav7\Citations\Grant;

/** Clears the rows that link a grant member, so the removal's log entry can say which ones they were. */
interface RowClearer
{
    /** @return list<array{row_type: 'award'|'record', record_id: int, relation_id: int, kind_title: string, row_date: int}> */
    public function clear(int $grantMemberId): array;
}
