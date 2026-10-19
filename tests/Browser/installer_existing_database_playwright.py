"""Real one-page installer acceptance on partial/full MySQL schemas at desktop/mobile sizes."""
from __future__ import annotations

import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import zipfile
from pathlib import Path

import requests
from playwright.sync_api import expect, sync_playwright

SOURCE = Path(__file__).resolve().parents[2]
OWNER_EMAIL = "installer-owner@example.test"
OWNER_PASSWORD = "FixtureOwn12"  # Exact 12-character boundary; disposable fixture only.
assert len(OWNER_PASSWORD) == 12
DATABASE = os.environ.get("VELVET_TEST_DB_NAME", "")
assert re.search(r"(?:^|_)test(?:_|$)", DATABASE, re.I), "An explicitly named disposable test database is required"
OUTPUT = Path(os.environ.get("VELVET_SCREENSHOT_DIR", "/tmp/vvx-installer-browser-evidence"))
OUTPUT.mkdir(parents=True, exist_ok=True)


def assert_page_fits(page):
    assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "Installer overflows the viewport"
    expect(page.locator("#installerForm")).to_be_visible()
    assert page.locator("#installerForm").count() == 1, "Installer was split into multiple form pages"
    assert not re.search(r"use the empty|choose an empty|database must be empty", page.locator("body").inner_text(), re.I), "Obsolete empty-database requirement is still displayed"


def assert_installer_geometry(page, device, phase):
    """Check the actual displayed inputs and expanded cards, including AJAX replacements."""
    expect(page.locator("#readiness")).to_have_attribute("open", "")
    geometry = page.evaluate("""() => {
        const box = selector => {
            const rect = document.querySelector(selector).getBoundingClientRect();
            return {x: rect.x, y: rect.y, width: rect.width, height: rect.height};
        };
        const folder = getComputedStyle(document.querySelector('[data-file-checks]'));
        return {
            serverHeading: box('.issue-groups > div:first-child h3'),
            folderHeading: box('.issue-groups > div:last-child h3'),
            serverCard: box('[data-system-checks] > :first-child'),
            folderCard: box('[data-file-checks] > :first-child'),
            password: box('#ownerPassword'),
            confirmation: box('#ownerPasswordConfirm'),
            folderPadding: [folder.paddingTop, folder.paddingRight, folder.paddingBottom, folder.paddingLeft],
            folderBorder: [folder.borderTopWidth, folder.borderRightWidth, folder.borderBottomWidth, folder.borderLeftWidth],
            folderBackground: folder.backgroundColor,
            serverColumns: getComputedStyle(document.querySelector('[data-system-checks]')).gridTemplateColumns,
            folderColumns: folder.gridTemplateColumns
        };
    }""")
    label = f"{device}/{phase}"

    def equal(first, second, dimensions):
        for dimension in dimensions:
            actual = geometry[first][dimension]
            expected = geometry[second][dimension]
            assert abs(actual - expected) <= 1, f"{label}: {first}/{second} {dimension} differ ({actual} vs {expected})"

    assert all(float(value.removesuffix("px")) == 0 for value in geometry["folderPadding"]), f"{label}: Folders has inherited wrapper padding: {geometry['folderPadding']}"
    assert all(float(value.removesuffix("px")) == 0 for value in geometry["folderBorder"]), f"{label}: Folders has an extra divider: {geometry['folderBorder']}"
    assert geometry["folderBackground"] in ("rgba(0, 0, 0, 0)", "transparent"), f"{label}: Folders has an extra background panel"
    assert len(geometry["serverColumns"].split()) == len(geometry["folderColumns"].split()) == 1, f"{label}: Technical cards are unexpectedly split into nested columns"
    equal("serverCard", "folderCard", ["width"])
    equal("password", "confirmation", ["width", "height"])
    if device == "desktop":
        equal("serverHeading", "folderHeading", ["y", "width"])
        equal("serverCard", "folderCard", ["y"])
        equal("password", "confirmation", ["y"])
    else:
        equal("serverHeading", "folderHeading", ["x", "width"])
        equal("serverCard", "folderCard", ["x"])
        equal("password", "confirmation", ["x"])
        assert geometry["confirmation"]["y"] > geometry["password"]["y"] + geometry["password"]["height"], f"{label}: Password fields overlap when stacked"
    assert_page_fits(page)
    return geometry


with tempfile.TemporaryDirectory(prefix="vvx-installer-browser-") as temporary:
    work = Path(temporary)
    runtime = work / "runtime"
    packages = work / "packages"
    build = subprocess.run([sys.executable, str(SOURCE / "scripts/package.py"), "--output", str(packages)], capture_output=True, text=True)
    assert build.returncode == 0, build.stderr + build.stdout
    archive = packages / "Nikki-Wilder-Velvet-Vixxen-0.8.1-Fresh-Install.zip"

    def overlay_runtime():
        runtime.mkdir(exist_ok=True)
        with zipfile.ZipFile(archive) as package:
            package.extractall(runtime)
        (runtime / ".vvx-installer-db-fixture").write_text("Disposable installer browser acceptance.\n")
        if not (runtime / "config/app.php").is_file():
            shutil.copy2(runtime / "config/app.example.php", runtime / "config/app.php")

    overlay_runtime()
    env = dict(os.environ, VELVET_INSTALLER_RUNTIME=str(runtime))

    def fixture(command):
        result = subprocess.run(["php", str(SOURCE / "tests/Installer/browser_database_fixture.php"), command], env=env, capture_output=True, text=True)
        assert result.returncode == 0, result.stderr + result.stdout
        print(result.stdout.strip(), flush=True)

    fixture("prepare-partial")
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    base = f"http://127.0.0.1:{port}"
    router = work / "router.php"
    router.write_text((SOURCE / "tests/Http/router.php").read_text().replace("$root = dirname(__DIR__, 2);", "$root = " + json.dumps(str(runtime)) + ";"))
    server_log_path = work / "http-server.log"
    server_log = server_log_path.open("w")
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", str(runtime), str(router)], env=env, stdout=server_log, stderr=subprocess.STDOUT)
    try:
        session = requests.Session()
        session.trust_env = False
        for attempt in range(80):
            if server.poll() is not None:
                raise AssertionError("PHP fixture server stopped: " + server_log_path.read_text())
            try:
                response = session.get(base + "/install/", timeout=1)
                if response.status_code == 200:
                    break
            except requests.RequestException:
                pass
            time.sleep(0.1)
        else:
            raise AssertionError("PHP fixture server did not become ready: " + server_log_path.read_text())

        with sync_playwright() as playwright:
            options = {"headless": True, "args": ["--no-sandbox", "--disable-dev-shm-usage"]}
            if os.getenv("VELVET_CHROMIUM"):
                options["executable_path"] = os.environ["VELVET_CHROMIUM"]
            browser = playwright.chromium.launch(**options)
            for device, viewport in [("desktop", {"width": 1440, "height": 1000}), ("mobile", {"width": 390, "height": 844})]:
                if device == "mobile":
                    overlay_runtime()
                    fixture("stage-resume")
                context = browser.new_context(viewport=viewport)
                page = context.new_page()
                page.set_default_timeout(15000)
                errors = []
                page.on("pageerror", lambda error: errors.append(str(error)))
                page.on("response", lambda response: errors.append(f"HTTP {response.status}: {response.url}") if response.status >= 500 else None)
                response = page.goto(base + "/install/", wait_until="networkidle")
                assert response and response.status == 200
                assert_page_fits(page)
                expect(page.locator("[data-install-button]")).to_be_enabled()
                if not page.locator("#readiness").evaluate("element => element.open"):
                    page.locator("#readiness > summary").click()
                initial_geometry = assert_installer_geometry(page, device, "initial")
                page.screenshot(path=str(OUTPUT / f"installer-existing-{device}-details-initial.png"), full_page=True)
                with page.expect_response(lambda response: response.request.method == "POST" and "recheck" in (response.request.post_data or "")) as readiness:
                    page.locator("[data-recheck]").click()
                assert readiness.value.status == 200 and readiness.value.json().get("ready"), readiness.value.json()
                expect(page.locator("[data-recheck]")).to_be_enabled()
                checked_geometry = assert_installer_geometry(page, device, "after-run-checks")
                page.screenshot(path=str(OUTPUT / f"installer-existing-{device}-details-rechecked.png"), full_page=True)
                (OUTPUT / f"installer-existing-{device}-geometry.json").write_text(json.dumps({"initial": initial_geometry, "after_run_checks": checked_geometry}, indent=2) + "\n")

                # The real public endpoint must reject a forged install request before touching records.
                forged = context.request.post(base + "/install/", form={"action": "install_now", "_csrf": "invalid-fixture-token"}, headers={"Accept": "application/json"})
                assert forged.status == 422 and not forged.json().get("ok")
                fixture("verify-unchanged")

                fields = {"db_host": os.environ.get("VELVET_TEST_DB_HOST", "127.0.0.1"), "db_name": DATABASE,
                          "db_user": os.environ["VELVET_TEST_DB_USER"], "db_password": os.environ.get("VELVET_TEST_DB_PASSWORD", ""),
                          "admin_name": "Installer Browser Owner", "admin_email": OWNER_EMAIL,
                          "admin_password": OWNER_PASSWORD, "admin_password_confirmation": OWNER_PASSWORD,
                          "site_name": "Browser Installation Fixture", "base_url": base}
                for name, value in fields.items():
                    page.locator(f'[name="{name}"]').fill(value)
                page.locator(".inline-advanced summary").click()
                page.locator('[name="db_port"]').fill(os.environ.get("VELVET_TEST_DB_PORT", "3306"))
                page.locator('[name="confirm_install"]').check()
                with page.expect_response(lambda response: response.request.method == "POST" and "test_database" in (response.request.post_data or "")) as connection:
                    page.locator("[data-test-database]").click()
                assert connection.value.status == 200 and connection.value.json().get("ok")
                expect(page.locator("[data-database-result]")).to_have_class(re.compile("success"))

                if device == "desktop":
                    page.locator('[name="admin_password"]').fill("12345678901")
                    page.locator('[name="admin_password_confirmation"]').fill("12345678901")
                    page.locator("[data-install-button]").click()
                    expect(page.locator('[data-field-error="admin_password"]')).not_to_have_text("")
                    fixture("verify-unchanged")
                else:
                    page.locator('[name="admin_password"]').fill("WrongFixture123!")
                    page.locator('[name="admin_password_confirmation"]').fill("WrongFixture123!")
                    with page.expect_response(lambda response: response.request.method == "POST" and "install_now" in (response.request.post_data or ""), timeout=60000) as refused:
                        page.locator("[data-install-button]").click()
                    refusal = refused.value.json()
                    assert refused.value.status == 422 and not refusal.get("ok") and "owner" in refusal.get("message", "").lower(), refusal
                    expect(page.locator("[data-live-notice]")).to_contain_text(re.compile("owner", re.I))
                    fixture("verify-unchanged")

                page.locator('[name="admin_password"]').fill(OWNER_PASSWORD)
                page.locator('[name="admin_password_confirmation"]').fill(OWNER_PASSWORD)
                assert_installer_geometry(page, device, "filled-password")
                assert_page_fits(page)
                page.screenshot(path=str(OUTPUT / f"installer-existing-{device}-form.png"), full_page=True)
                with page.expect_response(lambda response: response.request.method == "POST" and "install_now" in (response.request.post_data or ""), timeout=120000) as installation:
                    page.locator("[data-install-button]").click()
                installed = installation.value.json()
                assert installation.value.status == 200 and installed.get("ok"), installed
                assert installed.get("cleanup", {}).get("public_removed") is True, installed
                expect(page.locator("[data-installation-success]")).to_be_visible()
                expect(page.locator("[data-success-owner]")).to_have_text(OWNER_EMAIL)
                expect(page.locator("body")).not_to_have_class(re.compile(r"\binstalling\b"))
                expect(page.locator("[data-install-button]")).not_to_have_class(re.compile(r"\bis-loading\b"))
                expect(page.locator("[data-install-button]")).to_have_text("Installation complete")
                expect(page.locator("[data-install-button]")).to_be_disabled()
                assert page.evaluate("getComputedStyle(document.body).overflowY !== 'hidden'"), "Completed installer still locks page scrolling"
                fixture("verify-installed")
                page.screenshot(path=str(OUTPUT / f"installer-existing-{device}-complete.png"), full_page=True)

                page.locator("[data-login-link]").click()
                page.wait_for_url("**/admin/?page=login&installed=1", wait_until="networkidle")
                page.locator('[name="email"]').fill(OWNER_EMAIL)
                page.locator('[name="password"]').fill(OWNER_PASSWORD)
                page.get_by_role("button", name="Sign in", exact=True).click()
                expect(page.locator("[data-admin-navigation]")).to_be_attached()
                assert "page=login" not in page.url, "New/existing owner could not log in after installation"
                assert not errors, errors
                print(f"PASS real {device} installer: existing MySQL data, aligned Server/Folders and password inputs before/after Run Checks, one-page AJAX form, 12-character owner password, preserved state, verified cleanup and owner login", flush=True)
                context.close()
            print("Existing-database desktop/mobile installer browser acceptance passed (real packaged PHP, MySQL 8, Playwright and Chromium).", flush=True)
            browser.close()
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=10)
        server_log.close()
        shutil.copy2(server_log_path, OUTPUT / "installer-existing-http-server.log")
        fixture("cleanup")
