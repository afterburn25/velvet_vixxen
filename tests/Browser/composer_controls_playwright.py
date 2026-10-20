"""Exercise off-frame editing, per-device backgrounds, draft preview and rollback."""
from __future__ import annotations

from public_urls import is_route_url

import json
import struct
import zlib

from playwright.sync_api import expect, sync_playwright

from composer_support import (
    BASE,
    RUNTIME, action, choose_device, choose_section, control, dismiss_age,
    drag_grip, handles_reachable, launch, login, percent, read_scene, screenshot, url,
)


def crop_fixture():
    """A geometric color strip, with no person or production placeholder content."""
    width, height = 120, 360
    pixels = b"".join(b"\x00" + (b"\x1e\xe0\xb2\xff" if row < 240 else b"\xff\x39\x69\xff") * width for row in range(height))
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)
    png = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(pixels)) + chunk(b"IEND", b"")
    path = RUNTIME / "storage/temp/composer-crop-fixture.png"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(png)
    return path


with sync_playwright() as playwright:
    browser = launch(playwright)
    context = browser.new_context(viewport={"width": 1680, "height": 1100})
    errors = []
    context.on("page", lambda child: child.on("pageerror", lambda error: errors.append(str(error))))
    page = context.new_page()
    login(page)
    fixture_store = RUNTIME / 'storage/data.json'
    fixture_data = json.loads(fixture_store.read_text())
    fixture_data['media']['protected-composer-fixture'] = {
        'id': 'protected-composer-fixture', 'title': 'Protected gallery original',
        'type': 'gallery', 'mime': 'image/jpeg', 'private': True,
        'file': 'private:images/protected-original.jpg',
        'thumbnail': 'images/defaults/canva-hero-preview.jpg',
    }
    fixture_store.write_text(json.dumps(fixture_data))
    page.reload(wait_until='networkidle')
    expect(page.locator('[data-media-id="protected-composer-fixture"]')).to_have_count(0)
    assert all(asset['id'] != 'protected-composer-fixture' for asset in page.evaluate('window.COMPOSER_DATA.media')), 'Protected media entered the public composition picker'

    settings = {
        "desktop": {"bgX": 18, "bgY": 67, "bgZoom": 145, "bgBrightness": 88, "bgContrast": 128, "bgSaturation": 78, "bgBlur": 2, "overlay": 32, "overlayColor": "#170b14"},
        "tablet": {"bgX": 70, "bgY": 30, "bgZoom": 170, "bgBrightness": 105, "bgContrast": 112, "bgSaturation": 92, "bgBlur": 1, "overlay": 24, "overlayColor": "#19070e"},
        "mobile": {"bgX": 40, "bgY": 80, "bgZoom": 115, "bgBrightness": 96, "bgContrast": 120, "bgSaturation": 106, "bgBlur": 0, "overlay": 40, "overlayColor": "#0b0510"},
    }
    property_map = {"bgX": "x", "bgY": "y", "bgZoom": "zoom", "bgBrightness": "brightness", "bgContrast": "contrast", "bgSaturation": "saturation", "bgBlur": "blur", "overlay": "overlay", "overlayColor": "overlayColor"}
    for device, values in settings.items():
        choose_device(page, device)
        page.locator('[data-property-tab="background"]').click()
        for name, value in values.items():
            control(page, name, value)
    action(page, "save")
    saved = read_scene(context)["scene"]
    page.reload(wait_until="networkidle")
    for device, values in settings.items():
        choose_device(page, device)
        for name, value in values.items():
            expected = value / 100 if name == "bgZoom" else value
            assert saved["devices"][device]["background"][property_map[name]] == expected, f"{device} {name} was shared with another device"
        bg = page.locator('[data-canvas-bg]')
        assert bg.evaluate("image => image.style.objectPosition") == f"{values['bgX']}% {values['bgY']}%"
        assert bg.evaluate("image => image.style.transform") == f"scale({values['bgZoom'] / 100})"
    choose_device(page, "desktop")

    # The real upload endpoint supplies an image layer with a visibly distinct lower edge.
    page.locator('[data-action="toggle-upload"]').click()
    page.locator('#quickUpload input[name="image"]').set_input_files(str(crop_fixture()))
    with page.expect_response(lambda response: is_route_url(response.url, BASE, "api/upload.php") and response.request.method == "POST") as uploaded:
        page.locator('#quickUpload button').click()
    upload = uploaded.value.json()
    assert uploaded.value.ok and upload.get("ok"), "Crop fixture upload failed"
    asset = upload["asset"]
    card = page.locator(f'[data-media-id="{asset["id"]}"]')
    card.hover()
    card.locator('[data-media-action="add"]').click()
    layer = page.locator('.composer-layer.selected')
    layer_id = layer.get_attribute('data-layer-id')
    control(page, 'layerWidth', 10)
    control(page, 'layerX', 70)
    control(page, 'layerY', 50)
    page.locator('[data-snap-enabled]').uncheck()

    for x, y, axis, edge in [(-3, 50, 'left', 'left'), (103, 50, 'left', 'right'), (70, -3, 'top', 'top'), (70, 103, 'top', 'bottom')]:
        control(page, 'layerX', 70)
        control(page, 'layerY', 50)
        frame = page.locator('[data-canvas]').bounding_box()
        assert frame
        drag_grip(page, (x - 70) / 100 * frame['width'], (y - 50) / 100 * frame['height'])
        assert abs(percent(layer, axis) - (x if axis == 'left' else y)) < 0.1, f"Dragging could not cross the {edge} edge"
        handles_reachable(page)
        box = layer.bounding_box()
        assert box
        if edge == 'left': assert box['x'] < frame['x']
        if edge == 'right': assert box['x'] + box['width'] > frame['x'] + frame['width']
        if edge == 'top': assert box['y'] < frame['y']
        if edge == 'bottom': assert box['y'] + box['height'] > frame['y'] + frame['height']

    # Docked handles continue resizing while most of the photo is below the frame.
    before = percent(layer, 'width')
    handle = page.locator('[data-selection-overlay] [data-handle="se"]')
    box = handle.bounding_box()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.mouse.down()
    page.mouse.move(box['x'] + box['width'] / 2 + 12, box['y'] + box['height'] / 2 + 16, steps=6)
    page.mouse.up()
    assert percent(layer, 'width') > before, 'A docked off-frame handle failed to resize'
    assert abs(layer.evaluate('node => node.offsetHeight / node.offsetWidth') - 3) < 0.1, 'Corner resize distorted the photo aspect ratio'
    for x, y in [(-400, 50), (500, 50), (50, -400), (50, 500), (-400, 500)]:
        control(page, 'layerX', x)
        control(page, 'layerY', y)
        handles_reachable(page)
    page.locator('[data-action="layer-recover"]').click()
    assert percent(layer, 'left') == 50 and percent(layer, 'top') == 50
    control(page, 'layerWidth', 10)
    control(page, 'layerX', 70)

    page.locator('[data-action="layer-lock"]').click()
    expect(page.locator('[data-control="layerX"]')).to_be_disabled()
    expect(page.locator('[data-selection-overlay] [data-handle]')).to_have_count(0)
    locked = (percent(layer, 'left'), percent(layer, 'top'))
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    box = layer.bounding_box()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.mouse.down()
    page.mouse.move(box['x'] + box['width'] / 2 + 30, box['y'] + box['height'] / 2 + 15, steps=6)
    page.mouse.up()
    page.keyboard.press('ArrowRight')
    assert (percent(layer, 'left'), percent(layer, 'top')) == locked, 'Locked photo moved'
    page.locator('[data-action="layer-lock"]').click()
    page.locator('[data-action="layer-hide"]').click()
    expect(page.locator('.composer-layer')).to_have_count(0)
    expect(page.locator(f'[data-layer-select="{layer_id}"]')).to_be_visible()
    page.locator('[data-action="layer-hide"]').click()
    expect(page.locator('.composer-layer')).to_have_count(1)
    page.locator('[data-action="layer-duplicate"]').click()
    expect(page.locator('.composer-layer')).to_have_count(2)
    duplicate_id = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
    page.locator('[data-action="layer-back"]').click()
    assert page.locator('.composer-layer').first.get_attribute('data-layer-id') == duplicate_id, 'Move Back did not change paint order'
    page.locator('[data-action="layer-forward"]').click()
    assert page.locator('.composer-layer').last.get_attribute('data-layer-id') == duplicate_id, 'Move Forward did not change paint order'
    page.locator('[data-action="layer-delete"]').click()
    page.locator(f'[data-layer-select="{layer_id}"]').click()
    layer = page.locator('.composer-layer.selected')
    page.locator('[data-snap-enabled]').check()
    frame = page.locator('[data-canvas]').bounding_box()
    drag_grip(page, -0.2 * frame['width'] + 0.4, 0.4, lambda: expect(page.locator('.snap-guide')).not_to_have_count(0))
    assert percent(layer, 'left') == 50 and percent(layer, 'top') == 50, 'Alignment snapping did not find the scene center'
    expect(page.locator('.snap-guide')).to_have_count(0)

    page.locator('[data-action="layer-crop-bottom"]').click()
    frame = page.locator('[data-canvas]').bounding_box()
    box = layer.bounding_box()
    cropped = (box['y'] + box['height'] - frame['y'] - frame['height']) / box['height']
    assert abs(cropped - 0.2) < 0.01, f'Lower-edge crop was not 20%: {cropped}'
    screenshot(page, 'composer-lower-edge-crop.png')
    crop_y = percent(layer, 'top')
    page.locator('[data-action="undo"]').click()
    assert percent(layer, 'top') == 50
    page.locator('[data-action="redo"]').click()
    assert abs(percent(layer, 'top') - crop_y) < 0.01

    # Publish four independent photo layers straddling all frame edges.
    control(page, 'layerX', 0)
    control(page, 'layerY', 50)
    for x, y in [(100, 50), (50, 0), (50, 100)]:
        page.locator('[data-action="layer-duplicate"]').click()
        control(page, 'layerX', x)
        control(page, 'layerY', y)
    bottom_layer_id = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
    for _ in range(4):
        page.locator('[data-action="layer-back"]').click()
    page.locator('[data-property-tab="background"]').click()
    control(page, 'sceneTitle', 'Edge crop proof A')
    published_a = action(page, 'publish')
    version_a = published_a['id']
    # A valid randomly generated file-store ID can consist entirely of digits.
    # Fix one disposable version to that rare case so rollback cannot cast away zeroes.
    fixture_store = RUNTIME / 'storage/data.json'
    fixture_data = json.loads(fixture_store.read_text())
    for version in fixture_data['scenes']['hero']['versions']:
        if str(version['id']) == str(version_a):
            version['id'] = '000012340056'
    fixture_store.write_text(json.dumps(fixture_data))
    version_a = '000012340056'
    public = context.new_page()
    for device, width in [('desktop', 1440), ('tablet', 900), ('mobile', 390)]:
        public.set_viewport_size({'width': width, 'height': 1000})
        public.goto(url('/'), wait_until='networkidle')
        dismiss_age(public)
        values = settings[device]
        background = public.locator('[data-scene="hero"] .scene-background')
        assert background.evaluate('image => image.style.objectPosition') == f"{values['bgX']}% {values['bgY']}%"
        assert background.evaluate('image => image.style.transform') == f"scale({values['bgZoom'] / 100})"
        assert f"brightness({values['bgBrightness']}%)" in background.evaluate('image => image.style.filter')
        assert public.locator('[data-scene="hero"]').evaluate('node => getComputedStyle(node).overflow') == 'hidden'
        assert public.locator('[data-scene="hero"] .scene-stage').evaluate('node => getComputedStyle(node).overflow') == 'hidden'
        assert public.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'A cropped photo caused public horizontal overflow'
        if device == 'desktop':
            expect(public.locator('[data-scene="hero"] .public-scene-layer')).to_have_count(4)
            assert public.locator('[data-scene="hero"] .public-scene-layer img').evaluate_all('images => images.every(image => image.complete && image.naturalWidth === 120 && image.naturalHeight === 360)')
            bottom_photo = public.locator(f'[data-scene="hero"] [data-layer-id="{bottom_layer_id}"]')
            assert int(bottom_photo.evaluate('node => node.style.zIndex')) < 0
            visible_in_front = bottom_photo.evaluate('''node => {
              const frame = node.closest('[data-scene]').getBoundingClientRect();
              const rect = node.getBoundingClientRect();
              node.style.pointerEvents = 'auto';
              const hit = document.elementFromPoint(rect.left + rect.width / 2, frame.bottom - Math.min(rect.height / 4, 120));
              node.style.pointerEvents = '';
              return node.contains(hit);
            }''')
            assert visible_in_front, 'Repeated Move Back placed the photo behind the background artwork'
            screenshot(public, 'public-scene-all-edge-crops.png')

    # Preview must save the current unsaved Hero AND Footer without publishing either.
    preview_title = 'Unpublished Hero Preview </script><script>window.__sceneTitleScriptRan = true</script>'
    control(page, 'sceneTitle', preview_title)
    control(page, 'bgBrightness', 74)
    choose_section(page, 'footer')
    page.locator('[data-property-tab="background"]').click()
    control(page, 'sceneTitle', 'Unpublished Footer Preview')
    control(page, 'bgX', 33)
    choose_section(page, 'hero')
    before_versions = len(read_scene(context)['versions'])
    with page.expect_popup() as popup:
        page.locator('[data-action="preview"]').click()
    preview = popup.value
    preview.wait_for_url('**/?preview=1')
    preview.wait_for_load_state('networkidle')
    preview_scenes = preview.evaluate('window.VELVET_CONFIG.scenes')
    assert preview_scenes['hero']['title'] == preview_title
    assert preview.evaluate('window.__sceneTitleScriptRan !== true'), 'Scene metadata escaped the embedded JSON script'
    assert preview_scenes['hero']['devices']['desktop']['background']['brightness'] == 74
    assert preview_scenes['footer']['title'] == 'Unpublished Footer Preview'
    assert preview_scenes['footer']['devices']['desktop']['background']['x'] == 33
    assert len(read_scene(context)['versions']) == before_versions, 'Draft preview created a published version'
    public.goto(url('/'), wait_until='networkidle')
    assert public.evaluate('window.VELVET_CONFIG.scenes.hero.title') == 'Edge crop proof A', 'Preview changed the live composition'
    preview.close()

    control(page, 'sceneTitle', 'Edge crop proof B')
    control(page, 'bgBrightness', 122)
    action(page, 'publish')
    page.locator('[data-property-tab="versions"]').click()
    version = page.locator(f'[data-version-id="{version_a}"]')
    expect(version).to_contain_text('Edge crop proof A')
    page.once('dialog', lambda dialog: dialog.accept())
    with page.expect_response(lambda response: is_route_url(response.url, BASE, "api/scene.php") and response.request.method == 'POST') as restored:
        version.locator('button').click()
    restored_data = restored.value.json()
    assert restored.value.ok and restored_data.get('ok')
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    assert read_scene(context)['scene']['title'] == 'Edge crop proof A'
    public.goto(url('/'), wait_until='networkidle')
    assert public.evaluate('window.VELVET_CONFIG.scenes.hero.title') == 'Edge crop proof A'
    assert public.evaluate('window.VELVET_CONFIG.scenes.hero.devices.desktop.background.brightness') == 88

    assert not errors, 'Composer/public JavaScript errors: ' + '; '.join(errors)
    context.close()
    browser.close()

print('Expanded Composer acceptance passed: independent device backgrounds, real uploads, all-edge drag/crop, reachable off-frame resize, aspect ratio, extreme positions, lock/hide/order, snapping, draft preview, and published-version rollback.')
