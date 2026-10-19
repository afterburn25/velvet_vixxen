"""Interactive booking, communications and publishing on desktop and mobile."""
from __future__ import annotations
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ["VELVET_BASE_URL"].rstrip("/")
OUT = Path(os.environ.get("VELVET_SCREENSHOT_DIR", "/tmp/vvx-browser-evidence"))
OUT.mkdir(parents=True, exist_ok=True)

def visit(page, path):
    response = page.goto(BASE + path, wait_until="domcontentloaded")
    assert response and response.status == 200, f"Page failed: {path}"
    if page.locator("#ageGate:not([hidden])").count():
        page.locator("#ageEnter").click()

with sync_playwright() as playwright:
    options = {"headless": True, "args": ["--no-sandbox", "--disable-dev-shm-usage"]}
    if os.getenv("VELVET_CHROMIUM"):
        options["executable_path"] = os.environ["VELVET_CHROMIUM"]
    browser = playwright.chromium.launch(**options)
    for label, viewport in [("desktop", {"width": 1440, "height": 1000}), ("mobile", {"width": 390, "height": 844})]:
        context = browser.new_context(viewport=viewport)
        page = context.new_page()
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        visit(page, "/booking/")
        page.select_option('select[name="inquiry_type"]', "appearance")
        page.fill('input[name="contact_name"]', "Browser Fixture")
        page.fill('input[name="contact_email"]', f"booking-{label}@example.test")
        page.fill('textarea[name="details"]', f"Browser {label} appearance inquiry")
        page.check('input[name="consent"]')
        page.get_by_role("button", name="Submit Private Inquiry").click()
        page.get_by_text("Your private inquiry was submitted successfully.", exact=True).wait_for()
        page.screenshot(path=str(OUT / f"booking-{label}.png"), full_page=True)

        visit(page, "/account/login.php")
        page.fill('input[name="email"]', "member@example.com")
        page.fill('input[name="password"]', "VelvetMember12")
        page.get_by_role("button", name="Sign In", exact=True).click()
        page.wait_for_url("**/account/")
        visit(page, "/messages/")
        form = page.locator("form.inbox-new")
        form.locator('[name="subject"]').fill(f"Browser {label} private conversation")
        form.locator('[name="message"]').fill(f"Browser {label} message")
        form.get_by_role("button", name="Send Message", exact=True).click()
        page.locator(".conversation-messages").get_by_text(f"Browser {label} message", exact=True).wait_for()
        page.reload(wait_until="domcontentloaded")
        assert page.locator(".conversation-messages").get_by_text(f"Browser {label} message", exact=True).is_visible()
        page.screenshot(path=str(OUT / f"messages-{label}.png"), full_page=True)

        visit(page, "/newsletter/")
        page.locator('input[name="name"]').fill("Browser Newsletter")
        page.locator('input[name="email"]').fill(f"newsletter-{label}@example.test")
        page.check('input[name="consent"]')
        page.get_by_role("button", name="Subscribe", exact=True).click()
        page.locator(".marketing-notice").wait_for()
        assert "unconfirmed" in page.locator(".marketing-notice").inner_text().lower()
        assert page.locator('a[href*="confirm.php?token="]').count() == 0
        page.screenshot(path=str(OUT / f"newsletter-{label}.png"), full_page=True)
        assert not errors, errors
        context.close()
    browser.close()
print("Interactive desktop/mobile booking, private messaging and newsletter flows passed.")
