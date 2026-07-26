# Verification: are this suite's template modifications in force?

A recorded manual pass of `cav7-core:check-template-modifications` and its cron
twin, against a live board. This stands in for CI coverage, which the check does
not have and will not get: everything it does needs a live XenForo entity layer
and database — the joins, the template-map resolution, the in-use-style filter.
Only `ShippedModifications` is covered by a test, and only for its refusal
contract.

What that costs the suite, and when this pass has to be re-run, is stated once
in the addon's [README](../../README.md#it-has-almost-no-ci-coverage-on-purpose).
This file is the pass itself.

Run against `~/srv/xenforo-dev` (XenForo 2.3.11) on 2026-07-26.

Two habits this pass is written to, because a hand-written record fails in ways
CI does not: re-derive every number from a run rather than carrying it forward,
and read the restore block against the break steps object by object. A step
written from recollection reads exactly like a captured one.

## Conventions used below

```bash
# DB_ROOT_PW is the dev stack's local root password; read it from the stack's
# docker-compose.yml rather than writing it down here.
Q() { docker exec xenforo-staging-db mariadb -uroot -p"$DB_ROOT_PW" xenforo -N -B -e "$1"; }
CHECK() { docker exec xenforo-staging-fpm php cmd.php cav7-core:check-template-modifications; echo "exit=$?"; }
```

Importing an add-on's data is XenForo's own `xf:addon-rebuild <AddOnId>`
(aliased `xf-addon:rebuild`), which is also the remedy the check prints for two
of its shapes. Installing is `xf:addon-install`, aliased `xf-addon:install` as
`CONTRIBUTING.md` names it.

One helper was written into `app/cav7-verify/` on the stack for the run and
removed afterwards; it is reproduced at the end of this file.

- `edit-copy.php <template_id> break|restore` — edits one template copy and
  saves it **through the entity**, so XenForo re-applies the modifications and
  rewrites its application results exactly as an ACP style edit would. Editing
  `xf_template` with SQL does not do this, and a pass built on SQL edits would
  be reading stale results.

## Starting state

`Cav7/Core` had never been released or had its data imported, so the cron entry
did not exist on the board. Installed with:

```bash
docker exec xenforo-staging-fpm php cmd.php xf-addon:sync-json Cav7/Core
docker exec xenforo-staging-fpm php cmd.php xf:addon-rebuild Cav7/Core
Q "SELECT version_id, version_string FROM xf_addon WHERE addon_id='Cav7/Core';"
Q "SELECT entry_id, cron_class, cron_method, active FROM xf_cron_entry WHERE entry_id='cav7CoreTemplateModCheck';"
Q "SELECT phrase_text FROM xf_phrase WHERE title='cron_entry.cav7CoreTemplateModCheck';"
```

```
1000070  1.0.0
cav7CoreTemplateModCheck  Cav7\Core\Cron\CheckTemplateModifications  run  1
Check this suite's template modifications are in force
```

The version stamp has to be the one `addon.json` ships, not merely some 1.0.0.
Nothing hashes `_data`, so a board already carrying an older stamp imports its
files and none of its data — the very shape this check reports as *shipped but
not installed*.

## The board's own live failure

Before anything was broken deliberately, the check found the failure the issue
was filed about:

```
1 not in force, of 11 modification(s) shipped by this suite, across 12 template copy(ies):

  shipped but not installed — cav7DiscordSyncPatchResyncButton (Cav7/DiscordSyncPatch),
  public:connected_account_associated_nfDiscord, costing 1 copy(ies): copy 2089 (master),
  rendered for 7th Cavalry Gaming (3) — the add-on ships this in its _data and the board holds
  no record of it, so its data was never imported. [...]
exit=1
```

✅ **shipped but not installed.** `Cav7/DiscordSyncPatch` was active at the exact
`version_id` the repo ships, its files were on disk, and the feature merged in
#184 was absent from every compiled style copy. The check names it; nothing else
on the board does.

The remedy the failure line names is a real command, and it clears:

```bash
docker exec xenforo-staging-fpm php cmd.php xf:addon-rebuild Cav7/DiscordSyncPatch
```

```
Importing... Add-on data (Phrases)
Importing... Add-on data (Template modifications)
```

```
All in force: 11 modification(s) shipped by this suite, across 12 template copy(ies).
exit=0
```

✅ **All in force exits 0 and reports what it checked.**

The copy count does **not** move across this remedy. `BoardFacts` enumerates a
copy for every *shipped* modification, taking the target from the record where
there is one and from the shipped row where there is not, so an uninstalled
modification still contributes its copies — copy 2089 is named in the failure
line above, while it is still uninstalled. Re-checked by deleting the record
again:

```bash
Q "DELETE FROM xf_template_modification WHERE modification_key='cav7DiscordSyncPatchResyncButton';"
CHECK
```

```
1 not in force, of 11 modification(s) shipped by this suite, across 12 template copy(ies):
```

✅ **The counts are the same either side of installing it**, which is what
"reconciliation, not scan" means at the level of the totals: what the run says
it checked is fixed by what the addons ship, not by what the board happens to
hold.

## Each failure shape

### matched nothing, in a style's own copy

Style 3 (`7th Cavalry Gaming`, the board default) has its own copy of
`nf_rosters_user_view`. Edited so the find stops matching, the way a style edit
would:

```bash
docker exec xenforo-staging-fpm php cav7-verify/edit-copy.php 2153 break
```

```
matched nothing — cav7RosterPatchAwardDateUtc (Cav7/RosterPatch), public:nf_rosters_user_view,
copy 2153 (style 3), rendered for 7th Cavalry Gaming (3) — the find matched nothing in this
style's own copy, so somebody edited it away from what the find expects. [...]
exit=1
```

✅ **Editing an in-use style's copy makes the command exit 1 and name the
modification, template and copy**, and ✅ **the line says which styles resolve to
that copy**. This is the #106 shape.

### matched nothing, in the master copy

Same edit to copy 2144, the master:

```
matched nothing — [...] copy 2144 (master), rendered for no in-use style, and inherited by any
style created without its own copy — the find matched nothing in the master copy, so the
vendor's own markup has moved: every style inheriting this copy is unpatched [...]
```

✅ **The master copy is checked even when no in-use style resolves to it**, and
the diagnosis differs from the style case: master mismatch means the vendor
moved, style mismatch means somebody edited. Both were reported at once, on
separate lines, when both copies were broken.

### no result recorded, against a copy that exists

```bash
Q "DELETE FROM xf_template_modification_log WHERE modification_id=(SELECT modification_id FROM xf_template_modification WHERE modification_key='cav7_api_key_account_wrapper');"
```

```
matched nothing — cav7_api_key_account_wrapper (Cav7/ApiKeyManager), public:account_wrapper,
copy 600 (master), rendered for 7th Cavalry Gaming (3) — XenForo has recorded no result against
this copy, so it has not been compiled since the modification was installed [...]
```

Running the command that line names clears it:

```bash
docker exec xenforo-staging-fpm php cmd.php xf:addon-rebuild Cav7/ApiKeyManager
```

```
Importing... Add-on data (Template modifications)
All in force: 11 modification(s) shipped by this suite, across 12 template copy(ies).
exit=0
```

✅ **A modification with no recorded result against a copy that exists is
reported, rather than passing by absence**, and ✅ **the remedy each failure
line prints is a real command that fixes the state it describes** — checked for
both shapes that name one. This is the case a reader of the
existing rows structurally cannot see, and it carries the `matched nothing`
shape with a reason that names the different remedy — rebuild the templates,
rather than fix the find.

### installed but disabled, and owning add-on inactive

```bash
Q "UPDATE xf_template_modification SET enabled=0 WHERE modification_key='cav7MilpacProfile';"
Q "UPDATE xf_addon SET active=0 WHERE addon_id='Cav7/RosterPatch';"
```

```
installed but disabled — cav7MilpacProfile (Cav7/MilpacTooltip), public:member_view,
costing 2 copy(ies): copy 885 (master), rendered for no in-use style, and inherited by any style
created without its own copy; copy 2507 (style 3), rendered for 7th Cavalry Gaming (3) — [...]

owning add-on inactive — cav7RosterPatchAwardDateUtc (Cav7/RosterPatch), public:nf_rosters_user_view,
costing 2 copy(ies): copy 2144 (master), [...]; copy 2153 (style 3), rendered for
7th Cavalry Gaming (3) — [...]
owning add-on inactive — cav7RosterPatchRecordDateUtc (Cav7/RosterPatch), [...]
```

✅ **A disabled modification, and one whose add-on is inactive, are each
reported.** Neither singles out a copy, because XenForo attempted nothing and
there is no result for one to be missing from — that is exactly why they are
invisible on the board. Both still name the copies they cost, so "one inert
style" and "every member on the board" are not the same line. The inactive
add-on produces one line per modification it owns, which is what makes it read
as the single fault it is.

### target template does not exist

A vendor rename moves the template rows and the map that resolves them together:

```bash
Q "UPDATE xf_template     SET title='member_tooltip_gone' WHERE type='public' AND title='member_tooltip';"
Q "UPDATE xf_template_map SET title='member_tooltip_gone' WHERE type='public' AND title='member_tooltip';"
```

```
target template does not exist — cav7MilpacTooltipLink (Cav7/MilpacTooltip), public:member_tooltip
— no copy of this template exists on the board [...]
```

✅ **A modification whose target template does not exist is reported.**

### recorded a non-ok status

Seeded directly:

```bash
Q "UPDATE xf_template_modification_log SET status='error_compile' WHERE modification_id=(SELECT modification_id FROM xf_template_modification WHERE modification_key='cav7EnlistDefRankValue') AND template_id=2137;"
```

```
recorded a non-ok status — cav7EnlistDefRankValue (Cav7/EnlistmentDefaults), public:nf_rosters_user_add,
copy 2137 (master) [...] — XenForo recorded the status "error_compile" against this copy [...]
```

✅ **A non-ok status is reported, not only the zero-match case.**

Seeded rather than provoked, and the issue anticipated that this shape would be
awkward. Worth recording why the natural route failed: setting a modification's
find to an uncompilable pattern (`#[#`) and recompiling the copy made XenForo
record **`ok` with `apply_count` 0**, not an error status. So on 2.3.11 a find
that cannot compile at all presents as `matched nothing` rather than as an
error. The check reports it either way; the diagnosis it prints would send the
reader to look for moved markup rather than a broken pattern. Not worth
designing around on the evidence of one probe, but it is what was observed.

## Exclusions

### Modifications outside this suite are ignored

```bash
Q "SELECT count(*) FROM xf_template_modification_log l JOIN xf_template_modification m ON m.modification_id=l.modification_id WHERE m.addon_id NOT LIKE 'Cav7/%' AND l.status='ok' AND l.apply_count=0;"
```

```
21
```

✅ **21 third-party modifications on this board are recorded as matching
nothing, and not one appears in any run above.** The exclusion does real work
rather than guarding a hypothetical.

### A copy no in-use style resolves to is not reported

All three copies of `nf_rosters_user_view`, and the styles owning them:

```
2144  0  (master)
2503  1  Default style        user_selectable=0
2153  3  7th Cavalry Gaming   user_selectable=1   <- board default
```

✅ **Copy 2503 is never named in any run above, while 2144 and 2153 both are.**
Style 1 is neither the board default nor selectable, so its copy renders for
nobody. Making it selectable would pull it into the check on the next run with
no code change, because the in-use set is computed at run time.

## Discovery failure exits 2

```bash
# truncate an add-on's shipped file mid-tag
printf '<?xml version="1.0" encoding="utf-8"?>\n<template_modifications><modification template="nf_rosters_roster_index">\n' \
  > app/src/addons/Cav7/RosterSearch/_data/template_modifications.xml
```

```
The check could not be performed: Cav7/RosterSearch ships a template_modifications.xml that will
not parse, so what it ships cannot be established: /var/www/html/src/addons/Cav7/RosterSearch/
_data/template_modifications.xml (Premature end of data in tag modification line 2)
exit=2
```

An installed add-on whose files are not on the board is the second way
discovery fails — it ships whatever was last uploaded and this run cannot read
it, which is indistinguishable from shipping nothing:

```bash
Q "INSERT INTO xf_addon (addon_id, title, version_string, version_id, json_hash, active, is_processing, is_legacy) VALUES ('Cav7/Ghost','7Cav - Ghost','1.0.0',1000070,'',1,0,0);"
```

```
The check could not be performed: Cav7/Ghost is installed and its files are missing from this
board (addon.json), so what it ships cannot be established
exit=2
```

✅ **An add-on whose file cannot be parsed exits 2; an add-on with no
modifications does not** — eight of the installed add-ons ship no such file and
every run above exited 0 or 1.

## The scheduled run

With three failures standing, the error log was emptied, the cron entry's class
and method were called exactly as XenForo's runner calls them, and the ACP
dashboard's own condition was read before and after:

```
dashboard notice before: false
entry: cav7CoreTemplateModCheck active=1 Cav7\Core\Cron\CheckTemplateModifications::run
ran
dashboard notice after: true

Cav7/Core: template modification not in force — matched nothing — cav7_api_key_account_wrapper [...]
Cav7/Core: template modification not in force — recorded a non-ok status — cav7EnlistDefRankValue [...]
Cav7/Core: template modification not in force — matched nothing — cav7MilpacProfile [...]
```

✅ **The scheduled run writes failures to the error log and the admin dashboard
raises its notice.** `hasErrorsInLog()` is the condition
`XF\Admin\Controller\IndexController` reads to raise "server errors have been
logged", and it flipped false → true on the cron's own entries alone.

One entry per failure, on every run, with no state tracking to suppress a
repeat.

## The record's owner, not the shipping add-on

`owning add-on inactive` is decided from the add-on the **modification record**
names, read by joining `xf_template_modification` to `xf_addon`, rather than
from the add-on whose files were read. XenForo skips a modification on the
owner its record names, so reading the shipping add-on would call one in force
that the board never applies. The two agree on any board where the data was
imported normally, which is why the run above — deactivating `Cav7/RosterPatch`
and seeing both of its modifications reported — exercises the join without
being able to tell the two readings apart. A board where they diverged is not
reachable through any supported path.

## Restoring the board

One line per break step above, in the order they appear:

```bash
# matched nothing, style copy and master copy
docker exec xenforo-staging-fpm php cav7-verify/edit-copy.php 2153 restore
docker exec xenforo-staging-fpm php cav7-verify/edit-copy.php 2144 restore
# no result recorded — cleared inline above by the remedy that line printed, nothing to undo here
# installed but disabled, and owning add-on inactive
Q "UPDATE xf_template_modification SET enabled=1 WHERE modification_key='cav7MilpacProfile';"
Q "UPDATE xf_addon SET active=1 WHERE addon_id='Cav7/RosterPatch';"
# target template does not exist
Q "UPDATE xf_template     SET title='member_tooltip' WHERE type='public' AND title='member_tooltip_gone';"
Q "UPDATE xf_template_map SET title='member_tooltip' WHERE type='public' AND title='member_tooltip_gone';"
# recorded a non-ok status, and the uncompilable-find probe beside it
Q "UPDATE xf_template_modification_log SET status='ok' WHERE status='error_compile';"
docker exec xenforo-staging-fpm php cmd.php xf:addon-rebuild Cav7/EnlistmentDefaults
# discovery failures
git -C <repo> checkout -- src/addons/Cav7/RosterSearch/_data/template_modifications.xml   # then copy to the stack
Q "DELETE FROM xf_addon WHERE addon_id='Cav7/Ghost';"
```

A deleted application-result row is restored by re-saving the copy, not by
re-inserting it: `break` then `restore` on the copy makes XenForo write it
again. A find edited by hand is restored by rebuilding its owning add-on, which
re-imports the shipped pattern over the edited one.

Final state:

```
All in force: 11 modification(s) shipped by this suite, across 12 template copy(ies).
exit=0
```

Note that the stack now carries `cav7DiscordSyncPatchResyncButton` installed,
where before this pass it did not. That is the remedy having been applied, not
drift.

Two things were left changed on purpose: the error log was reloaded from a
`LOAD DATA` round trip of its 101 rows rather than a dump, so exact byte
fidelity of its `varbinary` columns is not guaranteed; and `Cav7/Core` is now
installed at `1000070` / 1.0.0 with its data imported.

Re-checked at the end, so the statements above are the board's state rather
than a recollection of it:

```bash
CHECK
Q "SELECT addon_id FROM xf_addon WHERE addon_id='Cav7/Ghost';"
Q "SELECT COUNT(*) FROM xf_template_modification_log WHERE status<>'ok';"
Q "SELECT COUNT(*) FROM xf_template_modification m JOIN xf_addon a USING(addon_id)
    WHERE a.addon_id LIKE 'Cav7/%' AND (m.enabled=0 OR a.active=0);"
```

```
All in force: 11 modification(s) shipped by this suite, across 12 template copy(ies).
exit=0
(no rows)
0
0
```


## The helpers

```php
// edit-copy.php — edits one template copy and saves it through the entity, so
// XenForo re-applies the modifications and rewrites its application results
// exactly as a style edit in the ACP would.
//   edit-copy.php <template_id> break|restore
$dir = dirname(__DIR__);
require $dir . '/src/XF.php';
XF::start($dir);
$app = XF::setupApp(XF\Cli\App::class);
$app->start(true);

$templateId = (int) ($argv[1] ?? 0);
$mode = $argv[2] ?? 'break';
$marker = "\n<!-- cav7-171-verification -->\n";

$t = $app->em()->find(XF\Entity\Template::class, $templateId);
if (!$t) { fwrite(STDERR, "no template $templateId\n"); exit(1); }

if ($mode === 'break') {
    file_put_contents("/tmp/cav7-171-$templateId.orig", $t->template);
    // A plausible style edit: the date cell still renders, through a property
    // the find does not name. This is the #106 shape.
    $t->template = $marker . str_replace('$award.award_date', '$award.awardDate', $t->template);
} else {
    $t->template = file_get_contents("/tmp/cav7-171-$templateId.orig");
}
$t->save();
```

