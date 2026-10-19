"""Real-browser acceptance for separate memberships, content checkout and refunds."""
from __future__ import annotations

import os
import re
import time
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import Page, sync_playwright

BASE = os.getenv("VELVET_BASE_URL", "http://127.0.0.1:8765").rstrip("/")
SCREENSHOT_DIR = Path(os.getenv("VELVET_SCREENSHOT_DIR", "")).expanduser() if os.getenv("VELVET_SCREENSHOT_DIR") else None
failures: list[str] = []


def observe(page: Page, label: str) -> None:
    page.on(
        "console",
        lambda msg: failures.append(f"{label} console {msg.type}: {msg.text}")
        if msg.type == "error"
        else None,
    )
    page.on("pageerror", lambda exc: failures.append(f"{label} page error: {exc}"))

    def request_failed(request) -> None:
        reason = str(request.failure or "")
        path = request.url.lower().split("?", 1)[0]
        if "ERR_ABORTED" in reason and path.endswith((".mp3", ".mp4", ".webm", ".wav", ".m4a")):
            return
        failures.append(f"{label} request failed: {request.url} {reason}")

    page.on("requestfailed", request_failed)


def visit(page: Page, path: str, wait_ms: int = 250) -> None:
    page.goto(BASE + path, wait_until="domcontentloaded", timeout=30_000)
    page.wait_for_timeout(wait_ms)


def dismiss_age_gate(page: Page) -> None:
    gate = page.locator("#ageGate:not([hidden])")
    if gate.count():
        page.locator("#ageEnter").click()
        page.wait_for_timeout(100)


def screenshot(page: Page, filename: str, full_page: bool = True) -> None:
    if SCREENSHOT_DIR is None:
        return
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(SCREENSHOT_DIR / filename), full_page=full_page)


def launch_browser(playwright):
    options = {"headless": True, "args": ["--no-sandbox", "--disable-dev-shm-usage"]}
    executable = os.getenv("VELVET_CHROMIUM", "").strip()
    if executable:
        options["executable_path"] = executable
    return playwright.chromium.launch(**options)


with sync_playwright() as playwright:
    browser = launch_browser(playwright)
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    observe(page, "commerce-desktop")

    # Public store and catalog.
    visit(page, "/shop/")
    dismiss_age_gate(page)
    body = page.locator("body").inner_text()
    for title in ["Velvet Signature Tee", "Velvet Studio Mug", "Velvet VIP"]:
        assert title in body, f"Store is missing {title}"
    assert page.locator(".store-card").count() >= 5
    assert page.locator('.merchandise-card').count() == 2
    screenshot(page, "store-desktop.png")

    # Register and verify a fresh member entirely through the browser UI.
    email = f"commerce.browser.{int(time.time())}@example.com"
    visit(page, "/account/register.php")
    page.fill('input[name="display_name"]', "Commerce Browser Member")
    page.fill('input[name="email"]', email)
    page.fill('input[name="password"]', "VelvetCommerce12")
    page.fill('input[name="password_confirmation"]', "VelvetCommerce12")
    page.check('input[name="terms"]')
    page.get_by_role("button", name="Create Account").click()
    verification = page.locator("a.member-debug-link")
    assert verification.count() == 1, "Local verification link was not rendered"
    verification.click()
    page.wait_for_url("**/account/verify.php**", timeout=15_000)
    assert "You're confirmed" in page.locator("body").inner_text()

    # Purchase a recurring membership through the sandbox adapter.
    visit(page, "/shop/")
    plan_card = page.locator(".store-card").filter(has_text="Velvet VIP")
    assert plan_card.count() >= 1
    plan_card.first.get_by_role("button", name="Choose Plan").click()
    page.wait_for_url("**/checkout/?intent=**", timeout=15_000)
    assert "Velvet VIP" in page.locator("body").inner_text()
    assert page.get_by_role('heading', name='Start your membership.', exact=True).is_visible()
    assert "Sandbox checkout" in page.locator("body").inner_text()
    page.check(".checkout-confirm input")
    screenshot(page, "checkout-subscription.png")
    page.get_by_role("button", name=re.compile(r"^Pay ")).click()
    page.wait_for_url("**/checkout/success.php?order=**", timeout=20_000)
    success_text = page.locator("body").inner_text()
    assert "Your content is ready" in success_text
    subscription_order = parse_qs(urlparse(page.url).query).get("order", [""])[0]
    assert subscription_order.startswith("VVX-")
    screenshot(page, "checkout-success.png")

    # Billing controls and self-service cancel/resume.
    visit(page, "/account/billing.php")
    assert "Velvet VIP" in page.locator("body").inner_text()
    screenshot(page, "member-billing.png")
    page.get_by_role("button", name="Cancel at Period End").click()
    assert "will end after the current paid period" in page.locator("body").inner_text()
    page.get_by_role("button", name="Resume Renewal").click()
    assert "Automatic renewal was resumed" in page.locator("body").inner_text()

    # Subscriber-priced bundle with a coupon.
    visit(page, "/collections/")
    bundle_card = page.locator(".store-card").filter(has_text="Neon Night Collection")
    bundle_card.first.get_by_role("button", name="Buy Collection Now").click()
    page.wait_for_url("**/checkout/?intent=**", timeout=15_000)
    assert "USD 19.99" in page.locator("body").inner_text()
    page.fill('input[name="coupon"]', "WELCOME10")
    page.get_by_role("button", name="Apply").click()
    assert "USD 17.99" in page.locator("body").inner_text()
    screenshot(page, "cart-bundle-coupon.png")
    page.check(".checkout-confirm input")
    page.get_by_role("button", name=re.compile(r"^Pay ")).click()
    page.wait_for_url("**/checkout/success.php?order=**", timeout=20_000)
    bundle_order = parse_qs(urlparse(page.url).query).get("order", [""])[0]
    assert bundle_order.startswith("VVX-")
    assert "Neon Night Collection" in page.locator("body").inner_text()

    visit(page, "/account/library.php")
    library_text = page.locator("body").inner_text()
    assert "Velvet Lounge" in library_text
    assert "Neon Confessions" in library_text

    visit(page, f"/receipt/?order={bundle_order}")
    receipt_text = page.locator("body").inner_text()
    assert bundle_order in receipt_text
    assert "USD 17.99" in receipt_text
    screenshot(page, "receipt-bundle.png")

    # A declined purchase remains locked and shows a clear error.
    visit(page, "/photos/view.php?slug=after-dark")
    page.get_by_role("button", name=re.compile(r"^Rent ")).click()
    page.wait_for_url("**/checkout/?intent=**", timeout=15_000)
    page.select_option('select[name="sandbox_result"]', "decline")
    page.check(".checkout-confirm input")
    page.get_by_role("button", name=re.compile(r"^Pay ")).click()
    assert "declined" in page.locator("body").inner_text().lower()
    screenshot(page, "checkout-declined.png")

    # A direct content checkout must never add an item to the merchandise cart.
    visit(page, "/cart/")
    assert "Your cart is waiting" in page.locator("body").inner_text()
    assert page.locator('[data-cart-line]').count() == 0

    # Owner commerce administration and full refund.
    visit(page, "/admin/?page=login")
    page.fill('input[name="email"]', "admin@example.com")
    page.fill('input[name="password"]', "VelvetDemo12")
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_url("**/admin/", timeout=15_000)
    visit(page, "/admin/?page=commerce")
    assert "Subscriptions and PPV" in page.locator("body").inner_text()
    screenshot(page, "admin-commerce.png")
    visit(page, "/admin/?page=orders")
    assert bundle_order in page.locator("body").inner_text()
    screenshot(page, "admin-orders.png")
    visit(page, f"/admin/?page=orders&order={bundle_order}")
    assert "Refund Full Order" in page.locator("body").inner_text()
    page.fill('input[name="reason"]', "Playwright commerce acceptance refund")
    page.once('dialog', lambda dialog: dialog.accept())
    page.get_by_role("button", name="Refund Full Order").click()
    assert "was refunded" in page.locator("body").inner_text()

    # Member view reflects the refunded order and revoked bundle access.
    visit(page, "/admin/?page=logout")
    visit(page, "/account/billing.php")
    assert bundle_order in page.locator("body").inner_text()
    assert "refunded" in page.locator("body").inner_text().lower()
    visit(page, "/account/library.php")
    assert "Neon Confessions" not in page.locator("body").inner_text()
    context.close()

    # Mobile storefront and cart layout.
    mobile = browser.new_context(viewport={"width": 390, "height": 844})
    page = mobile.new_page()
    observe(page, "commerce-mobile")
    visit(page, "/shop/")
    dismiss_age_gate(page)
    assert page.locator(".store-card").count() >= 5
    screenshot(page, "store-mobile.png")
    mobile.close()
    browser.close()

if failures:
    raise SystemExit("\n".join(failures))
print("Subscriptions & PPV Commerce browser acceptance test passed.")
