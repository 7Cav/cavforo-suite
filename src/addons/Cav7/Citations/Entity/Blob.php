<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Storage\BlobFile;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * Read side of a citation blob. Writes go through Storage\BlobStore.
 *
 * COLUMNS
 * @property int|null $blob_id
 * @property string $content_hash raw SHA-256, 32 bytes
 * @property string $url_token
 * @property string $format
 * @property string $origin
 * @property int $byte_size
 * @property int $width
 * @property int $height
 * @property int $ref_count
 * @property int $created_date
 * @property string|null $keep_reason
 * @property string $encoder_label
 * @property string $imagemagick_version
 * @property string $libjpeg_version
 */
class Blob extends Entity
{
    public function getUrl(bool $canonical = false): string
    {
        return $this->app()->applyExternalDataUrl(
            BlobFile::key($this->blob_id, $this->url_token, $this->format),
            $canonical
        );
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_blob';
        $structure->shortName = 'Cav7\Citations:Blob';
        $structure->primaryKey = 'blob_id';
        $structure->columns = [
            'blob_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'content_hash' => ['type' => self::BINARY, 'required' => true, 'maxLength' => 32],
            'url_token' => ['type' => self::STR, 'required' => true, 'maxLength' => 32],
            'format' => ['type' => self::STR, 'required' => true, 'allowedValues' => ['jpeg', 'png', 'gif']],
            'origin' => ['type' => self::STR, 'required' => true, 'allowedValues' => ['source', 'encoded']],
            'byte_size' => ['type' => self::UINT, 'required' => true],
            'width' => ['type' => self::UINT, 'default' => 0],
            'height' => ['type' => self::UINT, 'default' => 0],
            'ref_count' => ['type' => self::UINT, 'default' => 0],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'keep_reason' => [
                'type' => self::STR,
                'nullable' => true,
                'default' => null,
                'allowedValues' => ['already_jpeg', 'grew', 'decode_failed', 'gif', 'reverted'],
            ],
            'encoder_label' => ['type' => self::STR, 'default' => '', 'maxLength' => 50],
            'imagemagick_version' => ['type' => self::STR, 'default' => '', 'maxLength' => 50],
            'libjpeg_version' => ['type' => self::STR, 'default' => '', 'maxLength' => 50],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
