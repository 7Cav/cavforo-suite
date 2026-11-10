<?php

/**
 * Upload checks through real Imagick, the hash-named file tree and the typesetting table.
 * XF's abstracted write is stubbed with a plain copy into the same tree: the real path
 * through internal-data:// is checked on a dev stack.
 *
 * Needs the imagick extension. CI's test job declares it.
 *
 * Run:
 *   php tests/TemplateFilesTest.php
 */

namespace XF {
    class App
    {
    }
}

namespace XF\Util {
    class File
    {
        public static string $root = '';
        public static int $writes = 0;

        public static function copyFileToAbstractedPath($file, $abstractedPath): void
        {
            self::$writes++;
            $path = self::$root . '/' . substr($abstractedPath, strlen('internal-data://'));
            @mkdir(dirname($path), 0777, true);
            copy($file, $path);
        }
    }
}

namespace Cav7\Citations\Tests {

    require __DIR__ . '/support/Harness.php';

    spl_autoload_register(function (string $class): void {
        foreach (['Render', 'Template', 'Grant'] as $directory) {
            $prefix = "Cav7\\Citations\\$directory\\";
            if (str_starts_with($class, $prefix)) {
                require __DIR__ . "/../$directory/" . substr($class, strlen($prefix)) . '.php';
            }
        }
    });

    use Cav7\Citations\Render\LibraryVersions;
    use Cav7\Citations\Render\Line;
    use Cav7\Citations\Render\Overrun;
    use Cav7\Citations\Render\PlacedImage;
    use Cav7\Citations\Render\SetField;
    use Cav7\Citations\Template\FileStore;
    use Cav7\Citations\Template\ImageUpload;
    use Cav7\Citations\Template\OverrunLog;
    use Cav7\Citations\Template\TypesettingRows;
    use Cav7\Citations\Template\UploadRefused;
    use XF\Db\AbstractAdapter;
    use XF\Util\File;

    $root = sys_get_temp_dir() . '/cav7cit-files-' . bin2hex(random_bytes(6));
    mkdir($root);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($root)));

    function upload(string $bytes, string $name = 'upload.png'): string
    {
        global $root;
        $path = "$root/" . bin2hex(random_bytes(4)) . "-$name";
        file_put_contents($path, $bytes);
        return $path;
    }

    function refusal(string $path, array $accepted): ?string
    {
        try {
            ImageUpload::read($path, $accepted);
            return null;
        } catch (UploadRefused $e) {
            return $e->getMessage();
        }
    }

    function jpeg(int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#778899'));
        $image->setImageFormat('jpeg');
        return $image->getImageBlob();
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300"/></svg>';

    // G-35, G-36, G-37.
    $goodPng = png(300, 200);
    foreach ([
        'SVG bytes named .png are refused' => [$svg, 'plate.png', ImageUpload::PLATE_FORMATS, UploadRefused::NOT_AN_ACCEPTED_IMAGE],
        'a GIF is no plate' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'plate.gif', ImageUpload::PLATE_FORMATS, UploadRefused::NOT_AN_ACCEPTED_IMAGE],
        'a JPEG makes a plate' => [jpeg(200, 100), 'plate.png', ImageUpload::PLATE_FORMATS, null],
        'a JPEG is no ink, whatever its name' => [jpeg(200, 100), 'ink.png', ImageUpload::ART_FORMATS, UploadRefused::NOT_AN_ACCEPTED_IMAGE],
        'a PNG named .jpg is read as the PNG it is' => [$goodPng, 'ink.jpg', ImageUpload::ART_FORMATS, null],
        'a PNG signature on garbage fails its decode' => ["\x89PNG\r\n\x1A\n" . str_repeat('x', 200), 'plate.png', ImageUpload::PLATE_FORMATS, UploadRefused::UNDECODABLE_IMAGE],
        'a JPEG signature on an SVG fails its decode' => ["\xFF\xD8\xFF" . $svg, 'plate.jpg', ImageUpload::PLATE_FORMATS, UploadRefused::UNDECODABLE_IMAGE],
        'an empty file is refused' => ['', 'plate.png', ImageUpload::PLATE_FORMATS, UploadRefused::NOT_AN_ACCEPTED_IMAGE],
        '4000px on a side passes' => [png(4000, 10), 'plate.png', ImageUpload::PLATE_FORMATS, null],
        '4001px on a side is refused' => [png(4001, 10), 'plate.png', ImageUpload::PLATE_FORMATS, UploadRefused::IMAGE_TOO_LARGE],
        '12 megapixels passes' => [png(4000, 3000), 'plate.png', ImageUpload::PLATE_FORMATS, null],
        'past 12 megapixels is refused' => [png(3500, 3500), 'plate.png', ImageUpload::PLATE_FORMATS, UploadRefused::IMAGE_TOO_LARGE],
    ] as $label => [$bytes, $name, $accepted, $expected]) {
        $got = refusal(upload($bytes, $name), $accepted);
        check($label, $got === $expected, var_export($got, true));
    }

    $read = ImageUpload::read(upload(jpeg(321, 123), 'plate.png'), ImageUpload::PLATE_FORMATS);
    check('format and size come from the bytes', [$read->format, $read->width, $read->height] === ['jpeg', 321, 123]);

    // G-39.
    foreach ([1199 => true, 1200 => false, 1275 => false, 1350 => false, 1351 => true, 511 => true] as $width => $warns) {
        check("a plate {$width}px wide " . ($warns ? 'warns' : 'is quiet'), (new ImageUpload('png', $width, 100))->plateWidthWarns() === $warns);
    }

    // G-38, and the rule the whole tree rests on.
    File::$root = $root;
    $store = new FileStore($root);
    $bytes = png(40, 30);
    $hash = $store->put(upload($bytes), 'png');
    $expectedPath = "$root/cav7_citation_files/" . substr($hash, 0, 2) . "/$hash.png";

    check('a file is named by the SHA-256 of its bytes', $hash === hash('sha256', $bytes));
    check('the path shards on the hash', $store->path($hash, 'png') === $expectedPath);
    check('the stored file is the uploaded bytes', file_get_contents($expectedPath) === $bytes);

    $store->put(upload($bytes), 'png');
    check('identical bytes leave one file and write nothing', File::$writes === 1);

    file_put_contents($expectedPath, substr($bytes, 0, 10));
    $store->put(upload($bytes), 'png');
    check('a torn file is written again', file_get_contents($expectedPath) === $bytes);

    $jpegHash = $store->put(upload(jpeg(5, 5)), 'jpeg');
    check('a JPEG plate takes .jpg', is_file("$root/cav7_citation_files/" . substr($jpegHash, 0, 2) . "/$jpegHash.jpg"));
    $fontHash = $store->put(__DIR__ . '/../fonts/Tinos-Regular.ttf', 'font');
    check('a pinned font takes .ttf', is_file($store->path($fontHash, 'font')) && str_ends_with($store->path($fontHash, 'font'), '.ttf'));

    try {
        $store->put("$root/missing.ttf", 'font');
        check('a missing source throws rather than pinning nothing', false);
    } catch (\RuntimeException) {
        check('a missing source throws rather than pinning nothing', true);
    }

    // The typesetting table.
    $db = new AbstractAdapter();
    $db->query('CREATE TABLE ' . TypesettingRows::TABLE . ' (
        grant_id INTEGER, grant_member_id INTEGER, field_key TEXT, size REAL, below_legibility INTEGER, set_data BLOB,
        addon_version_id INTEGER, imagemagick_version TEXT, freetype_version TEXT, typeset_date INTEGER,
        PRIMARY KEY (grant_id, grant_member_id, field_key))');

    $rows = new TypesettingRows($db);
    $libraries = new LibraryVersions('7.1.2-34', '2.14.3');
    $line = new Line('For a single act of courage', 139.45, 607.2, 996.0, 9.842);
    $nameLine = new Line('Ewan McQuill', 500.0, 500.0, 200.0, 0.0);
    $art = new PlacedImage(str_repeat('a', 64), 572, 310, 136, 140);

    $rows->write(1, TypesettingRows::SHARED, [
        'body' => new SetField('body', 24.75, true, [$line]),
        'empty' => new SetField('empty', 0.0, false, []),
    ], 1010070, $libraries, 1000);
    $rows->write(1, 7, [
        'recipient' => new SetField('recipient', 40.0, false, [$nameLine]),
        'insignia' => new SetField('insignia', 0.0, false, null, art: $art),
        'no_art' => new SetField('no_art', 0.0, false, null),
    ], 1010070, $libraries, 1000);
    $rows->write(1, 8, ['recipient' => new SetField('recipient', 39.0, false, [$nameLine])], 1010070, $libraries, 1000);
    $rows->write(2, TypesettingRows::SHARED, ['other' => new SetField('other', 12.5, true, [$line])], 1010070, $libraries, 1000);

    $read = $rows->read(1, 7);
    $keys = array_keys($read);
    sort($keys);
    check('a member reads their rows and the shared rows, no one else\'s', $keys === ['body', 'empty', 'insignia', 'no_art', 'recipient'], json_encode($keys));
    check('lines survive the table', $read['body']->lines == [$line] && $read['body']->size === 24.75 && $read['body']->belowLegibility);
    check('a field that printed nothing stays a text field', $read['empty']->lines === []);
    check('placed art survives the table', $read['insignia']->art == $art && $read['insignia']->lines === null);
    check('a rank with no art reads back as no art', $read['no_art']->art === null && $read['no_art']->lines === null);

    $rows->write(1, 7, ['recipient' => new SetField('recipient', 38.5, false, [$nameLine])], 1010071, $libraries, 2000);
    check('a remade field replaces its row', $rows->read(1, 7)['recipient']->size === 38.5);
    check('a remade field takes the new stamp', (int) $db->fetchOne(
        'SELECT addon_version_id FROM ' . TypesettingRows::TABLE . " WHERE grant_member_id = 7 AND field_key = 'recipient'"
    ) === 1010071);

    $all = $rows->all(1);
    check('a grant reads back whole, shared apart from each member', array_keys($all->shared) === ['body', 'empty'] && array_keys($all->members) === [7, 8] && count($all->members[7]) === 3);
    check('a grant holds one set of stamps per version it was made under', count($rows->stamps(1)) === 2);

    $rows->restamp(1, 1010072, new LibraryVersions('7.1.2-40', '2.15.0'));
    check('a restamp reaches every row of the grant and no other', array_map('intval', array_column($rows->stamps(1), 'addon_version_id')) === [1010072] && (int) $rows->stamps(2)[0]['addon_version_id'] === 1010070);
    check('a restamp leaves the set data alone', $rows->read(1, 7)['recipient']->size === 38.5);
    check('--addon-version finds a grant by any row', $rows->grantsStamped(1010072) === [1] && $rows->grantsStamped(1010070) === [2] && $rows->grantsStamped(999) === []);

    // G-48: below_legibility rows joined to their grant.
    $db->query('CREATE TABLE xf_cav7_citation_grant (grant_id INTEGER, template_version_id INTEGER, kind_title TEXT, withdrawn_date INTEGER)');
    $db->query('CREATE TABLE xf_cav7_citation_template_version (template_version_id INTEGER, template_id INTEGER, version_number INTEGER)');
    $db->query("INSERT INTO xf_cav7_citation_grant VALUES (1, 41, 'Bronze Star', 0), (2, 41, 'Silver Star', 0), (3, 41, 'PUC', 1700000000)");
    $db->query('INSERT INTO xf_cav7_citation_template_version VALUES (41, 6, 3)');
    $rows->write(1, 8, ['recipient' => new SetField('recipient', 20.0, true, [$nameLine])], 1010070, $libraries, 1000);
    $rows->write(3, TypesettingRows::SHARED, ['body' => new SetField('body', 12.5, true, [$line])], 1010070, $libraries, 1000);

    $listed = fn (): array => array_map(
        fn (array $row): string => "{$row['grant_id']}:{$row['grant_member_id']}:{$row['field_key']}",
        $rows->belowLegibility()
    );
    check('G-48 a shared field is one entry, a member field one per member, a withdrawn grant none', $listed() === ['1:0:body', '1:8:recipient', '2:0:other'], json_encode($listed()));
    $entry = $rows->belowLegibility()[0];
    check('G-48 an entry carries its grant and version', $entry['kind_title'] === 'Bronze Star' && (int) $entry['template_id'] === 6 && (int) $entry['version_number'] === 3);

    $rows->clearMember(1, 8);
    check('G-48 a removed member\'s rows go with them', $listed() === ['1:0:body', '2:0:other'] && !isset($rows->all(1)->members[8]) && isset($rows->all(1)->members[7]));

    $rows->clearGrant(1);
    check('moving a version clears only that grant', $rows->all(1)->shared === [] && $rows->all(1)->members === [] && isset($rows->all(2)->shared['other']));

    // G-45.
    $db->query('CREATE TABLE ' . OverrunLog::TABLE . ' (
        template_version_id INTEGER, imagemagick_version TEXT, freetype_version TEXT, log_date INTEGER,
        PRIMARY KEY (template_version_id, imagemagick_version, freetype_version))');
    $errors = [];
    $overruns = new OverrunLog($db, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });
    $overrun = new Overrun('body', 3.5);

    $overruns->record(41, 7, $overrun, $libraries, 1000);
    check('G-45 a first overrun logs, naming what S1 needs', count($errors) === 1
        && str_contains($errors[0], 'template version 41') && str_contains($errors[0], 'body') && str_contains($errors[0], 'grant member 7')
        && str_contains($errors[0], '3.50px') && str_contains($errors[0], '7.1.2-34') && str_contains($errors[0], '2.14.3'), $errors[0] ?? '');
    $overruns->record(41, 8, new Overrun('recipient', 9.0), $libraries, 2000);
    check('G-45 the same pair logs nothing more', count($errors) === 1);
    $overruns->record(41, 7, $overrun, new LibraryVersions('7.1.2-34', '2.15.0'), 3000);
    check('G-45 a new FreeType logs again', count($errors) === 2);
    $overruns->record(42, 7, $overrun, $libraries, 3000);
    check('G-45 another version logs', count($errors) === 3);

    $db->query('DROP TABLE ' . OverrunLog::TABLE);
    $overruns->record(43, 7, $overrun, $libraries, 3000);
    check('a failure writing the log doesn\'t fail the render', count($errors) === 3);

    finish();
}
