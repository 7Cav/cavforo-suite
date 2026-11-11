<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Storage\DataDisk;
use Cav7\Citations\Storage\ImageSource;
use Cav7\Citations\Storage\RowKind;

/**
 * The read path and reference guard on both vendor row entities.
 *
 * Assumes the vendor's image services stamp citation_date and save through the entity on
 * upload and removal, and that its getImageUrl() is the legacy read. After a vendor
 * upgrade, re-check on a dev stack that a re-upload serves the legacy file at once and a
 * removal drops the reference.
 *
 * @property int $record_id
 * @property int $citation_date
 * @property int|null $citation_blob_id
 * @property int|null $citation_grant_member_id
 * @property int|null $citation_regiment_grant_id
 * @property-read Blob|null $CitationBlob
 * @property-read GrantMember|null $CitationGrantMember
 * @property-read Grant|null $CitationRegimentGrant
 */
trait CitationRow
{
    abstract protected function citationKind(): RowKind;

    public function hasGrantLink(): bool
    {
        return $this->citation_grant_member_id !== null || $this->citation_regiment_grant_id !== null;
    }

    /** The one writer of a regiment link. It doesn't save, so a new row is made and linked in one save. */
    public function linkRegimentGrant(int $grantId): void
    {
        $this->bulkSet([
            'citation_regiment_grant_id' => $grantId,
            'citation_grant_member_id' => null,
            'citation_blob_id' => null,
            'citation_date' => \XF::$time,
        ]);
    }

    public function getImageUrl($canonical = false): ?string
    {
        $source = ImageSource::for(
            $this->hasGrantLink(),
            (int) $this->citation_date,
            $this->citation_blob_id,
            fn () => DataDisk::forApp($this->app())->stat($this->citationKind()->legacyKey($this->record_id)) !== null
        );

        return match ($source) {
            ImageSource::Grant => ($this->CitationGrantMember ?? $this->CitationRegimentGrant)?->renderUrl((bool) $canonical),
            ImageSource::None => null,
            ImageSource::Blob => $this->CitationBlob?->getUrl((bool) $canonical) ?? parent::getImageUrl($canonical),
            ImageSource::Legacy => parent::getImageUrl($canonical),
        };
    }

    protected function _preSave(): void
    {
        if ($this->isUpdate() && $this->isChanged('citation_date') && !$this->isChanged('citation_blob_id')) {
            $this->citation_blob_id = null;
        }

        // The vendor's checkbox and deleteImage() zero the date and know nothing of links.
        if ($this->isUpdate() && $this->isChanged('citation_date') && (int) $this->citation_date === 0) {
            $this->citation_grant_member_id = null;
            $this->citation_regiment_grant_id = null;
        }

        parent::_preSave();
    }
}
