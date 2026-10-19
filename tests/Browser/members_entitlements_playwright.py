"""Browser acceptance test for the 0.4.0 member and entitlement milestone."""
from __future__ import annotations

import os
from public_urls import site_url, wait_for_route
from playwright.sync_api import sync_playwright

BASE = os.getenv("VELVET_BASE_URL", "http://127.0.0.1:8765").rstrip("/")
failures: list[str] = []


def observe(page, label: str) -> None:
    page.on("console", lambda msg: failures.append(f"{label} console {msg.type}: {msg.text}") if msg.type == "error" else None)
    page.on("pageerror", lambda exc: failures.append(f"{label} page error: {exc}"))

    def request_failed(request) -> None:
        reason = str(request.failure or "")
        path = request.url.lower().split("?", 1)[0]
        if "ERR_ABORTED" in reason and path.endswith((".mp3", ".mp4", ".webm", ".wav", ".m4a")):
            return
        failures.append(f"{label} request failed: {request.url} {reason}")

    page.on("requestfailed", request_failed)


def visit(page, path: str, wait_ms: int = 200) -> None:
    page.goto(site_url(BASE, path), wait_until="domcontentloaded", timeout=30_000)
    page.wait_for_timeout(wait_ms)
    if page.locator("#ageGate:not([hidden])").count():
        page.locator("#ageEnter").click()


def launch_browser(playwright):
    options = {"headless": True, "args": ["--no-sandbox"]}
    executable = os.getenv("VELVET_CHROMIUM", "").strip()
    if executable:
        options["executable_path"] = executable
    return playwright.chromium.launch(**options)


with sync_playwright() as playwright:
    browser = launch_browser(playwright)

    context = browser.new_context(viewport={"width": 1440, "height": 900})
    page = context.new_page()
    observe(page, "member-desktop")
    visit(page, "/account/login.php")
    page.fill('input[name="email"]', "member@example.com")
    page.fill('input[name="password"]', "VelvetMember12")
    page.click('button:has-text("Sign In")')
    wait_for_route(page, BASE, 'account', timeout=15_000)
    assert "Welcome, Velvet Member" in page.locator("body").inner_text()
    assert "Velvet VIP" in page.locator("body").inner_text()
    assert page.locator('.member-status-pill:has-text("Active membership")').count() == 1

    visit(page, "/account/library.php")
    library_text = page.locator("body").inner_text()
    for title in ["Mirror Play", "Velvet Lounge", "Welcome to the Velvet Vixxen", "Pink Room Dreams"]:
        assert title in library_text
    assert page.locator(".member-library-card").count() >= 4

    visit(page, "/account/security.php")
    assert page.locator('h2:has-text("Signed-in devices")').count() == 1
    assert page.locator('.member-status-pill:has-text("Current")').count() == 1
    assert "member.login" in page.locator("body").inner_text()

    visit(page, "/videos/watch.php?slug=welcome-to-the-velvet-vixxen")
    assert page.locator("[data-video-player]").count() == 1
    favorite = page.locator("[data-favorite-toggle]")
    if favorite.count():
        favorite.first.click()
        page.wait_for_timeout(300)
        assert favorite.first.get_attribute("aria-pressed") in {"true", "false"}

    visit(page, "/membership/")
    assert page.locator(".plan-card").count() >= 3
    context.close()

    mobile = browser.new_context(viewport={"width": 390, "height": 844})
    page = mobile.new_page()
    observe(page, "member-mobile")
    visit(page, "/account/login.php")
    page.fill('input[name="email"]', "member@example.com")
    page.fill('input[name="password"]', "VelvetMember12")
    page.click('button:has-text("Sign In")')
    wait_for_route(page, BASE, 'account', timeout=15_000)
    assert page.locator(".member-hero").is_visible()
    visit(page, "/account/library.php")
    assert page.locator(".member-library-card").count() >= 4
    mobile.close()
    browser.close()

if failures:
    raise SystemExit("\n".join(failures))
print("Members & Entitlements browser acceptance test passed.")
