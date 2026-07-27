# Verification: does DotTokenFix's require block gate what it claims?

A recorded manual pass against a live board, standing in for CI coverage this
cannot have. `tools/validate-addon.php` checks manifest shape and never reads
`require`, and CI has no XenForo, so nothing in the pipeline can answer whether
a declared floor refuses anything. Only a board with the vendor add-ons
installed can, because the comparison XenForo makes is against the installed
`version_id`, which CI does not have.

The vocabulary below — _require entry_, _version floor_ — is the root
[`CONTEXT.md`](../../../../../../CONTEXT.md) glossary's. How to write a floor is
[`docs/addon-format.md`](../../../../../../docs/addon-format.md).

Run against `~/srv/xenforo-dev` (XenForo 2.3.11) on 2026-07-26, for
[#190](https://github.com/7Cav/cavforo-suite/issues/190).

## What is being checked

`XF\AddOn\Manager::checkAddOnRequirements()` is the method XenForo's installer
calls, and `Manager.php:370-371` is the whole of the comparison:

```php
$enabled      = isset($addOns[$productKey]);
$versionValid = ($version === '*' || ($enabled && $addOns[$productKey] >= $version));
// ... if (!$enabled || !$versionValid) -> error
```

The pass drives that real method rather than reimplementing it — the bug being
fixed lived in exactly this comparison, so a reimplementation would be free to
reproduce the mistake and agree with itself. The helper reads the `require` map
out of the add-on's own `addon.json` on the board, so what is checked is the
shipped artifact rather than a transcription of it.

Assertions are on allowed/refused only, never on the message text. The labels
are prose a maintainer authors; pinning them would report that somebody edited a
string, which `git` already does.

## Conventions used below

```bash
S=~/srv/xenforo-dev/app
# The stack holds a copy of the addon tree, not a symlink to the repo, so the
# manifest under test has to be put there before each run or the board answers
# for whatever was last uploaded.
cp src/addons/Cav7/DotTokenFix/addon.json $S/src/addons/Cav7/DotTokenFix/addon.json
cp require-block-check.php $S/
CHECK() { docker exec xenforo-staging-fpm php /var/www/html/require-block-check.php; echo "exit=$?"; }
```

Each scenario is the board's own installed set with exactly one entry changed,
so nothing else drifts between them. The board is read once and the variations
are made in memory — no scenario writes to the database, which is why this pass
has no break/restore ladder.

## Before: the floors as #190 found them

```
require block under test:
  SV/ElasticSearchEssentials   1000000      ElasticSearch Essentials 1.0.0+
  XFES                         '2.1.0'      XenForo Enhanced Search 2.1.0+

board: XF=2031170 XFES=2031170 ESE=1768911023

PASS as installed on this board           expected ALLOWED  got ALLOWED
FAIL XenForo at 2.2.0                     expected REFUSED  got ALLOWED
FAIL ESE at an arbitrary old version_id   expected ALLOWED  got REFUSED
PASS ESE absent                           expected REFUSED  got REFUSED
PASS XFES absent                          expected REFUSED  got REFUSED

RED — 2 of 5 scenario(s) failed.
exit=1
```

❌ **XenForo 2.2.0 was allowed.** DotTokenFix declared no `XF` entry at all — the
only add-on in the suite that did not — so the platform was unconstrained.

❌ **ESE at `version_id` 1 was refused.** The `1000000` floor was doing something,
but not anything useful. ESE's own upgrade ladder runs `2040000` through
`3120001` in XenForo's `AABBCCDE` scheme and then switches to `1690254714`, a
unix timestamp — installed today is `1768911023`. The declared floor sat below
both halves of that history, so it refused only versions that do not exist. Note
the switch: ESE's current scheme is no guide to its older releases, which is why
a floor has to be read against the ladder rather than against the installed
version alone.

✅ **ESE absent and XFES absent were both already refused**, and stayed refused
after the fix. This is the part of the entry that was never inert, and it is why
the finding is a truthfulness problem rather than a silent failure: the number
gated nothing, the entry still gated presence.

## After: the corrected block

```
require block under test:
  XF                           2030070      XenForo 2.3.0+
  XFES                         '*'          XenForo Enhanced Search
  SV/ElasticSearchEssentials   '*'          ElasticSearch Essentials

board: XF=2031170 XFES=2031170 ESE=1768911023

PASS as installed on this board           expected ALLOWED  got ALLOWED
PASS XenForo at 2.2.0                     expected REFUSED  got REFUSED
PASS ESE at an arbitrary old version_id   expected ALLOWED  got ALLOWED
PASS ESE absent                           expected REFUSED  got REFUSED
PASS XFES absent                          expected REFUSED  got REFUSED

GREEN — all 5 scenarios behave as required.
exit=0
```

✅ **The platform floor now refuses XenForo 2.2.0**, which is the constraint that
was actually wanted and was previously asserted nowhere.

✅ **`*` gives up the version check and keeps the presence check.** Both wildcard
entries admit any installed version and still refuse an absent add-on. This is
the behaviour the whole decision rests on, and it is XenForo's own
(`$version === '*'` short-circuits `$versionValid`, while `$enabled` is tested
separately), not something this add-on arranges.

## What this pass does not cover

- The label text. Deliberate; see above.
- Whether XFES 2.3 is genuinely the API floor DotTokenFix needs. No XFES 2.1 or
  2.2 source exists on the stack to compare against, so no floor on `XFES` could
  be substantiated. The platform floor stands in for it, which is honest because
  XFES ships inside the XenForo package and tracks core's version exactly —
  XFES `2031170`/2.3.11 against XF `2031170`/2.3.11 on this board.
- Runtime behaviour with an old ESE. Not reachable: ESE sets
  `elasticess_near_exact` unconditionally, under its own comment *"always
  generate analyzer configuration, even if it isn't used"* — in
  `SV/ElasticSearchEssentials/XFES/Service/Analyzer.php:107`, the vendor's
  extension, not XenForo's own `XFES/Service/Analyzer.php`, which holds
  unrelated stemmer configuration at that line. So the `isset()` guard in this
  add-on's `Analyzer` fires only when ESE is absent entirely — which the
  presence check already refuses.

## Restoring the stack

Nothing in the database was mutated; the scenarios vary an in-memory copy of the
add-on cache. One break step was taken and undone: to capture the *before*
transcript with the same helper that produced the *after* one, the stack's copy
of `addon.json` was temporarily rewritten to hold the two floors as #190 found
them, run, and then restored from the copy taken beforehand. Only two files were
placed:

```bash
rm -f $S/require-block-check.php
# $S/src/addons/Cav7/DotTokenFix/addon.json is left holding the corrected
# manifest, which is what the stack should mirror from the repo anyway.
```

Re-checked at the end, so the statement above is the stack's state rather than a
recollection of it:

```bash
docker exec xenforo-staging-fpm ls /var/www/html/require-block-check.php
docker exec xenforo-staging-fpm php -r 'print_r(json_decode(file_get_contents("/var/www/html/src/addons/Cav7/DotTokenFix/addon.json"),true)["require"]);'
```

```
ls: /var/www/html/require-block-check.php: No such file or directory
Array
(
    [XF] => Array
        (
            [0] => 2030070
            [1] => XenForo 2.3.0+
        )

    [XFES] => Array
        (
            [0] => *
            [1] => XenForo Enhanced Search
        )

    [SV/ElasticSearchEssentials] => Array
        (
            [0] => *
            [1] => ElasticSearch Essentials
        )

)
```

The first of those two commands reported the file **still present** when it was
run immediately after the `rm`, while the host path was already gone. Docker
Desktop's bind mount serves a stale read for a second or so, so a restore check
run straight after the delete confirms the wrong thing. Re-run it, or leave a
beat, before believing it.

## The helper

```php
// require-block-check.php — drives Cav7/DotTokenFix's real require block through
// XenForo's own checkAddOnRequirements() and asserts allowed/refused per
// scenario. The require map is read from the addon.json on the board, so this
// checks the shipped artifact rather than a copy of it.
$dir = '/var/www/html';
require $dir . '/src/XF.php';

XF::start($dir);
$app = XF::setupApp(XF\Cli\App::class);
$app->start(true);   // setupApp() already calls setup(); calling it again re-fires app_setup

$manifest = json_decode(file_get_contents($dir . '/src/addons/Cav7/DotTokenFix/addon.json'), true);
$require = $manifest['require'];

$manager   = $app->addOnManager();
$container = $app->container();

// The board's own installed set, as XenForo built it. Each scenario is this set
// with one thing changed, so nothing else drifts between scenarios.
$live = $container['addon.cache'];

$without = function (array $cache, string $key): array {
    unset($cache[$key]);
    return $cache;
};

// [label, cache, expected allowed?]
$scenarios = [
    ['as installed on this board',         $live,                                         true],
    ['XenForo at 2.2.0',                   ['XF' => 2020070] + $live,                     false],
    ['ESE at an arbitrary old version_id', ['SV/ElasticSearchEssentials' => 1] + $live,   true],
    ['ESE absent',                         $without($live, 'SV/ElasticSearchEssentials'), false],
    ['XFES absent',                        $without($live, 'XFES'),                       false],
];

echo "require block under test:\n";
foreach ($require as $key => [$version, $label]) {
    printf("  %-28s %-12s %s\n", $key, var_export($version, true), $label);
}
echo "\nboard: XF=" . ($live['XF'] ?? '?')
    . " XFES=" . ($live['XFES'] ?? 'absent')
    . " ESE=" . ($live['SV/ElasticSearchEssentials'] ?? 'absent') . "\n\n";

$failed = 0;
foreach ($scenarios as [$label, $cache, $expectAllowed]) {
    $container->set('addon.cache', $cache);

    $errors = [];
    $allowed = $manager->checkAddOnRequirements($require, '7Cav - Dot Token Fix', $errors);

    $ok = ($allowed === $expectAllowed);
    $failed += $ok ? 0 : 1;

    printf("%-4s %-36s expected %-8s got %s\n",
        $ok ? 'PASS' : 'FAIL', $label,
        $expectAllowed ? 'ALLOWED' : 'REFUSED',
        $allowed ? 'ALLOWED' : 'REFUSED');
}

echo "\n";
if ($failed) {
    echo "RED — $failed of " . count($scenarios) . " scenario(s) failed.\n";
    exit(1);
}
echo "GREEN — all " . count($scenarios) . " scenarios behave as required.\n";
exit(0);
```
