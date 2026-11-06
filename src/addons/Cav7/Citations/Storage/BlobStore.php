<?php

namespace Cav7\Citations\Storage;

use XF\Db\AbstractAdapter;

/**
 * The only writer of the blob table and the blob tree.
 */
final class BlobStore
{
    public const TABLE = 'xf_cav7_citation_blob';
    public const DELETION_LOG = 'xf_cav7_citation_deletion_log';

    public function __construct(private AbstractAdapter $db, private DataDisk $disk)
    {
    }

    /**
     * Finds the blob holding exactly these bytes, or creates it. A hash match leaves the
     * existing blob's fields alone, so a matching encoded output stays whatever it was.
     *
     * @param 'source'|'encoded' $origin
     * @param array{encoder_label?: string, imagemagick_version?: string, libjpeg_version?: string} $encoderFields
     * @return array<string, mixed> the blob row
     * @throws BlobDamage
     */
    public function findOrCreate(string $file, string $origin, array $encoderFields = []): array
    {
        $format = BlobFile::format((string) @file_get_contents($file, false, null, 0, 8));
        if ($format === null) {
            throw new BlobDamage('no known image signature');
        }

        $hash = hash_file('sha256', $file, true);
        $existing = $this->byHash($hash);
        if ($existing) {
            $this->confirm($existing);
            return $existing;
        }

        return $this->create($file, $hash, $format, $origin, $encoderFields);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byHash(string $hash): ?array
    {
        return $this->db->fetchRow('SELECT * FROM ' . self::TABLE . ' WHERE content_hash = ?', [$hash]) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byId(int $blobId): ?array
    {
        return $this->db->fetchRow('SELECT * FROM ' . self::TABLE . ' WHERE blob_id = ?', [$blobId]) ?: null;
    }

    public function keep(int $blobId, string $reason): void
    {
        $this->db->update(self::TABLE, ['keep_reason' => $reason], "blob_id = ? AND origin = 'source'", [$blobId]);
    }

    /**
     * The log entry lands before the file goes. A crash in between leaves a file with no
     * row, which the sweep reports.
     *
     * @param array<string, mixed> $blob
     */
    public function delete(array $blob, string $reason, ?int $lastRefCount, bool $brakeLifted): void
    {
        $key = BlobFile::keyOf($blob);

        $this->db->beginTransaction();
        $this->logDeletion(
            $reason,
            $key,
            (int) $blob['byte_size'],
            $blob['content_hash'],
            (int) $blob['blob_id'],
            $lastRefCount,
            $brakeLifted
        );
        $this->db->delete(self::TABLE, 'blob_id = ?', [$blob['blob_id']]);
        $this->db->commit();

        $this->disk->delete($key);
    }

    public function logDeletion(
        string $reason,
        string $path,
        int $byteSize,
        string $hash,
        ?int $blobId,
        ?int $lastRefCount,
        bool $brakeLifted
    ): void {
        $this->db->insert(self::DELETION_LOG, [
            'deleted_date' => time(),
            'reason' => $reason,
            'path' => $path,
            'byte_size' => $byteSize,
            'content_hash' => $hash,
            'blob_id' => $blobId,
            'last_ref_count' => $lastRefCount,
            'brake_lifted' => $brakeLifted ? 1 : 0,
        ]);
    }

    /**
     * @param array<string, mixed> $blob
     * @throws BlobDamage
     */
    private function confirm(array $blob): void
    {
        $onDisk = $this->disk->hash(BlobFile::keyOf($blob));
        if ($onDisk === null) {
            throw new BlobDamage('blob file missing');
        }
        if (!hash_equals($blob['content_hash'], $onDisk)) {
            throw new BlobDamage('blob file hash mismatch');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function create(string $file, string $hash, string $format, string $origin, array $encoderFields): array
    {
        $size = @getimagesize($file) ?: [0, 0];
        $key = null;

        $this->db->beginTransaction();
        try {
            $token = bin2hex(random_bytes(16));
            $this->db->insert(self::TABLE, [
                'content_hash' => $hash,
                'url_token' => $token,
                'format' => $format,
                'origin' => $origin,
                'byte_size' => filesize($file),
                'width' => $size[0],
                'height' => $size[1],
                'created_date' => time(),
            ] + $encoderFields);

            $blobId = (int) $this->db->lastInsertId();
            $key = BlobFile::key($blobId, $token, $format);
            $this->disk->writeOnce($key, $file);

            if ($this->disk->hash($key) !== $hash) {
                throw new BlobDamage('blob file failed read-back');
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            if ($key !== null) {
                $this->disk->delete($key);
            }
            throw $e;
        }

        return $this->byId($blobId);
    }
}
