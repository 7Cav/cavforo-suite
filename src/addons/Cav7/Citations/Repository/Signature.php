<?php

namespace Cav7\Citations\Repository;

use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Template\SignatureChoices;
use XF\Mvc\Entity\Repository;

class Signature extends Repository
{
    /**
     * Frozen means a grant_signature row names it. That table comes with grants, and this
     * is the one line to change when it does.
     */
    public function isFrozen(int $signatureId): bool
    {
        return false;
    }

    /**
     * @param int|null $currentId The grant's current signature when correcting.
     */
    public function choices(SignatureSlot $slot, ?int $currentId, bool $showRetired): SignatureChoices
    {
        $rows = $this->db()->fetchAll(
            'SELECT signature_id, billet_id, state FROM xf_cav7_citation_signature
            WHERE billet_id = ? OR signature_id = ?
            ORDER BY signature_id',
            [$slot->billet_id, $currentId ?? 0]
        );

        $signatures = array_map(fn (array $row): array => [
            'signature_id' => (int) $row['signature_id'],
            'billet_id' => (int) $row['billet_id'],
            'state' => $row['state'],
        ], $rows);

        return SignatureChoices::for($slot, $signatures, $currentId, $showRetired);
    }
}
