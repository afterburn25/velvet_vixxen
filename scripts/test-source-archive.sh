#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="${RUNNER_TEMP:-/tmp}/velvet-vixxen-source-archive"
rm -rf "$WORK"
mkdir -p "$WORK"
tar -xzf "$ROOT/source/velvet-vixxen-0.3.0-repo-source.tar.gz" -C "$WORK"
cd "$WORK"
if [[ ! -f config/app.php ]]; then
  cp config/app.example.php config/app.php
fi
bash scripts/test.sh
