#!/usr/bin/env bash
set -euo pipefail
SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$SOURCE_ROOT"
for executable in php node python; do
  command -v "$executable" >/dev/null || { echo "Required test runtime missing: $executable" >&2; exit 1; }
done
for required in app/bootstrap.php install/index.php public/router.php scripts/create_demo.php tests/smoke.php; do
  test -s "$required" || { echo "Required application source missing: $required" >&2; exit 1; }
done
# Scan the actual checkout before creating test runtime state.
php tests/Security/repository_safety.php
python tests/Installer/packaged_preflight.py
python tests/Installer/packaged_opcache.py
WORK="$(python tests/copy_runtime.py)"
cleanup() { python "$SOURCE_ROOT/tests/copy_runtime.py" --remove "$WORK"; }
trap cleanup EXIT
export VELVET_TEST_RUNTIME="$WORK"
cd "$WORK"
echo "== PHP syntax =="
while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find . -type f -name '*.php' -not -path './vendor/*' -print0)
echo "== JavaScript syntax =="
while IFS= read -r -d '' file; do node --check "$file" >/dev/null; done < <(find public -type f -name '*.js' -print0)
node tests/Unit/api_client.js
echo "== Python syntax =="
python - <<'PYCODE'
import ast
from pathlib import Path
for path in sorted(Path('tests').rglob('*.py')):
    ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
PYCODE
echo "== Application tests in a disposable copy =="
php tests/smoke.php
for test in \
  tests/Installer/foundation.php tests/Installer/single_page.php tests/Installer/recovery.php \
  tests/Unit/hero_library.php tests/Unit/installation_urls.php tests/Feature/runtime_build.php tests/Feature/root_layout.php \
  tests/Unit/migrations.php tests/Unit/addon_manifest.php tests/Unit/addon_package.php \
  tests/Unit/media_access.php tests/Unit/media_store.php tests/Unit/member_auth.php \
  tests/Unit/entitlement_resolver.php tests/Unit/commerce.php tests/Unit/merchandise.php tests/Unit/addon_licensing.php \
  tests/Feature/media_center.php tests/Feature/members_entitlements.php tests/Feature/commerce.php \
  tests/Feature/addon_framework.php tests/Feature/communications.php tests/Feature/marketing.php \
  tests/Feature/repair_backups.php tests/Feature/database_updates.php tests/Feature/site_ux.php tests/Feature/booking_form.php tests/Feature/scene_buttons.php tests/Security/protected_media.php tests/Security/member_security.php \
  tests/Security/payment_webhook.php tests/Security/addon_security.php tests/Structure/organized.php tests/Structure/public_routes.php; do
  php "$test"
done
php tests/Security/repository_safety.php
python tests/Packaging/runtime_packages.py
echo "All non-database tests passed."
