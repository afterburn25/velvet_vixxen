#!/usr/bin/env python3
"""Verify organized customer packages through their real dispatcher, without rewriting."""
from __future__ import annotations

import base64
from collections import Counter
import hashlib
from html import unescape
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
from urllib.parse import parse_qs, quote, urlencode, urljoin, urlparse, urlsplit
from zipfile import ZipFile

import requests

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tests/Browser'))
from packaged_media_upload_playwright import FIXTURE, csrf_from_html, requests_session, require, state

EVIDENCE = Path(os.environ['VELVET_SCREENSHOT_DIR']).resolve() if os.environ.get('VELVET_SCREENSHOT_DIR') else None
APPROVED_ROOT_FILES = {'.htaccess', 'VERSION', 'index.php', 'route.php'}
APPROVED_FRESH_ROOTS = {'addons', 'admin', 'app', 'config', 'database', 'images', 'includes',
                        'install', 'public', 'storage', 'templates', 'uploads', 'vendor'}
APPROVED_UPGRADE_ROOTS = APPROVED_FRESH_ROOTS - {'config', 'install', 'storage', 'uploads'}


def canonical_url(base: str, route: str, *, preserve_alias: bool = False) -> str:
    """Exercise the delivered physical entry point; never use a test-server fallback."""
    parts = urlsplit(route)
    require(not parts.scheme and not parts.netloc, 'A test route must be local.')
    selector = parts.path.lstrip('/') if preserve_alias else parts.path.strip('/')
    return base + '/route.php?vvx_route=' + quote(selector, safe='') \
        + ('&' + parts.query if parts.query else '') + ('#' + parts.fragment if parts.fragment else '')


def route_inventory() -> dict[str, str]:
    text = (ROOT / 'app/Support/public_routes.php').read_text()
    block = re.search(r'function\s+vvx_public_routes\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[(.*?)\];', text, re.S)
    require(block is not None, 'Explicit registered public routes are missing.')
    pairs = re.findall(r"'([^']+)'\s*=>\s*'([^']+)'", block[1])
    routes = dict(pairs)
    require(len(routes) == len(pairs), 'Duplicate registered public URLs are not permitted.')
    require(len(routes) == 94 and len(set(routes.values())) == 47, 'Registered aliases or protected handlers were removed.')
    baseline = json.loads((ROOT / 'tests/Fixtures/public-routes-0.8.14.json').read_text())
    require(baseline['source_commit'] == '91f796767e2f5a844e9733703e92c965a99803cc' and len(baseline['routes']) == 87, 'The retained routing baseline changed.')
    additions = {name: name for name in ('api/mail-events.php', 'campaign/click.php', 'privacy/choices.php')}
    additions.update({'protected-asset.php': 'protected-asset.php', 'reports': 'reports/index.php', 'reports/': 'reports/index.php', 'reports/index.php': 'reports/index.php'})
    require(routes == baseline['routes'] | additions, 'A retained route changed or an unreviewed handler was introduced.')
    return routes


def application_response(session: requests.Session, base: str, route: str) -> requests.Response:
    response = session.get(canonical_url(base, route, preserve_alias=True), timeout=15, allow_redirects=False)
    require(response.headers.get('x-frame-options', '').upper() == 'SAMEORIGIN'
            and response.headers.get('content-security-policy') == "frame-ancestors 'self'",
            f'{route}: the actual application never ran (host HTTP {response.status_code}).')
    require(response.status_code in (200, 201, 204, 301, 302, 303, 307, 308, 400, 401, 403, 404, 405, 419, 422),
            f'{route}: unexpected application HTTP {response.status_code}.')
    require(not re.search(r'Fatal error:|Uncaught (?:Error|Exception)|Warning:.*\.php', response.text),
            f'{route}: PHP execution warnings/errors reached the response.')
    return response


def login(session: requests.Session, base: str, member: bool = False, return_to: str | None = None) -> str:
    endpoint = canonical_url(base, 'account/login.php') if member else base + '/admin/?page=login'
    if member and return_to is not None:
        endpoint += '&' + urlencode({'return': return_to})
    initial = session.get(endpoint, timeout=15)
    require(initial.status_code == 200, 'Physical sign-in entrypoint did not load.')
    data = {'_csrf': csrf_from_html(initial.text),
            'email': 'member@example.test' if member else 'owner@example.test',
            'password': 'VelvetMember12' if member else 'VelvetDemo12'}
    if member:
        hidden = re.search(r'<input[^>]+name="return"[^>]+value="([^"]*)"', initial.text)
        require(hidden is not None, 'Actual member form is missing its return destination.')
        expected_url = urlsplit(canonical_url(base, return_to if return_to is not None else '/account/'))
        expected = expected_url.path + '?' + expected_url.query
        require(unescape(hidden[1]) == expected, 'Member form return destination escaped the installation directory.')
        if return_to is not None:
            data['return'] = unescape(hidden[1])
        # Default login intentionally omits return from both query and POST;
        # the actual application must resolve its own default under the site.
    response = session.post(endpoint, data=data, timeout=15, allow_redirects=False)
    require(response.status_code in (302, 303), 'Actual account/owner HTTP login failed.')
    destination = urljoin(endpoint, response.headers.get('location', ''))
    require(destination.startswith(base + '/'), 'Sign-in redirected outside the configured installation directory.')
    if member:
        require(destination == canonical_url(base, return_to if return_to is not None else '/account/'),
                'Member sign-in did not preserve its requested site-relative destination.')
    page = session.get(destination, timeout=15)
    require(page.status_code == 200, 'The authenticated account/owner page did not load.')
    return csrf_from_html(page.text)


def cleanup_routes(site: Path, base: str, routes: dict[str, str], owner: requests.Session,
                   member: requests.Session, keep_custom: bool) -> dict:
    fixture = json.loads((ROOT / 'tests/Fixtures/root-layout-build5.json').read_text())
    require(fixture['source_commit'] == '2bc178013c9c528f45a5d0447624f3cbaf4395f2'
            and fixture['build_id'] == '20261019.5' and len(fixture['files']) == 46,
            'Cleanup fixture must identify the literal rejected Build .5 forwarding files.')
    retired = {relative: contents.encode() for relative, contents in fixture['files'].items()}
    retired_directories = {relative.split('/', 1)[0] for relative in retired if '/' in relative}
    require(len(retired_directories) == 25, 'The actual rejected update added 25 route directories.')
    inventory = json.loads((site / 'includes/runtime-build.json').read_text())
    active = inventory['files']
    require(not set(active).intersection(retired), 'The corrected runtime inventory still requires retired forwarding files.')
    require(all(hashlib.sha256((site / relative).read_bytes()).hexdigest() == digest for relative, digest in active.items()),
            'The current extracted application is incomplete before cleanup.')
    for relative, content in retired.items():
        target = site / relative
        require(not target.exists(), 'Corrected customer package already contains a retired root entry: ' + relative)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    retained = {
        'account/custom-owner-notes.txt': b'Disposable custom account notes; preserve in their existing location.\n',
        'api/custom-integration-notes.txt': b'Disposable custom API notes; preserve in their existing location.\n',
        'booking/custom-booking-notes.txt': b'Disposable custom booking notes; preserve in their existing location.\n',
    } if keep_custom else {}
    for relative, content in retained.items():
        target = site / relative
        require(not target.exists(), 'Cleanup fixture would replace an existing custom item: ' + relative)
        target.write_bytes(content)
    retained_metadata = {relative: (site / relative).stat() for relative in retained}
    data_before = (site / 'storage/data.json').read_bytes()
    endpoint = base + '/admin/repair.php'
    view = owner.get(endpoint, timeout=15)
    require(view.status_code == 200 and 'data-root-layout="repair-available"' in view.text
            and '46 verified forwarding files ready to retire' in view.text
            and 'value="repair_root_layout"' in view.text, 'The real owner repair page did not identify all known old files.')
    csrf = csrf_from_html(view.text)

    # Root cleanup is an owner action; neither anonymous nor member-only sessions
    # may use it. Every denied request must leave the exact prior files untouched.
    anonymous = requests_session()
    for session in [anonymous, member]:
        denied = session.post(endpoint, data={'action': 'repair_root_layout', '_csrf': csrf},
                              timeout=15, allow_redirects=False)
        require(denied.status_code in (302, 303) and '/admin/' in denied.headers.get('location', ''),
                'A nonowner session reached the folder repair action.')
    anonymous.close()
    denied = owner.post(endpoint, data={'action': 'repair_root_layout', '_csrf': 'invalid-fixture-token'},
                        timeout=15, allow_redirects=False)
    require(denied.status_code == 419 and 'session token expired' in denied.text.lower(),
            'Actual folder repair failed to enforce owner CSRF protection.')
    wrong_method = owner.put(endpoint, data={'action': 'repair_root_layout', '_csrf': csrf},
                             timeout=15, allow_redirects=False)
    require(wrong_method.status_code == 405 and wrong_method.headers.get('allow') == 'GET, POST',
            'Folder repair accepted an unsupported request method.')
    require(all((site / relative).read_bytes() == content for relative, content in retired.items()),
            'A denied repair request changed old forwarding files.')
    require(not list((site / 'storage/private').glob('root-layout/retired-*')),
            'A denied repair request created an archive or partially performed cleanup.')

    repaired = owner.post(endpoint, data={'action': 'repair_root_layout', '_csrf': csrf},
                          timeout=15, allow_redirects=False)
    require(repaired.status_code == 303 and urljoin(endpoint, repaired.headers.get('location', '')) == endpoint,
            'Actual owner folder repair did not complete through its physical entry point.')
    result = owner.get(endpoint, timeout=15)
    expected_removed = 25 - len({relative.split('/', 1)[0] for relative in retained})
    require(result.status_code == 200 and 'data-layout-result="complete"' in result.text
            and 'data-retired-files="46"' in result.text
            and f'data-removed-directories="{expected_removed}"' in result.text,
            'Owner repair did not report the actual retired files and empty folders.')
    require(all(not (site / relative).exists() for relative in retired), 'A known old forwarding file survived actual owner repair.')
    require(all((site / relative).is_file() and hashlib.sha256((site / relative).read_bytes()).hexdigest() == digest
                for relative, digest in active.items()), 'Folder repair altered a current organized application file.')
    require((site / 'storage/data.json').read_bytes() == data_before, 'Folder repair modified stored owner/member/content records.')
    for relative, content in retained.items():
        target = site / relative
        before = retained_metadata[relative]
        require(target.read_bytes() == content and target.stat().st_ino == before.st_ino
                and target.stat().st_mtime_ns == before.st_mtime_ns and target.stat().st_mode == before.st_mode,
                'Unknown custom item was moved, altered, or removed: ' + relative)
    expected_roots = APPROVED_FRESH_ROOTS | {relative.split('/', 1)[0] for relative in retained}
    actual_roots = {path.name for path in site.iterdir() if path.is_dir()}
    require(actual_roots == expected_roots, 'Actual installed root contains leftover empty public route directories.')
    require(('data-root-layout="custom-preserved"' if keep_custom else 'data-root-layout="organized"') in result.text,
            'Repair UI did not distinguish a restored root from intentionally preserved custom folders.')

    archives = list((site / 'storage/private/root-layout').glob('retired-*'))
    require(len(archives) == 1, 'Folder repair must produce one private reversible record.')
    record = json.loads((archives[0] / 'forwarders.json').read_text())
    require(record['source_commit'] == fixture['source_commit'] and record['installed_build'] == inventory['build_id']
            and set(record['entries']) == set(retired), 'Retired-file archive does not identify the actual known source files.')
    for relative, content in retired.items():
        archived = record['entries'][relative]
        require(archived['encoding'] == 'base64' and base64.b64decode(archived['contents'], validate=True) == content
                and archived['sha256'] == hashlib.sha256(content).hexdigest(),
                'Reversible record lost or altered an old forwarding file: ' + relative)
    require(not list(archives[0].rglob('*.php')) and 'Require all denied' in (archives[0] / '.htaccess').read_text(),
            'Retired forwarding code became an executable file in its archive.')
    require(all(path.stat().st_mode & 0o002 == 0 for path in [archives[0], *archives[0].iterdir()]),
            'Retired-file archive became world-writable.')

    guest = requests_session()
    after_cleanup = {route: application_response(guest, base, route).status_code for route in routes}
    guest.close()
    view = owner.get(endpoint, timeout=15)
    repeat = owner.post(endpoint, data={'action': 'repair_root_layout', '_csrf': csrf_from_html(view.text)},
                        timeout=15, allow_redirects=False)
    require(repeat.status_code == 303 and list((site / 'storage/private/root-layout').glob('retired-*')) == archives,
            'Repeated owner repair created another archive or failed despite no known work remaining.')
    require(all((site / relative).read_bytes() == content for relative, content in retained.items())
            and all(hashlib.sha256((site / relative).read_bytes()).hexdigest() == digest for relative, digest in active.items()),
            'Repeated repair changed current application files or preserved custom items.')
    return {'actual_owner_cleanup': True, 'nonowner_rejection': True, 'csrf_rejection': True,
            'method_rejection': True, 'retired_known_files': len(retired), 'removed_empty_directories': expected_removed,
            'preserved_current_runtime_files': len(active), 'custom_items_preserved_in_place': len(retained),
            'reversible_nonexecuting_archive': True, 'installed_root_directories': sorted(actual_roots),
            'registered_routes_after_cleanup': after_cleanup, 'repeat_cleanup_preserved_routes': True}


def verify_media_access(site: Path, base: str, guest: requests.Session, member: requests.Session) -> dict:
    locked = application_response(guest, base, 'photos/view.php?slug=vixxen-nights')
    unlocked = application_response(member, base, 'photos/view.php?slug=vixxen-nights')
    require(locked.status_code == 200 and 'Unlock Vixxen Nights.' in locked.text
            and unlocked.status_code == 200 and 'Available to You' in unlocked.text
            and 'Unlock Vixxen Nights.' not in unlocked.text, 'Registered-gallery entitlement guard or slug query changed.')

    # A signed media URL generated by the real helper must retain every signing
    # parameter through central dispatch, including HTTP byte-range playback.
    private_contents = b'Disposable route media: alpha & beta; signed-byte-range fixture.\n'
    private_file = site / 'storage/private/media/route-fixture.bin'
    private_file.parent.mkdir(parents=True, exist_ok=True)
    private_file.write_bytes(private_contents)
    data_path = site / 'storage/data.json'
    data = json.loads(data_path.read_text())
    data['media']['route-fixture-asset'] = {'id': 'route-fixture-asset', 'title': 'Route fixture', 'type': 'file',
        'file': 'private:route-fixture.bin', 'thumbnail': '', 'mime': 'application/octet-stream', 'private': True,
        'size': len(private_contents), 'createdAt': '2026-10-19T00:00:00Z'}
    data_path.write_text(json.dumps(data))
    php = shutil.which('php')
    generated = subprocess.run([php, '-r', "define('ROOT_PATH', $argv[1]); require ROOT_PATH . '/app/bootstrap.php'; echo media_signed_url('route-fixture-asset');", str(site)],
                               check=True, capture_output=True, text=True).stdout
    signed = urlsplit(generated)
    parameters = parse_qs(signed.query)
    require(signed.path == urlparse(base).path + '/route.php' and parameters.get('vvx_route') == ['media.php']
            and parameters.get('id') == ['route-fixture-asset'] and 'expires' in parameters and 'signature' in parameters,
            'The actual media helper did not generate a complete central-dispatch URL.')
    fetched = member.get(generated, timeout=15)
    require(fetched.status_code == 200 and fetched.content == private_contents
            and 'no-store' in fetched.headers.get('cache-control', ''), 'Signed media query did not deliver the exact private fixture bytes.')
    ranged = member.get(generated, headers={'Range': 'bytes=2-12'}, timeout=15)
    require(ranged.status_code == 206 and ranged.content == private_contents[2:13]
            and ranged.headers.get('content-range') == f'bytes 2-12/{len(private_contents)}',
            'Central dispatch broke signed media byte-range delivery.')
    tampered = {key: values[0] for key, values in parameters.items()}
    tampered['signature'] = '0' * 64
    denied = guest.get(base + '/route.php?' + urlencode(tampered), timeout=15)
    require(denied.status_code == 403, 'Central dispatch admitted a tampered protected-media signature.')
    return {'registered_gallery_access': True, 'signed_media_query_preserved': True,
            'exact_private_bytes': True, 'signed_media_range': True, 'tampered_signature_rejected': True}


def inspect_case(site: Path, base: str, routes: dict[str, str], keep_custom: bool) -> dict:
    config_before = (site / 'config/app.php').read_bytes()
    lock_before = (site / 'storage/install.lock').read_bytes()
    owners_before = state(site)['admins']
    guest = requests_session()
    responses = {}
    for route, handler in routes.items():
        response = application_response(guest, base, route)
        responses[route] = {'handler': handler, 'status': response.status_code,
                            'application_executed': True, 'content_type': response.headers.get('content-type', '')}

    # Missing records must remain application 404s, distinguishable from absent
    # entrypoints. Specific protected/method paths retain their actual guards.
    for route in ['photos/view.php', 'videos/watch.php', 'communication/download.php']:
        require(responses[route]['status'] == 404, f'{route}: missing-record behavior changed.')
    for route in ['api/scene.php', 'api/upload.php', 'api/member-state.php']:
        require(responses[route]['status'] == 401, f'{route}: guest authentication guard changed.')
    for route in ['api/addon-license.php', 'api/payment-webhook.php', 'api/marketing-event.php']:
        require(responses[route]['status'] == 405, f'{route}: method guard changed.')
    require(responses['media.php']['status'] == 403, 'Unsigned protected media must remain forbidden.')

    for alias, canonical in [('join', 'account/register.php'), ('login', 'account/login.php'),
                             ('create-account', 'account/register.php'), ('recover', 'account/forgot.php')]:
        expected = application_response(guest, base, canonical)
        expected_title = re.search(r'<title>(.*?)</title>', expected.text, re.S).group(1)
        for route in [alias, alias + '/', alias + '/index.php']:
            response = application_response(guest, base, route)
            require(response.status_code == 200 and '<title>' + expected_title + '</title>' in response.text,
                    f'{route}: short alias did not render its actual canonical authentication page.')

    for route in ['photos/view.php?slug=pink-paradise', 'videos/watch.php?slug=welcome-to-the-velvet-vixxen']:
        require(application_response(guest, base, route).status_code == 200, 'A valid packaged content page failed: ' + route)

    # The package's real dispatcher must exist. A synthetic fallback would hide
    # its removal and invalidate this real-host acceptance test.
    entrypoint = site / 'route.php'
    contents = entrypoint.read_bytes()
    entrypoint.unlink()
    try:
        missing = guest.get(canonical_url(base, 'booking/index.php'), timeout=15, allow_redirects=False)
        require(missing.status_code == 404 and 'x-frame-options' not in missing.headers,
                'Missing public entrypoint was incorrectly rescued by a test-router fallback.')
    finally:
        entrypoint.write_bytes(contents)
    require(application_response(guest, base, 'booking/index.php').status_code == 200,
            'Restored real dispatcher did not reach its protected application handler.')
    old_pretty = guest.get(base + '/booking/index.php', timeout=15, allow_redirects=False)
    require(old_pretty.status_code == 404 and 'x-frame-options' not in old_pretty.headers,
            'The no-rewrite test incorrectly assumed a retired physical route still exists.')

    member = requests_session()
    login(member, base, member=True)
    member_results = {}
    for route in ['account/', 'account/index.php', 'account/billing.php', 'account/library.php',
                  'account/security.php', 'messages/', 'requests/']:
        response = application_response(member, base, route)
        require(response.status_code == 200, 'Authenticated member page failed without rewriting: ' + route)
        member_results[route] = response.status_code
    member_state = application_response(member, base, 'api/member-state.php?type=track&id=demo-track-1')
    require(member_state.status_code == 200 and member_state.json().get('ok') is True,
            'Authenticated member-state API failed without rewriting.')
    require(application_response(member, base, 'api/scene.php').status_code == 401,
            'Physical owner scene endpoint admitted a member-only session.')
    request_member = requests_session()
    login(request_member, base, member=True, return_to='/requests/')
    logout = request_member.get(canonical_url(base, 'account/logout.php'), timeout=15, allow_redirects=False)
    require(logout.status_code in (302, 303)
            and urljoin(base, logout.headers.get('location', '')) == canonical_url(base, 'account/login.php'),
            'Member logout escaped the configured installation directory.')
    request_member.close()

    owner = requests_session()
    csrf = login(owner, base)
    scene = application_response(owner, base, 'api/scene.php?section=footer')
    require(scene.status_code == 200 and scene.json().get('ok') is True
            and scene.json()['scene']['section'] == 'footer', 'Owner scene dispatch lost its section query.')
    scene_before = state(site)['scenes']
    rejected_save = owner.post(canonical_url(base, 'api/scene.php?section=footer'), json={'action': 'save', 'scene': scene.json()['scene']},
                               headers={'X-CSRF-Token': 'invalid-fixture-token'}, timeout=15)
    require(rejected_save.status_code == 419 and rejected_save.json().get('ok') is False
            and state(site)['scenes'] == scene_before, 'Dispatched scene API lost its CSRF protection.')
    updated = scene.json()['scene']
    updated['background']['x'] = 63
    saved = owner.post(canonical_url(base, 'api/scene.php?section=footer'), json={'action': 'save', 'scene': updated},
                       headers={'X-CSRF-Token': csrf}, timeout=15)
    saved_scenes = state(site)['scenes']
    require(saved.status_code == 200 and saved.json().get('ok') is True
            and saved_scenes['footer']['draft']['background']['x'] == 63
            and saved_scenes['hero'] == scene_before['hero'], 'Central dispatch changed the scene query or JSON body.')
    upload = owner.get(canonical_url(base, 'api/upload.php'), headers={'X-CSRF-Token': csrf}, timeout=15)
    require(upload.status_code == 405 and upload.json().get('ok') is False, 'Owner upload method restriction changed.')
    access_before = verify_media_access(site, base, guest, member)
    cleanup = cleanup_routes(site, base, routes, owner, member, keep_custom)
    access_after = verify_media_access(site, base, guest, member)
    require(all(cleanup['registered_routes_after_cleanup'][route] == row['status'] for route, row in responses.items()),
            'A registered guest route changed behavior after the old root files were retired.')
    footer_after = application_response(owner, base, 'api/scene.php?section=footer')
    require(footer_after.status_code == 200 and footer_after.json()['scene']['background']['x'] == 63,
            'Retiring old roots broke the owner scene API or lost the saved footer.')
    require((site / 'config/app.php').read_bytes() == config_before and (site / 'storage/install.lock').read_bytes() == lock_before
            and state(site)['admins'] == owners_before, 'Route verification changed original owner or installation identity.')
    guest.close()
    member.close()
    owner.close()
    return {'registered_routes': responses, 'status_counts': dict(Counter(str(row['status']) for row in responses.values())),
            'short_alias_page_parity': True, 'native_missing_dispatcher_negative_control': True,
            'retired_pretty_php_without_rewriting_returns_native_404': True,
            'authenticated_member_pages': member_results, 'member_state': True, 'owner_scene': True,
            'default_member_return': True, 'requests_return': True, 'logout_return': True,
            'scene_query_json_and_csrf': True, 'access_before_cleanup': access_before, 'access_after_cleanup': access_after,
            'preserved_owner_and_installation': True, 'root_cleanup': cleanup}


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for real packaged route acceptance.')
    routes = route_inventory()
    handlers = {'public/routes/' + handler for handler in routes.values()}
    spec = importlib.util.spec_from_file_location('vvx_public_route_package', ROOT / 'scripts/package.py')
    require(spec is not None and spec.loader is not None, 'Runtime builder is unavailable.')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'), 'Keep route evidence outside application files.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without any router script; PHP opcode caching explicitly disabled',
               'physical_public_dispatchers': 1, 'protected_public_handlers': len(handlers), 'registered_aliases': len(routes),
               'pretty_url_scope': 'Old pretty PHP paths require real web-server rewriting; this test uses canonical route.php selectors.', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-public-routes-') as temporary:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        upgrade = next(entry for entry in built['packages'] if entry['kind'] == 'upgrade')
        archive = work / 'packages' / fresh['filename']
        summary['fresh_zip_sha256'] = fresh['sha256']
        summary['upgrade_zip_sha256'] = upgrade['sha256']
        for release, expected_roots in [(fresh, APPROVED_FRESH_ROOTS), (upgrade, APPROVED_UPGRADE_ROOTS)]:
            with ZipFile(work / 'packages' / release['filename']) as zipped:
                entries = set(zipped.namelist())
                roots = {name.split('/', 1)[0] for name in entries if '/' in name}
                root_files = {name for name in entries if '/' not in name}
                require(roots == expected_roots and root_files == APPROVED_ROOT_FILES,
                        'Actual customer ZIP root layout is not the approved organized allowlist.')
                require(handlers <= entries and 'route.php' in entries, 'Customer ZIP is missing organized dispatch implementations.')
                summary[release['kind'] + '_root_directories'] = sorted(roots)
        for label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
            document_root = work / label / 'www'
            site = document_root / prefix.lstrip('/')
            site.mkdir(parents=True)
            with ZipFile(archive) as zipped:
                zipped.extractall(site)
            (site / '.vvx-test-runtime').write_text('Disposable public-route acceptance.\n')
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            base = f'http://127.0.0.1:{port}{prefix}'
            subprocess.run([php, str(FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
            environment = os.environ.copy()
            environment.pop('PHP_CLI_SERVER_WORKERS', None)
            log_file = work / label / 'php-server.log'
            with log_file.open('w+') as log:
                process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                           cwd=work, env=environment, stdout=log, stderr=log)
                try:
                    session = requests_session()
                    deadline = time.monotonic() + 15
                    while True:
                        require(process.poll() is None, 'Disposable PHP route server exited unexpectedly.')
                        try:
                            if session.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                break
                        except requests.RequestException:
                            pass
                        require(time.monotonic() < deadline, 'Disposable PHP route server did not become ready.')
                        time.sleep(0.1)
                    session.close()
                    summary['cases'][label] = inspect_case(site, base, routes, keep_custom=label == 'root')
                    print(f'Packaged public routing {label}: PASS ({len(routes)} aliases through one physical dispatcher, {len(handlers)} protected handlers; real owner removal of 46 old root files, query/auth/CSRF/media guards and preserved custom items).', flush=True)
                finally:
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)
                    if EVIDENCE:
                        shutil.copyfile(log_file, EVIDENCE / f'packaged-public-routes-{label}-http.log')
    if EVIDENCE:
        (EVIDENCE / 'packaged-public-routes-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Every registered public alias reached its protected implementation through canonical route.php URLs without rewriting, before and after actual owner root repair, at domain root and nested installation.', flush=True)


if __name__ == '__main__':
    run()
