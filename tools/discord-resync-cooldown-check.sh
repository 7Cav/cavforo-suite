#!/usr/bin/env bash
#
# discord-resync-cooldown-check.sh — run the issue #167 cooldown check against a
# local XenForo dev stack.
#
# Not part of CI and not run by tools/run-tests.sh: it needs a live XenForo with
# NF/Discord and Cav7/DiscordSyncPatch installed, and CI runs neither. See
# CONTRIBUTING.md on getting a stack.
#
# The script is fed to the container over stdin rather than copied in, so
# nothing lands in the bind-mounted webroot even for the length of a run.
#
#   tools/discord-resync-cooldown-check.sh
#   XENFORO_DEV_STACK=~/somewhere/else tools/discord-resync-cooldown-check.sh
#
set -euo pipefail

stack="${XENFORO_DEV_STACK:-$HOME/srv/xenforo-dev}"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="$repo_root/tools/discord-resync-cooldown-check.php"

[ -f "$stack/docker-compose.yml" ] || {
  echo "error: no docker-compose.yml under $stack" >&2
  echo "set XENFORO_DEV_STACK to your stack, or see CONTRIBUTING.md" >&2
  exit 2
}
[ -f "$script" ] || { echo "error: missing $script" >&2; exit 1; }

# The stack's copy of the addon is a copy, not a symlink, so a check run against
# a stale one tests the wrong code. Say so rather than let it pass quietly.
stack_action="$stack/app/src/addons/Cav7/DiscordSyncPatch/XF/Pub/Controller/Account.php"
repo_action="$repo_root/src/addons/Cav7/DiscordSyncPatch/XF/Pub/Controller/Account.php"
if [ ! -f "$stack_action" ] || ! cmp -s "$stack_action" "$repo_action"; then
  echo "error: the stack's Cav7/DiscordSyncPatch does not match this working tree." >&2
  echo "sync it and reimport, then re-run:" >&2
  echo "  rsync -a --delete '$repo_root/src/addons/Cav7/DiscordSyncPatch/' '$stack/app/src/addons/Cav7/DiscordSyncPatch/'" >&2
  echo "  (cd '$stack' && docker compose exec -T fpm php cmd.php xf:addon-rebuild Cav7/DiscordSyncPatch)" >&2
  exit 1
fi

cd "$stack"
docker compose exec -T fpm php < "$script"
