#!/usr/bin/env bash
#
# run-tools-tests.sh — run the repo-level tool tests (tools/tests/*.php), with no
# XenForo and no test framework. Each test is a self-contained script that exits
# non-zero on failure and pins one of the tools/ scripts (package-web-assets.php,
# check-data-consistency.php, ...). New tools/tests/*.php are picked up
# automatically — nothing here is hardcoded. CI and local runs share this script.
#
#   tools/run-tools-tests.sh
#
set -uo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$repo_root/tools" || exit 1
shopt -s nullglob
tests=(tests/*.php)
if (( ${#tests[@]} == 0 )); then
  echo "error: no *.php files in tools/tests" >&2
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
echo "All tools tests passed."
