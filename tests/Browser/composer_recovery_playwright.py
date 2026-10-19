"""Exercise recovered Composer controls against a disposable installed application."""
from __future__ import annotations

import os
import re
from pathlib import Path
from urllib.parse import urljoin, urlparse

from playwright.sync_api import expect, sync_playwright

BASE = os.environ.get("VELVET_BASE_URL", "http://127.0.0.1:8765/").rstrip("/") + "/"
RUNTIME = Path(os.environ.get("VELVET_TEST_RUNTIME", "/nonexistent"))
if urlparse(BASE).hostname not in {"127.0.0.1", "localhost", "::1"} or not (RUNTIME / ".vvx-test-runtime").is_file():
    raise SystemExit("Composer acceptance requires a marked disposable localhost test application.")


def url(path: str) -> str:
    return urljoin(BASE, path.lstrip("/"))


def control(page, name: str, value: float) -> None:
    field = page.locator(f'[data-control="{name}"]')
    field.fill(str(value))
    field.press("Tab")


def percent(locator, name: str) -> float:
    return locator.evaluate("(node, name) => parseFloat(node.style[name])", name)


def change_scene(page, action: str, section: str) -> dict:
    with page.expect_response(lambda response: "/api/scene.php" in response.url and response.request.method == "POST") as result:
        page.locator(f'[data-action="{action}"]').click()
    response = result.value
    data = response.json()
    assert response.ok and data.get("ok"), f"{section} {action} failed: {data.get('message')}"
    expect(page.locator("[data-status]")).not_to_have_class(re.compile(r"\berror\b"))
    return data


def scene(context, section: str) -> dict:
    response = context.request.get(url(f"api/scene.php?section={section}"))
    assert response.ok, f"Cannot read {section} scene"
    payload = response.json()
    assert payload.get("ok"), f"Cannot read {section} scene data"
    return payload["scene"]


def choose_device(page, device: str) -> None:
    page.locator(f'[data-device="{device}"]').click()


def add_photo(page):
    page.locator('[data-assets-tab="media"]').click()
    card = page.locator('[data-media-id="demo-album-cover"]')
    expect(card).to_have_count(1)
    card.hover()
    card.locator('[data-media-action="add"]').click()
    layer = page.locator(".composer-layer.selected")
    expect(layer).to_have_count(1)
    return layer


def move_in_two_steps(page, locator, dx: float, dy: float, property_name: str) -> None:
    locator.scroll_into_view_if_needed()
    box = locator.bounding_box()
    assert box and box["width"] > 0 and box["height"] > 0
    x, y = box["x"] + box["width"] / 2, box["y"] + box["height"] / 2
    before = percent(locator, property_name)
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + dx / 2, y + dy / 2, steps=3)
    halfway = percent(locator, property_name)
    page.mouse.move(x + dx, y + dy, steps=3)
    after = percent(locator, property_name)
    page.mouse.up()
    assert halfway != before and after != halfway, "Pointer movement stopped after the first update"


def resize_from_corner(page, target, handle_selector: str, dx: float, dy: float) -> None:
    handle = target.locator(handle_selector)
    if handle.count() == 0 and "content" not in handle_selector:
        handle = page.locator("[data-selection-overlay]").locator(handle_selector)
    handle.scroll_into_view_if_needed()
    box = handle.bounding_box()
    assert box
    before = percent(target, "width")
    x, y = box["x"] + box["width"] / 2, box["y"] + box["height"] / 2
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + dx, y + dy, steps=6)
    page.mouse.up()
    assert percent(target, "width") > before, "Corner resize did not increase the object width"


def assert_stable_resize_focus(page, layer) -> None:
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    handle = page.locator('[data-selection-overlay] [data-handle="se"]')
    handle.focus()
    original = handle.element_handle()
    assert original is not None
    # Reload the real layer image to reproduce the asynchronous load callback
    # which previously replaced a resize handle immediately after a drag.
    layer.locator('img').evaluate('''image => new Promise((resolve, reject) => {
      image.addEventListener('load', () => resolve(), {once: true});
      image.addEventListener('error', () => reject(new Error('The selected image failed to reload')), {once: true});
      image.src = image.src;
    })''')
    assert original.evaluate('node => node.isConnected && document.activeElement === node'), 'Image loading detached or unfocused the resize handle'
    viewport = page.viewport_size
    page.set_viewport_size({'width': viewport['width'] - 64, 'height': viewport['height']})
    # Let native layout and ResizeObserver complete without a timed sleep.
    page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
    assert original.evaluate('node => node.isConnected && document.activeElement === node'), 'Canvas resizing detached or unfocused the resize handle'
    page.set_viewport_size(viewport)
    page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
    expect(handle).to_be_focused()


def place_content(page, values: dict) -> None:
    page.locator('[data-property-tab="content"]').click()
    # Size first so changing the origin does not clamp against the previous size.
    for key in ["width", "height", "x", "y"]:
        control(page, "content" + key.capitalize(), values[key])


def screenshot(page, filename: str) -> None:
    destination = os.environ.get("VELVET_SCREENSHOT_DIR", "")
    if destination:
        Path(destination).mkdir(parents=True, exist_ok=True)
        page.screenshot(path=str(Path(destination) / filename), full_page=True)


with sync_playwright() as playwright:
    options = {"headless": True}
    executable = os.environ.get("VELVET_CHROMIUM") or os.environ.get("VV_TEST_CHROME")
    if executable:
        options["executable_path"] = executable
    browser = playwright.chromium.launch(**options)
    context = browser.new_context(viewport={"width": 1680, "height": 1100})
    page = context.new_page()
    errors: list[str] = []
    page.on("pageerror", lambda error: errors.append(str(error)))

    page.goto(url("admin/?page=login"), wait_until="networkidle")
    page.get_by_label("Email address").fill("admin@example.com")
    page.get_by_label("Password").fill("VelvetDemo12")
    page.get_by_role("button", name="Sign in", exact=True).click()
    page.wait_for_url("**/admin/**")
    page.goto(url("admin/?page=composer"), wait_until="networkidle")
    expect(page.locator("[data-content-box]")).to_be_visible()
    expect(page.locator('[data-media-id="default-hero"] img')).to_have_attribute("src", url("images/defaults/canva-hero-preview.jpg"))
    expect(page.locator('[data-media-id="demo-album-cover"] img')).to_have_attribute("src", url("uploads/images/demo/album-cover.jpg"))
    assert page.locator("[data-media-list] img").evaluate_all("images => images.every(image => image.complete && image.naturalWidth > 0 && image.dataset.fallbackApplied !== '1')"), "A bundled or uploaded Composer thumbnail failed to load"

    layer = add_photo(page)
    control(page, "layerWidth", 18)
    move_in_two_steps(page, layer, 24, 8, "left")
    assert_stable_resize_focus(page, layer)
    resize_from_corner(page, layer, ".resize-handle.se", 12, 8)

    # Both size boundaries must survive an actual draft save and reload.
    layer_id = layer.get_attribute("data-layer-id")
    for width in [400, 0.5]:
        control(page, "layerWidth", width)
        assert percent(layer, "width") == width
        change_scene(page, "save", "hero")
        stored = next(row for row in scene(context, "hero")["devices"]["desktop"]["layers"] if row["id"] == layer_id)
        assert stored["width"] == width, f"Saved layer width was clamped away from {width}%"
    page.reload(wait_until="networkidle")
    layer = page.locator(f'[data-layer-id="{layer_id}"]')
    assert percent(layer, "width") == 0.5
    # Restore an ordinary editing size through the same owner controls.
    layer.click(force=True)
    control(page, "layerWidth", 18)
    control(page, "layerX", 76)
    control(page, "layerY", 45)

    content_box = page.locator("[data-content-box]")
    move_in_two_steps(page, content_box, 15, 8, "left")
    resize_from_corner(page, content_box, ".content-resize-handle.se", 10, 4)
    placements = {
        "desktop": {"x": 12, "y": 22, "width": 32, "height": 48},
        "tablet": {"x": 9, "y": 28, "width": 48, "height": 50},
        "mobile": {"x": 8, "y": 55, "width": 84, "height": 40},
    }
    place_content(page, placements["desktop"])

    page.locator('[data-action="pan-background"]').click()
    canvas = page.locator("[data-canvas]")
    canvas.scroll_into_view_if_needed()
    rect = canvas.bounding_box()
    assert rect
    background = page.locator("[data-canvas-bg]")
    before_pan = background.evaluate("image => image.style.objectPosition")
    x, y = rect["x"] + rect["width"] * 0.92, rect["y"] + rect["height"] * 0.07
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x - 20, y + 8, steps=6)
    page.mouse.up()
    after_pan = background.evaluate("image => image.style.objectPosition")
    assert before_pan != after_pan, "Background pan did not change the scene"
    page.locator('[data-action="undo"]').click()
    assert background.evaluate("image => image.style.objectPosition") == before_pan
    page.locator('[data-action="redo"]').click()
    assert background.evaluate("image => image.style.objectPosition") == after_pan
    page.locator('[data-action="pan-background"]').click()

    for device in ["tablet", "mobile"]:
        choose_device(page, device)
        assert page.locator(".composer-layer").count() == 0, "Editing desktop unexpectedly changed another device"
        add_photo(page)
        control(page, "layerWidth", 22 if device == "tablet" else 55)
        control(page, "layerX", 72 if device == "tablet" else 50)
        control(page, "layerY", 36 if device == "tablet" else 24)
        place_content(page, placements[device])
    choose_device(page, "desktop")
    assert abs(percent(content_box, "left") - placements["desktop"]["x"]) < 0.01
    change_scene(page, "save", "hero")

    public = context.new_page()
    public.on("pageerror", lambda error: errors.append(str(error)))
    public.goto(url("/"), wait_until="networkidle")
    assert public.locator('[data-scene="hero"] .public-scene-layer').count() == 0, "Saving a draft modified the published composition"
    change_scene(page, "publish", "hero")
    page.reload(wait_until="networkidle")
    stored = scene(context, "hero")
    for device, expected in placements.items():
        choose_device(page, device)
        for key, value in expected.items():
            assert stored["devices"][device]["contentBox"][key] == value
            css_property = {"x": "left", "y": "top"}.get(key, key)
            assert abs(percent(content_box, css_property) - value) < 0.01

    # Hero and Footer carry independent content placements and publication history.
    page.locator('[data-section="footer"]').click()
    choose_device(page, "desktop")
    footer_placement = {"x": 9, "y": 22, "width": 40, "height": 58}
    place_content(page, footer_placement)
    change_scene(page, "publish", "footer")
    page.locator('[data-section="hero"]').click()
    assert percent(content_box, "left") == placements["desktop"]["x"]
    screenshot(page, "composer-desktop.png")

    for device, viewport in [("desktop", {"width": 1440, "height": 1000}), ("tablet", {"width": 900, "height": 1000}), ("mobile", {"width": 390, "height": 844})]:
        public.set_viewport_size(viewport)
        public.goto(url("/"), wait_until="networkidle")
        if public.locator("#ageGate:not([hidden])").count():
            public.locator("#ageEnter").click()
        live_content = public.locator('[data-scene="hero"] .hero-content')
        for key, value in placements[device].items():
            css_property = {"x": "left", "y": "top"}.get(key, key)
            assert abs(percent(live_content, css_property) - value) < 0.01, f"Published {device} content placement was not rendered"
        image = public.locator('[data-scene="hero"] .public-scene-layer img')
        expect(image).to_have_count(1)
        expect(image).to_have_attribute("src", url("uploads/images/demo/album-cover.jpg"))
        assert image.evaluate("image => image.complete && image.naturalWidth > 0")
        expect(live_content.locator(".hero-buttons a").first).to_be_visible()
        if device == "desktop":
            closing = public.locator('[data-scene="footer"] .closing-content')
            assert percent(closing, "left") == footer_placement["x"]

    page.set_viewport_size({"width": 390, "height": 844})
    choose_device(page, "mobile")
    page.locator("[data-content-box]").scroll_into_view_if_needed()
    expect(page.locator("[data-content-box]")).to_be_visible()
    assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1"), "Mobile Composer controls overflow the viewport"
    screenshot(page, "composer-mobile.png")
    assert not errors, "Composer/public JavaScript errors: " + "; ".join(errors)
    context.close()
    browser.close()

print("Composer recovery acceptance passed: drag, resize, 0.5–400% persistence, live text/buttons, per-device placement, background pan, undo/redo, draft isolation, and public image URLs.")
