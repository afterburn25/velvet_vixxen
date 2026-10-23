"""HTTP acceptance for memberships, direct PPV/rentals, collections and refunds."""
from __future__ import annotations

import os
import re
from urllib.parse import parse_qs, urljoin, urlparse

import requests
from bs4 import BeautifulSoup

BASE = os.getenv("VELVET_BASE_URL", "http://127.0.0.1:8765").rstrip("/") + "/"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def get_csrf(html: str) -> str:
    soup = BeautifulSoup(html, "html.parser")
    field = soup.select_one('input[name="_csrf"]')
    if field and field.get("value"):
        return str(field["value"])
    meta = soup.select_one('meta[name="csrf-token"]')
    if meta and meta.get("content"):
        return str(meta["content"])
    raise AssertionError("CSRF token was not present in the page.")


def get(session: requests.Session, path: str, **kwargs) -> requests.Response:
    response = session.get(urljoin(BASE, path.lstrip("/")), timeout=20, **kwargs)
    require(response.status_code == 200, f"GET {path} returned {response.status_code}")
    return response


def post_with_page(
    session: requests.Session,
    post_path: str,
    referer_path: str,
    data: dict[str, object],
    *,
    allow_redirects: bool = True,
) -> requests.Response:
    page = get(session, referer_path)
    payload = {"_csrf": get_csrf(page.text), **data}
    return session.post(
        urljoin(BASE, post_path.lstrip("/")),
        data=payload,
        timeout=20,
        allow_redirects=allow_redirects,
    )


# Public store and cart are visible without authentication.
anonymous = requests.Session()
store_page = get(anonymous, "/shop/")
for phrase in ["Velvet store", "Merchandise", "Velvet Signature Tee", "Velvet Studio Mug", "Velvet VIP"]:
    require(phrase in store_page.text, f"Store is missing: {phrase}")
cart_page = get(anonymous, "/cart/")
require("Your cart is waiting" in cart_page.text, "Empty cart state did not render.")

# Register and verify a new customer so purchases are isolated from demo data.
member = requests.Session()
register_page = get(member, "/account/register.php")
registration = member.post(
    urljoin(BASE, "account/register.php"),
    data={
        "_csrf": get_csrf(register_page.text),
        "display_name": "Commerce HTTP Member",
        "email": "commerce-http@example.test",
        "password": "CommerceHttpPass12!",
        "password_confirmation": "CommerceHttpPass12!",
        "terms": "1",
    },
    timeout=20,
)
require(registration.status_code == 200 and "account was created" in registration.text.lower(), "Commerce test member registration failed.")
verification_link = BeautifulSoup(registration.text, "html.parser").select_one("a.member-debug-link")
require(verification_link is not None and verification_link.get("href"), "Verification link was not exposed in file-mode test.")
verified = member.get(str(verification_link["href"]), timeout=20, allow_redirects=True)
require(verified.status_code == 200 and "You're confirmed" in verified.text, "Commerce test member verification failed.")

# Subscribe through the dedicated membership checkout.
add_plan = post_with_page(
    member,
    "/checkout/",
    "/membership/",
    {"action": "start", "type": "plan", "id": "plan-vip", "mode": "subscribe"},
)
require(add_plan.status_code == 200 and "Velvet VIP" in add_plan.text and 'intent=' in add_plan.url, "Membership checkout intent was not created.")
checkout_page = add_plan
require("Sandbox checkout" in checkout_page.text and "USD 19.99" in checkout_page.text, "Subscription checkout quote was incorrect.")
subscription_checkout = member.post(
    checkout_page.url,
    data={
        "_csrf": get_csrf(checkout_page.text),
        "purchase_terms_revision": BeautifulSoup(checkout_page.text, "html.parser").select_one('input[name="purchase_terms_revision"]')["value"],
        "action": "pay",
        "provider": "sandbox",
        "card_number": "4242 4242 4242 4242",
        "sandbox_result": "success",
        "confirmation": "1",
    },
    timeout=20,
    allow_redirects=True,
)
require(subscription_checkout.status_code == 200 and "Your content is ready" in subscription_checkout.text, "Subscription checkout did not complete.")
subscription_order = parse_qs(urlparse(subscription_checkout.url).query).get("order", [""])[0]
require(subscription_order.startswith("VVX-"), "Subscription success URL did not contain an order number.")

billing = get(member, "/account/billing.php")
require("Velvet VIP" in billing.text and "active" in billing.text.lower(), "Paid subscription was not shown in billing.")
# Cancel and resume using every bound field from the current server form.
def billing_action(html: str, action: str) -> dict[str, str]:
    form = BeautifulSoup(html, "html.parser").select_one(
        'form:has(input[name="action"][value="' + action + '"])'
    )
    require(form is not None, "Current billing action was not rendered: " + action)
    fields = {str(field["name"]): str(field.get("value", ""))
              for field in form.select("input[name]")}
    require(bool(fields.get("_csrf")) and bool(fields.get("subscription_id"))
            and fields.get("agreement_revision", "").isdigit()
            and fields.get("request_id", "").startswith("member:"),
            "Recurring form lacks its CSRF, subscription, revision or stable request identity.")
    return fields


def paid_through(html: str) -> str:
    state = BeautifulSoup(html, "html.parser").select_one("[data-recurring-state]")
    require(state is not None, "Confirmed recurring state is missing from billing.")
    match = re.search(r"Paid through (.+?) UTC\.", state.get_text(" ", strip=True))
    require(match is not None, "Exact paid-through UTC instant is missing.")
    return match.group(1)


original_paid_through = paid_through(billing.text)
cancel_fields = billing_action(billing.text, "cancel_subscription")
cancel = member.post(
    urljoin(BASE, "account/billing.php"), data=cancel_fields,
    timeout=20, allow_redirects=True,
)
require(cancel.status_code == 200 and "Renewal canceled. Paid through" in cancel.text,
        "Sandbox cancellation was not confirmed.")
saved_cancel = get(member, "/account/billing.php")
require("Renewal canceled. Paid through" in saved_cancel.text
        and paid_through(saved_cancel.text) == original_paid_through,
        "Confirmed cancellation was not retained or changed the paid-through instant.")
resume_fields = billing_action(saved_cancel.text, "resume_subscription")
require(resume_fields["subscription_id"] == cancel_fields["subscription_id"]
        and int(resume_fields["agreement_revision"]) > int(cancel_fields["agreement_revision"]),
        "Resume form does not bind the same subscription's current confirmed revision.")
resume = member.post(
    urljoin(BASE, "account/billing.php"), data=resume_fields,
    timeout=20, allow_redirects=True,
)
require(resume.status_code == 200 and "Automatic renewal confirmed." in resume.text,
        "Sandbox resumption was not confirmed.")
saved_resume = get(member, "/account/billing.php")
require("Automatic renewal confirmed." in saved_resume.text
        and paid_through(saved_resume.text) == original_paid_through,
        "Confirmed resumption was not retained or changed the paid-through instant.")
next_cancel = billing_action(saved_resume.text, "cancel_subscription")
require(next_cancel["subscription_id"] == cancel_fields["subscription_id"]
        and int(next_cancel["agreement_revision"]) > int(resume_fields["agreement_revision"]),
        "Resumed billing did not expose a newly bound cancellation form.")

# Buy a subscriber-priced bundle with a coupon.
bundle_add = post_with_page(
    member,
    "/checkout/",
    "/collections/",
    {"action": "start", "type": "bundle", "id": "bundle-neon-night", "mode": "purchase"},
)
require("Neon Night Collection" in bundle_add.text and "USD 19.99" in bundle_add.text, "Subscriber bundle price was not shown in cart.")
coupon = member.post(
    bundle_add.url,
    data={"_csrf": get_csrf(bundle_add.text), "action": "coupon", "coupon": "WELCOME10"},
    timeout=20,
    allow_redirects=True,
)
require("WELCOME10" in coupon.text and "USD 17.99" in coupon.text, "Coupon was not applied to the subscriber bundle.")
bundle_checkout_page = coupon
require("USD 17.99" in bundle_checkout_page.text, "Discounted bundle total did not reach checkout.")
bundle_checkout = member.post(
    bundle_checkout_page.url,
    data={
        "_csrf": get_csrf(bundle_checkout_page.text),
        "purchase_terms_revision": BeautifulSoup(bundle_checkout_page.text, "html.parser").select_one('input[name="purchase_terms_revision"]')["value"],
        "action": "pay",
        "provider": "sandbox",
        "card_number": "5555 5555 5555 4444",
        "sandbox_result": "success",
        "confirmation": "1",
    },
    timeout=20,
    allow_redirects=True,
)
require(bundle_checkout.status_code == 200 and "Your content is ready" in bundle_checkout.text, "Bundle checkout did not complete.")
bundle_order = parse_qs(urlparse(bundle_checkout.url).query).get("order", [""])[0]
require(bundle_order.startswith("VVX-"), "Bundle order number was missing.")

library = get(member, "/account/library.php")
require("Velvet Lounge" in library.text and "Neon Confessions" in library.text, "Purchased bundle items were not added to My Library.")
receipt = get(member, f"/receipt/?order={bundle_order}")
for phrase in [bundle_order, "Neon Night Collection", "USD 17.99", "Thank you for supporting Nikki Wilder"]:
    require(phrase in receipt.text, f"Receipt is missing: {phrase}")

# Rent a gallery through a separate order.
rental_add = post_with_page(
    member,
    "/checkout/",
    "/photos/view.php?slug=after-dark",
    {"action": "start", "type": "gallery", "id": "demo-gallery-after-dark", "mode": "rental"},
)
require("After Dark" in rental_add.text and "Rental" in rental_add.text, "Gallery rental was not added to cart.")
rental_checkout_page = rental_add
rental_checkout = member.post(
    rental_checkout_page.url,
    data={
        "_csrf": get_csrf(rental_checkout_page.text),
        "purchase_terms_revision": BeautifulSoup(rental_checkout_page.text, "html.parser").select_one('input[name="purchase_terms_revision"]')["value"],
        "action": "pay",
        "provider": "sandbox",
        "card_number": "4242 4242 4242 4242",
        "sandbox_result": "success",
        "confirmation": "1",
    },
    timeout=20,
    allow_redirects=True,
)
require("Your content is ready" in rental_checkout.text, "Rental checkout did not complete.")
library_after_rental = get(member, "/account/library.php")
require("After Dark" in library_after_rental.text, "Active gallery rental was not shown in My Library.")

# A declined checkout must not unlock content.
decline_add = post_with_page(
    member,
    "/checkout/",
    "/videos/watch.php?slug=neon-confessions",
    {"action": "start", "type": "video", "id": "demo-video-ppv", "mode": "purchase"},
)
decline_page = decline_add
declined = member.post(
    decline_page.url,
    data={
        "_csrf": get_csrf(decline_page.text),
        "purchase_terms_revision": BeautifulSoup(decline_page.text, "html.parser").select_one('input[name="purchase_terms_revision"]')["value"],
        "action": "pay",
        "provider": "sandbox",
        "card_number": "4000 0000 0000 0002",
        "sandbox_result": "decline",
        "confirmation": "1",
    },
    timeout=20,
    allow_redirects=True,
)
require("declined" in declined.text.lower(), "Sandbox declined-payment state was not displayed.")
# Remove the retained failed-checkout item so later admin assertions are isolated.
cart_after_decline = get(member, "/cart/")
clear = member.post(
    urljoin(BASE, "cart/"),
    data={"_csrf": get_csrf(cart_after_decline.text), "action": "clear", "return": "/cart/"},
    timeout=20,
    allow_redirects=True,
)
require("Your cart is empty" in clear.text, "Cart could not be cleared after a declined payment.")

# Owner commerce management and a real full refund.
admin = requests.Session()
admin_login = get(admin, "/admin/?page=login")
admin_response = admin.post(
    urljoin(BASE, "admin/?page=login"),
    data={"_csrf": get_csrf(admin_login.text), "email": "admin@example.com", "password": "VelvetDemo12"},
    timeout=20,
    allow_redirects=True,
)
require(admin_response.status_code == 200 and "/admin/" in admin_response.url, "Admin login failed for commerce acceptance test.")
for path, phrase in [
    ("/admin/?page=commerce", "Subscriptions and PPV"),
    ("/admin/?page=orders", "Orders & Transactions"),
    ("/admin/?page=coupons", "Create coupon"),
    ("/admin/?page=bundles", "Create bundle"),
    ("/admin/?page=payments", "Payment providers"),
]:
    page = get(admin, path)
    require(phrase in page.text, f"Commerce admin page {path} did not render {phrase}.")

orders_page = get(admin, "/admin/?page=orders")
require(bundle_order in orders_page.text, "Paid bundle order was not listed in admin.")
order_link = BeautifulSoup(orders_page.text, "html.parser").find("a", href=re.compile(r"page=orders&order=.*"))
# Select the exact bundle order link when possible.
for link in BeautifulSoup(orders_page.text, "html.parser").find_all("a", href=True):
    if bundle_order in link.get_text(" ", strip=True):
        order_link = link
        break
require(order_link is not None, "Admin order detail link was not present.")
# Query the order by its public order number; Store::order accepts either identifier.
order_detail = get(admin, f"/admin/?page=orders&order={bundle_order}")
require("Refund Full Order" in order_detail.text and bundle_order in order_detail.text, "Paid order detail did not expose refund controls.")
refund = admin.post(
    urljoin(BASE, f"admin/?page=orders&order={bundle_order}"),
    data={
        "_csrf": get_csrf(order_detail.text),
        "action": "refund_order",
        "order_id": bundle_order,
        "reason": "HTTP commerce acceptance refund",
    },
    timeout=20,
    allow_redirects=True,
)
require("was refunded" in refund.text and "refunded" in refund.text.lower(), "Admin full refund did not complete.")

billing_after_refund = get(member, "/account/billing.php")
require(bundle_order in billing_after_refund.text and "refunded" in billing_after_refund.text.lower(), "Refunded order status was not visible to the member.")
library_after_refund = get(member, "/account/library.php")
# These bundle-only entitlements must be gone. The subscription itself remains because it was a separate order.
require("Neon Confessions" not in library_after_refund.text, "Refunded bundle video access remained in My Library.")

print("Subscriptions & PPV Commerce HTTP acceptance test passed.")

