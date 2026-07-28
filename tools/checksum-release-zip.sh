#!/usr/bin/env bash
#
# checksum-release-zip.sh — write the `.sha256` sidecar that gets published
# beside a release zip. The release workflow calls this after building the zip;
# both files are then uploaded to the GitHub release.
#
# The entry names the artifact file alone, never the path this script was handed
# it by. `sha256sum` echoes back whatever path it is given, so checksumming an
# absolute path bakes the building machine's directory layout into a file whose
# only job is to be checked on somebody else's machine — where that directory
# does not exist and `sha256sum -c` fails on "No such file or directory" without
# ever comparing a digest.
#
# The artifact is located by absolute path and the sidecar is written beside it,
# so the caller's working directory does not matter.
#
# usage:
#   tools/checksum-release-zip.sh <file>
#
# Needs `sha256sum`, which is also what verifies the result, so a host without
# it could not check a published release either.

set -euo pipefail

[ $# -eq 1 ] || { echo "usage: tools/checksum-release-zip.sh <file>" >&2; exit 2; }

artifact="$1"
[ -f "$artifact" ] || { echo "error: no file at $artifact" >&2; exit 1; }

dir="$(cd "$(dirname "$artifact")" && pwd)"
base="$(basename "$artifact")"

# Run from the artifact's own directory and name it relatively, so the entry
# carries the bare filename a downloader will have it under.
( cd "$dir" && sha256sum "$base" > "$base.sha256" )
