<?php

namespace Cav7\Citations\Storage;

/**
 * The data/ directory as local files. Keys are paths relative to it.
 *
 * Plain files rather than XF's abstracted filesystem, because the sweep's guards need
 * mtimes and directory listings. Assumes the local data adapter. A board that overrides
 * fsAdapters['data'] can't run the sweep, and nginx couldn't serve the trees either.
 */
final class DataDisk
{
    public function __construct(private string $root)
    {
    }

    public function path(string $key): string
    {
        return $this->root . '/' . $key;
    }

    /**
     * @return array{mtime: int, size: int}|null
     */
    public function stat(string $key): ?array
    {
        $path = $this->path($key);
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat ? ['mtime' => $stat['mtime'], 'size' => $stat['size']] : null;
    }

    /**
     * @return array<string, array{dir: bool, mtime: int, size: int}> by name, sorted
     */
    public function entries(string $dir): array
    {
        $names = @scandir($this->path($dir));
        if ($names === false) {
            return [];
        }

        $entries = [];
        foreach (array_diff($names, ['.', '..']) as $name) {
            $path = $this->path("$dir/$name");
            $stat = @stat($path);
            if ($stat) {
                $entries[$name] = ['dir' => is_dir($path), 'mtime' => $stat['mtime'], 'size' => $stat['size']];
            }
        }

        return $entries;
    }

    public function hash(string $key): ?string
    {
        $path = $this->path($key);

        return is_file($path) ? hash_file('sha256', $path, true) : null;
    }

    public function leadingBytes(string $key): string
    {
        return (string) @file_get_contents($this->path($key), false, null, 0, 8);
    }

    /**
     * Refuses to replace anything, so a blob file can only ever be written once.
     */
    public function writeOnce(string $key, string $file): void
    {
        $path = $this->path($key);
        $this->ensureDirectory(dirname($path));

        $target = @fopen($path, 'xb');
        $source = @fopen($file, 'rb');
        if (!$target || !$source) {
            throw new \RuntimeException("Could not write $key");
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
        chmod($path, 0644);

        if ($copied !== filesize($file)) {
            throw new \RuntimeException("Short write to $key");
        }
    }

    public function delete(string $key): bool
    {
        return @unlink($this->path($key));
    }

    // Each new directory gets XF's blank index.html, so a server with listings on
    // can't hand out the url tokens.
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $this->ensureDirectory(dirname($dir));
        if (!@mkdir($dir, 0755) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create $dir");
        }
        file_put_contents("$dir/index.html", ' ');
    }
}
