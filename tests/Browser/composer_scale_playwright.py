#!/usr/bin/env python3
"""Compare scene projection and compact public footers in extracted packages.

Only marked disposable installations receive geometric uploaded fixtures. The
test exercises the normal owner API, Save, Full Page Preview and Publish; it
does not replace the renderer, intercept application responses or use a routing
fallback. Only the external footer-credit destination is fulfilled locally when
checking its real new-tab link, so the test cannot contact that external site.
"""
from __future__ import annotations

import base64
from copy import deepcopy
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import time
from zipfile import ZipFile
import zlib

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('vvx_scale_package_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
assert spec is not None and spec.loader is not None
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)
require = helpers.require
EVIDENCE = helpers.EVIDENCE
WIDTHS = {'desktop': 1440, 'tablet': 900, 'mobile': 390}
HEIGHTS = {'hero': {'desktop': 760, 'tablet': 900, 'mobile': 860},
           'footer': {'desktop': 420, 'tablet': 520, 'mobile': 620}}
VIEWPORTS = [1440, 1920, 1280, 1121, 1120, 900, 1024, 768, 761, 760, 390, 430, 320]
FOOTER_VIEWPORTS = [320, 390, 760, 900, 1121, 1440]
CREDIT_URL = 'https://cajunwebpro.com/'


def png(width: int, height: int, pixel) -> bytes:
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data) & 0xffffffff)
    data = b''.join(b'\0' + b''.join(bytes(pixel(x, y)) for x in range(width)) for y in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', width, height, 8, 6, 0, 0, 0)) \
        + chunk(b'IDAT', zlib.compress(data)) + chunk(b'IEND', b'')


# Transparent side gutters and an opaque green lower marker make accidental
# clipping observable without inventing or substituting an owner's photograph.
PORTRAIT = png(160, 240, lambda x, y: (0, 0, 0, 0) if x < 12 or x >= 148
               else ((39, 240, 106, 255) if y >= 216 else (218, 58, 174, 220)))
BACKGROUND = png(640, 360, lambda x, y: (30 + (x // 80) * 18, 20 + (y // 60) * 22, 83, 255))


def device_for(width: int) -> str:
    return 'mobile' if width <= 760 else ('tablet' if width <= 1120 else 'desktop')


def near(actual: float, expected: float, label: str, tolerance: float = 0.00025) -> None:
    require(math.isfinite(actual) and abs(actual - expected) <= tolerance,
            f'{label}: expected {expected:.8f}, got {actual:.8f} (tolerance {tolerance}).')


def read_scene(context, base: str, section: str) -> dict:
    result = helpers.response_json(context.request.get(helpers.route_url(base, f'api/scene.php?section={section}')), 200, section + ' read')
    require(result['ok'], 'The authenticated scene read failed.')
    return result['scene']


def action(page, base: str, section: str, operation: str) -> None:
    page.locator(f'[data-section="{section}"]').click()
    with page.expect_response(lambda response: helpers.is_route_url(response.url, base, 'api/scene.php')
                              and response.request.method == 'POST') as captured:
        page.locator(f'[data-action="{operation}"]').click()
    result = helpers.response_json(captured.value, 200, section + ' ' + operation)
    require(result['ok'], section + ' ' + operation + ' failed.')
    require(captured.value.request.post_data_json['action'] == operation, 'The owner control sent the wrong scene action.')
    expect(page.locator('[data-action="save"]')).to_be_enabled()
    expect(page.locator('[data-status]')).not_to_have_class(re.compile(r'\berror\b'))


def settled(page, public: bool = False) -> None:
    selector = '[data-scene] img' if public else '[data-canvas] img'
    page.locator(selector).evaluate_all('''images => Promise.all(images.map(image => {
        if (!image.getAttribute('src')) return Promise.resolve();
        return image.decode();
    }))''')
    page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')


def dismiss_age(page) -> None:
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()


def snapshot(page, section: str, public: bool) -> dict:
    return page.evaluate('''({section, publicView}) => {
        const root = document.querySelector(publicView ? `[data-scene="${section}"]` : '[data-canvas]');
        const frame = root.getBoundingClientRect();
        const normalized = node => {
            const r = node.getBoundingClientRect();
            return {x: (r.left - frame.left) / frame.width, y: (r.top - frame.top) / frame.height,
                width: r.width / frame.width, height: r.height / frame.height};
        };
        const visual = node => {
            const style = getComputedStyle(node), r = node.getBoundingClientRect();
            const scale = r.width / parseFloat(style.width);
            const px = value => parseFloat(value) * scale / frame.width;
            return {font: px(style.fontSize), radius: px(style.borderTopLeftRadius),
                border: px(node.style.borderWidth || style.borderTopWidth),
                filter: Array.from(style.filter.matchAll(/(-?[\\d.]+)px/g), m => Number(m[1]) * scale / frame.width)};
        };
        const bg = root.querySelector(publicView ? '.scene-background' : '[data-canvas-bg]');
        const background = bg.getBoundingClientRect(), style = getComputedStyle(bg);
        const fit = style.objectFit === 'contain' ? Math.min : Math.max;
        const factor = fit(background.width / bg.naturalWidth, background.height / bg.naturalHeight);
        const width = bg.naturalWidth * factor, height = bg.naturalHeight * factor;
        const position = style.objectPosition.split(' ').map(parseFloat);
        const painting = {x: (background.left + (background.width - width) * position[0] / 100 - frame.left) / frame.width,
            y: (background.top + (background.height - height) * position[1] / 100 - frame.top) / frame.height,
            width: width / frame.width, height: height / frame.height};
        const layerSelector = publicView ? '.public-scene-layer' : '.composer-layer';
        const layers = {};
        root.querySelectorAll(layerSelector).forEach(node => {
            const image = node.querySelector('img');
            const rect = normalized(image || node);
            layers[node.dataset.layerId] = {rect, visual: visual(node),
                visible: {x: Math.max(0, rect.x), y: Math.max(0, rect.y),
                    right: Math.min(1, rect.x + rect.width), bottom: Math.min(1, rect.y + rect.height)},
                src: image ? image.src : null};
        });
        const content = root.querySelector(publicView ? (section === 'hero' ? '.hero-content' : '.closing-content') : '[data-content-box]');
        const copy = publicView ? Array.from(content.querySelectorAll('.hero-brand,.hero-subtitle,h1,p,.script-heading,.scene-content-buttons,.scene-content-buttons > *'),
            node => ({tag: node.tagName, text: node.textContent, rect: normalized(node)})) : [];
        return {frame: {width: frame.width, height: frame.height}, layers, content: normalized(content),
            background: {painting, fit: style.objectFit, src: bg.src, visual: visual(bg)},
            overflow: getComputedStyle(root).overflow, copy};
    }''', {'section': section, 'publicView': public})


def compare(editor: dict, public: dict, section: str, device: str, label: str, height: int | None = None) -> None:
    reference_height = height or HEIGHTS[section][device]
    for name, actual in [('Composer', editor), ('public', public)]:
        near(actual['frame']['height'] / actual['frame']['width'], reference_height / WIDTHS[device],
             label + ' ' + name + ' scene aspect ratio')
        require(actual['overflow'] == 'hidden', label + ' ' + name + ' must clip at the same scene boundary.')
    require(set(editor['layers']) == set(public['layers']), label + ' rendered the wrong device layers.')
    for identifier in editor['layers']:
        expected, actual = editor['layers'][identifier], public['layers'][identifier]
        for group in ['rect', 'visible']:
            for name, value in expected[group].items():
                near(actual[group][name], value, f'{label} {identifier} {group} {name}')
        require(expected['src'] == actual['src'], label + ' changed the uploaded image URL.')
        # Pixel properties are normalized after any ancestor transform, so this
        # checks the rendered result without prescribing a wrapper implementation.
        for name in ['font', 'radius', 'border'] if identifier.endswith('-button') else []:
            near(actual['visual'][name], expected['visual'][name], f'{label} {identifier} {name}', 0.0005)
        require(len(actual['visual']['filter']) == len(expected['visual']['filter']), label + ' changed image effects.')
        for index, value in enumerate(expected['visual']['filter']):
            near(actual['visual']['filter'][index], value, f'{label} {identifier} filter {index}', 0.0001)
    for name, value in editor['content'].items():
        near(public['content'][name], value, label + ' live content ' + name)
    require(public['background']['src'] == editor['background']['src']
            and public['background']['fit'] == editor['background']['fit'], label + ' changed background or fit.')
    for name, value in editor['background']['painting'].items():
        near(public['background']['painting'][name], value, label + ' background crop ' + name)
    for index, value in enumerate(editor['background']['visual']['filter']):
        near(public['background']['visual']['filter'][index], value, label + ' background blur', 0.0001)
    portrait = public['layers'][f'scale-{section}-{device}-photo']['rect']
    near(portrait['y'] + portrait['height'], 0.999, label + ' portrait lower edge')
    require(public['layers'][f'scale-{section}-{device}-crop']['rect']['y'] +
            public['layers'][f'scale-{section}-{device}-crop']['rect']['height'] > 1,
            label + ' must retain deliberate off-frame cropping.')


def lower_marker(page, section: str) -> list[int]:
    """Sample the real composited page, including following sections' overlap."""
    target = page.locator(f'[data-scene="{section}"]')
    target.evaluate('''node => {
        const r = node.getBoundingClientRect();
        scrollTo({top: r.top + scrollY + r.height * .994 - innerHeight / 2, behavior: 'instant'});
    }''')
    page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
    point = target.evaluate('''node => {
        const r = node.getBoundingClientRect();
        return {x: Math.floor(r.left + r.width * .75), y: Math.floor(r.top + r.height * .994)};
    }''')
    data = page.screenshot(animations='disabled')
    pixel = page.evaluate('''async ({data, point}) => {
        const image = new Image(); image.src = data; await image.decode();
        const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
        const context = canvas.getContext('2d'); context.drawImage(image, 0, 0);
        return Array.from(context.getImageData(point.x, point.y, 1, 1).data);
    }''', {'data': 'data:image/png;base64,' + base64.b64encode(data).decode(), 'point': point})
    if EVIDENCE and any(abs(actual - expected) > 2 for actual, expected in zip(pixel, [39, 240, 106, 255])):
        (EVIDENCE / f'composer-scale-{section}-{page.viewport_size["width"]}-failed-marker.png').write_bytes(data)
    require(all(abs(actual - expected) <= 2 for actual, expected in zip(pixel, [39, 240, 106, 255])),
            f'{section} {page.viewport_size["width"]}px lower marker was clipped or covered on the actual page at {point}: {pixel}.')
    return pixel


def compare_copy(expected: dict, actual: dict, label: str) -> None:
    """Real public copy must keep its wrapping and visible crop within a device."""
    require(len(expected['copy']) == len(actual['copy']) and expected['copy'], label + ' lost public live copy.')
    for index, (before, after) in enumerate(zip(expected['copy'], actual['copy'])):
        require(before['tag'] == after['tag'] and before['text'] == after['text'], label + ' changed public copy or buttons.')
        for dimension, value in before['rect'].items():
            near(after['rect'][dimension], value, f'{label} live copy {index} {dimension}', .001)


def footer_snapshot(page) -> dict:
    return page.locator('.legal-footer').evaluate('''footer => {
        const frame = footer.getBoundingClientRect(), style = getComputedStyle(footer);
        const rect = node => {
            const r = node.getBoundingClientRect();
            return {left:r.left, top:r.top, right:r.right, bottom:r.bottom, width:r.width, height:r.height};
        };
        const visible = node => {
            const s=getComputedStyle(node), r=node.getBoundingClientRect();
            return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
        };
        const groups = {};
        for (const [key, selector] of Object.entries({brand:'.brand-script', subtitle:'.brand-sub',
            signature:'.signature', navigation:'nav a', legal:'.legal-links a', credit:'.site-credit a'})) {
            groups[key] = Array.from(footer.querySelectorAll(selector)).filter(visible).map(node => ({
                font:parseFloat(getComputedStyle(node).fontSize), text:node.textContent.trim(), rect:rect(node)}));
        }
        const boxes = Array.from(footer.children).filter(visible).map(node => ({name:node.className || node.tagName, ...rect(node)}));
        const links = Array.from(footer.querySelectorAll('nav a,.legal-links a')).filter(visible)
            .map(node => ({name:node.textContent.trim(), ...rect(node)}));
        const text = [], walker = document.createTreeWalker(footer, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node=walker.currentNode;
            if (!node.textContent.trim() || !visible(node.parentElement)) continue;
            const range=document.createRange(); range.selectNodeContents(node);
            for (const r of range.getClientRects()) {
                if (r.width && r.height) text.push({text:node.textContent.trim(), left:r.left,top:r.top,right:r.right,bottom:r.bottom});
            }
        }
        const credit=footer.querySelector('.site-credit a'), creditStyle=getComputedStyle(credit);
        const player=document.querySelector('#persistentPlayer');
        return {viewport:innerWidth, viewportHeight:innerHeight, frame:rect(footer),
            bodyPaddingBottom:parseFloat(getComputedStyle(document.body).paddingBottom),
            player:player && visible(player) ? rect(player) : null, minHeight:parseFloat(style.minHeight),
            paddingTop:parseFloat(style.paddingTop), paddingBottom:parseFloat(style.paddingBottom),
            border:parseFloat(style.borderTopWidth)+parseFloat(style.borderBottomWidth),
            scrollWidth:footer.scrollWidth, clientWidth:footer.clientWidth, groups, boxes, links, text,
            credit:{href:credit.href,target:credit.target,rel:credit.rel,color:creditStyle.color,
                decoration:creditStyle.textDecorationLine,font:parseFloat(creditStyle.fontSize)}};
    }''')


def check_footer(data: dict, label: str) -> None:
    width, frame = data['viewport'], data['frame']
    require(frame['left'] >= -.5 and frame['right'] <= width + .5
            and data['scrollWidth'] <= data['clientWidth'] + 1, label + ' footer itself exceeds the viewport.')
    require(frame['top'] >= -.5 and frame['bottom'] <= data['viewportHeight'] + .5,
            label + ' footer cannot be brought fully into view.')
    if data['player']:
        require(frame['bottom'] <= data['player']['top'] + .5,
                label + ' fixed audio player covers the footer or its credit.')
    require(data['minHeight'] == 0 and data['paddingTop'] <= 10 and data['paddingBottom'] <= 10,
            label + ' reserves the old tall footer space.')
    if width == 1440:
        require(frame['height'] < 118, f'{label} desktop footer should be shorter than the old 118px minimum, got {frame["height"]}.')
    occupied = max(box['bottom'] for box in data['boxes']) - min(box['top'] for box in data['boxes'])
    require(frame['height'] - occupied <= data['paddingTop'] + data['paddingBottom'] + data['border'] + 1,
            label + ' has unexplained reserved vertical space beyond content and padding.')
    minima = {'brand': 40, 'subtitle': 12.8, 'signature': 32 if width <= 760 else 38.4,
              'navigation': 16, 'legal': 16, 'credit': 16}
    for group, minimum in minima.items():
        rows = data['groups'][group]
        expected_visible = group != 'navigation' or width > 1120
        expected_visible = expected_visible and (group != 'legal' or width > 760)
        require(bool(rows) == expected_visible, label + ' changed existing responsive visibility for ' + group + '.')
        require(all(row['font'] >= minimum - .05 for row in rows), label + ' did not enlarge ' + group + ' text.')
    credit = data['credit']
    require(credit['href'] == CREDIT_URL and credit['target'] == '_blank'
            and {'noopener', 'noreferrer'} <= set(credit['rel'].split()), label + ' credit has the wrong link or new-tab relationship.')
    color = [float(component) for component in re.findall(r'[\d.]+', credit['color'])]
    require(len(color) >= 3 and color[2] > color[0] + 30 and color[2] > color[1] + 20
            and 'underline' in credit['decoration'] and credit['font'] >= 16,
            label + ' credit must be visibly blue, underlined and at least 16px.')
    for box in [*data['boxes'], *data['text']]:
        require(box['left'] >= max(0, frame['left']) - 1 and box['right'] <= min(width, frame['right']) + 1
                and box['top'] >= frame['top'] - 1 and box['bottom'] <= frame['bottom'] + 1,
                f'{label} clips footer content: {box}.')
    for group in ['boxes', 'links']:
        for index, first in enumerate(data[group]):
            for second in data[group][index + 1:]:
                overlap_x = min(first['right'], second['right']) - max(first['left'], second['left'])
                overlap_y = min(first['bottom'], second['bottom']) - max(first['top'], second['top'])
                require(overlap_x <= 1 or overlap_y <= 1, f'{label} overlaps footer {group}: {first["name"]} / {second["name"]}.')


def footer_acceptance(context, page, base: str, label: str) -> dict:
    records, clicks = [], []
    external_navigations = []

    def external_credit(route) -> None:
        if route.request.is_navigation_request():
            external_navigations.append(route.request.url)
        route.fulfill(status=200, content_type='text/html',
                      body='<!doctype html><title>External credit navigation fixture</title><p>Local new-tab acceptance.</p>')

    pattern = 'https://cajunwebpro.com/**'
    context.route(pattern, external_credit)
    try:
        for renderer, destination in [('homepage', base + '/'), ('common', helpers.route_url(base, 'about/'))]:
            page.goto(destination, wait_until='networkidle')
            dismiss_age(page)
            expect(page.locator('.legal-footer')).to_have_count(1)
            expect(page.locator('.legal-footer .site-credit a')).to_have_count(1)
            for width in FOOTER_VIEWPORTS:
                page.set_viewport_size({'width': width, 'height': 1100})
                page.wait_for_timeout(180)
                page.locator('.legal-footer').scroll_into_view_if_needed()
                page.evaluate("() => scrollTo({top:document.documentElement.scrollHeight,behavior:'instant'})")
                page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
                data = footer_snapshot(page)
                if EVIDENCE:
                    (EVIDENCE / f'composer-scale-{label}-legal-footer-{renderer}-{width}-measurements.json').write_text(
                        json.dumps(data, indent=2) + '\n')
                check_footer(data, f'{label} {renderer} {width}px')
                records.append({'renderer': renderer, 'width': width, 'height': data['frame']['height'],
                                'fonts': {name:[row['font'] for row in rows] for name,rows in data['groups'].items()},
                                'min_height': data['minHeight'], 'credit': data['credit'],
                                'contained_and_without_overlap': True, 'clear_of_fixed_audio_player': True})
                if width in [320, 1440]:
                    opener_url, previous_count = page.url, len(external_navigations)
                    with context.expect_page() as opened:
                        page.locator('.legal-footer .site-credit a').click()
                    popup = opened.value
                    popup.wait_for_url(CREDIT_URL)
                    popup.wait_for_load_state('domcontentloaded')
                    require(not page.is_closed() and page.url == opener_url, 'The footer credit replaced its original page.')
                    require(popup.evaluate('window.opener === null'), 'The external credit retained an opener reference.')
                    require(external_navigations[previous_count:] == [CREDIT_URL], 'The new-tab click did not reach the controlled exact credit destination.')
                    clicks.append({'renderer':renderer, 'width':width, 'url':popup.url,
                                   'opener_page_preserved':True, 'noopener':True, 'external_request_fulfilled_locally':True})
                    popup.close()
                    if EVIDENCE:
                        page.locator('.legal-footer').screenshot(path=str(EVIDENCE / f'composer-scale-{label}-legal-footer-{renderer}-{width}.png'))
    finally:
        context.unroute(pattern, external_credit)
    return {'cases':records, 'new_tab_clicks':clicks,
            'larger_text_blue_underlined_credit':True, 'compact_intrinsic_height':True,
            'homepage_and_common_renderer':True, 'own_footer_bounds_and_wrapping':True}


def footer_without_tracks(page, site: Path, base: str, label: str) -> list[dict]:
    """Use real empty catalogue responses to check no hidden-player reservation."""
    require(site.resolve().is_relative_to(Path(tempfile.gettempdir()).resolve())
            and (site / '.vvx-test-runtime').is_file(), 'Empty-track fixtures require a marked disposable runtime.')
    path = site / 'storage/data.json'
    saved = path.read_bytes()
    data = json.loads(saved)
    require(data.get('mediaCenter', {}).get('tracks'), 'The disposable fixture must start with real playable track records.')
    data['mediaCenter']['tracks'] = {}
    records = []
    try:
        path.write_text(json.dumps(data))
        for renderer, destination in [('homepage', base + '/'), ('common', helpers.route_url(base, 'about/'))]:
            page.goto(destination, wait_until='networkidle')
            require(page.evaluate('window.VELVET_MEDIA_CENTER.tracks.length') == 0,
                    label + ' empty catalogue did not reach the actual public player response.')
            expect(page.locator('#persistentPlayer')).to_be_hidden()
            for width in [320, 1440]:
                page.set_viewport_size({'width': width, 'height': 1100})
                page.wait_for_timeout(180)
                page.evaluate("() => scrollTo({top:document.documentElement.scrollHeight,behavior:'instant'})")
                page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
                actual = footer_snapshot(page)
                check_footer(actual, f'{label} empty catalogue {renderer} {width}px')
                require(actual['player'] is None and actual['bodyPaddingBottom'] == 0,
                        label + ' reserves bottom space when the actual empty catalogue leaves the player hidden.')
                records.append({'renderer':renderer, 'width':width, 'height':actual['frame']['height'],
                                'server_track_count':0, 'player_hidden':True, 'body_padding_bottom':0})
    finally:
        path.write_bytes(saved)
    return records


def fixture_scene(original: dict, section: str, portrait: dict, background: dict) -> dict:
    scene = deepcopy(original)
    scene['scaleRegressionSentinel'] = 'preserve unrelated saved scene fields'
    for index, (device, width) in enumerate(WIDTHS.items()):
        view = scene.setdefault('devices', {}).setdefault(device, {})
        height = HEIGHTS[section][device]
        view['height'] = height
        view['background'] = {'src': background['file'], 'fit': 'contain' if index == 1 else 'cover',
                              'x': 27, 'y': 68, 'zoom': 1.23, 'brightness': 100, 'contrast': 100,
                              'saturation': 100, 'blur': 3, 'overlay': 0, 'overlayColor': '#080007'}
        view['contentBox'] = {'x': 4, 'y': 8, 'width': 34, 'height': 42}
        common = {'src': portrait['file'], 'mediaId': portrait['id'], 'rotation': 0, 'opacity': 100,
                  'brightness': 100, 'contrast': 100, 'saturation': 100, 'shadowX': 7, 'shadowY': 12,
                  'shadowBlur': 9, 'shadowOpacity': 35, 'visible': True, 'locked': False, 'z': 2}
        view['layers'] = [
            {**common, 'id': f'scale-{section}-{device}-photo', 'name': 'Transparent geometric portrait',
             'x': 75, 'y': 59.9, 'width': (height * .8 / 1.5) / width * 100},
            {**common, 'id': f'scale-{section}-{device}-crop', 'name': 'Deliberately cropped geometric photo',
             'x': 10, 'y': 103, 'width': 12, 'flipX': True},
            {**common, 'id': f'scale-{section}-{device}-button', 'type': 'button', 'name': 'Scale test link',
             'x': 33, 'y': 83, 'width': 26, 'height': 8, 'fontSize': 22, 'radius': 17,
             'label': 'View photos', 'url': 'photos/', 'style': 'solid', 'color': '#ff2d8d',
             'textColor': '#ffffff', 'borderColor': '#ff87cc', 'newTab': False},
        ]
    return scene


def browser_case(browser, site: Path, base: str, label: str) -> dict:
    context = browser.new_context(viewport={'width': 1680, 'height': 1100})
    errors: list[str] = []
    context.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()
    page.goto(base + '/admin/?page=login', wait_until='networkidle')
    page.get_by_label('Email address').fill('owner@example.test')
    page.get_by_label('Password', exact=True).fill('VelvetDemo12')
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url('**/admin/')
    page.goto(base + '/admin/?page=composer', wait_until='networkidle')
    token = page.locator('meta[name="csrf-token"]').get_attribute('content')
    assets = []
    for name, data in [('portrait', PORTRAIT), ('background', BACKGROUND)]:
        response = context.request.post(helpers.route_url(base, 'api/upload.php'),
                                        headers={'X-CSRF-Token': token},
                                        multipart={'image': {'name': f'{label}-scale-{name}.png', 'mimeType': 'image/png', 'buffer': data}})
        result = helpers.response_json(response, 200, name + ' actual upload')
        require(result['ok'], name + ' fixture upload failed.')
        helpers.assert_asset(context, page, site, base, result['asset'], data)
        assets.append(result['asset'])
    published_before = deepcopy(helpers.state(site)['scenes'])
    fixtures = {}
    for section in ['hero', 'footer']:
        fixtures[section] = fixture_scene(read_scene(context, base, section), section, *assets)
        result = helpers.response_json(context.request.post(helpers.route_url(base, f'api/scene.php?section={section}'),
                                       headers={'X-CSRF-Token': token}, data={'action': 'save', 'scene': fixtures[section]}), 200, section + ' draft fixture')
        require(result['ok'], 'The normal authenticated scene save rejected the geometric fixture.')
    page.reload(wait_until='networkidle')
    for section in ['hero', 'footer']:
        action(page, base, section, 'save')
        saved = read_scene(context, base, section)
        require(saved.get('scaleRegressionSentinel') == fixtures[section]['scaleRegressionSentinel'], 'Save rewrote unrelated scene data.')
        for device in WIDTHS:
            require(saved['devices'][device]['height'] == HEIGHTS[section][device], 'Save changed stored design height.')
            require(saved['devices'][device]['contentBox'] == fixtures[section]['devices'][device]['contentBox'], 'Save moved the content box.')
        require(helpers.state(site)['scenes'][section]['published'] == published_before[section]['published'], 'Save changed the live scene.')

    with context.expect_page() as opened:
        page.locator('[data-action="preview"]').click()
    preview = opened.value
    preview.wait_for_url(base + '/?preview=1')
    preview.wait_for_load_state('networkidle')
    dismiss_age(preview)
    settled(preview, True)
    for section in ['hero', 'footer']:
        page.locator(f'[data-section="{section}"]').click()
        page.locator('[data-device="desktop"]').click()
        settled(page)
        editor_snapshot, public_snapshot = snapshot(page, section, False), snapshot(preview, section, True)
        if EVIDENCE:
            (EVIDENCE / f'composer-scale-{label}-{section}-preview-measurements.json').write_text(
                json.dumps({'editor': editor_snapshot, 'public': public_snapshot}, indent=2) + '\n')
            preview.locator(f'[data-scene="{section}"]').screenshot(path=str(EVIDENCE / f'composer-scale-{label}-{section}-preview.png'))
        compare(editor_snapshot, public_snapshot, section, 'desktop', label + ' draft ' + section)
        require(helpers.state(site)['scenes'][section]['published'] == published_before[section]['published'], 'Full Page Preview published a draft.')
    preview.close()
    for section in ['hero', 'footer']:
        action(page, base, section, 'publish')
    page.reload(wait_until='networkidle')
    snapshots = {}
    for section in ['hero', 'footer']:
        for device in WIDTHS:
            page.locator(f'[data-section="{section}"]').click()
            page.locator(f'[data-device="{device}"]').click()
            settled(page)
            snapshots[section, device] = snapshot(page, section, False)
            if EVIDENCE:
                page.screenshot(path=str(EVIDENCE / f'composer-scale-{label}-{section}-{device}.png'), full_page=True)

    saved_scenes = deepcopy(helpers.state(site)['scenes'])
    guest = browser.new_context(viewport={'width': 1440, 'height': 1100})
    public = guest.new_page()
    public.on('pageerror', lambda error: errors.append(str(error)))
    public.goto(base + '/', wait_until='networkidle')
    dismiss_age(public)
    copy_baselines = {}
    for device, width in WIDTHS.items():
        public.set_viewport_size({'width': width, 'height': 1100})
        public.wait_for_timeout(180)
        settled(public, True)
        for section in ['hero', 'footer']:
            copy_baselines[section, device] = snapshot(public, section, True)
    cases = []
    page_overflow_diagnostics = []
    for width in VIEWPORTS:
        device = device_for(width)
        public.set_viewport_size({'width': width, 'height': 1100})
        # The existing renderer intentionally debounces native resize by 120 ms.
        public.wait_for_timeout(180)
        settled(public, True)
        for section in ['hero', 'footer']:
            actual = snapshot(public, section, True)
            compare(snapshots[section, device], actual, section, device, f'{label} {section} {width}px')
            compare_copy(copy_baselines[section, device], actual, f'{label} {section} {width}px')
            marker = lower_marker(public, section)
            cases.append({'section': section, 'viewport_width': width, 'device': device,
                          'frame': actual['frame'], 'lower_marker_rgba': marker})
        overflow = public.evaluate('''() => ({viewport: innerWidth, document: document.documentElement.scrollWidth,
            scenes: Array.from(document.querySelectorAll('[data-scene]')).map(node => {
                const rect=node.getBoundingClientRect(); return {section:node.dataset.scene,
                    left:rect.left,right:rect.right,overflow:getComputedStyle(node).overflowX};
            }),
            outside: Array.from(document.querySelectorAll('body *')).map(node => ({node,
                rect: node.getBoundingClientRect()})).filter(({node, rect}) => !node.closest('[data-scene]')
                    && rect.width && rect.right > innerWidth + 1)
                .slice(0, 25).map(({node, rect}) => ({tag: node.tagName, class: node.className,
                    left: rect.left, right: rect.right, width: rect.width}))})''')
        require(all(scene['left'] >= -.5 and scene['right'] <= width + .5 and scene['overflow'] == 'hidden'
                    for scene in overflow['scenes']), f'The projected scene leaks beyond the {width}px viewport: {overflow}.')
        # The 320px contact cards have separate pre-existing page overflow. Keep
        # that diagnostic distinct from clipped scenes and the legal footer,
        # whose newly requested bounds are asserted below in both renderers.
        if overflow['document'] > overflow['viewport'] + 1:
            page_overflow_diagnostics.append(overflow)
            if EVIDENCE:
                (EVIDENCE / f'composer-scale-{label}-overflow-{width}.json').write_text(json.dumps(overflow, indent=2) + '\n')
        if EVIDENCE and width in [1440, 1920, 900, 390, 320]:
            public.evaluate("() => scrollTo({top:0,behavior:'instant'})")
            public.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
            public.screenshot(path=str(EVIDENCE / f'composer-scale-{label}-public-{width}.png'), full_page=True)

    # Shrinking the editor itself must reproject effects and controls without
    # mutating device geometry. Compare the same saved scene after a real resize.
    page.set_viewport_size({'width': 390, 'height': 844})
    for section in ['hero', 'footer']:
        page.locator(f'[data-section="{section}"]').click()
        page.locator('[data-device="mobile"]').click()
        settled(page)
        compare(snapshot(page, section, False), snapshot(public, section, True), section, 'mobile', label + ' narrow editor ' + section)
    footer_results = footer_acceptance(guest, public, base, label)
    require(helpers.state(site)['scenes'] == saved_scenes, 'Viewport changes or read-only rendering rewrote saved scenes/history.')
    footer_results['empty_catalogue_cases'] = footer_without_tracks(public, site, base, label)
    require(not errors, 'Composer/public uncaught JavaScript errors: ' + '; '.join(errors))
    guest.close()
    context.close()
    return {'actual_uploads': True, 'save_preview_publish_reload': True, 'independent_hero_footer_devices': True,
            'normalized_photo_background_content_and_button_geometry': True, 'public_copy_wrap_and_crop_invariant': True,
            'visible_lower_edge_pixel': True,
            'viewport_changes_preserve_saved_scene_data': True, 'scene_bounds_clipped_to_viewport': True,
            'unrelated_page_overflow_diagnostics': page_overflow_diagnostics, 'cases': cases,
            'legal_footer': footer_results}


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for actual packaged scene projection acceptance.')
    package_spec = importlib.util.spec_from_file_location('vvx_scale_package', ROOT / 'scripts/package.py')
    require(package_spec is not None and package_spec.loader is not None, 'The actual runtime package builder is missing.')
    package = importlib.util.module_from_spec(package_spec)
    package_spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Keep scene screenshots outside runtime files or in ignored engineering artifacts.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'test_source_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-composer-scale-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary['fresh_zip_sha256'] = fresh['sha256']
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
                (site / '.vvx-test-runtime').write_text('Disposable packaged Composer scale acceptance.\n')
                with socket.socket() as listener:
                    listener.bind(('127.0.0.1', 0))
                    port = listener.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                identity = {name: (site / name).read_bytes() for name in ['config/app.php', 'storage/install.lock']}
                owners = deepcopy(helpers.state(site)['admins'])
                log_path = work / label / 'http.log'
                environment = os.environ.copy()
                environment.pop('PHP_CLI_SERVER_WORKERS', None)
                with log_path.open('w+') as log:
                    process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                                                '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                               cwd=work, env=environment, stdout=log, stderr=log)
                    try:
                        with helpers.requests_session() as session:
                            deadline = time.monotonic() + 15
                            while True:
                                require(process.poll() is None, 'The disposable PHP server exited before readiness.')
                                try:
                                    if session.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                        break
                                except requests.RequestException:
                                    pass
                                require(time.monotonic() < deadline, 'The disposable PHP server did not become ready.')
                                time.sleep(.1)
                        summary['cases'][label] = browser_case(browser, site, base, label)
                        require(all((site / name).read_bytes() == data for name, data in identity.items())
                                and helpers.state(site)['admins'] == owners, 'Projection acceptance changed installation identity or owners.')
                        print(f'Packaged Composer scale {label}: PASS (Hero/Footer, 13 viewport widths, lower-edge pixels, drafts/publication; compact homepage/common footers and real new-tab credit).', flush=True)
                    except BaseException as error:
                        if EVIDENCE:
                            summary.update({'status': 'failed', 'failure': str(error)})
                            (EVIDENCE / 'composer-scale-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
                        raise
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_path, EVIDENCE / f'composer-scale-{label}-http.log')
        finally:
            browser.close()
    if EVIDENCE:
        summary['status'] = 'passed'
        (EVIDENCE / 'composer-scale-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Actual-package Composer/public scale and compact legal-footer acceptance passed at root and nested paths.', flush=True)


if __name__ == '__main__':
    run()
