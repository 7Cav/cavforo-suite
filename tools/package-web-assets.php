<?php

/**
 * package-web-assets.php — reproduce XenForo's build.json web-asset handling for
 * the no-XenForo packaging path (issue #103). Given an addon's source dir and
 * the upload/ root of a release build, it copies the addon's declared web assets
 * out of _files/ into the web root and writes the .min.js companions that
 * build.json's minify names, then checks every <xf:js> the addon owns resolves
 * (including any min="1" file).
 *
 *   php tools/package-web-assets.php <addon-src-dir> <upload-root> <addon-id>
 *
 * <addon-src-dir>  the addon directory holding build.json, _files/ and _data/
 *                  (in the release build this is the extracted
 *                  upload/src/addons/<Vendor>/<Id> tree, read before _files/ and
 *                  build.json are stripped from it).
 * <upload-root>    the build's upload/ directory; web assets land at its root, so
 *                  _files/js/... becomes <upload-root>/js/...
 * <addon-id>       the addon id, e.g. Cav7/MilpacMention. Used to tell which
 *                  <xf:js addon="..."> references this addon owns.
 *
 * This mirrors XF\Service\AddOn\ReleaseBuilderService:
 *   - _files/ is excluded from the build by default; a path is copied out of it
 *     only when build.json names it under additional_files, at the same web-root
 *     path it has relative to _files/ (prepareFilesToCopy).
 *   - minify "*" walks upload/js and writes <name>.min.js for every .js that is
 *     not already a .min.js; a minify array names specific paths (minifyJs). The
 *     min filename is the source name with .js replaced by .min.js.
 *
 * The main thing it does not reproduce is real minification: XF shells out to
 * the Closure Compiler, which the CI/release runners do not have, so the .min.js
 * is a byte-for-byte copy of the source. It is valid, working JS at the exact
 * path a min="1" include requests, so the asset serves (no 404); it is just not
 * size-optimised. (Same spirit as package-addon.sh not reproducing hashes.json.)
 * It also diverges from XF on additional_files: there is no install-root fallback
 * for a declared path, and a path with no (or an empty) _files backing is an
 * error here rather than silently skipped.
 *
 * An addon with no build.json and no owned <xf:js> is a clean no-op. Exits
 * non-zero, naming the offending item, when a declared additional_files path has
 * no _files backing or an owned <xf:js> does not resolve at the web root.
 */

$srcDir = rtrim($argv[1] ?? '', '/');
$uploadRoot = rtrim($argv[2] ?? '', '/');
$addonId = $argv[3] ?? '';

if ($srcDir === '' || $uploadRoot === '' || $addonId === '') {
    fwrite(STDERR, "usage: php tools/package-web-assets.php <addon-src-dir> <upload-root> <addon-id>\n");
    exit(2);
}
if (!is_dir($srcDir)) {
    fwrite(STDERR, "error: addon source dir not found: $srcDir\n");
    exit(2);
}
if (!is_dir($uploadRoot)) {
    fwrite(STDERR, "error: upload root not found: $uploadRoot\n");
    exit(2);
}

$filesRoot = "$srcDir/_files";
$errors = [];

// --- read build.json (defaults match XF: empty additional_files/minify) -------
$buildJson = ['additional_files' => [], 'minify' => []];
$buildJsonPath = "$srcDir/build.json";
if (is_file($buildJsonPath)) {
    $decoded = json_decode((string) file_get_contents($buildJsonPath), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "error: build.json is not valid JSON: " . json_last_error_msg() . "\n");
        exit(1);
    }
    $buildJson = array_replace($buildJson, $decoded);
}

/**
 * Copy $from to $to, creating parent directories as needed. Returns null on
 * success, or an error string when the directory could not be made or the copy
 * failed — a discarded failure here means the asset silently never reaches the
 * zip (a 404 behind a green build), so the caller must surface it.
 */
function copyInto(string $from, string $to): ?string
{
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return "failed to create directory: $dir";
    }
    if (!@copy($from, $to)) {
        return "failed to copy $from -> $to";
    }
    return null;
}

/** Every file (not dirs) under $root, at any depth, as absolute pathnames. */
function filesUnder(string $root): array
{
    $out = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile()) {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

// --- additional_files: copy each declared path out of _files/ to the web root -
// A declared path is relative to _files/, and it keeps that same path under the
// upload root — so _files/js/V/A becomes upload/js/V/A (XF's stdPath logic).
$copied = 0;
foreach ((array) ($buildJson['additional_files'] ?? []) as $rel) {
    if (!is_string($rel) || $rel === '') {
        continue;
    }
    $rel = trim($rel, '/');
    $source = "$filesRoot/$rel";
    if (is_dir($source)) {
        $filesInEntry = filesUnder($source);
        if (!$filesInEntry) {
            // The dir backs the declaration but holds no files, so it would copy
            // nothing and pass silently — the same missing-asset trap as a path
            // with no backing at all.
            $errors[] = "additional_files entry contributes no files: _files/$rel";
        }
        foreach ($filesInEntry as $file) {
            // Path of this file relative to _files/, preserved under upload/.
            $stdPath = ltrim(substr($file, strlen($filesRoot)), '/');
            if (($err = copyInto($file, "$uploadRoot/$stdPath")) !== null) {
                $errors[] = $err;
                continue;
            }
            $copied++;
        }
    } elseif (is_file($source)) {
        if (($err = copyInto($source, "$uploadRoot/$rel")) !== null) {
            $errors[] = $err;
        } else {
            $copied++;
        }
    } else {
        $errors[] = "additional_files path has no _files backing: _files/$rel";
    }
}

// --- minify: write <name>.min.js for the requested JS (verbatim copy) ---------
/** Map a .js path to its .min.js companion (XF: .js -> .min.js, once, at end). */
function minName(string $path): string
{
    return preg_replace('/\.js$/', '.min.js', $path, 1);
}

$minify = $buildJson['minify'] ?? [];
$minified = 0;

if ($minify === '*') {
    // Walk upload/js, minify every .js that is not already a .min.js.
    $jsRoot = "$uploadRoot/js";
    if (is_dir($jsRoot)) {
        foreach (filesUnder($jsRoot) as $file) {
            $name = basename($file);
            if (!str_ends_with($name, '.js') || str_ends_with($name, '.min.js')) {
                continue;
            }
            if (($err = copyInto($file, minName($file))) !== null) {
                $errors[] = $err;
                continue;
            }
            $minified++;
        }
    }
} elseif (is_array($minify)) {
    // Named paths, each relative to the upload root (XF: uploadRoot/<file>).
    foreach ($minify as $rel) {
        if (!is_string($rel) || $rel === '') {
            continue;
        }
        $rel = ltrim($rel, '/');
        if (!str_ends_with($rel, '.js')) {
            // XF's minifyJs only handles .js paths; a non-.js entry is a
            // build.json mistake, not something to drop on the floor.
            $errors[] = "minify entry is not a .js file: $rel";
            continue;
        }
        if (str_ends_with($rel, '.min.js')) {
            // Already minified — nothing to write, a legitimate no-op.
            continue;
        }
        $source = "$uploadRoot/$rel";
        if (!is_file($source)) {
            $errors[] = "minify path is not at the web root: $rel";
            continue;
        }
        if (($err = copyInto($source, minName($source))) !== null) {
            $errors[] = $err;
            continue;
        }
        $minified++;
    }
}

// --- <xf:js src> resolution check: every reference this addon owns must exist -
// XF serves <xf:js src="X"> from the web root's js/ folder, and a min="1"
// include requests js/<X with .js -> .min.js> in a non-dev install. Scan the
// addon's _data templates and template modifications for <xf:js> tags, and for
// each one this addon owns (addon="<addon-id>") require the file at the web root.
$references = [];
foreach (['template_modifications.xml', 'templates.xml'] as $dataFile) {
    $path = "$srcDir/_data/$dataFile";
    if (!is_file($path)) {
        continue;
    }
    $raw = (string) file_get_contents($path);
    // Match <xf:js ...> anywhere, including inside CDATA (raw text scan). Capture
    // the attribute string; attribute order is arbitrary.
    if (!preg_match_all('/<xf:js\b([^>]*?)\/?>/is', $raw, $tags)) {
        continue;
    }
    foreach ($tags[1] as $attrString) {
        $attrs = [];
        preg_match_all(
            '/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/',
            $attrString,
            $pairs,
            PREG_SET_ORDER
        );
        foreach ($pairs as $pair) {
            // Group 2 is the double-quoted value, group 3 the single-quoted one;
            // exactly one alternative matched, so take whichever is non-empty.
            $attrs[$pair[1]] = ($pair[2] ?? '') !== '' ? $pair[2] : ($pair[3] ?? '');
        }
        $references[] = $attrs;
    }
}

$checked = 0;
foreach ($references as $attrs) {
    $src = $attrs['src'] ?? '';
    $owner = $attrs['addon'] ?? '';
    // Only references this addon owns are its responsibility to ship. A tag with
    // no addon, or another addon's id, points at a core/vendor asset we do not
    // package here.
    if ($src === '' || $owner !== $addonId) {
        continue;
    }
    $checked++;

    $base = "js/$src";
    if (!is_file("$uploadRoot/$base")) {
        $errors[] = "<xf:js src=\"$src\"> ($addonId) does not resolve: $base missing at web root";
    }

    $min = strtolower($attrs['min'] ?? '');
    if ($min === '1' || $min === 'true') {
        $minPath = minName($base);
        if ($minPath !== $base && !is_file("$uploadRoot/$minPath")) {
            $errors[] = "<xf:js src=\"$src\" min=\"1\"> ($addonId) has no minified asset: $minPath missing at web root";
        }
    }
}

if ($errors) {
    fwrite(STDERR, "FAIL web assets: $addonId\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

echo "OK web assets: $addonId "
    . "($copied file(s) copied, $minified minified, $checked xf:js reference(s) checked)\n";
exit(0);
