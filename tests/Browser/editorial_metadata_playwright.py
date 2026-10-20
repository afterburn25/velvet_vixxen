#!/usr/bin/env python3
"""Actual Fresh metadata editing and saved previews, file/MySQL desktop/mobile.

Each case extracts an independent package, repeats the focused HTTP matrix,
then saves through real browser forms. MySQL activation also uses the owner's
actual Preview Move and Move to MySQL controls.
"""
from __future__ import annotations

import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.parse import parse_qs

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("vvx_metadata_http", ROOT / "tests/Http/editorial_metadata_http.py")
assert spec and spec.loader
metadata = importlib.util.module_from_spec(spec)
spec.loader.exec_module(metadata)
http, check = metadata.http, metadata.check
BROWSER_TAGS = ["Behind the scenes", "Studio", "旅行"]
BROWSER_AUTHOR = "Nikki & Studio"
BROWSER_ALT = "Saved studio artwork from the browser"
BROWSER_SEO = "A separate browser search title"
BROWSER_DESCRIPTION = "A saved browser description with an ampersand & punctuation."
BROWSER_RELATED = ["metadata-post-" + name for name in
                   ("public-a", "gold", "draft", "future", "members", "public-b")]


def screenshot(page, evidence: Path | None, label: str):
    if evidence:
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        filename = "editorial-metadata-" + label + ".png"
        page.screenshot(path=str(evidence / filename), full_page=True)
        check((evidence / filename).stat().st_size > 1000, "A required metadata screenshot is empty.")
        return filename
    return None


def geometry(page, label: str):
    measured = page.evaluate("""() => ({
      viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll('[data-editorial-metadata] input, [data-editorial-metadata] textarea, [data-editorial-metadata] select, [data-publication-editor-card] .marketing-button, [data-preview-back]')]
        .filter(element => element.getClientRects().length).map(element => {
          const box = element.getBoundingClientRect();
          return {name: element.name || element.innerText || element.tagName,
                  left: box.left, right: box.right, width: box.width, height: box.height};
        })
    })""")
    check(measured["content"] <= measured["viewport"] + 1,
          label + " overflows horizontally: " + repr(measured))
    check(all(control["left"] >= -1 and control["right"] <= measured["viewport"] + 1
              and control["height"] >= 40 for control in measured["controls"]),
          label + " has clipped or undersized metadata controls: " + repr(measured))
    return measured


def keyboard_focus(page, control):
    control.focus()
    page.keyboard.press("Shift+Tab")
    page.keyboard.press("Tab")
    check(control.evaluate('(element) => document.activeElement === element && element.matches(":focus-visible")'),
          "A metadata control is not keyboard reachable with visible focus.")


def enter_age_gate(page):
    if page.locator("#ageGate:not([hidden])").count():
        page.locator("#ageEnter").click()
        expect(page.locator("#ageGate")).to_be_hidden()


def owner_login(page, base: str):
    page.goto(base + "/admin/?page=login", wait_until="networkidle")
    page.get_by_label("Email address").fill(http.OWNER)
    page.get_by_label("Password", exact=True).fill(http.PASSWORD)
    page.get_by_role("button", name="Sign in", exact=True).click()
    page.wait_for_url(base + "/admin/")


def submit(page, control, label: str, redirect=True):
    with page.expect_navigation(wait_until="networkidle") as navigation:
        control.click()
    response = navigation.value
    check(response is not None and response.status == 200, label + " returned an unexpected HTTP status.")
    if redirect:
        check(response.request.method == "GET" and response.request.redirected_from is not None
              and response.request.redirected_from.method == "POST", label + " did not use POST/Redirect/GET.")
    else:
        check(response.request.method == "POST", label + " did not submit its real owner form.")
    check(page.locator(".admin-alert.error").count() == 0,
          label + " returned an owner error: " + " ".join(page.locator(".admin-alert.error").all_inner_texts()))


def decoded_image(image, expected_suffix: str):
    expect(image).to_be_visible()
    image.scroll_into_view_if_needed()
    expect(image).to_have_js_property("complete", True)
    check(image.evaluate("(element) => element.naturalWidth") == 96
          and image.evaluate("(element) => element.naturalHeight") == 64
          and image.get_attribute("src").endswith(expected_suffix),
          "The browser did not decode the selected actual local image original.")


def browser_activate(browser, case: dict, viewport: dict):
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    actions, errors = [], []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
            if request.method == "POST" else None)
    try:
        before = metadata.snapshot(case["site"], case["php"])
        check(before["status"]["state"] == "ready", "Browser SQL activation requires an installed, unmoved fixture.")
        owner_login(page, case["base"])
        page.goto(case["base"] + "/admin/?page=marketing-storage", wait_until="networkidle")
        expect(page.locator("[data-marketing-storage]")).to_have_attribute("data-storage-state", "ready")
        submit(page, page.get_by_role("button", name="Preview Move", exact=True), "Browser SQL review", redirect=False)
        submit(page, page.get_by_role("button", name="Move to MySQL", exact=True), "Browser SQL move")
        expect(page.locator("[data-marketing-storage]")).to_have_attribute("data-storage-state", "mysql")
        after = metadata.snapshot(case["site"], case["php"])
        http.independent_sql(after)
        check(before["marketing"] == after["marketing"] and before["legacy_sha256"] == after["legacy_sha256"]
              and before["metadata"] == after["metadata"], "Browser SQL move changed the exact metadata snapshot or source media.")
        http.protected(before, after)
        check(actions == ["preview_marketing_storage", "migrate_marketing_storage"] and not errors,
              "Browser SQL activation skipped its actual review/move or raised a JavaScript error.")
        return {"real_owner_post_actions": actions, "exact_sql_import_and_original_json_preserved": True,
                "javascript_errors": errors, "mysql_version": after["mysql_version"]}
    finally:
        context.close()


def browser_case(browser, case: dict, viewport: dict, evidence: Path | None):
    site, base, php, label = case["site"], case["base"], case["php"], case["label"]
    context = browser.new_context(viewport=viewport)
    owner = context.new_page()
    errors, actions, preview_requests, screenshots = [], [], [], []
    measures, readers, reader_results = {}, [], {}
    started = http.checks

    def listen(page):
        page.on("pageerror", lambda error: errors.append(str(error)))

    context.on("page", listen)
    listen(owner)
    owner.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
             if request.method == "POST" else None)

    def capture(page, suffix):
        filename = screenshot(page, evidence, label + "-" + suffix)
        if filename:
            screenshots.append(filename)

    try:
        owner_login(owner, base)
        initial = metadata.snapshot(site, php)
        manifest = initial["metadata"]["manifest"]
        owner.goto(metadata.editor_url(base), wait_until="networkidle")
        editor = owner.locator('form[data-publication-editor="posts"]')
        expect(editor.locator('[data-editorial-metadata="posts"]')).to_be_visible()
        keyboard_focus(owner, editor.locator('[name="tags"]'))
        keyboard_focus(owner, editor.locator("[data-editorial-image-picker]"))
        keyboard_focus(owner, editor.locator('[data-editorial-related-slot="1"]'))
        editor.locator('[name="tags"]').fill("Behind the scenes, Studio, studio, 旅行")
        editor.locator('[name="author_name"]').fill(BROWSER_AUTHOR)
        editor.locator('[name="featured_alt"]').fill(BROWSER_ALT)
        editor.locator('[name="seo_title"]').fill(BROWSER_SEO)
        editor.locator('[name="seo_description"]').fill(BROWSER_DESCRIPTION)
        editor.locator("[data-editorial-image-picker]").select_option(str(manifest["assets"]["b"]["id"]))
        decoded_image(editor.locator("[data-editorial-image-sample]"), "/uploads/images/editorial-metadata-b.png")
        expect(editor.locator("[data-editorial-image-sample]")).to_have_attribute("alt", BROWSER_ALT)
        expect(editor.locator("[data-editorial-search-title]")).to_contain_text(BROWSER_SEO)
        expect(editor.locator("[data-editorial-search-description]")).to_have_text(BROWSER_DESCRIPTION)
        for number, identity in enumerate(BROWSER_RELATED, 1):
            editor.locator('[data-editorial-related-slot="' + str(number) + '"]').select_option(identity)
        submit(owner, editor.get_by_role("button", name="Save Post", exact=True), "Browser metadata post save")
        after_post = metadata.snapshot(site, php)
        saved = metadata.record(after_post)
        check(saved["tags"] == BROWSER_TAGS and saved["author_name"] == BROWSER_AUTHOR
              and saved["featured_asset_id"] == str(manifest["assets"]["b"]["id"])
              and saved["featured_alt"] == BROWSER_ALT and saved["seo_title"] == BROWSER_SEO
              and saved["seo_description"] == BROWSER_DESCRIPTION and saved["related_post_ids"] == BROWSER_RELATED,
              "The browser form did not save its exact canonical metadata and ordered references.")
        original = metadata.record(initial)
        check(all(saved[key] == original[key] for key in
                  ("id", "slug", "body", "category", "status", "access", "publish_at", "unknown_metadata")),
              "Browser metadata save changed unrelated publication content, identity, timing or access.")
        http.protected(initial, after_post)
        check(initial["metadata"] == after_post["metadata"]
              and initial["marketing"]["events"] == after_post["marketing"]["events"],
              "Browser post save changed assets or marketing events.")
        owner.reload(wait_until="networkidle")
        editor = owner.locator('form[data-publication-editor="posts"]')
        expect(editor.locator('[name="tags"]')).to_have_value(", ".join(BROWSER_TAGS))
        expect(editor.locator('[name="author_name"]')).to_have_value(BROWSER_AUTHOR)
        expect(editor.locator('[name="seo_title"]')).to_have_value(BROWSER_SEO)
        check(editor.locator("[data-editorial-related-slot]").evaluate_all("(items) => items.map(item => item.value)") == BROWSER_RELATED,
              "Browser reload lost saved related selection order.")
        measures["post_editor"] = geometry(owner, label + " post editor")
        capture(owner, "post-editor")
        editor.locator('[name="seo_title"]').fill("UNSAVED_BROWSER_METADATA_INJECTION")
        editor.locator('[name="author_name"]').fill("UNSAVED_BROWSER_AUTHOR_INJECTION")

        for collection in ("posts", "landing_pages"):
            if collection == "landing_pages":
                owner.goto(metadata.editor_url(base, collection), wait_until="networkidle")
                editor = owner.locator('form[data-publication-editor="landing_pages"]')
                check(editor.locator('[name="tags"], [name="author_name"], [data-editorial-related-slot]').count() == 0,
                      "Landing metadata editor has unsupported post-only controls.")
                keyboard_focus(owner, editor.locator('[name="seo_title"]'))
                editor.locator('[name="featured_asset_id"]').select_option(str(manifest["assets"]["a"]["id"]))
                editor.locator('[name="featured_alt"]').fill("Browser landing artwork")
                editor.locator('[name="seo_title"]').fill("Browser landing search title")
                editor.locator('[name="seo_description"]').fill("Browser landing description.")
                submit(owner, editor.get_by_role("button", name="Save Page", exact=True), "Browser landing metadata save")
                page_saved = metadata.record(metadata.snapshot(site, php), collection)
                check(page_saved["seo_title"] == "Browser landing search title"
                      and page_saved["featured_asset_id"] == str(manifest["assets"]["a"]["id"]),
                      "The real landing metadata form did not persist its saved image/SEO.")
                measures["landing_editor"] = geometry(owner, label + " landing editor")
                capture(owner, "landing-editor")
                owner.locator('[name="seo_title"]').fill("UNSAVED_BROWSER_METADATA_INJECTION")
            link = owner.locator('[data-publication-editor-card="' + collection + '"] [data-publication-preview]')
            expect(link).to_have_attribute("href", metadata.preview_url(base, collection))
            keyboard_focus(owner, link)
            before = metadata.snapshot(site, php)
            with owner.expect_popup() as opened:
                link.click()
            preview = opened.value
            requests_for_preview = []
            preview.on("request", lambda request: requests_for_preview.append({"url": request.url, "method": request.method}))
            preview.wait_for_load_state("networkidle")
            expect(preview.locator("[data-marketing-preview]")).to_be_visible()
            expect(preview.locator("[data-preview-content]")).to_have_attribute("data-preview-content", collection)
            check("UNSAVED_BROWSER_" not in preview.content(), "Saved preview used unsaved browser metadata.")
            expect(preview.locator("[data-preview-editorial-seo]")).to_contain_text(
                BROWSER_SEO if collection == "posts" else "Browser landing search title")
            if collection == "posts":
                check(metadata.related_ids(preview.content()) == BROWSER_RELATED,
                      "Private browser preview dropped saved draft/future/restricted references or order.")
                expect(preview.locator("[data-preview-content] [data-editorial-byline] span").first).to_have_text(BROWSER_AUTHOR)
                check(preview.locator("[data-editorial-related] a[href], [data-editorial-related] form").count() == 0,
                      "Private browser related cards have active destinations.")
                address = preview.url
                preview.locator("[data-related-post-id]").first.click()
                check(preview.url == address, "An owner preview related card navigated to a public destination.")
            check(preview.locator("script, form, [data-marketing-event]").count() == 0,
                  "Private browser metadata preview contains scripts/forms/tracking.")
            decoded_image(preview.locator("[data-preview-content] [data-editorial-featured-image]").first,
                          "/uploads/images/editorial-metadata-" + ("b" if collection == "posts" else "a") + ".png")
            measures[collection + "_preview"] = geometry(preview, label + " " + collection + " preview")
            capture(preview, collection + "-preview")
            preview.reload(wait_until="networkidle")
            check(all(entry["method"] == "GET" and not any(token in entry["url"] for token in
                      ("marketing-event", "member-state", "heartbeat", "marketing.js", "site.js"))
                      for entry in requests_for_preview), "Private browser preview sent tracking or heartbeat requests.")
            check(requests_for_preview, "Saved preview request evidence is empty after actual reload.")
            preview_requests.extend(requests_for_preview)
            metadata.no_writes(before, metadata.snapshot(site, php), "Browser saved preview open/reload/interaction")
            preview.close()

        for name, related_names in (("guest", ("public-a", "public-b")),
                                    ("free", ("public-a", "members", "public-b")),
                                    ("gold", ("public-a", "gold", "members", "public-b"))):
            reader_context = browser.new_context(viewport=viewport)
            readers.append(reader_context)
            reader = reader_context.new_page()
            listen(reader)
            if name != "guest":
                reader.goto(http.route(base, "account/login.php?return=%2Fblog%2F"), wait_until="networkidle")
                enter_age_gate(reader)
                reader.get_by_label("Email address").fill(name + "@example.test")
                reader.get_by_label("Password", exact=True).fill(http.MEMBER_PASSWORD)
                reader.get_by_role("button", name="Sign In", exact=True).click()
                reader.wait_for_load_state("networkidle")
            response = reader.goto(metadata.public_url(base), wait_until="networkidle")
            enter_age_gate(reader)
            check(response is not None and response.status == 200, "Browser could not open the saved eligible post.")
            expect(reader.locator("h1.marketing-title")).to_have_text(original["title"])
            check(BROWSER_SEO in reader.title(), "Browser document title ignored the separate saved SEO title.")
            expect(reader.locator('meta[name="description"]')).to_have_attribute("content", BROWSER_DESCRIPTION)
            detail = reader.locator('[data-editorial-post="metadata-post-main"]')
            expect(detail.locator("[data-editorial-byline] span")).to_have_text(BROWSER_AUTHOR)
            check(detail.locator("[data-editorial-tags] li").all_text_contents() == BROWSER_TAGS,
                  "Browser public tag order or exact labels differ from saved values.")
            decoded_image(detail.locator("[data-editorial-featured-image]"), "/uploads/images/editorial-metadata-b.png")
            expect(detail.locator("[data-editorial-featured-image]")).to_have_attribute("alt", BROWSER_ALT)
            expected_ids = ["metadata-post-" + value for value in related_names]
            check(metadata.related_ids(reader.content()) == expected_ids, "Browser related access/order differs for " + name)
            metadata.assert_hidden(reader.content(), set(metadata.RELATED_NAMES) - set(related_names), name + " browser related markup")
            measures["reader_" + name] = geometry(reader, label + " " + name + " reader")
            if name == "guest":
                capture(reader, "public-post")
            reader.goto(http.route(base, "blog/"), wait_until="networkidle")
            cards = reader.locator("[data-editorial-post-card]").evaluate_all(
                "(items) => items.map(item => item.getAttribute('data-editorial-post-card'))")
            denied_names = {"draft", "future", "owner-draft"} | ({"members", "gold"} if name == "guest" else {"gold"} if name == "free" else set())
            check(all("metadata-post-" + value not in cards for value in denied_names)
                  and "metadata-post-main" in cards, "Browser blog cards disclose inaccessible metadata sources.")
            metadata.assert_hidden(reader.content(), denied_names, name + " browser cards")
            main_card = reader.locator('[data-editorial-post-card="metadata-post-main"]')
            expect(main_card.locator("[data-editorial-byline] span")).to_have_text(BROWSER_AUTHOR)
            decoded_image(main_card.locator("[data-editorial-featured-image]"), "/uploads/images/editorial-metadata-b.png")
            denied = reader.goto(metadata.public_url(base, name="owner-draft"), wait_until="networkidle")
            check(denied is not None and denied.status == 404, "Private metadata draft became public in a browser.")
            metadata.assert_hidden(reader.content(), ["owner-draft"], "Browser denied source")
            refused = reader.goto(metadata.preview_url(base), wait_until="networkidle")
            check(refused is not None and refused.status == 403 and reader.locator("body").inner_text() == "Preview unavailable.",
                  "A nonowner browser opened saved metadata preview.")
            reader_results[name] = {"related_post_ids": expected_ids, "metadata_escaped": True,
                                    "hidden_reference_metadata_absent": True, "actual_image_decoded": True,
                                    "private_preview_refused": True}
        final = metadata.snapshot(site, php)
        http.protected(initial, final)
        check(initial["metadata"] == final["metadata"] and initial["marketing"]["banners"] == final["marketing"]["banners"],
              "Browser metadata actions modified original assets or unrelated banners.")
        if case["mode"] == "mysql":
            http.independent_sql(final)
        check(actions == ["save_post", "save_page"], "Browser metadata saves skipped or added owner actions: " + repr(actions))
        check(not errors, "Metadata browser JavaScript errors: " + repr(errors))
        capture_state = case["capture"]
        check(capture_state.connections == 0 and not capture_state.payloads and not capture_state.errors,
              "Metadata browser work contacted SMTP or sent email.")
        if evidence:
            check(len(screenshots) == 5, "Required metadata screenshot evidence is missing.")
        return {
            "browser_checks": http.checks - started, "actual_owner_edit_actions": actions,
            "post_and_landing_metadata_round_trip": True, "native_controls_keyboard_focus": True,
            "selected_original_image_decoded_and_alt_updated": True,
            "search_preview_updates_and_saved_head_separate_from_heading": True,
            "ordered_related_selection_and_public_viewer_filtering": reader_results,
            "saved_preview_ignores_unsaved_browser_metadata": True,
            "saved_preview_related_cards_inert_and_no_events_or_mail": True,
            "original_assets_and_frozen_marketing_preserved": True,
            "mysql_payload_readback": case["mode"] == "mysql", "preview_requests": preview_requests,
            "javascript_errors": errors, "geometry": measures, "screenshots": screenshots,
            "smtp_connections": capture_state.connections, "smtp_messages": len(capture_state.payloads),
        }
    finally:
        for reader_context in readers:
            reader_context.close()
        context.close()


def run():
    evidence = metadata.evidence_directory()
    summary = {"php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "playwright": importlib.metadata.version("playwright"),
               "server": "php -S without any router script", "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-metadata-browser-") as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        archive, identity = metadata.prepare_package(work)
        summary.update(identity)
        options = {"headless": True}
        executable = os.environ.get("VELVET_CHROMIUM") or os.environ.get("VV_TEST_CHROME")
        if executable:
            options["executable_path"] = executable
        browser = playwright.chromium.launch(**options)
        summary["browser"] = browser.version
        try:
            for mode in ("file", "mysql"):
                for path_label, prefix, viewport in (
                    ("root-desktop", "", {"width": 1440, "height": 1000}),
                    ("nested-mobile", "/nested/velvet", {"width": 390, "height": 844}),
                ):
                    label = mode + "-" + path_label
                    # The retained access suite writes its own logs into the
                    # same CI evidence directory; give this runtime a distinct
                    # log prefix while keeping stable metadata case labels.
                    with http.runtime(work, archive, mode, "metadata-" + label, prefix, evidence) as case:
                        case["label"] = label
                        metadata.fixture(case["site"], "seed", case["php"])
                        activation = browser_activate(browser, case, viewport) if mode == "mysql" else None
                        result = metadata.http_case(case)
                        summary["cases"][label] = {"viewport": viewport, "mysql_activation": activation,
                                                  "http": result, "browser": browser_case(browser, case, viewport, evidence)}
                    print("Editorial Metadata browser " + label + ": PASS.", flush=True)
        finally:
            browser.close()
    if evidence:
        (evidence / "editorial-metadata-browser-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial metadata actual-package browser acceptance passed: four file/MySQL root-desktop/nested-mobile cases.", flush=True)


if __name__ == "__main__":
    run()
