<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Storage\ImageSource;

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
 * @property-read Blob|null $CitationBlob
 */
trait CitationRow
{
    public function hasGrantLink(): bool
    {
        return false;
    }

    public function getImageUrl($canonical = false): ?string
    {
        $source = ImageSource::for($this->hasGrantLink(), (int) $this->citation_date, $this->citation_blob_id);

        return match ($source) {
            ImageSource::Grant => null,
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

        parent::_preSave();
    }
}
