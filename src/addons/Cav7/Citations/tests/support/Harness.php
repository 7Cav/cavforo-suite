<?php

/**
 * Shared by the storage tests. It lives under support/ so the runner doesn't execute it.
 *
 * The stub adapter runs the storage classes' real SQL against in-memory SQLite, so a test
 * couples to what a query means rather than its text. It is test infrastructure and says
 * nothing about running on SQLite. The one MySQL-only piece, FOR UPDATE, is dropped here:
 * SQLite has one writer, so row locks and GET_LOCK are checked on a dev stack. Collation
 * and type affinity stay the stack's business too.
 */

namespace XF\Db {
    class Statement
    {
        public function __construct(private int $rows)
        {
        }

        public function rowsAffected(): int
        {
            return $this->rows;
        }
    }

    class AbstractAdapter
    {
        public \PDO $pdo;

        /** @var list<?\Closure> run in order, one per beginTransaction(), to land a race there */
        public array $beforeBegin = [];

        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        private function run(string $sql, array $params): \PDOStatement
        {
            $sql = str_replace(' FOR UPDATE', '', $sql);
            try {
                $statement = $this->pdo->prepare($sql);
                $statement->execute(array_values($params));
            } catch (\PDOException $e) {
                throw new \RuntimeException('Harness drift, SQLite cannot run this: ' . $e->getMessage() . "\n$sql");
            }

            return $statement;
        }

        public function query($sql, $params = []): Statement
        {
            return new Statement($this->run($sql, (array) $params)->rowCount());
        }

        public function fetchRow($sql, $params = [])
        {
            return $this->run($sql, (array) $params)->fetch(\PDO::FETCH_ASSOC);
        }

        public function fetchOne($sql, $params = [])
        {
            return $this->run($sql, (array) $params)->fetchColumn();
        }

        public function fetchAll($sql, $params = []): array
        {
            return $this->run($sql, (array) $params)->fetchAll(\PDO::FETCH_ASSOC);
        }

        public function fetchAllColumn($sql, $params = []): array
        {
            return $this->run($sql, (array) $params)->fetchAll(\PDO::FETCH_COLUMN);
        }

        public function fetchPairs($sql, $params = []): array
        {
            return $this->run($sql, (array) $params)->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        public function insert($table, array $values): int
        {
            $marks = implode(', ', array_fill(0, count($values), '?'));
            $columns = implode(', ', array_keys($values));

            return $this->query("INSERT INTO $table ($columns) VALUES ($marks)", $values)->rowsAffected();
        }

        public function update($table, array $values, $where, $params = []): int
        {
            $set = implode(', ', array_map(fn (string $column) => "$column = ?", array_keys($values)));

            return $this->query(
                "UPDATE $table SET $set WHERE $where",
                array_merge(array_values($values), (array) $params)
            )->rowsAffected();
        }

        public function delete($table, $where, $params = []): int
        {
            return $this->query("DELETE FROM $table WHERE $where", $params)->rowsAffected();
        }

        public function lastInsertId()
        {
            return $this->pdo->lastInsertId();
        }

        public function beginTransaction(): void
        {
            $race = array_shift($this->beforeBegin);
            if ($race) {
                $race();
            }
            $this->pdo->beginTransaction();
        }

        public function commit(): void
        {
            $this->pdo->commit();
        }

        public function rollback(): void
        {
            $this->pdo->rollBack();
        }
    }
}

namespace Cav7\Citations\Tests {

    use Cav7\Citations\Storage\BlobFile;
    use Cav7\Citations\Storage\BlobStore;
    use Cav7\Citations\Storage\DamageLog;
    use Cav7\Citations\Storage\DataDisk;
    use Cav7\Citations\Storage\Reencoder;
    use Cav7\Citations\Storage\Reverter;
    use Cav7\Citations\Storage\RowKind;
    use Cav7\Citations\Storage\Rows;
    use Cav7\Citations\Storage\SourcePurger;
    use Cav7\Citations\Storage\Sweeper;
    use Cav7\Citations\Storage\SweepProgress;
    use XF\Db\AbstractAdapter;

    $root = dirname(__DIR__, 2);
    foreach (['RowKind', 'BlobFile', 'BlobDamage', 'DataDisk', 'DamageLog', 'BlobStore', 'Rows', 'SweepRules', 'SweepProgress', 'Sweeper', 'ReencodeRules', 'Reencoder', 'RevertScope', 'Reverter', 'SourcePurger'] as $class) {
        require_once "$root/Storage/$class.php";
    }
    require_once "$root/Render/JpegEncoder.php";

    $GLOBALS['failures'] = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $GLOBALS['failures']++;
            echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
        }
    }

    function finish(): void
    {
        exit($GLOBALS['failures'] === 0 ? 0 : 1);
    }

    function png(int $width = 24, int $height = 16, string $color = '#336699'): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel($color));
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /**
     * An uncompressed gradient that q85 lands well under, so a run actually encodes it.
     * $seed varies the pixels.
     */
    function photoPng(int $seed = 1): string
    {
        $image = new \Imagick();
        $image->newPseudoImage(320, 240, 'gradient:#112233-#ddeeff');
        $image->rotateImage(new \ImagickPixel('white'), $seed);
        $image->setImageFormat('png');
        $image->setOption('png:compression-level', '0');

        return $image->getImageBlob();
    }

    /**
     * One board: a database with the addon's tables, the two vendor tables reduced to the
     * columns storage reads, and a real data/ directory.
     */
    final class Stage
    {
        public AbstractAdapter $db;
        public DataDisk $disk;
        public BlobStore $blobs;
        public Rows $rows;
        public string $root;

        /** @var list<string> */
        public array $errorLog = [];

        /** @var list<string> */
        public array $said = [];

        public function __construct()
        {
            $this->root = sys_get_temp_dir() . '/cav7cit-test-' . bin2hex(random_bytes(6));
            mkdir($this->root);
            register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($this->root)));

            $this->db = new AbstractAdapter();
            foreach (RowKind::cases() as $kind) {
                $this->db->query("CREATE TABLE {$kind->table()} (
                    record_id INTEGER PRIMARY KEY, citation_date INTEGER NOT NULL DEFAULT 0, citation_blob_id INTEGER NULL)");
            }
            $this->db->query('CREATE TABLE ' . BlobStore::TABLE . " (
                blob_id INTEGER PRIMARY KEY AUTOINCREMENT, content_hash BLOB NOT NULL UNIQUE, url_token TEXT NOT NULL,
                format TEXT NOT NULL, origin TEXT NOT NULL, byte_size INTEGER NOT NULL, width INTEGER DEFAULT 0,
                height INTEGER DEFAULT 0, ref_count INTEGER DEFAULT 0, created_date INTEGER NOT NULL, keep_reason TEXT NULL,
                encoder_label TEXT DEFAULT '', imagemagick_version TEXT DEFAULT '', libjpeg_version TEXT DEFAULT '')");
            $this->db->query('CREATE TABLE ' . BlobStore::DELETION_LOG . ' (
                log_id INTEGER PRIMARY KEY AUTOINCREMENT, deleted_date INTEGER, reason TEXT, path TEXT, byte_size INTEGER,
                content_hash BLOB, blob_id INTEGER NULL, last_ref_count INTEGER NULL, brake_lifted INTEGER DEFAULT 0)');
            $this->db->query('CREATE TABLE ' . Reencoder::RUN_TABLE . " (
                run_id INTEGER PRIMARY KEY AUTOINCREMENT, started_date INTEGER, finished_date INTEGER DEFAULT 0,
                archive_sync_date INTEGER, encoder_label TEXT, encoded_count INTEGER DEFAULT 0, kept_count INTEGER DEFAULT 0,
                damage_count INTEGER DEFAULT 0, rows_moved INTEGER DEFAULT 0)");
            $this->db->query('CREATE TABLE ' . Reencoder::LOG_TABLE . ' (
                log_id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, row_kind TEXT, record_id INTEGER,
                source_blob_id INTEGER, source_hash BLOB, source_archive_key TEXT, encoded_blob_id INTEGER,
                reverted_date INTEGER DEFAULT 0, reverted_to_blob_id INTEGER NULL)');

            $this->disk = new DataDisk($this->root);
            $this->blobs = new BlobStore($this->db, $this->disk);
            $this->rows = new Rows($this->db);
        }

        public function damageLog(bool $dryRun = false): DamageLog
        {
            return new DamageLog(
                $dryRun ? null : function (string $line): void {
                    $this->errorLog[] = $line;
                },
                $this->sayer()
            );
        }

        public function sayer(): \Closure
        {
            return function (string $line): void {
                $this->said[] = $line;
            };
        }

        public function row(RowKind $kind, int $id, int $citationDate = 1000, ?int $blobId = null): void
        {
            $this->db->insert($kind->table(), [
                'record_id' => $id,
                'citation_date' => $citationDate,
                'citation_blob_id' => $blobId,
            ]);
        }

        public function legacyFile(RowKind $kind, int $id, string $bytes, int $age = 7200): string
        {
            $key = $kind->legacyKey($id);
            $path = $this->disk->path($key);
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $bytes);
            touch($path, time() - $age);

            return $key;
        }

        /**
         * @return array<string, mixed>
         */
        public function blob(string $bytes, string $origin = 'source'): array
        {
            $file = $this->tempFile($bytes);
            $blob = $this->blobs->findOrCreate($file, $origin);
            unlink($file);

            return $blob;
        }

        public function tempFile(string $bytes): string
        {
            $file = tempnam($this->root, 'in');
            file_put_contents($file, $bytes);

            return $file;
        }

        public function refOf(RowKind $kind, int $id): ?int
        {
            return $this->rows->facts($kind, $id)['citation_blob_id'] ?? null;
        }

        public function exists(string $key): bool
        {
            return $this->disk->stat($key) !== null;
        }

        public function sweep(bool $liftBrake = false, bool $dryRun = false, bool $verifyHashes = false): SweepProgress
        {
            $progress = new SweepProgress();
            $progress->liftBrake = $liftBrake;
            $progress->dryRun = $dryRun;
            $progress->verifyHashes = $verifyHashes;
            $this->sweeper($dryRun)->run($progress);

            return $progress;
        }

        public function sweeper(bool $dryRun = false): Sweeper
        {
            return new Sweeper($this->db, $this->disk, $this->blobs, $this->rows, $this->damageLog($dryRun), $this->sayer());
        }

        public function reencoder(): Reencoder
        {
            return new Reencoder($this->db, $this->disk, $this->blobs, $this->rows, $this->damageLog(), $this->sayer());
        }

        public function reencode(?int $syncedAt = null): array
        {
            return $this->reencoder()->run($syncedAt ?? time() + 1, 'test-im', 'test-jpeg');
        }

        public function reverter(): Reverter
        {
            return new Reverter($this->db, $this->disk, $this->blobs, $this->rows, $this->damageLog());
        }

        public function purger(): SourcePurger
        {
            return new SourcePurger($this->db, $this->blobs, $this->rows);
        }

        public function deletions(string $reason): array
        {
            return $this->db->fetchAll('SELECT * FROM ' . BlobStore::DELETION_LOG . ' WHERE reason = ?', [$reason]);
        }

        /**
         * Every byte under data/ and in the database, for proving a run changed nothing.
         */
        public function fingerprint(): string
        {
            $files = [];
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if ($file->isFile()) {
                    $files[$file->getPathname()] = md5_file($file->getPathname());
                }
            }
            ksort($files);

            $tables = [BlobStore::TABLE, BlobStore::DELETION_LOG, Reencoder::RUN_TABLE, Reencoder::LOG_TABLE];
            foreach (RowKind::cases() as $kind) {
                $tables[] = $kind->table();
            }
            foreach ($tables as $table) {
                $files[$table] = base64_encode(serialize($this->db->fetchAll("SELECT * FROM $table")));
            }

            return md5(serialize($files) . serialize($this->errorLog));
        }
    }
}
