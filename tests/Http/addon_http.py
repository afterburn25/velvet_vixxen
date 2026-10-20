from __future__ import annotations

import os
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup

BASE = os.environ.get("VELVET_BASE_URL", "http://127.0.0.1:8765/")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def csrf(html: str) -> str:
    soup = BeautifulSoup(html, "html.parser")
    field = soup.select_one('input[name="_csrf"]')
    require(field is not None and field.get("value"), "CSRF token was missing.")
    return str(field["value"])


def get(session: requests.Session, path: str) -> requests.Response:
    response = session.get(urljoin(BASE, path.lstrip("/")), timeout=20, allow_redirects=True)
    require(response.status_code == 200, f"GET {path} returned {response.status_code}")
    return response


# The installed starter add-on must extend the public homepage and route.
anonymous = requests.Session()
home = get(anonymous, "/")
require("Installed add-on" in home.text, "Active add-on homepage widget was not rendered.")
route = get(anonymous, "/extension/?route=welcome")
require("Add-on public route" in route.text and "Welcome to the Velvet Vixxen" in route.text, "Add-on public route did not render.")

# Sign in to the owner administration area.
admin = requests.Session()
login = get(admin, "/admin/?page=login")
logged_in = admin.post(
    urljoin(BASE, "admin/?page=login"),
    data={"_csrf": csrf(login.text), "email": "admin@example.com", "password": "VelvetDemo12"},
    timeout=20,
    allow_redirects=True,
)
require(logged_in.status_code == 200 and "/admin/" in logged_in.url, "Owner login failed.")

addons = get(admin, "/admin/?page=addons")
for phrase in ["Add-on Manager", "Velvet Welcome Banner", "Explicit permissions", "License &amp; remote updates", "Safe Mode"]:
    require(phrase in addons.text, f"Add-on Manager is missing: {phrase}")

# Inspect the included package through the real same-page permission review flow.
review = admin.post(
    urljoin(BASE, "admin/?page=addons"),
    data={"_csrf": csrf(addons.text), "action": "inspect_starter_addon"},
    timeout=20,
    allow_redirects=True,
)
require(review.status_code == 200, "Starter package review did not return HTTP 200.")
for phrase in ["Permission and compatibility review", "Requested permissions", "unsigned", "admin.menu.register", "Repair Package"]:
    require(phrase.lower() in review.text.lower(), f"Permission review is missing: {phrase}")

# The add-on-owned administration page can save namespaced settings.
page = get(admin, "/admin/?page=welcome-banner")
require("Starter add-on" in page.text and "Welcome Banner" in page.text, "Dynamic add-on admin page did not render.")
saved = admin.post(
    urljoin(BASE, "admin/?page=welcome-banner"),
    data={
        "_csrf": csrf(page.text),
        "action": "save_addon_settings",
        "addon_id": "velvet.welcome-banner",
        "return_page": "welcome-banner",
        "settings[enabled]": "1",
        "settings[headline]": "HTTP Add-on Acceptance",
        "settings[copy]": "Saved through the add-on-owned administration page.",
        "settings[button_label]": "Explore",
    },
    timeout=20,
    allow_redirects=True,
)
require("Add-on settings saved" in saved.text and "HTTP Add-on Acceptance" in saved.text, "Add-on settings did not save.")
updated_home = get(anonymous, "/")
require("HTTP Add-on Acceptance" in updated_home.text, "Saved add-on setting did not reach the homepage widget.")
updated_route = get(anonymous, "/extension/?route=welcome")
require("Saved through the add-on-owned administration page" in updated_route.text, "Saved setting did not reach the public route.")

# Deactivation must remove public runtime registrations, then activation restores them.
addons = get(admin, "/admin/?page=addons")
deactivated = admin.post(
    urljoin(BASE, "admin/?page=addons"),
    data={"_csrf": csrf(addons.text), "action": "deactivate_addon", "addon_id": "velvet.welcome-banner"},
    timeout=20,
    allow_redirects=True,
)
require("deactivated" in deactivated.text.lower(), "Deactivate request did not report success.")
home_without = get(anonymous, "/")
require("HTTP Add-on Acceptance" not in home_without.text, "Deactivated add-on widget remained public.")
missing_route = anonymous.get(urljoin(BASE, "extension/?route=welcome"), timeout=20, allow_redirects=False)
require(missing_route.status_code == 404, f"Deactivated add-on route returned {missing_route.status_code}, expected 404.")

addons = get(admin, "/admin/?page=addons")
activated = admin.post(
    urljoin(BASE, "admin/?page=addons"),
    data={"_csrf": csrf(addons.text), "action": "activate_addon", "addon_id": "velvet.welcome-banner"},
    timeout=20,
    allow_redirects=True,
)
require("activated" in activated.text.lower(), "Activate request did not report success.")
restored = get(anonymous, "/")
require("HTTP Add-on Acceptance" in restored.text, "Reactivated add-on widget did not return.")

# Safe mode suppresses optional code without deleting package state.
addons = get(admin, "/admin/?page=addons")
safe = admin.post(
    urljoin(BASE, "admin/?page=addons"),
    data={"_csrf": csrf(addons.text), "action": "toggle_addon_safe_mode", "enabled": "1"},
    timeout=20,
    allow_redirects=True,
)
require("Safe Mode enabled" in safe.text, "Safe Mode did not enable.")
safe_home = get(anonymous, "/")
require("HTTP Add-on Acceptance" not in safe_home.text, "Safe Mode did not suppress the add-on widget.")

safe_page = get(admin, "/admin/?page=addons")
unsafe = admin.post(
    urljoin(BASE, "admin/?page=addons"),
    data={"_csrf": csrf(safe_page.text), "action": "toggle_addon_safe_mode", "enabled": "0"},
    timeout=20,
    allow_redirects=True,
)
require("Safe Mode disabled" in unsafe.text, "Safe Mode did not disable.")
require("HTTP Add-on Acceptance" in get(anonymous, "/").text, "Add-on did not recover after Safe Mode was disabled.")

print("Add-on HTTP acceptance passed.")
