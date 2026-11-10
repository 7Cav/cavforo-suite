<?php

namespace Cav7\Citations\Template;

use XF\App;
use XF\Util\File;

/**
 * Plates, ink, rank art and pinned fonts, each named by its SHA-256 so nothing can edit what
 * a published version or frozen signature pins. Nothing here deletes: these files can't be
 * re-derived and the archive doesn't hold them.
 *
 * Writes go through internal-data://, and reads need real paths for Imagick, so this
 * assumes the local adapter. Stays apart from the blob tree: no refcounts, no sweep.
 */
final class FileStore
{
    public const TREE = 'cav7_citation_files';

    private const EXTENSIONS = ['png' => 'png', 'jpeg' => 'jpg', 'font' => 'ttf'];

    public function __construct(private string $root)
    {
    }

    public static function forApp(App $app): self
    {
        return new self(File::canonicalizePath($app->config('internalDataPath')));
    }

    /**
     * Identical bytes land on the file already there. A file that doesn't match its own name
     * is a torn write, so it's written again.
     *
     * @param 'png'|'jpeg'|'font' $format
     * @return string The file's hash.
     */
    public function put(string $source, string $format): string
    {
        $hash = @hash_file('sha256', $source) ?: throw new \RuntimeException("Can't read $source");

        $path = $this->path($hash, $format);
        if (!is_file($path) || hash_file('sha256', $path) !== $hash) {
            File::copyFileToAbstractedPath($source, 'internal-data://' . self::key($hash, $format));
        }

        return $hash;
    }

    /** @param 'png'|'jpeg'|'font' $format */
    public function path(string $hash, string $format): string
    {
        return $this->root . '/' . self::key($hash, $format);
    }

    private static function key(string $hash, string $format): string
    {
        return sprintf('%s/%s/%s.%s', self::TREE, substr($hash, 0, 2), $hash, self::EXTENSIONS[$format]);
    }
}
