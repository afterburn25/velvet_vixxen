"""End-to-end Media Center smoke test for CI and release verification."""
from __future__ import annotations

import os
from playwright.sync_api import sync_playwright

BASE = os.getenv("VELVET_BASE_URL", "http://127.0.0.1:8765").rstrip("/")
failures: list[str] = []


def observe(page, label: str) -> None:
    page.on("console", lambda msg: failures.append(f"{label} console {msg.type}: {msg.text} {msg.location}") if msg.type == "error" else None)
    page.on("pageerror", lambda exc: failures.append(f"{label} page error: {exc}"))
    page.on("response", lambda response: failures.append(f"{label} HTTP {response.status}: {response.url}") if response.status >= 400 else None)

    def request_failed(request) -> None:
        reason = str(request.failure or "")
        path = request.url.lower().split("?", 1)[0]
        if "ERR_ABORTED" in reason and path.endswith((".mp3", ".mp4", ".webm", ".wav", ".m4a")):
            return
        failures.append(f"{label} request failed: {request.url} {reason}")

    page.on("requestfailed", request_failed)


def visit(page, path: str, wait_ms: int = 300) -> None:
    page.goto(BASE + path, wait_until="domcontentloaded", timeout=30_000)
    page.wait_for_timeout(wait_ms)


def dismiss_age_gate(page) -> None:
    if page.locator("#ageGate:not([hidden])").count():
        page.locator("#ageEnter").click()


with sync_playwright() as playwright:
    launch_options = {"headless": True, "args": ["--autoplay-policy=no-user-gesture-required", "--no-sandbox"]}
    executable = os.getenv("VELVET_CHROMIUM", "").strip()
    if executable:
        launch_options["executable_path"] = executable
    browser = playwright.chromium.launch(**launch_options)
    context = browser.new_context(viewport={"width": 1440, "height": 900})
    page = context.new_page()
    observe(page, "desktop")

    visit(page, "/")
    dismiss_age_gate(page)
    assert page.locator(".set-card").count() == 5
    assert page.locator("[data-player]").is_visible()
    page.locator("[data-play-track]").first.click()
    page.wait_for_timeout(250)
    assert page.locator("[data-player-title]").inner_text() == "Velvet After Dark"

    visit(page, "/music/")
    assert page.locator("[data-track-card]").count() == 3
    assert page.locator("[data-player-title]").inner_text() == "Velvet After Dark"

    visit(page, "/photos/")
    assert page.locator(".gallery-card").count() == 5
    visit(page, "/photos/view.php?slug=pink-paradise")
    assert page.locator("[data-lightbox-item]").count() == 5
    page.locator("[data-lightbox-item]").first.click()
    before = page.locator("[data-lightbox-count]").inner_text()
    page.locator("[data-lightbox-next]").click()
    assert page.locator("[data-lightbox-count]").inner_text() != before
    page.locator("[data-lightbox-close]").click()

    visit(page, "/videos/watch.php?slug=welcome-to-the-velvet-vixxen")
    video = page.locator("[data-video-player]")
    assert video.count() == 1 and video.get_attribute("src")
    assert video.locator("track[kind=captions]").count() == 1
    assert page.locator("[data-player]").count() == 0

    visit(page, "/admin/?page=login")
    page.fill("input[name=email]", "admin@example.com")
    page.fill("input[name=password]", "VelvetDemo12")
    page.click('button:has-text("Sign in")')
    page.wait_for_url("**/admin/", timeout=15_000)

    visit(page, "/admin/?page=music&edit_track=demo-track-1")
    form = page.locator('form:has(input[name="action"][value="save_track"])')
    form.locator('button:has-text("Save Track")').click()
    page.wait_for_url("**?page=music", timeout=15_000)
    assert "saved" in page.locator(".admin-alert.success").inner_text().lower()

    visit(page, "/admin/?page=galleries&edit=demo-gallery")
    form = page.locator('form:has(input[name="action"][value="save_gallery"])')
    assert form.locator("[data-gallery-sort] article").count() == 5
    form.locator('button:has-text("Save Gallery")').click()
    page.wait_for_url("**?page=galleries", timeout=15_000)
    assert "saved" in page.locator(".admin-alert.success").inner_text().lower()

    visit(page, "/admin/?page=videos&edit=demo-video")
    form = page.locator('form:has(input[name="action"][value="save_video"])')
    form.locator('button:has-text("Save Video")').click()
    page.wait_for_url("**?page=videos", timeout=15_000)
    assert "saved" in page.locator(".admin-alert.success").inner_text().lower()
    context.close()

    mobile = browser.new_context(viewport={"width": 390, "height": 844})
    page = mobile.new_page()
    observe(page, "mobile")
    visit(page, "/")
    dismiss_age_gate(page)
    page.locator("#mobileMenu").click()
    assert page.locator("#mobileMenu").get_attribute("aria-expanded") == "true"
    visit(page, "/photos/view.php?slug=pink-paradise")
    page.locator("[data-lightbox-item]").first.click()
    assert page.locator("[data-lightbox]").is_visible()
    mobile.close()
    browser.close()

if failures:
    raise SystemExit("\n".join(failures))
print("Media Center browser acceptance test passed.")
