#!/usr/bin/env python3
"""Actual Fresh menu editor HTTP acceptance on root/nested file and MySQL installs.

Uses real owner forms, physical route.php dispatch, committed-record readback and
an observed SQL owner-row lock. No browser is required for this HTTP gate; the
browser suite imports these helpers and exercises the editing controls itself.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import select
import shutil
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse
from zipfile import ZipFile

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(__file__).with_name('menus_fixture.php')
sys.dont_write_bytecode = True
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_base = load('vvx_menu_runtime', ROOT / 'tests/Http/editorial_access_http.py')
session, fields, changed = _base.session, _base.fields, _base.changed
owner_login, member_login, route = _base.owner_login, _base.member_login, _base.route
runtime, evidence_directory = _base.runtime, _base.evidence_directory
OWNER, PASSWORD, MEMBER_PASSWORD = _base.OWNER, _base.PASSWORD, _base.MEMBER_PASSWORD
UI_FILES = ('admin/index.php', 'app/Core/Store.php', 'app/Core/MenuService.php', 'app/Core/MenuStoreTrait.php',
            'app/Support/menu_admin.php', 'app/Views/admin/menus.php', 'app/Views/admin/menus-actions.php',
            'app/Views/admin/navigation.php', 'app/Views/public/partials/navigation.php',
            'app/Views/public/partials/menu-items.php', 'app/Views/public/partials/header.php',
            'app/Views/public/partials/footer.php', 'public/home.php', 'public/assets/js/menu-admin.js',
            'public/assets/css/menu-admin.css', 'public/assets/js/navigation.js', 'public/assets/css/navigation.css',
            'public/assets/css/site-ux.css')


def fixture(site: Path, action: str, php: str = 'php', **values):
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=40, cwd='/tmp')
    check(result.returncode == 0, 'Guarded menu fixture failed (' + action + '): ' + result.stderr[:1800])
    return json.loads(result.stdout)


def prepare_package(work: Path):
    package = load('vvx_menu_package', ROOT / 'scripts/package.py')
    source_identity = package.package_identity({name: (ROOT / name).read_bytes()
                                                for name in ('VERSION', 'includes/version.php')})
    expected = os.environ.get('VELVET_EXPECTED_FRESH_SHA256', '').lower()
    if os.environ.get('VELVET_FRESH_PACKAGE'):
        archive = Path(os.environ['VELVET_FRESH_PACKAGE']).resolve()
        check(archive.is_file() and bool(re.fullmatch(r'[a-f0-9]{64}', expected)),
              'Pin the supplied actual Fresh ZIP with VELVET_EXPECTED_FRESH_SHA256.')
    else:
        built = package.build(ROOT, work / 'packages', source_identity[0])
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
    actual = hashlib.sha256(archive.read_bytes()).hexdigest()
    if expected:
        check(actual == expected, 'The actual Fresh archive differs from its immutable expected hash.')
    with ZipFile(archive) as zipped:
        files = {name: zipped.read(name) for name in zipped.namelist() if not name.endswith('/')}
    version, build = package.package_identity(files)
    check((version, build) == source_identity, 'The actual Fresh identity differs from canonical source.')
    package.validate(files, 'fresh', version)
    check(set(UI_FILES) <= files.keys() and all(files[name] == (ROOT / name).read_bytes() for name in UI_FILES),
          'The actual Fresh archive does not contain the exact menu runtime sources under test.')
    check(not any(name.startswith(('tests/', 'scripts/', 'docs/')) for name in files),
          'The customer archive contains private menu fixtures or source tests.')
    frozen = work / 'verified-customer-fresh.zip'
    shutil.copyfile(archive, frozen)
    check(hashlib.sha256(frozen.read_bytes()).hexdigest() == actual, 'The archive changed while its immutable test copy was created.')
    return frozen, {'version': version, 'build_id': build, 'fresh_zip_sha256': actual,
                    'expected_fresh_zip_sha256': expected or actual, 'externally_pinned_fresh': bool(expected),
                    'archive_runtime_matches_sources': True}


def menu_url(base: str, location='header'):
    return base + '/admin/?page=menus&location=' + location


def menu_form(owner, base: str, location='header'):
    response = owner.get(menu_url(base, location), timeout=15)
    check(response.status_code == 200, 'The real owner Menus page did not load.')
    private(response)
    return fields(response.text, 'form[data-menu-editor]'), response


def private(response):
    cache = response.headers.get('Cache-Control', '').lower()
    check('private' in cache and 'no-store' in cache and response.headers.get('Pragma') == 'no-cache',
          'Owner menu responses lack private/no-store headers.')


def protected(before: dict, after: dict):
    check(before['protected'] == after['protected'] and before['unrelated_sha256'] == after['unrelated_sha256']
          and before['admin_security_sha256'] == after['admin_security_sha256']
          and before['outbox_count'] == after['outbox_count'],
          'A menu action changed unrelated records, configuration, uploads, backup or mail outbox.')


def unchanged(before: dict, after: dict):
    protected(before, after)
    check(before['menus'] == after['menus'] and before['records'] == after['records'] and before['audit'] == after['audit'],
          'A refused/read-only menu request changed a saved menu, revision or audit.')


def fresh_owner_login(owner, base: str, site: Path, php: str, baseline: dict):
    before = fixture(site, 'read', php)
    protected(baseline, before)
    owner_login(base, owner)
    after = fixture(site, 'read', php)
    check(before['admin_security_sha256'] != after['admin_security_sha256'],
          'An actual fresh owner sign-in did not change its durable security journal.')
    unchanged({**before, 'admin_security_sha256': after['admin_security_sha256']}, after)
    baseline['admin_security_sha256'] = after['admin_security_sha256']


def node(identity, label, url, target='self', enabled=True, children=None):
    return {'id': identity, 'label': label, 'url': url, 'target': target, 'enabled': enabled,
            'children': [] if children is None else children}


def save_menu(owner, base: str, location: str, items):
    values, _ = menu_form(owner, base, location)
    response = owner.post(menu_url(base, location), data=changed(values, menu_json=json.dumps(items)), timeout=20)
    check(response.status_code == 200 and response.history and response.history[0].status_code in (302, 303)
          and response.request.method == 'GET' and 'menu saved.' in response.text,
          'The real owner menu save failed to commit and redirect to GET.')
    private(response)
    return response


def reset_menu(owner, base: str, location: str):
    _, page = menu_form(owner, base, location)
    values = fields(page.text, 'form[data-menu-reset]')
    response = owner.post(menu_url(base, location), data=values, timeout=20)
    check(response.status_code == 200 and response.history and 'original defaults.' in response.text,
          'Restore default menu did not commit through the real owner action.')
    return response


def default_public(client, base: str):
    for destination, labels in (('', ['Home', 'Music', 'Photos', 'Videos', 'Membership', 'Store', 'Booking', 'About Me']),
                                ('about/', ['Home', 'Music', 'Photos', 'Videos', 'Membership', 'Store', 'Messages', 'Booking', 'About Me'])):
        response = client.get(base + '/' if not destination else route(base, destination), timeout=15)
        check(response.status_code == 200, 'An actual public page failed: ' + destination)
        soup = BeautifulSoup(response.text, 'html.parser')
        header = soup.select_one('#primaryNav')
        footer = soup.select_one('nav[aria-label="Footer navigation"]')
        check(header is not None and [item.get_text(strip=True) for item in header.select(':scope > a:not(.mobile-account-nav)')]
              == ['Home', 'Music', 'Photos', 'Videos', 'Membership', 'Store', 'Booking', 'About Me'],
              'An unconfigured Header changed its historical links or ordering.')
        check(footer is not None and [item.get_text(strip=True) for item in footer.select('a')] == labels,
              'An unconfigured Footer changed its context-specific links or ordering.')
        if not destination:
            check(footer.select_one('a')['href'] == '#top', 'The home default Footer lost its page-local Home fragment.')
        else:
            check(header.select_one('a[aria-current="page"]').get_text(strip=True) == 'About Me',
                  'The default active About page marker was lost.')


def custom_public(client, base: str, member=None):
    for destination in ('', 'about/'):
        response = client.get(base + '/' if not destination else route(base, destination), timeout=15)
        check(response.status_code == 200, 'A custom-menu public page failed.')
        soup = BeautifulSoup(response.text, 'html.parser')
        header = soup.select_one('#primaryNav [data-public-menu]')
        footer = soup.select_one('nav[aria-label="Footer navigation"] [data-public-menu]')
        check(header is not None and footer is not None, 'Saved custom menus did not appear on both public shells.')
        check([item['data-navigation-item'] for item in header.select(':scope > [data-navigation-item]')]
              == ['home-item', 'explore', 'about-current'], 'Saved top-level order or hidden-parent filtering changed.')
        check(header.select_one('[data-navigation-item="hidden"]') is None
              and header.select_one('[data-navigation-item="hidden-child"]') is None,
              'A hidden parent or its child reached public output.')
        label = header.select_one('[data-navigation-item="home-item"] .navigation-label')
        check(label.get_text() == 'Home <img src=x onerror=alert(1)> & "quoted"' and label.select_one('img') is None,
              'A saved label became markup or lost its literal text.')
        group = header.select_one('[data-navigation-item="explore"] > .navigation-menu-row')
        check(group.select_one('a') is None and group.select_one('[data-menu-toggle]') is not None,
              'A destination-free parent did not become a submenu disclosure.')
        tab = header.select_one('[data-navigation-item="photo-tab"] a')
        window = header.select_one('[data-navigation-item="popup"] a')
        check(tab.get('target') == '_blank' and {'noopener', 'noreferrer'} <= set(tab.get('rel', [])),
              'A New tab item lost its safe native target.')
        check(window.get('target') == '_blank' and window.has_attr('data-menu-window')
              and {'noopener', 'noreferrer'} <= set(window.get('rel', [])), 'A New window item lost its native fallback/window hook.')
        local = urlparse(tab['href'])
        check(local.path == urlparse(base).path + '/route.php' and parse_qs(local.query).get('vvx_route') == ['photos'],
              'The nested install prefix or physical route dispatcher was lost.')
        check(footer.select_one('[data-navigation-item="footer-top"] a')['href'] == '#top',
              'A fragment-only saved item ceased to be page-local.')
        check([item['data-navigation-item'] for item in footer.select(':scope > [data-navigation-item]')]
              == ['footer-group', 'footer-top'], 'The common/home custom Footer order differs.')
        if destination:
            check(header.select_one('[data-navigation-item="about-current"] a').get('aria-current') == 'page',
                  'The custom active page marker was not resolved through route.php.')
        check(soup.select_one('.header-cart') is not None and soup.select_one('.header-login').get_text(strip=True) == 'Login'
              and soup.select_one('.legal-links') is not None, 'Custom menus removed fixed cart/account/legal controls.')
        credit = soup.select_one('.site-credit a')
        check(credit is not None and credit['href'] == 'https://cajunwebpro.com/' and credit.get('target') == '_blank',
              'Custom Footer edits changed the Cajun Web Pro new-tab credit.')
    if member is not None:
        response = member.get(route(base, 'about/'), timeout=15)
        soup = BeautifulSoup(response.text, 'html.parser')
        check(response.status_code == 200 and soup.select_one('.header-login').get_text(strip=True) == 'My Account'
              and any(link.get_text(strip=True) == 'Sign Out' for link in soup.select('.header-actions a')),
              'Menu customization changed signed-in member controls.')


def owner_revoked_during_lock(owner, base: str, site: Path, php: str, baseline: dict):
    results = []
    for action, status in (('menu_save', 'demoted'), ('menu_reset', 'inactive')):
        values, page = menu_form(owner, base)
        if action == 'menu_reset':
            values = fields(page.text, 'form[data-menu-reset]')
        before = fixture(site, 'read', php)
        token = secrets.token_hex(8)
        release = site / ('storage/temp/menu-owner-' + token + '.release')
        worker = subprocess.Popen([php, str(FIXTURE), str(site), 'owner-lock'], stdin=subprocess.PIPE,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd='/tmp')
        try:
            worker.stdin.write(json.dumps({'token': token, 'status': status}))
            worker.stdin.close()
            check(bool(select.select([worker.stdout], [], [], 10)[0]), 'The owned SQL owner-row lock did not become ready.')
            ready = worker.stdout.readline()
            check(bool(ready) and json.loads(ready).get('locked') is True, 'The fixture did not acquire its real owner-row lock.')
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                pending = pool.submit(owner.post, menu_url(base), data=values, timeout=30)
                try:
                    deadline = time.monotonic() + 10
                    waiting = []
                    while not pending.done() and time.monotonic() < deadline:
                        waiting = fixture(site, 'owner-wait', php)['waiting']
                        if waiting:
                            break
                        time.sleep(.02)
                    check(len(waiting) == 1 and not pending.done(), 'The actual menu write did not reach the blocked owner SELECT FOR UPDATE.')
                finally:
                    release.write_text('Release the exclusively owned menu owner lock.\n')
                response = pending.result(timeout=30)
                check(response.status_code == 403, 'A revoked owner completed a menu write after the SQL lock wait.')
                private(response)
                if status == 'inactive':
                    observed = owner.get(menu_url(base), allow_redirects=False, timeout=15)
                    check(observed.status_code == 302 and observed.headers.get('Location') == base + '/admin/?page=login',
                          'The current identity check did not revoke the inactive owner session.')
        finally:
            if not release.exists():
                release.write_text('Release the exclusively owned menu lock after failure.\n')
            try:
                worker.wait(timeout=8)
            except subprocess.TimeoutExpired:
                worker.terminate()
                worker.wait(timeout=5)
            stderr = worker.stderr.read()
            worker.stdout.close()
            worker.stderr.close()
            _base.fixture(site, 'owner-status', php, status='owner')
            release.unlink(missing_ok=True)
            check(worker.returncode == 0, 'The owned owner-lock fixture failed: ' + stderr[:1000])
        unchanged(before, fixture(site, 'read', php))
        if status == 'inactive':
            revoked = owner.get(menu_url(base), allow_redirects=False, timeout=15)
            check(revoked.status_code == 302 and revoked.headers.get('Location') == base + '/admin/?page=login',
                  'Restoring the locked owner account revived its revoked session.')
            fresh_owner_login(owner, base, site, php, baseline)
        results.append({'action': action, 'revocation': status, 'observed_select_for_update_wait': True,
                        'http_status': 403, 'exact_state_preserved': True})
    return results


def http_case(site: Path, base: str, php: str, capture):
    owner, guest, member = owner_login(base), session(), member_login(base, 'free')
    initial = fixture(site, 'read', php)
    try:
        check(initial['records'] == {} and all(not value['configured'] and value['revision'] == 0 for value in initial['menus'].values()),
              'Actual Fresh fixture did not start with absent custom menu settings.')
        for location in ('header', 'footer'):
            values, page = menu_form(owner, base, location)
            soup = BeautifulSoup(page.text, 'html.parser')
            check(soup.select_one('form[data-menu-editor] [data-menu-save]').has_attr('disabled')
                  and soup.select_one('form[data-menu-editor] [data-menu-add-root]').has_attr('disabled'),
                  'The owner form can overwrite JSON before the client editor initializes.')
            check(dict(values)['revision'] == '0' and dict(values)['location'] == location
                  and len(json.loads(dict(values)['menu_json'])) == (8 if location == 'header' else 9),
                  'The owner form did not receive the exact initial location/default/revision.')
            check(soup.select_one('a[data-menu-location-link]') is not None and 'menu-admin.js?v=' in page.text
                  and 'menu-admin.css?v=' in page.text, 'The real Menus page lacks its editor assets/location controls.')
        default_public(guest, base)
        unchanged(initial, fixture(site, 'read', php))

        values, _ = menu_form(owner, base)
        for client in (guest, member):
            denied = client.get(menu_url(base), allow_redirects=False, timeout=15)
            check(denied.status_code in (302, 303) and 'page=login' in denied.headers.get('Location', ''),
                  'A guest/member could open the owner menu editor.')
            denied = client.post(menu_url(base), data=values, allow_redirects=False, timeout=15)
            check(denied.status_code in (302, 303, 403), 'A guest/member could submit an owner menu form.')
        bad_requests = [
            ('post', menu_url(base), changed(values, _csrf='invalid'), 419),
            ('post', base + '/admin/?page=dashboard', values, 400),
            ('post', menu_url(base), changed(values, location='sidebar'), 400),
            ('post', menu_url(base), changed(values, action='save_scene'), 422),
            ('put', menu_url(base), None, 405),
            ('delete', menu_url(base), None, 405),
        ]
        for method, url, payload, status in bad_requests:
            response = owner.request(method, url, data=payload, allow_redirects=False, timeout=15)
            check(response.status_code == status, 'Menu method/page/location/action/CSRF defense failed: ' + method + ' ' + str(status))
            private(response)
            if status == 419:
                retained = dict(fields(response.text, 'form[data-menu-editor]'))
                check(retained['menu_json'] == dict(values)['menu_json'] and retained['revision'] == dict(values)['revision'],
                      'An expired form token discarded the owner\'s attempted menu.')
                check(BeautifulSoup(response.text, 'html.parser').select_one('form[data-menu-attempted="true"]') is not None,
                      'An expired form token did not mark its retained draft as unsaved.')
        unchanged(initial, fixture(site, 'read', php))

        header_items = [node('home-item', 'Home <img src=x onerror=alert(1)> & "quoted"', '/'),
                        node('explore', 'Explore', '', children=[
                            node('media-group', 'Media', 'music/', children=[node('photo-tab', 'Photos', 'photos/', 'tab')]),
                            node('popup', 'New window', 'https://example.test/window', 'window')]),
                        node('hidden', 'Hidden parent', 'videos/', enabled=False, children=[node('hidden-child', 'Hidden child', 'booking/')]),
                        node('about-current', 'About current', 'about/')]
        footer_items = [node('footer-group', 'Footer group', '', children=[node('footer-about', 'About', 'about/', 'tab')]),
                        node('footer-top', 'Back to top', '#top')]
        save_menu(owner, base, 'header', header_items)
        save_menu(owner, base, 'footer', footer_items)
        saved = fixture(site, 'read', php)
        check(saved['menus']['header'] == {'revision': 1, 'configured': True, 'items': header_items}
              and saved['menus']['footer'] == {'revision': 1, 'configured': True, 'items': footer_items},
              'The committed location records differ from the real submitted forms.')
        check(saved['records']['navigation_menu_header'] == {'schema': 1, **saved['menus']['header']}
              and saved['records']['navigation_menu_footer'] == {'schema': 1, **saved['menus']['footer']},
              'Independent storage readback disagrees with Store menu snapshots.')
        protected(initial, saved)
        custom_public(guest, base, member)
        values, _ = menu_form(owner, base)
        invalid = [('{broken', False), ('{}', False), (json.dumps([node('unsafe', 'Unsafe', 'javascript:alert(1)')]), True),
                   (json.dumps([node('dupe', 'One', '/'), node('dupe', 'Two', '/')]), False),
                   (json.dumps([node('missing', 'No destination', '')]), True)]
        for raw, editable in invalid:
            before = fixture(site, 'read', php)
            response = owner.post(menu_url(base), data=changed(values, menu_json=raw), timeout=15, allow_redirects=False)
            check(response.status_code == 422 and not response.history, 'A malformed/unsafe menu submission was accepted.')
            soup = BeautifulSoup(response.text, 'html.parser')
            retained = fields(response.text, 'form[data-menu-editor]')
            check(dict(retained)['menu_json'] == raw and dict(retained)['revision'] == '1'
                  and soup.select_one('.admin-alert.error') is not None and soup.select_one('form[data-menu-attempted="true"]') is not None,
                  'A rejected edit lost its attempted JSON, original revision or unsaved-draft marker.')
            if editable:
                check(not soup.select_one('form[data-menu-editor]').has_attr('data-menu-load-error'),
                      'Business validation unnecessarily disabled correction of the attempted menu.')
            unchanged(before, fixture(site, 'read', php))
        for bad_revision in ('-1', '01', '9007199254740992'):
            response = owner.post(menu_url(base), data=changed(values, revision=bad_revision), timeout=15, allow_redirects=False)
            check(response.status_code == 422, 'An invalid revision reached menu persistence.')
            unchanged(saved, fixture(site, 'read', php))

        # Keep an old open form while another owner save advances only Header.
        stale = values
        header_items[0]['label'] = 'Updated Home'
        save_menu(owner, base, 'header', header_items)
        current = fixture(site, 'read', php)
        check(current['menus']['header']['revision'] == 2 and current['menus']['footer']['revision'] == 1,
              'Location revisions are not independent.')
        response = owner.post(menu_url(base), data=changed(stale, menu_json='[]'), timeout=15, allow_redirects=False)
        retained = dict(fields(response.text, 'form[data-menu-editor]'))
        check(response.status_code == 409 and retained['menu_json'] == '[]' and retained['revision'] == '1'
              and 'changed after you opened it' in response.text
              and BeautifulSoup(response.text, 'html.parser').select_one('form[data-menu-attempted="true"]') is not None,
              'A stale form overwrote or silently adopted the newer revision or lost its unsaved-draft marker.')
        response = owner.post(menu_url(base), data=changed(stale, action='menu_reset', menu_json=None), timeout=15, allow_redirects=False)
        check(response.status_code == 409, 'A stale default reset overwrote a newer menu.')
        unchanged(current, fixture(site, 'read', php))

        for status in ('demoted', 'inactive'):
            _base.fixture(site, 'owner-status', php, status=status)
            blocked = fixture(site, 'read', php)
            try:
                denied = owner.get(menu_url(base), allow_redirects=False, timeout=15)
                check(denied.status_code == (403 if status == 'demoted' else 302)
                      and (status == 'demoted' or denied.headers.get('Location') == base + '/admin/?page=login'),
                      'A stale cached owner role could reopen Menus after revocation.')
                for action in ('menu_save', 'menu_reset'):
                    response = owner.post(menu_url(base), data=changed(stale, action=action, revision='2'), allow_redirects=False, timeout=15)
                    check(response.status_code == (403 if status == 'demoted' else 302)
                          and (status == 'demoted' or response.headers.get('Location') == base + '/admin/?page=login'),
                          'A revoked owner could save/reset a menu.')
                unchanged(blocked, fixture(site, 'read', php))
            finally:
                _base.fixture(site, 'owner-status', php, status='owner')
        revoked = owner.get(menu_url(base), allow_redirects=False, timeout=15)
        check(revoked.status_code == 302 and revoked.headers.get('Location') == base + '/admin/?page=login',
              'Restoring the inactive account revived its old menu session.')
        fresh_owner_login(owner, base, site, php, initial)
        lock_cases = owner_revoked_during_lock(owner, base, site, php, initial) if initial['mode'] == 'mysql' else []
        save_menu(owner, base, 'header', [])
        save_menu(owner, base, 'footer', [])
        cleared = fixture(site, 'read', php)
        check(all(value['configured'] and value['items'] == [] for value in cleared['menus'].values()),
              'An intentionally empty saved menu fell back to default links.')
        response = guest.get(route(base, 'about/'), timeout=15)
        soup = BeautifulSoup(response.text, 'html.parser')
        check(not soup.select('#primaryNav [data-navigation-item]') and not soup.select('nav[aria-label="Footer navigation"] [data-navigation-item]')
              and soup.select_one('.header-cart') is not None and soup.select_one('.site-credit a') is not None,
              'Clearing editable menus removed fixed controls or emitted default menu items.')
        reset_menu(owner, base, 'header')
        reset_menu(owner, base, 'footer')
        final = fixture(site, 'read', php)
        check(final['menus']['header']['revision'] == 4 and final['menus']['footer']['revision'] == 3
              and all(not value['configured'] for value in final['menus'].values()), 'Reset did not preserve increasing revision tombstones.')
        default_public(guest, base)
        protected(initial, final)
        check(capture.connections == 0 and not capture.payloads and not capture.errors, 'Menu editing attempted SMTP delivery.')
        return {'mode': initial['mode'], 'mysql_version': initial['mysql_version'], 'http_forms_and_redirects': True,
                'default_contexts_and_no_read_writes': True, 'nested_order_visibility_and_targets': True,
                'safe_labels_urls_and_nested_physical_routes': True, 'csrf_methods_page_forgery_and_owner_revocation': True,
                'attempted_json_and_original_stale_revision_retained': True, 'empty_menus_and_reset_tombstones': True,
                'independent_committed_record_readback': True, 'mysql_owner_lock_cases': lock_cases,
                'menu_actions_preserve_security_journal': True, 'revoked_sessions_require_real_signin': True,
                'config_upload_backup_unrelated_and_outbox_preserved': True, 'smtp_connections': capture.connections}
    finally:
        owner.close()
        guest.close()
        member.close()


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='mysql')
    options = parser.parse_args()
    evidence = evidence_directory()
    summary = {'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without router script', 'playwright_required': False, 'cases': {}}
    modes = ('file', 'mysql') if options.mode == 'both' else (options.mode,)
    with tempfile.TemporaryDirectory(prefix='vvx-menus-http-') as temporary:
        work = Path(temporary)
        archive, identity = prepare_package(work)
        summary.update(identity)
        for mode in modes:
            for path_label, prefix in (('root', ''), ('nested', '/nested/velvet')):
                label = mode + '-' + path_label
                with runtime(work, archive, mode, label, prefix, evidence) as case:
                    summary['cases'][label] = http_case(case['site'], case['base'], case['php'], case['capture'])
                print('Navigation Menus HTTP ' + label + ': PASS.', flush=True)
    summary['assertions'] = checks + _base.checks
    if evidence:
        (evidence / 'menus-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2), flush=True)
    print('Navigation menu actual-package HTTP acceptance passed.', flush=True)


if __name__ == '__main__':
    run()
