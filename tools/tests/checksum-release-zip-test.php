<?php

/**
 * checksum-release-zip-test.php — pins tools/checksum-release-zip.sh, the tool
 * that writes the `.sha256` sidecar published beside a release zip (issue
 * #256), with no XenForo and no test framework. Self-contained: builds a
 * throwaway artifact in the system temp dir, runs the real tool against it,
 * reads the sidecar it wrote, then cleans up. Exits non-zero on any failure.
 *
 * The gap this guards: the release workflow ran `sha256sum "$zip"` on the
 * absolute path package-addon.sh prints, so every published sidecar named the
 * runner's workspace — /home/runner/work/cavforo-suite/... — and `sha256sum -c`
 * on a downloaded pair failed with "No such file or directory". The digests
 * were correct; only the name made them unusable. Thirty-three releases shipped
 * that way, because nothing here ever executed a sidecar.
 *
 * The two fields are asserted separately, each against a source of truth
 * outside the tool: the name against the literal filename this test created,
 * the digest against PHP's own hash_file(). One `sha256sum -c` run cannot stand
 * in for either — it passes on an absolute-path sidecar whenever the artifact
 * still sits where it was built, which in a test it always does unless the
 * fixture is moved away first. The move case below is what makes that run mean
 * anything, and the field assertions are what keep the check honest if the
 * move is ever weakened to a copy.
 *
 * Run:
 *   php tools/tests/checksum-release-zip-test.php
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

$tool = dirname(__DIR__) . '/checksum-release-zip.sh';

/**
 * Run the real tool against $artifact, from $cwd. The working directory is a
 * parameter because the release step's happens to be the directory the zip
 * lands in, and a tool that quietly depends on that coincidence breaks the
 * moment it is called from anywhere else.
 *
 * Returns [exitCode, combinedOutput].
 */
function runTool(string $tool, string $artifact, string $cwd): array
{
    $cmd = 'cd ' . escapeshellarg($cwd)
        . ' && ' . escapeshellarg($tool) . ' ' . escapeshellarg($artifact) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

/**
 * Split a checksum entry into its [digest, name] fields. The format is one
 * line, digest first, name second, separated by run of whitespace — so the two
 * can be asserted apart from each other rather than as one opaque string.
 */
function fields(string $entry): array
{
    $parts = preg_split('/\s+/', trim($entry), 2);
    return [$parts[0] ?? '', $parts[1] ?? ''];
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

$base = sys_get_temp_dir() . '/cav7-checksum-release-zip-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);

// The name is a literal, written once here and asserted against below. Deriving
// the expectation with basename() would recompute it the way the tool does and
// agree with the tool however wrong it was.
$artifactName = 'Cav7-Fixture-v1.0.0.zip';

try {
    // --- Case 1: the sidecar names the artifact, and nothing else ---------
    // The whole defect in one assertion. It reads the name field directly, so
    // no amount of path resolution elsewhere can satisfy it.
    $buildDir = "$base/build";
    $elsewhere = "$base/elsewhere";
    mkdir($buildDir, 0777, true);
    mkdir($elsewhere, 0777, true);

    $artifact = "$buildDir/$artifactName";
    file_put_contents($artifact, "not really a zip, but it has bytes and that is all a digest needs\n");

    [$code, $out] = runTool($tool, $artifact, $elsewhere);
    check('checksumming an artifact exits zero', $code === 0, $out);

    $sidecar = "$artifact.sha256";
    check('the sidecar is written beside the artifact', is_file($sidecar), $out);
    check(
        'the sidecar is not written into the invoking directory',
        !is_file("$elsewhere/$artifactName.sha256"),
        $out
    );

    $entry = is_file($sidecar) ? file_get_contents($sidecar) : '';
    check(
        'the sidecar holds exactly one entry',
        substr_count(trim($entry), "\n") === 0 && trim($entry) !== '',
        var_export($entry, true)
    );

    [$digest, $name] = fields($entry);
    check(
        'the entry names the artifact file alone',
        $name === $artifactName,
        'name field was ' . var_export($name, true)
    );
    check(
        'the entry carries the artifact digest',
        $digest === hash_file('sha256', $artifact),
        'digest field was ' . var_export($digest, true)
    );

    // --- Case 2: the pair verifies where it lands, not where it was built -
    // What a downloader actually does. Both files move to a directory that has
    // no relationship to the one they were built in, and the build directory
    // stops existing — so an entry naming any path outside the pair's own
    // directory has nothing left to resolve to.
    $downloadDir = "$base/download";
    mkdir($downloadDir, 0777, true);
    rename($artifact, "$downloadDir/$artifactName");
    rename($sidecar, "$downloadDir/$artifactName.sha256");
    rmrf($buildDir);

    $verify = 'cd ' . escapeshellarg($downloadDir)
        . ' && sha256sum -c ' . escapeshellarg("$artifactName.sha256") . ' 2>&1';
    $verifyOut = [];
    $verifyCode = 0;
    exec($verify, $verifyOut, $verifyCode);
    check(
        'sha256sum -c verifies the downloaded pair',
        $verifyCode === 0,
        implode("\n", $verifyOut)
    );

    // --- Case 3: an artifact that is not there is a failure, not a sidecar -
    // A sidecar written for a build that did not produce a zip would publish a
    // checksum of nothing, and the release step would upload it.
    //
    // The missing artifact sits in a directory that DOES exist. Naming one in a
    // directory that does not would make this vacuous: the tool resolves the
    // directory before it checksums anything, so a missing directory fails the
    // run for a reason that has nothing to do with the artifact, and an
    // implementation with no existence check at all would pass. With the
    // directory present, dropping that check leaves a zero-byte sidecar behind.
    $emptyBuild = "$base/empty-build";
    mkdir($emptyBuild, 0777, true);
    $missing = "$emptyBuild/Cav7-Missing-v9.9.9.zip";
    [$code, $out] = runTool($tool, $missing, $base);
    check('checksumming an artifact that does not exist exits non-zero', $code !== 0, $out);
    check('no sidecar is written for an artifact that does not exist', !is_file("$missing.sha256"), $out);
} finally {
    rmrf($base);
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
