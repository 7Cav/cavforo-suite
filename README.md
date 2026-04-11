# DotTokenFix

A XenForo addon that extends [ElasticSearch Essentials](https://xenforo.com/community/resources/elasticsearch-essentials.5210/) to correctly tokenize dot-separated usernames (e.g. `Molitor.K`) in search.

## Problem

Without this addon, usernames like `Molitor.K` are indexed as a single token `molitor.k`. Searching for `molitor` returns no results.

## Solution

Injects a `pattern_replace` char filter into the ElasticSearch analyzer that splits dots between alphanumeric characters into spaces before tokenization. `Molitor.K` becomes `Molitor K`, producing tokens `molitor` and `k` — so searching for `molitor`, `k`, or `molitor.k` all match.

## Requirements

- XenForo Enhanced Search (XFES) 2.1.0+
- ElasticSearch Essentials 1.0.0+

## Installation

1. Upload the `Cav7/DotTokenFix` directory to your XenForo `src/addons/` folder.
2. Install the addon via the XenForo Admin CP.
3. Rebuild the search index.

## License

MIT
