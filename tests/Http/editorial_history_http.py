#!/usr/bin/env python3
"""Actual-package editorial history and safe restoration, file and MySQL.

CI runs --mode mysql on both supported PHP versions. The browser producer
repeats this focused HTTP matrix in four independent customer installations.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import select
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlencode
from zipfile import ZipFile

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("vvx_history_metadata_http", ROOT / "tests/Http/editorial_metadata_http.py")
assert spec and spec.loader
metadata = importlib.util.module_from_spec(spec)
spec.loader.exec_module(metadata)
http, check = metadata.http, metadata.check
FIXTURE = Path(__file__).with_name("editorial_history_fixture.php")
KINDS = {"posts": "post", "banners": "banner", "landing_pages": "page"}
TABS = {"posts": "posts", "banners": "banners", "landing_pages": "campaigns"}
NEW_RUNTIME = ("app/Core/MarketingHistory.php", "app/Core/MarketingStore.php", "includes/marketing_admin.php",
               "admin/index.php", "app/Views/admin/marketing-history.php", "app/Views/admin/marketing-actions.php",
               "app/Views/admin/marketing-publication-editor.php", "app/Views/admin/marketing-publication-list.php",
               "public/assets/css/marketing.css")


def fixture(site: Path, action: str, php="php", _count=True, **values):
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=45, cwd="/tmp")
    message = "Guarded history fixture failed (" + action + "): " + result.stderr[:1800]
    if _count:
        check(result.returncode == 0, message)
    elif result.returncode != 0:
        raise AssertionError(message)
    return json.loads(result.stdout)


def seed(case):
    metadata.fixture(case["site"], "seed", case["php"])
    return fixture(case["site"], "seed", case["php"])


def identity(collection="posts", name="main"):
    return "history-" + KINDS[collection] + "-" + name


def editor_url(base, collection="posts", name="main"):
    return base + "/admin/?" + urlencode({"page": "marketing", "tab": TABS[collection],
                                          "edit_" + KINDS[collection]: identity(collection, name)})


def history_url(base, collection="posts", name="main", version=None):
    query = {"page": "marketing-history", "collection": collection, "id": identity(collection, name)}
    if version is not None:
        query["version"] = version
    return base + "/admin/?" + urlencode(query)


def restore_url(base, collection="posts"):
    return base + "/admin/?" + urlencode({"page": "marketing", "tab": TABS[collection]})


def preview_url(base, collection="posts", name="main"):
    return base + "/admin/?" + urlencode({"page": "marketing-preview", "collection": collection,
                                          "id": identity(collection, name)})


def public_url(base, collection="posts", name="main"):
    return http.route(base, ("blog/post.php?slug=" if collection == "posts" else "campaign/?slug=")
                      + identity(collection, name))


def snapshot(site, php):
    return metadata.snapshot(site, php)


def record(saved, collection="posts", name="main"):
    found = [row for row in saved["marketing"][collection] if row.get("id") == identity(collection, name)]
    check(len(found) == 1, "A reserved history publication is missing or duplicated.")
    return found[0]


def stored_entries(saved, collection="posts", name="main"):
    envelope = record(saved, collection, name).get("editorial_history", {"schema": 1, "entries": []})
    check(isinstance(envelope, dict) and envelope.get("schema") == 1 and isinstance(envelope.get("entries"), list),
          "The stored editorial history envelope is malformed.")
    entries = envelope["entries"]
    check(len(entries) <= 20 and len({row["id"] for row in entries}) == len(entries),
          "History retention or version identities are invalid.")
    for row in entries:
        check(re.fullmatch(r"[a-f0-9]{32}", row["id"]) is not None and row["timezone"] == "America/Chicago"
              and row["action"] in ("save", "visibility", "restore") and isinstance(row["source_edit_version"], int),
              "A history entry lacks its bounded identity, capture context or action.")
        check(not set(row["snapshot"]) & {"id", "slug", "created_at", "updated_at", "edit_version", "editorial_history",
                                           "history_unknown", "unknown_metadata", "legacy_unknown", "legacy_private_note"},
              "History copied identity, private unknown fields, or recursively nested history.")
    return entries


def no_writes(before, after, label):
    metadata.no_writes(before, after, label)


def current_fields(owner, base, collection="posts", name="main"):
    response = owner.get(editor_url(base, collection, name), timeout=20)
    check(response.status_code == 200, "The actual publication editor failed to load.")
    return http.fields(response.text, 'form[data-publication-editor="' + collection + '"]'), response


def save(owner, base, values, collection="posts", name="main"):
    response = owner.post(editor_url(base, collection, name), data=values, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == "GET"
          and not BeautifulSoup(response.text, "html.parser").select(".admin-alert.error"),
          "The actual publication save failed or omitted POST/Redirect/GET.")
    return response


def history(owner, base, collection="posts", name="main", version=None):
    response = owner.get(history_url(base, collection, name, version), timeout=25)
    check(response.status_code == 200, "The actual private history/review page did not load: " + response.text[:200])
    http.private(response, True)
    soup = BeautifulSoup(response.text, "html.parser")
    check(soup.select_one("[data-publication-history-list]") is not None
          and soup.select_one("[data-history-current]") is not None, "The history list/current summary is missing.")
    check("HISTORY_PRIVATE_UNKNOWN" not in response.text and "legacy_private_note" not in response.text,
          "History exposed unrelated private/unknown record fields.")
    check(not soup.select("[data-history-injection]"), "A historical string was interpreted as HTML.")
    if version is not None:
        check(soup.select_one("[data-history-review]") is not None
              and soup.select_one("form[data-history-restore]") is not None, "The selected version lacks its review/restore form.")
        check(not soup.select("[data-history-content] a[href], [data-history-content] iframe, "
                              "[data-history-content] script, [data-history-content] form"),
              "Historical content includes an active link, script or form.")
    return response, soup


def entry_ids(soup):
    return [row["data-history-entry"] for row in soup.select("[data-publication-history-list] [data-history-entry]")]


def restore_fields(owner, base, entry, collection="posts", name="main"):
    response, _ = history(owner, base, collection, name, entry)
    return http.fields(response.text, "form[data-history-restore]")


def restore(owner, base, values, collection="posts"):
    response = owner.post(restore_url(base, collection), data=values, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == "GET"
          and not BeautifulSoup(response.text, "html.parser").select(".admin-alert.error"),
          "A valid history restoration failed or omitted POST/Redirect/GET.")
    check('edit_' + KINDS[collection] + '=' in response.url, "Restoration did not return to the existing publication editor.")
    return response


def content_changes(collection, suffix):
    if collection == "banners":
        return {"text": "History changed banner " + suffix, "url": "/membership/"}
    return {"title": "History changed " + KINDS[collection] + " " + suffix,
            "body": "HISTORY_CURRENT_BODY_" + collection + "_" + suffix,
            "excerpt": "Current excerpt " + suffix, "seo_title": "Current search title " + suffix,
            "seo_description": "Current search description " + suffix,
            "featured_alt": "Current image description " + suffix}


def safe_state(row, collection):
    return row.get("status") == "draft" if collection == "posts" else row.get("enabled") is False


def exercise_collection(owner, guest, base, site, php, collection):
    before = snapshot(site, php)
    original = record(before, collection)
    response, soup = history(owner, base, collection)
    check(entry_ids(soup) == [] and not soup.select("form[data-history-restore]"), "Legacy history was fabricated before the first change.")
    no_writes(before, snapshot(site, php), "Opening legacy history")
    values, editor = current_fields(owner, base, collection)
    links = BeautifulSoup(editor.text, "html.parser").select("[data-publication-history]")
    check(any(identity(collection) in link.get("href", "") for link in links), "The existing editor/list lacks its history link.")
    save(owner, base, http.changed(values, **content_changes(collection, "first")), collection)
    first = snapshot(site, php)
    versions = stored_entries(first, collection)
    check(len(versions) == 1 and versions[0]["action"] == "save"
          and versions[0]["snapshot"]["text" if collection == "banners" else "body"]
          == original["text" if collection == "banners" else "body"], "First edit did not capture the real legacy baseline.")
    baseline_id = versions[0]["id"]
    response, soup = history(owner, base, collection, version=baseline_id)
    check(entry_ids(soup) == [baseline_id], "The history UI differs from saved newest-first versions.")
    no_writes(first, snapshot(site, php), "Reviewing an earlier version")
    expected_events = first["marketing"]["events"]
    if collection != "banners":
        check("HISTORY_PRIOR_BODY_" + collection + "_main" in soup.select_one("[data-history-selected]").get_text(),
              "Review did not show the selected saved historical body.")
        if collection == "posts":
            check(all("Metadata post " + name in soup.select_one("[data-history-selected]").get_text()
                      for name in ("public-a", "public-b")),
                  "History did not resolve current related titles from its read-only snapshot.")
        public = guest.get(public_url(base, collection), timeout=20)
        check(public.status_code == 200 and "HISTORY_CURRENT_BODY_" + collection + "_first" in public.text
              and "HISTORY_PRIOR_BODY_" + collection + "_main" not in public.text and "editorial_history" not in public.text,
              "The public publication leaked the stored historical body or envelope.")
        public_state = snapshot(site, php)
        public_events = public_state["marketing"]["events"]
        if collection == "landing_pages":
            check(public_events[:-1] == expected_events and public_events[-1]["name"] == "campaign_view"
                  and public_events[-1]["campaign"] == identity(collection),
                  "The explicit public landing-page control did not create its one expected campaign view.")
            expected_events = public_events
        else:
            no_writes(first, public_state, "Public post history-disclosure control")
    unchanged, _ = current_fields(owner, base, collection)
    save(owner, base, unchanged, collection)
    check([row["id"] for row in stored_entries(snapshot(site, php), collection)] == [baseline_id],
          "Saving unchanged editor content generated duplicate history.")
    fixture(site, "current-unknown", php, collection=collection)
    values, _ = current_fields(owner, base, collection)
    changes = content_changes(collection, "second") | {"access_mode": "members", "access_plan_ids[]": []}
    if collection == "posts":
        changes |= {"publish_at": "2035-01-01T12:00", "tags": "Changed, Studio", "author_name": "Changed byline",
                    "related_post_ids[]": ["metadata-post-public-a", "metadata-post-public-b"]}
    else:
        changes |= {"starts_at": "2035-01-01T12:00", "ends_at": "2035-02-01T12:00"}
    save(owner, base, http.changed(values, **changes), collection)
    before_restore = snapshot(site, php)
    current = record(before_restore, collection)
    check(len(stored_entries(before_restore, collection)) == 2, "The second content change did not create exactly one version.")
    request = restore_fields(owner, base, baseline_id, collection)
    # Server-held snapshots are authoritative, including visibility. Forged
    # extra body/status/identity inputs cannot broaden a restoration.
    forged = http.changed(request, title="UNTRUSTED_RESTORE_TITLE", body="UNTRUSTED_RESTORE_BODY", status="published",
                          enabled="1", slug="untrusted-permalink", access_mode="public")
    restore(owner, base, forged, collection)
    after = snapshot(site, php)
    result = record(after, collection)
    check(safe_state(result, collection), "Restoring an older version republished or re-enabled content.")
    if collection != "banners":
        denied = guest.get(public_url(base, collection), timeout=20)
        check(denied.status_code == 404 and "HISTORY_PRIOR_BODY" not in denied.text,
              "A restored draft/disabled publication was publicly disclosed.")
        http.private(denied)
    for key in ("id", "slug", "created_at", "history_unknown"):
        check(result.get(key) == current.get(key), "Restore replaced the current identity/unknown field: " + key)
    check([row["id"] for row in after["marketing"][collection]] == [row["id"] for row in before["marketing"][collection]],
          "Restore changed the existing saved record order.")
    excluded = {"editorial_history", "edit_version", "updated_at", "history_unknown", "status", "enabled"}
    for key in original.keys() - excluded:
        expected = "" if key in ("publish_at", "starts_at", "ends_at") and original[key] is None else original[key]
        check(result.get(key) == expected, "Restore did not recover the old editable value (with canonical empty dates): " + key)
    restored_entries = stored_entries(after, collection)
    check(len(restored_entries) == 3 and restored_entries[0]["action"] == "restore"
          and restored_entries[0]["snapshot"]["text" if collection == "banners" else "body"]
          == current["text" if collection == "banners" else "body"], "Restore did not capture the displaced content for undo.")
    http.protected(before, after)
    check(expected_events == after["marketing"]["events"], "History editing/review/restore recorded marketing events.")
    stale = owner.post(restore_url(base, collection), data=request, allow_redirects=False, timeout=20)
    http.rejected_form(stale, "Replayed old restoration")
    no_writes(after, snapshot(site, php), "Replayed restoration")
    undo = restore_fields(owner, base, restored_entries[0]["id"], collection)
    restore(owner, base, undo, collection)
    undone = record(snapshot(site, php), collection)
    check(safe_state(undone, collection)
          and undone["text" if collection == "banners" else "body"] == current["text" if collection == "banners" else "body"],
          "A restoration could not itself be undone into a safe unpublished state.")
    return {"legacy_baseline_version": baseline_id, "legacy_first_edit_captured": True, "readonly_escaped_review": True,
            "noop_save_no_duplicate": True, "restore_same_identity_and_current_unknown_fields": True,
            "forged_content_ignored": True, "restore_unpublished": True, "restore_archived_and_undoable": True,
            "stale_restore_rejected": True}


def rejected_requests(owner, guest, member, base, site, php):
    versions = stored_entries(snapshot(site, php))
    valid = restore_fields(owner, base, versions[0]["id"])
    attempts = [
        ("invalid CSRF", http.changed(valid, _csrf="invalid")),
        ("missing CSRF", http.changed(valid, _csrf=None)),
        ("invalid revision", http.changed(valid, publication_revision="0" * 64)),
        ("missing revision", http.changed(valid, publication_revision=None)),
        ("missing version", http.changed(valid, history_id=None)),
        ("unknown version", http.changed(valid, history_id="0" * 32)),
        ("malformed version", http.changed(valid, history_id="../private")),
        ("array version", http.changed(valid, history_id=None, **{"history_id[]": versions[0]["id"]})),
        ("unsupported collection", http.changed(valid, collection="campaign_queue")),
        ("wrong publication", http.changed(valid, id="history-post-guarded")),
    ]
    for label, values in attempts:
        before = snapshot(site, php)
        response = owner.post(restore_url(base), data=values, allow_redirects=False, timeout=20)
        check(response.status_code in (200, 400, 403, 409, 419, 422) and not response.is_redirect
              and (response.status_code != 200 or BeautifulSoup(response.text, "html.parser").select(".admin-alert.error")),
              "An invalid restoration was accepted: " + label)
        no_writes(before, snapshot(site, php), label)
    for label, client in (("guest", guest), ("member", member)):
        before = snapshot(site, php)
        for method, url, kwargs in (("get", history_url(base), {}), ("get", history_url(base, version=versions[0]["id"]), {}),
                                    ("post", restore_url(base), {"data": valid})):
            response = getattr(client, method)(url, allow_redirects=False, timeout=20, **kwargs)
            check(response.status_code in (302, 303, 403), label + " gained owner history access.")
            check("HISTORY_PRIOR_BODY" not in response.text and "HISTORY_CURRENT_BODY" not in response.text,
                  label + " was shown historical publication content.")
        no_writes(before, snapshot(site, php), label + " history refusal")
    malformed_queries = ["collection=campaign_queue&id=history-post-main", "collection[]=posts&id=history-post-main",
                         "collection=posts&id[]=history-post-main", "collection=posts&id=../private",
                         "collection=posts&id=history-post-main&version[]=bad", "collection=posts&id=history-post-main&version=" + "0" * 32]
    for query in malformed_queries:
        before = snapshot(site, php)
        response = owner.get(base + "/admin/?page=marketing-history&" + query, timeout=20)
        check(response.status_code in (400, 404) and "HISTORY_PRIOR_BODY" not in response.text,
              "An invalid history selection disclosed saved content.")
        http.private(response, True)
        no_writes(before, snapshot(site, php), "Invalid history query")
    before = snapshot(site, php)
    for method in ("post", "put", "delete"):
        response = getattr(owner, method)(history_url(base), timeout=20, allow_redirects=False)
        check(response.status_code == 405 and response.headers.get("Allow") == "GET", "The private history route accepted " + method)
        http.private(response, True)
    no_writes(before, snapshot(site, php), "Wrong history HTTP methods")
    return {"invalid_restore_vectors": [label for label, _ in attempts], "guest_and_member_denied": True,
            "invalid_history_queries": len(malformed_queries), "history_get_only": True}


def reference_revalidation(owner, base, site, php):
    values, _ = current_fields(owner, base, name="guarded")
    save(owner, base, http.changed(values, **content_changes("posts", "guarded")), name="guarded")
    version = stored_entries(snapshot(site, php), name="guarded")[0]["id"]
    request = restore_fields(owner, base, version, name="guarded")
    cases = []
    for label, change, recover in (
        ("unavailable tier", lambda: http.fixture(site, "plan-state", php, plan="gold", state="disabled"),
         lambda: http.fixture(site, "plan-state", php, plan="gold", state="active")),
        ("now private image", lambda: metadata.fixture(site, "media-state", php, name="a", state="private"),
         lambda: metadata.fixture(site, "media-state", php, name="a", state="restored")),
        ("missing image bytes", lambda: metadata.fixture(site, "media-state", php, name="a", state="missing"),
         lambda: metadata.fixture(site, "media-state", php, name="a", state="file-restored")),
        ("removed related post", lambda: metadata.fixture(site, "related-state", php, name="public-a", state="remove"),
         lambda: metadata.fixture(site, "related-state", php, name="public-a", state="restore")),
    ):
        change()
        try:
            before = snapshot(site, php)
            response = owner.post(restore_url(base), data=request, timeout=20, allow_redirects=False)
            http.rejected_form(response, "Restore with " + label)
            no_writes(before, snapshot(site, php), "Restore with " + label)
        finally:
            recover()
        cases.append(label)
    # Another item's disappearance must not poison this item's revision once
    # the current referenced state is valid again.
    restore(owner, base, restore_fields(owner, base, version, name="guarded"))
    check(record(snapshot(site, php), name="guarded")["status"] == "draft", "Valid reference recovery did not permit safe restoration.")
    return {"current_references_rechecked": cases, "valid_recovery_restores_draft": True}


def noninitializing_reads(owner, base, site, php, mode):
    selected = stored_entries(snapshot(site, php))[0]["id"]
    cases = []
    original = fixture(site, "read-artifacts", php)
    check(original["files"]["data.lock"]["mode"] == 0o640, "The owned fixture did not create its expected private lock mode.")
    fixture(site, "read-lock-mode", php, mode=0o440)
    try:
        before = fixture(site, "read-artifacts", php)
        storage_before = http.fixture(site, "storage-files", php)
        for version in (None, selected):
            history(owner, base, version=version)
        check(fixture(site, "read-artifacts", php) == before and before["files"]["data.lock"]["mode"] == 0o440,
              "A history list/review changed the existing read-only lock's mode or stored bytes.")
        check(http.fixture(site, "storage-files", php) == storage_before, "Read-only lock history access rewrote file/SQL state.")
    finally:
        fixture(site, "read-lock-mode", php, mode=original["files"]["data.lock"]["mode"])
    cases.append({"existing_lock_mode": "0440", "list_and_review_preserved_bytes_and_mode": True})
    for name in (("data.lock", "sql-cutover.json") if mode == "mysql" else ("data.lock",)):
        fixture(site, "hold-read-artifact", php, name=name)
        try:
            before = fixture(site, "read-artifacts", php)
            storage_before = http.fixture(site, "storage-files", php)
            for version in (None, selected):
                history(owner, base, version=version)
            after = fixture(site, "read-artifacts", php)
            check(before == after and after["files"][name] is None,
                  "A read-only history request recreated or changed the absent " + name)
            check(storage_before == http.fixture(site, "storage-files", php),
                  "A history list/review initialized marketing files or rewrote SQL state.")
        finally:
            fixture(site, "restore-read-artifact", php, name=name)
        cases.append({"missing_artifact": name, "list_and_review_succeeded_without_initialization": True})
    return cases


def retention_and_withdrawal(owner, base, site, php):
    first_version = None
    for number in range(22):
        values, _ = current_fields(owner, base, name="retention")
        save(owner, base, http.changed(values, title="History retention edit " + str(number)), name="retention")
        entries = stored_entries(snapshot(site, php), name="retention")
        if first_version is None:
            first_version = entries[0]["id"]
        check(len(entries) == min(number + 1, 20), "The actual save route did not retain exactly the newest twenty prior versions.")
    before = snapshot(site, php)
    response, soup = history(owner, base, name="retention")
    check(len(entry_ids(soup)) == 20 and first_version not in entry_ids(soup), "The history UI retained an expired version.")
    missing = owner.get(history_url(base, name="retention", version=first_version), timeout=20)
    check(missing.status_code == 404, "A pruned history version was still reviewable.")
    no_writes(before, snapshot(site, php), "Review pruned history")
    fixture(site, "malformed-history", php)
    before = snapshot(site, php)
    malformed = record(before, name="malformed")["editorial_history"]
    response = owner.get(history_url(base, name="malformed"), timeout=20)
    check(response.status_code == 404 and "HISTORY_MALFORMED_PRIVATE" not in response.text,
          "Malformed history was disclosed or treated as valid history.")
    values, _ = current_fields(owner, base, name="malformed")
    failed = owner.post(editor_url(base, name="malformed"), data=http.changed(values, title="Must not save"),
                        timeout=20, allow_redirects=False)
    http.rejected_form(failed, "Normal save with malformed history")
    no_writes(before, snapshot(site, php), "Malformed history refusal")
    editor = owner.get(editor_url(base, name="malformed"), timeout=20)
    withdrawal = http.fields(editor.text, '[data-publication-id="history-post-malformed"] form:has(input[value="set_publication_enabled"])')
    response = owner.post(editor_url(base, name="malformed"), data=http.changed(withdrawal, enabled="0"), timeout=20)
    check(response.status_code == 200 and response.history, "Malformed history prevented safe withdrawal.")
    withdrawn = record(snapshot(site, php), name="malformed")
    check(withdrawn["status"] == "draft" and withdrawn["editorial_history"] == malformed,
          "Safe withdrawal changed malformed history or failed to hide the publication.")
    values, _ = current_fields(owner, base, name="delete")
    save(owner, base, http.changed(values, title="History deletion candidate"), name="delete")
    deleted_version = stored_entries(snapshot(site, php), name="delete")[0]["id"]
    _, editor = current_fields(owner, base, name="delete")
    deletion = http.fields(editor.text, '[data-publication-id="history-post-delete"] form:has(input[value="delete_publication"])')
    response = owner.post(editor_url(base, name="delete"), data=deletion, timeout=20)
    check(response.status_code == 200 and response.history, "The existing revision-checked delete action failed.")
    after = snapshot(site, php)
    check(not any(row.get("id") == "history-post-delete" for row in after["marketing"]["posts"]), "Deleted content/history still has a record.")
    for version in (None, deleted_version):
        check(owner.get(history_url(base, name="delete", version=version), timeout=20).status_code == 404,
              "A deleted publication was recoverable through its removed history.")
    return {"actual_saves": 22, "newest_prior_version_limit": 20, "pruned_version_unavailable": True,
            "malformed_history_refuses_edit_allows_safe_withdrawal": True, "delete_hides_active_record_and_history_route": True}


def owner_revocation(owner, base, site, php):
    results = []
    selected = stored_entries(snapshot(site, php))[0]["id"]
    for action, status in (("list", "demoted"), ("review", "inactive"), ("restore", "demoted")):
        values = restore_fields(owner, base, selected)
        before = snapshot(site, php)
        token = secrets.token_hex(8)
        release = site / ("storage/temp/editorial-history-records-" + token + ".release")
        worker = subprocess.Popen([php, str(FIXTURE), str(site), "records-lock"], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd="/tmp")
        try:
            worker.stdin.write(json.dumps({"token": token}))
            worker.stdin.close()
            check(bool(select.select([worker.stdout], [], [], 10)[0]), "The owned history table lock did not become ready.")
            ready = worker.stdout.readline()
            check(bool(ready) and json.loads(ready).get("locked") is True, "The fixture did not acquire its real records WRITE lock.")
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                pending = (pool.submit(owner.post, restore_url(base), data=values, timeout=25) if action == "restore"
                           else pool.submit(owner.get, history_url(base, version=selected if action == "review" else None), timeout=25))
                try:
                    observed = {}
                    deadline = time.monotonic() + 10
                    while not pending.done() and time.monotonic() < deadline:
                        observed = fixture(site, "records-wait", php, _count=False)
                        if observed["waiting_connections"]:
                            break
                        time.sleep(.02)
                    check(len(observed.get("waiting_connections", [])) == 1 and not pending.done(),
                          "The real history " + action + " did not block on authoritative SQL records: " + repr(observed))
                    http.fixture(site, "owner-status", php, status=status)
                finally:
                    release.write_text("Release the exclusively owned history records lock.\n")
                response = pending.result(timeout=25)
                check(response.status_code == 403, "An owner revoked during the history read completed " + action)
                check("HISTORY_PRIOR_BODY" not in response.text and "HISTORY_CURRENT_BODY" not in response.text,
                      "History disclosed content after current ownership was revoked.")
                if action != "restore":
                    http.private(response, True)
        finally:
            if not release.exists():
                release.write_text("Release the owned history lock after failure.\n")
            try:
                worker.wait(timeout=8)
            except subprocess.TimeoutExpired:
                worker.terminate()
                worker.wait(timeout=5)
            error = worker.stderr.read()
            check(worker.returncode == 0, "The owned history lock worker failed: " + error[:1000])
            worker.stdout.close()
            worker.stderr.close()
            http.fixture(site, "owner-status", php, status="owner")
            release.unlink(missing_ok=True)
        no_writes(before, snapshot(site, php), "Owner revoked during authoritative history read")
        if status == "inactive":
            check(owner.get(history_url(base, "posts"), allow_redirects=False, timeout=20).status_code == 403,
                  "Restoring the inactive account revived its revoked history session.")
            http.owner_login(base, owner)
        results.append({"action": action, "owner_change": status, "observed_records_select_wait": True,
                        "http_status": 403, "exact_state_preserved": True})
    return results


def http_case(case):
    site, base, php = case["site"], case["base"], case["php"]
    started = http.checks
    owner, guest = http.owner_login(base), http.session()
    member = None
    try:
        if case["mode"] == "mysql" and http.fixture(site, "read", php)["status"]["state"] == "ready":
            http.activate_mysql(owner, base, site, php)
        initial = snapshot(site, php)
        member = http.member_login(base, "free")
        if case["mode"] == "mysql":
            http.independent_sql(initial)
        collections = {collection: exercise_collection(owner, guest, base, site, php, collection) for collection in KINDS}
        invalid = rejected_requests(owner, guest, member, base, site, php)
        references = reference_revalidation(owner, base, site, php)
        noninitializing = noninitializing_reads(owner, base, site, php, case["mode"])
        waits = owner_revocation(owner, base, site, php) if case["mode"] == "mysql" else []
        retention = retention_and_withdrawal(owner, base, site, php)
        final = snapshot(site, php)
        http.protected(initial, final)
        final_events = final["marketing"]["events"]
        check(initial["metadata"] == final["metadata"] and final_events[:-1] == initial["marketing"]["events"]
              and final_events[-1]["name"] == "campaign_view" and final_events[-1]["campaign"] == identity("landing_pages"),
              "History work changed original media or created events beyond the single explicit public landing-page control.")
        if case["mode"] == "mysql":
            http.independent_sql(final)
        capture = case["capture"]
        check(capture.connections == 0 and not capture.payloads and not capture.errors, "History work contacted SMTP or sent mail.")
        return {"http_checks": http.checks - started, "mode": case["mode"], "mysql_version": final["mysql_version"],
                "site_timezone": final["site_timezone"], "sql_timezone": final["sql_timezone"],
                "collections": collections, "invalid_requests": invalid, "reference_revalidation": references,
                "readonly_missing_storage_artifacts": noninitializing,
                "mysql_owner_revocation_during_record_reads": waits, "retention_and_delete": retention,
                "independent_sql_payload_readback": case["mode"] == "mysql", "protected_records_queues_consent_and_media_preserved": True,
                "explicit_public_control_campaign_views": 1, "owner_history_marketing_events": 0,
                "smtp_connections": capture.connections, "smtp_messages": len(capture.payloads)}
    finally:
        owner.close()
        guest.close()
        if member is not None:
            member.close()


def prepare_package(work):
    archive, package = metadata.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(name in zipped.namelist() and zipped.read(name) == (ROOT / name).read_bytes() for name in NEW_RUNTIME),
              "The exact current history runtime is absent from the actual customer package.")
    package["history_archive_runtime_matches_sources"] = True
    package["evidence_schema"] = 1
    return archive, package


def evidence_directory():
    value = os.environ.get("VELVET_SCREENSHOT_DIR") or os.environ.get("VELVET_HISTORY_EVIDENCE_DIR")
    if not value:
        return None
    path = Path(value).resolve()
    check(not path.is_relative_to(ROOT) or path.is_relative_to(ROOT / "artifacts"), "History evidence must remain outside tracked runtime source.")
    path.mkdir(parents=True, exist_ok=True)
    return path


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("file", "mysql", "both"), default="mysql")
    options = parser.parse_args()
    evidence = evidence_directory()
    summary = {"php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "server": "php -S without any router script", "playwright_required": False, "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-history-http-") as temporary:
        work = Path(temporary)
        archive, package = prepare_package(work)
        summary.update(package)
        for mode in (("file", "mysql") if options.mode == "both" else (options.mode,)):
            for path_label, prefix in (("root", ""), ("nested", "/nested/velvet")):
                label = mode + "-" + path_label
                with http.runtime(work, archive, mode, "history-" + label, prefix, evidence) as case:
                    seed(case)
                    summary["cases"][label] = http_case(case)
                print("Editorial History HTTP " + label + ": PASS.", flush=True)
    if evidence:
        (evidence / "editorial-history-http-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial history actual-package HTTP acceptance passed.", flush=True)


if __name__ == "__main__":
    run()
