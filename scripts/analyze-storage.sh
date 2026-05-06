#!/usr/bin/env bash
set -euo pipefail

storage_dir="${1:-storage}"

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required to analyze storage JSON files." >&2
  exit 1
fi

if ! command -v rg >/dev/null 2>&1; then
  echo "ripgrep (rg) is required to analyze log types." >&2
  exit 1
fi

echo "Weekly files"
for file in "$storage_dir"/*.json; do
  [ -f "$file" ] || continue
  jq -r '"\(.week) uploads=\(.upload_count) actual=\(.uploads|length) updated=\(.updated_at)"' "$file"
done

echo
echo "Bridge IDs"
jq -r '.uploads[].payload.bridgeId' "$storage_dir"/*.json | sort -n | uniq -c

echo
echo "Log types"
jq -r '.uploads[].payload.logText' "$storage_dir"/*.json \
  | rg -o '\[[A-Z_]+\]' \
  | sort \
  | uniq -c \
  | sort -nr

echo
echo "Storage size"
du -sh "$storage_dir"
