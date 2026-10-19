#!/usr/bin/env python3
"""Verify every registered customer URL using actual files, without rewriting."""
from __future__ import annotations

import argparse
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
from urllib.parse import urlencode, urljoin, urlparse
from zipfile import ZipFile

import requests

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tests/Browser'))
from packaged_media_upload_playwright import FIXTURE, csrf_from_html, requests_session, require, state

EVIDENCE = Path(os.environ['VELVET_SCREENSHOT_DIR']).resolve() if os.environ.get('VELVET_SCREENSHOT_DIR') else None


def route_inventory() -> dict[str, str]:
    text = (ROOT / 'public/router.php').read_text()
    block = re.search(r'\$routes\s*=\s*\[(.*?)\];', text, re.S)
    require(block is not None, 'Explicit registered public routes are missing.')
    pairs = re.findall(r"'([^']+)'\s*=>\s*'([^']+)'", block[1])
    routes = dict(pairs)
    require(len(routes) == len(pairs), 'Duplicate registered public URLs are not permitted.')
    return routes


def application_response(session: requests.Session, base: str, route: str) -> requests.Response:
    response = session.get(base + '/' + route, timeout=15, allow_redirects=False)
    # A host may canonicalize a real directory before PHP runs. Follow only its
    # one exact local trailing-slash redirect, never an unrelated/external URL.
    if response.status_code in (301, 302, 307, 308) and 'x-frame-options' not in response.headers:
        location = urljoin(response.url, response.headers.get('location', ''))
        require(location == response.url.rstrip('/') + '/', f'Unexpected host redirect for {route}.')
        response = session.get(location, timeout=15, allow_redirects=False)
    require(response.headers.get('x-frame-options', '').upper() == 'SAMEORIGIN'
            and response.headers.get('content-security-policy') == "frame-ancestors 'self'",
            f'{route}: the actual application never ran (host HTTP {response.status_code}).')
    require(response.status_code in (200, 201, 204, 301, 302, 303, 307, 308, 400, 401, 403, 404, 405, 419, 422),
            f'{route}: unexpected application HTTP {response.status_code}.')
    require(not re.search(r'Fatal error:|Uncaught (?:Error|Exception)|Warning:.*\.php', response.text),
            f'{route}: PHP execution warnings/errors reached the response.')
    return response


def login(session: requests.Session, base: str, member: bool = False, return_to: str | None = None) -> str:
    endpoint = base + ('/account/login.php' if member else '/admin/?page=login')
    if member and return_to is not None:
        endpoint += '?' + urlencode({'return': return_to})
    initial = session.get(endpoint, timeout=15)
    require(initial.status_code == 200, 'Physical sign-in entrypoint did not load.')
    data = {'_csrf': csrf_from_html(initial.text),
            'email': 'member@example.test' if member else 'owner@example.test',
            'password': 'VelvetMember12' if member else 'VelvetDemo12'}
    if member:
        hidden = re.search(r'<input[^>]+name="return"[^>]+value="([^"]*)"', initial.text)
        require(hidden is not None, 'Actual member form is missing its return destination.')
        expected = urlparse(base).path + (return_to if return_to is not None else '/account/')
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
        require(destination == base + (return_to if return_to is not None else '/account/'),
                'Member sign-in did not preserve its requested site-relative destination.')
    page = session.get(destination, timeout=15)
    require(page.status_code == 200, 'The authenticated account/owner page did not load.')
    return csrf_from_html(page.text)


def cleanup_routes(site: Path, base: str, routes: dict[str, str], owner: requests.Session) -> dict:
    entrypoints = {route if route.endswith('.php') else route.rstrip('/') + '/index.php' for route in routes}
    active = {relative: hashlib.sha256((site / relative).read_bytes()).hexdigest() for relative in entrypoints}
    retained = {
        'account/previous-owner-notes.txt': b'Disposable unknown account notes; preserve these bytes.\n',
        'api/previous-integration-notes.txt': b'Disposable unknown API notes; preserve these bytes.\n',
        'booking/previous-booking-notes.txt': b'Disposable unknown booking notes; preserve these bytes.\n',
    }
    obsolete = 'scripts/previous-operator-notes.txt'
    obsolete_content = b'Disposable obsolete CLI-folder notes; archive these bytes privately.\n'
    for relative, content in {**retained, obsolete: obsolete_content}.items():
        target = site / relative
        require(not target.exists(), 'Cleanup fixture would replace an existing file: ' + relative)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    endpoint = base + '/admin/?page=repair-backups'
    view = owner.get(endpoint, timeout=15)
    require(view.status_code == 200 and 'archive_legacy_root_entries' in view.text,
            'Actual owner cleanup page did not identify the obsolete scripts folder.')
    csrf = csrf_from_html(view.text)

    denied = owner.post(endpoint, data={'action': 'archive_legacy_root_entries', '_csrf': 'invalid-fixture-token'},
                        timeout=15, allow_redirects=False)
    require(denied.status_code == 200 and 'session token expired' in denied.text.lower(),
            'Actual legacy cleanup failed to enforce owner CSRF protection.')
    require((site / obsolete).read_bytes() == obsolete_content and all((site / relative).is_file() for relative in active),
            'Rejected cleanup changed installed route files.')

    archived = owner.post(endpoint, data={'action': 'archive_legacy_root_entries', '_csrf': csrf},
                          timeout=15, allow_redirects=False)
    require(archived.status_code in (302, 303) and urljoin(endpoint, archived.headers.get('location', '')) == endpoint,
            'Actual owner cleanup action did not complete normally.')
    missing_or_changed = [relative for relative, digest in active.items()
                          if not (site / relative).is_file() or hashlib.sha256((site / relative).read_bytes()).hexdigest() != digest]
    require(not missing_or_changed, 'Legacy cleanup removed or changed active public entrypoints: ' + ', '.join(sorted(missing_or_changed)))

    private = site / 'storage/private'
    archive_roots = list(private.glob('legacy-root-layout-*'))
    require(bool(archive_roots), 'Cleanup did not create a private archive for genuinely obsolete files.')
    moved_obsolete = [archive / obsolete for archive in archive_roots if (archive / obsolete).is_file()]
    require(not (site / obsolete).exists() and len(moved_obsolete) == 1 and moved_obsolete[0].read_bytes() == obsolete_content,
            'Obsolete operational notes were lost or were not actually archived.')
    for relative, content in retained.items():
        candidates = [archive / relative for archive in archive_roots]
        require(not (site / relative).exists()
                and any(candidate.is_file() and candidate.read_bytes() == content for candidate in candidates),
                'Unknown legacy data was lost, changed, or left in the public route folder: ' + relative)
    for archive in archive_roots:
        protection = archive / '.htaccess'
        require(protection.is_file() and 'Require all denied' in protection.read_text(), 'Legacy archive lacks private-directory protection.')
        require(archive.stat().st_mode & 0o002 == 0, 'Legacy archive became world-writable.')

    guest = requests_session()
    after_cleanup = {}
    for route in routes:
        response = application_response(guest, base, route)
        after_cleanup[route] = response.status_code
    guest.close()
    view = owner.get(endpoint, timeout=15)
    repeat = owner.post(endpoint, data={'action': 'archive_legacy_root_entries', '_csrf': csrf_from_html(view.text)},
                        timeout=15, allow_redirects=False)
    require(repeat.status_code in (302, 303) and moved_obsolete[0].read_bytes() == obsolete_content
            and all((site / relative).is_file() and hashlib.sha256((site / relative).read_bytes()).hexdigest() == digest
                    for relative, digest in active.items()), 'Repeated cleanup removed active entrypoints or altered preserved archive data.')
    return {'actual_owner_cleanup': True, 'csrf_rejection': True, 'preserved_active_entrypoints': len(active),
            'unknown_legacy_data_preserved': len(retained), 'obsolete_scripts_privately_archived': True,
            'registered_routes_after_cleanup': after_cleanup, 'repeat_cleanup_preserved_routes': True}


def inspect_case(site: Path, base: str, routes: dict[str, str]) -> dict:
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

    # The real host returns 404 when the physical PHP file is removed. Internal
    # handler source still exists, so a universal fake route fallback would make
    # this negative control ineffective and fail the test.
    entrypoint = site / 'booking/index.php'
    contents = entrypoint.read_bytes()
    entrypoint.unlink()
    try:
        missing = guest.get(base + '/booking/index.php', timeout=15, allow_redirects=False)
        require(missing.status_code == 404 and 'x-frame-options' not in missing.headers,
                'Missing public entrypoint was incorrectly rescued by a test-router fallback.')
    finally:
        entrypoint.write_bytes(contents)
    require(application_response(guest, base, 'booking/index.php').status_code == 200,
            'Restored real public entrypoint did not reach its application handler.')

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
    logout = request_member.get(base + '/account/logout.php', timeout=15, allow_redirects=False)
    require(logout.status_code in (302, 303)
            and urljoin(base, logout.headers.get('location', '')) == base + '/account/login.php',
            'Member logout escaped the configured installation directory.')
    request_member.close()

    owner = requests_session()
    csrf = login(owner, base)
    scene = application_response(owner, base, 'api/scene.php?section=hero')
    require(scene.status_code == 200 and scene.json().get('ok') is True, 'Authenticated owner could not read the physical scene API.')
    upload = owner.get(base + '/api/upload.php', headers={'X-CSRF-Token': csrf}, timeout=15)
    require(upload.status_code == 405 and upload.json().get('ok') is False, 'Owner upload method restriction changed.')
    cleanup = cleanup_routes(site, base, routes, owner)
    require((site / 'config/app.php').read_bytes() == config_before and (site / 'storage/install.lock').read_bytes() == lock_before
            and state(site)['admins'] == owners_before, 'Route verification changed original owner or installation identity.')
    guest.close()
    member.close()
    owner.close()
    return {'registered_routes': responses, 'status_counts': dict(Counter(str(row['status']) for row in responses.values())),
            'short_alias_page_parity': True, 'native_missing_entrypoint_negative_control': True,
            'authenticated_member_pages': member_results, 'member_state': True, 'owner_scene': True,
            'default_member_return': True, 'requests_return': True, 'logout_return': True,
            'preserved_owner_and_installation': True, 'legacy_cleanup': cleanup}


def run() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--legacy-cleanup-source', type=Path,
                        help='Test-only negative control: restore a captured earlier cleanup service in disposable package copies.')
    args = parser.parse_args()
    php = shutil.which('php')
    require(php is not None, 'PHP is required for real packaged route acceptance.')
    routes = route_inventory()
    entrypoints = {route if route.endswith('.php') else route.rstrip('/') + '/index.php' for route in routes}
    spec = importlib.util.spec_from_file_location('vvx_public_route_package', ROOT / 'scripts/package.py')
    require(spec is not None and spec.loader is not None, 'Runtime builder is unavailable.')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'), 'Keep route evidence outside application files.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without any router script', 'physical_entrypoints': len(entrypoints), 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-public-routes-') as temporary:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary['fresh_zip_sha256'] = fresh['sha256']
        with ZipFile(archive) as zipped:
            require(entrypoints <= set(zipped.namelist()), 'Customer package is missing registered public entrypoints.')
        for label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
            document_root = work / label / 'www'
            site = document_root / prefix.lstrip('/')
            site.mkdir(parents=True)
            with ZipFile(archive) as zipped:
                zipped.extractall(site)
            if args.legacy_cleanup_source:
                legacy = args.legacy_cleanup_source.resolve()
                require(legacy.is_file() and not legacy.is_relative_to(site), 'The negative control requires a captured external source file.')
                (site / 'app/Core/RepairBackupService.php').write_bytes(legacy.read_bytes())
                summary['legacy_cleanup_negative_control_sha256'] = hashlib.sha256(legacy.read_bytes()).hexdigest()
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
                process = subprocess.Popen([php, '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
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
                    summary['cases'][label] = inspect_case(site, base, routes)
                    print(f'Packaged public routing {label}: PASS ({len(routes)} registered URLs, {len(entrypoints)} physical entrypoints; application responses, member/owner protection and aliases).', flush=True)
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
    print('Every registered public URL reached actual application code without rewriting at domain root and nested installation.', flush=True)


if __name__ == '__main__':
    run()
