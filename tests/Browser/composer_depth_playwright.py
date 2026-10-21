#!/usr/bin/env python3
"""Actual-package depth and furniture occlusion through the real owner controls.

Geometric PNGs are uploaded normally into marked disposable installations.
Pixel evidence compares the real preview/public composite with a separately
published background-only reference at the same device size. The test never
replaces a renderer, injects application state or intercepts an HTTP response.
"""
from __future__ import annotations

import argparse
import base64
from copy import deepcopy
import hashlib
import importlib.metadata
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import struct
import sys
import tempfile
from urllib.parse import parse_qs, urlparse
import zlib

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


http = load('vvx_depth_http', ROOT / 'tests/Http/composer_depth_http.py')
media = load('vvx_depth_media', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
check = http.check
WIDTHS = {'desktop': 1440, 'tablet': 900, 'mobile': 390}
HEIGHTS = {'hero': {'desktop': 720, 'tablet': 600, 'mobile': 300},
           'footer': {'desktop': 420, 'tablet': 420, 'mobile': 300}}
DEPTHS = {'desktop': 30, 'tablet': 35, 'mobile': 40}
PINK, CYAN = [240, 30, 180, 255], [20, 220, 235, 255]


def png(width, height, pixel):
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data) & 0xffffffff)
    data = b''.join(b'\0' + b''.join(bytes(pixel(x, y)) for x in range(width)) for y in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', width, height, 8, 6, 0, 0, 0)) \
        + chunk(b'IDAT', zlib.compress(data)) + chunk(b'IEND', b'')


BACKGROUND = png(600, 400, lambda x, y: (28 + (x // 60) * 13, 34 + (y // 40) * 17, 68 + ((x // 60 + y // 40) % 2) * 42, 255))
ALTERNATE = png(600, 400, lambda x, y: (80 + (x // 60) * 10, 24 + (y // 40) * 12, 45, 255))
PORTRAITS = [png(120, 240, lambda x, y, color=color: [0, 0, 0, 0] if x < 12 or x >= 108 else color)
             for color in (PINK, CYAN)]


def near(actual, expected, label, tolerance=.0006):
    check(math.isfinite(actual) and abs(actual - expected) <= tolerance,
          f'{label}: expected {expected:.8f}, got {actual:.8f}; tolerance {tolerance}.')


def settled(page, public=False):
    selector = '[data-scene] img' if public else '[data-canvas] img'
    page.locator(selector).evaluate_all('''images => Promise.all(images.map(image => {
        if (!image.getAttribute('src')) return Promise.resolve();
        return image.decode().catch(() => {});
    }))''')
    page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')


def dismiss_age(page):
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()


def choose(page, section, device, tab='layer'):
    page.locator(f'[data-section="{section}"]').click()
    page.locator(f'[data-device="{device}"]').click()
    page.locator(f'[data-property-tab="{tab}"]').click()
    settled(page)


def control(page, name, value):
    element = page.locator(f'[data-control="{name}"]')
    expect(element).to_be_enabled()
    if element.get_attribute('type') == 'range':
        element.evaluate('''(input, value) => {
            input.value = String(value); input.dispatchEvent(new Event('input', {bubbles:true}));
            input.dispatchEvent(new Event('change', {bubbles:true}));
        }''', value)
    elif element.get_attribute('type') == 'checkbox':
        element.set_checked(bool(value))
    else:
        element.fill(str(value))
        element.press('Tab')
    settled(page)


def select_layer(page, identity):
    page.locator('[data-property-tab="layer"]').click()
    page.locator(f'[data-layer-select="{identity}"]').click()


def read_scene(context, base, section):
    return http.response_json(context.request.get(http.route(base, 'api/scene.php?section=' + section)),
                              200, section + ' browser read')


def api(context, base, token, section, operation, scene=None, version=None):
    payload = {'action': operation}
    if scene is not None:
        payload['scene'] = scene
    if version is not None:
        payload['versionId'] = version
    return http.response_json(context.request.post(http.route(base, 'api/scene.php?section=' + section),
                                                   headers={'X-CSRF-Token': token}, data=payload),
                              200, section + ' fixture ' + operation)


def action(page, base, section, operation):
    page.locator(f'[data-section="{section}"]').click()
    with page.expect_response(lambda response: media.is_route_url(response.url, base, 'api/scene.php')
                              and response.request.method == 'POST') as captured:
        page.locator(f'[data-action="{operation}"]').click()
    result = http.response_json(captured.value, 200, section + ' owner ' + operation)
    check(captured.value.request.post_data_json['action'] == operation, 'The real owner control sent the wrong action.')
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    expect(page.locator('[data-status]')).not_to_have_class(re.compile(r'\berror\b'))
    return result


def geometry(page, section, public=False):
    return page.evaluate('''({section, publicView}) => {
        const root = document.querySelector(publicView ? `[data-scene="${section}"]` : '[data-canvas]');
        const frame = root.getBoundingClientRect();
        const rect = node => {
            const r = node.getBoundingClientRect();
            return {x:(r.left-frame.left)/frame.width, y:(r.top-frame.top)/frame.height,
                width:r.width/frame.width, height:r.height/frame.height};
        };
        const bg = root.querySelector(publicView ? '.scene-background' : '[data-canvas-bg]');
        const r = bg.getBoundingClientRect(), style = getComputedStyle(bg);
        // Independent browser painting calculation; no application geometry helper.
        const factor = (style.objectFit === 'contain' ? Math.min : Math.max)(r.width/bg.naturalWidth, r.height/bg.naturalHeight);
        const width = bg.naturalWidth*factor, height = bg.naturalHeight*factor;
        const position = style.objectPosition.split(' ').map(parseFloat);
        const background = {x:(r.left+(r.width-width)*position[0]/100-frame.left)/frame.width,
            y:(r.top+(r.height-height)*position[1]/100-frame.top)/frame.height,
            width:width/frame.width, height:height/frame.height, fit:style.objectFit, src:bg.src};
        const layers = {};
        root.querySelectorAll(publicView ? '.public-scene-layer' : '.composer-layer').forEach(node => {
            const image = node.querySelector('img');
            layers[node.dataset.layerId] = {rect:rect(image || node), src:image?.src || null,
                hidden:getComputedStyle(node).display === 'none', tag:node.tagName};
        });
        return {frame:{x:frame.left,y:frame.top,width:frame.width,height:frame.height}, background, layers,
            foreground:root.querySelectorAll('.scene-foreground').length,
            overflow:getComputedStyle(root).overflow};
    }''', {'section': section, 'publicView': public})


def expected_projection(layer, view):
    depth = layer.get('depth', 0)
    scale = 1 / (1 + 3 * depth / 100)
    p = view.get('perspective', {'x': 50, 'y': 35})
    return {'x': (p['x'] + (layer['x'] - p['x']) * scale) / 100,
            'y': (p['y'] + (layer['y'] - p['y']) * scale) / 100,
            'width': layer['width'] * scale / 100}


def assert_projection(measured, view, label):
    for layer in view['layers']:
        identity = layer['id']
        actual = measured['layers'].get(identity)
        if layer.get('visible') is False:
            check(actual is None or actual['hidden'], label + ': a hidden cutout became visible.')
            continue
        check(actual is not None and not actual['hidden'], label + ': a visible independent layer is missing.')
        expected = expected_projection(layer, view)
        for axis in ('x', 'y'):
            near(actual['rect'][axis] + actual['rect']['width' if axis == 'x' else 'height'] / 2,
                 expected[axis], label + ' ' + identity + ' center ' + axis)
        near(actual['rect']['width'], expected['width'], label + ' ' + identity + ' width')
    check(measured['overflow'] == 'hidden', label + ': scene boundaries stopped clipping consistently.')


def compare(editor, actual, label):
    near(actual['frame']['height'] / actual['frame']['width'], editor['frame']['height'] / editor['frame']['width'], label + ' frame')
    for identity, expected in editor['layers'].items():
        if expected['hidden']:
            continue
        check(identity in actual['layers'], label + ' missing layer ' + identity)
        for key, value in expected['rect'].items():
            near(actual['layers'][identity]['rect'][key], value, label + ' ' + identity + ' ' + key)
        check(actual['layers'][identity]['src'] == expected['src'], label + ' changed independent image source.')
    for key in ('x', 'y', 'width', 'height'):
        near(actual['background'][key], editor['background'][key], label + ' background ' + key)
    check(actual['foreground'] == 1 and editor['foreground'] == 1, label + ': the valid foreground was not rendered.')


def fixture_scene(original, section, assets):
    scene = deepcopy(original)
    scene['depthAcceptance'] = {'independentPhotos': True, 'section': section}
    scene['background'] = {'src': assets['background']['file'], 'fit': 'cover', 'x': 37, 'y': 61, 'zoom': 1.17,
                           'brightness': 91, 'contrast': 109, 'saturation': 84, 'blur': .6,
                           'overlay': 17, 'overlayColor': '#1b102a', 'width': 600, 'height': 400}
    for device in WIDTHS:
        bg = deepcopy(scene['background'])
        if section == 'hero' and device == 'desktop':
            bg['fit'] = 'contain'
        photo_width = ({'desktop': 22, 'tablet': 28, 'mobile': 48} if section == 'hero'
                       else {'desktop': 14, 'tablet': 22, 'mobile': 40})[device]
        common = {'rotation': 0, 'opacity': 100, 'brightness': 100, 'contrast': 100, 'saturation': 100,
                  'blur': 0, 'shadow': 0, 'shadowOpacity': 0, 'feather': 0, 'flipX': False,
                  'visible': True, 'locked': False}
        scene['devices'][device] = {'height': HEIGHTS[section][device], 'background': bg,
            'contentBox': {'x': 3, 'y': 4, 'width': 28, 'height': 28}, 'layers': [
                {**common, 'id': f'rear-{section}-{device}', 'src': assets['rear']['file'],
                 'x': 80, 'y': 85, 'width': photo_width, 'z': 20, 'custom': {'independent': 'rear'}},
                {**common, 'id': f'front-{section}-{device}', 'src': assets['front']['file'],
                 'x': 52, 'y': 76, 'width': 12, 'z': -5, 'custom': {'independent': 'front'}},
                {'id': f'button-{section}-{device}', 'type': 'button', 'label': 'Depth booking', 'url': 'booking/',
                 'x': 90, 'y': 76, 'width': 14, 'height': 10, 'z': -20, 'fontSize': 13, 'style': 'solid', 'newTab': False},
                {**common, 'id': f'hidden-{section}-{device}', 'src': assets['front']['file'],
                 'x': 65, 'y': 55, 'width': 14, 'z': 30, 'depth': 48, 'behindForeground': True,
                 'visible': False, 'locked': True, 'custom': {'hiddenLocked': 'retained'}},
            ]}
    return scene


def draw_outline(page, section):
    page.locator('[data-property-tab="background"]').click()
    page.locator('[data-action="foreground-draw"]').click()
    for action_name in ('save', 'publish', 'preview'):
        expect(page.locator(f'[data-action="{action_name}"]')).to_be_disabled()
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    measured = geometry(page, section)
    frame, bg = measured['frame'], measured['background']
    vertices = [(43, 61), (89, 61), (89, 91), (43, 91)]
    expected = [{'x': (x / 100 - bg['x']) / bg['width'] * 100,
                 'y': (y / 100 - bg['y']) / bg['height'] * 100} for x, y in vertices]
    check(all(0 <= point[axis] <= 100 for point in expected for axis in ('x', 'y')),
          'The synthetic furniture outline must stay on the source image.')
    for x, y in vertices:
        page.mouse.click(frame['x'] + frame['width'] * x / 100, frame['y'] + frame['height'] * y / 100)
    page.locator('[data-action="foreground-finish"]').click()
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    expect(page.locator('[data-control="foregroundEnabled"]')).to_be_checked()
    fields = page.locator('[data-foreground-point-index]').evaluate_all('''inputs => inputs.map(input => ({
        index:Number(input.dataset.foregroundPointIndex), axis:input.dataset.foregroundAxis, value:Number(input.value)}))''')
    check(len(fields) == 8, 'Real pointer drawing did not produce four editable image-space vertices.')
    for field in fields:
        near(field['value'], expected[field['index']][field['axis']], 'Pointer drawn source-image vertex', .04)
    return expected


def ui_edge_cases(page, context, base, token, section, device, alternate):
    identity = f'rear-{section}-{device}'
    select_layer(page, identity)
    original = geometry(page, section)
    page.locator('[data-action="layer-depth-reset"]').click()
    expect(page.locator('[data-control="layerDepth"]')).to_have_value('0')
    expect(page.locator('[data-control="layerBehindForeground"]')).to_be_checked()
    page.locator('[data-action="undo"]').click()
    select_layer(page, identity)
    expect(page.locator('[data-control="layerDepth"]')).to_have_value(str(DEPTHS[device]))
    expect(page.locator('[data-control="layerBehindForeground"]')).to_be_checked()
    page.locator('[data-action="redo"]').click()
    select_layer(page, identity)
    expect(page.locator('[data-control="layerDepth"]')).to_have_value('0')
    page.locator('[data-action="undo"]').click()
    select_layer(page, identity)
    page.locator('[data-action="layer-lock"]').click()
    expect(page.locator('[data-control="layerDepth"]')).to_be_disabled()
    page.locator('[data-action="layer-lock"]').click()
    page.locator('[data-action="layer-hide"]').click()
    hidden = geometry(page, section)['layers'].get(identity)
    check(hidden is None or hidden['hidden'], 'Hide did not remove the rear cutout from the composite.')
    page.locator('[data-action="undo"]').click()
    select_layer(page, identity)
    page.locator('[data-snap-enabled]').set_checked(False)
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    measured = geometry(page, section)
    r, frame = measured['layers'][identity]['rect'], measured['frame']
    x, y = frame['x'] + (r['x'] + r['width'] / 2) * frame['width'], frame['y'] + (r['y'] + r['height'] * .18) * frame['height']
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + frame['width'] * .025, y + frame['height'] * .015, steps=5)
    page.mouse.up()
    moved = geometry(page, section)['layers'][identity]['rect']
    near(moved['x'] - r['x'], .025, 'Dragging a deep photo uses displayed horizontal coordinates', .004)
    near(moved['y'] - r['y'], .015, 'Dragging a deep photo uses displayed vertical coordinates', .004)
    page.locator('[data-action="undo"]').click()
    after = geometry(page, section)
    for layer in (identity, f'front-{section}-{device}'):
        for key, value in original['layers'][layer]['rect'].items():
            near(after['layers'][layer]['rect'][key], value, 'Undo preserves independent photo ' + key)

    page.locator('[data-property-tab="background"]').click()
    page.locator('[data-foreground-point-details] summary').click()
    point = page.locator('[data-foreground-point-index="0"][data-foreground-axis="x"]')
    old = float(point.input_value())
    point.fill('101')
    point.press('Tab')
    near(float(point.input_value()), old, 'Invalid numeric vertex retains last valid shape', .001)
    point.fill(str(old + 1))
    point.press('Tab')
    near(float(point.input_value()), old + 1, 'Valid numeric vertex applies', .001)
    page.locator('[data-action="undo"]').click()
    near(float(point.input_value()), old, 'One undo restores numeric vertex edit', .001)
    handle = page.locator('[data-foreground-point="0"]')
    handle.scroll_into_view_if_needed()
    box = handle.bounding_box()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.mouse.down()
    expect(page.locator('[data-action="save"]')).to_be_disabled()
    page.mouse.move(box['x'] + box['width'] / 2 + 7, box['y'] + box['height'] / 2 + 4, steps=4)
    page.mouse.up()
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    check(abs(float(point.input_value()) - old) > .1, 'Dragging a foreground handle did not edit source-image coordinates.')
    page.locator('[data-action="undo"]').click()
    near(float(point.input_value()), old, 'One undo restores a dragged vertex', .001)
    page.locator('[data-action="foreground-clear"]').click()
    expect(page.locator('[data-control="foregroundEnabled"]')).not_to_be_checked()
    check(geometry(page, section)['foreground'] == 0, 'Clear left furniture visible.')
    page.locator('[data-action="undo"]').click()
    expect(page.locator('[data-control="foregroundEnabled"]')).to_be_checked()
    # A bow tie cannot leave drawing mode or replace the valid saved shape.
    page.locator('[data-action="foreground-draw"]').click()
    page.locator('[data-canvas]').scroll_into_view_if_needed()
    frame = geometry(page, section)['frame']
    for x, y in [(45, 63), (85, 89), (45, 89), (85, 63)]:
        page.mouse.click(frame['x'] + frame['width'] * x / 100, frame['y'] + frame['height'] * y / 100)
    page.locator('[data-action="foreground-finish"]').click()
    expect(page.locator('[data-action="save"]')).to_be_disabled()
    expect(page.locator('[data-foreground-status]')).to_have_class(re.compile(r'\berror\b'))
    page.locator('[data-action="foreground-cancel"]').click()
    expect(page.locator('[data-control="foregroundEnabled"]')).to_be_checked()
    near(float(point.input_value()), old, 'Cancel preserves the previous complete polygon', .001)
    # Selecting another uploaded source retains the outline, safely disabled.
    page.locator('[data-assets-tab="media"]').click()
    page.locator(f'[data-media-id="{alternate["id"]}"] [data-media-action="background"]').click()
    expect(page.locator('[data-control="foregroundEnabled"]')).not_to_be_checked()
    expect(page.locator('[data-control="foregroundEnabled"]')).to_be_disabled()
    action(page, base, section, 'save')
    changed = read_scene(context, base, section)['scene']['devices'][device]
    check(changed['background']['src'] == alternate['file'] and changed['foreground']['enabled'] is False,
          'Changing the actual background failed to disable and retain its former mask.')
    page.locator('[data-action="undo"]').click()
    expect(page.locator('[data-control="foregroundEnabled"]')).to_be_checked()


def inside(point, polygon):
    x, y = point
    result = False
    for index, (ax, ay) in enumerate(polygon):
        bx, by = polygon[index - 1]
        if (ay > y) != (by > y) and x < (bx - ax) * (y - ay) / (by - ay) + ax:
            result = not result
    return result


def sample_png(page, data, points):
    return page.evaluate('''async ({data, points}) => {
        const image = new Image(); image.src = data; await image.decode();
        const canvas = document.createElement('canvas'); canvas.width=image.naturalWidth; canvas.height=image.naturalHeight;
        const context=canvas.getContext('2d'); context.drawImage(image,0,0);
        return points.map(point => Array.from(context.getImageData(Math.floor(point[0]*canvas.width),
            Math.floor(point[1]*canvas.height),1,1).data));
    }''', {'data': 'data:image/png;base64,' + base64.b64encode(data).decode(), 'points': points})


def pixels(page, section, device, scene, reference, label, evidence):
    measured = geometry(page, section, True)
    view, bg = scene['devices'][device], measured['background']
    polygon = [(bg['x'] + p['x'] / 100 * bg['width'], bg['y'] + p['y'] / 100 * bg['height'])
               for p in view['foreground']['points']]
    groups = {'covered': [], 'exposed': [], 'front': [], 'horizontal_edge': [], 'vertical_edge': []}
    for kind, identity in [('rear', f'rear-{section}-{device}'), ('front', f'front-{section}-{device}')]:
        r = measured['layers'][identity]['rect']
        for fx in (.3, .5, .7):
            for fy in (.18, .3, .42, .54, .66, .78, .88):
                point = (r['x'] + r['width'] * fx, r['y'] + r['height'] * fy)
                if not (.02 < point[0] < .98 and .03 < point[1] < .97):
                    continue
                # Keep samples away from mask edges and antialiasing.
                if not all(inside((point[0] + dx, point[1] + dy), polygon) == inside(point, polygon)
                           for dx, dy in [(0, -.012), (0, .012), (-.006, 0), (.006, 0)]):
                    continue
                if kind == 'front' and inside(point, polygon):
                    groups['front'].append(point)
                elif kind == 'rear':
                    groups['covered' if inside(point, polygon) else 'exposed'].append(point)
    # A foreground can match every interior sample yet draw a dark seam if its
    # base/image/shade are antialiased separately. Compare both sides of actual
    # polygon edges against the real background-only rendering. Exclude every
    # visible photo and the standalone button's larger shadow footprint.
    def unobstructed(point):
        if not (.02 < point[0] < .98 and .03 < point[1] < .97):
            return False
        for identity, layer in measured['layers'].items():
            if layer['hidden']:
                continue
            r = layer['rect']
            margin = 36 if identity.startswith('button-') else 3
            mx, my = margin / measured['frame']['width'], margin / measured['frame']['height']
            if r['x'] - mx <= point[0] <= r['x'] + r['width'] + mx \
                    and r['y'] - my <= point[1] <= r['y'] + r['height'] + my:
                return False
        return True
    for index, start in enumerate(polygon):
        end = polygon[(index + 1) % len(polygon)]
        horizontal = abs(end[0] - start[0]) >= abs(end[1] - start[1])
        for fraction in (.1, .2, .3, .4, .45, .5, .6, .7, .8, .9):
            edge = (start[0] + (end[0] - start[0]) * fraction,
                    start[1] + (end[1] - start[1]) * fraction)
            for offset in (-.7, 0, .7):
                point = (edge[0] + (0 if horizontal else offset / measured['frame']['width']),
                         edge[1] + (offset / measured['frame']['height'] if horizontal else 0))
                if unobstructed(point):
                    groups['horizontal_edge' if horizontal else 'vertical_edge'].append(point)
    check(all(len(points) >= 3 for points in groups.values()), label + ': the fixture lacks distinct covered/exposed/front samples.')
    image = page.locator(f'[data-scene="{section}"]').screenshot(animations='disabled')
    if evidence:
        (evidence / f'composer-depth-{label}-{section}-{device}.png').write_bytes(image)
    report = {}
    for group, points in groups.items():
        actual = sample_png(page, image, points)
        expected = sample_png(page, reference, points) if group in ('covered', 'horizontal_edge', 'vertical_edge') \
            else [PINK if group == 'exposed' else CYAN] * len(points)
        errors = [max(abs(a - b) for a, b in zip(rgba, target)) for rgba, target in zip(actual, expected)]
        check(max(errors) <= 3, f'{label} {section}/{device}: {group} pixels differ from real reference; '
              f'max RGBA error {max(errors)}, samples {list(zip(points, actual, expected))[:6]}.')
        report[group] = {'samples': len(points), 'max_channel_error': max(errors),
                         'example': {'normalized_point': points[0], 'actual_rgba': actual[0], 'expected_rgba': expected[0]}}
    return report


def restore_ui(page, base, section, version):
    page.locator(f'[data-section="{section}"]').click()
    page.locator('[data-property-tab="versions"]').click()
    page.once('dialog', lambda dialog: dialog.accept())
    with page.expect_response(lambda response: media.is_route_url(response.url, base, 'api/scene.php')
                              and response.request.method == 'POST') as captured:
        page.locator(f'[data-version-id="{version}"] [data-restore-version]').click()
    result = http.response_json(captured.value, 200, 'Owner version restore')
    check(captured.value.request.post_data_json['action'] == 'restore', 'Version restore used the wrong owner action.')
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    return result


def browser_case(browser, item, evidence):
    site, base, php, label = item['site'], item['base'], item['php'], item['label']
    errors, failed_assets = [], []
    owner = browser.new_context(viewport={'width': 1680, 'height': 1100})
    guest = browser.new_context(viewport={'width': 1440, 'height': 1100})
    for context in (owner, guest):
        context.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
        context.on('response', lambda response: failed_assets.append(response.url)
                   if response.status >= 400 and response.request.resource_type == 'image' else None)
    page, public = owner.new_page(), guest.new_page()
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill(http.OWNER)
        page.get_by_label('Password', exact=True).fill(http.PASSWORD)
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(base + '/admin/?page=composer', wait_until='networkidle')
        token = page.locator('meta[name="csrf-token"]').get_attribute('content')
        assets = {}
        for name, data in [('background', BACKGROUND), ('alternate', ALTERNATE), ('rear', PORTRAITS[0]), ('front', PORTRAITS[1])]:
            response = owner.request.post(http.route(base, 'api/upload.php'), headers={'X-CSRF-Token': token},
                multipart={'type': 'background' if name in ('background', 'alternate') else 'model',
                           'image': {'name': f'{label}-depth-{name}.png', 'mimeType': 'image/png', 'buffer': data}})
            result = http.response_json(response, 200, name + ' uploaded PNG')
            media.assert_asset(owner, page, site, base, result['asset'], data)
            assets[name] = result['asset']
        protected = http.snapshot(site, php)
        fixtures = {section: fixture_scene(read_scene(owner, base, section)['scene'], section, assets)
                    for section in ('hero', 'footer')}
        for section, fixture in fixtures.items():
            background_only = deepcopy(fixture)
            for view in background_only['devices'].values():
                view['layers'] = []
            api(owner, base, token, section, 'publish', background_only)
        public.goto(base + '/', wait_until='networkidle')
        dismiss_age(public)
        references = {}
        for device, width in WIDTHS.items():
            public.set_viewport_size({'width': width, 'height': 1100})
            public.wait_for_timeout(180)
            settled(public, True)
            for section in fixtures:
                references[section, device] = public.locator(f'[data-scene="{section}"]').screenshot(animations='disabled')
                if evidence:
                    (evidence / f'composer-depth-{label}-{section}-{device}-background-reference.png').write_bytes(references[section, device])
        live_before = http.snapshot(site, php)
        for section, fixture in fixtures.items():
            api(owner, base, token, section, 'save', fixture)
        page.reload(wait_until='networkidle')
        action(page, base, 'hero', 'save')
        neutral = read_scene(owner, base, 'hero')['scene']
        for view in neutral['devices'].values():
            check('perspective' not in view and 'foreground' not in view
                  and 'depth' not in view['layers'][0] and 'behindForeground' not in view['layers'][0],
                  'Saving an unchanged depth-zero legacy composition added optional depth or mask fields.')
        drawn = {}
        for section in fixtures:
            for device in WIDTHS:
                choose(page, section, device, 'background')
                control(page, 'perspectiveX', 65)
                control(page, 'perspectiveY', 35)
                select_layer(page, f'rear-{section}-{device}')
                before_front = geometry(page, section)['layers'][f'front-{section}-{device}']
                control(page, 'layerDepth', DEPTHS[device])
                control(page, 'layerBehindForeground', True)
                check(geometry(page, section)['layers'][f'front-{section}-{device}'] == before_front,
                      'Depth changed a separate front photo.')
                drawn[section, device] = draw_outline(page, section)
                if section == 'hero' and device == 'desktop':
                    ui_edge_cases(page, owner, base, token, section, device, assets['alternate'])
            # Copy includes the entire mask/perspective layout, and one undo keeps
            # the original independent three-device layouts.
            if section == 'hero':
                page.locator('[data-action="copy-layout"]').click()
                choose(page, section, 'desktop')
                select_layer(page, 'rear-hero-mobile')
                expect(page.locator('[data-control="layerDepth"]')).to_have_value(str(DEPTHS['mobile']))
                page.locator('[data-action="undo"]').click()
                choose(page, section, 'desktop')
                select_layer(page, 'rear-hero-desktop')
                expect(page.locator('[data-control="layerDepth"]')).to_have_value(str(DEPTHS['desktop']))
            action(page, base, section, 'save')
        drafts = http.snapshot(site, php)
        scenes = {section: read_scene(owner, base, section)['scene'] for section in fixtures}
        for section, scene in scenes.items():
            check(scene['depthAcceptance'] == fixtures[section]['depthAcceptance'], 'Composer lost unrelated scene metadata.')
            check(drafts['scenes'][section]['published'] == live_before['scenes'][section]['published']
                  and drafts['scenes'][section]['versions'] == live_before['scenes'][section]['versions'],
                  'Editing and saving depth published a draft or appended public history.')
            check(drafts['scenes'][section]['draft'] == scene, 'Saved scene API differs from independent committed records.')
            for device, view in scene['devices'].items():
                layer, original = view['layers'][0], fixtures[section]['devices'][device]['layers'][0]
                check(view['perspective'] == {'x': 65, 'y': 35} and layer['depth'] == DEPTHS[device]
                      and layer['behindForeground'] is True, 'Depth and room perspective did not persist independently.')
                check(all(layer[key] == original[key] for key in ('x', 'y', 'width', 'src', 'custom')),
                      'Depth altered the saved base position, scale, image source or independent metadata.')
                for index, point in enumerate(view['foreground']['points']):
                    for axis in ('x', 'y'):
                        near(point[axis], drawn[section, device][index][axis], 'Saved source-image polygon', .04)
                hidden = view['layers'][3]
                check(hidden['visible'] is False and hidden['locked'] is True and hidden['depth'] == 48
                      and hidden['custom'] == {'hiddenLocked': 'retained'}, 'Hidden locked photo state was lost.')
        page.reload(wait_until='networkidle')
        editors = {}
        for section in fixtures:
            for device in WIDTHS:
                choose(page, section, device)
                editors[section, device] = geometry(page, section)
                assert_projection(editors[section, device], scenes[section]['devices'][device], 'Reloaded Composer')
                if evidence:
                    page.locator('[data-canvas]').screenshot(path=str(evidence / f'composer-depth-{label}-{section}-{device}-composer.png'))
        with owner.expect_page() as opened:
            page.locator('[data-action="preview"]').click()
        preview = opened.value
        preview.wait_for_url(base + '/?preview=1')
        preview.wait_for_load_state('networkidle')
        dismiss_age(preview)
        reports = []
        for kind, target in [('preview', preview), ('published', public)]:
            if kind == 'published':
                preview.close()
                for section in fixtures:
                    action(page, base, section, 'publish')
                public.reload(wait_until='networkidle')
            for device, width in WIDTHS.items():
                target.set_viewport_size({'width': width, 'height': 1100})
                target.wait_for_timeout(180)
                settled(target, True)
                for section in fixtures:
                    measured = geometry(target, section, True)
                    compare(editors[section, device], measured, label + ' ' + kind + ' ' + section + '/' + device)
                    assert_projection(measured, scenes[section]['devices'][device], 'Public projection')
                    report = pixels(target, section, device, scenes[section], references[section, device], label + '-' + kind, evidence)
                    reports.append({'view': kind, 'section': section, 'device': device, 'pixels': report})
                    button = target.locator(f'[data-scene="{section}"] [data-layer-id="button-{section}-{device}"]')
                    check(button.evaluate("node => node.tagName === 'A' && !!node.href && getComputedStyle(node).pointerEvents !== 'none'"),
                          'Foreground masking swallowed or flattened the real standalone link.')
            if kind == 'preview':
                after_preview = http.snapshot(site, php)
                for section in fixtures:
                    check(after_preview['scenes'][section]['published'] == live_before['scenes'][section]['published']
                          and after_preview['scenes'][section]['versions'] == live_before['scenes'][section]['versions'],
                          'Full Page Preview published the saved draft.')
        published = http.snapshot(site, php)
        versions = {section: read_scene(owner, base, section)['versions'][0]['id'] for section in fixtures}
        for section in fixtures:
            check(len(published['scenes'][section]['versions']) == len(live_before['scenes'][section]['versions']) + 1,
                  'The real Publish control failed to append exactly one depth composition version.')
        # Native button activation is still possible above the masked foreground.
        public.set_viewport_size({'width': 1440, 'height': 1100})
        public.wait_for_timeout(180)
        with public.expect_navigation(wait_until='domcontentloaded'):
            public.locator('[data-scene="hero"] [data-layer-id="button-hero-desktop"]').click()
        parsed = urlparse(public.url)
        check(parsed.path == urlparse(base).path + '/route.php' and parse_qs(parsed.query).get('vvx_route') == ['booking'],
              'A standalone scene button lost its nested physical-route destination.')
        # Disable preserves the shape and exposes exactly the formerly covered body.
        choose(page, 'hero', 'desktop', 'background')
        control(page, 'foregroundEnabled', False)
        action(page, base, 'hero', 'publish')
        public.goto(base + '/', wait_until='networkidle')
        settled(public, True)
        disabled = geometry(public, 'hero', True)
        check(disabled['foreground'] == 0, 'A disabled foreground remained visible.')
        r = disabled['layers']['rear-hero-desktop']['rect']
        exposed = sample_png(public, public.locator('[data-scene="hero"]').screenshot(),
                             [(r['x'] + r['width'] * .5, r['y'] + r['height'] * .75)])[0]
        check(max(abs(a - b) for a, b in zip(exposed, PINK)) <= 3, 'Turning off furniture did not reveal the preserved cutout.')
        restored = restore_ui(page, base, 'hero', versions['hero'])
        check(http.without_metadata(restored['scene']) == http.without_metadata(scenes['hero']),
              'The real Versions control did not restore the full independent depth composition.')
        # A genuinely missing source cannot replay a mask over its fallback image.
        broken = deepcopy(scenes['hero'])
        missing = 'images/defaults/depth-acceptance-missing.png'
        broken['devices']['desktop']['background']['src'] = missing
        broken['devices']['desktop']['foreground']['source'] = missing
        api(owner, base, token, 'hero', 'publish', broken)
        public.reload(wait_until='networkidle')
        settled(public, True)
        failed = geometry(public, 'hero', True)
        check(failed['foreground'] == 0 and 'rear-hero-desktop' in failed['layers'],
              'A missing original background left a furniture mask over the fallback or removed the cutout.')
        check(any(missing in url for url in failed_assets), 'The missing-background case did not actually request its unavailable source.')
        api(owner, base, token, 'hero', 'restore', version=versions['hero'])
        final = http.snapshot(site, php)
        http.protected(protected, final)
        check(not errors, 'Actual Composer/public JavaScript errors: ' + repr(errors))
        check(all(missing in url for url in failed_assets), 'An actual uploaded/default image failed: ' + repr(failed_assets))
        check(item['capture'].connections == 0 and not item['capture'].payloads, 'Scene editing unexpectedly contacted SMTP.')
        return {'mode': item['mode'], 'mysql_version': final['mysql_version'], 'comparisons': reports,
                'ui_undo_redo_reset_lock_hide_drag_copy': True, 'pointer_and_numeric_polygon_edits': True,
                'invalid_polygon_cancel_clear_and_source_change': True, 'preview_did_not_publish': True,
                'version_restore': True, 'broken_source_hides_mask': True, 'smtp_connections': 0}
    finally:
        owner.close()
        guest.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='file')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'cases': {}, 'passed': False, 'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'playwright': importlib.metadata.version('playwright'), 'server': 'actual extracted Fresh, php -S without router'}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-composer-depth-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            archive, identity = http.prepare_package(work)
            summary.update(identity)
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('VELVET_CHROMIUM') or None,
                                                  args=['--no-sandbox'])
            summary['chromium'] = browser.version
            try:
                for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                    for name, prefix in [('root', ''), ('nested', '/members/velvet')]:
                        label = mode + '-' + name
                        with http.runtime(work, archive, mode, 'depth-browser-' + label, prefix, evidence) as item:
                            item['label'] = label
                            summary['cases'][label] = browser_case(browser, item, evidence)
                        print('Composer depth actual-package browser ' + label + ': PASS.', flush=True)
            finally:
                browser.close()
        summary['assertions'] = http.checks
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'composer-depth-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
