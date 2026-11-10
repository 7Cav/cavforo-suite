<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\SignatureSlot;

/** What a slot's picker shows, and what it starts on. */
final class SignatureChoices
{
    /** @param list<array{signature_id: int, billet_id: int, state: string}> $shown */
    public function __construct(
        public readonly array $shown,
        public readonly ?int $preselected,
    ) {
    }

    /**
     * @param list<array{signature_id: int, billet_id: int, state: string}> $signatures
     * @param int|null $currentId The grant's current signature when correcting.
     */
    public static function for(SignatureSlot $slot, array $signatures, ?int $currentId, bool $showRetired): self
    {
        $shown = array_values(array_filter(
            $signatures,
            fn (array $signature): bool => $signature['signature_id'] === $currentId
                || ($signature['billet_id'] === $slot->billet_id && ($signature['state'] === 'active' || $showRetired))
        ));

        $preselected = null;
        if ($currentId !== null) {
            $preselected = in_array($currentId, array_column($shown, 'signature_id'), true) ? $currentId : null;
        } else {
            $active = array_values(array_filter($shown, fn (array $signature): bool => $signature['state'] === 'active'));
            $preselected = count($active) === 1 ? $active[0]['signature_id'] : null;
        }

        return new self($shown, $preselected);
    }
}
