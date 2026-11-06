<?php

/**
 * findOrCreate against real files and real SQL. Run under pdo_sqlite.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/BlobStoreTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/support/Harness.php';

use Cav7\Citations\Storage\BlobDamage;
use Cav7\Citations\Storage\BlobFile;
use Cav7\Citations\Storage\BlobStore;

function damage(\Closure $call): ?string
{
    try {
        $call();
    } catch (BlobDamage $e) {
        return $e->getMessage();
    }

    return null;
}

$stage = new Stage();
$bytes = png();

$blob = $stage->blob($bytes);
$key = BlobFile::keyOf($blob);
check('the file holds the bytes as given', file_get_contents($stage->disk->path($key)) === $bytes);
check('the hash is of the bytes as stored', $blob['content_hash'] === hash('sha256', $bytes, true));
check('format comes from the bytes', $blob['format'] === 'png' && str_ends_with($key, '.png'));
check('dimensions come from the header', (int) $blob['width'] === 24 && (int) $blob['height'] === 16);
check('the token is 32 hex characters', preg_match('/^[0-9a-f]{32}$/', $blob['url_token']) === 1);

$again = $stage->blob($bytes);
check('the same bytes find the same blob', $again['blob_id'] === $blob['blob_id']);
check('and leave one row', (int) $stage->db->fetchOne('SELECT COUNT(*) FROM ' . BlobStore::TABLE) === 1);

$asEncoded = $stage->blob($bytes, 'encoded');
check('a hash match leaves the existing blob a source blob', $asEncoded['origin'] === 'source');

$zero = $stage->tempFile('');
check('a zero-byte file is damage', damage(fn () => $stage->blobs->findOrCreate($zero, 'source')) !== null);
$text = $stage->tempFile('<html>not an image</html>');
check('an unknown signature is damage', damage(fn () => $stage->blobs->findOrCreate($text, 'source')) !== null);
check('damage creates no blob', (int) $stage->db->fetchOne('SELECT COUNT(*) FROM ' . BlobStore::TABLE) === 1);

$truncated = substr(png(64, 64), 0, 40);
$blobTruncated = $stage->blob($truncated);
check('a truncated PNG with a valid signature is taken in as it is', $blobTruncated['format'] === 'png');

$path = $stage->disk->path($key);
$file = $stage->tempFile($bytes);

file_put_contents($path, 'tampered');
check('a hash match with a changed file is damage', damage(fn () => $stage->blobs->findOrCreate($file, 'source')) !== null);
check('and the file is not rewritten from the bytes in hand', file_get_contents($path) === 'tampered');

unlink($path);
check('a hash match with a missing file is damage', damage(fn () => $stage->blobs->findOrCreate($file, 'source')) !== null);
check('and the file is not recreated', !file_exists($path));

$blocked = new Stage();
file_put_contents($blocked->disk->path('roster_citation_blobs'), 'a file where the tree should go');
$failed = false;
try {
    $blocked->blob(png());
} catch (\Throwable $e) {
    $failed = true;
}
check('a write that fails throws', $failed);
check('and leaves no row behind', (int) $blocked->db->fetchOne('SELECT COUNT(*) FROM ' . BlobStore::TABLE) === 0);

// A delete that fails leaves no transaction open on a connection the job keeps using.
$stage = new Stage();
$blob = $stage->blob(png());
$stage->db->query('DROP TABLE ' . BlobStore::DELETION_LOG);
try {
    $stage->blobs->delete($blob, 'zero_reference', 0, false);
} catch (\Throwable) {
}
$open = false;
try {
    $stage->db->beginTransaction();
    $stage->db->rollback();
} catch (\PDOException) {
    $open = true;
}
check('a delete that fails rolls back', !$open && $stage->blobs->byId((int) $blob['blob_id']) !== null);

$stage2 = new Stage();
$first = $stage2->blob(png(10, 10));
$dir = dirname($stage2->disk->path(BlobFile::keyOf($first)));
check('a new blob directory gets a guard index', file_exists("$dir/index.html"));
$rewrite = false;
try {
    $stage2->disk->writeOnce(BlobFile::keyOf($first), $stage2->tempFile('new'));
} catch (\RuntimeException $e) {
    $rewrite = true;
}
check('a blob file cannot be written twice', $rewrite);

finish();
