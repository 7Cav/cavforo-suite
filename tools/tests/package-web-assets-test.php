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
 * Note: nothing in CI runs tools/ tests yet (run-tests.sh only runs addon
 * tests/*.php). This is the local completion proof for #103; wiring a tools-test
 * lane into CI is a separate follow-up.
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
    check(
        'A: nothing copied to upload outside the js/ web root',
        !is_dir("$up/_files") && !is_dir("$up/src"),
        $out
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
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
