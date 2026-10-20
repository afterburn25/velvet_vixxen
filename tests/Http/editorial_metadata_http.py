#!/usr/bin/env python3
"""Actual Fresh metadata acceptance with real owner requests and saved SQL data.

CI runs --mode mysql on both PHP versions. The browser suite reuses this
focused matrix in four independently extracted file/MySQL runtimes.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import hashlib
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
spec = importlib.util.spec_from_file_location("vvx_metadata_access_http", ROOT / "tests/Http/editorial_access_http.py")
assert spec and spec.loader
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
check = http.check
FIXTURE = Path(__file__).resolve().with_name("editorial_metadata_fixture.php")
NEW_RUNTIME = (
    "app/Core/Store.php", "app/Core/MediaStoreTrait.php",
    "app/Core/MarketingMetadata.php", "app/Core/MarketingPublication.php", "app/Core/MarketingStore.php",
    "includes/marketing_admin.php", "includes/marketing.php", "includes/marketing_public_shell.php",
    "app/Views/admin/marketing-publication-editor.php", "app/Views/admin/marketing-preview.php",
    "app/Views/public/partials/header.php", "public/routes/blog/index.php", "public/routes/blog/post.php",
    "public/routes/campaign/index.php", "public/assets/css/marketing.css", "public/assets/js/marketing-editorial.js",
)
METADATA_FIELDS = ("tags", "author_name", "featured_asset_id", "featured_alt", "seo_title",
                   "seo_description", "related_post_ids")
RELATED_NAMES = ("gold", "draft", "future", "members", "public-b", "public-a")
SAVED_TAGS = ["Studio", "Café", "旅行"]
SAVED_AUTHOR = 'Nikki <span data-metadata-injection="author"> & guests'
SAVED_ALT = 'Artwork "quoted" <b>description</b> & detail'
SAVED_SEO = 'Search "title" <span data-metadata-injection="seo"> & more'
SAVED_DESCRIPTION = 'Description "quoted" <img data-metadata-injection="description"> & more.'


def fixture(site: Path, action: str, php: str = "php", _count: bool = True, **values):
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=45, cwd="/tmp")
    message = "Guarded metadata fixture failed (" + action + "): " + result.stderr[:1800]
    if _count:
        check(result.returncode == 0, message)
    elif result.returncode != 0:
        raise AssertionError(message)
    return json.loads(result.stdout)


def snapshot(site: Path, php: str):
    saved = http.fixture(site, "read", php)
    saved["metadata"] = fixture(site, "read", php)
    return saved


def record(saved: dict, collection="posts", name="main"):
    identity = ("metadata-post-" if collection == "posts" else "metadata-page-") + name
    matches = [item for item in saved["marketing"][collection] if item["id"] == identity]
    check(len(matches) == 1, "The reserved metadata record is missing or duplicated: " + identity)
    return matches[0]


def editor_url(base: str, collection="posts", name="main"):
    return base + "/admin/?" + urlencode({
        "page": "marketing", "tab": "posts" if collection == "posts" else "campaigns",
        "edit_post" if collection == "posts" else "edit_page":
            ("metadata-post-" if collection == "posts" else "metadata-page-") + name,
    })


def preview_url(base: str, collection="posts", name="main"):
    return base + "/admin/?" + urlencode({
        "page": "marketing-preview", "collection": collection,
        "id": ("metadata-post-" if collection == "posts" else "metadata-page-") + name,
    })


def public_url(base: str, collection="posts", name="main"):
    return http.route(base, ("blog/post.php?slug=metadata-post-" if collection == "posts"
                             else "campaign/?slug=metadata-page-") + name)


def no_writes(before: dict, after: dict, label: str):
    check(before["marketing"] == after["marketing"] and before["sql"] == after["sql"]
          and before["legacy_sha256"] == after["legacy_sha256"] and before["outbox"] == after["outbox"]
          and before["metadata"]["assets"] == after["metadata"]["assets"]
          and before["metadata"]["image_files"] == after["metadata"]["image_files"]
          and before["metadata"]["raw_sql_assets"] == after["metadata"]["raw_sql_assets"],
          label + " changed saved marketing, SQL, media, queue or mail state.")
    http.protected(before, after)


def post(owner, base: str, values, collection="posts", name="main"):
    response = owner.post(editor_url(base, collection, name), data=values, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == "GET",
          "Metadata save did not use successful POST/Redirect/GET.")
    check(not BeautifulSoup(response.text, "html.parser").select(".admin-alert.error"),
          "The actual metadata save returned an owner error.")
    return response


def current_fields(owner, base: str, collection="posts", name="main"):
    page = owner.get(editor_url(base, collection, name), timeout=20)
    check(page.status_code == 200, "The actual metadata editor did not load.")
    return http.fields(page.text, 'form[data-publication-editor="' + collection + '"]'), page


def related_ids(html: str):
    return [element["data-related-post-id"] for element in BeautifulSoup(html, "html.parser")
            .select("[data-editorial-related] [data-related-post-id]")]


def assert_hidden(html: str, names, label: str):
    for name in names:
        tokens = ["metadata-post-" + name, "META_EXCERPT_" + name, "META_BODY_" + name,
                  "META_TAG_" + name, "META_AUTHOR_" + name, "META_ALT_" + name,
                  "META_SEO_" + name, "META_DESCRIPTION_" + name,
                  "editorial-metadata-" + name + ".png"]
        check(all(token not in html for token in tokens), label + " disclosed metadata of " + name)
    check("private:images/" not in html and "storage/private/media/" not in html
          and "RESERVED_PRIVATE_SIGNATURE" not in html and "PRIVATE_METADATA_IMAGE_TITLE" not in html,
          label + " exposed protected image paths, catalogue labels or signatures.")


def assert_seo(response, title: str, description: str):
    soup = BeautifulSoup(response.text, "html.parser")
    check(response.status_code == 200 and soup.title is not None and title in soup.title.get_text(),
          "The actual public document title did not use saved SEO text.")
    descriptions = soup.select('meta[name="description"]')
    check(len(descriptions) == 1 and descriptions[0].get("content") == description,
          "Saved SEO description did not reach the single escaped public meta element.")
    check(not soup.select("[data-metadata-injection]"),
          "Metadata text created an HTML element/attribute instead of remaining escaped text.")
    http.private(response)
    return soup


def viewer_matrix(clients: dict, base: str):
    expected = {
        "guest": ["public-b", "public-a"],
        "free": ["members", "public-b", "public-a"],
        "gold": ["gold", "members", "public-b", "public-a"],
        "silver": ["members", "public-b", "public-a"],
    }
    rows = {}
    for name, wanted in expected.items():
        response = clients[name].get(public_url(base), timeout=20)
        soup = assert_seo(response, SAVED_SEO, SAVED_DESCRIPTION)
        check(related_ids(response.text) == ["metadata-post-" + value for value in wanted],
              "Related selection order/access/timing differs for " + name)
        assert_hidden(response.text, set(RELATED_NAMES) - set(wanted), name + " related markup")
        detail = soup.select_one('[data-editorial-post="metadata-post-main"]')
        check(detail is not None and detail.select_one("[data-editorial-byline] span").get_text() == SAVED_AUTHOR,
              "Saved author byline did not render as exact text.")
        check([tag.get_text() for tag in detail.select("[data-editorial-tags] li")] == SAVED_TAGS,
              "Saved tag labels/order did not render exactly.")
        image = detail.select_one("[data-editorial-featured-image]")
        check(image is not None and image.get("alt") == SAVED_ALT and "/uploads/images/editorial-metadata-a.png" in image["src"]
              and "signature=" not in image["src"], "The featured image did not use the selected public original and saved alternative.")
        image_response = clients[name].get(image["src"], timeout=15)
        check(image_response.status_code == 200 and image_response.headers.get("Content-Type", "").startswith("image/")
              and image_response.content.startswith(b"\x89PNG\r\n\x1a\n"), "The featured image URL did not return the actual PNG.")
        rows[name] = {"related_post_ids": related_ids(response.text), "metadata_escaped": True,
                      "hidden_reference_metadata_absent": True, "image_readback": True}
    for name in ("members", "gold", "draft", "future", "owner-draft"):
        denied = clients["guest"].get(public_url(base, name=name), timeout=20)
        check(denied.status_code == 404, "A denied/unpublished source metadata post was public: " + name)
        http.private(denied)
        assert_hidden(denied.text, [name], "Denied source")
    return rows


def invalid_edits(owner, member, base: str, site: Path, php: str, manifest: dict):
    vectors = [
        {"author_name": "é" * 121}, {"seo_title": "é" * 121}, {"seo_description": "é" * 321},
        {"featured_alt": "é" * 241}, {"tags": ",".join("tag-" + str(n) for n in range(31))},
        {"tags": "é" * 65},
        {"related_post_ids[]": ["metadata-post-public-a", "metadata-post-public-a"]},
        {"related_post_ids[]": ["metadata-post-main"]}, {"related_post_ids[]": ["missing-metadata-post"]},
        {"related_post_ids[]": ["metadata-post-" + n for n in ("public-a", "public-b", "gold", "members", "draft", "future", "owner-draft")]},
    ]
    for name in ("private", "deleted", "missing", "nonimage", "svg", "external", "traversal", "private-path", "query", "symlink", "mismatch"):
        vectors.append({"featured_asset_id": str(manifest["assets"][name]["id"])})
    outcomes = []
    for changes in vectors:
        values, _ = current_fields(owner, base)
        before = snapshot(site, php)
        response = owner.post(editor_url(base), data=http.changed(values, **changes), timeout=25, allow_redirects=False)
        http.rejected_form(response, "Invalid metadata")
        no_writes(before, snapshot(site, php), "Rejected metadata")
        outcomes.append({"fields": sorted(changes), "rejected_without_write": True})
    for field in ("tags", "author_name", "featured_asset_id", "seo_title", "seo_description", "related_post_ids"):
        values, _ = current_fields(owner, base)
        values = [(key, value) for key, value in values if key not in (field, field + "[]")]
        values.append((field + "[0][invalid]", "NESTED_METADATA_INJECTION"))
        before = snapshot(site, php)
        response = owner.post(editor_url(base), data=values, timeout=25, allow_redirects=False)
        http.rejected_form(response, "Nested metadata")
        no_writes(before, snapshot(site, php), "Rejected nested metadata")
    values, _ = current_fields(owner, base)
    before = snapshot(site, php)
    bad_csrf = owner.post(editor_url(base), data=http.changed(values, _csrf="invalid-metadata-csrf"), timeout=20, allow_redirects=False)
    check(bad_csrf.status_code == 419, "Metadata owner POST accepted an invalid CSRF token.")
    denied = member.post(editor_url(base), data=values, timeout=20, allow_redirects=False)
    check(denied.status_code in (302, 303, 403), "A member session submitted owner metadata.")
    no_writes(before, snapshot(site, php), "CSRF/nonowner metadata refusal")
    for state in ("demoted", "inactive"):
        values, _ = current_fields(owner, base)
        before = snapshot(site, php)
        http.fixture(site, "owner-status", php, status=state)
        try:
            response = owner.post(editor_url(base), data=http.changed(values, seo_title="Revoked metadata"), timeout=20, allow_redirects=False)
            check(response.status_code == 302 and response.headers.get("Location") == base + "/admin/",
                  "The existing initial owner gate did not redirect a demoted/inactive metadata POST.")
            preview = owner.get(preview_url(base), timeout=20)
            check(preview.status_code == 403 and preview.text == "Preview unavailable.", "A revoked owner preview disclosed metadata.")
            http.private(preview, True)
        finally:
            http.fixture(site, "owner-status", php, status="owner")
        no_writes(before, snapshot(site, php), "Revoked owner metadata refusal")
    return outcomes


def saved_preview(owner, clients, base: str, site: Path, php: str):
    outcomes = {}
    for collection, name in (("posts", "main"), ("posts", "owner-draft"), ("landing_pages", "main")):
        before = snapshot(site, php)
        response = owner.get(preview_url(base, collection, name), timeout=20)
        check(response.status_code == 200, "The real private saved metadata preview failed.")
        http.private(response, True)
        soup = BeautifulSoup(response.text, "html.parser")
        check(soup.select_one("[data-preview-editorial-seo]") is not None, "Saved preview lacks its metadata/search appearance.")
        check(not soup.select("script, form, [data-marketing-event]"), "A private metadata preview contains scripts, forms or tracking hooks.")
        if collection == "posts":
            expected = record(before, collection, name)
            check(expected["seo_title"] in soup.select_one("[data-preview-editorial-seo]").get_text(),
                  "Saved preview did not show stored SEO.")
            wanted = expected["related_post_ids"]
            check(related_ids(response.text) == wanted, "Owner preview did not preserve saved draft/future/restricted references.")
            check(not soup.select("[data-editorial-related] a[href], [data-editorial-related] form"),
                  "An owner preview related card can navigate to an event-producing public route.")
        else:
            check(not soup.select("[data-editorial-byline], [data-editorial-tags], [data-editorial-related]"),
                  "Landing preview gained unsupported post-only metadata.")
        check(not soup.select("[data-metadata-injection]") and "private:images/" not in response.text
              and "RESERVED_PRIVATE_SIGNATURE" not in response.text, "Saved preview exposed markup or protected paths.")
        no_writes(before, snapshot(site, php), "Opening saved metadata preview")
        for client_name in ("guest", "free", "gold"):
            denied = clients[client_name].get(preview_url(base, collection, name), timeout=20)
            check(denied.status_code == 403 and denied.text == "Preview unavailable.", "A nonowner opened saved metadata preview.")
            http.private(denied, True)
        outcomes[collection + ":" + name] = {"saved_fields": True, "related_links_inert": True, "no_writes": True}
    return outcomes


def live_changes(owner, clients, base: str, site: Path, php: str):
    observed = []
    for state in ("draft", "future", "members", "remove"):
        fixture(site, "related-state", php, name="public-b", state=state)
        try:
            response = clients["guest"].get(public_url(base), timeout=20)
            check(response.status_code == 200 and related_ids(response.text) == ["metadata-post-public-a"],
                  "A changed related target remained in the guest output: " + state)
            assert_hidden(response.text, ["public-b"], "Changed related target")
        finally:
            fixture(site, "related-state", php, name="public-b", state="restore")
        observed.append({"related_change": state, "effective_next_request": True})
    http.fixture(site, "subscription-state", php, member="gold", status="expired")
    try:
        response = clients["gold"].get(public_url(base), timeout=20)
        check(related_ids(response.text) == ["metadata-post-members", "metadata-post-public-b", "metadata-post-public-a"],
              "An expired tier subscription retained the gold related post or lost member-only access.")
        assert_hidden(response.text, ["gold"], "Expired tier related output")
    finally:
        http.fixture(site, "subscription-state", php, member="gold", status="active")
    for state in ("deleted", "private", "missing"):
        fixture(site, "media-state", php, name="a", state=state)
        try:
            before = snapshot(site, php)
            response = clients["guest"].get(public_url(base), timeout=20)
            check(response.status_code == 200 and "META_BODY_main" in response.text,
                  "A disappeared featured image hid the otherwise eligible source body.")
            check("uploads/images/editorial-metadata-a.png" not in response.text
                  and "private:images/" not in response.text and "storage/private/media/" not in response.text,
                  "A changed featured asset was still exposed in the next public response.")
            editor = owner.get(editor_url(base), timeout=20)
            check(BeautifulSoup(editor.text, "html.parser").select_one("[data-editorial-image-unavailable]") is not None,
                  "The owner was not told the saved image needs repair.")
            preview = owner.get(preview_url(base), timeout=20)
            check(preview.status_code == 200 and "uploads/images/editorial-metadata-a.png" not in preview.text
                  and "private:images/" not in preview.text, "Private preview reused an unavailable featured image.")
            no_writes(before, snapshot(site, php), "Unavailable-image reads")
        finally:
            fixture(site, "media-state", php, name="a", state="file-restored" if state == "missing" else "restored")
        observed.append({"image_change": state, "effective_next_request": True})
    return observed


def owner_revoked_during_media_read(owner, base: str, site: Path, php: str):
    results = []
    for action, status in (("preview", "demoted"), ("save", "inactive")):
        values, _ = current_fields(owner, base)
        before = snapshot(site, php)
        token = secrets.token_hex(8)
        release = site / ("storage/temp/editorial-metadata-media-" + token + ".release")
        worker = subprocess.Popen([php, str(FIXTURE), str(site), "media-lock"], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd="/tmp")
        try:
            worker.stdin.write(json.dumps({"token": token}))
            worker.stdin.close()
            check(bool(select.select([worker.stdout], [], [], 10)[0]), "The owned media table lock did not become ready.")
            ready = worker.stdout.readline()
            check(bool(ready) and json.loads(ready).get("locked") is True, "The fixture did not acquire a real media_assets WRITE lock.")
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                pending = (pool.submit(owner.get, preview_url(base), timeout=25) if action == "preview"
                           else pool.submit(owner.post, editor_url(base), data=values, timeout=25))
                try:
                    deadline = time.monotonic() + 10
                    observed = {}
                    while not pending.done() and time.monotonic() < deadline:
                        observed = fixture(site, "media-wait", php, _count=False)
                        if observed["waiting_connections"]:
                            break
                        time.sleep(.02)
                    check(len(observed.get("waiting_connections", [])) == 1 and not pending.done(),
                          "The real " + action + " request did not block on media_assets: " + repr(observed))
                    http.fixture(site, "owner-status", php, status=status)
                finally:
                    release.write_text("Release the exclusively owned metadata image catalogue lock.\n")
                response = pending.result(timeout=25)
                check(response.status_code == 403, "An owner revoked during a real media read completed " + action)
                if action == "preview":
                    check(response.text == "Preview unavailable.", "Revoked preview disclosed metadata after the media wait.")
                    http.private(response, True)
        finally:
            if not release.exists():
                release.write_text("Release the exclusively owned metadata lock after failure.\n")
            try:
                worker.wait(timeout=8)
            except subprocess.TimeoutExpired:
                worker.terminate()
                worker.wait(timeout=5)
            stderr = worker.stderr.read()
            check(worker.returncode == 0, "The owned media table lock worker failed: " + stderr[:1000])
            worker.stdout.close()
            worker.stderr.close()
            http.fixture(site, "owner-status", php, status="owner")
            release.unlink(missing_ok=True)
        no_writes(before, snapshot(site, php), "Owner revocation during media catalogue wait")
        results.append({"action": action, "owner_change": status, "observed_media_select_wait": True,
                        "http_status": 403, "exact_state_preserved": True})
    return results


def http_case(case: dict):
    site, base, php = case["site"], case["base"], case["php"]
    started = http.checks
    owner = http.owner_login(base)
    clients = {"guest": http.session()}
    try:
        status = http.fixture(site, "read", php)["status"]["state"]
        if case["mode"] == "mysql" and status == "ready":
            http.activate_mysql(owner, base, site, php)
        initial = snapshot(site, php)
        if case["mode"] == "mysql":
            http.independent_sql(initial)
        manifest = initial["metadata"]["manifest"]
        for name in ("free", "gold", "silver"):
            clients[name] = http.member_login(base, name)
        values, page = current_fields(owner, base)
        soup = BeautifulSoup(page.text, "html.parser")
        metadata = soup.select_one('[data-editorial-metadata="posts"]')
        check(metadata is not None and len(metadata.select("[data-editorial-related-slot]")) == 6,
              "The actual post editor lacks metadata controls or six ordered related slots.")
        picker = metadata.select_one("[data-editorial-image-picker]")
        choices = {option.get("value") for option in picker.select("option")}
        check(str(manifest["assets"]["a"]["id"]) in choices and str(manifest["assets"]["b"]["id"]) in choices,
              "The real image picker omitted decoded public originals.")
        for name in ("private", "deleted", "missing", "nonimage", "svg", "external", "traversal", "private-path", "query", "symlink", "mismatch"):
            check(str(manifest["assets"][name]["id"]) not in choices, "The real image picker offered an unsafe asset: " + name)
        saved_values = http.changed(values, tags="Studio, studio, Café, CAFÉ, 旅行", author_name=SAVED_AUTHOR,
                                    featured_asset_id=str(manifest["assets"]["a"]["id"]), featured_alt=SAVED_ALT,
                                    seo_title=SAVED_SEO, seo_description=SAVED_DESCRIPTION,
                                    **{"related_post_ids[]": manifest["related_order"]})
        post(owner, base, saved_values)
        saved = snapshot(site, php)
        current = record(saved)
        original = record(initial)
        check(current["tags"] == SAVED_TAGS and current["author_name"] == SAVED_AUTHOR
              and current["seo_title"] == SAVED_SEO and current["seo_description"] == SAVED_DESCRIPTION
              and current["featured_alt"] == SAVED_ALT and current["related_post_ids"] == manifest["related_order"],
              "Actual owner metadata did not persist canonically.")
        for key in ("id", "slug", "category", "body", "status", "access", "publish_at", "created_at", "unknown_metadata"):
            check(current[key] == original[key], "Metadata save changed unrelated publication data: " + key)
        check([p["id"] for p in saved["marketing"]["posts"]] == [p["id"] for p in initial["marketing"]["posts"]],
              "Owner metadata save reordered saved records.")
        http.protected(initial, saved)
        check(saved["marketing"]["events"] == initial["marketing"]["events"]
              and saved["metadata"] == initial["metadata"], "Owner metadata save changed events or source media.")
        roundtrip, _ = current_fields(owner, base)
        values_dict = dict(roundtrip)
        check(values_dict["author_name"] == SAVED_AUTHOR and values_dict["seo_title"] == SAVED_SEO
              and [value for key, value in roundtrip if key == "related_post_ids[]"] == manifest["related_order"],
              "The saved metadata editor did not reload exact values/order.")
        before_stale = snapshot(site, php)
        stale = owner.post(editor_url(base), data=saved_values, timeout=20, allow_redirects=False)
        http.rejected_form(stale, "Stale metadata revision")
        no_writes(before_stale, snapshot(site, php), "Stale metadata save")
        # Old clients omit metadata entirely; absent form keys must preserve it.
        omitted = [(key, value) for key, value in roundtrip if key not in METADATA_FIELDS and key != "related_post_ids[]"]
        post(owner, base, omitted)
        preserved = record(snapshot(site, php))
        check(all(preserved[key] == current[key] for key in METADATA_FIELDS), "Legacy omitted metadata fields overwrote saved values.")
        vectors = invalid_edits(owner, clients["free"], base, site, php, manifest)
        # A metadata-only draft edit remains a draft.
        draft_values, _ = current_fields(owner, base, name="owner-draft")
        post(owner, base, http.changed(draft_values, seo_title="Saved private draft search title"), name="owner-draft")
        check(record(snapshot(site, php), name="owner-draft")["status"] == "draft", "A metadata edit published a saved draft.")
        page_values, page = current_fields(owner, base, "landing_pages")
        page_soup = BeautifulSoup(page.text, "html.parser")
        check(page_soup.select_one('[data-editorial-metadata="landing_pages"]') is not None
              and not page_soup.select('form[data-publication-editor="landing_pages"] [name="tags"], form[data-publication-editor="landing_pages"] [name="author_name"], form[data-publication-editor="landing_pages"] [data-editorial-related-slot]'),
              "Landing page metadata controls do not match their bounded image/SEO scope.")
        post(owner, base, http.changed(page_values, seo_title="Saved landing SEO & title",
                                      seo_description="Saved landing description.", featured_alt="Saved landing artwork"), "landing_pages")
        page_result = clients["guest"].get(public_url(base, "landing_pages"), timeout=20)
        assert_seo(page_result, "Saved landing SEO & title", "Saved landing description.")
        check("META_PAGE_BODY" in page_result.text and "/uploads/images/editorial-metadata-b.png" in page_result.text,
              "Landing page lost its content or safe featured image.")
        # Banners retain their previous shape.
        banner = owner.get(http.editor_url(base, "banners"), timeout=20)
        check(not BeautifulSoup(banner.text, "html.parser").select('[data-editorial-metadata="banners"]'),
              "Unrequested metadata controls were added to banners.")
        matrix = viewer_matrix(clients, base)
        malformed = clients["guest"].get(public_url(base, name="malformed"), timeout=20)
        check(malformed.status_code == 200 and "META_BODY_malformed" in malformed.text
              and "PRIVATE_MALFORMED_DESCRIPTION" not in malformed.text and "Array" not in BeautifulSoup(malformed.text, "html.parser").title.get_text(),
              "Malformed optional legacy metadata hid eligible content or leaked malformed fields.")
        previews = saved_preview(owner, clients, base, site, php)
        changes = live_changes(owner, clients, base, site, php)
        waits = owner_revoked_during_media_read(owner, base, site, php) if case["mode"] == "mysql" else []
        # Explicit clearing has a different meaning from omission.
        clear_values, _ = current_fields(owner, base)
        post(owner, base, http.changed(clear_values, tags="", author_name="", featured_asset_id="", featured_alt="",
                                      seo_title="", seo_description="", **{"related_post_ids[]": [""] * 6}))
        cleared = record(snapshot(site, php))
        check(cleared["tags"] == [] and cleared["related_post_ids"] == [] and all(cleared[key] == "" for key in
              ("author_name", "featured_asset_id", "featured_alt", "seo_title", "seo_description")), "Explicit blank metadata did not clear saved values.")
        fallback = clients["guest"].get(public_url(base), timeout=20)
        fallback_soup = assert_seo(fallback, cleared["title"], cleared["excerpt"])
        detail = fallback_soup.select_one('[data-editorial-post="metadata-post-main"]')
        check(not detail.select("[data-editorial-byline], [data-editorial-tags], [data-editorial-featured-image]")
              and not related_ids(fallback.text), "Cleared metadata still appeared publicly.")
        restore_values, _ = current_fields(owner, base)
        post(owner, base, http.changed(restore_values, tags=", ".join(SAVED_TAGS), author_name=SAVED_AUTHOR,
                                      featured_asset_id=str(manifest["assets"]["a"]["id"]), featured_alt=SAVED_ALT,
                                      seo_title=SAVED_SEO, seo_description=SAVED_DESCRIPTION,
                                      **{"related_post_ids[]": manifest["related_order"]}))
        final = snapshot(site, php)
        http.protected(initial, final)
        check(initial["marketing"]["banners"] == final["marketing"]["banners"]
              and initial["metadata"] == final["metadata"], "Metadata acceptance changed unrelated banners, media assets or original image bytes.")
        if case["mode"] == "mysql":
            http.independent_sql(final)
        capture = case["capture"]
        check(capture.connections == 0 and not capture.payloads and not capture.errors, "Metadata work contacted SMTP or sent email.")
        return {
            "http_checks": http.checks - started, "mode": case["mode"], "mysql_version": final["mysql_version"],
            "site_timezone": final["site_timezone"], "sql_timezone": final["sql_timezone"],
            "real_owner_metadata_save_reload_and_preservation": True,
            "post_and_landing_scope_banners_unchanged": True,
            "unicode_tags_deduplicated_ordered_and_escaped": True,
            "legacy_omission_and_explicit_clear_are_distinct": True,
            "strict_invalid_shapes_and_asset_choices_rejected": vectors,
            "csrf_stale_revision_and_revoked_owner_refused": True,
            "viewer_matrix": matrix, "source_and_related_denials_hide_all_metadata": True,
            "public_original_image_readback_no_protected_signature": True,
            "malformed_optional_legacy_metadata_safe_fallback": True,
            "saved_preview_metadata_and_inert_references": previews,
            "live_reference_changes": changes, "mysql_owner_revocation_during_media_reads": waits,
            "fresh_sql_payload_readback": case["mode"] == "mysql",
            "original_media_and_frozen_marketing_preserved": True,
            "smtp_connections": capture.connections, "smtp_messages": len(capture.payloads),
        }
    finally:
        owner.close()
        for client in clients.values():
            client.close()


def prepare_package(work: Path):
    archive, identity = http.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(name in zipped.namelist() and zipped.read(name) == (ROOT / name).read_bytes() for name in NEW_RUNTIME),
              "The actual package does not contain the exact complete metadata runtime under test.")
    identity["metadata_archive_runtime_matches_sources"] = True
    identity["evidence_schema"] = 1
    return archive, identity


def evidence_directory():
    value = os.environ.get("VELVET_SCREENSHOT_DIR") or os.environ.get("VELVET_METADATA_EVIDENCE_DIR")
    if not value:
        return None
    path = Path(value).resolve()
    check(not path.is_relative_to(ROOT) or path.is_relative_to(ROOT / "artifacts"),
          "Private metadata evidence must stay outside tracked/runtime source.")
    path.mkdir(parents=True, exist_ok=True)
    return path


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("file", "mysql", "both"), default="mysql")
    options = parser.parse_args()
    evidence = evidence_directory()
    summary = {"php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "server": "php -S without any router script", "playwright_required": False, "cases": {}}
    modes = ("file", "mysql") if options.mode == "both" else (options.mode,)
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-metadata-http-") as temporary:
        work = Path(temporary)
        archive, identity = prepare_package(work)
        summary.update(identity)
        for mode in modes:
            for path_label, prefix in (("root", ""), ("nested", "/nested/velvet")):
                label = mode + "-" + path_label
                with http.runtime(work, archive, mode, "metadata-" + label, prefix, evidence) as case:
                    fixture(case["site"], "seed", case["php"])
                    summary["cases"][label] = http_case(case)
                print("Editorial Metadata HTTP " + label + ": PASS.", flush=True)
    if evidence:
        (evidence / "editorial-metadata-http-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial metadata actual-package HTTP acceptance passed.", flush=True)


if __name__ == "__main__":
    run()
