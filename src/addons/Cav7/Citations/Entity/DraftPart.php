<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Template\VersionLifecycle;

/**
 * A row of a version's design, so it freezes with the version. The stored state decides,
 * because a cached relation can still say draft after a publish.
 *
 * @property int $template_version_id
 */
trait DraftPart
{
    protected function _preSave(): void
    {
        $this->refuseUnlessDraft();
    }

    protected function _preDelete(): void
    {
        $this->refuseUnlessDraft();
    }

    private function refuseUnlessDraft(): void
    {
        $state = $this->db()->fetchOne(
            'SELECT state FROM xf_cav7_citation_template_version WHERE template_version_id = ?',
            $this->template_version_id
        );

        if ($state !== VersionLifecycle::DRAFT) {
            $this->error(\XF::phrase('cav7_cit_refused_version_frozen'));
        }
    }
}
