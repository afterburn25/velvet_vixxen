"""Real HTTP regressions for restored communication and marketing boundaries."""
from __future__ import annotations
import json
import os
import re
from pathlib import Path
from urllib.parse import urljoin
import requests
from bs4 import BeautifulSoup

BASE = os.environ["VELVET_BASE_URL"].rstrip("/") + "/"
ROOT = Path(os.environ["VELVET_TEST_RUNTIME"])
assert (ROOT / ".vvx-test-runtime").is_file(), "Disposable runtime marker is required."

def get(session, path):
    response = session.get(urljoin(BASE, path.lstrip("/")), timeout=20)
    assert response.status_code == 200, (path, response.status_code, response.text[:300])
    return response

def token(response, name="_csrf"):
    field = BeautifulSoup(response.text, "html.parser").select_one(f'input[name="{name}"]')
    assert field and field.get("value"), f"Missing {name} token at {response.url}"
    return field["value"]

def data():
    return json.loads((ROOT / "storage/data.json").read_text())

def login(email):
    session = requests.Session()
    page = get(session, "/account/login.php")
    response = session.post(urljoin(BASE, "account/login.php"), data={"_csrf": token(page), "email": email, "password": "VelvetMember12", "return": "/account/"}, timeout=20)
    assert response.status_code == 200 and response.url.rstrip("/").endswith("/account"), "Member login failed."
    return session

guest = requests.Session()
for path in ["messages/", "requests/", "admin/marketing.php"]:
    response = guest.get(urljoin(BASE, path), timeout=20, allow_redirects=False)
    assert response.status_code in (302, 303, 403), f"Private route exposed without authentication: {path}"
for path in ["config/app.php", "storage/data.json", "public/routes/messages/index.php", "includes/bootstrap.php"]:
    response = guest.get(urljoin(BASE, path), timeout=20)
    assert response.status_code in (403, 404), f"Private implementation path exposed: {path}"

booking = get(guest, "booking/")
payload = {"inquiry_type": "appearance", "contact_name": "Fixture Guest", "contact_email": "guest@example.test", "details": "Private booking HTTP fixture", "consent": "1"}
before = len(data().get("bookingRequests", {}))
bad = guest.post(urljoin(BASE, "booking/"), data=payload, timeout=20)
assert "form session expired" in bad.text and len(data().get("bookingRequests", {})) == before, "Booking CSRF validation failed."
payload["_csrf"] = token(booking)
saved = guest.post(urljoin(BASE, "booking/"), data=payload, timeout=20)
assert "submitted successfully" in saved.text and len(data().get("bookingRequests", {})) == before + 1, "Booking was not persisted."

member, other = login("member@example.com"), login("other@example.com")
inbox = get(member, "messages/")
sent = member.post(urljoin(BASE, "messages/"), data={"_csrf": token(inbox), "action": "new_thread", "subject": "HTTP private fixture subject", "message": "HTTP private fixture body"}, timeout=20)
assert "HTTP private fixture body" in sent.text and "thread=" in sent.url, "Private conversation creation failed."
thread_id = re.search(r"thread=([^&]+)", sent.url).group(1)
not_yours = get(other, "messages/?thread=" + thread_id)
assert "HTTP private fixture subject" not in not_yours.text and "HTTP private fixture body" not in not_yours.text, "Another member read a private thread."
injection = other.post(urljoin(BASE, "messages/"), data={"_csrf": token(not_yours), "action": "send_message", "thread_id": thread_id, "message": "forbidden-cross-member-reply"}, timeout=20)
assert "Conversation not found" in injection.text and "forbidden-cross-member-reply" not in json.dumps(data()), "Cross-member thread write succeeded."
assert get(member, "requests/").status_code == 200

newsletter = get(guest, "newsletter/")
invalid = guest.post(urljoin(BASE, "newsletter/"), data={"name": "Fixture", "email": "newsletter@example.test", "consent": "1", "csrf": "wrong"}, timeout=20)
assert invalid.status_code == 419, "Newsletter CSRF rejection failed."
valid = guest.post(urljoin(BASE, "newsletter/"), data={"name": "Fixture", "email": "newsletter@example.test", "consent": "1", "csrf": token(newsletter, "csrf")}, timeout=20)
assert valid.status_code == 200 and "unconfirmed" in valid.text.lower(), "Disabled email delivery must leave the subscription unconfirmed."
assert 'confirm.php?token=' not in valid.text, "Newsletter exposed an email confirmation token in the public response."
marketing = json.loads((ROOT / "storage/private/marketing/data.json").read_text())
contacts = [c for c in marketing["contacts"] if c["email"] == "newsletter@example.test"]
assert len(contacts) == 1 and contacts[0]["status"] == "pending", "Newsletter bypassed confirmation."
for origin in ["https://foreign.example.test", "not-an-origin", "null"]:
    assert guest.post(urljoin(BASE, "api/marketing-event.php"), headers={"Origin": origin}, json={"event": "page_view", "path": "/foreign"}, timeout=20).status_code == 403, "Foreign/malformed-origin analytics request was accepted."
assert guest.post(urljoin(BASE, "api/marketing-event.php"), json={"event": "page_view", "path": "/missing"}, timeout=20).status_code == 403, "Origin-free analytics request was accepted."
extra = guest.post(urljoin(BASE, "api/marketing-event.php"), headers={"Origin": BASE.rstrip("/")}, json={"event": "page_view", "path": "/accepted", "private_message": "forbidden-analytics-body"}, timeout=20)
assert extra.status_code == 400, "Analytics accepted a private payload field."
same = guest.post(urljoin(BASE, "api/marketing-event.php"), headers={"Origin": BASE.rstrip("/")}, json={"event": "page_view", "path": "/accepted?secret=fixture-private-query"}, timeout=20)
assert same.status_code == 200 and same.json()["ok"], "Same-origin analytics request failed."
assert "forbidden-analytics-body" not in (ROOT / "storage/private/marketing/data.json").read_text(), "Analytics stored a private message body."
assert "fixture-private-query" not in (ROOT / "storage/private/marketing/data.json").read_text(), "Analytics stored a private query string."
print("Communication ownership, booking CSRF, newsletter consent and same-origin analytics HTTP regressions passed.")
