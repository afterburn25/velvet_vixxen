#!/usr/bin/env python3
"""Edit the third CTA inside the real Live Text + Buttons group.

Each case extracts the actual customer ZIP into a marked temporary installation
and starts PHP without a router script. The browser clicks the canvas group and
its inspector, then follows real preview, publication and public route.php URLs.
Only synthetic fixture data is created, outside the source tree/customer ZIP.
"""
from __future__ import annotations

import copy
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from zipfile import ZipFile

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
helper_spec = importlib.util.spec_from_file_location(
    'vvx_group_button_package_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
assert helper_spec is not None and helper_spec.loader is not None
helpers = importlib.util.module_from_spec(helper_spec)
helper_spec.loader.exec_module(helpers)
require = helpers.require
EVIDENCE = helpers.EVIDENCE
DEVICES = ('desktop', 'tablet', 'mobile')
VIEWPORTS = {
    'desktop': {'width': 1440, 'height': 1000},
    'tablet': {'width': 834, 'height': 1112},
    'mobile': {'width': 390, 'height': 844},
}
BOX_FIELDS = {'x': 'contentX', 'y': 'contentY', 'width': 'contentWidth', 'height': 'contentHeight'}


def legacy_fixture(site: Path) -> None:
    """Preserve a real old payload with independent image/button layers.

The translucent rectangle is a geometry fixture, never a substitute person or
owner photograph. Removing contentButtons exercises real legacy fallback; no
PHP/HTML/JavaScript response is replaced by this fixture.
"""
    require((site / '.vvx-test-runtime').is_file() and site.is_relative_to(Path(tempfile.gettempdir())),
            'Legacy scenes may only be seeded in a marked disposable temporary package.')
    data = helpers.state(site)
    image = site / 'uploads/media/group-geometry-fixture.png'
    image.parent.mkdir(parents=True, exist_ok=True)
    image.write_bytes(helpers.PHOTO)
    data['media']['group-geometry-fixture'] = {
        'id': 'group-geometry-fixture', 'title': 'Transparent geometry fixture', 'type': 'model',
        'file': 'media/group-geometry-fixture.png', 'thumbnail': 'media/group-geometry-fixture.png',
        'width': 64, 'height': 96, 'mime': 'image/png', 'private': False,
    }
    for section in ('hero', 'footer'):
        for scope in ('draft', 'published'):
            scene = data['scenes'][section][scope]
            scene.pop('contentButtons', None)
            for device in DEVICES:
                scene['devices'][device]['layers'] = [
                    {'id': f'{section}-separate-image', 'type': 'model', 'name': 'Geometry fixture',
                     'mediaId': 'group-geometry-fixture', 'src': 'media/group-geometry-fixture.png',
                     'x': 86, 'y': 23, 'width': 8, 'locked': False, 'visible': True, 'z': 1},
                    {'id': f'{section}-separate-button', 'type': 'button', 'name': 'Separate layer',
                     'label': 'Separate layer', 'url': 'about/', 'x': 75, 'y': 10, 'width': 26,
                     'height': 7, 'locked': False, 'visible': True, 'z': 2, 'fontSize': 14,
                     'radius': 10, 'style': 'solid', 'color': '#ff2d8d', 'textColor': '#ffffff',
                     'borderColor': '#ff2d8d', 'newTab': False, 'rotation': 0, 'opacity': 100},
                ]
    (site / 'storage/data.json').write_text(json.dumps(data))


def screenshot(page, name: str) -> None:
    if EVIDENCE:
        is_editor = page.locator('#visualComposer').count() > 0
        if not is_editor:
            page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        # Full-page capture can resize this fixed-height editor, which redraws
        # its inspector. Keep the actual chosen Button 3 panel in the viewport.
        page.screenshot(path=str(EVIDENCE / f'composer-group-buttons-{name}.png'), full_page=not is_editor)


def dismiss_age(page) -> None:
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()


def choose(page, section: str, device: str) -> None:
    page.locator(f'[data-section="{section}"]').click()
    page.locator(f'[data-device="{device}"]').click()


def open_group(page) -> None:
    # Deliberately leave the inspector first: this assertion must be earned by
    # clicking the actual canvas object, not by directly selecting its tab.
    page.locator('[data-property-tab="background"]').click()
    page.locator('[data-content-box]').click()
    expect(page.locator('[data-property-page="content"]')).to_have_class(re.compile(r'\bactive\b'))
    expect(page.locator('[data-action="add-content-button"]')).to_be_visible()


def cards(page):
    return page.locator('[data-property-page="content"] [data-content-button-id]')


def card(page, identifier: str):
    return page.locator(f'[data-property-page="content"] [data-content-button-id="{identifier}"]')


def field(page, identifier: str, name: str):
    return card(page, identifier).locator(f'[data-content-button-field="{name}"]')


def edit(page, identifier: str, name: str, value) -> None:
    control = field(page, identifier, name)
    if control.get_attribute('type') == 'checkbox':
        control.set_checked(bool(value))
    elif control.evaluate('node => node.tagName') == 'SELECT':
        control.select_option(value)
    else:
        control.fill(str(value))
        control.press('Tab')


def add_group_button(page, count: int) -> str:
    page.get_by_role('button', name='+ Add button to this group', exact=True).click()
    expect(cards(page)).to_have_count(count)
    current = cards(page).nth(count - 1)
    expect(current.get_by_role('heading', name=f'Button {count}', exact=True)).to_be_visible()
    identifier = current.get_attribute('data-content-button-id')
    require(bool(identifier), 'Adding a grouped button did not give its inspector card a stable ID.')
    expect(page.locator('[data-content-box] [data-content-button-preview]')).to_have_count(count)
    return identifier


def read_box(page) -> dict:
    return {name: float(page.locator(f'[data-control="{control}"]').input_value())
            for name, control in BOX_FIELDS.items()}


def set_box(page, values: dict) -> None:
    for name, value in values.items():
        control = page.locator(f'[data-control="{BOX_FIELDS[name]}"]')
        control.fill(str(value))
        control.press('Tab')


def compare_box(actual: dict, expected: dict, message: str) -> None:
    require(all(abs(float(actual[name]) - expected[name]) < 0.11 for name in BOX_FIELDS),
            f'{message}: actual={actual}, expected={expected}')


def drag(page, locator, dx: int, dy: int) -> tuple[dict, dict]:
    locator.scroll_into_view_if_needed()
    rectangle = locator.bounding_box()
    require(bool(rectangle), 'The grouped control has no real pointer target.')
    x, y = rectangle['x'] + rectangle['width'] / 2, rectangle['y'] + rectangle['height'] / 2
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + dx / 2, y + dy / 2, steps=4)
    halfway = read_box(page)
    page.mouse.move(x + dx, y + dy, steps=4)
    page.mouse.up()
    return halfway, read_box(page)


def scene_action(page, base: str, section: str, action: str, status: int = 200) -> dict:
    with page.expect_response(lambda response: helpers.is_route_url(response.url, base, 'api/scene.php')
                              and response.request.method == 'POST') as captured:
        page.locator(f'[data-action="{action}"]').click()
    response = captured.value
    result = helpers.response_json(response, status, f'{section} grouped CTA {action}')
    helpers.assert_route_destination(response.url, base, f'api/scene.php?section={section}',
                                     'Grouped button action lost its physical endpoint or installation path.')
    require(response.request.post_data_json['action'] == action, 'The owner action sent the wrong operation.')
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    expect(page.locator('[data-action="publish"]')).to_be_enabled()
    expect(page.locator('[data-action="preview"]')).to_be_enabled()
    if status == 200:
        require(result['ok'], f'{section} {action} returned a failure envelope.')
        expect(page.locator('[data-status]')).not_to_have_class(re.compile(r'\berror\b'))
    else:
        require(not result['ok'], 'The unsafe destination returned a success envelope.')
        expect(page.locator('[data-status]')).to_have_class(re.compile(r'\berror\b'))
    return result


def public_buttons(page, section: str):
    return page.locator(f'[data-scene="{section}"] .scene-content-buttons [data-content-button-id]')


def public_button(page, section: str, identifier: str):
    return page.locator(
        f'[data-scene="{section}"] .scene-content-buttons [data-content-button-id="{identifier}"]')


def check_images(page, selector: str, label: str) -> int:
    images = page.locator(selector)
    tested = 0
    for index in range(images.count()):
        image = images.nth(index)
        source = image.get_attribute('src') or ''
        if not source:
            continue
        # Image.decode verifies the actual DOM image; no request interception or
        # replacement screenshot/image can make a missing public asset pass.
        if image.is_visible():
            image.scroll_into_view_if_needed()
        image.evaluate('image => image.decode()')
        require(image.evaluate('image => image.complete && image.naturalWidth > 0'),
                f'{label}: an actual image failed to decode: {source}')
        require(image.get_attribute('data-fallback-applied') != '1',
                f'{label}: a fallback concealed a broken Composer asset: {source}')
        tested += 1
    require(tested > 0, f'{label}: no actual images were checked.')
    return tested


def edit_section(page, site: Path, base: str, section: str, label: str) -> dict:
    legacy_count = 2 if section == 'hero' else 1
    choose(page, section, 'desktop')
    open_group(page)
    expect(cards(page)).to_have_count(legacy_count)
    expect(page.locator('[data-content-box] [data-content-button-preview]')).to_have_count(legacy_count)
    original_layouts = {}
    for device in DEVICES:
        page.locator(f'[data-device="{device}"]').click()
        original_layouts[device] = read_box(page)
    page.locator('[data-device="desktop"]').click()
    original_layers = copy.deepcopy(helpers.state(site)['scenes'][section]['draft']['devices'])
    if section == 'footer':
        second = add_group_button(page, 2)
        edit(page, second, 'label', 'Footer details')
        edit(page, second, 'url', '/about/')
    identifier = add_group_button(page, 3)
    # Adding a grouped button is a history operation independent of layer Add Button.
    page.locator('[data-action="undo"]').click()
    expect(cards(page)).to_have_count(2)
    page.locator('[data-action="redo"]').click()
    expect(card(page, identifier)).to_have_count(1)
    expect(card(page, identifier)).to_have_attribute('data-content-button-index', '2')
    text = f'{section.title()} <book> & inquire'
    destination = ('/booking/' if section == 'hero' else 'booking/index.php') + f'?source=group-{label}-{section}#bookingForm'
    edit(page, identifier, 'label', text)
    edit(page, identifier, 'url', destination)
    edit(page, identifier, 'style', 'glass')
    expect(page.locator(f'[data-content-button-preview="{identifier}"]')).to_contain_text(text)

    card(page, identifier).locator('[data-content-button-action="remove"]').click()
    expect(cards(page)).to_have_count(2)
    page.locator('[data-action="undo"]').click()
    expect(field(page, identifier, 'label')).to_have_value(text)
    page.locator('[data-action="redo"]').click()
    expect(card(page, identifier)).to_have_count(0)
    page.locator('[data-action="undo"]').click()
    card(page, identifier).locator('[data-content-button-action="up"]').click()
    expect(card(page, identifier)).to_have_attribute('data-content-button-index', '1')
    card(page, identifier).locator('[data-content-button-action="down"]').click()
    expect(card(page, identifier)).to_have_attribute('data-content-button-index', '2')

    for device in DEVICES:
        page.locator(f'[data-device="{device}"]').click()
        compare_box(read_box(page), original_layouts[device], 'Adding/reordering buttons moved a device layout')
        expect(page.locator('[data-content-box] [data-content-button-preview]')).to_have_count(3)
        expect(page.locator('.composer-layer')).to_have_count(2)
    page.locator('[data-device="desktop"]').click()
    page.locator('[data-snap-enabled]').uncheck()
    before_drag = read_box(page)
    halfway, after_drag = drag(page, page.locator('[data-content-box]'), 24, 14)
    require(halfway['x'] > before_drag['x'] and halfway['y'] > before_drag['y']
            and after_drag['x'] > halfway['x'] and after_drag['y'] > halfway['y'],
            'The three-button group did not move continuously with the actual pointer.')
    halfway, after_resize = drag(page, page.locator('[data-content-handle="se"]'), 10, 8)
    require(halfway['width'] > after_drag['width'] and halfway['height'] > after_drag['height']
            and after_resize['width'] > halfway['width'] and after_resize['height'] > halfway['height'],
            'The three-button group did not resize continuously with its actual handle.')
    expected_layouts = copy.deepcopy(original_layouts)
    expected_layouts['desktop'] = after_resize
    page.locator('[data-device="mobile"]').click()
    set_box(page, {'width': 80, 'height': 42 if section == 'hero' else 56,
                   'x': 10, 'y': 50 if section == 'hero' else 35})
    expected_layouts['mobile'] = read_box(page)
    page.locator('[data-device="tablet"]').click()
    compare_box(read_box(page), original_layouts['tablet'], 'Mobile edits changed the tablet layout')
    page.locator('[data-device="desktop"]').click()
    compare_box(read_box(page), expected_layouts['desktop'], 'Mobile edits changed the dragged desktop layout')
    scene_action(page, base, section, 'save')
    saved = helpers.state(site)['scenes'][section]['draft']
    require(len(saved['contentButtons']) == 3 and saved['contentButtons'][2]['id'] == identifier,
            'Saving did not persist three ordered buttons inside the content group.')
    require(saved['contentButtons'][2]['label'] == text and saved['contentButtons'][2]['url'] == destination,
            'Saving lost the third button label or destination.')
    for device in DEVICES:
        require(saved['devices'][device]['layers'] == original_layers[device]['layers'],
                'Group controls changed an independent image or standalone button layer.')
        compare_box(saved['devices'][device]['contentBox'], expected_layouts[device], 'Saved geometry differs from the inspector')
    page.reload(wait_until='networkidle')
    for device in DEVICES:
        choose(page, section, device)
        open_group(page)
        expect(cards(page)).to_have_count(3)
        expect(card(page, identifier).get_by_role('heading', name='Button 3', exact=True)).to_be_visible()
        expect(field(page, identifier, 'label')).to_have_value(text)
        expect(field(page, identifier, 'url')).to_have_value(destination)
        compare_box(read_box(page), expected_layouts[device], 'Reload lost a distinct device layout')
    choose(page, section, 'desktop')
    page.locator('[data-assets-tab="media"]').click()
    checked_images = check_images(page, '[data-canvas] img, [data-media-list] img', section + ' editor')
    card(page, identifier).scroll_into_view_if_needed()
    screenshot(page, f'{label}-{section}-inspector-desktop')
    return {'id': identifier, 'label': text, 'destination': destination,
            'layouts': expected_layouts, 'editor_images_decoded': checked_images}


def browser_case(browser, site: Path, base: str, label: str) -> dict:
    owner = browser.new_context(viewport=VIEWPORTS['desktop'])
    visitor = browser.new_context(viewport=VIEWPORTS['desktop'])
    errors: list[str] = []
    image_errors: list[str] = []
    for context in (owner, visitor):
        context.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
        context.on('response', lambda response: image_errors.append(f'{response.status} {response.url}')
                   if response.request.resource_type == 'image' and response.status >= 400 else None)
    page = owner.new_page()
    public = visitor.new_page()
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(base + '/admin/?page=composer', wait_until='networkidle')
        public.goto(base + '/', wait_until='networkidle')
        dismiss_age(public)
        before = helpers.state(site)['scenes']
        legacy_labels = {}
        for section, count in [('hero', 2), ('footer', 1)]:
            require('contentButtons' not in before[section]['draft']
                    and 'contentButtons' not in before[section]['published'], 'Legacy fixture did not omit the new list.')
            expect(public_buttons(public, section)).to_have_count(count)
            legacy_labels[section] = public_buttons(public, section).all_text_contents()
        controls = {section: edit_section(page, site, base, section, label) for section in ('hero', 'footer')}
        for section in controls:
            require(helpers.state(site)['scenes'][section]['published'] == before[section]['published'],
                    'Save Draft changed a published scene.')

        preview_requests = []
        def record_preview(response) -> None:
            if helpers.is_route_url(response.url, base, 'api/scene.php') and response.request.method == 'POST':
                preview_requests.append(response)
        page.on('response', record_preview)
        try:
            with page.expect_popup() as opened:
                page.get_by_role('button', name='Full Page Preview', exact=True).click()
            preview = opened.value
            preview.wait_for_url(base + '/?preview=1')
            preview.wait_for_load_state('networkidle')
        finally:
            page.remove_listener('response', record_preview)
        require(not preview.is_closed(), 'The full-page preview tab opened and immediately disappeared.')
        require(preview.evaluate('window.opener === null'), 'Preview did not sever its opener reference.')
        require(len(preview_requests) == 2, 'Preview did not save exactly the Hero and Footer drafts.')
        for section, response in zip(('hero', 'footer'), preview_requests):
            helpers.assert_route_destination(response.url, base, f'api/scene.php?section={section}',
                                             'Preview lost the real scene endpoint or section.')
            require(helpers.response_json(response, 200, 'Grouped CTA preview save')['ok']
                    and response.request.post_data_json['action'] == 'save', 'Preview unexpectedly published a scene.')
        dismiss_age(preview)
        anonymous_preview = visitor.new_page()
        anonymous_preview.goto(base + '/?preview=1', wait_until='networkidle')
        for section, control in controls.items():
            expect(public_buttons(preview, section)).to_have_count(3)
            expect(public_button(preview, section, control['id'])).to_have_text(control['label'])
            expect(public_button(public, section, control['id'])).to_have_count(0)
            expect(public_button(anonymous_preview, section, control['id'])).to_have_count(0)
            require(helpers.state(site)['scenes'][section]['published'] == before[section]['published'],
                    'Full Page Preview replaced a published snapshot.')
        preview_images = check_images(preview, '[data-scene] img', 'Owner full-page preview')
        screenshot(preview, f'{label}-draft-preview')
        require(not preview.is_closed(), 'The preview tab closed after its real scene content loaded.')
        anonymous_preview.close()
        preview.close()

        versions = {}
        for section in ('hero', 'footer'):
            choose(page, section, 'desktop')
            result = scene_action(page, base, section, 'publish')
            require(len(result['versions']) == len(before[section]['versions']) + 1,
                    'Publishing grouped buttons did not create a scene history entry.')
            versions[section] = result['versions'][0]['id']
        public_image_checks = 0
        for device, viewport in VIEWPORTS.items():
            public.set_viewport_size(viewport)
            public.goto(base + '/', wait_until='networkidle')
            dismiss_age(public)
            for section, control in controls.items():
                expect(public_buttons(public, section)).to_have_count(3)
                require(public_buttons(public, section).all_text_contents()[:len(legacy_labels[section])]
                        == legacy_labels[section], 'Adding the third CTA changed existing automatic button labels.')
                content = public.locator(f'[data-scene="{section}"] ' + ('.hero-content' if section == 'hero' else '.closing-content'))
                box = content.evaluate('node => ({x: parseFloat(node.style.left), y: parseFloat(node.style.top), '
                                       'width: parseFloat(node.style.width), height: parseFloat(node.style.height)})')
                compare_box(box, control['layouts'][device], f'Published {section} uses the wrong {device} layout')
                cta = public_button(public, section, control['id'])
                expect(cta).to_have_text(control['label'])
                require(cta.evaluate('node => node.tagName') == 'A' and cta.locator('img,canvas,book').count() == 0,
                        'Third grouped button is not a real safely escaped HTML link.')
                helpers.assert_route_destination(cta.evaluate('node => node.href'), base, control['destination'],
                                                 'The third CTA lost its dispatcher, nested path, query or fragment.')
                cta.focus()
                expect(cta).to_be_focused()
            public_image_checks += check_images(public, '[data-scene] img', device + ' published scenes')
            screenshot(public, f'{label}-public-{device}')
            if EVIDENCE:
                for section in controls:
                    public.locator(f'[data-scene="{section}"]').screenshot(
                        path=str(EVIDENCE / f'composer-group-buttons-{label}-{section}-public-{device}.png'))
            for section, control in controls.items():
                if section == 'footer':
                    public.goto(base + '/', wait_until='networkidle')
                cta = public_button(public, section, control['id'])
                destination = cta.evaluate('node => node.href')
                with public.expect_navigation(wait_until='networkidle') as navigation:
                    cta.click()
                require(navigation.value is not None and navigation.value.status == 200 and public.url == destination,
                        f'Actual {device} {section} third-button click did not reach its real Booking route.')
                expect(public.get_by_role('heading', name=re.compile(r'Booking.*Appearances'))).to_be_visible()
                expect(public.locator('#bookingForm')).to_be_visible()

        # The owner can still click the group and edit Button 3 at phone width.
        page.set_viewport_size(VIEWPORTS['mobile'])
        for section, control in controls.items():
            choose(page, section, 'mobile')
            open_group(page)
            expect(field(page, control['id'], 'label')).to_have_value(control['label'])
            mobile_label = control['label'] + ' mobile'
            edit(page, control['id'], 'label', mobile_label)
            expect(page.locator(f'[data-content-button-preview="{control["id"]}"]')).to_contain_text(mobile_label)
            page.locator('[data-action="undo"]').click()
            expect(field(page, control['id'], 'label')).to_have_value(control['label'])
            page.locator('[data-action="redo"]').click()
            expect(field(page, control['id'], 'label')).to_have_value(mobile_label)
            page.locator('[data-action="undo"]').click()
            card(page, control['id']).scroll_into_view_if_needed()
            screenshot(page, f'{label}-{section}-inspector-mobile')
        page.set_viewport_size(VIEWPORTS['desktop'])

        for section, control in controls.items():
            choose(page, section, 'desktop')
            open_group(page)
            stored_before = copy.deepcopy(helpers.state(site)['scenes'])
            edit(page, control['id'], 'url', 'javascript:window.unsafeGroupButton=true')
            scene_action(page, base, section, 'publish', 422)
            require(helpers.state(site)['scenes'] == stored_before,
                    'Server rejection of an unsafe grouped URL changed a draft, publication or history.')
            edit(page, control['id'], 'url', control['destination'])

        # A later publication can be restored through the real owner history UI.
        choose(page, 'hero', 'desktop')
        open_group(page)
        hero = controls['hero']
        edit(page, hero['id'], 'label', 'Revised grouped booking')
        edit(page, hero['id'], 'newTab', True)
        scene_action(page, base, 'hero', 'publish')
        public.goto(base + '/', wait_until='networkidle')
        revised = public_button(public, 'hero', hero['id'])
        expect(revised).to_have_text('Revised grouped booking')
        expect(revised).to_have_attribute('target', '_blank')
        expect(revised).to_have_attribute('rel', 'noopener noreferrer')
        with public.expect_popup() as opened:
            revised.click()
        destination_tab = opened.value
        destination_tab.wait_for_load_state('networkidle')
        helpers.assert_route_destination(destination_tab.url, base, hero['destination'],
                                         'The real new-tab button lost its Booking destination.')
        expect(destination_tab.locator('#bookingForm')).to_be_visible()
        require(destination_tab.evaluate('window.opener === null'), 'The grouped new-tab link retained an opener.')
        destination_tab.close()
        page.on('dialog', lambda dialog: dialog.accept())
        page.locator('[data-property-tab="versions"]').click()
        with page.expect_response(lambda response: helpers.is_route_url(response.url, base, 'api/scene.php')
                                  and response.request.method == 'POST') as restored:
            page.locator(f'[data-version-id="{versions["hero"]}"] [data-restore-version]').click()
        require(helpers.response_json(restored.value, 200, 'Restore grouped CTA version')['ok'],
                'Restoring a published group-button version failed.')
        public.goto(base + '/', wait_until='networkidle')
        expect(public_button(public, 'hero', hero['id'])).to_have_text(hero['label'])
        require(public_button(public, 'hero', hero['id']).get_attribute('target') is None,
                'Restoring history retained a later new-tab setting.')
        expect(public_buttons(public, 'hero')).to_have_count(3)

        # Defense for old manually edited/corrupt stored data is independent of
        # the valid writer path: a persisted unsafe link never becomes clickable.
        forged = helpers.state(site)
        for scope in ('draft', 'published'):
            forged['scenes']['hero'][scope]['contentButtons'][2]['url'] = 'javascript:window.unsafeGroupButton=true'
        (site / 'storage/data.json').write_text(json.dumps(forged))
        public.goto(base + '/', wait_until='networkidle')
        expect(public_button(public, 'hero', hero['id'])).to_have_count(0)
        expect(public_buttons(public, 'hero')).to_have_count(2)
        require(public.evaluate('window.unsafeGroupButton') is None, 'An unsafe legacy group-button URL executed.')
        require(not errors, 'Grouped button acceptance raised JavaScript errors: ' + '; '.join(errors))
        require(not image_errors, 'Grouped button acceptance requested broken images: ' + '; '.join(image_errors))
        return {
            'legacy_hero_two_footer_one_preserved': True, 'canvas_click_opens_group_inspector': True,
            'third_hero_and_footer_cta_add_edit_reorder_remove_undo_redo': True,
            'save_reload_and_shared_content_separate_device_geometry': True,
            'continuous_group_drag_resize': True, 'independent_image_and_standalone_layers_preserved': True,
            'preview_tab_retained_owner_only_drafts': True, 'published_history_restore': True,
            'real_booking_clicks_desktop_tablet_mobile': 6, 'server_unsafe_url_rejected_without_write': True,
            'real_new_tab_booking_link_opener_isolated': True,
            'unsafe_legacy_url_not_rendered': True, 'literal_label_escaping': True,
            'mobile_owner_inspector_edit_undo_redo': True,
            'uncaught_javascript_errors': errors, 'broken_image_responses': image_errors,
            'preview_images_decoded': preview_images, 'public_images_decoded': public_image_checks,
            'sections': controls,
        }
    except Exception:
        screenshot(page, f'{label}-failure-editor')
        if not public.is_closed():
            screenshot(public, f'{label}-failure-public')
        raise
    finally:
        visitor.close()
        owner.close()


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for extracted-package group-button acceptance.')
    package_spec = importlib.util.spec_from_file_location('vvx_group_button_package', ROOT / 'scripts/package.py')
    require(package_spec is not None and package_spec.loader is not None, 'Actual runtime package builder is missing.')
    package = importlib.util.module_from_spec(package_spec)
    package_spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Engineering screenshots/logs must stay outside customer runtime and tracked source paths.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'),
               'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-group-buttons-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary.update({'build_id': built['build_id'], 'fresh_zip_sha256': fresh['sha256']})
        with ZipFile(archive) as zipped:
            entries = set(zipped.namelist())
            require({'route.php', 'public/routes/api/scene.php', 'public/routes/booking/index.php'} <= entries,
                    'Customer ZIP omitted an actual endpoint needed by the grouped button workflow.')
            require({name.split('/')[0] for name in entries if '/' in name}
                    == {'addons', 'admin', 'app', 'config', 'database', 'images', 'includes', 'install',
                        'public', 'storage', 'templates', 'uploads', 'vendor'},
                    'The actual grouped-button package changed the approved organized root directories.')
            require({name for name in entries if '/' not in name} == {'.htaccess', 'VERSION', 'index.php', 'route.php'},
                    'The actual grouped-button package added a rejected root file.')
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
                document_root = work / label / 'www'
                site = document_root / prefix.lstrip('/')
                site.mkdir(parents=True)
                with ZipFile(archive) as zipped:
                    zipped.extractall(site)
                (site / '.vvx-test-runtime').write_text('Disposable packaged grouped-button acceptance.\n')
                with socket.socket() as listener:
                    listener.bind(('127.0.0.1', 0))
                    port = listener.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True,
                               capture_output=True, text=True)
                legacy_fixture(site)
                preserved = {name: (site / name).read_bytes() for name in ('config/app.php', 'storage/install.lock')}
                owners = helpers.state(site)['admins']
                environment = os.environ.copy()
                environment.pop('PHP_CLI_SERVER_WORKERS', None)
                log_file = work / label / 'php-server.log'
                with log_file.open('w+') as log:
                    process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                                                '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                               cwd=work, env=environment, stdout=log, stderr=log)
                    try:
                        session = helpers.requests_session()
                        deadline = time.monotonic() + 15
                        while True:
                            require(process.poll() is None, 'Disposable PHP server exited before becoming ready.')
                            try:
                                if session.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                    break
                            except requests.RequestException:
                                pass
                            require(time.monotonic() < deadline, 'Disposable PHP server did not become ready.')
                            time.sleep(0.1)
                        session.close()
                        summary['cases'][label] = browser_case(browser, site, base, label)
                        require(all((site / name).read_bytes() == data for name, data in preserved.items())
                                and helpers.state(site)['admins'] == owners,
                                'Group-button editing changed installation identity or owner credentials.')
                        require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_file.read_text()),
                                'Grouped button execution produced PHP diagnostics.')
                        print(f'Packaged grouped buttons {label}: PASS (real inspector, third Hero/Footer CTA, '
                              'history, device geometry, retained preview and six public Booking clicks).', flush=True)
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_file, EVIDENCE / f'composer-group-buttons-{label}-http.log')
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'composer-group-buttons-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Live Text + Buttons group acceptance passed at root/nested installation paths with real '
          'desktop/mobile owner controls and desktop/tablet/mobile public links.', flush=True)


if __name__ == '__main__':
    run()
