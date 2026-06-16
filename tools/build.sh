#!/usr/bin/env bash
#
# build.sh — build one addon's release zip the canonical way, through
# XenForo's own xf-addon:build-release. This is the local build step, and it
# needs a working XenForo install: it exports _data/ from the database first,
# then packages the zip.
#
# Point it at your install with one of:
#   XF_ROOT=/path/to/xenforo                              tools/build.sh SteamChecker
#   XF_CMD="docker exec xenforo-staging-fpm php cmd.php"  tools/build.sh SteamChecker
#
# Your repo's src/addons/Cav7 must be reachable by that install (the symlink
# setup in CONTRIBUTING.md), so the export writes back into this repo and the
# zip lands in the addon's _releases/ directory.
#
# To package a zip without a XenForo install (CI, or a quick build from already
# committed data), use tools/package-addon.sh instead.

set -euo pipefail

usage() {
  cat >&2 <<'EOF'
usage: tools/build.sh <AddonId> [extra xf-addon:build-release args]

  <AddonId>   addon id under src/addons/Cav7 (e.g. SteamChecker or Cav7/SteamChecker)

environment (one is required):
  XF_ROOT   path to a XenForo install; the CLI is "$XF_ROOT/cmd.php"
  XF_CMD    full command that runs the XenForo CLI, e.g.
            "docker exec xenforo-staging-fpm php cmd.php"
EOF
}

[ $# -ge 1 ] || { usage; exit 2; }
addon_id="${1#Cav7/}"
shift

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ ! -d "$repo_root/src/addons/Cav7/$addon_id" ]; then
  echo "error: no addon at src/addons/Cav7/$addon_id" >&2
  exit 1
fi

if [ -n "${XF_CMD:-}" ]; then
  read -r -a xf_cmd <<< "$XF_CMD"
elif [ -n "${XF_ROOT:-}" ]; then
  xf_cmd=(php "$XF_ROOT/cmd.php")
else
  echo "error: set XF_ROOT or XF_CMD to point at your XenForo install" >&2
  echo >&2
  usage
  exit 1
fi

echo "Building Cav7/$addon_id with: ${xf_cmd[*]} xf-addon:build-release"
exec "${xf_cmd[@]}" xf-addon:build-release "Cav7/$addon_id" "$@"
