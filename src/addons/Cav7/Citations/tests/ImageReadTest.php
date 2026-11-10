<?php

/**
 * The read path and reference guard on a row entity, with the proxy parent stubbed.
 *
 * The vendor's services and the live profile page are checked on a dev stack. After a
 * vendor upgrade, re-check that a re-upload through the real service serves the legacy
 * file at once and that a removal drops the reference.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ImageReadTest.php
 */

namespace XF {
    class App
    {
        public static string $dataPath = '';

        public function config(string $key): mixed
        {
            return $key === 'externalDataPath' ? self::$dataPath : null;
        }

        public function applyExternalDataUrl(string $path, bool $canonical): string
        {
            return ($canonical ? 'https://board.test/data/' : 'data/') . $path;
        }
    }
}

namespace XF\Util {
    class File
    {
        public static function canonicalizePath(string $path): string
        {
            return $path;
        }
    }
}

namespace XF\Mvc\Entity {
    class Structure
    {
    }

    class Entity
    {
        public const UINT = 'uint';
        public const STR = 'str';
        public const BINARY = 'binary';
        public const TO_ONE = 'to_one';

        public array $values = [];
        public array $changed = [];
        public bool $insert = false;

        public function __get($name)
        {
            return $this->values[$name] ?? null;
        }

        public function __set($name, $value)
        {
            $this->changed[$name] = $this->values[$name] ?? null;
            $this->values[$name] = $value;
        }

        public function isUpdate(): bool
        {
            return !$this->insert;
        }

        public function isChanged(string $name): bool
        {
            return array_key_exists($name, $this->changed);
        }

        public function app(): \XF\App
        {
            return new \XF\App();
        }

        protected function _preSave()
        {
        }

        public function preSave(): void
        {
            $this->_preSave();
        }
    }
}

namespace Cav7\Citations\NF\Rosters\Entity {
    class XFCP_RosterUserAward extends \XF\Mvc\Entity\Entity
    {
        public function getImageUrl($canonical = false): ?string
        {
            return "legacy/{$this->record_id}.jpg?{$this->citation_date}";
        }
    }
}

namespace Cav7\Citations\Tests {

    require __DIR__ . '/../Storage/BlobFile.php';
    require __DIR__ . '/../Storage/DataDisk.php';
    require __DIR__ . '/../Storage/RowKind.php';
    require __DIR__ . '/../Storage/ImageSource.php';
    require __DIR__ . '/../Entity/Blob.php';
    require __DIR__ . '/../Entity/CitationRow.php';
    require __DIR__ . '/../NF/Rosters/Entity/RosterUserAward.php';

    use Cav7\Citations\Entity\Blob;
    use Cav7\Citations\NF\Rosters\Entity\RosterUserAward;
    use Cav7\Citations\Storage\ImageSource;

    $failures = 0;

    \XF\App::$dataPath = sys_get_temp_dir() . '/cav7cit-read-' . bin2hex(random_bytes(4));
    mkdir(\XF\App::$dataPath . '/roster_award_citations/0', 0777, true);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg(\XF\App::$dataPath)));

    function check(string $label, bool $ok): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label\n";
        }
    }

    function target(string $url): object
    {
        return new class($url) {
            public function __construct(private string $url)
            {
            }

            public function renderUrl(bool $canonical): string
            {
                return ($canonical ? 'https://board.test/' : '') . $this->url;
            }
        };
    }

    function linked(?int $memberId, ?int $regimentId, ?object $member = null, ?object $regiment = null): RosterUserAward
    {
        $row = row(500, 3);
        $row->values += [
            'citation_grant_member_id' => $memberId,
            'citation_regiment_grant_id' => $regimentId,
            'CitationGrantMember' => $member,
            'CitationRegimentGrant' => $regiment,
        ];

        return $row;
    }

    function row(int $citationDate, ?int $blobId, bool $blobRowExists = true): RosterUserAward
    {
        $row = new RosterUserAward();
        $blob = new Blob();
        $blob->values = ['blob_id' => $blobId, 'url_token' => 'tok', 'format' => 'png'];
        $row->values = [
            'record_id' => 7,
            'citation_date' => $citationDate,
            'citation_blob_id' => $blobId,
            'CitationBlob' => $blobId !== null && $blobRowExists ? $blob : null,
        ];

        return $row;
    }

    $file = fn () => true;
    $noFile = fn () => false;
    foreach ([
        ['a grant link outranks everything', [true, 500, 3, $file], ImageSource::Grant],
        ['even with a zero date', [true, 0, null, $file], ImageSource::Grant],
        ['a zero date means no image', [false, 0, null, $file], ImageSource::None],
        ['even with a stale reference', [false, 0, 3, $file], ImageSource::None],
        ['a legacy file outranks the blob', [false, 500, 3, $file], ImageSource::Legacy],
        ['a reference with no legacy file serves the blob', [false, 500, 3, $noFile], ImageSource::Blob],
        ['no reference falls back to legacy', [false, 500, null, $noFile], ImageSource::Legacy],
    ] as [$label, $facts, $expected]) {
        check("source: $label", ImageSource::for(...$facts) === $expected);
    }

    check(
        'a blob URL is the key with no query string',
        row(500, 41234)->getImageUrl() === 'data/roster_citation_blobs/41/41234-tok.png'
    );
    check(
        '$canonical passes through',
        row(500, 3)->getImageUrl(true) === 'https://board.test/data/roster_citation_blobs/0/3-tok.png'
    );
    check('a reference to a missing blob row falls back to legacy', row(500, 3, false)->getImageUrl() === 'legacy/7.jpg?500');
    touch(\XF\App::$dataPath . '/roster_award_citations/0/7.jpg');
    check('a restored legacy file outranks the blob', row(500, 3)->getImageUrl() === 'legacy/7.jpg?500');
    unlink(\XF\App::$dataPath . '/roster_award_citations/0/7.jpg');

    check('a row with neither link has none', row(500, 3)->hasGrantLink() === false);
    check('a member link outranks the blob', linked(9, null, target('citations/9.jpg'))->getImageUrl() === 'citations/9.jpg');
    check('a regiment link reads the grant', linked(null, 4, null, target('citations/regiment/4.jpg'))->getImageUrl() === 'citations/regiment/4.jpg');
    check('$canonical reaches the render', linked(9, null, target('citations/9.jpg'))->getImageUrl(true) === 'https://board.test/citations/9.jpg');
    check('a link to a missing member reads nothing', linked(9, null)->getImageUrl() === null);
    check('a link to a missing grant reads nothing', linked(null, 4)->getImageUrl() === null);

    $upload = row(500, 3);
    $upload->citation_date = 900;
    $upload->preSave();
    check('a re-upload clears the reference', $upload->citation_blob_id === null);
    check('so it reads the legacy file at once', $upload->getImageUrl() === 'legacy/7.jpg?900');

    $removal = row(500, 3);
    $removal->citation_date = 0;
    $removal->preSave();
    check('a removal clears the reference', $removal->citation_blob_id === null);

    foreach ([[9, null], [null, 4]] as [$memberId, $regimentId]) {
        $cleared = linked($memberId, $regimentId);
        $cleared->citation_date = 0;
        $cleared->preSave();
        check('a zero date clears the link', !$cleared->hasGrantLink());
    }

    $restamped = linked(9, null);
    $restamped->citation_date = 900;
    $restamped->preSave();
    check('a new date keeps it', $restamped->citation_grant_member_id === 9);

    $edited = linked(9, null);
    $edited->details = 'edited';
    $edited->preSave();
    check('so does an unrelated save', $edited->citation_grant_member_id === 9);

    $both = row(500, 3);
    $both->citation_date = 900;
    $both->citation_blob_id = 4;
    $both->preSave();
    check('a save setting both keeps the new reference', $both->citation_blob_id === 4);

    $other = row(500, 3);
    $other->details = 'edited';
    $other->preSave();
    check('an unrelated save leaves the reference alone', $other->citation_blob_id === 3);

    $new = row(500, 3);
    $new->insert = true;
    $new->citation_date = 900;
    $new->preSave();
    check('an insert is left alone', $new->citation_blob_id === 3);

    exit($failures === 0 ? 0 : 1);
}
