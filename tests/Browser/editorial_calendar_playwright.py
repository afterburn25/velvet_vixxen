#!/usr/bin/env python3
"""Real publishing calendar and expiry controls in four installed packages."""
from __future__ import annotations

from datetime import datetime
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.parse import parse_qs
from zoneinfo import ZoneInfo

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("vvx_calendar_http", ROOT / "tests/Http/editorial_calendar_http.py")
assert spec and spec.loader
calendar = importlib.util.module_from_spec(spec)
spec.loader.exec_module(calendar)
http, check = calendar.http, calendar.check


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
              and response.request.redirected_from.method == "POST", label + " omitted POST/Redirect/GET.")
    check(page.locator(".admin-alert.error").count() == 0,
          label + " returned an owner error: " + " ".join(page.locator(".admin-alert.error").all_inner_texts()))


def filter_calendar(page, month, kind):
    form = page.locator("[data-calendar-filter-form]")
    form.get_by_label("Month", exact=True).fill(month)
    kind_control = form.get_by_role("combobox", name="Content type", exact=True)
    expect(kind_control).to_have_attribute("name", "type")
    kind_control.select_option(kind)
    with page.expect_navigation(wait_until="networkidle") as navigation:
        form.get_by_role("button", name="Show calendar", exact=True).click()
    response = navigation.value
    check(response is not None and response.status == 200 and response.request.method == "GET",
          "Calendar filtering did not use the real GET form.")
    expect(page.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-month", month)
    expect(page.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-filter", kind)


def geometry(page, label):
    measured = page.evaluate("""() => ({
      viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll('[data-calendar-filter-form] input:not([type=hidden]), [data-calendar-filter-form] select, [data-calendar-filter-form] button, .marketing-calendar-navigation a, .marketing-calendar-day-link, [data-calendar-edit], [data-calendar-undated] summary')]
        .filter(element => element.getClientRects().length).map(element => {
          const rect = element.getBoundingClientRect();
          return {name: element.innerText || element.getAttribute('aria-label') || element.name,
                  day: element.classList.contains('marketing-calendar-day-link'), left: rect.left,
                  right: rect.right, width: rect.width, height: rect.height};
        })
    })""")
    check(measured["content"] <= measured["viewport"] + 1, label + " overflows horizontally: " + repr(measured))
    check(all(control["left"] >= -1 and control["right"] <= measured["viewport"] + 1
              and control["height"] >= (32 if control["day"] else 40) for control in measured["controls"]),
          label + " contains clipped or undersized controls: " + repr(measured))
    return measured


def keyboard_focus(page, control):
    control.focus()
    page.keyboard.press("Shift+Tab")
    page.keyboard.press("Tab")
    check(control.evaluate('(element) => document.activeElement === element && element.matches(":focus-visible")'),
          "A calendar control is not keyboard reachable with visible focus.")


def screenshot(page, evidence, label):
    if evidence is None: return None
    pictures = page.locator("img:visible").all()
    for picture in pictures:
        picture.scroll_into_view_if_needed()
        check(picture.evaluate("""async image => { await image.decode(); return image.complete && image.naturalWidth > 0; }"""),
              "A rendered calendar/editor image failed to decode.")
    page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
    name = "editorial-calendar-" + label + ".png"
    page.screenshot(path=str(evidence / name), full_page=True)
    check((evidence / name).stat().st_size > 1000, "Required calendar screenshot is empty.")
    return name


def browser_activate(browser, case, viewport):
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    actions = []
    page.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
            if request.method == "POST" else None)
    try:
        before = calendar.snapshot(case["site"], case["php"])
        check(before["status"]["state"] == "ready", "Browser SQL activation requires an unmoved owned fixture.")
        owner_login(page, case["base"])
        page.goto(case["base"] + "/admin/?page=marketing-storage", wait_until="networkidle")
        submit(page, page.get_by_role("button", name="Preview Move", exact=True), "Calendar SQL review", redirect=False)
        submit(page, page.get_by_role("button", name="Move to MySQL", exact=True), "Calendar SQL move")
        expect(page.locator("[data-marketing-storage]")).to_have_attribute("data-storage-state", "mysql")
        after = calendar.snapshot(case["site"], case["php"])
        http.independent_sql(after)
        check(before["marketing"] == after["marketing"] and before["legacy_sha256"] == after["legacy_sha256"]
              and before["metadata"] == after["metadata"] and before["calendar"] == after["calendar"],
              "The browser SQL migration changed the imported calendar/media fixture.")
        check(actions == ["preview_marketing_storage", "migrate_marketing_storage"], "SQL activation did not use both real owner controls.")
        return {"real_owner_post_actions": actions, "exact_import_preserved": True, "mysql_version": after["mysql_version"]}
    finally:
        context.close()


def browser_case(browser, case, viewport, evidence):
    site, base, php, label = case["site"], case["base"], case["php"], case["label"]
    context = browser.new_context(viewport=viewport)
    owner = context.new_page()
    errors, actions, screenshots, measures = [], [], [], {}
    started = http.checks
    context.on("page", lambda page: page.on("pageerror", lambda error: errors.append(str(error))))
    owner.on("pageerror", lambda error: errors.append(str(error)))
    owner.on("request", lambda request: actions.extend(parse_qs(request.post_data or "").get("action", []))
             if request.method == "POST" else None)

    def capture(page, suffix):
        name = screenshot(page, evidence, label + "-" + suffix)
        if name: screenshots.append(name)

    try:
        owner_login(owner, base)
        initial = calendar.snapshot(site, php)
        owner.goto(base + "/admin/?page=marketing&tab=calendar", wait_until="networkidle")
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute(
            "data-calendar-month", datetime.now(ZoneInfo("America/Chicago")).strftime("%Y-%m"))
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-filter", "all")
        filter_calendar(owner, calendar.MONTH, "all")
        expect(owner.get_by_role("heading", name="Publishing calendar", exact=True)).to_be_visible()
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-timezone", "America/Chicago")
        check(set(owner.locator("[data-calendar-event]").evaluate_all("rows => rows.map(row => row.dataset.calendarType)")) == set(calendar.TYPES),
              "The actual browser calendar omits a seeded content family.")
        measures["all-content"] = geometry(owner, label + " combined calendar")
        capture(owner, "all-content")
        day = owner.locator('[data-calendar-day="2030-02-01"] a')
        keyboard_focus(owner, day)
        day.click()
        check(owner.url.endswith("#calendar-day-2030-02-01")
              and owner.locator("#calendar-day-2030-02-01").evaluate("element => {const r=element.getBoundingClientRect();return r.top>=-1 && r.top<innerHeight;}"),
              "A real day link did not reach its agenda.")
        filter_calendar(owner, calendar.MONTH, "posts")
        check(set(owner.locator("[data-calendar-event]").evaluate_all("rows => rows.map(row => row.dataset.calendarType)")) == {"posts"},
              "The actual type control did not filter dated content.")
        previous = owner.get_by_role("link", name="Previous month", exact=True)
        keyboard_focus(owner, previous)
        previous.click()
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-month", "2030-01")
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-filter", "posts")
        expect(owner.locator('[data-calendar-source-id="calendar-post-previous-edge"]')).to_be_visible()
        owner.get_by_role("link", name="Next month", exact=True).click()
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-month", calendar.MONTH)
        measures["posts"] = geometry(owner, label + " post calendar")
        capture(owner, "posts")
        owner.get_by_role("link", name="This month", exact=True).click()
        current_month = datetime.now(ZoneInfo("America/Chicago")).strftime("%Y-%m")
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-month", current_month)
        expect(owner.locator("[data-publishing-calendar]")).to_have_attribute("data-calendar-filter", "posts")
        filter_calendar(owner, "2038-07", "posts")
        expect(owner.locator("[data-calendar-empty]")).to_be_visible()
        expect(owner.get_by_role("heading", name="No dated items this month", exact=True)).to_be_visible()
        measures["empty"] = geometry(owner, label + " empty calendar")
        capture(owner, "empty-month")
        undated = owner.locator("[data-calendar-undated]")
        undated.locator("summary").click()
        expect(undated.locator('[data-calendar-source-id="calendar-post-undated"]')).to_be_visible()
        expect(undated.locator('[data-calendar-source-id="calendar-post-invalid"]')).to_contain_text("review")
        measures["undated"] = geometry(owner, label + " undated review")
        capture(owner, "undated-review")
        filter_calendar(owner, "2026-11", "posts")
        first = owner.locator('[data-calendar-source-id="calendar-post-fold-first"]').first
        second = owner.locator('[data-calendar-source-id="calendar-post-fold-second"]').first
        expect(first).to_contain_text("CDT")
        expect(second).to_contain_text("CST")
        capture(owner, "dst-fold")
        filter_calendar(owner, calendar.MONTH, "all")
        # Editor links must open the actual existing records. Their paths are
        # inspected in HTTP acceptance; Chromium follows one from every family.
        linked, native_music_editors = [], []
        for kind in calendar.TYPES:
            entry = owner.locator('[data-calendar-event][data-calendar-type="' + kind + '"] [data-calendar-edit]').first
            expect(entry).to_be_visible()
            entry.click()
            owner.wait_for_load_state("networkidle")
            check(owner.url.startswith(base + "/admin/") and owner.locator(".login-card").count() == 0
                  and owner.locator(".admin-alert.error").count() == 0, "Calendar editor link failed for " + kind)
            linked.append({"type": kind, "path": owner.url.removeprefix(base)})
            owner.goto(calendar.calendar_url(base), wait_until="networkidle")
        # A separate no-script browser uses the same saved owner session and
        # native GET controls; no data mutation is needed for calendar use.
        passive = browser.new_context(viewport=viewport, storage_state=context.storage_state(), java_script_enabled=False)
        try:
            page = passive.new_page()
            page.goto(calendar.calendar_url(base), wait_until="networkidle")
            filter_calendar(page, calendar.MONTH, "music")
            expect(page.locator("[data-calendar-event]")).not_to_have_count(0)
            check(set(page.locator("[data-calendar-event]").evaluate_all("rows => rows.map(row => row.dataset.calendarType)")) == {"music"},
                  "Native no-script calendar filtering failed.")
            measures["no-script"] = geometry(page, label + " script-free calendar")
            capture(page, "no-script-music")
            for name, collection, panel in (("album", "albums", "albums"), ("track", "tracks", "tracks"),
                                             ("playlist", "audioPlaylists", "playlists")):
                expected_id = str(initial["calendar"]["manifest"]["media"][name]["id"])
                entry = page.locator('[data-calendar-event^="' + collection + ':record:' + expected_id + ':"] [data-calendar-edit]')
                expect(entry).to_be_visible()
                entry.click()
                page.wait_for_load_state("networkidle")
                active_panel = page.locator('[data-tab-panel="' + panel + '"].active')
                expect(active_panel).to_be_visible()
                expect(active_panel.locator('form [name="id"]')).to_have_value(expected_id)
                expect(active_panel.locator('form [name="title"]')).to_have_value("Calendar " + name)
                check(page.locator(".admin-alert.error").count() == 0,
                      "The native calendar music edit link failed for " + name)
                native_music_editors.append(name)
                page.goto(calendar.calendar_url(base, kind="music"), wait_until="networkidle")
        finally:
            passive.close()
        calendar.no_writes(initial, calendar.snapshot(site, php), "Browser calendar filtering/navigation and editor links")
        check(not actions, "Browsing the calendar submitted a hidden mutation action.")

        # Use the real post editor reached through a dated calendar entry.
        filter_calendar(owner, calendar.MONTH, "posts")
        owner.locator('[data-calendar-source-id="calendar-post-browser"] [data-calendar-edit]').click()
        owner.wait_for_load_state("networkidle")
        form = owner.locator('form[data-publication-editor="posts"]')
        expect(form.get_by_label("Expires at (optional)", exact=True)).to_have_value("")
        form.get_by_label("Expires at (optional)", exact=True).fill("2030-02-25T12:30:45")
        form.locator('[name="status"]').select_option("published")
        submit(owner, form.get_by_role("button", name="Save Post", exact=True), "Browser expiry save")
        saved = calendar.record(calendar.snapshot(site, php), "browser")
        check(saved["expires_at"] == "2030-02-25T18:30:45+00:00" and saved["status"] == "published",
              "The real expiry editor did not save its UTC instant/status.")
        original_version = next(row["id"] for row in saved["editorial_history"]["entries"] if "expires_at" not in row["snapshot"])
        owner.reload(wait_until="networkidle")
        expect(owner.locator('[name="expires_at"]')).to_have_value("2030-02-25T12:30:45")
        capture(owner, "expiry-saved-editor")
        before_bad = calendar.snapshot(site, php)
        owner.locator('[name="expires_at"]').fill("2030-02-01T12:00")
        owner.locator('[name="title"]').fill("Browser attempted expiry title")
        with owner.expect_navigation(wait_until="networkidle") as navigation:
            owner.get_by_role("button", name="Save Post", exact=True).click()
        response = navigation.value
        check(response is not None and response.request.method == "POST" and response.status in (200, 409, 422),
              "The invalid browser expiry did not remain on the refused actual POST.")
        expect(owner.locator(".admin-alert.error")).to_be_visible()
        expect(owner.locator('[name="expires_at"]')).to_have_value(re.compile(r"^2030-02-01T12:00(?::00)?$"))
        expect(owner.locator('[name="title"]')).to_have_value("Browser attempted expiry title")
        calendar.no_writes(before_bad, calendar.snapshot(site, php), "Invalid browser expiration")
        capture(owner, "expiry-refused")
        owner.goto(calendar.editor_url(base, "browser"), wait_until="networkidle")
        owner.locator('[data-publication-editor-card="posts"] [data-publication-history]').click()
        owner.wait_for_url("**page=marketing-history**")
        owner.locator('[data-history-entry="' + original_version + '"]').get_by_role("link", name="Review version", exact=True).click()
        owner.wait_for_url("**version=" + original_version + "**")
        expect(owner.locator("[data-history-selected]")).to_contain_text("No expiry")
        capture(owner, "no-expiry-history")
        submit(owner, owner.locator("form[data-history-restore]").get_by_role("button", name="Restore as draft", exact=True),
               "Browser old no-expiry restore")
        restored = calendar.record(calendar.snapshot(site, php), "browser")
        check(restored["status"] == "draft" and "expires_at" not in restored,
              "The real history restore retained newer expiry or published the old post.")
        expect(owner.locator('[name="expires_at"]')).to_have_value("")
        expect(owner.locator('[name="status"]')).to_have_value("draft")
        capture(owner, "restored-draft")
        final = calendar.snapshot(site, php)
        http.protected(initial, final)
        check(initial["metadata"] == final["metadata"] and initial["calendar"] == final["calendar"]
              and initial["marketing"]["events"] == final["marketing"]["events"],
              "Browser expiry/calendar actions changed original media, scene versions or marketing events.")
        check(actions == ["save_post", "save_post", "restore_publication"], "Browser controls submitted unexpected mutation actions.")
        check(not errors, "Calendar/expiry browser raised JavaScript errors: " + repr(errors))
        if case["mode"] == "mysql": http.independent_sql(final)
        check(case["capture"].connections == 0 and not case["capture"].payloads, "Browser calendar/expiry contacted SMTP.")
        return {"browser_checks": http.checks - started, "real_owner_post_actions": actions, "editor_links": linked,
                "real_month_filter_navigation_and_day_jump": True, "keyboard_visible_focus": True,
                "native_no_script_filter": True, "native_music_editor_links": native_music_editors,
                "expiry_save_reload_validation_and_history_restore": True,
                "geometry": measures, "screenshots": screenshots, "javascript_errors": errors,
                "smtp_connections": case["capture"].connections, "smtp_messages": len(case["capture"].payloads)}
    finally:
        context.close()


def run():
    evidence = calendar.evidence_directory()
    summary = {"report_kind": "editorial_calendar_browser", "php": subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True),
               "playwright": importlib.metadata.version("playwright"), "server": "php -S without any router script", "cases": {}}
    with tempfile.TemporaryDirectory(prefix="vvx-editorial-calendar-browser-") as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        archive, package = calendar.prepare_package(work)
        summary.update(package)
        options = {"headless": True}
        executable = os.environ.get("VELVET_CHROMIUM") or os.environ.get("VV_TEST_CHROME")
        if executable: options["executable_path"] = executable
        browser = playwright.chromium.launch(**options)
        summary["browser"] = browser.version
        try:
            for mode in ("file", "mysql"):
                for path_label, prefix, viewport in (("root-desktop", "", {"width": 1440, "height": 1000}),
                                                       ("nested-mobile", "/nested/velvet", {"width": 390, "height": 844})):
                    label = mode + "-" + path_label
                    with http.runtime(work, archive, mode, "calendar-" + label, prefix, evidence) as case:
                        case["label"] = label
                        calendar.seed(case)
                        activation = browser_activate(browser, case, viewport) if mode == "mysql" else None
                        result = calendar.http_case(case)
                        summary["cases"][label] = {"viewport": viewport, "mysql_activation": activation,
                                                  "http": result, "browser": browser_case(browser, case, viewport, evidence)}
                    print("Editorial Calendar browser " + label + ": PASS.", flush=True)
        finally:
            browser.close()
    if evidence:
        (evidence / "editorial-calendar-browser-evidence.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    print("Editorial calendar actual-package browser acceptance passed: four file/MySQL root-desktop/nested-mobile cases.", flush=True)


if __name__ == "__main__":
    run()
