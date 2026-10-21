#!/usr/bin/env python3
"""Actual owner menu editing and public navigation in extracted Fresh packages.

No application request is replaced and no synthetic router is used. Only the
reserved external link destination is fulfilled locally for actual new-page
clicks. File and MySQL cases own independent marked disposable installations.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('vvx_menu_http', ROOT / 'tests/Http/menus_http.py')
assert spec and spec.loader
menus = importlib.util.module_from_spec(spec)
spec.loader.exec_module(menus)
check = menus.check
EXTERNAL = 'https://navigation.example.test/'
WIDTHS = [320, 390, 760, 900, 1121, 1280, 1281, 1440, 1920]
DEFAULTS = ['Home', 'Music', 'Photos', 'Videos', 'Membership', 'Store', 'Booking', 'About Me']
OPEN_RECORDER = """(() => {
    const open = window.open;
    window.menuOpenObservations = [];
    window.open = function (...args) {
        window.menuOpenObservations.push(args.map(String));
        return Reflect.apply(open, window, args);
    };
})();"""


def frames(page):
    page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')


def public_ready(page):
    expect(page.locator('.public-navigation')).to_have_class(re.compile(r'\bnavigation-enhanced\b'))
    if page.locator('#ageGate:not([hidden])').count():
        page.locator('#ageEnter').click()
    page.evaluate('() => document.fonts.ready')
    frames(page)


def public_goto(page, destination):
    response = page.goto(destination, wait_until='networkidle')
    check(response is not None and response.status == 200, 'An actual public route failed: ' + destination)
    public_ready(page)


def player_box(page):
    # The file fixture retains a demo track; the real MySQL installer starts
    # with no tracks. Observe the attached player in both legitimate states.
    player = page.locator('[data-player]')
    check(player.count() == 1, 'The expected public player element is missing.')
    box = player.bounding_box()
    clearance = page.evaluate('parseFloat(getComputedStyle(document.body).paddingBottom)')
    if box is None:
        check(player.evaluate('element => element.hidden') and clearance == 0,
              'An invisible player is not intentionally hidden or still reserves page space.')
    else:
        check(abs(clearance - (math.ceil(box['height']) + 8)) < 1,
              'Visible-player footer clearance changed while editing menus.')
    return box


def source_items(page):
    return json.loads(page.locator('[data-menu-json]').input_value())


def flatten(items):
    return [entry for item in items for entry in [item, *flatten(item['children'])]]


def edit(page, item_id):
    # Structural edits deliberately restore keyboard focus on the next frame.
    # Let that visible UI transition finish before a subsequent field is typed.
    frames(page)
    row = page.locator(f'[data-menu-item="{item_id}"]')
    button = row.locator(f'[data-menu-action="edit"][data-menu-id="{item_id}"]')
    if button.get_attribute('aria-expanded') != 'true':
        button.click()
        expect(row.locator(f'[data-menu-field="label"][data-menu-id="{item_id}"]')).to_be_focused()
    expect(row.locator(f'[data-menu-field="label"][data-menu-id="{item_id}"]')).to_be_visible()


def field(page, item_id, name):
    edit(page, item_id)
    return page.locator(f'[data-menu-field="{name}"][data-menu-id="{item_id}"]')


def change(page, item_id, **values):
    for name, value in values.items():
        control = field(page, item_id, name)
        if name in ('target', 'parent'):
            control.select_option(value)
        elif name == 'enabled':
            control.set_checked(value)
        else:
            control.fill(value)
        if name != 'parent':
            actual = next(item for item in flatten(source_items(page)) if item['id'] == item_id)
            check(actual[name] == value, 'The real editor changed a different field: ' + name)


def add(page, label, url, target='self', parent=None):
    before = {item['id'] for item in flatten(source_items(page))}
    if parent:
        edit(page, parent)
        page.locator(f'[data-menu-action="add-child"][data-menu-id="{parent}"]').click()
    else:
        page.locator('[data-menu-add-root]').click()
    added = [item for item in flatten(source_items(page)) if item['id'] not in before]
    check(len(added) == 1, 'Add item did not create one independent stable identity.')
    item_id = added[0]['id']
    change(page, item_id, label=label, url=url, target=target)
    return item_id


def remove(page, item_id):
    edit(page, item_id)
    page.locator(f'[data-menu-action="remove"][data-menu-id="{item_id}"]').click()
    check(item_id not in {item['id'] for item in flatten(source_items(page))}, 'Remove item left it in the editable tree.')


def submit(page, control, label):
    with page.expect_navigation(wait_until='networkidle') as navigation:
        control.click()
    response = navigation.value
    check(response is not None and response.status == 200 and response.request.method == 'GET'
          and response.request.redirected_from is not None and response.request.redirected_from.method == 'POST',
          label + ' did not complete the actual POST/Redirect/GET form.')
    check(page.locator('.admin-alert.error').count() == 0, label + ' returned an owner error.')
    expect(page.locator('[data-menu-editor]')).to_have_attribute('data-menu-ready', 'true')


def open_editor(page, base, location):
    response = page.goto(base + '/admin/?page=menus&location=' + location, wait_until='networkidle')
    check(response is not None and response.status == 200, 'The current owner could not open Menus.')
    expect(page.locator('[data-menu-editor]')).to_have_attribute('data-menu-ready', 'true')


def save(page):
    expected = source_items(page)
    revision = int(page.locator('[data-menu-editor] [name=revision]').input_value())
    submit(page, page.locator('[data-menu-save]'), 'Save menu')
    check(source_items(page) == expected, 'The real owner save changed the ordered menu tree.')
    check(int(page.locator('[data-menu-editor] [name=revision]').input_value()) == revision + 1,
          'The owner save did not advance the current menu revision.')
    return expected


def reset(page):
    submit(page, page.locator('[data-menu-reset] button'), 'Restore default menu')


def screenshot(page, evidence, label, selector=None, prefix='menus-'):
    if evidence is None:
        return None
    target = page.locator(selector) if selector else page
    if selector:
        target.scroll_into_view_if_needed()
    frames(page)
    name = prefix + label + '.png'
    target.screenshot(path=str(evidence / name))
    check((evidence / name).stat().st_size > 1000, 'The required menu screenshot is empty.')
    return name


def bounds(page, selector, label):
    measured = page.locator(selector).evaluate("""root => {
        const viewport = innerWidth;
        const rect = element => { const r = element.getBoundingClientRect();
            return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}; };
        const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
        return {viewport, root:rect(root), controls:[...root.querySelectorAll('a,button')].filter(visible)
            .map(element => ({text:element.innerText || element.getAttribute('aria-label'),...rect(element)})),
            panels:[...root.querySelectorAll('[data-menu-panel]')].filter(visible).map(rect)};
    }""")
    check(measured['root']['left'] >= -1 and measured['root']['right'] <= measured['viewport'] + 1,
          label + ' extends beyond the viewport: ' + repr(measured))
    check(all(node['left'] >= -1 and node['right'] <= measured['viewport'] + 1 for node in measured['controls']),
          label + ' clips a visible link or disclosure horizontally: ' + repr(measured))
    check(all(node['left'] >= -1 and node['right'] <= measured['viewport'] + 1 for node in measured['panels']),
          label + ' clips an open submenu horizontally: ' + repr(measured))
    return measured


def toggle(page, instance, item_id):
    return page.locator(f'[data-menu-instance="{instance}"] [data-navigation-item="{item_id}"] > .navigation-menu-row > [data-menu-toggle]')


def link(page, instance, item_id):
    return page.locator(f'[data-menu-instance="{instance}"] [data-navigation-item="{item_id}"] > .navigation-menu-row > a')


def primary_open(page):
    button = page.locator('#mobileMenu')
    if button.is_visible() and button.get_attribute('aria-expanded') != 'true':
        button.click()
    frames(page)


def disclosure_open(page, instance, item_id):
    if instance == 'header':
        primary_open(page)
    button = toggle(page, instance, item_id)
    if button.get_attribute('aria-expanded') != 'true':
        button.click()
    expect(button).to_have_attribute('aria-expanded', 'true')
    frames(page)


def defaults(page, base):
    records = []
    for renderer, destination in [('home', base + '/'), ('common', menus.route(base, 'about/'))]:
        public_goto(page, destination)
        check(page.locator('#primaryNav > a:not(.mobile-account-nav)').all_text_contents() == DEFAULTS,
              'An untouched header default label/order changed.')
        expected_footer = DEFAULTS if renderer == 'home' else DEFAULTS[:6] + ['Messages'] + DEFAULTS[6:]
        check(page.locator('.legal-footer nav > a').all_text_contents() == expected_footer,
              'The untouched page-specific footer changed.')
        check(page.locator('[data-menu-custom]').count() == 0, 'Merely reading defaults configured a custom menu.')
        check(page.locator('.site-credit a').get_attribute('href') == 'https://cajunwebpro.com/'
              and page.locator('.legal-links a').count() == 5, 'Menu rendering removed fixed credit/legal links.')
        for width in [320, 760, 1120, 1280, 1281, 1440]:
            page.set_viewport_size({'width': width, 'height': 1000})
            frames(page)
            check(page.locator('#mobileMenu').is_visible() == (width <= 1280), 'The default header breakpoint changed.')
            check(page.locator('.legal-footer nav').is_visible() == (width > 1120), 'The default footer visibility changed.')
            if width <= 1280:
                page.locator('#mobileMenu').click()
                expect(page.locator('#primaryNav')).to_be_visible()
                expect(page.locator('#mobileMenu')).to_have_attribute('aria-expanded', 'true')
                check(page.locator('#primaryNav .mobile-account-nav').all_text_contents() == ['Cart', 'Login'],
                      'The default drawer lost fixed guest controls.')
                page.locator('#primaryNav a').first.focus()
                page.keyboard.press('Escape')
                expect(page.locator('#mobileMenu')).to_be_focused()
                expect(page.locator('#primaryNav')).to_be_hidden()
            records.append({'renderer': renderer, 'width': width, 'default_links_preserved': True})
    page.set_viewport_size({'width': 1440, 'height': 1000})
    return records


def footer_settle(page, scripts):
    if scripts:
        frames(page)
    else:
        # Animation-frame callbacks do not execute with page scripts disabled.
        # The driver timer still permits native layout/scroll input to settle.
        page.wait_for_timeout(60)


def footer_position(page, label, expectation, expected_player, scripts=True):
    """Measure flow and real scrolling; never resize, hide or replace page content."""
    page.evaluate('window.scrollTo({top:0,behavior:"instant"})')
    footer_settle(page, scripts)
    measured = page.evaluate("""() => {
        const rect = element => { const r = element.getBoundingClientRect();
            return {top:r.top,bottom:r.bottom,height:r.height}; };
        const body = document.body, main = document.getElementById('mainContent');
        const footer = document.querySelector('.legal-footer'), player = document.querySelector('[data-player]');
        const playerState = !player ? 'omitted' : player.hidden ? 'hidden' : 'visible';
        return {viewport_width:innerWidth,viewport_height:innerHeight,
            document_height:document.scrollingElement.scrollHeight,
            client_height:document.scrollingElement.clientHeight,
            padding_bottom:parseFloat(getComputedStyle(body).paddingBottom),
            body:rect(body),main:rect(main),footer:rect(footer),
            footer_position:getComputedStyle(footer).position,player_state:playerState,
            player:playerState === 'visible' ? rect(player) : null};
    }""")
    check(measured['player_state'] == expected_player, label + ' did not exercise its expected actual player state.')
    clearance = math.ceil(measured['player']['height']) + 8 if measured['player'] else 0
    check(abs(measured['padding_bottom'] - clearance) < .1, label + ' reserves an incorrect player gap.')
    check(measured['footer_position'] not in ('fixed', 'absolute', 'sticky')
          and measured['footer']['top'] >= measured['main']['bottom'] - 1,
          label + ' overlays the footer on the page content.')
    check(abs(measured['footer']['bottom'] + clearance - measured['document_height']) <= 1,
          label + ' leaves an unused gap below its in-flow footer: ' + repr(measured))
    check(measured['body']['height'] >= measured['viewport_height'] - 1,
          label + ' does not fill a short viewport.')
    # Aim outside nested form/list scroll areas so this tests the document.
    page.mouse.move(5, measured['viewport_height'] // 2)
    page.mouse.wheel(0, 800)
    page.wait_for_timeout(150)
    footer_settle(page, scripts)
    wheel_y = page.evaluate('scrollY')
    if expectation == 'fit':
        check(measured['document_height'] == measured['client_height'] == measured['viewport_height']
              and wheel_y == 0, label + ' has unnecessary document scrolling: ' + repr(measured) + ' wheel=' + str(wheel_y))
    else:
        check(expectation == 'long' and measured['document_height'] > measured['viewport_height']
              and wheel_y > 0, label + ' suppresses necessary document scrolling.')
    page.evaluate('window.scrollTo({top:document.scrollingElement.scrollHeight,behavior:"instant"})')
    footer_settle(page, scripts)
    bottom = page.locator('.legal-footer').bounding_box()
    player = page.locator('[data-player]')
    dock = player.bounding_box() if player.count() and expected_player == 'visible' else None
    check(abs(bottom['y'] + bottom['height'] + clearance - measured['viewport_height']) <= 1,
          label + ' does not reach the viewport bottom after scrolling to the page end.')
    check(not dock or bottom['y'] + bottom['height'] <= dock['y'] + 1,
          label + ' leaves footer controls behind the fixed player.')
    return {'label': label, 'expectation': expectation, 'scripts_enabled': scripts, 'actual_wheel_scroll_y': wheel_y,
            'footer_in_flow': True, 'footer_reaches_page_end': True, 'measured': measured}


def footer_goto(page, base, route, status=200, scripts=True):
    destination = base + '/' if route == 'home' else menus.route(base, route)
    response = page.goto(destination, wait_until='networkidle')
    check(response is not None and response.status == status, 'A footer layout route returned the wrong response: ' + route)
    if scripts:
        public_ready(page)
    else:
        footer_settle(page, False)
        check(page.evaluate('document.fonts.status') == 'loaded', 'Script-free footer fonts did not finish loading.')


def default_footer_positions(browser, page, case, evidence, errors):
    base, label = case['base'], case['label']
    state = 'visible' if case['mode'] == 'file' else 'hidden'
    report = {'layouts': [], 'screenshots': []}
    # These are actual routes, including short404/auth pages, genuinely long
    # cart/home content, and a taller viewport that can fit the whole homepage.
    specs = [
        ('short-gallery-desktop', 'photos/view.php?slug=missing-footer-check', 404, 1440, 1000, 'fit', state, False),
        ('short-gallery-mobile', 'photos/view.php?slug=missing-footer-check', 404, 390, 844, 'fit', state, True),
        ('short-gallery-small', 'photos/view.php?slug=missing-footer-check', 404, 320, 568, 'long', state, False),
        ('auth-desktop', 'account/verify.php', 200, 1440, 1000, 'fit', 'omitted', False),
        ('auth-mobile', 'account/verify.php', 200, 390, 844, 'fit', 'omitted', True),
        ('auth-small', 'account/verify.php', 200, 320, 568, 'long', 'omitted', False),
        ('cart-desktop', 'cart/', 200, 1440, 900, 'long', state, False),
        ('cart-mobile', 'cart/', 200, 390, 844, 'long', state, False),
        ('home-desktop', 'home', 200, 1440, 1000, 'long', state, False),
        ('home-mobile', 'home', 200, 390, 844, 'long', state, False),
        ('home-tall-viewport', 'home', 200, 1440, 6000, 'fit', state, False),
    ]
    for name, route, status, width, height, expectation, player, capture in specs:
        page.set_viewport_size({'width': width, 'height': height})
        footer_goto(page, base, route, status)
        report['layouts'].append(footer_position(page, name, expectation, player))
        if capture:
            report['screenshots'].append(screenshot(page, evidence, label + '-' + name, prefix='footer-layout-'))
    # The same real short page must track height changes and fractional layout
    # calculations without gaining a one-pixel scroll range.
    footer_goto(page, base, 'photos/view.php?slug=missing-footer-check', 404)
    for height in (751, 1001):
        page.set_viewport_size({'width': 1440, 'height': height})
        frames(page)
        report['layouts'].append(footer_position(page, 'short-gallery-resize-' + str(height), 'fit', state))
    no_script = browser.new_context(java_script_enabled=False)
    no_script.on('page', lambda opened: opened.on('pageerror', lambda error: errors.append(str(error))))
    try:
        fallback = no_script.new_page()
        for width, height in ((1440, 1000), (390, 844)):
            fallback.set_viewport_size({'width': width, 'height': height})
            footer_goto(fallback, base, 'photos/view.php?slug=missing-footer-check', 404, scripts=False)
            report['layouts'].append(footer_position(fallback, 'script-free-hidden-player-' + str(width), 'fit', 'hidden', scripts=False))
    finally:
        no_script.close()
    # Authenticate an isolated public context before the strict menu mutation
    # baseline. Loading a visible player may legitimately synchronize the
    # member's playback record, so these page visits are not read-only storage
    # operations. Do not intercept that request or loosen the menu baseline.
    member_context = browser.new_context()
    member_context.on('page', lambda opened: opened.on('pageerror', lambda error: errors.append(str(error))))
    client = menus.member_login(base, 'free')
    try:
        member_context.add_cookies([{'name': cookie.name, 'value': cookie.value,
                                    'domain': urlparse(base).hostname, 'path': cookie.path or '/'}
                                   for cookie in client.cookies])
    finally:
        client.close()
    before_reads = menus.fixture(case['site'], 'read', case['php'])
    try:
        member = member_context.new_page()
        player_sync_responses = []
        member.on('response', lambda response: player_sync_responses.append(response.status)
                  if response.request.method == 'POST'
                  and parse_qs(urlparse(response.url).query).get('vvx_route') == ['api/member-state.php']
                  else None)
        for name, route, status, width, height, expectation, capture in [
            ('short-receipt-desktop', 'receipt/?order=missing-footer-check', 404, 1440, 900, 'fit', True),
            ('short-receipt-mobile', 'receipt/?order=missing-footer-check', 404, 390, 844, 'fit', False),
            ('account-library-desktop', 'account/library.php?tab=favorites', 200, 1440, 900, 'fit', False),
            ('messages-tall-viewport', 'messages/', 200, 1440, 1600, 'fit', False),
            ('messages-mobile', 'messages/', 200, 390, 844, 'long', False),
        ]:
            member.set_viewport_size({'width': width, 'height': height})
            footer_goto(member, base, route, status)
            report['layouts'].append(footer_position(member, name, expectation, state))
            if capture:
                report['screenshots'].append(screenshot(member, evidence, label + '-' + name, prefix='footer-layout-'))
        after_reads = menus.fixture(case['site'], 'read', case['php'])
        check(all(before_reads[key] == after_reads[key]
                  for key in ('menus', 'records', 'audit', 'protected', 'outbox_count')),
              'Public layout navigation changed menus, protected files, or the mail outbox.')
        check(all(status == 200 for status in player_sync_responses),
              'The normal public player synchronization failed during member layout navigation.')
        report['member_navigation_before_menu_baseline'] = {
            'scope': 'Separate public browser context before the strict menu preservation baseline',
            'player_sync_route': 'api/member-state.php',
            'observed_player_sync_posts': len(player_sync_responses),
            'observed_player_sync_post_statuses': player_sync_responses,
            'menus_protected_files_and_outbox_unchanged': True,
        }
    finally:
        member_context.close()
    page.set_viewport_size({'width': 1440, 'height': 1000})
    return report


def custom_footer_positions(page, case, footer, evidence):
    rows, captures = [], []
    state = 'visible' if case['mode'] == 'file' else 'hidden'
    for width, height, expectation in ((1440, 1000, 'fit'), (390, 700, 'long')):
        page.set_viewport_size({'width': width, 'height': height})
        footer_goto(page, case['base'], 'photos/view.php?slug=missing-footer-check', 404)
        collapsed_height = page.locator('.legal-footer').bounding_box()['height']
        disclosure_open(page, 'footer', footer['parent'])
        disclosure_open(page, 'footer', footer['group'])
        row = footer_position(page, 'expanded-custom-footer-' + str(width), expectation, state)
        check(row['measured']['footer']['height'] > collapsed_height,
              'Expanded footer submenus do not increase their actual in-flow footer height.')
        row['collapsed_footer_height'] = collapsed_height
        rows.append(row)
        if width == 390:
            captures.append(screenshot(page, evidence, case['label'] + '-custom-footer-mobile', prefix='footer-layout-'))
    page.set_viewport_size({'width': 1440, 'height': 1000})
    return rows, captures


def build_header(owner, base):
    open_editor(owner, base, 'header')
    check([node['label'] for node in source_items(owner)] == DEFAULTS, 'The header editor did not start from its exact defaults.')
    for item_id in ['videos', 'membership', 'shop', 'booking', 'about']:
        remove(owner, item_id)
    change(owner, 'home', label='Start', url='/')
    change(owner, 'music', label='Explore & <b>listen</b>', url='music/')
    group = add(owner, 'Library & extras', '', parent='music')
    deep = add(owner, 'Deep About <script>', 'about/?from=menu&value=a%20b#bio', parent=group)
    edit(owner, deep)
    expect(owner.locator(f'[data-menu-action="add-child"][data-menu-id="{deep}"]')).to_be_disabled()
    child_tab = add(owner, 'New tab', EXTERNAL + 'tab?source=menu', 'tab', 'music')
    child_window = add(owner, 'New window', EXTERNAL + 'window?source=menu', 'window', 'music')
    native_window = add(owner, 'Native window destination', 'about/?native_window=1', 'window', 'music')
    long_label = 'A deliberately long submenu title with words and XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'
    long_id = add(owner, long_label[:80], 'about/', parent=group)
    hidden = add(owner, 'Hidden parent', 'photos/')
    add(owner, 'Hidden descendant', 'photos/', parent=hidden)
    change(owner, hidden, enabled=False)
    empty_group = add(owner, 'Hidden-only group', '')
    hidden_child = add(owner, 'Disabled only child', 'photos/', parent=empty_group)
    change(owner, hidden_child, enabled=False)
    more = add(owner, 'More information', 'about/')
    change(owner, more, parent=group)
    check(any(item['id'] == more for item in next(item for item in flatten(source_items(owner)) if item['id'] == group)['children']),
          'The real Parent item control did not move the item.')
    change(owner, more, parent='')
    for _ in range(2):
        owner.locator(f'[data-menu-action="up"][data-menu-id="{more}"]').click()
    remove(owner, 'photos')
    expected = save(owner)
    # A discarded edit must never leak into either the persisted model or public view.
    change(owner, 'home', label='Discard this unsaved draft')
    owner.locator('[data-menu-discard]').click()
    check(source_items(owner) == expected, 'Discard unsaved changes failed to restore the saved tree.')
    owner.reload(wait_until='networkidle')
    expect(owner.locator('[data-menu-editor]')).to_have_attribute('data-menu-ready', 'true')
    check(source_items(owner) == expected, 'Reload changed the saved menu identities/order/targets.')
    return {'parent': 'music', 'group': group, 'deep': deep, 'tab': child_tab, 'window': child_window,
            'native_window': native_window, 'long': long_id, 'more': more, 'hidden': hidden,
            'empty_group': empty_group, 'items': expected}


def build_footer(owner, base):
    open_editor(owner, base, 'footer')
    check([node['label'] for node in source_items(owner)] == DEFAULTS[:6] + ['Messages'] + DEFAULTS[6:],
          'The footer editor lost its documented common-page starting defaults.')
    for node in list(source_items(owner)):
        remove(owner, node['id'])
    parent = add(owner, 'Footer links & information', '')
    group = add(owner, 'More resources', '', parent=parent)
    deep = add(owner, 'About this site', 'about/', parent=group)
    tab = add(owner, 'Footer new tab', EXTERNAL + 'footer-tab', 'tab', parent)
    window = add(owner, 'Footer new window', EXTERNAL + 'footer-window', 'window', parent)
    anchor = add(owner, 'Back to top', '#top')
    expected = save(owner)
    return {'parent': parent, 'group': group, 'deep': deep, 'tab': tab, 'window': window,
            'anchor': anchor, 'items': expected}


def rejected_draft(owner, base, case, decisions):
    open_editor(owner, base, 'header')
    expected = source_items(owner)
    before = menus.fixture(case['site'], 'read', case['php'])
    edit(owner, 'home')
    close = owner.locator('[data-menu-action="edit"][data-menu-id="home"]')
    close.click()
    expect(close).to_be_focused()
    close.click()
    expect(owner.locator('[data-menu-field="label"][data-menu-id="home"]')).to_be_focused()
    temporary = add(owner, 'Temporary keyboard item', 'about/')
    remove(owner, temporary)
    expect(owner.locator(f'[data-menu-action="edit"][data-menu-id="{expected[-1]["id"]}"]')).to_be_focused()
    check(source_items(owner) == expected, 'Removing a temporary keyboard item changed the saved draft.')
    change(owner, 'home', url='javascript:alert(1)')
    with owner.expect_navigation(wait_until='networkidle') as navigation:
        owner.locator('[data-menu-save]').click()
    response = navigation.value
    check(response is not None and response.status == 422 and response.request.method == 'POST',
          'The unsafe destination was not rejected by the actual owner POST.')
    expect(owner.locator('[data-menu-editor]')).to_have_attribute('data-menu-ready', 'true')
    expect(owner.locator('[data-menu-editor]')).to_have_attribute('data-menu-dirty', 'true')
    check(source_items(owner)[0]['url'] == 'javascript:alert(1)' and owner.locator('.admin-alert.error').count() > 0,
          'The rejected form lost the attempted correction or its validation message.')
    decisions.append(False)
    owner.locator('[data-menu-location-link]', has_text='Footer').click()
    check('location=header' in owner.url and not decisions, 'Canceling location navigation discarded an unsaved rejected edit.')
    expect(owner.locator('[data-menu-editor]')).to_have_attribute('data-menu-dirty', 'true')
    with owner.expect_navigation(wait_until='networkidle') as navigation:
        owner.locator('[data-menu-discard]').click()
    check(navigation.value is not None and navigation.value.status == 200 and navigation.value.request.method == 'GET',
          'Discarding a rejected edit did not reload the current saved menu.')
    expect(owner.locator('[data-menu-editor]')).to_have_attribute('data-menu-ready', 'true')
    expect(owner.locator('[data-menu-editor]')).to_have_attribute('data-menu-dirty', 'false')
    check(source_items(owner) == expected, 'Discard/reload restored the rejected submission instead of the saved menu.')
    menus.unchanged(before, menus.fixture(case['site'], 'read', case['php']))
    return {'server_rejected_unsafe_url': True, 'attempt_stays_dirty': True,
            'cancel_location_preserves_attempt': True, 'discard_reloads_saved_tree': True,
            'edit_close_remove_keyboard_focus': True}


def owner_mobile(owner, base, evidence, label):
    open_editor(owner, base, 'footer')
    original = source_items(owner)
    owner.set_viewport_size({'width': 390, 'height': 844})
    owner.wait_for_function('document.querySelector(".admin-sidebar").getBoundingClientRect().right <= 1')
    sidebar = owner.locator('.sidebar-toggle')
    sidebar.click()
    expect(sidebar).to_have_attribute('aria-expanded', 'true')
    owner.wait_for_function('document.querySelector(".admin-sidebar").getBoundingClientRect().left >= -1')
    owner.mouse.click(380, 20)
    expect(sidebar).to_have_attribute('aria-expanded', 'false')
    owner.wait_for_function('document.querySelector(".admin-sidebar").getBoundingClientRect().right <= 1')
    added = add(owner, 'Phone menu edit', 'about/')
    save(owner)
    change(owner, added, label='Phone menu edited', target='tab')
    save(owner)
    edit(owner, added)
    measured = bounds(owner, '.menu-admin-page', 'Owner mobile menu editor')
    captured = screenshot(owner, evidence, label + '-owner-mobile')
    remove(owner, added)
    save(owner)
    check(source_items(owner) == original, 'The actual phone Add/Edit/Save/Remove flow changed pre-existing footer items.')
    owner.set_viewport_size({'width': 1440, 'height': 1000})
    return {'sidebar_open_and_outside_close': True, 'real_add_edit_save_remove': True,
            'measured': measured}, captured


def keyboard(page, ids, instance='header'):
    if instance == 'header':
        primary_open(page)
    parent = toggle(page, instance, ids['parent'])
    parent.focus()
    page.keyboard.press('Shift+Tab')
    page.keyboard.press('Tab')
    expect(parent).to_be_focused()
    check(parent.evaluate('element => element.matches(":focus-visible")'), 'A menu disclosure lacks keyboard focus indication.')
    if parent.get_attribute('aria-expanded') == 'true':
        parent.click()
    page.keyboard.press('Enter')
    expect(parent).to_have_attribute('aria-expanded', 'true')
    group = toggle(page, instance, ids['group'])
    page.keyboard.press('Tab')
    expect(group).to_be_focused()
    page.keyboard.press('Space')
    expect(group).to_have_attribute('aria-expanded', 'true')
    page.keyboard.press('Tab')
    expect(link(page, instance, ids['deep'])).to_be_focused()
    page.keyboard.press('Escape')
    expect(group).to_be_focused()
    expect(group).to_have_attribute('aria-expanded', 'false')
    page.keyboard.press('Escape')
    expect(parent).to_be_focused()
    expect(parent).to_have_attribute('aria-expanded', 'false')
    page.keyboard.press('Tab')
    check(not page.evaluate('() => Boolean(document.activeElement.closest("[data-menu-panel]"))'),
          'A hidden submenu remains in the native Tab sequence.')
    disclosure_open(page, instance, ids['parent'])
    page.locator('.public-navigation .brand-lockup').focus()
    frames(page)
    expect(parent).to_have_attribute('aria-expanded', 'false')
    disclosure_open(page, instance, ids['parent'])
    # Click an uncovered strip below the drawer. A mobile drawer deliberately
    # covers the top of main, so its first pixel is not an outside target.
    point = page.evaluate("""() => {
        const nav = document.querySelector('#primaryNav').getBoundingClientRect();
        const player = document.querySelector('[data-player]');
        const bottom = player && !player.hidden ? player.getBoundingClientRect().top : innerHeight;
        return {x:4,y:Math.min(bottom - 10, nav.bottom + 12)};
    }""")
    page.mouse.click(point['x'], point['y'])
    expect(parent).to_have_attribute('aria-expanded', 'false')
    return {'tab_and_shift_tab': True, 'enter_and_space': True, 'escape_restores_focus': True,
            'hidden_items_not_tabbable': True, 'focus_outside_and_pointer_outside_close': True}


def popup(page, context, ids, instance, item_id, expected_url, kind, modifiers=None, button='left'):
    disclosure_open(page, instance, ids['parent'])
    before = page.url
    calls = page.evaluate('window.menuOpenObservations.length')
    with context.expect_page() as opened:
        link(page, instance, item_id).click(modifiers=modifiers or [], button=button)
    child = opened.value
    try:
        child.wait_for_url(expected_url, wait_until='domcontentloaded')
        check(child.url == expected_url, 'The real menu click opened an unexpected destination: ' + child.url)
        if expected_url.startswith(EXTERNAL):
            check(child.locator('body').inner_text() == 'Controlled menu destination',
                  'The controlled external link response was not received.')
        else:
            public_ready(child)
            check(child.locator('.public-navigation').count() == 1,
                  'The native modified click did not reach the actual local public route.')
        check(child.evaluate('window.opener === null'), 'A new menu page retained its opener.')
        check(page.url == before, 'A separate-page menu target changed the original page.')
        observations = page.evaluate('window.menuOpenObservations')
        requested_window = kind == 'window' and not modifiers and button == 'left'
        check(len(observations) == calls + int(requested_window), 'A menu click intercepted native modifiers or omitted its window request.')
        if requested_window:
            check(observations[-1] == [expected_url, '_blank', 'popup,noopener,noreferrer'],
                  'The new-window request did not retain popup/noopener/noreferrer features.')
        check(link(page, instance, item_id).get_attribute('target') == '_blank'
              and {'noopener', 'noreferrer'} <= set(link(page, instance, item_id).get_attribute('rel').split()),
              'The native separate-page fallback is unsafe or missing.')
        return {'instance': instance, 'kind': kind, 'modifiers': modifiers or [], 'button': button,
                'url': child.url, 'opener_null': True, 'original_page_unchanged': True,
                'script_window_request': requested_window}
    finally:
        child.close()


def public_matrix(page, base, ids, footer, evidence, label):
    rows, captures = [], []
    for renderer, destination in [('home', base + '/'), ('common', menus.route(base, 'about/'))]:
        public_goto(page, destination)
        check(page.locator('#primaryNav b,#primaryNav script').count() == 0,
              'A saved menu label became executable HTML.')
        check(link(page, 'header', ids['parent']).locator('.navigation-label').inner_text() == 'Explore & <b>listen</b>',
              'The custom menu label did not remain literal escaped text.')
        check(page.locator(f'[data-navigation-item="{ids["hidden"]}"]').count() == 0
              and page.locator(f'[data-navigation-item="{ids["empty_group"]}"]').count() == 0,
              'A disabled branch or empty group leaked into public navigation.')
        check(page.locator('.legal-footer .site-credit a').get_attribute('href') == 'https://cajunwebpro.com/'
              and page.locator('.legal-footer .legal-links a').count() == 5,
              'A saved footer menu replaced independent legal/designer links.')
        for width in WIDTHS:
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('window.scrollTo({top:0,behavior:"instant"})')
            frames(page)
            disclosure_open(page, 'header', ids['parent'])
            disclosure_open(page, 'header', ids['group'])
            header = bounds(page, '.public-navigation', label + ' ' + renderer + ' header ' + str(width))
            root_panel = page.locator('#public-menu-header-' + ids['parent']).bounding_box()
            if page.locator('#mobileMenu').is_visible():
                nav = page.locator('#primaryNav').bounding_box()
                player = player_box(page)
                check(nav['y'] >= header['root']['bottom'] - 1 and nav['y'] + nav['height'] <= (player['y'] if player else 1000) + 1,
                      'The header drawer overlaps the header or persistent player.')
            else:
                check(root_panel['y'] >= 0 and root_panel['y'] + root_panel['height'] <= 1000,
                      'The desktop dropdown leaves the usable viewport.')
            if width in (390, 1440):
                captures.append(screenshot(page, evidence, label + '-' + renderer + '-header-' + str(width)))
            page.keyboard.press('Escape')
            page.locator('.public-navigation .brand-lockup').focus()
            frames(page)
            disclosure_open(page, 'footer', footer['parent'])
            disclosure_open(page, 'footer', footer['group'])
            page.locator('.legal-footer .site-credit').scroll_into_view_if_needed()
            frames(page)
            foot = bounds(page, '.legal-footer', label + ' ' + renderer + ' footer ' + str(width))
            credit = page.locator('.legal-footer .site-credit').bounding_box()
            player = player_box(page)
            check(credit['y'] + credit['height'] <= (player['y'] if player else 1000) + 1,
                  'The saved custom footer leaves its independent credit under the player.')
            if width in (390, 1440):
                captures.append(screenshot(page, evidence, label + '-' + renderer + '-footer-' + str(width), '.legal-footer'))
            rows.append({'renderer': renderer, 'width': width, 'header': header, 'footer': foot,
                         'persistent_player_visible': player is not None})
    return rows, captures


def stress_menu(owner, page, base):
    open_editor(owner, base, 'header')
    for node in list(source_items(owner)):
        remove(owner, node['id'])
    # Fifty genuine Add item actions exercise the advertised limit, saved order
    # and very long top-level labels without writing a fixture tree behind the UI.
    for number in range(50):
        add(owner, ('Long navigation item ' + str(number + 1) + ' ' + 'W' * 80)[:80], 'about/?item=' + str(number + 1))
    expect(owner.locator('[data-menu-add-root]')).to_be_disabled()
    saved = save(owner)
    public_goto(page, base + '/')
    results = []
    for width in (320, 1440, 1920):
        page.set_viewport_size({'width': width, 'height': 1000})
        frames(page)
        expect(page.locator('#mobileMenu')).to_be_visible()
        primary_open(page)
        check(page.locator('#primaryNav [data-navigation-item]').count() == 50, 'The public long menu silently lost saved items.')
        last = link(page, 'header', saved[-1]['id'])
        last.scroll_into_view_if_needed()
        check(last.is_visible(), 'The last item in a long menu cannot be reached.')
        measured = bounds(page, '#primaryNav', 'Long menu at ' + str(width))
        check(page.locator('#primaryNav').evaluate('element => element.scrollHeight > element.clientHeight'),
              'A long menu lacks its bounded vertical scrolling region.')
        results.append({'width': width, 'items': 50, 'measured': measured})
        page.keyboard.press('Escape')
    return results


def empty_and_reset(owner, page, base):
    records = []
    for location in ('header', 'footer'):
        open_editor(owner, base, location)
        for node in list(source_items(owner)):
            remove(owner, node['id'])
        save(owner)
        public_goto(page, base + '/')
        check(page.locator(f'[data-menu-instance="{location}"] [data-navigation-item]').count() == 0,
              'An explicitly empty saved menu fell back to defaults.')
        check(page.locator('#primaryNav .mobile-account-nav').count() == 2
              and page.locator('.legal-footer .site-credit a').count() == 1,
              'Empty navigation removed independent account/credit controls.')
        reset(owner)
        records.append({'location': location, 'explicit_empty_preserved': True, 'real_reset': True})
    defaults(page, base)
    return records


def browser_case(browser, case, evidence):
    label, base = case['label'], case['base']
    owner_context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    public_context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    public_context.add_init_script(OPEN_RECORDER)
    public_context.route(EXTERNAL + '**', lambda route: route.fulfill(status=200, content_type='text/html',
        body='<!doctype html><title>Controlled destination</title><body>Controlled menu destination</body>'))
    errors, actions, dialogs, decisions = [], [], [], []
    for context in (owner_context, public_context):
        context.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
    owner, page = owner_context.new_page(), public_context.new_page()
    def handle_dialog(dialog):
        dialogs.append(dialog.type)
        if decisions and not decisions.pop(0):
            dialog.dismiss()
        else:
            dialog.accept()
    owner.on('dialog', handle_dialog)
    owner.on('request', lambda request: actions.extend(parse_qs(request.post_data or '').get('action', []))
             if request.method == 'POST' else None)
    report = {'screenshots': []}
    try:
        report['defaults'] = defaults(page, base)
        report['footer_placement'] = default_footer_positions(browser, page, case, evidence, errors)
        owner.goto(base + '/admin/?page=login', wait_until='networkidle')
        owner.get_by_label('Email address').fill(menus.OWNER)
        owner.get_by_label('Password', exact=True).fill(menus.PASSWORD)
        owner.get_by_role('button', name='Sign in', exact=True).click()
        owner.wait_for_url(base + '/admin/')
        original = menus.fixture(case['site'], 'read', case['php'])
        ids = build_header(owner, base)
        report['rejected_draft'] = rejected_draft(owner, base, case, decisions)
        report['screenshots'].append(screenshot(owner, evidence, label + '-owner-header'))
        footer = build_footer(owner, base)
        report['screenshots'].append(screenshot(owner, evidence, label + '-owner-footer'))
        report['owner_mobile'], captured = owner_mobile(owner, base, evidence, label)
        report['screenshots'].append(captured)
        public_goto(page, base + '/')
        report['keyboard_header_desktop'] = keyboard(page, ids)
        report['keyboard_footer_desktop'] = keyboard(page, footer, 'footer')
        page.set_viewport_size({'width': 390, 'height': 1000})
        page.evaluate('window.scrollTo({top:0,behavior:"instant"})')
        frames(page)
        report['keyboard_header_mobile'] = keyboard(page, ids)
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.evaluate('window.scrollTo({top:0,behavior:"instant"})')
        frames(page)
        report['popups'] = []
        # Native modified clicks can begin before Chromium exposes the new
        # target to Playwright's route hook. A real local application URL keeps
        # this check observable and avoids any uncontrolled external request.
        native_url = link(page, 'header', ids['native_window']).get_attribute('href')
        for values in [
            (ids, 'header', ids['tab'], EXTERNAL + 'tab?source=menu', 'tab', None, 'left'),
            (ids, 'header', ids['window'], EXTERNAL + 'window?source=menu', 'window', None, 'left'),
            (ids, 'header', ids['native_window'], native_url, 'window', ['Control'], 'left'),
            (ids, 'header', ids['native_window'], native_url, 'window', ['Shift'], 'left'),
            (ids, 'header', ids['native_window'], native_url, 'window', None, 'middle'),
            (footer, 'footer', footer['tab'], EXTERNAL + 'footer-tab', 'tab', None, 'left'),
            (footer, 'footer', footer['window'], EXTERNAL + 'footer-window', 'window', None, 'left'),
        ]:
            report['popups'].append(popup(page, public_context, *values))
        disclosure_open(page, 'header', ids['parent'])
        disclosure_open(page, 'header', ids['group'])
        same_tab = link(page, 'header', ids['deep'])
        expected_url = same_tab.get_attribute('href')
        with page.expect_navigation(wait_until='networkidle'):
            same_tab.click()
        check(page.url == expected_url and parse_qs(urlparse(page.url).query).get('vvx_route') == ['about']
              and parse_qs(urlparse(page.url).query).get('value') == ['a b'] and urlparse(page.url).fragment == 'bio',
              'Same-tab navigation lost its nested-install route, query or fragment.')
        public_ready(page)
        check(link(page, 'header', ids['more']).get_attribute('aria-current') is None,
              'A different query destination was falsely marked as the current page.')
        public_goto(page, menus.route(base, 'about/'))
        expect(link(page, 'header', ids['more'])).to_have_attribute('aria-current', 'page')
        report['same_tab_route_query_fragment'] = True
        report['layouts'], screenshots = public_matrix(page, base, ids, footer, evidence, label)
        report['screenshots'].extend(screenshots)
        footer_rows, footer_screens = custom_footer_positions(page, case, footer, evidence)
        report['footer_placement']['layouts'].extend(footer_rows)
        report['footer_placement']['screenshots'].extend(footer_screens)
        # Real touchscreen actions in an independent browser context.
        touch = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
        try:
            touch_page = touch.new_page()
            touch_page.on('pageerror', lambda error: errors.append(str(error)))
            public_goto(touch_page, base + '/')
            touch_page.locator('#mobileMenu').tap()
            toggle(touch_page, 'header', ids['parent']).tap()
            toggle(touch_page, 'header', ids['group']).tap()
            expect(link(touch_page, 'header', ids['deep'])).to_be_visible()
            touch_page.locator('#mobileMenu').tap()
            expect(touch_page.locator('#primaryNav')).to_be_hidden()
            report['touch_three_levels'] = True
        finally:
            touch.close()
        no_script = browser.new_context(viewport={'width': 1440, 'height': 1000}, java_script_enabled=False)
        no_script.route(EXTERNAL + '**', lambda route: route.fulfill(status=200, content_type='text/html', body='Controlled menu destination'))
        try:
            fallback = no_script.new_page()
            fallback.goto(base + '/', wait_until='networkidle')
            check(link(fallback, 'header', ids['window']).is_visible()
                  and link(fallback, 'header', ids['deep']).is_visible(), 'Script-free custom links/submenus are inaccessible.')
            with no_script.expect_page() as opened:
                link(fallback, 'header', ids['window']).click()
            child = opened.value
            child.wait_for_url(EXTERNAL + 'window?source=menu', wait_until='domcontentloaded')
            check(child.url == EXTERNAL + 'window?source=menu', 'Script-free new-window fallback did not open the native link.')
            child.close()
            report['native_target_fallback_without_javascript'] = True
        finally:
            no_script.close()
        report['long_menu'] = stress_menu(owner, page, base)
        report['empty_and_reset'] = empty_and_reset(owner, page, base)
        final = menus.fixture(case['site'], 'read', case['php'])
        menus.protected(original, final)
        check(not errors, 'The actual menu UI/public pages raised JavaScript errors: ' + repr(errors))
        report.update({'real_owner_actions': actions, 'confirmed_dialogs': len(dialogs), 'javascript_errors': errors,
                       'scripted_window_observation_calls_native_function': True,
                       'configuration_media_and_unrelated_records_unchanged': True,
                       'mysql_version': final['mysql_version']})
        return report
    except Exception:
        if evidence:
            for kind, current in [('owner', owner), ('public', page)]:
                try:
                    current.screenshot(path=str(evidence / ('menus-' + label + '-FAILED-' + kind + '.png')))
                except Exception:
                    pass
            (evidence / ('menus-' + label + '-partial.json')).write_text(json.dumps(report, indent=2) + '\n')
        raise
    finally:
        owner_context.close()
        public_context.close()


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    options = parser.parse_args()
    evidence_value = os.environ.get('VELVET_SCREENSHOT_DIR') or os.environ.get('VELVET_MENUS_EVIDENCE_DIR')
    evidence = Path(evidence_value).resolve() if evidence_value else None
    if evidence:
        check(not evidence.is_relative_to(ROOT) or evidence.is_relative_to(ROOT / 'artifacts'),
              'Private menu evidence must stay outside tracked/runtime source.')
        evidence.mkdir(parents=True, exist_ok=True)
    modes = ('file', 'mysql') if options.mode == 'both' else (options.mode,)
    summary = {'test_source_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'), 'server': 'php -S without a router script',
               'external_destinations_fulfilled_locally': [EXTERNAL], 'cases': {}, 'passed': False}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-menus-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            archive, identity = menus.prepare_package(work)
            summary.update(identity)
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('VELVET_CHROMIUM') or None,
                                                  args=['--no-sandbox'])
            summary['chromium'] = browser.version
            try:
                for mode in modes:
                    for path_label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
                        label = mode + '-' + path_label
                        with menus.runtime(work, archive, mode, 'menus-browser-' + label, prefix, evidence) as case:
                            case['label'] = label
                            summary['cases'][label] = browser_case(browser, case, evidence)
                        print('Menus actual-package browser ' + label + ': PASS.', flush=True)
            finally:
                browser.close()
        summary['passed'] = True
        print(json.dumps(summary, indent=2), flush=True)
        print('Menu editor actual-package browser acceptance passed.', flush=True)
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'menus-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    run()
