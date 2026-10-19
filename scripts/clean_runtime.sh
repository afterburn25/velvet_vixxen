#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
if [[ ! -f "$ROOT/.vvx-test-runtime" || "${VELVET_TEST_RUNTIME:-}" != "$ROOT" ]]; then
  echo "Refusing to clean an application outside a marked disposable test copy." >&2
  exit 1
fi

cp config/app.example.php config/app.php
rm -f storage/data.json storage/install.lock

for directory in storage/logs storage/cache storage/sessions storage/temporary storage/temp storage/updates storage/private storage/backups; do
  [ -d "$directory" ] || continue
  find "$directory" -mindepth 1 -type f ! -name '.gitkeep' ! -name '.htaccess' -delete
  find "$directory" -mindepth 1 -type d -empty -delete 2>/dev/null || true
done

for directory in addons/installed addons/staging; do
  [ -d "$directory" ] || continue
  find "$directory" -mindepth 1 -type f ! -name '.gitkeep' ! -name '.htaccess' -delete
  find "$directory" -mindepth 1 -type d -empty -delete 2>/dev/null || true
done

echo "Runtime installation state and installed add-on files removed; config/app.php restored to the uninstalled template."
