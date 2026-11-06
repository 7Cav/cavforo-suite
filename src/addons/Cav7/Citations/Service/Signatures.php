<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Signature;
use Cav7\Citations\Template\ImageUpload;
use XF\Service\AbstractService;

/** Retiring and printed lines are plain saves on the entity, and it refuses what freezing forbids. */
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

    public function replaceInk(Signature $signature, string $inkPath): void
    {
        $this->takeInk($signature, $inkPath);
        $signature->save();
    }

    private function takeInk(Signature $signature, string $inkPath): void
    {
        [$image, $hash] = $this->service(ImageIntake::class)->store($inkPath, ImageUpload::ART_FORMATS);

        $signature->bulkSet(['ink_hash' => $hash, 'ink_width' => $image->width, 'ink_height' => $image->height]);
    }
}
