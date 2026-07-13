<?php

/**
 * package-web-assets-test.php — pins tools/package-web-assets.php, the step that
 * reproduces XenForo's build.json handling for the no-XenForo packaging path
 * (issue #103). Self-contained: builds throwaway fixture addon dirs in the
 * system temp dir, runs the real tool against each, asserts on its exit code,
 * output and the files it lands in the upload root, then cleans up. Exits
 * non-zero on any failure.
 *
 * The gap this guards: XenForo's ReleaseBuilderService excludes _files/ from a
 * build and only copies a path out of it when build.json names it under
 * additional_files, minifying (writing <name>.min.js) for paths named by
 * minify. tools/package-addon.sh builds the release zip with no XenForo, so it
 * has to do the same or an addon's web-served JS never reaches the web root and
 * 404s in a non-dev install. This tool is that reproduction; the test locks:
 *   - additional_files + minify "*" copies _files/<path> to upload/<path> and
 *     writes <name>.min.js beside it (MilpacMention's shape),
 *   - the minify array form and an additional_files file (not dir) entry,
 *   - the no-op path for an addon with no build.json (no upload/js created),
 *   - the <xf:js src> resolution check: an owned reference that resolves passes,
 *     one that does not fails and names the missing web path, and a reference
 *     owned by another addon is not this addon's responsibility (ignored),
 *   - a declared additional_files path with no _files backing is an error.
 *
 * Run:
 *   php tools/tests/package-web-assets-test.php
 *
 * Note: CI runs the tools/ tests via the tools-test job, which invokes
 * tools/run-tools-tests.sh.
 */

namespace Cav7\Tools\Tests;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** Write $content to $path, creating parent directories as needed. */
function putFile(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

/** Run the real tool; return [exitCode, combinedOutput]. */
function runTool(string $tool, string $srcDir, string $uploadDir, string $addonId): array
{
    $cmd = 'php ' . escapeshellarg($tool)
        . ' ' . escapeshellarg($srcDir)
        . ' ' . escapeshellarg($uploadDir)
        . ' ' . escapeshellarg($addonId)
        . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

/** Recursively remove a directory tree. */
function rmrf(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            rmrf("$path/$entry");
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** Every file (not dirs) under $dir, at any depth, as absolute pathnames. */
function allFilesUnder(string $dir): array
{
    $out = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = "$dir/$entry";
        if (is_dir($path)) {
            $out = array_merge($out, allFilesUnder($path));
        } else {
            $out[] = $path;
        }
    }
    return $out;
}

/**
 * Build a throwaway fixture: an addon source dir plus its own upload/ root.
 * Returns [srcDir, uploadDir].
 *
 * $files maps a path relative to the addon dir (e.g. "_files/js/V/A/x.js",
 * "build.json", "_data/template_modifications.xml") to its contents.
 */
function makeFixture(string $base, string $name, array $files): array
{
    $src = "$base/$name/addon";
    $upload = "$base/$name/upload";
    mkdir($src, 0777, true);
    mkdir($upload, 0777, true);
    foreach ($files as $rel => $content) {
        putFile("$src/$rel", $content);
    }
    return [$src, $upload];
}

/** A template_modifications.xml carrying one <xf:js> tag inside CDATA. */
function tmodWithJs(string $addon, string $src, string $min): string
{
    return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
        . "<template_modifications>\n"
        . "  <modification type=\"public\" template=\"editor\" modification_key=\"k\" action=\"str_replace\">\n"
        . "    <find><![CDATA[<!--[XF:include_js]-->]]></find>\n"
        . "    <replace><![CDATA[\$0\n"
        . "\t<xf:js addon=\"$addon\" src=\"$src\" min=\"$min\" />]]></replace>\n"
        . "  </modification>\n"
        . "</template_modifications>\n";
}

$tool = dirname(__DIR__) . '/package-web-assets.php';

$base = sys_get_temp_dir() . '/cav7-web-assets-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);

$jsBody = "// widget\n((window) => {\n\t'use strict'\n\tconst X = 1\n\treturn X\n})(window)\n";

try {
    // --- A. additional_files dir + minify "*", with an owned min=1 xf:js -------
    // MilpacMention's exact shape: _files/js/V/A/editor.js declared as an
    // additional_files directory, minify "*", referenced by <xf:js min="1">.
    [$src, $up] = makeFixture($base, 'A', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonA\"],\n    \"minify\": \"*\"\n}\n",
        '_files/js/Vendor/AddonA/editor.js' => $jsBody,
        '_data/template_modifications.xml' => tmodWithJs('Vendor/AddonA', 'Vendor/AddonA/editor.js', '1'),
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonA');
    check('A: build.json copy+minify exits 0', $code === 0, "exit=$code\n$out");
    check(
        'A: _files JS copied to upload web root',
        is_file("$up/js/Vendor/AddonA/editor.js"),
        $out
    );
    check(
        'A: copied editor.js is byte-identical to source',
        is_file("$up/js/Vendor/AddonA/editor.js")
            && file_get_contents("$up/js/Vendor/AddonA/editor.js") === $jsBody,
        $out
    );
    check(
        'A: editor.min.js written beside editor.js',
        is_file("$up/js/Vendor/AddonA/editor.min.js"),
        $out
    );
    check(
        'A: min filename is <name>.min.js (not <name>.js.min or editor.min.min.js)',
        is_file("$up/js/Vendor/AddonA/editor.min.js")
            && !is_file("$up/js/Vendor/AddonA/editor.min.min.js"),
        $out
    );
    // Enumerate every file the tool actually landed and assert each is under the
    // js/ web root — catches a stray copy anywhere (e.g. leaking _files/ or src/),
    // unlike a hand-picked !is_dir() list that can only catch paths named ahead of
    // time.
    $strayA = array_filter(
        allFilesUnder($up),
        fn(string $f): bool => strpos($f, "$up/js/") !== 0
    );
    check(
        'A: every file under upload/ lives under the js/ web root',
        $strayA === [],
        'stray: ' . implode(', ', $strayA) . "\n$out"
    );

    // --- B. no build.json: clean no-op, no upload/js created -------------------
    [$src, $up] = makeFixture($base, 'B', [
        '_data/options.xml' => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<options/>\n",
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonB');
    check('B: no build.json exits 0 (no-op)', $code === 0, "exit=$code\n$out");
    check('B: no upload/js created for a no-op addon', !is_dir("$up/js"), $out);

    // --- C. owned <xf:js> that resolves to nothing: fail and name the path -----
    // No build.json, so nothing is copied; the template still asks for the file.
    [$src, $up] = makeFixture($base, 'C', [
        '_data/template_modifications.xml' => tmodWithJs('Vendor/AddonC', 'Vendor/AddonC/ghost.js', '1'),
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonC');
    check('C: unresolved owned xf:js fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check('C: failure names the missing web asset', str_contains($out, 'ghost.js'), $out);

    // --- D. xf:js owned by another addon (or none): not our responsibility -----
    $foreign = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
        . "<templates>\n"
        . "  <template type=\"public\" title=\"t\"><![CDATA[\n"
        . "    <xf:js addon=\"XF\" src=\"vendor/foo.js\" />\n"
        . "    <xf:js src=\"bar.js\" />\n"
        . "  ]]></template>\n"
        . "</templates>\n";
    [$src, $up] = makeFixture($base, 'D', [
        '_data/templates.xml' => $foreign,
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonD');
    check('D: xf:js owned by another addon is ignored (exit 0)', $code === 0, "exit=$code\n$out");
    check('D: no upload/js created for unowned references', !is_dir("$up/js"), $out);

    // --- E. minify array form + additional_files file (not dir) entry ----------
    [$src, $up] = makeFixture($base, 'E', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonE/widget.js\"],\n"
            . "    \"minify\": [\"js/Vendor/AddonE/widget.js\"]\n}\n",
        '_files/js/Vendor/AddonE/widget.js' => $jsBody,
        '_data/template_modifications.xml' => tmodWithJs('Vendor/AddonE', 'Vendor/AddonE/widget.js', '1'),
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonE');
    check('E: array minify + file additional_files exits 0', $code === 0, "exit=$code\n$out");
    check('E: single-file additional_files copied', is_file("$up/js/Vendor/AddonE/widget.js"), $out);
    check('E: array-minify produced widget.min.js', is_file("$up/js/Vendor/AddonE/widget.min.js"), $out);

    // --- F. additional_files names a path with no _files backing: error --------
    [$src, $up] = makeFixture($base, 'F', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonF\"],\n    \"minify\": \"*\"\n}\n",
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonF');
    check('F: additional_files with no _files backing fails', $code !== 0, "exit=$code\n$out");
    check('F: failure names the missing additional_files path', str_contains($out, 'js/Vendor/AddonF'), $out);

    // --- G. a failed copy is loud: non-zero exit, names the copy, no silent OK --
    // The silent-missing-asset bug this guard exists to prevent: a discarded
    // copy() return prints "OK ... (N copied)" and exits 0 with the asset absent
    // from the zip → a 404 behind a green build. Force copy() to fail
    // deterministically (works even as root, so no chmod-vs-root flakiness): make
    // the destination file *path* an existing directory, which copy() cannot
    // overwrite.
    [$src, $up] = makeFixture($base, 'G', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonG\"]\n}\n",
        '_files/js/Vendor/AddonG/editor.js' => $jsBody,
    ]);
    mkdir("$up/js/Vendor/AddonG/editor.js", 0777, true); // block the copy target
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonG');
    check('G: a failed copy exits non-zero', $code !== 0, "exit=$code\n$out");
    check(
        'G: failure names the copy that failed',
        stripos($out, 'copy') !== false && str_contains($out, 'editor.js'),
        $out
    );
    check(
        'G: a failed copy never prints the OK line',
        !str_contains($out, 'OK web assets'),
        $out
    );

    // --- I. additional_files dir that EXISTS but is EMPTY: error, not a no-op ---
    // Same silent-skip class as G: the dir backs the declaration but contributes
    // nothing, so without a guard it copies zero files and exits 0 — the asset is
    // silently absent. The backing dir is created empty (makeFixture only writes
    // files, so mkdir it directly).
    [$src, $up] = makeFixture($base, 'I', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonI\"]\n}\n",
    ]);
    mkdir("$src/_files/js/Vendor/AddonI", 0777, true); // exists, but holds no files
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonI');
    check('I: empty additional_files dir fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check(
        'I: failure names the empty additional_files entry',
        str_contains($out, 'js/Vendor/AddonI'),
        $out
    );

    // --- J. a minify array entry not ending in .js: error, not silent drop ------
    // The .css is copied to the web root by additional_files, then named in
    // minify where it does not belong; dropping it silently hides a build.json
    // mistake. (A .min.js entry is a legitimate no-op and must still be skipped
    // quietly — covered by E's widget.js flow.)
    // Assert on the guard's distinctive wording, not just "style.css": if the
    // non-.js guard were deleted, the .css falls through to copyInto and fails
    // copying the file onto itself — an error that *also* names style.css, so a
    // "style.css" assertion would stay green with the guard gone. "not a .js
    // file" only appears when the guard itself fires.
    [$src, $up] = makeFixture($base, 'J', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonJ\"],\n"
            . "    \"minify\": [\"js/Vendor/AddonJ/style.css\"]\n}\n",
        '_files/js/Vendor/AddonJ/style.css' => "body{color:red}\n",
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonJ');
    check('J: non-.js minify entry fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check('J: failure cites the non-.js guard', str_contains($out, 'not a .js file'), $out);

    // --- H. owned min="1" xf:js whose BASE resolves but has NO .min.js ----------
    // The exact #103 404: additional_files copies editor.js so the base resolves,
    // but with no minify key nothing writes editor.min.js, and a non-dev install
    // requests the .min.js a min="1" include names. The build must fail and point
    // at the missing .min.js specifically (base resolves, so it must NOT be the
    // "does not resolve" message).
    [$src, $up] = makeFixture($base, 'H', [
        'build.json' => "{\n    \"additional_files\": [\"js/Vendor/AddonH\"]\n}\n",
        '_files/js/Vendor/AddonH/editor.js' => $jsBody,
        '_data/template_modifications.xml' => tmodWithJs('Vendor/AddonH', 'Vendor/AddonH/editor.js', '1'),
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonH');
    check('H: base editor.js is present (copied)', is_file("$up/js/Vendor/AddonH/editor.js"), $out);
    check('H: min="1" with no .min.js companion fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check(
        'H: failure names the missing .min.js specifically',
        str_contains($out, 'js/Vendor/AddonH/editor.min.js'),
        $out
    );

    // --- K. minify array names a .js that is not at the web root: error ---------
    // Nothing in additional_files backs it, so there is no source to minify; the
    // build must fail and name the path rather than skip it.
    // Assert on the guard's distinctive wording, not just the path: if the
    // at-web-root guard were deleted, the missing source flows into copyInto,
    // which mkdir's the parent before the copy fails — so the copy error still
    // names the path *and* a spurious empty js/Vendor/AddonK is left behind.
    // "not at the web root" fires only from the guard, and the no-dir check pins
    // that the guard bails before creating anything.
    [$src, $up] = makeFixture($base, 'K', [
        'build.json' => "{\n    \"minify\": [\"js/Vendor/AddonK/missing.js\"]\n}\n",
    ]);
    [$code, $out] = runTool($tool, $src, $up, 'Vendor/AddonK');
    check('K: minify path not at web root fails (exit non-zero)', $code !== 0, "exit=$code\n$out");
    check(
        'K: failure cites the at-web-root guard',
        str_contains($out, 'not at the web root'),
        $out
    );
    check(
        'K: guard bails before creating a spurious empty dir',
        !is_dir("$up/js/Vendor/AddonK"),
        $out
    );
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
