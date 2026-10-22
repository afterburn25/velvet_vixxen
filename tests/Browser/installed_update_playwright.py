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
import re
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
SECURITY_FIXTURE = ROOT / 'tests/Http/installed_update_security_fixture.php'
OLD_COMMIT = '2bc178013c9c528f45a5d0447624f3cbaf4395f2'
OLD_FRESH_SHA256 = 'dcd89db762e58541160ffdcdf4901d0bb5f07c9f3c0d66b99a6480701006bfc9'
OLD_ASSET_VERSION = '0.8.1-20261019.5'
OLD_BUILD = '20261019.5'
PRIOR_RELEASE_COMMIT = '91f796767e2f5a844e9733703e92c965a99803cc'
PRIOR_RELEASE_SHA256 = '5365f363d4448ad40201a3db9e59f3b1c8573031126c6bbab16ababa22be84fb'
PRIOR_RELEASE_IDENTITY = '0.8.14-20261021.3'
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
foreach (['admin/index.php', 'app/Views/admin/composer.php', 'includes/version.php', 'admin/update.php', 'app/Core/Auth.php', 'app/Core/Store.php', 'app/Core/RuntimeBuildService.php', 'app/Views/admin/update-status.php'] as $file) {
    $cached[$file] = function_exists('opcache_is_script_cached') && opcache_is_script_cached(ROOT_PATH . '/' . $file);
}
json_response(['ok' => true, 'pid' => getmypid(), 'php' => PHP_VERSION,
    'enabled' => is_array($status) && !empty($status['opcache_enabled']),
    'cli_enabled_flag' => (bool) ini_get('opcache.enable_cli'),
    'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
    'cached' => $cached,
    'neighbor_cached' => function_exists('opcache_is_script_cached') && opcache_is_script_cached($other),
    'repair_entry_cached' => function_exists('opcache_is_script_cached') && opcache_is_script_cached(ROOT_PATH . '/admin/repair.php'),
    'current_authentication' => method_exists(app_auth(), 'requireRecentOwner'),
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


def prior_release_package(work: Path) -> Path:
    """The delivered 0.8.14 package contains the legacy repair entry itself."""
    source = work / 'prior-release-source'
    source.mkdir()
    archive = work / 'prior-release-source.tar'
    subprocess.run(['git', 'archive', '--format=tar', '--output=' + str(archive), PRIOR_RELEASE_COMMIT], cwd=ROOT, check=True)
    with tarfile.open(archive) as tar:
        for member in tar.getmembers():
            require((member.isfile() or member.isdir()) and not member.name.startswith('/')
                    and '..' not in Path(member.name).parts, 'Prior-release source archive contains an unsafe entry.')
        tar.extractall(source, filter='data')
    manifest = build_packages(source, work / 'prior-release-packages')
    fresh = next(package for package in manifest['packages'] if package['kind'] == 'fresh')
    zipped = work / 'prior-release-packages' / fresh['filename']
    require(sha256(zipped) == fresh['sha256'] == PRIOR_RELEASE_SHA256,
            'Rebuilt 0.8.14 differs from the exact delivered package.')
    with ZipFile(zipped) as package:
        require('admin/repair.php' in package.namelist() and 'admin/repair-current.php' not in package.namelist()
                and PRIOR_RELEASE_IDENTITY.encode() in package.read('includes/version.php'),
                'Prior release does not reproduce the actual cached legacy repair entry boundary.')
    return zipped


def snapshot(site: Path) -> dict[str, bytes]:
    return {relative: (site / relative).read_bytes() for relative in PRESERVED}


def unchanged(site: Path, expected: dict[str, bytes], label: str) -> None:
    require(all((site / relative).read_bytes() == value for relative, value in expected.items()),
            label + ' changed configuration, installation identity, records, or existing uploads/private files.')


def authentication_only(site: Path, expected: dict[str, bytes], label: str) -> None:
    """Preserve existing data across authentication and empty schema initialization."""
    unchanged(site, {name: data for name, data in expected.items() if name != 'storage/data.json'}, label)
    before = json.loads(expected['storage/data.json'])
    after = helpers.state(site)
    for data in (before, after):
        data.get('settings', {}).pop('_private_admin_security_v1', None)
    # A current Store write materializes the newly declared empty journal when
    # upgrading a historical file store. Accept only that exact missing -> []
    # transition; existing journal entries and every other field remain exact.
    if 'paymentJournal' not in before and 'paymentJournal' in after:
        require(after['paymentJournal'] == [], label + ' created payment records during authentication.')
        before['paymentJournal'] = []
    require(after == before, label + ' changed installed data outside the intentional administrator security journal or missing empty payment journal.')


def security_fixture(php: str, site: Path, action: str, *arguments: str) -> dict:
    result = subprocess.run([php, str(SECURITY_FIXTURE), str(site), action, *arguments],
                            check=True, capture_output=True, text=True)
    require(not result.stderr, 'Historical security fixture emitted PHP warnings: ' + result.stderr)
    return json.loads(result.stdout)


def login_http(base: str, email: str):
    session = helpers.requests_session()
    login = session.get(base + '/admin/?page=login', timeout=10)
    result = session.post(base + '/admin/?page=login', data={
        '_csrf': helpers.csrf_from_html(login.text), 'email': email, 'password': 'VelvetDemo12',
    }, timeout=10)
    require(result.status_code == 200 and 'page=login' not in result.url, 'Real fixture admin login failed.')
    return session, helpers.csrf_from_html(result.text)


@contextmanager
def installed_server(work: Path, old: Path, label: str, nested: bool, cache: bool, php: str, mfa: bool = False):
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
    factors = security_fixture(php, site, 'enable-factor') if mfa else None
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
            yield site, base, process, factors
        finally:
            failed = sys.exc_info()[0] is not None
            process.terminate()
            process.wait(timeout=10)
            if EVIDENCE:
                shutil.copyfile(log_path, EVIDENCE / f'installed-update-{label}-http.log')
            if not failed:
                warnings = re.findall(r'PHP (?:Warning|Notice|Fatal error|Deprecated|Parse error)[^\n]*', log_path.read_text())
                require(not warnings, 'Historical update emitted PHP errors: ' + '; '.join(warnings))


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
                require(result.status_code == (200 if method == 'get' else 419)
                        and 'data-repair-auth="signin"' in result.text and 'Refresh application PHP' not in result.text,
                        description + ' reached owner update verification/refresh.')
    # A legacy cookie cannot promote even the former owner. Current credentials
    # must then observe the saved demotion, rather than trusting its old role.
    stale = restricted.get(endpoint, timeout=15)
    require(stale.status_code == 200 and 'data-repair-auth="signin"' in stale.text,
            'A demoted legacy owner cookie was promoted into current authentication.')
    stale_post = restricted.post(endpoint, data={'action': 'refresh_php_cache', '_csrf': restricted_csrf},
                                 timeout=15, allow_redirects=False)
    require(stale_post.status_code == 401, 'A legacy owner cookie activated PHP without current authentication.')
    current = restricted.post(endpoint, data={'action': 'repair_signin', '_csrf': helpers.csrf_from_html(stale.text),
                                              'email': 'former-owner@example.test', 'password': 'VelvetDemo12'},
                              timeout=15, allow_redirects=False)
    require(current.status_code == 403, 'Current sign-in ignored a saved owner demotion.')
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
    return {'anonymous_and_member_get_post_denied': True, 'legacy_owner_cookie_never_promoted': True,
            'demoted_owner_current_session_get_post_403': True,
            'forbidden_method_405': True, 'missing_and_wrong_csrf_419': True, 'unknown_action_400': True}


def partial_uploads_refused(site: Path, base: str, context, preserved: dict, csrf: str) -> dict:
    endpoint = base + '/admin/repair.php'
    failures = []
    # Exercise parse failure before either entry can cache a complete controller.
    # This must remain a native recovery response even with PHP errors displayed.
    controller = site / 'app/Support/repair_entry.php'
    controller_bytes = controller.read_bytes()
    controller.write_bytes(b'<?php\ndeclare(strict_types=1);\nif (\n')
    try:
        for entry in ['repair.php', 'repair-current.php']:
            for method in ['get', 'post']:
                target = base + '/admin/' + entry
                result = context.request.get(target) if method == 'get' else context.request.post(
                    target, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
                require(result.status == 503 and 'data-update-status="incomplete"' in result.text()
                        and 'Complete the update' in result.text() and 'app/Support/repair_entry.php' in result.text()
                        and 'Refresh application PHP' not in result.text() and 'Parse error' not in result.text(),
                        f'Truncated controller did not safely refuse {method.upper()} through {entry}.')
        unchanged(site, preserved, 'Truncated controller refusal')
    finally:
        controller.write_bytes(controller_bytes)
    # Run before any successful repair preflight; even old authenticated sessions
    # cannot make incomplete uploads refresh authentication or application PHP.
    for relative in ['app/Core/RepairAccess.php', 'app/Core/Auth.php', 'app/Core/AdminSecurityStoreTrait.php',
                     'app/Core/MerchandiseStoreTrait.php', 'app/Core/Config.php', 'app/Views/admin/repair-signin.php',
                     'app/Support/repair_entry.php']:
        path = site / relative
        content = path.read_bytes()
        path.unlink()
        try:
            for method in ['get', 'post']:
                result = context.request.get(endpoint) if method == 'get' else context.request.post(
                    endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
                require(result.status == 503 and 'Complete the update' in result.text() and relative in result.text()
                        and 'Refresh application PHP' not in result.text(),
                        f'Missing {relative} did not safely refuse {method.upper()} with a native 503.')
            unchanged(site, preserved, 'Missing dependency refusal')
            failures.append(relative)
        finally:
            path.write_bytes(content)
    asset = site / 'public/assets/js/composer.js'
    content = asset.read_bytes()
    asset.write_bytes(content + b'\n// Synthetic partial update diagnostic.\n')
    try:
        result = context.request.get(endpoint)
        require(result.status == 503 and 'data-update-status="incomplete"' in result.text()
                and 'public/assets/js/composer.js' in result.text()
                and 'Different file contents' in result.text(), 'Mixed runtime did not identify the precise incomplete file.')
        require('type="submit">Refresh application PHP' not in result.text(), 'Incomplete files exposed an activation control.')
        blocked = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
        require(blocked.status == 503 and 'The update is incomplete.' in blocked.text(),
                'An owner could activate an incomplete runtime by posting directly.')
        unchanged(site, preserved, 'Incomplete update inspection/refusal')
        require(asset.read_bytes() == content + b'\n// Synthetic partial update diagnostic.\n',
                'Update verification silently rewrote a mismatched runtime file.')
    finally:
        asset.write_bytes(content)
    inventory_path = site / 'includes/runtime-build.json'
    raw = inventory_path.read_bytes()
    inventory = json.loads(raw)
    del inventory['files']['public/assets/js/scene-depth.js']
    inventory_path.write_text(json.dumps(inventory))
    try:
        for method in ['get', 'post']:
            result = context.request.get(endpoint) if method == 'get' else context.request.post(
                endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
            require(result.status == 503 and 'file inventory is incomplete' in result.text(),
                    'A self-consistent incomplete manifest bypassed the current inventory floor.')
    finally:
        inventory_path.write_bytes(raw)
    unchanged(site, preserved, 'Rejected update operations')
    return {'truncated_controller_both_entries_get_post_native_503': True,
            'missing_files_get_post_native_503': failures, 'mixed_file_get_post_native_503': True,
            'current_inventory_floor_get_post_native_503': True, 'customer_state_exactly_preserved': True}


def auth_geometry(page, label: str, mode: str) -> dict:
    results = {}
    for device, viewport in VIEWPORTS.items():
        page.set_viewport_size(viewport)
        expect(page.locator('[data-repair-auth]')).to_have_attribute('data-repair-auth', mode)
        geometry = page.evaluate('''() => ({width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
            controls: [...document.querySelectorAll('input:not([type=hidden]),button')].map(element => {
                const box=element.getBoundingClientRect(); return {width:box.width,height:box.height,left:box.left,right:box.right};
            })})''')
        require(geometry['scrollWidth'] <= viewport['width'] + 1 and geometry['controls'],
                f'{label}/{mode}/{device}: Authentication layout overflows or has no controls.')
        require(all(control['width'] >= 44 and control['height'] >= 44 and control['left'] >= 0
                    and control['right'] <= viewport['width'] + 1 for control in geometry['controls']),
                f'{label}/{mode}/{device}: Authentication targets are clipped or smaller than44pixels.')
        if EVIDENCE:
            page.screenshot(path=str(EVIDENCE / f'installed-update-{label}-auth-{mode}-{device}.png'), full_page=True)
        results[device] = {'viewport': viewport, 'no_horizontal_overflow': True,
                           'control_count': len(geometry['controls']), 'minimum_target_pixels': 44}
    return results


def current_owner_login(page, context, site: Path, base: str, php: str, label: str, factors: dict | None,
                        entry: str = 'repair.php') -> dict:
    endpoint = base + '/admin/' + entry
    before_get = snapshot(site)
    response = page.goto(endpoint, wait_until='networkidle')
    require(response is not None and response.status == 200 and 'no-store' in response.headers.get('cache-control', ''),
            'Current-security sign-in is unavailable or browser-cacheable.')
    expect(page.locator('[data-repair-auth]')).to_have_attribute('data-repair-auth', 'signin')
    unchanged(site, before_get, 'Current owner sign-in GET')
    expect(page.get_by_role('button', name='Refresh application PHP', exact=True)).to_have_count(0)
    layouts = {'signin': auth_geometry(page, label, 'signin')}
    csrf = helpers.csrf_from_html(page.content())
    for token in [None, 'wrong-repair-login-token']:
        fields = {'action': 'repair_signin', 'email': 'owner@example.test', 'password': 'VelvetDemo12'}
        if token is not None:
            fields['_csrf'] = token
        denied = context.request.post(endpoint, form=fields, max_redirects=0)
        require(denied.status == 419 and 'data-repair-auth="signin"' in denied.text(), 'Repair sign-in bypassed CSRF.')
    before_login = snapshot(site)
    old = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
    require(old.status == 401, 'Legacy owner session activated application PHP before signing in.')
    unchanged(site, before_login, 'Legacy session and invalid-CSRF rejection')
    page.get_by_label('Email address', exact=True).fill('owner@example.test')
    page.get_by_label('Password', exact=True).fill('VelvetDemo12')
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_load_state('networkidle')
    require(page.url == endpoint, 'Repair sign-in redirected into a different, potentially cached entry.')
    if factors is not None:
        expect(page.locator('[data-repair-auth]')).to_have_attribute('data-repair-auth', 'factor')
        layouts['factor'] = auth_geometry(page, label, 'factor')
        csrf = helpers.csrf_from_html(page.content())
        denied = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
        require(denied.status == 401 and 'data-repair-auth="factor"' in denied.text(), 'Pending-factor cookie activated PHP.')
        denied = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'repair_factor', 'code': 'incorrect'}, max_redirects=0)
        require(denied.status == 401, 'Invalid second factor was accepted by the actual repair endpoint.')
        page.get_by_label('Authenticator or recovery code', exact=True).fill(security_fixture(php, site, 'code')['code'])
        page.get_by_role('button', name='Confirm owner access', exact=True).click()
        page.wait_for_load_state('networkidle')
        require(page.url == endpoint, 'Repair factor redirected into a different, potentially cached entry.')
    expect(page.locator('[data-update-status]')).to_have_attribute('data-update-status', 'complete')
    cookie = next(cookie['value'] for cookie in context.cookies() if cookie['name'] == 'velvet_vixxen_admin')
    security_fixture(php, site, 'age-session', cookie)
    page.goto(endpoint, wait_until='networkidle')
    expect(page.locator('[data-repair-auth]')).to_have_attribute('data-repair-auth', 'reauth')
    # One non-MFA case plus the MFA case exercise both layouts; other cases still
    # perform real recent-owner confirmation in their original browser context.
    if label == 'root-cache' or factors is not None:
        layouts['reauth'] = auth_geometry(page, label, 'reauth')
    csrf = helpers.csrf_from_html(page.content())
    denied = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'refresh_php_cache'}, max_redirects=0)
    require(denied.status == 428, 'Expired recent-owner confirmation activated application PHP.')
    if factors is not None:
        denied = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'repair_reauth', 'password': 'VelvetDemo12'}, max_redirects=0)
        require(denied.status == 428, 'Password-only recent-owner confirmation bypassed the enabled factor.')
        page.get_by_label('Authenticator or recovery code', exact=True).fill(factors['recovery'][0])
    page.get_by_label('Password', exact=True).fill('VelvetDemo12')
    page.get_by_role('button', name='Confirm owner access', exact=True).click()
    page.wait_for_load_state('networkidle')
    require(page.url == endpoint, 'Repair confirmation redirected into a different, potentially cached entry.')
    expect(page.locator('[data-update-status]')).to_have_attribute('data-update-status', 'complete')
    if factors is not None:
        require(security_fixture(php, site, 'state')['recovery_count'] == 9, 'Recovery-code confirmation was not consumed.')
        security_fixture(php, site, 'age-session', cookie)
        replay = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'repair_reauth', 'password': 'VelvetDemo12',
                                                      'code': factors['recovery'][0]}, max_redirects=0)
        require(replay.status == 428, 'Consumed recovery code was accepted a second time.')
        renewed = context.request.post(endpoint, form={'_csrf': csrf, 'action': 'repair_reauth', 'password': 'VelvetDemo12',
                                                       'code': factors['recovery'][1]}, max_redirects=0)
        require(renewed.status == 303, 'Unused recovery code could not restore recent owner confirmation.')
        page.reload(wait_until='networkidle')
    authentication_only(site, before_login, 'Explicit owner authentication')
    require(page.locator('form').evaluate_all('(forms) => forms.every(form => form.getAttribute("action") === ' + json.dumps(entry) + ')'),
            'An update action would submit to a different, potentially cached entry.')
    return {'legacy_cookie_rejected': True, 'current_password': True, 'signin_csrf_checked': True,
            'recent_owner_confirmation': True, 'totp_required_and_accepted': factors is not None,
            'unused_recovery_accepted_and_replay_refused': factors is not None,
            'only_security_and_missing_empty_payment_journal_writes': True, 'entry': entry, 'forms_and_redirects_keep_entry': True,
            'geometry': layouts}


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


def run_case(browser, site: Path, base: str, process, upgrade: Path, build: dict, label: str, cache: bool, php: str,
             factors: dict | None = None) -> dict:
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
    phase = {'name': 'historical-before-overlay'}
    page.on('pageerror', lambda error: negative_errors.append(
        {'message': str(error), 'stack': error.stack, 'phase': phase['name']}))
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
        require(not before['current_authentication'], 'Historical server unexpectedly has current owner-security methods.')
        require(before['enabled'] == cache and not before['validate_timestamps'], 'PHP cache fixture is not configured as declared.')
        require(all(before['cached'].values()) if cache else not any(before['cached'].values()),
                'Negative control did not warm the actual old PHP scripts as required.')
        require(before['neighbor_cached'] == cache, 'Independent cache sentinel was not warmed as declared.')
        preserved = snapshot(site)
        owners = helpers.state(site)['admins']
        old_csrf = helpers.csrf_from_html(page.content())
        with ZipFile(upgrade) as package:
            names = package.namelist()
            package.extractall(site)
            require(all((site / name).read_bytes() == package.read(name) for name in names), 'Overlay does not exactly match the complete Upgrade ZIP.')
        unchanged(site, preserved, 'Complete Upgrade extraction')
        phase['name'] = 'historical-overlay-before-repair'
        page.reload(wait_until='networkidle')
        negative = {'all_upgrade_bytes_match': True, 'same_php_process': True}
        residue = {path.name for path in site.iterdir() if path.is_dir() and path.name in RETIRED_ROOTS}
        require(residue == RETIRED_ROOTS and (site / 'media.php').is_file(),
                'ZIP extraction alone unexpectedly removed old root folders; the real cleanup negative control is invalid.')
        negative.update({'overlay_alone_leaves_all_25_root_folders': True, 'known_forwarders_remain': 46})
        if cache:
            stale = cache_probe(context, base)
            require(stale['pid'] == before['pid'], 'The PHP process restarted during extraction/reload.')
            require(stale['asset_identity'] == OLD_ASSET_VERSION and all(stale['cached'].values()),
                    'Old Build .5 PHP did not remain cached through actual extraction.')
            require(not stale['current_authentication'], 'Extraction refreshed old Auth without owner verification.')
            old_status = context.request.get(base + '/admin/update.php')
            require(old_status.status == 200 and 'The update file inventory is incomplete.' in old_status.text()
                    and 'data-update-status=' not in old_status.text(),
                    'The warmed .5 update verifier did not reproduce the removed-root inventory failure.')
            negative.update({'old_update_controller_class_and_view_remain_cached': True,
                             'old_inventory_validator_rejects_organized_package': True,
                             'old_asset_version': OLD_ASSET_VERSION})
        partials = partial_uploads_refused(site, base, context, preserved, old_csrf)
        if cache:
            unchanged_cache = cache_probe(context, base)
            require(unchanged_cache['asset_identity'] == OLD_ASSET_VERSION and not unchanged_cache['current_authentication']
                    and unchanged_cache['neighbor_cached'], 'Rejected partial uploads changed cached authentication/application PHP.')
        # The pinned old Composer omits its successor's geometry dependency.
        # Accept only that exact observed mixed-runtime failure, with its old asset
        # URL and phase recorded. Every other historical/current error is a failure.
        historical_errors = list(negative_errors)
        require(all(cache and error['phase'] == 'historical-overlay-before-repair'
                    and error['message'] == "Cannot read properties of undefined (reading 'frame')"
                    and f'/public/assets/js/composer.js?v={OLD_ASSET_VERSION}:' in error['stack']
                    for error in historical_errors),
                'Unexpected historical-runtime JavaScript error: ' + json.dumps(historical_errors))
        negative['old_runtime_javascript_errors'] = historical_errors
        negative['unexpected_old_runtime_javascript_errors'] = 0
        phase['name'] = 'current-repair'
        authentication = current_owner_login(page, context, site, base, php, label, factors)
        authentication_only(site, preserved, 'Current owner authentication and recent confirmation')
        preserved = snapshot(site)
        expect(page.locator('[data-update-status]')).to_have_attribute('data-update-status', 'complete')
        expect(page.locator('[data-uploaded-build]')).to_have_text(build['build_id'])
        expect(page.locator('[data-running-build]')).to_have_text(OLD_BUILD if cache else build['build_id'])
        authenticated_cache = cache_probe(context, base)
        require(authenticated_cache['pid'] == before['pid'] and authenticated_cache['current_authentication']
                and authenticated_cache['asset_identity'] == (OLD_ASSET_VERSION if cache else f"{build['version']}-{build['build_id']}")
                and authenticated_cache['neighbor_cached'] == cache,
                'Current owner sign-in refreshed application/version/neighbor PHP before the explicit owner activation.')
        security = denied_requests(site, base, context, restricted, restricted_csrf, member)
        authentication_only(site, preserved, 'Current demoted-account authentication')
        preserved = snapshot(site)
        unchanged(site, preserved, 'Owner verification and rejected requests')
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
        phase['name'] = 'refreshed-composer'
        actions = after_refresh_actions(context, page, site, base, label, device)
        require(helpers.state(site)['admins'] == owners, 'Verification/actions changed owner account records.')
        unchanged(site, {key: value for key, value in preserved.items() if key != 'storage/data.json'}, 'Completed update actions')
        current_errors = [error for error in negative_errors if error['phase'] in ('current-repair', 'refreshed-composer')]
        require(not current_errors, 'Current update browser emitted uncaught JavaScript: ' + json.dumps(current_errors))
        return {'persistent_opcache': cache, 'php': before['php'], 'before': before, 'after': after,
                'negative_control': negative, 'partial_uploads': partials, 'authentication': authentication,
                'authenticated_before_activation': authenticated_cache,
                'security': security, 'geometry': geometry, 'layout': layout, 'actions': actions,
                'complete_upgrade_files': len(names), 'owner_get_no_store': True,
                'incomplete_overlay_diagnosed_and_activation_blocked': True, 'owner_form_post_303': True,
                'existing_configuration_identity_records_uploads_preserved': True,
                'same_authenticated_browser_context': True, 'no_php_restart': True,
                'uncaught_javascript_errors_after_current_repair': 0, 'php_warnings_or_errors': 0}
    finally:
        if EVIDENCE and not page.is_closed():
            page.screenshot(path=str(EVIDENCE / f'installed-update-{label}-last-page.png'), full_page=True)
        context.close()
        member.close()
        restricted.close()


def cached_entry_case(browser, site: Path, base: str, process, upgrade: Path, build: dict, php: str, factors: dict) -> dict:
    """A new physical entry reaches current security even if repair.php is old bytecode."""
    label = 'cached-repair-entry'
    context = browser.new_context(viewport=VIEWPORTS['desktop'])
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append({'message': str(error), 'stack': error.stack}))
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(base + '/admin/?page=composer', wait_until='networkidle')
        page.goto(base + '/admin/update.php', wait_until='networkidle')
        page.goto(base + '/admin/repair.php', wait_until='networkidle')
        expect(page.locator('[data-running-build]')).to_have_text('20261021.3')
        before = cache_probe(context, base, warm=True)
        require(before['pid'] == process.pid and before['enabled'] and not before['validate_timestamps']
                and before['asset_identity'] == PRIOR_RELEASE_IDENTITY and before['repair_entry_cached']
                and before['cached']['app/Core/Auth.php'] and not before['current_authentication']
                and before['neighbor_cached'], 'The delivered old repair entry/Auth were not actually cached.')
        factor_state = security_fixture(php, site, 'state')
        require(factor_state['enabled'] and factor_state['recovery_count'] == 10,
                'Current MFA settings were not already saved before the old runtime sign-in.')
        preserved = snapshot(site)
        owners = helpers.state(site)['admins']
        with ZipFile(upgrade) as package:
            names = package.namelist()
            package.extractall(site)
            require(all((site / name).read_bytes() == package.read(name) for name in names), 'Cached-entry overlay bytes differ from the actual Upgrade.')
        unchanged(site, preserved, 'Cached-entry complete Upgrade overlay')
        legacy = context.request.get(base + '/admin/repair.php')
        require(legacy.status == 200 and 'data-repair-auth=' not in legacy.text()
                and 'Refresh application PHP' in legacy.text() and '20261021.3' in legacy.text(),
                'The old repair entry did not reproduce the legacy-security boundary.')
        stale = cache_probe(context, base)
        require(stale['pid'] == before['pid'] and stale['repair_entry_cached'] and not stale['current_authentication']
                and stale['asset_identity'] == PRIOR_RELEASE_IDENTITY, 'Overlay silently refreshed the cached legacy entry or Auth.')
        unchanged(site, preserved, 'Inspection of the cached legacy entry')

        authentication = current_owner_login(page, context, site, base, php, label, factors, 'repair-current.php')
        authentication_only(site, preserved, 'New-entry password, MFA and recent confirmation')
        preserved = snapshot(site)
        before_activation = cache_probe(context, base)
        require(before_activation['pid'] == before['pid'] and before_activation['repair_entry_cached']
                and before_activation['current_authentication'] and before_activation['asset_identity'] == PRIOR_RELEASE_IDENTITY
                and before_activation['neighbor_cached'], 'New-entry authentication prematurely invalidated legacy entry/application/version/neighbor PHP.')
        expect(page.locator('[data-uploaded-build]')).to_have_text(build['build_id'])
        expect(page.locator('[data-running-build]')).to_have_text('20261021.3')
        endpoint = base + '/admin/repair-current.php'
        denied = context.request.post(endpoint, form={'action': 'refresh_php_cache', '_csrf': 'wrong'}, max_redirects=0)
        require(denied.status == 419, 'New-entry application activation bypassed owner CSRF.')
        unchanged(site, preserved, 'New-entry invalid-CSRF activation')
        geometry = status_geometry(page, label)
        page.set_viewport_size(VIEWPORTS['desktop'])
        with page.expect_navigation(wait_until='networkidle') as refreshed:
            page.get_by_role('button', name='Refresh application PHP', exact=True).click()
        require(refreshed.value is not None and refreshed.value.status == 200 and page.url == endpoint,
                'New-entry refresh failed or redirected to the cached legacy entry.')
        redirect = refreshed.value.request.redirected_from
        require(redirect is not None and redirect.method == 'POST' and redirect.url == endpoint
                and redirect.response().status == 303, 'New-entry refresh was not a real local POST/303 flow.')
        after = cache_probe(context, base)
        require(after['pid'] == process.pid == before['pid'] and after['asset_identity'] == f"{build['version']}-{build['build_id']}"
                and after['neighbor_cached'] and not after['repair_entry_cached'],
                'Protected new-entry activation restarted PHP, reset neighbors or left the cached old repair entry active.')
        unchanged(site, preserved, 'New-entry explicit application activation')
        compatibility = context.request.get(base + '/admin/repair.php')
        require(compatibility.status == 200 and 'data-update-status="complete"' in compatibility.text()
                and build['build_id'] in compatibility.text(), 'The compatibility entry did not recover after current PHP activation.')
        actions = after_refresh_actions(context, page, site, base, label, 'desktop')
        require(not errors, 'Cached-entry transition emitted JavaScript errors: ' + json.dumps(errors))
        require(helpers.state(site)['admins'] == owners, 'Cached-entry transition changed saved owner accounts.')
        unchanged(site, {name: data for name, data in preserved.items() if name != 'storage/data.json'}, 'Cached-entry completed actions')
        return {'historical_commit': PRIOR_RELEASE_COMMIT, 'historical_fresh_sha256': PRIOR_RELEASE_SHA256,
                'persistent_opcache': True, 'before': before, 'authenticated_before_activation': before_activation, 'after': after,
                'legacy_endpoint_retains_old_policy_until_refreshed': True,
                'legacy_cookie_reaches_old_form_with_saved_mfa': True,
                'legacy_form_was_not_used_to_activate': True, 'new_entry': 'admin/repair-current.php',
                'authentication': authentication, 'geometry': geometry, 'actions': actions,
                'complete_upgrade_files': len(names), 'no_php_restart': True, 'neighbor_cache_preserved': True,
                'current_compatibility_entry_after_activation': True, 'php_warnings_or_errors': 0,
                'uncaught_javascript_errors': 0, 'existing_configuration_identity_accounts_uploads_preserved': True}
    finally:
        if EVIDENCE and not page.is_closed():
            page.screenshot(path=str(EVIDENCE / f'installed-update-{label}-last-page.png'), full_page=True)
        context.close()


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
            require(sha256(upgrade) == current['sha256'], 'Actual Upgrade ZIP hash differs from its generated package record.')
            report.update({'build_id': build['build_id'], 'upgrade_sha256': current['sha256'],
                           'packages': {package['kind']: {'filename': package['filename'], 'sha256': package['sha256']}
                                        for package in build['packages']}})
            options = {'headless': True}
            executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
            if executable:
                options['executable_path'] = executable
            browser = playwright.chromium.launch(**options)
            report['browser_version'] = browser.version
            try:
                for label, nested, cache, mfa in [('root-cache', False, True, False), ('nested-cache', True, True, False),
                                                  ('nested-cache-disabled', True, False, False), ('root-cache-mfa', False, True, True)]:
                    with installed_server(work, old, label, nested, cache, php, mfa) as (site, base, process, factors):
                        report['cases'][label] = run_case(browser, site, base, process, upgrade, build, label, cache, php, factors)
                    print(f'Historical installed update {label}: PASS (same process/browser, complete overlay, owner verification and cache activation).', flush=True)
                prior = prior_release_package(work)
                with installed_server(work, prior, 'cached-repair-entry', False, True, php, True) as (site, base, process, factors):
                    report['cases']['cached-repair-entry'] = cached_entry_case(browser, site, base, process, upgrade, build, php, factors)
                print('Historical cached repair entry: PASS (exact delivered0.8.14, new entry, same process, current MFA before activation).', flush=True)
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
          f"cached legacy repair entry; current owner password/MFA/recovery and recent confirmation; desktop/mobile layout; real Preview, Publish and uploads. "
          f"Chromium {report['browser_version']}.", flush=True)


if __name__ == '__main__':
    run()
