#!/usr/bin/env bash
#
# package-addon.sh — build an addon's release zip from committed files, with no
# XenForo install. It reproduces the upload/ layout that xf-addon:build-release
# emits, so the zip installs through the admin panel's "Install/upgrade from
# archive". CI's build check and the release workflow both use this.
#
# This is the release build: the only zip a board should be installed from.
#
# It archives committed content only (from a git ref), so _data/ must already be
# exported and committed. Dev-only paths (_output/, tests/, docs/, ...) are left
# out of the zip, and tools/tests/package-addon-test.php asserts that for every
# addon. That test names the dev-only set itself rather than reading the array
# below, so edit both: one is the implementation, the other the specification.
#
# usage:
#   tools/package-addon.sh <AddonId> [--ref <git-ref>] [--out <file.zip>]
#
# With no --out, the file is named Cav7-<AddonId>-<version_string>.zip in the
# current directory, and its path is printed on stdout.

set -euo pipefail

addon_id=""
ref="HEAD"
out=""
while [ $# -gt 0 ]; do
  case "$1" in
    --ref) ref="$2"; shift 2 ;;
    --out) out="$2"; shift 2 ;;
    -h|--help) sed -n '2,/^set /p' "$0" | sed 's/^#\{0,1\} \{0,1\}//; /^set /d'; exit 0 ;;
    -*) echo "unknown option: $1" >&2; exit 2 ;;
    *) addon_id="${1#Cav7/}"; shift ;;
  esac
done
[ -n "$addon_id" ] || { echo "usage: tools/package-addon.sh <AddonId> [--ref REF] [--out FILE]" >&2; exit 2; }

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(git rev-parse --show-toplevel)"
sub="src/addons/Cav7/$addon_id"
if ! git -C "$repo_root" cat-file -e "$ref:$sub/addon.json" 2>/dev/null; then
  echo "error: no committed $sub/addon.json at ref '$ref'" >&2
  exit 1
fi

# Paths inside the addon that never ship inside upload/src/addons/... . XenForo's
# ReleaseBuilderService excludes _files/ and build.json there too: _files/ web
# assets are copied to the upload/ web root instead (see the web-asset step
# below), and build.json is a build input, not a shipped file.
excludes=( _output _files build.json tests docs CONTEXT.md .out-of-scope .gitattributes )

# version_string from the committed addon.json (well-formed, one key per line).
ver="$(git -C "$repo_root" show "$ref:$sub/addon.json" \
  | sed -n 's/.*"version_string"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"
[ -n "$ver" ] || { echo "error: could not read version_string from $sub/addon.json" >&2; exit 1; }

out="${out:-Cav7-$addon_id-$ver.zip}"
case "$out" in /*) : ;; *) out="$PWD/$out" ;; esac

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

prefix="upload/$sub/"
git -C "$repo_root" archive --format=tar --prefix="$prefix" "$ref:$sub" | tar -x -C "$tmp"

# Reproduce XenForo's build.json web-asset handling: copy the addon's declared
# _files/ web assets to the upload/ web root and write the .min.js the minify key
# names, then check every <xf:js src> the addon owns resolves there. Reads
# _files/ and build.json from the extracted tree, so it must run before the
# excludes below strip them. Copies nothing for an addon with no build.json, but
# the <xf:js> resolution check still runs and can fail the build for an addon
# that owns an <xf:js> even without one.
php "$script_dir/package-web-assets.php" "$tmp/$prefix" "$tmp/upload" "Cav7/$addon_id"

for e in "${excludes[@]}"; do
  rm -rf "${tmp:?}/$prefix$e"
done

rm -f "$out"
( cd "$tmp" && zip -rqX "$out" upload )
echo "$out"
