#!/usr/bin/env python3
"""Actual-package publishing calendar and post expiry, file/MySQL root/nested.

No clock injection or synthetic request router is used. The real public expiry
transition is observed across a short future instant in an owned installation.
"""
from __future__ import annotations

import argparse
import concurrent.futures
from datetime import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import select
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlencode, urljoin, urlparse
from zipfile import ZipFile
from zoneinfo import ZoneInfo

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("vvx_calendar_history_http", ROOT / "tests/Http/editorial_history_http.py")
assert spec and spec.loader
history = importlib.util.module_from_spec(spec)
spec.loader.exec_module(history)
metadata, http, check = history.metadata, history.http, history.check
FIXTURE = Path(__file__).with_name("editorial_calendar_fixture.php")
MONTH = "2030-02"
TYPES = ("posts", "banners", "landing_pages", "galleries", "videos", "music", "campaigns", "scenes")
NEW_RUNTIME = ("app/Core/MarketingCalendar.php", "app/Core/MarketingPublication.php", "app/Core/MarketingHistory.php",
               "app/Core/MarketingStore.php", "app/Core/Store.php", "includes/marketing_admin.php", "admin/index.php",
               "app/Views/admin/marketing-calendar.php", "app/Views/admin/marketing.php",
               "app/Views/admin/marketing-publication-editor.php", "app/Views/admin/marketing-publication-list.php",
               "app/Views/admin/marketing-history.php", "app/Views/admin/media-center.php", "public/assets/css/marketing.css",
               "public/assets/css/marketing-calendar.css")


def fixture(site, action, php="php", _count=True, **values):
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=45, cwd="/tmp")
    message = "Guarded calendar fixture failed (" + action + "): " + result.stderr[:2000]
    if _count:
        check(result.returncode == 0, message)
    elif result.returncode != 0:
        raise AssertionError(message)
    return json.loads(result.stdout)


def seed(case):
    history.seed(case)
    return fixture(case["site"], "seed", case["php"])


def snapshot(site, php):
    saved = metadata.snapshot(site, php)
    saved["calendar"] = fixture(site, "read", php)
    return saved


def no_writes(before, after, label):
    metadata.no_writes(before, after, label)
    check(before["calendar"] == after["calendar"], label + " changed media, scene drafts/history or independent SQL rows.")


def identity(name="main"):
    return "calendar-post-" + name


def record(saved, name="main"):
    rows = [row for row in saved["marketing"]["posts"] if row.get("id") == identity(name)]
    check(len(rows) == 1, "Reserved calendar post is missing or duplicated: " + name)
    return rows[0]


def calendar_url(base, month=MONTH, kind="all"):
    return base + "/admin/?" + urlencode({"page": "marketing", "tab": "calendar", "month": month, "type": kind})


def editor_url(base, name="main"):
    return base + "/admin/?" + urlencode({"page": "marketing", "tab": "posts", "edit_post": identity(name)})


def public_url(base, name="main"):
    return http.route(base, "blog/post.php?slug=" + identity(name))


def history_url(base, name="main", version=None):
    values = {"page": "marketing-history", "collection": "posts", "id": identity(name)}
    if version is not None:
        values["version"] = version
    return base + "/admin/?" + urlencode(values)


def current_fields(owner, base, name="main"):
    response = owner.get(editor_url(base, name), timeout=20)
    check(response.status_code == 200, "The packaged post editor failed to load.")
    values = http.fields(response.text, 'form[data-publication-editor="posts"]')
    check("expires_at" in dict(values), "The actual post editor is missing explicit expiration.")
    return values, response


def save(owner, base, values, name="main"):
    response = owner.post(editor_url(base, name), data=values, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == "GET"
          and not BeautifulSoup(response.text, "html.parser").select(".admin-alert.error"),
          "The actual post save failed or omitted POST/Redirect/GET: " + response.text[-250:])
    return response


def calendar(owner, base, month=MONTH, kind="all"):
    response = owner.get(calendar_url(base, month, kind), timeout=25)
    check(response.status_code == 200, "The packaged calendar failed to load: " + response.text[:300])
    http.private(response)
    soup = BeautifulSoup(response.text, "html.parser")
    root = soup.select_one("[data-publishing-calendar]")
    check(root is not None and root.get("data-calendar-month") == month
          and root.get("data-calendar-filter") == kind and root.get("data-calendar-timezone") == "America/Chicago",
          "The calendar omitted the selected month/filter/site timezone.")
    check(not soup.select("[data-calendar-injection]") and "CALENDAR_PRIVATE_" not in response.text,
          "The calendar exposed private bodies/metadata or interpreted a title as HTML.")
    check(not root.select('form[method="post"], [data-marketing-event]') and "marketing.js" not in response.text,
          "A read-only calendar exposes a mutation form or public tracking hook.")
    events = root.select("[data-calendar-agenda] [data-calendar-event]")
    keys = [row["data-calendar-event"] for row in events]
    check(len(keys) == len(set(keys)), "Calendar event identities collide.")
    times = []
    for row in events:
        parsed = datetime.fromisoformat(row.select_one("time[datetime]")["datetime"].replace("Z", "+00:00"))
        check(parsed.tzinfo is not None and parsed.astimezone(ZoneInfo("America/Chicago")).strftime("%Y-%m") == month,
              "An agenda item belongs to a different local month or lost its absolute instant.")
        times.append(parsed.timestamp())
        check(row.get("data-calendar-type") in TYPES and (kind == "all" or row["data-calendar-type"] == kind),
              "The selected content filter contains another type.")
        state = row.select_one("[data-calendar-state]")
        check(state is not None and state.get_text(strip=True), "A dated item has no saved/current state.")
        for link in row.select("[data-calendar-edit]"):
            destination = urlparse(urljoin(base + "/", link["href"]))
            check(destination.netloc == urlparse(base).netloc and destination.path == urlparse(base).path + "/admin/"
                  and parse_qs(destination.query).get("page"), "An editor link leaves the owned nested admin path.")
    check(times == sorted(times), "The agenda is not ordered by actual instants.")
    for row in root.select("[data-calendar-undated-item]"):
        check(kind == "all" or row["data-calendar-type"] == kind, "An undated item bypassed the type filter.")
    return response, soup, events


def event_ids(events):
    return [row["data-calendar-source-id"] for row in events]


def calendar_cases(owner, base, site, php):
    before = snapshot(site, php)
    response, soup, events = calendar(owner, base)
    ids = event_ids(events)
    check(set(row["data-calendar-type"] for row in events) == set(TYPES), "The actual combined calendar omits a content family.")
    check(ids.count(identity()) == 2, "Both post publication and expiration must be visible in the agenda.")
    check(ids.count("calendar-banner-main") == 2 and ids.count("calendar-page-main") == 2,
          "The agenda omitted a banner/landing-page start or end date.")
    manifest = before["calendar"]["manifest"]
    check(any(str(row["id"]) == str(manifest["media"]["dueGallery"]["id"]) and row["status"] == "scheduled"
              for row in before["calendar"]["media"]["galleries"]),
          "The calendar fixture lacks a due but unprocessed media schedule.")
    for name, kind, day in (("gallery", "galleries", 15), ("video", "videos", 16),
                            ("album", "music", 17), ("track", "music", 18), ("playlist", "music", 19)):
        check(any(row["data-calendar-type"] == kind
                  and row["data-calendar-source-id"] == str(manifest["media"][name]["id"])
                  and datetime.fromisoformat(row.select_one("time[datetime]")["datetime"].replace("Z", "+00:00"))
                      .astimezone(ZoneInfo("America/Chicago")).date().isoformat() == "2030-02-" + str(day)
                  for row in events), "The actual " + name + " schedule was not shown on its saved local date.")
    scene_instant = datetime.fromisoformat("2030-02-20T18:00:00+00:00").timestamp()
    check(all(any(row["data-calendar-type"] == "scenes" and row["data-calendar-source-id"] == section
                  and datetime.fromisoformat(row.select_one("time[datetime]")["datetime"].replace("Z", "+00:00")).timestamp() == scene_instant
                  for row in events) for section in ("hero", "footer")),
          "The saved Hero and Footer publication histories lost their actual publication instants.")
    check(identity("month-start") in ids and identity("month-end") in ids
          and identity("previous-edge") not in ids and identity("next-edge") not in ids,
          "The calendar did not use inclusive local month start and exclusive next-month start.")
    month_cells = {row["data-calendar-day"] for row in soup.select("[data-calendar-grid] [data-calendar-day]")
                   if row["data-calendar-day"].startswith(MONTH)}
    check(len(month_cells) == 28, "February2030 has the wrong number of calendar days.")
    undated = {row["data-calendar-source-id"]: row for row in soup.select("[data-calendar-undated-item]")}
    check(identity("undated") in undated and identity("invalid") in undated,
          "Missing or malformed dates were silently dropped instead of shown for review.")
    check("review" in undated[identity("invalid")].get_text(" ", strip=True).lower(), "Malformed post expiry lacks a review explanation.")
    check("scheduling" in " ".join(row.get_text(" ", strip=True) for row in soup.select("[data-calendar-notice]")).lower(),
          "Composer publication history is missing its separate scheduling boundary.")
    selected = {}
    for kind in TYPES:
        _, filtered, rows = calendar(owner, base, kind=kind)
        check(bool(rows), "A seeded calendar type was empty: " + kind)
        selected[kind] = len(rows)
        for label, target in (("Previous month", "2030-01"), ("Next month", "2030-03")):
            anchor = filtered.select_one('a[aria-label="' + label + '"]')
            check(anchor is not None, "Month navigation is missing: " + label)
            query = parse_qs(urlparse(anchor["href"]).query)
            check(query.get("month") == [target] and query.get("type") == [kind], "Month navigation lost the selected filter.")
    _, _, january = calendar(owner, base, "2030-01", "posts")
    _, _, march = calendar(owner, base, "2030-03", "posts")
    check(identity("previous-edge") in event_ids(january) and identity("next-edge") in event_ids(march),
          "Adjacent month boundary records are not discoverable.")
    _, leap, _ = calendar(owner, base, "2028-02", "posts")
    check(len([row for row in leap.select("[data-calendar-grid] [data-calendar-day]")
               if row["data-calendar-day"].startswith("2028-02")]) == 29, "The calendar lost leap day.")
    _, _, folds = calendar(owner, base, "2026-11", "posts")
    moments = {}
    for name in ("fold-first", "fold-second"):
        rows = [row for row in folds if row["data-calendar-source-id"] == identity(name)]
        check(len(rows) == 2, "A repeated-hour publication/expiry was collapsed or lost.")
        moments[name] = min(datetime.fromisoformat(row.select_one("time[datetime]")["datetime"].replace("Z", "+00:00")).timestamp()
                            for row in rows)
    check(moments["fold-second"] - moments["fold-first"] == 3600, "DST fold instants were silently merged.")
    _, empty, rows = calendar(owner, base, "2038-07", "posts")
    check(not rows and empty.select_one("[data-calendar-empty]") is not None, "An empty month lacks a useful empty state.")
    no_writes(before, snapshot(site, php), "Month/filter/calendar reads")
    return {"content_type_event_counts": selected, "all_eight_content_types": True, "local_month_edges": True,
            "leap_day": True, "dst_fold_distinct_instants": True, "private_fields_excluded_and_titles_escaped": True,
            "empty_month_and_undated_review": True, "read_only_no_workers_or_marketing_events": True}


def invalid_calendar(owner, guest, member, base, site, php):
    before = snapshot(site, php)
    failures = []
    for query in ("month=2030-13", "month=2030-2", "month=1969-12", "month=9999-01", "month[]=2030-02",
                  "month=2030-02&type=contacts", "month=2030-02&type[]=posts"):
        response = owner.get(base + "/admin/?page=marketing&tab=calendar&" + query, timeout=20)
        check(response.status_code == 400 and "CALENDAR_PRIVATE_" not in response.text
              and "data-calendar-event=" not in response.text, "Malformed calendar filter was accepted: " + query)
        http.private(response)
        failures.append(query)
    for alias in ("calendar1", "calendar-"):
        response = owner.get(base + "/admin/?" + urlencode({"page": "marketing", "tab": alias}), timeout=20)
        check(response.status_code == 200 and "data-publishing-calendar" not in response.text
              and "CALENDAR_PRIVATE_" not in response.text,
              "A malformed tab alias bypassed the controller's exact read-only calendar route.")
    for client in (guest, member):
        response = client.get(calendar_url(base) + "&owner=1&preview=1", allow_redirects=False, timeout=20)
        check(response.status_code in (302, 303, 403) and "data-calendar-event=" not in response.text
              and "Calendar post" not in response.text, "A nonowner accessed the private calendar.")
    for status in ("demoted", "inactive"):
        http.fixture(site, "owner-status", php, status=status)
        try:
            denied = owner.get(calendar_url(base), allow_redirects=False, timeout=20)
            check(denied.status_code in (302, 303, 403) and "Calendar post" not in denied.text,
                  "A changed owner retained calendar access.")
        finally:
            http.fixture(site, "owner-status", php, status="owner")
    stale = http.fixture(site, "stale-owner-session", php)
    client = http.session()
    try:
        client.cookies.set(stale["name"], stale["id"], domain="127.0.0.1", path="/")
        denied = client.get(calendar_url(base), allow_redirects=False, timeout=20)
        check(denied.status_code in (302, 303, 403) and "Calendar post" not in denied.text,
              "A stale owner ID inherited calendar authority.")
    finally:
        client.close()
    values, _ = current_fields(owner, base)
    for method in ("post", "put", "delete"):
        response = getattr(owner, method)(calendar_url(base), data=http.changed(values, title="MUST_NOT_SAVE"), timeout=20)
        check(response.status_code == 405, "The read-only calendar accepted " + method.upper())
    no_writes(before, snapshot(site, php), "Rejected calendar filters/authority/methods")
    return {"invalid_filters": failures, "malformed_tab_aliases_do_not_reach_calendar": True,
            "guest_member_stale_revoked_owner_refused": True, "non_get_refused": True}


def noninitializing_calendar(owner, base, site, php, mode):
    results = []
    for name in (("data.lock", "sql-cutover.json") if mode == "mysql" else ("data.lock",)):
        history.fixture(site, "hold-read-artifact", php, name=name)
        try:
            before = history.fixture(site, "read-artifacts", php)
            state = http.fixture(site, "storage-files", php)
            calendar(owner, base)
            check(before == history.fixture(site, "read-artifacts", php)
                  and state == http.fixture(site, "storage-files", php), "Calendar reads initialized the absent " + name)
        finally:
            history.fixture(site, "restore-read-artifact", php, name=name)
        results.append(name)
    before = http.fixture(site, "storage-files", php)
    held = http.fixture(site, "hold-source", php)
    try:
        response = owner.get(calendar_url(base), timeout=20)
        check(response.status_code in (200, 503) and "CALENDAR_PRIVATE_" not in response.text,
              "An absent marketing source produced an unsafe calendar response.")
        check(http.fixture(site, "storage-files", php) == held, "Calendar initialized an absent marketing source.")
        if mode == "mysql":
            check(response.status_code == 200 and identity() in response.text,
                  "The SQL calendar could not read authoritative records without a local JSON guard.")
        else:
            check(identity() not in response.text, "Missing file storage was replaced by fabricated saved calendar data.")
    finally:
        http.fixture(site, "restore-source", php)
    check(http.fixture(site, "storage-files", php) == before, "Noninitializing read fixture restoration changed exact bytes/rows.")
    held_sql = []
    if mode == "mysql":
        for hold, restore in (("hold-sql-state", "restore-sql-state"), ("hold-sql-authority", "restore-sql-authority")):
            blocked = http.fixture(site, hold, php)
            try:
                response = owner.get(calendar_url(base), timeout=20)
                check(response.status_code == 503 and "data-calendar-event=" not in response.text
                      and "Calendar post" not in response.text and "CALENDAR_PRIVATE_" not in response.text,
                      "The calendar fell back to archived content after authoritative SQL became unavailable.")
                http.private(response)
                check(http.fixture(site, "storage-files", php) == blocked,
                      "The calendar initialized SQL state, authority or files after a failed authoritative read.")
            finally:
                http.fixture(site, restore, php)
            check(http.fixture(site, "storage-files", php) == before, "Held SQL calendar fixture did not restore exact state.")
            held_sql.append(hold)
    return {"missing_artifacts_preserved": results, "absent_marketing_source_never_initialized": True,
            "unavailable_sql_held_without_fallback": held_sql}


def hidden(response, name):
    check(response.status_code == 404, "An expired or malformed-window post still has a public route: " + name)
    check(all(value not in response.text for value in (identity(name), "CALENDAR_PRIVATE_BODY_" + name,
              "CALENDAR_PRIVATE_EXCERPT_" + name, "CALENDAR_PRIVATE_SEO_" + name,
              "CALENDAR_PRIVATE_DESCRIPTION_" + name, "CALENDAR_PRIVATE_TAG_" + name,
              "CALENDAR_PRIVATE_AUTHOR_" + name)), "A hidden post disclosed content or metadata in its denial.")
    http.private(response)


def expiry_public(clients, owner, base, site, php):
    initial = snapshot(site, php)
    for name, client in clients.items():
        hidden(client.get(public_url(base, "expired"), timeout=20), "expired")
        hidden(client.get(public_url(base, "invalid-active"), timeout=20), "invalid-active")
        active = client.get(public_url(base, "active"), timeout=20)
        check(active.status_code == 200 and "CALENDAR_PRIVATE_BODY_active" in active.text, "An active post became unavailable.")
        check(identity("expired") not in active.text and identity("invalid-active") not in active.text,
              "Related posts disclosed an expired or malformed-window target.")
        listing = client.get(http.route(base, "blog/"), timeout=20)
        check(listing.status_code == 200 and identity("expired") not in listing.text and identity("invalid-active") not in listing.text,
              "The post listing contains expired or malformed-window metadata.")
    preview = owner.get(base + "/admin/?" + urlencode({"page": "marketing-preview", "collection": "posts", "id": identity("expired")}), timeout=20)
    check(preview.status_code == 200 and "CALENDAR_PRIVATE_BODY_expired" in preview.text,
          "The owner cannot privately preview an expired saved post.")
    http.private(preview, True)
    no_writes(initial, snapshot(site, php), "Expired public reads and saved owner preview")
    boundary = fixture(site, "boundary", php)
    first = clients["guest"].get(public_url(base, "boundary"), timeout=20)
    check(first.status_code == 200 and time.time() < boundary["expires_epoch"], "The real-clock boundary control was not visible before expiry.")
    deadline = time.monotonic() + 7
    while time.time() < boundary["expires_epoch"] and time.monotonic() < deadline:
        time.sleep(.025)
    check(time.time() >= boundary["expires_epoch"], "The real expiry boundary was not reached.")
    observed = time.time()
    for client in clients.values():
        hidden(client.get(public_url(base, "boundary"), timeout=20), "boundary")
        related = client.get(public_url(base, "active"), timeout=20)
        check(identity("boundary") not in related.text and identity("legacy") in related.text,
              "The next request did not remove only the expired related post.")
    saved = record(snapshot(site, php), "boundary")
    check(saved["status"] == "published" and saved["expires_at"] == boundary["expires_at"],
          "Expiry rewrote the saved post instead of evaluating its current visibility.")
    return {"viewer_matrix": list(clients), "direct_list_related_filtering": True, "malformed_expiry_fails_closed": True,
            "owner_saved_expired_preview": True,
            "real_expiry_epoch": boundary["expires_epoch"], "first_post_expiry_observation": observed,
            "status_and_expiry_preserved": True}


def expiry_authority(owner, clients, base, site, php):
    before = snapshot(site, php)
    values, _ = current_fields(owner, base)
    attempted = http.changed(values, expires_at="2030-02-28T12:00:00", title="MUST_NOT_SAVE_EXPIRY")
    for name in ("guest", "free"):
        response = clients[name].post(editor_url(base), data=attempted, allow_redirects=False, timeout=20)
        check(response.status_code in (302, 303, 403), "A nonowner reached the expiration write path: " + name)
    for state in ("demoted", "inactive"):
        http.fixture(site, "owner-status", php, status=state)
        try:
            response = owner.post(editor_url(base), data=attempted, allow_redirects=False, timeout=20)
            check(response.status_code == 302 and response.headers.get("Location") == base + "/admin/",
                  "The initial owner gate accepted an expiration POST after owner revocation.")
        finally:
            http.fixture(site, "owner-status", php, status="owner")
    stale = fixture(site, "stale-expiry-session", php)
    client = http.session()
    try:
        client.cookies.set(stale["name"], stale["id"], domain="127.0.0.1", path="/")
        for token in ("invalid-stale-session-token", stale["csrf"]):
            response = client.post(editor_url(base), data=http.changed(attempted, _csrf=token),
                                   allow_redirects=False, timeout=20)
            check(response.status_code in (302, 303, 403) and "CALENDAR_PRIVATE_" not in response.text
                  and "Calendar post" not in response.text and "<!doctype" not in response.text.lower(),
                  "A stale owner ID reached expiration dispatch or output before current ownership was checked.")
    finally:
        client.close()
    no_writes(before, snapshot(site, php), "Nonowner and revoked-owner expiration writes")
    return {"guest_member_stale_demoted_inactive_refused": True, "stale_invalid_csrf_refused_before_output": True,
            "exact_saved_state_preserved": True}


def expiry_edits(owner, base, site, php):
    before = snapshot(site, php)
    values, _ = current_fields(owner, base)
    check(dict(values)["expires_at"] == "2030-02-12T12:00:00", "Saved UTC expiry did not render in the site timezone.")
    old_values = list(values)
    save(owner, base, http.changed(values, expires_at="2030-02-21T14:15:16"))
    current = snapshot(site, php)
    saved = record(current)
    check(saved["expires_at"] == "2030-02-21T20:15:16+00:00" and saved["id"] == record(before)["id"]
          and saved["slug"] == record(before)["slug"] and saved["calendar_unknown"] == record(before)["calendar_unknown"],
          "Saving expiry lost the absolute instant, existing identity or unknown fields.")
    history_entries = saved["editorial_history"]["entries"]
    check(history_entries[0]["snapshot"]["expires_at"] == "2030-02-12T18:00:00Z", "History omitted the prior expiry.")
    _, _, updated_events = calendar(owner, base, kind="posts")
    saved_moments = {datetime.fromisoformat(row.select_one("time[datetime]")["datetime"].replace("Z", "+00:00")).timestamp()
                     for row in updated_events if row["data-calendar-source-id"] == identity()}
    check(saved_moments == {datetime.fromisoformat(saved[key].replace("Z", "+00:00")).timestamp()
                           for key in ("publish_at", "expires_at")},
          "The calendar did not reflect the owner's actual saved expiration change.")
    stale = owner.post(editor_url(base), data=http.changed(old_values, expires_at="2030-02-28T01:00:00"), timeout=20)
    http.rejected_form(stale, "Stale expiration revision")
    no_writes(current, snapshot(site, php), "Stale expiration edit")
    failures = []
    for bad in ("2030-02-02T12:00:00", "2029-12-31T00:00:00", "2030-02-30T12:00:00", "2030-01-01",
                "2027-03-14T02:30:00", "2030-02-22T12:00:00Z", "not-a-date"):
        values, _ = current_fields(owner, base)
        attempted = http.changed(values, publish_at="2020-01-01T00:00:00" if bad.startswith("2027-") else dict(values)["publish_at"],
                                 expires_at=bad, title="Retain attempted expiry title")
        response = owner.post(editor_url(base), data=attempted, timeout=20)
        http.rejected_form(response, "Invalid expiry " + bad)
        check("Retain attempted expiry title" in response.text, "A rejected expiration edit discarded attempted content.")
        no_writes(current, snapshot(site, php), "Invalid expiration " + bad)
        failures.append(bad)
    values, _ = current_fields(owner, base)
    for invalid in (http.changed(values, expires_at=None, **{"expires_at[]": ["invalid"]}),
                    http.changed(values, _csrf="invalid")):
        response = owner.post(editor_url(base), data=invalid, timeout=20)
        http.rejected_form(response, "Malformed expiry/CSRF")
        no_writes(current, snapshot(site, php), "Malformed expiration or CSRF")
    values, _ = current_fields(owner, base, "fold-second")
    fold_before = record(snapshot(site, php), "fold-second")
    save(owner, base, values, "fold-second")
    fold_after = record(snapshot(site, php), "fold-second")
    check(fold_after["publish_at"] == fold_before["publish_at"] and fold_after["expires_at"] == fold_before["expires_at"],
          "An untouched repeated-hour form changed the stored absolute offsets.")
    values, _ = current_fields(owner, base, "legacy")
    save(owner, base, http.changed(values, expires_at=None, title="Calendar legacy title edit"), "legacy")
    check("expires_at" not in record(snapshot(site, php), "legacy"), "An omitted legacy field fabricated an expiry.")
    values, _ = current_fields(owner, base, "legacy")
    save(owner, base, http.changed(values, expires_at="2030-02-25T12:00:00"), "legacy")
    with_expiry = record(snapshot(site, php), "legacy")
    selected = next(entry for entry in with_expiry["editorial_history"]["entries"] if "expires_at" not in entry["snapshot"])
    review = owner.get(history_url(base, "legacy", selected["id"]), timeout=20)
    check(review.status_code == 200 and "Expires at" in review.text and "No expiry" in review.text,
          "An older snapshot without expiry did not clearly show No expiry in review.")
    restore = http.fields(review.text, "form[data-history-restore]")
    response = owner.post(editor_url(base, "legacy"), data=restore, timeout=20)
    check(response.status_code == 200 and response.history, "The actual no-expiry history restoration failed.")
    restored = record(snapshot(site, php), "legacy")
    check(restored["status"] == "draft" and "expires_at" not in restored, "Restoration retained a newer expiry or republished the old content.")
    values, _ = current_fields(owner, base)
    save(owner, base, http.changed(values, expires_at=""))
    check(record(snapshot(site, php)).get("expires_at") == "", "The owner could not deliberately clear expiration.")
    http.protected(before, snapshot(site, php))
    return {"saved_utc_expiry": saved["expires_at"], "invalid_expiries": failures, "stale_revision_refused": True,
            "csrf_shape_guards": True, "dst_fold_roundtrip_preserved": True, "omitted_legacy_field_preserved": True,
            "history_captures_expiry_and_restores_old_absence_as_draft": True, "blank_clears_expiry": True}


def owner_revocation(owner, base, site, php):
    before = snapshot(site, php)
    token = secrets.token_hex(8)
    # Reuse the guarded records lock: scene foreign keys would also lock admins
    # and block the independent owner revocation this test must exercise.
    release = site / ("storage/temp/editorial-history-records-" + token + ".release")
    worker = subprocess.Popen([php, str(history.FIXTURE), str(site), "records-lock"], stdin=subprocess.PIPE,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd="/tmp")
    try:
        worker.stdin.write(json.dumps({"token": token})); worker.stdin.close()
        check(bool(select.select([worker.stdout], [], [], 10)[0]), "The owned calendar record lock was not ready.")
        check(json.loads(worker.stdout.readline()).get("locked") is True, "The fixture failed to lock authoritative calendar records.")
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            pending = pool.submit(owner.get, calendar_url(base), timeout=25)
            observed = {}
            try:
                deadline = time.monotonic() + 10
                while not pending.done() and time.monotonic() < deadline:
                    observed = history.fixture(site, "records-wait", php, _count=False)
                    if observed["waiting_connections"]:
                        break
                    time.sleep(.02)
                check(len(observed.get("waiting_connections", [])) == 1 and not pending.done(),
                      "The actual calendar did not block on authoritative marketing_storage_records.")
                http.fixture(site, "owner-status", php, status="demoted")
            finally:
                release.write_text("Release the exclusively owned calendar record lock.\n")
            response = pending.result(timeout=25)
            check(response.status_code == 403 and "Calendar post" not in response.text
                  and "data-calendar-event=" not in response.text,
                  "A revoked owner received calendar data after an authoritative source read.")
            http.private(response)
    finally:
        if not release.exists(): release.write_text("Release the owned calendar record lock after failure.\n")
        try: worker.wait(timeout=8)
        except subprocess.TimeoutExpired:
            worker.terminate(); worker.wait(timeout=5)
        error = worker.stderr.read()
        check(worker.returncode == 0, "Owned calendar lock worker failed: " + error[:1000])
        worker.stdout.close(); worker.stderr.close()
        http.fixture(site, "owner-status", php, status="owner")
        release.unlink(missing_ok=True)
    no_writes(before, snapshot(site, php), "Owner revoked during an authoritative calendar source read")
    return {"observed_records_select_wait": True, "read_table": "marketing_storage_records", "owner_revoked_before_response": True,
            "http_status": 403, "exact_records_preserved": True}


def http_case(case):
    site, base, php = case["site"], case["base"], case["php"]
    started = http.checks
    owner, clients = http.owner_login(base), {"guest": http.session()}
    try:
        if case["mode"] == "mysql" and http.fixture(site, "read", php)["status"]["state"] == "ready":
            http.activate_mysql(owner, base, site, php)
        for name in ("free", "gold", "silver"):
            clients[name] = http.member_login(base, name)
        initial = snapshot(site, php)
        if case["mode"] == "mysql": http.independent_sql(initial)
        calendar_result = calendar_cases(owner, base, site, php)
        invalid = invalid_calendar(owner, clients["guest"], clients["free"], base, site, php)
        noninitializing = noninitializing_calendar(owner, base, site, php, case["mode"])
        public = expiry_public(clients, owner, base, site, php)
        write_authority = expiry_authority(owner, clients, base, site, php)
        edits = expiry_edits(owner, base, site, php)
        revocation = owner_revocation(owner, base, site, php) if case["mode"] == "mysql" else None
        final = snapshot(site, php)
        http.protected(initial, final)
        check(initial["metadata"] == final["metadata"] and initial["calendar"] == final["calendar"]
              and initial["marketing"]["events"] == final["marketing"]["events"],
              "Calendar/expiry operations changed media, scene history, marketing events or email state.")
        if case["mode"] == "mysql": http.independent_sql(final)
        check(case["capture"].connections == 0 and not case["capture"].payloads and not case["capture"].errors,
              "Calendar or expiration actions attempted SMTP.")
        return {"http_checks": http.checks - started, "mode": case["mode"], "mysql_version": final["mysql_version"],
                "site_timezone": final["site_timezone"], "sql_timezone": final["sql_timezone"],
                "calendar": calendar_result, "invalid_requests": invalid, "noninitializing": noninitializing,
                "public_expiry": public, "expiry_write_authority": write_authority, "expiry_editing": edits,
                "mysql_owner_revocation": revocation,
                "independent_sql_readback": case["mode"] == "mysql",
                "marketing_events_added": len(final["marketing"]["events"]) - len(initial["marketing"]["events"]),
                "existing_marketing_events_preserved": True,
                "smtp_connections": case["capture"].connections, "smtp_messages": len(case["capture"].payloads)}
    finally:
        owner.close()
        for client in clients.values(): client.close()


def prepare_package(work):
    archive, package = history.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(name in zipped.namelist() and zipped.read(name) == (ROOT / name).read_bytes() for name in NEW_RUNTIME),
              "The exact current calendar/expiry runtime is absent from the actual customer package.")
    package["calendar_archive_runtime_matches_sources"] = True
    package["evidence_schema"] = 1
    package["acceptance_sources"] = {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                                     for path in (Path(__file__).resolve(), FIXTURE, ROOT / "tests/Browser/editorial_calendar_playwright.py")}
    return archive, package


def evidence_directory():
    value = os.environ.get("VELVET_SCREENSHOT_DIR") or os.environ.get("VELVET_CALENDAR_EVIDENCE_DIR")
    if not value: return None
    path = Path(value).resolve()
    check(not path.is_relative_to(ROOT) or path.is_relative_to(ROOT / "artifacts"), "Calendar evidence must remain outside tracked runtime source.")
    path.mkdir(parents=True, exist_ok=True)
    return path


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("file", "mysql", "both"), default="mysql")
    options = parser.parse_args()
    evidence = evidence_directory()
    summary = {"report_kind": "editorial_calendar_http", "php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "server": "php -S without any router script", "playwright_required": False, "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-calendar-http-") as temporary:
        work = Path(temporary)
        archive, package = prepare_package(work)
        summary.update(package)
        for mode in (("file", "mysql") if options.mode == "both" else (options.mode,)):
            for path_label, prefix in (("root", ""), ("nested", "/nested/velvet")):
                label = mode + "-" + path_label
                with http.runtime(work, archive, mode, "calendar-" + label, prefix, evidence) as case:
                    seed(case)
                    summary["cases"][label] = http_case(case)
                print("Editorial Calendar HTTP " + label + ": PASS.", flush=True)
    if evidence:
        (evidence / "editorial-calendar-http-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial calendar actual-package HTTP acceptance passed.", flush=True)


if __name__ == "__main__":
    run()
