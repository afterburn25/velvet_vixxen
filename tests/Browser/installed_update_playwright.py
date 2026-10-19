#!/usr/bin/env python3
"""Upgrade an actual historical installation without restarting PHP or its browser.

The rejected Build .5 ZIP is rebuilt from pinned Git history and must match the
exact delivered bytes. The real .6 Upgrade must restore its package allowlist,
but extraction alone leaves all 25old root folders. The owner's fresh repair entry
must bypass retained .5 PHP, activate the new runtime, safely remove only the 46
known forwarding files, and execute actual actions through the central dispatcher.
These are disposable file-store installations, not evidence of a customer host
or the separate MySQL upgrade/installer gates. No routes or responses are mocked.
"""
from __future__ import annotations

from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tarfile
import tempfile
import time
from zipfile import ZipFile
from urllib.parse import parse_qs, quote, urlparse

import requests
from playwright.sync_api import expect, sync_playwright

sys.dont_write_bytecode = True
import packaged_media_upload_playwright as helpers

ROOT = helpers.ROOT
EVIDENCE = helpers.EVIDENCE
require = helpers.require
OLD_COMMIT = '2bc178013c9c528f45a5d0447624f3cbaf4395f2'
OLD_FRESH_SHA256 = 'dcd89db762e58541160ffdcdf4901d0bb5f07c9f3c0d66b99a6480701006bfc9'
OLD_ASSET_VERSION = '0.8.1-20261019.5'
OLD_BUILD = '20261019.5'
APPROVED_ROOTS = {'addons', 'admin', 'app', 'config', 'database', 'images', 'includes', 'install',
                  'public', 'storage', 'templates', 'uploads', 'vendor'}
RETIRED_ROOTS = {'about', 'account', 'api', 'blog', 'booking', 'campaign', 'cart', 'checkout',
                 'collections', 'communication', 'create-account', 'extension', 'join', 'links',
                 'login', 'membership', 'messages', 'music', 'newsletter', 'photos', 'receipt',
                 'recover', 'requests', 'shop', 'videos'}
VIEWPORTS = {'desktop': {'width': 1440, 'height': 1000}, 'mobile': {'width': 390, 'height': 844}}
PRESERVED = ['config/app.php', 'storage/install.lock', 'storage/data.json',
             'storage/private/update-fixture.txt', 'uploads/media/update-existing.png']

# Read-only instrumentation in the disposable extracted site only. It requires
# an actual admin session, never enters either ZIP, and reveals no session data.
# The separate cache sentinel is not re-included on the post-refresh probe, so a
# global opcache_reset would fail the other-site preservation assertion.
PROBE = """<?php
declare(strict_types=1);
define('ROOT_PATH', __DIR__);
require ROOT_PATH . '/app/bootstrap.php';
require_admin(true);
$other = (string) getenv('VVX_UPDATE_TEST_NEIGHBOR');
if (isset($_GET['warm_other'])) require $other;
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
$cached = [];
foreach (['admin/index.php', 'app/Views/admin/composer.php', 'includes/version.php', 'admin/update.php', 'app/Core/RuntimeBuildService.php', 'app/Views/admin/update-status.php'] as $file) {
    $cached[$file] = function_exists('opcache_is_script_cached') && opcache_is_script_cached(ROOT_PATH . '/' . $file);
}
json_response(['ok' => true, 'pid' => getmypid(), 'php' => PHP_VERSION,
    'enabled' => is_array($status) && !empty($status['opcache_enabled']),
    'cli_enabled_flag' => (bool) ini_get('opcache.enable_cli'),
    'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
    'cached' => $cached,
    'neighbor_cached' => function_exists('opcache_is_script_cached') && opcache_is_script_cached($other),
    'asset_identity' => VELVET_VIXXEN_ASSET_VERSION]);
"""


def is_route(url: str, expected: str) -> bool:
    parsed = urlparse(url)
    return parsed.path.endswith('/route.php') and parse_qs(parsed.query).get('vvx_route') == [expected]


def repair_layout(context, page, site: Path, base: str, label: str, preserved: dict) -> dict:
    endpoint = base + '/admin/repair.php'
    # A custom sibling must remain in place even through the stale legacy action.
    custom = site / 'booking/custom-owner-file.txt'
    custom_bytes = b'Synthetic owner-added sibling; preserve its path and bytes.\n'
    custom.write_bytes(custom_bytes)
    csrf = helpers.csrf_from_html(context.request.get(endpoint).text())
    legacy = context.request.post(base + '/admin/index.php?page=repair-backups',
                                  form={'_csrf': csrf, 'action': 'archive_legacy_root_entries'}, max_redirects=0)
    require(legacy.status in (302, 303) and legacy.headers.get('location', '').endswith('/admin/repair.php'),
            'The stale broad archive action was not retired to the safe repair page.')
    require(custom.read_bytes() == custom_bytes, 'The legacy action moved an unknown owner sibling.')
    # Remove only this test-created sentinel before the all 25folder case. Separate
    # real cleanup below proves custom sibling preservation rather than merely bypassing it.
    page.reload(wait_until='networkidle')
    expect(page.locator('[data-root-layout]')).to_have_attribute('data-root-layout', 'repair-available')
    for action in ['repair_root_layout']:
        denied = context.request.post(endpoint, form={'_csrf': 'wrong-root-repair-token', 'action': action})
        require(denied.status == 419, 'Folder repair accepted an invalid CSRF token.')
        unchanged(site, preserved, 'Denied root-folder repair')
    before_files = {str(path.relative_to(site)): path.read_bytes() for root in RETIRED_ROOTS
                    for path in (site / root).glob('*.php')}
    before_files['media.php'] = (site / 'media.php').read_bytes()
    require(len(before_files) == 46, 'Actual rejected package does not have 46 retired forwarders.')
    with page.expect_navigation(wait_until='networkidle') as cleaned:
        page.get_by_role('button', name='Restore organized folders', exact=True).click()
    require(cleaned.value is not None and cleaned.value.status == 200, 'The real root-folder repair form failed.')
    expect(page.locator('[data-layout-result]')).to_have_attribute('data-retired-files', '46')
    expect(page.locator('[data-layout-result]')).to_have_attribute('data-removed-directories', '24')
    require(custom.read_bytes() == custom_bytes and (site / 'booking').is_dir(),
            'The repair removed or moved a custom sibling instead of preserving it.')
    require(all(not (site / name).exists() for name in before_files), 'Known forwarding PHP remains after owner repair.')
    require({path.name for path in site.iterdir() if path.is_dir() and path.name in RETIRED_ROOTS} == {'booking'},
            'The repair retained an obsolete empty route directory.')
    records = list((site / 'storage/private/root-layout').glob('retired-*/forwarders.json'))
    require(len(records) == 1, 'Exactly one private reversible archive record was not produced.')
    import base64
    record = json.loads(records[0].read_text())
    require(set(record['entries']) == set(before_files), 'Archive does not contain every retired public forwarding file.')
    for name, content in before_files.items():
        entry = record['entries'][name]
        require(base64.b64decode(entry['contents'], validate=True) == content
                and entry['sha256'] == hashlib.sha256(content).hexdigest(), 'Archived forwarding bytes do not match.')
    require(not list((site / 'storage/private/root-layout').rglob('*.php')), 'Folder repair left executable PHP backups.')
    unchanged(site, preserved, 'Safe known-file root repair')
    custom.unlink()  # The test owner removes its own sentinel; application code must never do so.
    page.reload(wait_until='networkidle')
    with page.expect_navigation(wait_until='networkidle'):
        page.get_by_role('button', name='Restore organized folders', exact=True).click()
    expect(page.locator('[data-layout-result]')).to_have_attribute('data-retired-files', '0')
    expect(page.locator('[data-layout-result]')).to_have_attribute('data-removed-directories', '1')
    expect(page.locator('[data-root-layout]')).to_have_attribute('data-root-layout', 'organized')
    require(not any((site / name).exists() for name in RETIRED_ROOTS) and not (site / 'media.php').exists(),
            'The approved root layout was not restored after removing the test-owner sentinel.')
    # The original generated runtime inventory must still match after cleanup.
    inventory = json.loads((site / 'includes/runtime-build.json').read_text())
    require(all(sha256(site / name) == digest for name, digest in inventory['files'].items()),
            'Root cleanup removed or modified active organized runtime files.')
    unchanged(site, preserved, 'Complete organized-root repair')
    if EVIDENCE:
        page.screenshot(path=str(EVIDENCE / f'installed-update-{label}-organized-root.png'), full_page=True)
    return {'known_files_retired': 46, 'old_root_directories_removed': 25,
            'unknown_custom_sibling_preserved': True, 'obsolete_broad_action_retired': True,
            'exact_reversible_nonexecuting_archive': True, 'active_runtime_unchanged': True}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build_packages(source: Path, destination: Path) -> dict:
    result = subprocess.run([sys.executable, str(source / 'scripts/package.py'), '--output', str(destination)],
                            check=True, capture_output=True, text=True, cwd=source)
    return json.loads(result.stdout)


def historical_package(work: Path) -> Path:
    available = subprocess.run(['git', 'cat-file', '-e', OLD_COMMIT + '^{commit}'], cwd=ROOT, capture_output=True)
    require(available.returncode == 0,
            'Pinned historical runtime is missing. Fetch complete Git history; this regression cannot skip its old build.')
    source = work / 'historical-source'
    source.mkdir()
    archive = work / 'historical-source.tar'
    subprocess.run(['git', 'archive', '--format=tar', '--output=' + str(archive), OLD_COMMIT], cwd=ROOT, check=True)
    with tarfile.open(archive) as tar:
        # Only this pinned repository's regular files/directories may be read.
        for member in tar.getmembers():
            require((member.isfile() or member.isdir()) and not member.name.startswith('/')
                    and '..' not in Path(member.name).parts, 'Historical source archive contains an unsafe entry.')
        tar.extractall(source, filter='data')
    manifest = build_packages(source, work / 'historical-packages')
    fresh = next(package for package in manifest['packages'] if package['kind'] == 'fresh')
    zipped = work / 'historical-packages' / fresh['filename']
    require(sha256(zipped) == OLD_FRESH_SHA256 == fresh['sha256'],
            'Rebuilt historical fresh ZIP differs from the exact previously delivered package.')
    with ZipFile(zipped) as package:
        require(b'data-action="add-button"' in package.read('app/Views/admin/composer.php'),
                'Rejected Build .5 must contain its actual existing Composer implementation.')
        roots = {name.split('/')[0] for name in package.namelist() if '/' in name}
        require(roots == APPROVED_ROOTS | RETIRED_ROOTS and 'media.php' in package.namelist(),
                'Historical ZIP does not reproduce the actual25extra-root-folder regression.')
        require(OLD_ASSET_VERSION.encode() in package.read('includes/version.php'),
                'Historical negative control has the wrong asset identity.')
    return zipped


def snapshot(site: Path) -> dict[str, bytes]:
    return {relative: (site / relative).read_bytes() for relative in PRESERVED}


def unchanged(site: Path, expected: dict[str, bytes], label: str) -> None:
    require(all((site / relative).read_bytes() == value for relative, value in expected.items()),
            label + ' changed configuration, installation identity, records, or existing uploads/private files.')


def login_http(base: str, email: str):
    session = helpers.requests_session()
    login = session.get(base + '/admin/?page=login', timeout=10)
    result = session.post(base + '/admin/?page=login', data={
        '_csrf': helpers.csrf_from_html(login.text), 'email': email, 'password': 'VelvetDemo12',
    }, timeout=10)
    require(result.status_code == 200 and 'page=login' not in result.url, 'Real fixture admin login failed.')
    return session, helpers.csrf_from_html(result.text)


@contextmanager
def installed_server(work: Path, old: Path, label: str, nested: bool, cache: bool, php: str):
    document_root = work / label / 'www'
    site = document_root / 'nested/velvet' if nested else document_root
    site.mkdir(parents=True)
    with ZipFile(old) as package:
        package.extractall(site)
    (site / '.vvx-test-runtime').write_text('Disposable pinned historical update acceptance.\n')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}' + ('/nested/velvet' if nested else '')
    subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True)
    data = helpers.state(site)
    data['admins']['update-former-owner'] = {
        **data['admins']['upload-fixture-owner'], 'id': 'update-former-owner',
        'name': 'Synthetic former owner', 'email': 'former-owner@example.test',
    }
    (site / 'storage/data.json').write_text(json.dumps(data))
    (site / 'storage/private/update-fixture.txt').write_text('Synthetic private preservation sentinel.\n')
    (site / 'uploads/media').mkdir(exist_ok=True)
    (site / 'uploads/media/update-existing.png').write_bytes(helpers.PHOTO)
    (site / 'opcache-update-probe.php').write_text(PROBE)
    neighbor = work / label / 'unrelated-runtime.php'
    neighbor.write_text("<?php return 'unrelated cache preservation sentinel';\n")
    log_path = work / label / 'http.log'
    with log_path.open('w') as log:
        # cli-server can cache PHP while enable_cli=0. The nested active-cache
        # case proves the refresh action must use the actual SAPI semantics;
        # only opcache.enable=0 makes the disabled case a valid control.
        process = subprocess.Popen([php, '-d', f'opcache.enable={int(cache)}',
                                    '-d', f'opcache.enable_cli={int(cache and not nested)}',
                                    '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0',
                                    '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                   cwd=work, stdout=log, stderr=log,
                                   env={**os.environ, 'VVX_UPDATE_TEST_NEIGHBOR': str(neighbor)})
        try:
            with helpers.requests_session() as probe:
                deadline = time.monotonic() + 10
                while True:
                    require(process.poll() is None, 'Actual PHP package server exited during startup.')
                    try:
                        if probe.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                            break
                    except requests.RequestException:
                        pass
                    require(time.monotonic() < deadline, 'Actual PHP package server did not start.')
                    time.sleep(.05)
            yield site, base, process
        finally:
            process.terminate()
            process.wait(timeout=10)
            if EVIDENCE:
                shutil.copyfile(log_path, EVIDENCE / f'installed-update-{label}-http.log')


def cache_probe(context, base: str, warm: bool = False) -> dict:
    return helpers.response_json(context.request.get(base + '/opcache-update-probe.php'
                                 + ('?warm_other=1' if warm else '')), 200, 'Owner cache probe')


def status_geometry(page, label: str) -> dict:
    results = {}
    for device, viewport in VIEWPORTS.items():
        page.set_viewport_size(viewport)
        expect(page.get_by_role('heading', name='Update Status', exact=True)).to_be_visible()
        expect(page.get_by_role('button', name='Refresh application PHP')).to_be_visible()
        geometry = page.evaluate('''() => {
            const box = document.querySelector('.update-shell').getBoundingClientRect();
            const cells = [...document.querySelectorAll('.update-summary > div')].map(element => {
                const bounds = element.getBoundingClientRect(); return {x: bounds.x, y: bounds.y};
            });
            return {left: box.left, right: box.right, width: innerWidth,
                bodyDisplay: getComputedStyle(document.body).display,
                scrollWidth: document.documentElement.scrollWidth, cells};
        }''')
        require(geometry['bodyDisplay'] == 'block' and geometry['left'] >= 0
                and geometry['right'] <= viewport['width'] + 1
                and geometry['scrollWidth'] <= viewport['width'] + 1,
                f'{label}/{device}: Update Status inherited the admin sidebar grid or overflows the viewport.')
        require(abs(geometry['left'] - (viewport['width'] - geometry['right'])) < 2,
                f'{label}/{device}: Update Status is not centered.')
        cells = geometry['cells']
        require((cells[1]['y'] > cells[0]['y'] and abs(cells[1]['x'] - cells[0]['x']) < 2) if device == 'mobile'
                else (abs(cells[1]['y'] - cells[0]['y']) < 2 and cells[1]['x'] > cells[0]['x']),
                f'{label}/{device}: Build summary columns do not adapt to the viewport.')
        if EVIDENCE:
            page.screenshot(path=str(EVIDENCE / f'installed-update-{label}-status-{device}.png'), full_page=True)
        results[device] = {'viewport': viewport, 'centered': True, 'no_horizontal_overflow': True,
                           'summary_columns': 1 if device == 'mobile' else 2}
    return results


def denied_requests(site: Path, base: str, context, restricted, restricted_csrf: str, member) -> dict:
    endpoint = base + '/admin/repair.php'
    with helpers.requests_session() as guest:
        for client, description in [(guest, 'Anonymous'), (member, 'Member-only')]:
            for method in ['get', 'post']:
                result = getattr(client, method)(endpoint, timeout=15, allow_redirects=False)
                require(result.status_code == 302 and 'page=login' in result.headers.get('location', ''),
                        description + ' reached owner update verification/refresh.')
    # This session was genuinely logged in while its role was owner, then the
    # stored account was demoted. Trusting only its cached session role fails.
    for method in ['get', 'post']:
        result = getattr(restricted, method)(endpoint, data={'action': 'refresh_php_cache', '_csrf': restricted_csrf},
                                            timeout=15, allow_redirects=False)
        require(result.status_code == 403, 'A stale owner session bypassed the current active-owner check.')
    require(context.request.put(endpoint).status == 405, 'Owner PUT was accepted by the update action.')
    csrf = helpers.csrf_from_html(context.request.get(endpoint).text())
    for token in [None, 'wrong-update-fixture-token']:
        fields = {'action': 'refresh_php_cache'}
        if token is not None:
            fields['_csrf'] = token
        require(context.request.post(endpoint, form=fields).status == 419, 'Refresh accepted missing or invalid CSRF.')
    require(context.request.post(endpoint, form={'_csrf': csrf, 'action': 'unexpected'}).status == 400,
            'Refresh accepted an unknown action.')
    return {'anonymous_and_member_get_post_denied': True, 'demoted_owner_session_get_post_403': True,
            'forbidden_method_405': True, 'missing_and_wrong_csrf_419': True, 'unknown_action_400': True}


def mixed_file_refused(site: Path, base: str, context, preserved: dict, expected_identity: str) -> None:
    endpoint = base + '/admin/repair.php'
    csrf = helpers.csrf_from_html(context.request.get(endpoint).text())
    asset = site / 'public/assets/js/composer.js'
    content = asset.read_bytes()
    asset.write_bytes(content + b'\n// Synthetic partial update diagnostic.\n')
    mixed = snapshot(site)
    try:
        result = context.request.get(endpoint)
        require(result.status == 200 and 'data-update-status="incomplete"' in result.text()
                and '<code>public/assets/js/composer.js</code>' in result.text()
                and 'Different file contents' in result.text(), 'Mixed runtime did not identify the precise incomplete file.')
        require('type="submit">Refresh application PHP' not in result.text(), 'Incomplete files exposed an activation control.')
        blocked = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
        require(blocked.status == 200 and 'The update is incomplete.' in blocked.text(),
                'An owner could activate an incomplete runtime by posting directly.')
        require(cache_probe(context, base)['asset_identity'] == expected_identity, 'Rejected partial update changed the running PHP build.')
        unchanged(site, mixed, 'Incomplete update inspection/refusal')
        require(asset.read_bytes() == content + b'\n// Synthetic partial update diagnostic.\n',
                'Update verification silently rewrote a mismatched runtime file.')
    finally:
        asset.write_bytes(content)
    unchanged(site, preserved, 'Rejected update operations')


def after_refresh_actions(context, page, site: Path, base: str, label: str, device: str) -> dict:
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    # Follow the actual dedicated page's relative link in the retained browser.
    page.get_by_role('link', name='Open Visual Composer', exact=True).click()
    page.wait_for_url(base + '/admin/index.php?page=composer')
    expect(page.locator('[data-action="add-button"]')).to_have_count(1)
    require(page.evaluate('typeof window.VELVET_API') == 'object', 'Refreshed admin did not load the API helper.')
    page.locator(f'[data-device="{device}"]').click()
    page.locator('[data-action="add-button"]').click()
    title = 'Updated booking ' + label
    for name, value in [('buttonLabel', title), ('buttonUrl', '/booking/?source=installed-update'), ('layerY', '25')]:
        control = page.locator(f'[data-control="{name}"]')
        control.fill(value)
        control.press('Tab')
    layer_id = page.locator('.composer-layer.selected').get_attribute('data-layer-id')
    require(bool(layer_id), 'Add Button did not create and select an actual layer.')
    published = helpers.state(site)['scenes']['hero']['published']
    with page.expect_popup() as opened:
        page.get_by_role('button', name='Full Page Preview', exact=True).click()
    preview = opened.value
    preview.wait_for_url(base + '/?preview=1')
    preview.wait_for_load_state('networkidle')
    require(not preview.is_closed(), 'Full Page Preview still closes after the warm-cache update.')
    if preview.locator('#ageGate:not([hidden])').count():
        preview.locator('#ageEnter').click()
    expect(preview.get_by_role('link', name=title, exact=True)).to_be_visible()
    require(helpers.state(site)['scenes']['hero']['published'] == published, 'Preview published a draft unexpectedly.')
    if EVIDENCE:
        preview.screenshot(path=str(EVIDENCE / f'installed-update-{label}-preview.png'), full_page=True)
    preview.close()
    with page.expect_response(lambda response: is_route(response.url, 'api/scene.php') and response.request.method == 'POST') as captured:
        page.get_by_role('button', name='Publish', exact=True).click()
    result = helpers.response_json(captured.value, 200, 'Updated Composer Publish')
    require(result['ok'] and captured.value.request.post_data_json['action'] == 'publish', 'Updated Publish did not reach the real handler.')
    expect(page.locator('[data-status]')).to_contain_text('published')
    require(any(layer['id'] == layer_id for layer in helpers.state(site)['scenes']['hero']['published']['devices'][device]['layers']),
            'Publish did not persist the new button in the real published scene.')
    public = context.new_page()
    public.goto(base + '/', wait_until='networkidle')
    if public.locator('#ageGate:not([hidden])').count():
        public.locator('#ageEnter').click()
    button = public.get_by_role('link', name=title, exact=True)
    expect(button).to_be_visible()
    with public.expect_navigation(wait_until='networkidle') as destination:
        button.click()
    require(destination.value is not None and destination.value.status == 200
            and is_route(public.url, 'booking') and parse_qs(urlparse(public.url).query).get('source') == ['installed-update'], 'Published button failed actual installation-relative Booking navigation.')
    public.close()

    # Both upload forms use the real same-process endpoint and return decodable,
    # byte-identical assets; merely obtaining HTTP 200 is insufficient.
    for surface in ['library', 'composer']:
        page.goto(base + '/admin/?page=' + ('media' if surface == 'library' else 'composer'), wait_until='networkidle')
        if surface == 'library':
            page.get_by_role('button', name='Upload Images', exact=True).click()
            form = page.locator('#libraryUpload')
            submit_name = 'Upload Image'
        else:
            page.locator('[data-action="toggle-upload"]').click()
            form = page.locator('#quickUpload')
            submit_name = 'Upload'
        form.locator('input[name=image]').set_input_files({
            'name': f'updated-{label}-{surface}.png', 'mimeType': 'image/png', 'buffer': helpers.PHOTO})
        with page.expect_response(lambda response: is_route(response.url, 'api/upload.php')
                                  and response.request.method == 'POST') as captured:
            form.get_by_role('button', name=submit_name, exact=True).click()
        result = helpers.response_json(captured.value, 200, 'Updated ' + surface + ' upload')
        require(result['ok'], 'Updated ' + surface + ' upload failed.')
        helpers.assert_asset(context, page, site, base, result['asset'], helpers.PHOTO)
        if surface == 'library':
            expect(page.locator('.library-card').filter(has=page.get_by_role('heading', name=f'updated-{label}-{surface}', exact=True))).to_be_visible()
        else:
            expect(page.locator(f'[data-media-id="{result["asset"]["id"]}"]')).to_be_visible()
    page.reload(wait_until='networkidle')
    page.locator(f'[data-device="{device}"]').click()
    expect(page.locator(f'[data-layer-id="{layer_id}"]')).to_have_count(1)
    require(not errors, 'Refreshed actions produced uncaught JavaScript errors: ' + '; '.join(errors))
    return {'add_button': True, 'preview_stays_open_with_saved_draft': True,
            'publish_persists_public_button': True, 'public_button_booking_navigation': True,
            'library_and_composer_uploads': True, 'exact_asset_bytes_and_browser_decode': True,
            'normal_reload_preserves_layer': True, 'uncaught_javascript_errors': 0}


def run_case(browser, site: Path, base: str, process, upgrade: Path, build: dict, label: str, cache: bool, php: str) -> dict:
    restricted, restricted_csrf = login_http(base, 'former-owner@example.test')
    data = helpers.state(site)
    data['admins']['update-former-owner']['role'] = 'editor'
    (site / 'storage/data.json').write_text(json.dumps(data))
    member_session = json.loads(subprocess.check_output([php, str(helpers.FIXTURE), str(site), 'member-session'], text=True))
    member = helpers.requests_session()
    member.cookies.set(member_session['name'], member_session['id'], domain='127.0.0.1', path='/')
    # Popups and additional pages inherit the context viewport, not a later
    # page.set_viewport_size call used by the Update Status geometry checks.
    device = 'mobile' if 'nested' in label else 'desktop'
    context = browser.new_context(viewport=VIEWPORTS[device])
    page = context.new_page()
    negative_errors = []
    page.on('pageerror', lambda error: negative_errors.append(str(error)))
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(base + '/admin/?page=media', wait_until='networkidle')
        page.goto(base + '/admin/?page=composer', wait_until='networkidle')
        require(page.locator('[data-action="add-button"]').count() == 1, 'Actual Build .5 Composer is not the pinned old build.')
        page.goto(base + '/admin/update.php', wait_until='networkidle')
        expect(page.locator('[data-running-build]')).to_have_text(OLD_BUILD)
        page.goto(base + '/admin/?page=composer', wait_until='networkidle')
        before = cache_probe(context, base, warm=True)
        require(before['pid'] == process.pid and before['asset_identity'] == OLD_ASSET_VERSION, 'Old runtime/process identity is wrong.')
        require(before['enabled'] == cache and not before['validate_timestamps'], 'PHP cache fixture is not configured as declared.')
        require(all(before['cached'].values()) if cache else not any(before['cached'].values()),
                'Negative control did not warm the actual old PHP scripts as required.')
        require(before['neighbor_cached'] == cache, 'Independent cache sentinel was not warmed as declared.')
        preserved = snapshot(site)
        owners = helpers.state(site)['admins']
        with ZipFile(upgrade) as package:
            names = package.namelist()
            package.extractall(site)
            require(all((site / name).read_bytes() == package.read(name) for name in names), 'Overlay does not exactly match the complete Upgrade ZIP.')
        unchanged(site, preserved, 'Complete Upgrade extraction')
        page.reload(wait_until='networkidle')
        stale = cache_probe(context, base)
        require(stale['pid'] == before['pid'], 'The PHP process restarted during extraction/reload.')
        negative = {'all_upgrade_bytes_match': True, 'same_php_process': True}
        residue = {path.name for path in site.iterdir() if path.is_dir() and path.name in RETIRED_ROOTS}
        require(residue == RETIRED_ROOTS and (site / 'media.php').is_file(),
                'ZIP extraction alone unexpectedly removed old root folders; the real cleanup negative control is invalid.')
        negative.update({'overlay_alone_leaves_all_25_root_folders': True, 'known_forwarders_remain': 46})
        if cache:
            require(stale['asset_identity'] == OLD_ASSET_VERSION and all(stale['cached'].values()),
                    'Old Build .5 PHP did not remain cached through actual extraction.')
            old_status = context.request.get(base + '/admin/update.php')
            require(old_status.status == 200 and 'The update file inventory is incomplete.' in old_status.text()
                    and 'data-update-status=' not in old_status.text(),
                    'The warmed .5 update verifier did not reproduce the removed-root inventory failure.')
            negative.update({'old_update_controller_class_and_view_remain_cached': True,
                             'old_inventory_validator_rejects_organized_package': True,
                             'old_asset_version': OLD_ASSET_VERSION})
        else:
            require(stale['asset_identity'] == f"{build['version']}-{build['build_id']}"
                    and page.locator('[data-action="add-button"]').count() == 1,
                    'Normal reload did not load the updated runtime when OPcache is disabled.')
        response = page.goto(base + '/admin/repair.php', wait_until='networkidle')
        require(response is not None and response.status == 200 and 'no-store' in response.headers.get('cache-control', ''),
                'Owner Update Status is missing or can be cached by the browser.')
        expect(page.locator('[data-update-status]')).to_have_attribute('data-update-status', 'complete')
        expect(page.locator('[data-uploaded-build]')).to_have_text(build['build_id'])
        expect(page.locator('[data-running-build]')).to_have_text(OLD_BUILD if cache else build['build_id'])
        security = denied_requests(site, base, context, restricted, restricted_csrf, member) if cache else {}
        unchanged(site, preserved, 'Owner verification and rejected requests')
        mixed_file_refused(site, base, context, preserved, stale['asset_identity'])
        geometry = status_geometry(page, label)
        page.set_viewport_size(VIEWPORTS[device])
        with page.expect_navigation(wait_until='networkidle') as refreshed:
            page.get_by_role('button', name='Refresh application PHP', exact=True).click()
        require(refreshed.value is not None and refreshed.value.status == 200, 'Actual refresh form did not reload Update Status.')
        redirect = refreshed.value.request.redirected_from
        require(redirect is not None and redirect.method == 'POST' and redirect.response().status == 303,
                'Refresh did not use a real owner POST followed by a 303 redirect.')
        expect(page.locator('[data-running-build]')).to_have_text(build['build_id'])
        expect(page.get_by_role('status')).to_contain_text('Application PHP was refreshed' if cache else 'PHP caching is disabled')
        after = cache_probe(context, base)
        require(after['pid'] == before['pid'] == process.pid
                and after['asset_identity'] == f"{build['version']}-{build['build_id']}",
                'Refresh required a PHP restart or failed to activate the actual new build.')
        require(after['neighbor_cached'] == cache, 'Application refresh reset unrelated PHP cache entries.')
        unchanged(site, preserved, 'Owner application cache refresh')
        layout = repair_layout(context, page, site, base, label, preserved)
        actions = after_refresh_actions(context, page, site, base, label, device)
        require(helpers.state(site)['admins'] == owners, 'Verification/actions changed owner account records.')
        unchanged(site, {key: value for key, value in preserved.items() if key != 'storage/data.json'}, 'Completed update actions')
        return {'persistent_opcache': cache, 'php': before['php'], 'before': before, 'after': after,
                'negative_control': negative, 'security': security, 'geometry': geometry, 'layout': layout, 'actions': actions,
                'complete_upgrade_files': len(names), 'owner_get_no_store': True,
                'incomplete_overlay_diagnosed_and_activation_blocked': True, 'owner_form_post_303': True,
                'existing_configuration_identity_records_uploads_preserved': True,
                'same_authenticated_browser_context': True, 'no_php_restart': True}
    finally:
        if EVIDENCE and not page.is_closed():
            page.screenshot(path=str(EVIDENCE / f'installed-update-{label}-last-page.png'), full_page=True)
        context.close()
        member.close()
        restricted.close()


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for installed-update acceptance.')
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Keep update evidence outside application files; ignored engineering artifacts are allowed.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    report = {'status': 'running', 'historical_commit': OLD_COMMIT, 'historical_fresh_sha256': OLD_FRESH_SHA256,
              'server': 'Actual PHP file serving without router/restart; no intercepted responses', 'cases': {}}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-installed-update-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            old = historical_package(work)
            build = build_packages(ROOT, work / 'current-packages')
            current = next(package for package in build['packages'] if package['kind'] == 'upgrade')
            upgrade = work / 'current-packages' / current['filename']
            report.update({'build_id': build['build_id'], 'upgrade_sha256': current['sha256']})
            options = {'headless': True}
            executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
            if executable:
                options['executable_path'] = executable
            browser = playwright.chromium.launch(**options)
            report['browser_version'] = browser.version
            try:
                for label, nested, cache in [('root-cache', False, True), ('nested-cache', True, True),
                                             ('nested-cache-disabled', True, False)]:
                    with installed_server(work, old, label, nested, cache, php) as (site, base, process):
                        report['cases'][label] = run_case(browser, site, base, process, upgrade, build, label, cache, php)
                    print(f'Historical installed update {label}: PASS (same process/browser, complete overlay, owner verification and cache activation).', flush=True)
            finally:
                browser.close()
        report['status'] = 'passed'
    except Exception as error:
        report['status'] = 'failed'
        report['failure'] = type(error).__name__ + ': ' + str(error).splitlines()[0]
        raise
    finally:
        if EVIDENCE:
            (EVIDENCE / 'installed-update-results.json').write_text(json.dumps(report, indent=2) + '\n')
    print(f"Installed-update acceptance passed: exact historical ZIP; root/nested persistent OPcache; cache-disabled refresh; "
          f"desktop/mobile layout; real Preview, Publish and uploads. Chromium {report['browser_version']}.", flush=True)


if __name__ == '__main__':
    run()
