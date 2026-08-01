# DotTokenFix

A XenForo addon that extends [ElasticSearch Essentials](https://xenforo.com/community/resources/elasticsearch-essentials.5210/) to correctly tokenize dot-separated usernames (e.g. `Molitor.K`) in search.

## Problem

Without this addon, usernames like `Molitor.K` are indexed as a single token `molitor.k`. Searching for `molitor` returns no results.

## Solution

Injects a `pattern_replace` char filter into the ElasticSearch analyzer that splits dots between alphanumeric characters into spaces before tokenization. `Molitor.K` becomes `Molitor K`, producing tokens `molitor` and `k`, so searching for `molitor`, `k`, or `molitor.k` all match.

## Requirements

- XenForo 2.3.0+
- XenForo Enhanced Search (XFES), any version
- ElasticSearch Essentials, any version

Both add-on entries carry `*` as their floor, so they gate that the add-on is
installed and pin no version. How a floor has to be written, and why one in the
wrong numbering gates nothing, is in
[`docs/addon-format.md`](../../../../docs/addon-format.md). The short of it here:
XFES ships inside the XenForo package and tracks core's version, so the XenForo
floor above already constrains it, and no ElasticSearch Essentials version is
one this addon needs.

Vendor-coupled behaviour is verified by hand on a dev stack rather than in CI.
Re-check it after a XenForo upgrade, and whenever XFES or ElasticSearch Essentials
moves.

## Installation

1. Upload the `Cav7/DotTokenFix` directory to your XenForo `src/addons/` folder.
2. Install the addon via the XenForo Admin CP.
3. Rebuild the search index.

## License

MIT, see [LICENSE](LICENSE).

## Provenance

Imported from https://github.com/7Cav/DotTokenFix at commit e4b52797dce9ee704e9aff33149c30dea69385dd.
