"""Shared helpers for real Composer acceptance in a disposable application."""
from __future__ import annotations

import os
from public_urls import is_route_url, site_url
import re
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import expect

BASE = os.environ.get("VELVET_BASE_URL", "http://127.0.0.1:8765/").rstrip("/") + "/"
RUNTIME = Path(os.environ.get("VELVET_TEST_RUNTIME", "/nonexistent"))
if urlparse(BASE).hostname not in {"127.0.0.1", "localhost", "::1"} or not (RUNTIME / ".vvx-test-runtime").is_file():
    raise SystemExit("Composer acceptance requires a marked disposable localhost application.")


def url(path: str) -> str:
    return site_url(BASE, path)


def launch(playwright):
    options = {"headless": True}
    executable = os.environ.get("VELVET_CHROMIUM") or os.environ.get("VV_TEST_CHROME")
    if executable:
        options["executable_path"] = executable
    return playwright.chromium.launch(**options)


def login(page) -> None:
    page.goto(url("admin/?page=login"), wait_until="networkidle")
    page.get_by_label("Email address").fill("admin@example.com")
    page.get_by_label("Password").fill("VelvetDemo12")
    page.get_by_role("button", name="Sign in", exact=True).click()
    page.wait_for_url("**/admin/**")
    page.goto(url("admin/?page=composer"), wait_until="networkidle")
    expect(page.locator("[data-canvas]")).to_be_visible()


def control(page, name: str, value) -> None:
    field = page.locator(f'[data-control="{name}"]')
    kind = field.get_attribute("type")
    if kind in {"range", "color"}:
        field.evaluate("(field, value) => { field.value = String(value); field.dispatchEvent(new Event('input', {bubbles: true})); field.dispatchEvent(new Event('change', {bubbles: true})); }", value)
    else:
        field.fill(str(value))
        field.press("Tab")


def percent(locator, name: str) -> float:
    return locator.evaluate("(node, name) => parseFloat(node.style[name])", name)


def choose_device(page, device: str) -> None:
    page.locator(f'[data-device="{device}"]').click()


def choose_section(page, section: str) -> None:
    page.locator(f'[data-section="{section}"]').click()


def read_scene(context, section="hero") -> dict:
    response = context.request.get(url(f"api/scene.php?section={section}"))
    data = response.json()
    assert response.ok and data.get("ok"), f"Cannot read {section} scene"
    return data


def action(page, name: str) -> dict:
    with page.expect_response(lambda response: is_route_url(response.url, BASE, "api/scene.php") and response.request.method == "POST") as result:
        page.locator(f'[data-action="{name}"]').click()
    response = result.value
    data = response.json()
    assert response.ok and data.get("ok"), f"Scene {name} failed: {data.get('message')}"
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    expect(page.locator("[data-status]")).not_to_have_class(re.compile(r"\berror\b"))
    return data


def background_loaded(page, expected_url: str, public=False) -> None:
    target = page.locator('[data-scene="hero"] .scene-background' if public else '[data-canvas-bg]')
    expect(target).to_have_attribute("src", expected_url)
    target.evaluate("image => image.decode()")
    assert target.evaluate("image => image.complete && image.naturalWidth > 0 && image.dataset.fallbackApplied !== '1'"), f"Background did not decode: {expected_url}"


def dismiss_age(page) -> None:
    if page.locator("#ageGate:not([hidden])").count():
        page.locator("#ageEnter").click()


def screenshot(page, filename: str) -> None:
    destination = os.environ.get("VELVET_SCREENSHOT_DIR", "")
    if destination:
        Path(destination).mkdir(parents=True, exist_ok=True)
        page.screenshot(path=str(Path(destination) / filename), full_page=True)


def handles_reachable(page) -> None:
    canvas = page.locator('[data-canvas]')
    canvas.scroll_into_view_if_needed()
    handles = page.locator('[data-selection-overlay] [data-handle]')
    expect(handles).to_have_count(4)
    details = handles.evaluate_all("""handles => {
      const frame = document.querySelector('[data-canvas]').getBoundingClientRect();
      return handles.map(handle => {
        const rect = handle.getBoundingClientRect();
        const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
        return {corner: handle.dataset.handle, inside: rect.left >= frame.left && rect.top >= frame.top && rect.right <= frame.right && rect.bottom <= frame.bottom, hit: hit === handle};
      });
    }""")
    assert all(item["inside"] and item["hit"] for item in details), f"A resize handle became unreachable: {details}"


def drag_grip(page, dx: float, dy: float, before_up=None) -> None:
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    grip = page.locator('[data-selection-move]')
    box = grip.bounding_box()
    assert box, "Selected photo has no reachable move grip"
    x, y = box["x"] + box["width"] / 2, box["y"] + box["height"] / 2
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + dx, y + dy, steps=8)
    if before_up:
        before_up()
    page.mouse.up()
