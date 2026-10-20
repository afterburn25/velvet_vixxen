#!/usr/bin/env python3
"""Real owner history review/restore in file/MySQL desktop/mobile packages.

Each independent installation repeats the focused HTTP matrix, then exercises
the actual list/review/restore controls in Chromium. SQL storage activation
also uses the existing owner's real review and move controls.
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
spec = importlib.util.spec_from_file_location("vvx_history_http", ROOT / "tests/Http/editorial_history_http.py")
assert spec and spec.loader
history = importlib.util.module_from_spec(spec)
spec.loader.exec_module(history)
http, check = history.http, history.check


def owner_login(page, base):
    page.goto(base + "/admin/?page=login", wait_until="networkidle")
    page.get_by_label("Email address").fill(http.OWNER)
    page.get_by_label("Password", exact=True).fill(http.PASSWORD)
    page.get_by_role("button", name="Sign in", exact=True).click()
    page.wait_for_url(base + "/admin/")


def submit(page, control, label, redirect=True):
    with page.expect_navigation(wait_until="networkidle") as navigation:
        control.click()
    response = navigation.value
    check(response is not None and response.status == 200, label + " returned an unexpected status.")
    if redirect:
        check(response.request.method == "GET" and response.request.redirected_from is not None
              and response.request.redirected_from.method == "POST", label + " did not use POST/Redirect/GET.")
    else:
        check(response.request.method == "POST", label + " did not submit the actual owner form.")
    check(page.locator(".admin-alert.error").count() == 0,
          label + " returned an owner validation error: " + " ".join(page.locator(".admin-alert.error").all_inner_texts()))


def screenshot(page, evidence, label):
    if evidence is None:
        return None
    # A full-page screenshot does not itself trigger lazy images below the
    # viewport. Visit and decode every rendered image before recording evidence.
    pictures = page.locator("img:visible").all()
    for picture in pictures:
        picture.scroll_into_view_if_needed()
        decoded = picture.evaluate("""async image => {
            await image.decode();
            return image.complete && image.naturalWidth > 0 && image.naturalHeight > 0;
        }""")
        check(decoded, "A rendered history image did not load and decode before its screenshot.")
    page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
    filename = "editorial-history-" + label + ".png"
    page.screenshot(path=str(evidence / filename), full_page=True)
    check((evidence / filename).stat().st_size > 1000, "A required history screenshot is empty.")
    return filename, len(pictures)


def geometry(page, label):
    measured = page.evaluate("""() => ({
      viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll('[data-publication-history], [data-publication-history-list] a, [data-history-restore] button, [data-history-back], [data-history-current] a')]
        .filter(element => element.getClientRects().length).map(element => {
          const rect = element.getBoundingClientRect();
          return {name: element.innerText || element.getAttribute('aria-label'),
                  left: rect.left, right: rect.right, width: rect.width, height: rect.height};
        })
    })""")
    check(measured["content"] <= measured["viewport"] + 1, label + " overflows horizontally: " + repr(measured))
    check(all(control["left"] >= -1 and control["right"] <= measured["viewport"] + 1 and control["height"] >= 40
              for control in measured["controls"]), label + " has clipped/undersized history controls: " + repr(measured))
    return measured


def keyboard_focus(page, control):
    control.focus()
    page.keyboard.press("Shift+Tab")
    page.keyboard.press("Tab")
    check(control.evaluate('(element) => document.activeElement === element && element.matches(":focus-visible")'),
          "A history control is not keyboard reachable with visible focus.")


def browser_activate(browser, case, viewport):
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors, actions = [], []
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
            if request.method == "POST" else None)
    try:
        before = history.snapshot(case["site"], case["php"])
        check(before["status"]["state"] == "ready", "Browser SQL activation requires an unmoved owned fixture.")
        owner_login(page, case["base"])
        page.goto(case["base"] + "/admin/?page=marketing-storage", wait_until="networkidle")
        expect(page.locator("[data-marketing-storage]")).to_have_attribute("data-storage-state", "ready")
        submit(page, page.get_by_role("button", name="Preview Move", exact=True), "History SQL move review", redirect=False)
        submit(page, page.get_by_role("button", name="Move to MySQL", exact=True), "History SQL move")
        expect(page.locator("[data-marketing-storage]")).to_have_attribute("data-storage-state", "mysql")
        after = history.snapshot(case["site"], case["php"])
        http.independent_sql(after)
        check(before["marketing"] == after["marketing"] and before["legacy_sha256"] == after["legacy_sha256"]
              and before["metadata"] == after["metadata"], "Browser SQL activation changed the imported history fixture or original media.")
        http.protected(before, after)
        check(actions == ["preview_marketing_storage", "migrate_marketing_storage"] and not errors,
              "Browser SQL activation omitted its real controls or raised a JavaScript error.")
        return {"real_owner_post_actions": actions, "exact_sql_import_and_original_json_preserved": True,
                "javascript_errors": errors, "mysql_version": after["mysql_version"]}
    finally:
        context.close()


def browser_case(browser, case, viewport, evidence):
    site, base, php, label = case["site"], case["base"], case["php"], case["label"]
    context = browser.new_context(viewport=viewport)
    owner = context.new_page()
    errors, actions, screenshots, measures, collections, decoded_images = [], [], [], {}, {}, {}
    started = http.checks

    def listen(page):
        page.on("pageerror", lambda error: errors.append(str(error)))

    context.on("page", listen)
    listen(owner)
    owner.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
             if request.method == "POST" else None)

    def capture(page, suffix):
        captured = screenshot(page, evidence, label + "-" + suffix)
        if captured:
            filename, image_count = captured
            screenshots.append(filename)
            decoded_images[filename] = image_count

    try:
        owner_login(owner, base)
        initial = history.snapshot(site, php)
        for collection in history.KINDS:
            before = history.snapshot(site, php)
            old_versions = history.stored_entries(before, collection)
            original_version = old_versions[-1]["id"]
            original = old_versions[-1]["snapshot"]
            owner.goto(history.editor_url(base, collection), wait_until="networkidle")
            form = owner.locator('form[data-publication-editor="' + collection + '"]')
            field = "text" if collection == "banners" else "title"
            form.locator('[name="' + field + '"]').fill("Browser changed " + history.KINDS[collection])
            if collection != "banners":
                form.locator('[name="body"]').fill("HISTORY_BROWSER_BODY_" + collection + "\nSaved from the real editor.")
            submit(owner, form.get_by_role("button", name={"posts": "Save Post", "banners": "Save Banner",
                   "landing_pages": "Save Page"}[collection], exact=True), "Browser history content save")
            saved = history.snapshot(site, php)
            new_versions = history.stored_entries(saved, collection)
            check(len(new_versions) == len(old_versions) + 1 and new_versions[0]["action"] == "save",
                  "A real browser save did not capture exactly one preceding version.")
            # Unsaved text must not become a history version or change review.
            form = owner.locator('form[data-publication-editor="' + collection + '"]')
            form.locator('[name="' + field + '"]').fill("HISTORY_BROWSER_UNSAVED_TEXT")
            link = owner.locator('[data-publication-editor-card="' + collection + '"] [data-publication-history]')
            expect(link).to_be_visible()
            keyboard_focus(owner, link)
            link.click()
            owner.wait_for_url("**page=marketing-history**")
            expect(owner.locator("[data-publication-history-list]")).to_be_visible()
            check("HISTORY_BROWSER_UNSAVED_TEXT" not in owner.content(), "Unsaved editor text entered private history.")
            check(owner.locator("[data-publication-history-list] [data-history-entry]").count() == len(new_versions),
                  "The browser history list differs from current saved versions.")
            measures[collection + "-history"] = geometry(owner, label + " " + collection + " history")
            capture(owner, collection + "-history")
            review_link = owner.locator('[data-history-entry="' + original_version + '"]').get_by_role("link", name="Review version", exact=True)
            keyboard_focus(owner, review_link)
            review_link.click()
            owner.wait_for_url("**version=" + original_version + "**")
            review = owner.locator("[data-history-selected]")
            expect(review).to_be_visible()
            expect(review).to_contain_text(original["text" if collection == "banners" else "body"])
            if collection == "posts":
                expect(review).to_contain_text("Metadata post public-a")
                expect(review).to_contain_text("Metadata post public-b")
            check(owner.locator("[data-history-injection]").count() == 0
                  and "HISTORY_PRIVATE_UNKNOWN" not in owner.content(), "History interpreted HTML or disclosed unknown private fields.")
            check(review.locator("a[href], script, iframe").count() == 0, "Historical content contains an active destination or script.")
            measures[collection + "-review"] = geometry(owner, label + " " + collection + " review")
            capture(owner, collection + "-review")
            history.no_writes(saved, history.snapshot(site, php), "Browser history review and unsaved edit")
            restore_button = owner.locator("form[data-history-restore]").get_by_role("button", name="Restore as draft" if collection == "posts" else "Restore disabled", exact=True)
            keyboard_focus(owner, restore_button)
            submit(owner, restore_button, "Browser restore " + collection)
            owner.wait_for_url("**edit_" + history.KINDS[collection] + "=" + history.identity(collection) + "**")
            after = history.snapshot(site, php)
            restored = history.record(after, collection)
            check(history.safe_state(restored, collection)
                  and restored["text" if collection == "banners" else "body"] == original["text" if collection == "banners" else "body"],
                  "The real restore control did not recover the selected content into an unpublished state.")
            for key in ("id", "slug", "created_at", "history_unknown"):
                check(restored.get(key) == history.record(saved, collection).get(key), "Browser restore replaced a current protected identity/field.")
            versions = history.stored_entries(after, collection)
            check(len(versions) == len(new_versions) + 1 and versions[0]["action"] == "restore",
                  "The browser restore did not retain the displaced content for undo.")
            owner.reload(wait_until="networkidle")
            form = owner.locator('form[data-publication-editor="' + collection + '"]')
            expect(form.locator('[name="' + field + '"]')).to_have_value(original[field])
            if collection == "posts":
                expect(form.locator('[name="status"]')).to_have_value("draft")
            else:
                expect(form.locator('[name="enabled"]')).not_to_be_checked()
            capture(owner, collection + "-restored-editor")
            http.protected(before, after)
            collections[collection] = {"real_save_review_restore_reload": True, "unsaved_editor_text_excluded": True,
                                       "same_id_permalink_order_and_unknown_fields": True, "safe_unpublished_restore": True,
                                       "keyboard_controls": True, "restore_version_id": original_version}

        # Keep the actual older review open while a second tab changes the same
        # publication. The first restore must refuse the stale snapshot.
        before_stale = history.snapshot(site, php)
        selected = history.stored_entries(before_stale)[0]["id"]
        owner.goto(history.history_url(base, version=selected), wait_until="networkidle")
        stale_tab = context.new_page()
        stale_tab.goto(history.editor_url(base), wait_until="networkidle")
        stale_tab.locator('form[data-publication-editor="posts"] [name="title"]').fill("Browser concurrent newer save")
        submit(stale_tab, stale_tab.get_by_role("button", name="Save Post", exact=True), "Browser concurrent save")
        newer = history.snapshot(site, php)
        with owner.expect_navigation(wait_until="networkidle") as navigation:
            owner.locator("form[data-history-restore]").get_by_role("button", name="Restore as draft", exact=True).click()
        response = navigation.value
        check(response is not None and response.request.method == "POST" and response.status in (200, 409, 422),
              "A stale browser restore did not stay on its refused POST response.")
        expect(owner.locator(".admin-alert.error")).to_be_visible()
        history.no_writes(newer, history.snapshot(site, php), "Stale real-browser restore")
        capture(owner, "stale-restore-refused")
        stale_tab.close()
        final = history.snapshot(site, php)
        http.protected(initial, final)
        check(initial["metadata"] == final["metadata"] and initial["marketing"]["events"] == final["marketing"]["events"],
              "Browser history actions changed media or recorded marketing events.")
        if case["mode"] == "mysql":
            http.independent_sql(final)
        check(actions.count("restore_publication") == 4 and all(action in ("save_post", "save_banner", "save_page", "restore_publication") for action in actions),
              "Browser history controls submitted unexpected actions or omitted a real restoration.")
        check(not errors, "Browser history raised JavaScript errors: " + repr(errors))
        check(case["capture"].connections == 0 and not case["capture"].payloads, "Browser history contacted SMTP.")
        return {"browser_checks": http.checks - started, "collections": collections, "stale_second_tab_restore_refused": True,
                "real_owner_post_actions": actions, "geometry": measures, "screenshots": screenshots,
                "screenshot_decoded_images": decoded_images,
                "javascript_errors": errors, "smtp_connections": case["capture"].connections,
                "smtp_messages": len(case["capture"].payloads)}
    finally:
        context.close()


def run():
    evidence = history.evidence_directory()
    summary = {"php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "playwright": importlib.metadata.version("playwright"), "server": "php -S without any router script", "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-history-browser-") as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        archive, package = history.prepare_package(work)
        summary.update(package)
        options = {"headless": True}
        executable = os.environ.get("VELVET_CHROMIUM") or os.environ.get("VV_TEST_CHROME")
        if executable:
            options["executable_path"] = executable
        browser = playwright.chromium.launch(**options)
        summary["browser"] = browser.version
        try:
            for mode in ("file", "mysql"):
                for path_label, prefix, viewport in (("root-desktop", "", {"width": 1440, "height": 1000}),
                                                       ("nested-mobile", "/nested/velvet", {"width": 390, "height": 844})):
                    label = mode + "-" + path_label
                    with http.runtime(work, archive, mode, "history-" + label, prefix, evidence) as case:
                        case["label"] = label
                        history.seed(case)
                        activation = browser_activate(browser, case, viewport) if mode == "mysql" else None
                        result = history.http_case(case)
                        summary["cases"][label] = {"viewport": viewport, "mysql_activation": activation,
                                                  "http": result, "browser": browser_case(browser, case, viewport, evidence)}
                    print("Editorial History browser " + label + ": PASS.", flush=True)
        finally:
            browser.close()
    if evidence:
        (evidence / "editorial-history-browser-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial history actual-package browser acceptance passed: four file/MySQL root-desktop/nested-mobile cases.", flush=True)


if __name__ == "__main__":
    run()
