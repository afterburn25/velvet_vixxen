"""HTTP acceptance test for the 0.4.0 member and entitlement milestone."""
from __future__ import annotations

import json
import os
import sys
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


def post_form(session: requests.Session, path: str, data: dict[str, object], referer_path: str) -> requests.Response:
    referer = get(session, referer_path)
    payload = {"_csrf": get_csrf(referer.text), **data}
    return session.post(urljoin(BASE, path.lstrip("/")), data=payload, timeout=20, allow_redirects=True)


# Anonymous public and membership views.
anonymous = requests.Session()
plans = get(anonymous, "/membership/")
require("Velvet VIP" in plans.text and "All Access" in plans.text, "Membership plans were not rendered publicly.")
locked_gallery = get(anonymous, "/photos/view.php?slug=mirror-play")
require("membership" in locked_gallery.text.lower() and "locked" in locked_gallery.text.lower(), "Anonymous subscription gallery did not show its locked state.")
unauthorized_api = anonymous.get(urljoin(BASE, "api/member-state.php?type=video&id=demo-video"), timeout=20)
require(unauthorized_api.status_code == 401, "Anonymous member-state API request was not rejected with 401.")

# Real member login and member-only pages.
member = requests.Session()
login = get(member, "/account/login.php")
login_response = member.post(
    urljoin(BASE, "account/login.php"),
    data={
        "_csrf": get_csrf(login.text),
        "email": "member@example.com",
        "password": "VelvetMember12",
        "return": "/account/",
    },
    timeout=20,
    allow_redirects=True,
)
require(login_response.status_code == 200
        and urlparse(login_response.url).path == urlparse(BASE).path.rstrip('/') + '/route.php'
        and parse_qs(urlparse(login_response.url).query).get('vvx_route') == ['account'],
        "Member login did not reach the dashboard through the physical dispatcher.")
require("Welcome, Velvet Member" in login_response.text, "Member dashboard greeting was not rendered.")
require("Velvet VIP" in login_response.text and "Active membership" in login_response.text, "Active subscription was not shown on the member dashboard.")

library = get(member, "/account/library.php")
for title in ["Mirror Play", "Velvet Lounge", "Welcome to the Velvet Vixxen", "Pink Room Dreams"]:
    require(title in library.text, f"My Library is missing entitled content: {title}")

security = get(member, "/account/security.php")
require("Signed-in devices" in security.text and "Current" in security.text, "Device-session management did not show the current session.")
require("member.login" in security.text, "Account activity did not record the member login.")

video = get(member, "/videos/watch.php?slug=welcome-to-the-velvet-vixxen")
require("Continue watching" in video.text or "data-video-player" in video.text, "Entitled video page did not render its player.")
require("Upgrade" not in video.text or "Member access" in video.text, "Entitled video unexpectedly displayed a hard purchase gate.")

# Server-backed favorite and playback state.
csrf = get_csrf(video.text)
state_write = member.post(
    urljoin(BASE, "api/member-state.php"),
    headers={"X-CSRF-Token": csrf, "Content-Type": "application/json"},
    data=json.dumps({
        "type": "video",
        "id": "demo-video",
        "progressSeconds": 63.25,
        "isFavorite": True,
        "viewed": True,
        "metadata": {"duration": 120},
    }),
    timeout=20,
)
require(state_write.status_code == 200, f"Member-state update returned {state_write.status_code}: {state_write.text[:200]}")
state_payload = state_write.json()
require(state_payload.get("ok") is True, "Member-state update did not return ok=true.")
require(abs(float(state_payload["state"]["progressSeconds"]) - 63.25) < 0.01, "Playback progress was not persisted through the API.")
require(state_payload["state"]["isFavorite"] is True, "Favorite state was not persisted through the API.")
state_read = member.get(urljoin(BASE, "api/member-state.php?type=video&id=demo-video"), timeout=20)
require(state_read.status_code == 200 and abs(float(state_read.json()["state"]["progressSeconds"]) - 63.25) < 0.01, "Saved member state could not be read back.")

# CSRF must be enforced.
csrf_failure = member.post(
    urljoin(BASE, "api/member-state.php"),
    headers={"Content-Type": "application/json"},
    data=json.dumps({"type": "video", "id": "demo-video", "isFavorite": False}),
    timeout=20,
)
require(csrf_failure.status_code == 419, "Member-state write without CSRF was not rejected.")

# Real registration and email-verification route in file-mode demo.
new_member = requests.Session()
registration_page = get(new_member, "/account/register.php")
registration = new_member.post(
    urljoin(BASE, "account/register.php"),
    data={
        "_csrf": get_csrf(registration_page.text),
        "display_name": "HTTP Test Member",
        "email": "http-test@example.test",
        "password": "HttpVelvetPass12!",
        "password_confirmation": "HttpVelvetPass12!",
        "terms": "1",
    },
    timeout=20,
)
require(registration.status_code == 200 and "account was created" in registration.text.lower(), "HTTP registration did not complete.")
soup = BeautifulSoup(registration.text, "html.parser")
verification_link = soup.select_one("a.member-debug-link")
require(verification_link is not None and verification_link.get("href"), "File-mode registration did not expose its local verification link.")
verified = new_member.get(str(verification_link["href"]), timeout=20, allow_redirects=True)
require(verified.status_code == 200 and "You're confirmed" in verified.text, "Email verification did not activate the new member.")
verified_dashboard = get(new_member, "/account/")
require("Welcome, HTTP Test Member" in verified_dashboard.text, "Verified member was not signed in after email confirmation.")

# Admin member-management pages.
admin = requests.Session()
admin_login = get(admin, "/admin/?page=login")
admin_response = admin.post(
    urljoin(BASE, "admin/?page=login"),
    data={
        "_csrf": get_csrf(admin_login.text),
        "email": "admin@example.com",
        "password": "VelvetDemo12",
    },
    timeout=20,
    allow_redirects=True,
)
require(admin_response.status_code == 200 and "/admin/" in admin_response.url, "Admin login failed.")
for path, text in [
    ("/admin/?page=members", "Member Directory"),
    ("/admin/?page=plans", "Membership Plans"),
    ("/admin/?page=entitlements&member=demo-member", "Grant Access"),
]:
    page = get(admin, path)
    require(text in page.text, f"Admin page {path} did not render {text}.")

# Exercise real admin writes for a plan and a direct entitlement.
plans_page = get(admin, "/admin/?page=plans")
plan_save = admin.post(
    urljoin(BASE, "admin/?page=plans"),
    data={
        "_csrf": get_csrf(plans_page.text),
        "action": "save_membership_plan",
        "id": "",
        "name": "HTTP Test Plan",
        "key": "http-test-plan",
        "description": "Created by the HTTP release test.",
        "billing_interval": "month",
        "interval_count": "1",
        "price": "9.99",
        "currency": "USD",
        "trial_days": "0",
        "ppv_discount_percent": "5",
        "sort_order": "90",
        "benefits": "Test benefit\nAnother benefit",
        "is_active": "1",
    },
    timeout=20,
    allow_redirects=True,
)
require(plan_save.status_code == 200 and "HTTP Test Plan saved" in plan_save.text, "Admin could not create a membership plan.")

entitlements_page = get(admin, "/admin/?page=entitlements&member=demo-member")
grant = admin.post(
    urljoin(BASE, "admin/?page=entitlements&member=demo-member"),
    data={
        "_csrf": get_csrf(entitlements_page.text),
        "action": "grant_entitlement",
        "member_id": "demo-member",
        "content_type": "gallery",
        "content_id": "demo-gallery-after-dark",
        "source_type": "complimentary",
        "expires_at": "",
        "note": "HTTP acceptance grant",
    },
    timeout=20,
    allow_redirects=True,
)
require(grant.status_code == 200 and "Content access granted" in grant.text and "After Dark" in grant.text, "Admin could not grant direct gallery access.")

print("Members & Entitlements HTTP acceptance test passed.")
