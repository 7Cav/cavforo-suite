<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\ImageUpload;
use Cav7\Citations\Template\UploadRefused;
use XF\PrintableException;
use XF\Service\AbstractService;

class ImageIntake extends AbstractService
{
    /**
     * @param list<string> $accepted
     * @return array{ImageUpload, string} The checked image and its hash.
     * @throws PrintableException
     */
    public function store(string $path, array $accepted): array
    {
        try {
            $image = ImageUpload::read($path, $accepted);
        } catch (UploadRefused $e) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_' . $e->getMessage()));
        }

        return [$image, FileStore::forApp($this->app)->put($path, $image->format)];
    }
}
