<?php

namespace Cav7\Citations\Repository;

use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Template\SignatureChoices;
use XF\Mvc\Entity\Repository;

class Signature extends Repository
{
    /** Read fresh rather than off the entity, so a grant pinning it since the load still counts. */
    public function isFrozen(int $signatureId): bool
    {
        return (int) $this->db()->fetchOne(
            'SELECT first_used_date FROM xf_cav7_citation_signature WHERE signature_id = ?',
            $signatureId
        ) > 0;
    }

    /** @return array<int, int> How many grants each signature is on, the ones that have any. */
    public function uses(): array
    {
        return array_map('intval', $this->db()->fetchPairs(
            'SELECT signature_id, COUNT(DISTINCT grant_id) FROM xf_cav7_citation_grant_signature GROUP BY signature_id'
        ));
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
