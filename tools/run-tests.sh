#!/usr/bin/env bash
#
# run-tests.sh — run one addon's standalone PHP tests (tests/*.php), with no
# XenForo and no test framework. Each test is a self-contained script that exits
# non-zero on failure. Addons without a tests/ directory are a no-op. CI and
# local runs share this script.
#
#   tools/run-tests.sh SteamChecker
#
set -uo pipefail

addon_id="${1:-}"
[ -n "$addon_id" ] || { echo "usage: tools/run-tests.sh <AddonId>" >&2; exit 2; }
addon_id="${addon_id#Cav7/}"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dir="$repo_root/src/addons/Cav7/$addon_id"
[ -d "$dir" ] || { echo "error: no addon at src/addons/Cav7/$addon_id" >&2; exit 1; }

if [ ! -d "$dir/tests" ]; then
  echo "no tests/ for $addon_id, nothing to run"
  exit 0
fi

cd "$dir" || exit 1
shopt -s nullglob
tests=(tests/*.php)
if (( ${#tests[@]} == 0 )); then
  echo "error: $addon_id has a tests/ directory but no *.php files in it" >&2
  exit 1
fi

failed=()
for t in "${tests[@]}"; do
  echo "=== $t"
  if php "$t"; then
    echo "PASS: $t"
  else
    echo "FAIL: $t (exit $?)"
    failed+=("$t")
  fi
done

if (( ${#failed[@]} > 0 )); then
  echo "FAILED: ${failed[*]}"
  exit 1
fi
echo "All tests passed for $addon_id."
