<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Signature;
use Cav7\Citations\Template\ImageUpload;
use XF\Service\AbstractService;

/**
 * Retiring and printed lines are saves on the entity, and it refuses what freezing forbids.
 * Its frozen check locks the row, which holds only inside a transaction, so an edit or a
 * delete saves in one.
 */
class Signatures extends AbstractService
{
    /** @param list<string> $printedLines */
    public function create(int $billetId, array $printedLines, string $inkPath, int $userId): Signature
    {
        /** @var Signature $signature */
        $signature = $this->em()->create('Cav7\Citations:Signature');
        $signature->bulkSet(['billet_id' => $billetId, 'create_user_id' => $userId]);
        $signature->setPrintedLines($printedLines);
        $this->takeInk($signature, $inkPath);
        $signature->save();

        return $signature;
    }

    /**
     * @param list<string> $printedLines
     * @param string|null $inkPath Null keeps the ink it has.
     */
    public function update(Signature $signature, int $billetId, array $printedLines, ?string $inkPath): void
    {
        $signature->billet_id = $billetId;
        $signature->setPrintedLines($printedLines);
        if ($inkPath !== null) {
            $this->takeInk($signature, $inkPath);
        }
        $this->db()->executeTransaction(fn () => $signature->save(true, false));
    }

    public function delete(Signature $signature): void
    {
        $this->db()->executeTransaction(fn () => $signature->delete(true, false));
    }

    private function takeInk(Signature $signature, string $inkPath): void
    {
        [$image, $hash] = $this->service(ImageIntake::class)->store($inkPath, ImageUpload::ART_FORMATS);

        $signature->bulkSet(['ink_hash' => $hash, 'ink_width' => $image->width, 'ink_height' => $image->height]);
    }
}
