<?php

namespace Cav7\Citations\Storage;

/**
 * What a blob's bytes say about it, and where it lives. The key is the blob's path
 * under data/ and also its object key in the archive.
 */
final class BlobFile
{
    public const TREE = 'roster_citation_blobs';

    private const EXTENSIONS = ['jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif'];

    public static function format(string $leadingBytes): ?string
    {
        return match (true) {
            str_starts_with($leadingBytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($leadingBytes, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($leadingBytes, 'GIF87a'), str_starts_with($leadingBytes, 'GIF89a') => 'gif',
            default => null,
        };
    }

    public static function shard(int $id): int
    {
        return intdiv($id, 1000);
    }

    public static function key(int $blobId, string $urlToken, string $format): string
    {
        $extension = self::EXTENSIONS[$format]
            ?? throw new \InvalidArgumentException("No extension for format '$format'");

        return sprintf('%s/%d/%d-%s.%s', self::TREE, self::shard($blobId), $blobId, $urlToken, $extension);
    }

    /**
     * @param array{blob_id: int|string, url_token: string, format: string} $blob
     */
    public static function keyOf(array $blob): string
    {
        return self::key((int) $blob['blob_id'], $blob['url_token'], $blob['format']);
    }
}
