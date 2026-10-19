"""Real live button editing, geometry, publication and device isolation."""
from __future__ import annotations

import json
import re

from playwright.sync_api import expect, sync_playwright

from composer_support import (
    RUNTIME, action, choose_device, choose_section, control, dismiss_age,
    handles_reachable, launch, login, percent, read_scene, screenshot, url,
)


def select(page, identifier):
    page.locator('[data-property-tab="layer"]').click()
    page.locator(f'[data-layer-select="{identifier}"]').click()
    return page.locator(f'.composer-layer[data-layer-id="{identifier}"]')


def number(page, control_name):
    return float(page.locator(f'[data-control="{control_name}"]').input_value())


def original_layouts(page):
    layouts = {}
    for device in ['desktop', 'tablet', 'mobile']:
        choose_device(page, device)
        layouts[device] = {name: number(page, name) for name in ['height', 'contentX', 'contentY', 'contentWidth', 'contentHeight']}
    choose_device(page, 'desktop')
    return layouts


def retain_content_layout(page, original):
    page.locator('[data-property-tab="background"]').click()
    control(page, 'height', original['height'])
    page.locator('[data-property-tab="content"]').click()
    for name in ['contentWidth', 'contentHeight', 'contentX', 'contentY']:
        control(page, name, original[name])


def drag(page, target, dx, dy):
    target.scroll_into_view_if_needed()
    box = target.bounding_box()
    assert box and box['width'] and box['height'], 'The button must have a real interactive rectangle'
    x, y = box['x'] + box['width'] / 2, box['y'] + box['height'] / 2
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + dx / 2, y + dy / 2, steps=3)
    midway = (number(page, 'layerX'), number(page, 'layerY'), number(page, 'layerWidth'), number(page, 'layerHeight'))
    page.mouse.move(x + dx, y + dy, steps=3)
    page.mouse.up()
    return midway


with sync_playwright() as playwright:
    browser = launch(playwright)
    context = browser.new_context(viewport={'width': 1680, 'height': 1100})
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    login(page)
    layouts = original_layouts(page)
    original_content = read_scene(context)['scene']['devices']['desktop'].get('contentBox')
    # A real existing media record remains a separately editable photo layer.
    page.locator('[data-assets-tab="media"]').click()
    photo_card = page.locator('[data-media-id="demo-album-cover"]')
    photo_card.hover()
    photo_card.locator('[data-media-action="add"]').click()
    photo_id = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
    control(page, 'layerWidth', 12)
    control(page, 'layerX', 85)
    control(page, 'layerY', 24)

    page.get_by_role('button', name='+ Add Button', exact=True).click()
    button = page.locator('.composer-layer.selected[data-layer-type="button"]')
    expect(button).to_have_count(1)
    identifier = button.get_attribute('data-layer-id')
    expect(page.locator('[data-button-controls]')).to_be_visible()
    expect(page.locator('[data-control="layerHeight"]')).to_be_visible()
    expect(page.locator('[data-control="layerFeather"]')).not_to_be_visible()
    control(page, 'buttonLabel', 'View the collection')
    control(page, 'buttonUrl', 'photos/')
    control(page, 'buttonColor', '#cb147b')
    control(page, 'buttonTextColor', '#ffffff')
    control(page, 'buttonBorderColor', '#ff87cc')
    control(page, 'buttonFontSize', 22)
    control(page, 'buttonRadius', 14)
    page.locator('[data-control="buttonStyle"]').select_option('pill')
    expect(button).to_have_text('View the collection')
    assert button.evaluate('node => node.style.borderRadius') == '999px'
    control(page, 'layerX', 70)
    control(page, 'layerY', 72)
    control(page, 'layerWidth', 22)
    control(page, 'layerHeight', 10)
    page.locator('[data-snap-enabled]').uncheck()

    # Direct pointer motion must update continuously, not merely on mouse-up.
    before_x, before_y = number(page, 'layerX'), number(page, 'layerY')
    halfway = drag(page, button, 28, -14)
    assert halfway[0] != before_x and halfway[1] != before_y, 'Button did not move during pointer drag'
    assert number(page, 'layerX') != halfway[0], 'Button stopped following the pointer after the first movement'
    width_before, height_before = number(page, 'layerWidth'), number(page, 'layerHeight')
    handles_reachable(page)
    halfway = drag(page, page.locator('[data-selection-overlay] [data-handle="se"]'), 15, 10)
    assert halfway[2] > width_before and halfway[3] > height_before, 'Corner resize must change both button dimensions continuously'
    assert number(page, 'layerWidth') > halfway[2] and number(page, 'layerHeight') > halfway[3], 'Button resize stopped early'

    control(page, 'layerX', -4)
    control(page, 'layerY', 103)
    handles_reachable(page)
    assert percent(button, 'left') == -4 and percent(button, 'top') == 103, 'Buttons must use the existing off-frame geometry'
    page.locator('[data-action="layer-recover"]').click()
    assert number(page, 'layerX') == 50 and number(page, 'layerY') == 50
    control(page, 'layerX', 72)
    control(page, 'layerY', 76)
    control(page, 'layerWidth', 23)
    control(page, 'layerHeight', 9)
    page.locator('[data-selection-move]').focus()
    page.keyboard.press('ArrowRight')
    assert number(page, 'layerX') == 72.2, 'Button keyboard nudge failed'
    page.locator('[data-action="undo"]').click()
    assert number(page, 'layerX') == 72, 'Button geometry undo failed'
    page.locator('[data-action="redo"]').click()
    assert number(page, 'layerX') == 72.2, 'Button geometry redo failed'

    page.locator('[data-action="layer-lock"]').click()
    expect(page.locator('[data-control="buttonLabel"]')).to_be_disabled()
    expect(page.locator('[data-control="buttonStyle"]')).to_be_disabled()
    expect(page.locator('[data-selection-overlay] [data-handle]')).to_have_count(0)
    before_lock = percent(button, 'left')
    drag(page, button, 30, 0)
    assert percent(button, 'left') == before_lock, 'Locked button moved'
    page.locator('[data-action="layer-lock"]').click()
    page.locator('[data-action="layer-hide"]').click()
    expect(button).to_have_count(0)
    page.locator('[data-action="layer-hide"]').click()
    expect(button).to_have_count(1)
    page.locator('[data-action="layer-back"]').click()
    behind = button.evaluate('node => Number(node.style.zIndex)')
    page.locator('[data-action="layer-forward"]').click()
    assert button.evaluate('node => Number(node.style.zIndex)') > behind
    page.locator('[data-action="layer-duplicate"]').click()
    expect(page.locator('.composer-layer[data-layer-type="button"]')).to_have_count(2)
    duplicate_id = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
    assert duplicate_id != identifier
    page.locator('[data-action="layer-delete"]').click()
    expect(page.locator('.composer-layer[data-layer-type="button"]')).to_have_count(1)
    page.locator('[data-action="undo"]').click()
    expect(page.locator('.composer-layer[data-layer-type="button"]')).to_have_count(2)
    page.locator('[data-action="redo"]').click()
    expect(page.locator('.composer-layer[data-layer-type="button"]')).to_have_count(1)
    button = select(page, identifier)

    # Copy is explicit; later adjustments remain local to each device.
    page.locator('[data-action="copy-layout"]').click()
    for target, geometry in [('tablet', (65, 76, 38, 10)), ('mobile', (50, 27, 78, 8))]:
        choose_device(page, target)
        select(page, identifier)
        for name, value in zip(['layerX', 'layerY', 'layerWidth', 'layerHeight'], geometry):
            control(page, name, value)
        if target == 'mobile':
            page.locator('[data-control="buttonStyle"]').select_option('outline')
            control(page, 'buttonFontSize', 18)
        retain_content_layout(page, layouts[target])
    choose_device(page, 'desktop')
    button = select(page, identifier)
    assert number(page, 'layerX') == 72.2 and number(page, 'layerWidth') == 23
    action(page, 'save')
    saved = read_scene(context)['scene']
    if original_content is not None:
        assert saved['devices']['desktop']['contentBox'] == original_content, 'Adding a button moved the old live text/button group'
    assert any(layer['id'] == photo_id for layer in saved['devices']['desktop']['layers']), 'Adding buttons removed the photo layer'
    for target, expected_width in [('desktop', 23), ('tablet', 38), ('mobile', 78)]:
        value = next(layer for layer in saved['devices'][target]['layers'] if layer['id'] == identifier)
        assert value['width'] == expected_width, 'Button layout was shared across devices'
    page.reload(wait_until='networkidle')
    button = select(page, identifier)
    expect(button).to_have_text('View the collection')
    expect(page.locator('[data-control="buttonUrl"]')).to_have_value('photos/')
    screenshot(page, 'composer-buttons-editor-desktop.png')

    guest = browser.new_context(viewport={'width': 1440, 'height': 1000})
    public = guest.new_page()
    public.on('pageerror', lambda error: errors.append(str(error)))
    public.goto(url(''), wait_until='networkidle')
    dismiss_age(public)
    expect(public.locator(f'[data-layer-id="{identifier}"]')).to_have_count(0)
    first_version = action(page, 'publish')['versions'][0]['id']
    for target, width, height, expected_width in [('desktop', 1440, 1000, 23), ('tablet', 834, 1112, 38), ('mobile', 390, 844, 78)]:
        public.set_viewport_size({'width': width, 'height': height})
        public.goto(url(''), wait_until='networkidle')
        dismiss_age(public)
        link = public.locator(f'[data-scene="hero"] a[data-layer-id="{identifier}"]')
        expect(public.get_by_role('link', name='View the collection', exact=True)).to_have_count(1)
        expect(link).to_have_text('View the collection')
        expect(link).to_have_attribute('href', url('photos/'))
        assert percent(link, 'width') == expected_width, 'Published button used the wrong device geometry'
        assert link.evaluate('node => getComputedStyle(node).pointerEvents') == 'auto', 'Public button is not clickable'
        assert link.locator('img,canvas').count() == 0, 'Button must remain HTML, not an image'
        if target == 'mobile':
            assert link.evaluate('node => getComputedStyle(node).backgroundColor') == 'rgba(0, 0, 0, 0)'
        link.focus()
        expect(link).to_be_focused()
        screenshot(public, f'composer-buttons-public-{target}.png')
        link.click()
        public.wait_for_url('**/photos/**')

    # Edits publish through ordinary version history, with safe new-tab links.
    control(page, 'buttonLabel', 'Read <more> & join')
    control(page, 'buttonUrl', 'https://example.test/collection')
    page.locator('[data-control="buttonNewTab"]').check()
    action(page, 'publish')
    public.set_viewport_size({'width': 1440, 'height': 1000})
    public.goto(url(''), wait_until='networkidle')
    literal = public.locator(f'a[data-layer-id="{identifier}"]')
    expect(literal).to_have_text('Read <more> & join')
    expect(literal).to_have_attribute('target', '_blank')
    expect(literal).to_have_attribute('rel', 'noopener noreferrer')
    expect(literal.locator('more')).to_have_count(0)

    # Unsafe URL rejected by the real endpoint without replacing any published content.
    before = read_scene(context)['scene']
    control(page, 'buttonUrl', 'javascript:alert(1)')
    with page.expect_response(lambda response: '/api/scene.php' in response.url and response.request.method == 'POST') as rejected:
        page.locator('[data-action="publish"]').click()
    assert rejected.value.status == 422 and rejected.value.json()['ok'] is False
    expect(page.locator('[data-status]')).to_have_class(re.compile(r'\berror\b'))
    assert read_scene(context)['scene'] == before, 'Rejected button destination replaced stored scene'
    page.on('dialog', lambda dialog: dialog.accept())
    page.locator('[data-property-tab="versions"]').click()
    with page.expect_response(lambda response: '/api/scene.php' in response.url and response.request.method == 'POST') as restored:
        page.locator(f'[data-version-id="{first_version}"] [data-restore-version]').click()
    assert restored.value.ok and restored.value.json()['ok']
    public.goto(url(''), wait_until='networkidle')
    expect(public.locator(f'a[data-layer-id="{identifier}"]')).to_have_text('View the collection')

    # Footer and small-screen owner editing use the same live layer controls.
    choose_section(page, 'footer')
    footer_layouts = original_layouts(page)
    page.get_by_role('button', name='+ Add Button', exact=True).click()
    footer_id = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
    control(page, 'buttonLabel', 'Explore membership')
    control(page, 'buttonUrl', '/membership/')
    control(page, 'layerY', 75)
    page.locator('[data-action="copy-layout"]').click()
    choose_device(page, 'mobile')
    select(page, footer_id)
    control(page, 'layerX', 50)
    control(page, 'layerY', 25)
    control(page, 'layerWidth', 76)
    retain_content_layout(page, footer_layouts['mobile'])
    select(page, footer_id)
    action(page, 'publish')
    page.set_viewport_size({'width': 390, 'height': 844})
    page.locator('[data-assets-tab="media"]').click()
    select(page, footer_id)
    expect(page.locator('[data-control="buttonLabel"]')).to_have_value('Explore membership')
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    handles_reachable(page)
    screenshot(page, 'composer-buttons-editor-mobile.png')
    public.set_viewport_size({'width': 390, 'height': 844})
    public.goto(url(''), wait_until='networkidle')
    footer = public.locator(f'[data-scene="footer"] a[data-layer-id="{footer_id}"]')
    expect(footer).to_have_attribute('href', url('membership/'))
    footer.click()
    public.wait_for_url('**/membership/**')

    # Legacy persisted payloads still receive renderer defense in depth.
    fixture_file = RUNTIME / 'storage/data.json'
    fixture = json.loads(fixture_file.read_text())
    for scope in ['draft', 'published']:
        forged = fixture['scenes']['hero'][scope]
        for view in forged['devices'].values():
            view['layers'].append({'id': 'unsafe-legacy-button', 'type': 'button', 'label': 'Unsafe fixture',
                'url': 'javascript:window.unsafeSceneButton=true', 'x': 50, 'y': 50, 'width': 20, 'height': 10})
    fixture_file.write_text(json.dumps(fixture))
    public.goto(url(''), wait_until='networkidle')
    expect(public.locator('[data-layer-id="unsafe-legacy-button"]')).to_have_count(0)
    assert public.evaluate('window.unsafeSceneButton') is None
    assert not errors, f'Unexpected JavaScript errors: {errors}'
    guest.close()
    context.close()
    browser.close()

print('Composer live button acceptance passed: add/edit/style, drag/free resize, clipping, history/layer controls, independent devices, safe live Hero/Footer links and real publication/restore.')
