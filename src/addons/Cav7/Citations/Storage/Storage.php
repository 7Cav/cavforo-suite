<?php

namespace Cav7\Citations\Storage;

use XF\App;
use XF\Db\AbstractAdapter;

/**
 * Builds the storage classes for a live board and holds the lock they share. The lock
 * belongs to the DB connection, so the server frees it if the process dies.
 */
final class Storage
{
    private const LOCK = 'cav7_citation_storage';

    public readonly BlobStore $blobs;
    public readonly Rows $rows;

    public function __construct(public readonly AbstractAdapter $db, public readonly DataDisk $disk)
    {
        $this->blobs = new BlobStore($db, $disk);
        $this->rows = new Rows($db);
    }

    public static function forApp(App $app): self
    {
        return new self($app->db(), DataDisk::forApp($app));
    }

    public function lock(): bool
    {
        return (int) $this->db->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]) === 1;
    }

    public function unlock(): void
    {
        $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
    }

    /**
     * @param \Closure(string): void $say
     */
    public function damageLog(\Closure $say, bool $dryRun = false): DamageLog
    {
        return new DamageLog($dryRun ? null : fn (string $line) => \XF::logError($line, true), $say);
    }

    /**
     * @param \Closure(string): void $say
     */
    public function sweeper(\Closure $say, bool $dryRun = false): Sweeper
    {
        return new Sweeper($this->db, $this->disk, $this->blobs, $this->rows, $this->damageLog($say, $dryRun), $say);
    }
}
