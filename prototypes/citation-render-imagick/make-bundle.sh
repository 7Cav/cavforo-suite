#!/bin/sh
# PROTOTYPE, throwaway. Packs what probe.sh needs into citation-probe.tgz, fonts included.
#   ./make-bundle.sh fonts-dir
set -eu
here=$(cd "$(dirname "$0")" && pwd)
tmp=$(mktemp -d)
mkdir -p "$tmp/citation-probe/fonts"
cp "$here/probe.sh" "$here/render.php" "$here/grant.php" "$here/plate-1275.png" "$here/ink.png" "$tmp/citation-probe/"
cp "$1"/*-Regular.ttf "$1"/*-Bold.ttf "$tmp/citation-probe/fonts/"
tar -czf "$here/citation-probe.tgz" -C "$tmp" citation-probe
rm -rf "$tmp"
ls -l "$here/citation-probe.tgz"
