#!/usr/bin/env python3
"""Actual-package deleted editorial recovery on file/MySQL, root and nested installs."""
from __future__ import annotations

import argparse
import concurrent.futures
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
from urllib.parse import urlencode
from zipfile import ZipFile

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("vvx_recovery_history_http", ROOT / "tests/Http/editorial_history_http.py")
assert spec and spec.loader
history = importlib.util.module_from_spec(spec)
spec.loader.exec_module(history)
http, check = history.http, history.check
FIXTURE = Path(__file__).with_name("editorial_recovery_fixture.php")
NEW_RUNTIME = ("app/Core/MarketingRecovery.php", "app/Core/MarketingStore.php", "admin/index.php",
               "app/Support/marketing_recovery_admin.php", "app/Views/admin/marketing.php",
               "app/Views/admin/marketing-deleted.php", "app/Views/admin/marketing-deleted-actions.php",
               "app/Views/admin/marketing-publication-list.php", "app/Views/admin/marketing-actions.php",
               "public/assets/css/marketing-deleted.css")


def fixture(site, action, php="php", **values):
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            capture_output=True, text=True, timeout=40, cwd="/tmp")
    check(result.returncode == 0, "Guarded recovery fixture failed (" + action + "): " + result.stderr[:1800])
    return json.loads(result.stdout)


def url(base, collection=None, entry=None):
    query = {"page": "marketing", "tab": "deleted"}
    if collection is not None:
        query["collection"] = collection
    if entry is not None:
        query["item"] = entry
    return base + "/admin/?" + urlencode(query)


def entries(saved):
    value = saved["marketing"].get("editorial_recovery", {"schema": 1, "entries": []})
    check(value["schema"] == 1 and isinstance(value["entries"], list) and len(value["entries"]) <= 50,
          "The actual archive envelope exceeds its type/count bound.")
    return value["entries"]


def selected(saved, collection="posts", name="main"):
    found = [entry for entry in entries(saved) if entry["collection"] == collection
             and entry["record"]["id"] == history.identity(collection, name)]
    check(len(found) == 1, "The exact deleted fixture record is missing or duplicated.")
    return found[0]


def review(owner, base, collection=None, entry=None):
    response = owner.get(url(base, collection, entry), timeout=25)
    check(response.status_code == 200, "Deleted content did not load: " + response.text[:200])
    http.private(response, True)
    soup = BeautifulSoup(response.text, "html.parser")
    check(soup.select_one("[data-editorial-recovery]") is not None, "The owner recovery workspace is missing.")
    check("HISTORY_PRIVATE_UNKNOWN" not in response.text and "history_unknown" not in response.text,
          "Recovery review exposed unrelated private record fields.")
    check(not soup.select("[data-history-injection]"), "Saved content was interpreted as HTML.")
    if entry is not None:
        check(soup.select_one("[data-recovery-review]") is not None and soup.select_one("form[data-recovery-purge]") is not None,
              "The selected deleted item lacks its private review and deliberate removal controls.")
        check(not soup.select("[data-recovery-content] a[href], [data-recovery-content] iframe, "
                              "[data-recovery-content] script, [data-recovery-content] form"),
              "Saved review content contains an active link, form or executable HTML.")
    return response, soup


def delete(owner, base, collection="posts", name="main"):
    response = owner.get(history.editor_url(base, collection, name), timeout=20)
    values = http.fields(response.text, '[data-publication-id="' + history.identity(collection, name) + '"] [data-publication-delete]')
    response = owner.post(history.editor_url(base, collection, name), data=values, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == "GET"
          and "moved to Deleted content" in response.text and not BeautifulSoup(response.text, "html.parser").select(".admin-alert.error"),
          "The actual recoverable removal control failed or omitted POST/Redirect/GET.")
    return values


def restore(owner, base, collection, entry, extra=None):
    response, _ = review(owner, base, collection, entry)
    values = http.fields(response.text, "form[data-recovery-restore]")
    if extra:
        values = http.changed(values, **extra)
    response = owner.post(url(base, collection, entry), data=values, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == "GET"
          and "edit_" + history.KINDS[collection] + "=" in response.url
          and not BeautifulSoup(response.text, "html.parser").select(".admin-alert.error"),
          "The actual recovery control failed to return an unpublished item to its editor.")
    return response, values


def collection_case(owner, guest, case, collection):
    site, base, php = case["site"], case["base"], case["php"]
    values, _ = history.current_fields(owner, base, collection)
    history.save(owner, base, http.changed(values, **history.content_changes(collection, "recovery")), collection)
    before = history.snapshot(site, php)
    original = history.record(before, collection)
    positions = [row["id"] for row in before["marketing"][collection]]
    old_history = original["editorial_history"]
    delete(owner, base, collection)
    deleted = history.snapshot(site, php)
    entry = selected(deleted, collection)
    check(entry["record"] == original and entry["record"]["editorial_history"] == old_history
          and entry["position"] == positions.index(original["id"]), "Removal lost exact content/history or saved order.")
    check(not any(row.get("id") == original["id"] for row in deleted["marketing"][collection]),
          "The deleted item still exists in the active collection.")
    for endpoint in (history.preview_url(base, collection), history.history_url(base, collection)):
        check(owner.get(endpoint, timeout=20).status_code == 404, "A deleted item remained reachable through its active preview/history URL.")
    if collection != "banners":
        public = guest.get(history.public_url(base, collection), timeout=20)
        check(public.status_code == 404 and original["body"] not in public.text, "A deleted post/page remained public.")
    response, soup = review(owner, base, collection, entry["id"])
    check(original["text" if collection == "banners" else "body"] in soup.select_one("[data-recovery-content]").get_text(),
          "The private saved review omitted the actual deleted content.")
    no_read_before = history.snapshot(site, php)
    review(owner, base)
    review(owner, base, collection, entry["id"])
    history.no_writes(no_read_before, history.snapshot(site, php), "Deleted list/review")
    if case["mode"] == "mysql":
        http.independent_sql(deleted)
        check("editorial_recovery" in json.loads(deleted["sql"]["marketing_storage_state"][0]["header_json"]),
              "SQL recovery was not committed with its exact header/layout snapshot.")
    restored_response, stale = restore(owner, base, collection, entry["id"],
        {"status": "published", "enabled": "1", "title": "Do not trust submitted content", "slug": "replacement-slug",
         "body": "Injected replacement", "access_mode": "public"})
    after = history.snapshot(site, php)
    row = history.record(after, collection)
    check(history.safe_state(row, collection) and row["text" if collection == "banners" else "body"] == original["text" if collection == "banners" else "body"],
          "Restore trusted submitted content/visibility instead of the saved private record.")
    check(all(row.get(key) == original.get(key) for key in ("id", "slug", "created_at", "history_unknown", "access"))
          and [item["id"] for item in after["marketing"][collection]] == positions, "Recovery changed identity, audience, unknown fields or order.")
    check(not any(item["id"] == entry["id"] for item in entries(after)), "Restoration left a second recoverable copy.")
    prior = row["editorial_history"]["entries"]
    check(prior[1:] == old_history["entries"] and prior[0]["action"] == "restore", "Recovery lost the retained prior versions or the previous publication.")
    replay = owner.post(url(base, collection, entry["id"]), data=stale, timeout=20, allow_redirects=False)
    check(replay.status_code == 409 and not BeautifulSoup(replay.text, "html.parser").select("form[data-recovery-restore]"),
          "An already restored item was restored again or received a replacement revision.")
    history.no_writes(after, history.snapshot(site, php), "Replayed recovery")
    if collection != "banners":
        check(guest.get(history.public_url(base, collection), timeout=20).status_code == 404, "A recovered draft/disabled page became public.")
    http.protected(before, after)
    return {"exact_saved_record_and_history_archived": True, "same_identity_permalink_audience_and_order": True,
            "restored_offline_from_server_record": True, "active_preview_history_public_hidden_while_deleted": True,
            "replay_refused_without_duplicate": True, "sql_header_parity": case["mode"] == "mysql"}


def rejected_requests(owner, guest, member, case, entry):
    site, base, php = case["site"], case["base"], case["php"]
    response, _ = review(owner, base, "posts", entry["id"])
    valid = http.fields(response.text, "form[data-recovery-restore]")
    purge = http.fields(response.text, "form[data-recovery-purge]")
    attempts = [
        ("invalid CSRF", http.changed(valid, _csrf="invalid")), ("missing CSRF", http.changed(valid, _csrf=None)),
        ("invalid revision", http.changed(valid, recovery_revision="0" * 64)),
        ("missing revision", http.changed(valid, recovery_revision=None)),
        ("unknown item", http.changed(valid, recovery_id="0" * 32)),
        ("malformed item", http.changed(valid, recovery_id="../private")),
        ("array item", http.changed(valid, recovery_id=None, **{"recovery_id[]": entry["id"]})),
        ("wrong type", http.changed(valid, collection="banners")),
        ("foreign type", http.changed(valid, collection="campaign_queue")),
        ("foreign action", http.changed(valid, action="run_campaign_batch")),
        ("permanent removal without confirmation", purge),
        ("array confirmation", http.changed(purge, **{"confirm_permanent[]": "1"})),
    ]
    for label, values in attempts:
        before = history.snapshot(site, php)
        refused = owner.post(url(base, "posts", entry["id"]), data=values, timeout=25, allow_redirects=False)
        check(refused.status_code in (400, 409, 419, 422) and not refused.is_redirect,
              "An invalid recovery action was accepted: " + label)
        check(not BeautifulSoup(refused.text, "html.parser").select("form[data-recovery-restore], form[data-recovery-purge]"),
              "A rejected recovery form silently acquired a new mutation form: " + label)
        history.no_writes(before, history.snapshot(site, php), label)
    for client, label in ((guest, "guest"), (member, "member")):
        before = history.snapshot(site, php)
        for endpoint in (url(base), url(base, "posts", entry["id"])):
            denied = client.get(endpoint, timeout=20, allow_redirects=False)
            check(denied.status_code == 403 and "HISTORY_PRIOR_BODY" not in denied.text, label + " read private recovery content.")
            http.private(denied, True)
        for values in (valid, http.changed(purge, confirm_permanent="1")):
            denied = client.post(url(base, "posts", entry["id"]), data=values, timeout=20, allow_redirects=False)
            check(denied.status_code == 403, label + " changed deleted content.")
        history.no_writes(before, history.snapshot(site, php), label + " recovery refusal")
    before = history.snapshot(site, php)
    for method in ("PUT", "PATCH", "DELETE", "HEAD"):
        denied = owner.request(method, url(base), timeout=20, allow_redirects=False)
        check(denied.status_code == 405 and denied.headers.get("Allow") == "GET, POST", "Recovery accepted " + method)
    for endpoint in (base + "/admin/?page=marketing&tab=posts", base + "/admin/?page=settings"):
        denied = owner.post(endpoint, data=valid, timeout=20, allow_redirects=False)
        check(denied.status_code == 400, "A foreign admin page dispatched a recovery action.")
    for query in ("collection[]=posts", "collection=campaigns", "collection=posts&item[]=invalid", "item=" + entry["id"],
                  "collection=posts&item=../private", "collection=posts&item=" + "0" * 32):
        denied = owner.get(url(base) + "&" + query, timeout=20)
        check(denied.status_code in (400, 404) and "HISTORY_PRIOR_BODY" not in denied.text, "A malformed recovery query exposed content.")
    history.no_writes(before, history.snapshot(site, php), "Wrong methods, queries and foreign dispatch")
    for status in ("demoted", "inactive"):
        before = history.snapshot(site, php)
        http.fixture(site, "owner-status", php, status=status)
        try:
            for endpoint in (url(base), url(base, "posts", entry["id"])):
                check(owner.get(endpoint, timeout=20).status_code == 403, "A stale owner session read deleted content.")
            check(owner.post(url(base, "posts", entry["id"]), data=valid, timeout=20).status_code == 403,
                  "A revoked owner session restored content.")
        finally:
            http.fixture(site, "owner-status", php, status="owner")
        history.no_writes(before, history.snapshot(site, php), "Revoked owner recovery refusal")
    return {"invalid_vectors": [label for label, _ in attempts], "guest_member_demoted_inactive_denied": True,
            "method_and_foreign_dispatch_guards": True, "failed_post_requires_explicit_reload": True}


def readonly(owner, case, entry):
    site, base, php = case["site"], case["base"], case["php"]
    cases = []
    history.fixture(site, "read-lock-mode", php, mode=0o440)
    try:
        before = http.fixture(site, "storage-files", php)
        review(owner, base); review(owner, base, "posts", entry["id"])
        check(before == http.fixture(site, "storage-files", php), "Read-only recovery rewrote marketing file/SQL state.")
        check(history.fixture(site, "read-artifacts", php)["files"]["data.lock"]["mode"] == 0o440,
              "Recovery changed a read-only lock's permissions.")
    finally:
        history.fixture(site, "read-lock-mode", php, mode=0o640)
    for name in (("data.lock", "sql-cutover.json") if case["mode"] == "mysql" else ("data.lock",)):
        history.fixture(site, "hold-read-artifact", php, name=name)
        try:
            before = http.fixture(site, "storage-files", php)
            review(owner, base); review(owner, base, "posts", entry["id"])
            check(before == http.fixture(site, "storage-files", php)
                  and history.fixture(site, "read-artifacts", php)["files"][name] is None, "Recovery initialized a missing " + name)
        finally:
            history.fixture(site, "restore-read-artifact", php, name=name)
        cases.append(name)
    http.fixture(site, "hold-source", php)
    try:
        before = http.fixture(site, "storage-files", php)
        response = owner.get(url(base), timeout=20)
        check(response.status_code == 200 and (case["mode"] == "mysql" or "Nothing to recover yet" in response.text),
              "An absent file source did not remain a safe empty/read-only recovery view.")
        check(before == http.fixture(site, "storage-files", php), "Recovery created an absent marketing source.")
    finally:
        http.fixture(site, "restore-source", php)
    fixture(site, "probe-install", php)
    try:
        review(owner, base); review(owner, base, "posts", entry["id"])
        check(fixture(site, "probe-read", php) == {"boots": 0, "status": "active"}, "Recovery GET booted an installed extension.")
        positive = owner.get(base + "/admin/?page=dashboard", timeout=20)
        check(positive.status_code == 200 and fixture(site, "probe-read", php)["boots"] > 0,
              "The installed extension boot probe lacked a real positive control.")
    finally:
        fixture(site, "probe-remove", php)
    return {"missing_artifacts_preserved": cases, "lock_permissions_preserved": True,
            "absent_file_source_never_initialized": True, "extension_boot_probe_and_positive_control": True}


def blocked_owner_reads(owner, case, entry):
    site, base, php = case["site"], case["base"], case["php"]
    outcomes = []
    for action, status in (("list", "demoted"), ("review", "inactive"), ("restore", "demoted"), ("purge", "inactive")):
        response, _ = review(owner, base, "posts", entry["id"])
        values = http.fields(response.text, "form[data-recovery-" + ("purge" if action == "purge" else "restore") + "]")
        if action == "purge":
            values = http.changed(values, confirm_permanent="1")
        before = history.snapshot(site, php)
        token = secrets.token_hex(8)
        release = site / ("storage/temp/editorial-history-records-" + token + ".release")
        worker = subprocess.Popen([php, str(history.FIXTURE), str(site), "records-lock"], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd="/tmp")
        try:
            worker.stdin.write(json.dumps({"token": token})); worker.stdin.close()
            check(bool(select.select([worker.stdout], [], [], 10)[0]) and json.loads(worker.stdout.readline())["locked"],
                  "The owned SQL recovery lock failed to become ready.")
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                pending = (pool.submit(owner.post, url(base, "posts", entry["id"]), data=values, timeout=30)
                           if action in ("restore", "purge") else pool.submit(owner.get,
                           url(base, "posts", entry["id"]) if action == "review" else url(base), timeout=30))
                try:
                    observed = {}; deadline = time.monotonic() + 10
                    while not pending.done() and time.monotonic() < deadline:
                        observed = history.fixture(site, "records-wait", php, _count=False)
                        if observed["waiting_connections"]:
                            break
                        time.sleep(.02)
                    check(len(observed.get("waiting_connections", [])) == 1 and not pending.done(),
                          "The actual recovery request did not wait on authoritative SQL data: " + repr(observed))
                    http.fixture(site, "owner-status", php, status=status)
                finally:
                    release.write_text("Release the exclusively owned recovery wait.\n")
                denied = pending.result(timeout=30)
                check(denied.status_code == 403 and "HISTORY_PRIOR_BODY" not in denied.text,
                      "Ownership revoked during a saved-data wait still completed recovery " + action)
                http.private(denied, True)
        finally:
            if not release.exists():
                release.write_text("Release the owned recovery lock after failure.\n")
            worker.wait(timeout=10)
            check(worker.returncode == 0, "The owned SQL lock worker failed: " + worker.stderr.read()[:1000])
            worker.stdout.close(); worker.stderr.close()
            http.fixture(site, "owner-status", php, status="owner")
            release.unlink(missing_ok=True)
        history.no_writes(before, history.snapshot(site, php), "Owner revoked during recovery " + action)
        outcomes.append({"action": action, "owner_change": status, "observed_records_select_wait": True, "http_status": 403})
    return outcomes


def http_case(case):
    site, base, php = case["site"], case["base"], case["php"]
    started = http.checks
    owner, guest, member = http.owner_login(base), http.session(), http.member_login(base, "free")
    try:
        if case["mode"] == "mysql" and http.fixture(site, "read", php)["status"]["state"] == "ready":
            http.activate_mysql(owner, base, site, php)
        initial = history.snapshot(site, php)
        check("editorial_recovery" not in initial["marketing"], "An untouched legacy/SQL layout was rewritten before its first removal.")
        before = http.fixture(site, "storage-files", php)
        response, _ = review(owner, base)
        check("Nothing to recover yet" in response.text and before == http.fixture(site, "storage-files", php),
              "The first recovery GET initialized or changed archive storage.")
        collections = {collection: collection_case(owner, guest, case, collection) for collection in history.KINDS}
        delete(owner, base, name="guarded")
        entry = selected(history.snapshot(site, php), name="guarded")
        invalid = rejected_requests(owner, guest, member, case, entry)
        reads = readonly(owner, case, entry)
        waits = blocked_owner_reads(owner, case, entry) if case["mode"] == "mysql" else []
        for kind in ("id", "slug"):
            fixture(site, "collision-add", php, collection="posts", entry_id=entry["id"], kind=kind)
            try:
                response, _ = review(owner, base, "posts", entry["id"])
                values = http.fields(response.text, "form[data-recovery-restore]")
                before = history.snapshot(site, php)
                refused = owner.post(url(base, "posts", entry["id"]), data=values, timeout=20)
                check(refused.status_code == 409 and "No item was replaced" in refused.text, "A recovery identity/permalink collision overwrote content.")
                history.no_writes(before, history.snapshot(site, php), "Recovery " + kind + " collision")
            finally:
                fixture(site, "collision-remove", php, collection="posts", entry_id=entry["id"], kind=kind)
        response, _ = review(owner, base, "posts", entry["id"])
        stale = http.fields(response.text, "form[data-recovery-restore]")
        fixture(site, "archive-revise", php, entry_id=entry["id"])
        before = history.snapshot(site, php)
        refused = owner.post(url(base, "posts", entry["id"]), data=stale, timeout=20)
        soup = BeautifulSoup(refused.text, "html.parser")
        check(refused.status_code == 409 and "changed" in refused.text and soup.select_one("[data-recovery-reload]") is not None
              and not soup.select("form[data-recovery-restore], form[data-recovery-purge]"), "A stale recovery form was silently rebound.")
        history.no_writes(before, history.snapshot(site, php), "Stale saved archive")
        fixture(site, "archive-corrupt", php, entry_id=entry["id"])
        try:
            before = http.fixture(site, "storage-files", php)
            failed = owner.get(url(base, "posts", entry["id"]), timeout=20)
            check(failed.status_code == 503 and "HISTORY_PRIOR_BODY" not in failed.text, "Malformed archive data was exposed or reset.")
            check(before == http.fixture(site, "storage-files", php), "Malformed archive review changed saved state.")
        finally:
            fixture(site, "archive-restore", php)
        # A retired tier is preserved on offline recovery; enabling remains guarded.
        http.fixture(site, "plan-state", php, plan="gold", state="disabled")
        try:
            response, _ = review(owner, base, "posts", entry["id"])
            check("saved audience needs review" in response.text, "Recovery did not identify an unavailable saved tier.")
            restore(owner, base, "posts", entry["id"])
            after = history.snapshot(site, php)
            row = history.record(after, name="guarded")
            check(row["status"] == "draft" and row["access"] == entry["record"]["access"], "Missing-tier recovery broadened saved access.")
            editor = owner.get(history.editor_url(base, name="guarded"), timeout=20)
            enable = http.fields(editor.text, '[data-publication-id="history-post-guarded"] form:has(input[value="set_publication_enabled"])')
            denied = owner.post(history.editor_url(base, name="guarded"), data=enable, timeout=20)
            http.rejected_form(denied, "Enable recovered missing tier")
            history.no_writes(after, history.snapshot(site, php), "Missing-tier enable refusal")
        finally:
            http.fixture(site, "plan-state", php, plan="gold", state="active")
        # Explicit final removal has its own confirmation, then cannot be replayed.
        delete(owner, base, name="delete")
        purged = selected(history.snapshot(site, php), name="delete")
        response, _ = review(owner, base, "posts", purged["id"])
        purge = http.changed(http.fields(response.text, "form[data-recovery-purge]"), confirm_permanent="1")
        response = owner.post(url(base, "posts", purged["id"]), data=purge, timeout=20)
        check(response.status_code == 200 and response.history and "permanently removed" in response.text,
              "Confirmed permanent removal failed.")
        before = history.snapshot(site, php)
        check(not any(row["id"] == purged["id"] for row in entries(before)), "Permanently removed content remained recoverable.")
        check(owner.get(url(base, "posts", purged["id"]), timeout=20).status_code == 404, "A permanently removed item still has a review.")
        check(owner.post(url(base, "posts", purged["id"]), data=purge, timeout=20).status_code == 409,
              "A permanent-removal replay was accepted.")
        history.no_writes(before, history.snapshot(site, php), "Permanent-removal replay")
        final = history.snapshot(site, php)
        http.protected(initial, final)
        check(initial["metadata"] == final["metadata"] and initial["marketing"]["events"] == final["marketing"]["events"],
              "Recovery changed original media or recorded marketing events.")
        if case["mode"] == "mysql":
            http.independent_sql(final)
        capture = case["capture"]
        check(capture.connections == 0 and not capture.payloads and not capture.errors, "Recovery contacted SMTP.")
        return {"http_checks": http.checks - started, "mode": case["mode"], "mysql_version": final["mysql_version"],
                "collections": collections, "invalid_requests": invalid, "readonly": reads,
                "mysql_owner_revocation_during_record_waits": waits, "identity_and_slug_collisions_refused": True,
                "stale_and_malformed_archive_preserved": True, "missing_tier_restored_offline_then_publish_refused": True,
                "explicit_confirmed_permanent_removal": True, "independent_sql_payload_readback": case["mode"] == "mysql",
                "protected_records_queues_consent_media_preserved": True, "marketing_events_created": 0,
                "smtp_connections": capture.connections, "smtp_messages": len(capture.payloads)}
    finally:
        owner.close(); guest.close(); member.close()


def prepare_package(work):
    archive, package = history.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(name in zipped.namelist() and zipped.read(name) == (ROOT / name).read_bytes() for name in NEW_RUNTIME),
              "The actual Fresh ZIP lacks the exact recovery runtime under test.")
    package["recovery_archive_runtime_matches_sources"] = True
    package["evidence_schema"] = 1
    return archive, package


def evidence_directory():
    value = os.environ.get("VELVET_SCREENSHOT_DIR") or os.environ.get("VELVET_RECOVERY_EVIDENCE_DIR")
    if not value:
        return None
    path = Path(value).resolve()
    check(not path.is_relative_to(ROOT) or path.is_relative_to(ROOT / "artifacts"), "Keep fixture evidence outside tracked/runtime source.")
    path.mkdir(parents=True, exist_ok=True)
    return path


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("file", "mysql", "both"), default="mysql")
    options = parser.parse_args()
    evidence = evidence_directory()
    summary = {"php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "server": "php -S without any router script", "playwright_required": False, "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-recovery-http-") as temporary:
        work = Path(temporary)
        archive, package = prepare_package(work); summary.update(package)
        for mode in (("file", "mysql") if options.mode == "both" else (options.mode,)):
            for path_label, prefix in (("root", ""), ("nested", "/nested/velvet")):
                label = mode + "-" + path_label
                with http.runtime(work, archive, mode, "recovery-" + label, prefix, evidence) as case:
                    history.seed(case)
                    summary["cases"][label] = http_case(case)
                print("Editorial recovery HTTP " + label + ": PASS.", flush=True)
    if evidence:
        (evidence / "editorial-recovery-http-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial recovery actual-package HTTP acceptance passed.", flush=True)


if __name__ == "__main__":
    run()
