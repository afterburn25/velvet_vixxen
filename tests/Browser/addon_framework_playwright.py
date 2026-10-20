from __future__ import annotations

import os
from public_urls import site_url

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VELVET_BASE_URL", "http://127.0.0.1:8765/")


def url(path: str) -> str:
    return site_url(BASE, path)


with sync_playwright() as playwright:
    options = {"headless": True, "args": ["--no-sandbox"]}
    if os.getenv("VELVET_CHROMIUM"):
        options["executable_path"] = os.environ["VELVET_CHROMIUM"]
    browser = playwright.chromium.launch(**options)
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()

    page.goto(url("/"), wait_until="networkidle")
    page.get_by_text("Installed add-on", exact=True).wait_for()
    assert page.get_by_text("Welcome to the Velvet Vixxen.", exact=False).count() >= 1

    page.goto(url("/admin/?page=login"), wait_until="networkidle")
    page.get_by_label("Email address").fill("admin@example.com")
    page.get_by_label("Password").fill("VelvetDemo12")
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_url("**/admin/**")

    page.goto(url("/admin/?page=addons"), wait_until="networkidle")
    page.get_by_role("heading", name="Add-on Manager").wait_for()
    page.get_by_text("Velvet Welcome Banner", exact=True).wait_for()
    page.get_by_role("button", name="Inspect Included Starter Add-on").click()
    page.locator(".addon-review-card").get_by_role("heading", name="Velvet Welcome Banner", exact=False).wait_for()
    page.locator('.addon-review-card').get_by_text("admin.menu.register", exact=True).wait_for()

    page.goto(url("/admin/?page=welcome-banner"), wait_until="networkidle")
    page.get_by_label("Headline").fill("Playwright Add-on Acceptance")
    page.get_by_role("button", name="Save Banner Settings").click()
    page.get_by_text("Add-on settings saved").wait_for()

    page.goto(url("/"), wait_until="networkidle")
    page.get_by_text("Playwright Add-on Acceptance", exact=False).wait_for()

    mobile = browser.new_context(viewport={"width": 390, "height": 844})
    mobile_page = mobile.new_page()
    mobile_page.goto(url("/"), wait_until="networkidle")
    mobile_page.get_by_text("Playwright Add-on Acceptance", exact=False).wait_for()
    assert mobile_page.locator(".addon-welcome-widget").is_visible()

    mobile.close()
    context.close()
    browser.close()

print("Add-on Playwright acceptance passed.")
