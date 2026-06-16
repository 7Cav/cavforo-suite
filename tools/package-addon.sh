#!/usr/bin/env bash
#
# package-addon.sh — build an addon's release zip from committed files, with no
# XenForo install. It reproduces the upload/ layout that xf-addon:build-release
# emits, so the zip installs through the admin panel's "Install/upgrade from
# archive". CI's build check and the release workflow both use this.
#
# It archives committed content only (from a git ref), so _data/ must already be
# exported and committed. Dev-only paths (_output/, tests/, docs/, ...) are left
# out of the zip.
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

repo_root="$(git rev-parse --show-toplevel)"
sub="src/addons/Cav7/$addon_id"
if ! git -C "$repo_root" cat-file -e "$ref:$sub/addon.json" 2>/dev/null; then
  echo "error: no committed $sub/addon.json at ref '$ref'" >&2
  exit 1
fi

# Paths inside the addon that never ship in a release.
excludes=( _output tests docs CONTEXT.md .out-of-scope .gitattributes )

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
for e in "${excludes[@]}"; do
  rm -rf "${tmp:?}/$prefix$e"
done

rm -f "$out"
( cd "$tmp" && zip -rqX "$out" upload )
echo "$out"
