#!/usr/bin/env python3
"""Real deleted-content controls, offline restoration and responsive file/MySQL packages."""
from __future__ import annotations

import argparse
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
spec = importlib.util.spec_from_file_location("vvx_recovery_browser_http", ROOT / "tests/Http/editorial_recovery_http.py")
assert spec and spec.loader
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)
http, history, check = recovery.http, recovery.history, recovery.check


def login(page, base):
    page.goto(base + "/admin/?page=login", wait_until="networkidle")
    page.get_by_label("Email address").fill(http.OWNER)
    page.get_by_label("Password", exact=True).fill(http.PASSWORD)
    page.get_by_role("button", name="Sign in", exact=True).click()
    page.wait_for_url(base + "/admin/")


def submit(page, control, label, redirect=True):
    with page.expect_navigation(wait_until="networkidle") as navigation:
        control.click()
    response = navigation.value
    check(response is not None and response.status == 200, label + " returned an unexpected response.")
    if redirect:
        check(response.request.method == "GET" and response.request.redirected_from is not None
              and response.request.redirected_from.method == "POST", label + " omitted POST/Redirect/GET.")
    else:
        check(response.request.method == "POST", label + " did not submit its actual form.")
    check(page.locator(".admin-alert.error").count() == 0,
          label + " returned an error: " + " ".join(page.locator(".admin-alert.error").all_inner_texts()))


def geometry(page, label):
    measured = page.evaluate("""() => ({viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll('[data-editorial-recovery] a, [data-editorial-recovery] button, [data-editorial-recovery] select, [data-editorial-recovery] summary')]
        .filter(element => element.getClientRects().length).map(element => {const r=element.getBoundingClientRect();
          return {name:element.innerText || element.getAttribute('aria-label'),left:r.left,right:r.right,width:r.width,height:r.height};})})""")
    check(measured["content"] <= measured["viewport"] + 1, label + " overflows horizontally: " + repr(measured))
    check(measured["controls"] and all(c["left"] >= -1 and c["right"] <= measured["viewport"] + 1
              and c["height"] >= 40 for c in measured["controls"]), label + " has clipped or small controls: " + repr(measured))
    return measured


def keyboard_focus(page, control):
    control.focus(); page.keyboard.press("Shift+Tab"); page.keyboard.press("Tab")
    check(control.evaluate('(element) => document.activeElement === element && element.matches(":focus-visible")'),
          "A recovery action is not keyboard reachable with visible focus.")


def activate_mysql(browser, case, viewport):
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    try:
        login(page, case["base"])
        before = history.snapshot(case["site"], case["php"])
        page.goto(case["base"] + "/admin/?page=marketing-storage", wait_until="networkidle")
        expect(page.locator("[data-marketing-storage]")).to_have_attribute("data-storage-state", "ready")
        submit(page, page.get_by_role("button", name="Preview Move", exact=True), "Recovery SQL preview", redirect=False)
        submit(page, page.get_by_role("button", name="Move to MySQL", exact=True), "Recovery SQL move")
        after = history.snapshot(case["site"], case["php"])
        http.independent_sql(after)
        check(before["marketing"] == after["marketing"] and before["legacy_sha256"] == after["legacy_sha256"],
              "Real SQL activation changed the original source or legacy archive-free layout.")
        return {"actual_owner_preview_and_move": True, "legacy_layout_and_original_json_preserved": True}
    finally:
        context.close()


def browser_case(browser, case, viewport, evidence):
    site, base, php, label = case["site"], case["base"], case["php"], case["label"]
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors, actions, screenshots, measures = [], [], [], {}
    started = http.checks
    context.on("page", lambda p: p.on("pageerror", lambda error: errors.append(str(error))))
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
            if request.method == "POST" else None)

    def capture(suffix):
        measures[suffix] = geometry(page, label + " " + suffix)
        if evidence:
            page.evaluate('window.scrollTo({top:0,left:0,behavior:"instant"})')
            filename = "editorial-recovery-" + label + "-" + suffix + ".png"
            page.screenshot(path=str(evidence / filename), full_page=True)
            check((evidence / filename).stat().st_size > 1000, "A recovery screenshot is empty.")
            screenshots.append(filename)

    def delete(collection="posts", name="main", target=None):
        target = target or page
        target.goto(history.editor_url(base, collection, name), wait_until="networkidle")
        button = target.locator('[data-publication-id="' + history.identity(collection, name)
                                + '"] [data-publication-delete]').get_by_role("button", name="Move to deleted content", exact=True)
        submit(target, button, "Browser move to deleted content")

    def open_review(collection="posts", name="main", target=None):
        target = target or page
        target.goto(recovery.url(base), wait_until="networkidle")
        target.get_by_label("Content type", exact=True).select_option(collection)
        target.get_by_role("button", name="Apply filter", exact=True).click()
        target.wait_for_url("**collection=" + collection)
        entry = recovery.selected(history.snapshot(site, php), collection, name)
        target.locator('[data-recovery-item="' + entry["id"] + '"]').get_by_role("link", name="Review item", exact=True).click()
        target.wait_for_url("**item=" + entry["id"])
        expect(target.locator("[data-recovery-review]")).to_have_attribute("data-recovery-review", entry["id"])
        return entry

    try:
        login(page, base)
        initial = history.snapshot(site, php)
        collections = {}
        for collection in history.KINDS:
            before = history.snapshot(site, php)
            original = history.record(before, collection)
            delete(collection)
            page.get_by_role("link", name="Deleted content", exact=True).first.click()
            page.wait_for_url(recovery.url(base))
            if collection == "posts":
                capture("list")
            entry = open_review(collection)
            expect(page.locator("[data-recovery-content]")).to_contain_text(original["text" if collection == "banners" else "body"])
            check(page.locator("[data-history-injection]").count() == 0, "Saved content executed as HTML in the browser review.")
            button = page.locator("[data-recovery-restore]").get_by_role("button",
                name="Restore as draft" if collection == "posts" else "Restore disabled", exact=True)
            keyboard_focus(page, button)
            capture(collection + "-review")
            read_before = history.snapshot(site, php)
            page.reload(wait_until="networkidle")
            history.no_writes(read_before, history.snapshot(site, php), "Browser recovery review reload")
            submit(page, button, "Browser restore deleted " + collection)
            after = history.snapshot(site, php)
            restored = history.record(after, collection)
            check(history.safe_state(restored, collection) and all(restored.get(key) == original.get(key)
                for key in ("id", "slug", "created_at", "body", "text", "access", "history_unknown", "editorial_history")),
                "Browser recovery changed saved identity/content/history or published an already offline item.")
            page.reload(wait_until="networkidle")
            editor = page.locator('form[data-publication-editor="' + collection + '"]')
            if collection == "posts":
                expect(editor.locator('[name="status"]')).to_have_value("draft")
            else:
                expect(editor.locator('[name="enabled"]')).not_to_be_checked()
            collections[collection] = {"actual_delete_filter_review_restore_reload": True,
                                       "same_identity_content_audience_history": True, "restored_offline": True, "keyboard_focus": True}

        # A second actual tab restores/redeletes the item while the old review stays open.
        delete()
        old_entry = open_review()
        stale_control = page.locator("[data-recovery-restore]").get_by_role("button", name="Restore as draft", exact=True)
        second = context.new_page()
        second.goto(recovery.url(base, "posts", old_entry["id"]), wait_until="networkidle")
        submit(second, second.get_by_role("button", name="Restore as draft", exact=True), "Second tab restore")
        delete(target=second)
        later = recovery.selected(history.snapshot(site, php))
        check(later["id"] != old_entry["id"], "A later browser deletion reused its old recovery identity.")
        before = history.snapshot(site, php)
        with page.expect_navigation(wait_until="networkidle") as navigation:
            stale_control.click()
        check(navigation.value is not None and navigation.value.status == 409, "An old real browser review restored a later deletion.")
        expect(page.locator(".admin-alert.error")).to_be_visible()
        check(page.locator("form[data-recovery-restore], form[data-recovery-purge]").count() == 0,
              "The stale browser response rebound its mutation form to a newer item.")
        history.no_writes(before, history.snapshot(site, php), "Two-tab recovery replay")
        capture("stale-form")
        second.close()
        open_review()
        submit(page, page.get_by_role("button", name="Restore as draft", exact=True), "Reviewed later deletion restore")

        # Native required confirmation and the separate permanent-removal control.
        delete(name="retention")
        purged = open_review(name="retention")
        page.locator("[data-recovery-permanent]").get_by_text("Permanently remove this item", exact=True).click()
        confirm = page.get_by_label("I understand this item and its saved history will be permanently removed.", exact=True)
        remove = page.get_by_role("button", name="Permanently remove", exact=True)
        count = len(actions)
        remove.click()
        check(not confirm.evaluate("element => element.validity.valid") and len(actions) == count,
              "The browser submitted permanent removal without its required acknowledgement.")
        confirm.check(); keyboard_focus(page, remove)
        capture("permanent-confirm")
        submit(page, remove, "Browser confirmed permanent removal")
        check(not any(entry["id"] == purged["id"] for entry in recovery.entries(history.snapshot(site, php))),
              "The actual permanent-removal control retained the selected item.")
        capture("empty")

        # Ordinary forms still work when scripts are disabled.
        no_script = browser.new_context(viewport=viewport, java_script_enabled=False)
        try:
            plain = no_script.new_page(); login(plain, base)
            delete("banners", target=plain)
            entry = open_review("banners", target=plain)
            submit(plain, plain.get_by_role("button", name="Restore disabled", exact=True), "No-script recovery")
            check(history.record(history.snapshot(site, php), "banners")["enabled"] is False,
                  "No-script recovery enabled the saved banner.")
        finally:
            no_script.close()

        final = history.snapshot(site, php)
        http.protected(initial, final)
        check(initial["metadata"] == final["metadata"] and initial["marketing"]["events"] == final["marketing"]["events"],
              "Browser recovery changed media or recorded marketing events.")
        if case["mode"] == "mysql":
            http.independent_sql(final)
        check(not errors and case["capture"].connections == 0 and not case["capture"].payloads,
              "Browser recovery raised JavaScript errors or contacted SMTP.")
        check(actions.count("editorial_deleted_restore") == 5 and actions.count("editorial_deleted_purge") == 1
              and actions.count("delete_publication") == 5, "A required real recovery control was omitted.")
        return {"browser_checks": http.checks - started, "collections": collections, "two_tab_redelete_old_form_refused": True,
                "native_and_server_confirmation": True, "no_script_delete_review_restore": True,
                "real_owner_post_actions": actions, "geometry": measures, "screenshots": screenshots,
                "javascript_errors": errors, "smtp_connections": case["capture"].connections, "smtp_messages": len(case["capture"].payloads)}
    finally:
        context.close()


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("file", "mysql", "both"), default="both")
    requested = parser.parse_args()
    evidence = recovery.evidence_directory()
    summary = {"php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "playwright": importlib.metadata.version("playwright"), "server": "php -S without any router script", "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-recovery-browser-") as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        archive, package = recovery.prepare_package(work); summary.update(package)
        options = {"headless": True}
        executable = os.environ.get("VELVET_CHROMIUM") or os.environ.get("VV_TEST_CHROME")
        if executable:
            options["executable_path"] = executable
        browser = playwright.chromium.launch(**options); summary["browser"] = browser.version
        try:
            for mode in (("file", "mysql") if requested.mode == "both" else (requested.mode,)):
                for path_label, prefix, viewport in (("root-desktop", "", {"width": 1440, "height": 1000}),
                    ("nested-mobile", "/nested/velvet", {"width": 390, "height": 844})):
                    label = mode + "-" + path_label
                    with http.runtime(work, archive, mode, "recovery-" + label, prefix, evidence) as case:
                        case["label"] = label; history.seed(case)
                        activation = activate_mysql(browser, case, viewport) if mode == "mysql" else None
                        summary["cases"][label] = {"viewport": viewport, "mysql_activation": activation,
                            "http": recovery.http_case(case), "browser": browser_case(browser, case, viewport, evidence)}
                    print("Editorial recovery browser " + label + ": PASS.", flush=True)
        finally:
            browser.close()
    if evidence:
        (evidence / "editorial-recovery-browser-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial recovery browser acceptance passed: " + requested.mode + " root-desktop/nested-mobile.", flush=True)


if __name__ == "__main__":
    run()
