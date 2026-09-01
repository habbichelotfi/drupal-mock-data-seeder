#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

find "$ROOT_DIR/src" "$ROOT_DIR/tests" -type f -name "*.php" -print0 | while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  echo "OK: $file"
done

echo "Smoke test passed."

