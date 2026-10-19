#!/usr/bin/env bash
set -euo pipefail
# Exec the snapshot before doing long-running work. Shared source edits cannot
# change the Bash program while it is reading later suite invocations.
if [[ -z "${VVX_HTTP_COPY:-}" ]]; then
  SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  cd "$SOURCE_ROOT"
  WORK="$(python tests/copy_runtime.py)"
  VVX_HTTP_COPY="$WORK" VVX_HTTP_SOURCE="$SOURCE_ROOT" exec bash "$WORK/scripts/test_http.sh"
fi
SOURCE_ROOT="${VVX_HTTP_SOURCE:?Missing source root}"
WORK="${VVX_HTTP_COPY:?Missing disposable runtime}"
[[ -f "$WORK/.vvx-test-runtime" && "$WORK" == "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" ]] || { echo "Refusing an unmarked or mismatched runtime." >&2; exit 1; }
PORT="${VELVET_TEST_PORT:-8765}"
BASE="http://127.0.0.1:${PORT}"
PID=""
cleanup() {
  if [[ -n "$PID" ]]; then kill "$PID" >/dev/null 2>&1 || true; wait "$PID" >/dev/null 2>&1 || true; fi
  if [[ -n "${VELVET_SCREENSHOT_DIR:-}" ]]; then
    mkdir -p "$VELVET_SCREENSHOT_DIR"
    cp "$WORK/http-server.log" "$VELVET_SCREENSHOT_DIR/http-server.log" 2>/dev/null || true
  fi
  python "$SOURCE_ROOT/tests/copy_runtime.py" --remove "$WORK"
}
trap cleanup EXIT
export VELVET_TEST_RUNTIME="$WORK" VELVET_BASE_URL="$BASE"
cd "$WORK"
php scripts/create_demo.php >/dev/null
php -S "127.0.0.1:${PORT}" -t "$WORK" "$WORK/tests/Http/router.php" >"$WORK/http-server.log" 2>&1 &
PID=$!
ready=false
for attempt in {1..40}; do
  if curl -fsS "$BASE/" >/dev/null 2>&1; then ready=true; break; fi
  sleep 0.25
done
if [[ "$ready" != true ]]; then cat "$WORK/http-server.log" >&2; exit 1; fi
if [[ -n "${VELVET_TEST_SUITE:-}" ]]; then
  suites=("$VELVET_TEST_SUITE")
elif [[ "${VELVET_BROWSER_TESTS:-0}" == 1 ]]; then
  suites=(tests/Browser/media_center_playwright.py tests/Browser/members_entitlements_playwright.py tests/Browser/commerce_playwright.py tests/Browser/addon_framework_playwright.py tests/Browser/current_platform_playwright.py tests/Browser/booking_form_playwright.py tests/Browser/merchandise_playwright.py tests/Browser/composer_recovery_playwright.py tests/Browser/composer_controls_playwright.py tests/Browser/composer_library_playwright.py tests/Browser/composer_buttons_playwright.py tests/Browser/site_ux_playwright.py tests/Browser/public_layout_audit_playwright.py)
else
  suites=(tests/Http/members_http.py tests/Http/commerce_http.py tests/Http/addon_http.py tests/Http/current_platform_http.py tests/Http/booking_form_http.py tests/Http/merchandise_http.py tests/Http/database_updates_http.py)
fi
for suite in "${suites[@]}"; do
  bash scripts/clean_runtime.sh >/dev/null
  php scripts/create_demo.php >/dev/null
  python "$suite"
done
