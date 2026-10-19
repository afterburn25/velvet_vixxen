#!/usr/bin/env python3
"""Exercise real packaged image uploads with no development-server route fallback.

The ordinary HTTP router forwards missing .php paths and previously hid absent
API entrypoints. This suite extracts the customer ZIP, serves only actual files,
and tests both a domain-root and a two-level subdirectory installation. Missing
physical endpoints are retained as a negative control; a separate labelled host
error fixture exercises the reported literal 'File not found.' response.
"""
from __future__ import annotations

import hashlib
from html.parser import HTMLParser
import importlib.util
import json
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
from urllib.parse import unquote, urlparse
from zipfile import ZipFile
import zlib

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = ROOT / 'tests/Http/media_upload_fixture.php'
EVIDENCE = Path(os.environ['VELVET_SCREENSHOT_DIR']).resolve() if os.environ.get('VELVET_SCREENSHOT_DIR') else None
sys.dont_write_bytecode = True


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def png(width: int, height: int, color: tuple[int, int, int, int]) -> bytes:
    """A transparent geometric test asset, never a substitute owner photograph."""
    def chunk(kind: bytes, payload: bytes) -> bytes:
        return struct.pack('!I', len(payload)) + kind + payload + struct.pack('!I', zlib.crc32(kind + payload) & 0xffffffff)
    rows = b''.join(b'\x00' + bytes(color) * width for _ in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', width, height, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')


PHOTO = png(64, 96, (244, 27, 155, 150))
THUMBNAIL = png(48, 36, (128, 14, 98, 255))
HOST_FILE_ERROR = b"<?php http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'File not found. ';"


def state(site: Path) -> dict:
    return json.loads((site / 'storage/data.json').read_text())


def uploaded_files(site: Path) -> dict[str, str]:
    return {path.relative_to(site).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for folder in ['uploads/media', 'uploads/thumbnails']
            for path in (site / folder).rglob('*') if path.is_file()}


def response_json(response, expected: int, label: str) -> dict:
    status = response.status if hasattr(response, 'status') else response.status_code
    require(status == expected, f'{label}: expected HTTP {expected}, got {status}.')
    require(response.headers.get('content-type', '').lower().startswith('application/json'), f'{label}: response is not JSON.')
    result = response.json()
    require(isinstance(result, dict) and isinstance(result.get('ok'), bool), f'{label}: invalid API envelope.')
    return result


def csrf_from_html(html: str) -> str:
    class TokenParser(HTMLParser):
        token = ''

        def handle_starttag(self, tag, attributes):
            fields = dict(attributes)
            if tag == 'meta' and fields.get('name') == 'csrf-token':
                self.token = fields.get('content') or ''
            elif tag == 'input' and fields.get('name') == '_csrf':
                self.token = fields.get('value') or ''

    parser = TokenParser()
    parser.feed(html)
    require(bool(parser.token), 'Actual owner page did not include CSRF protection.')
    return parser.token


def requests_session() -> requests.Session:
    session = requests.Session()
    session.trust_env = False
    return session


def security_http(site: Path, base: str, php: str) -> dict:
    endpoint = base + '/api/upload.php'
    scene_endpoint = base + '/api/scene.php?section=hero'
    before_media = state(site)['media']
    before_uploads = uploaded_files(site)
    guest = requests_session()
    result = response_json(guest.post(endpoint, files={'image': ('guest.png', PHOTO, 'image/png')}, timeout=10), 401, 'Guest upload')
    require(not result['ok'], 'A guest uploaded an owner asset.')
    require(not response_json(guest.get(scene_endpoint, timeout=10), 401, 'Guest scene access')['ok'], 'A guest read owner drafts.')

    # Authenticate through the real MemberAuth implementation. The private CLI
    # helper avoids adding a test login route or granting admin session state.
    member_session = json.loads(subprocess.check_output([php, str(FIXTURE), str(site), 'member-session'], text=True))
    member = requests_session()
    member.cookies.set(member_session['name'], member_session['id'], domain='127.0.0.1', path='/')
    result = response_json(member.post(endpoint, headers={'X-CSRF-Token': member_session['csrf']},
                                      files={'image': ('member.png', PHOTO, 'image/png')}, timeout=10), 401, 'Member-only upload')
    require(not result['ok'], 'A member-only session uploaded an owner asset.')
    require(not response_json(member.get(scene_endpoint, timeout=10), 401, 'Member-only scene access')['ok'], 'A member-only session read owner drafts.')

    owner = requests_session()
    login = owner.get(base + '/admin/?page=login', timeout=10)
    require(login.status_code == 200, 'Actual owner login is unavailable without rewriting.')
    signed_in = owner.post(base + '/admin/?page=login', data={
        '_csrf': csrf_from_html(login.text), 'email': 'owner@example.test', 'password': 'VelvetDemo12',
    }, timeout=10)
    require(signed_in.status_code == 200 and 'page=login' not in signed_in.url, 'Owner HTTP login failed.')
    csrf = csrf_from_html(signed_in.text)
    for headers in [{}, {'X-CSRF-Token': 'wrong-fixture-token'}]:
        result = response_json(owner.post(endpoint, headers=headers, files={'image': ('csrf.png', PHOTO, 'image/png')}, timeout=10), 419, 'Upload CSRF rejection')
        require(not result['ok'], 'Upload accepted a missing or wrong CSRF token.')
    result = response_json(owner.post(scene_endpoint, json={'action': 'publish', 'scene': {}}, timeout=10), 419, 'Scene CSRF rejection')
    require(not result['ok'], 'Scene publish accepted a missing CSRF token.')
    result = response_json(owner.get(endpoint, headers={'X-CSRF-Token': csrf}, timeout=10), 405, 'Upload method check')
    require(not result['ok'], 'GET reached image upload mutation.')
    result = response_json(owner.post(endpoint, headers={'X-CSRF-Token': csrf},
                                     files={'image': ('invalid.png', b'This is not an image.', 'image/png')}, timeout=10), 422, 'Invalid image rejection')
    require(not result['ok'] and 'image' in result.get('message', '').lower(), 'Invalid image did not produce a useful JSON validation error.')
    require(state(site)['media'] == before_media and uploaded_files(site) == before_uploads,
            'Rejected upload changed media records or stored files.')
    guest.close()
    member.close()
    owner.close()
    return {'guest_and_member_only_rejected': True, 'csrf_and_method_rejected': True, 'invalid_image_json': True}


def assert_asset(context, page, site: Path, base: str, asset: dict, original: bytes, thumbnail: bytes | None = None) -> None:
    for field, expected in [('url', original), ('thumbnailUrl', thumbnail)]:
        url = asset.get(field, '')
        require(isinstance(url, str) and url.startswith(base + '/uploads/'), f'Uploaded {field} lost the installation subdirectory.')
        response = context.request.get(url)
        require(response.status == 200 and response.headers.get('content-type', '').startswith('image/'), f'Uploaded {field} is not directly readable.')
        if expected is not None:
            require(response.body() == expected, f'Uploaded {field} did not preserve the submitted image bytes.')
        relative = unquote(urlparse(url).path[len(urlparse(base).path):]).lstrip('/')
        absolute = (site / relative).resolve()
        require(absolute.is_relative_to(site.resolve()) and absolute.is_file(), f'Uploaded {field} does not resolve to an existing package upload file.')
        dimensions = page.evaluate('''url => new Promise((resolve, reject) => {
            const image = new Image(); image.onload = () => resolve([image.naturalWidth, image.naturalHeight]);
            image.onerror = () => reject(new Error('Uploaded image failed to decode')); image.src = url;
        })''', url)
        require(dimensions[0] > 0 and dimensions[1] > 0, f'Uploaded {field} failed browser decoding.')


def submit(page, form, endpoint: str):
    with page.expect_response(lambda response: response.url == endpoint and response.request.method == 'POST') as captured:
        form.locator('button').last.click()
    return captured.value


def useful_error(message: str) -> None:
    require(bool(message.strip()), 'Upload failure did not appear in the actual interface.')
    require(not re.search(r'Unexpected token|not valid JSON|SyntaxError|<!doctype|<html', message, re.I),
            'The interface exposed JSON parser or host HTML errors: ' + message)
    require(bool(re.search(r'upload|server|file|page|request|image|session', message, re.I)), 'Upload failure has no useful explanation.')


def browser_case(browser, site: Path, base: str, label: str, viewport: dict) -> dict:
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    page_errors: list[str] = []
    page.on('pageerror', lambda error: page_errors.append(str(error)))
    page.goto(base + '/admin/?page=login', wait_until='networkidle')
    page.get_by_label('Email address').fill('owner@example.test')
    page.get_by_label('Password', exact=True).fill('VelvetDemo12')
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url('**/admin/')
    page.goto(base + '/admin/?page=media', wait_until='networkidle')
    page.get_by_role('button', name='Upload Images', exact=True).click()
    form = page.locator('#libraryUpload')
    require(form.get_attribute('action') == base + '/api/upload.php', 'Library form action does not use the configured installation URL.')
    photo_name = f'{label}-library.png'
    form.locator('input[name=image]').set_input_files({'name': photo_name, 'mimeType': 'image/png', 'buffer': PHOTO})
    form.locator('input[name=thumbnail]').set_input_files({'name': 'custom-thumbnail.png', 'mimeType': 'image/png', 'buffer': THUMBNAIL})
    endpoint_file = site / 'api/upload.php'
    endpoint_bytes = endpoint_file.read_bytes()
    endpoint = base + '/api/upload.php'
    baseline_media = state(site)['media']
    baseline_uploads = uploaded_files(site)

    # Real missing-file regression: no router is passed to php -S. Removing only
    # the physical compatibility entrypoint must produce a host-level 404 even
    # though public/routes/api/upload.php still exists in the runtime.
    endpoint_file.unlink()
    try:
        response = submit(page, form, endpoint)
        require(response.status == 404 and not response.headers.get('content-type', '').startswith('application/json'),
                'Missing API file was silently rescued by a development routing fallback.')
        expect(form.locator('[data-upload-message]')).to_have_class(re.compile(r'\berror\b'))
        native_error = form.locator('[data-upload-message]').inner_text()
        useful_error(native_error)
        expect(form.get_by_role('button', name='Upload Image', exact=True)).to_be_enabled()
    finally:
        endpoint_file.write_bytes(endpoint_bytes)

    # A labelled simulated FastCGI body confirms the exact reported F-token
    # failure without misrepresenting the built-in PHP server as FastCGI.
    endpoint_file.write_bytes(HOST_FILE_ERROR)
    try:
        native_parser = page.evaluate('''async endpoint => {
            try { await (await fetch(endpoint, {method: 'POST'})).json(); return {unexpected: true}; }
            catch (error) { return {name: error.name, message: error.message}; }
        }''', endpoint)
        require(native_parser.get('name') == 'SyntaxError', 'Literal host text did not reproduce the original JSON-parser failure.')
        response = submit(page, form, endpoint)
        require(response.status == 404 and response.text().startswith('File not found.'), 'Literal host error fixture was ineffective.')
        expect(form.locator('[data-upload-message]')).to_have_class(re.compile(r'\berror\b'))
        useful_error(form.locator('[data-upload-message]').inner_text())
        expect(form.get_by_role('button', name='Upload Image', exact=True)).to_be_enabled()
    finally:
        endpoint_file.write_bytes(endpoint_bytes)
    require(state(site)['media'] == baseline_media and uploaded_files(site) == baseline_uploads,
            'Failed routing attempts changed asset records or files.')

    response = submit(page, form, endpoint)
    result = response_json(response, 200, 'Library upload')
    require(result['ok'], 'Library upload failed with the restored real runtime endpoint.')
    library_asset = result['asset']
    assert_asset(context, page, site, base, library_asset, PHOTO, THUMBNAIL)
    card = page.locator('.library-card').filter(has=page.get_by_role('heading', name=photo_name[:-4], exact=True))
    expect(card).to_be_visible()
    card.locator('img').evaluate('image => image.decode()')
    require(card.locator('img').get_attribute('src') == library_asset['thumbnailUrl'], 'Refreshed Library did not display the new custom thumbnail.')
    if EVIDENCE:
        page.screenshot(path=str(EVIDENCE / f'packaged-upload-{label}-library.png'), full_page=True)

    page.goto(base + '/admin/?page=composer', wait_until='networkidle')
    device = 'mobile' if viewport['width'] < 600 else 'desktop'
    page.locator(f'[data-device="{device}"]').click()
    page.locator('[data-action="toggle-upload"]').click()
    form = page.locator('#quickUpload')
    expect(form).to_be_visible()
    composer_name = f'{label}-composer.png'
    form.locator('input[name=image]').set_input_files({'name': composer_name, 'mimeType': 'image/png', 'buffer': PHOTO})
    endpoint_file.unlink()
    try:
        response = submit(page, form, endpoint)
        require(response.status == 404, 'Composer missing-file negative control did not reach the actual host.')
        expect(page.locator('[data-status]')).to_have_class(re.compile(r'\berror\b'))
        useful_error(page.locator('[data-status]').inner_text())
        expect(form.get_by_role('button', name='Upload', exact=True)).to_be_enabled()
    finally:
        endpoint_file.write_bytes(endpoint_bytes)
    response = submit(page, form, endpoint)
    result = response_json(response, 200, 'Composer upload')
    require(result['ok'], 'Composer upload failed with the restored runtime endpoint.')
    composer_asset = result['asset']
    assert_asset(context, page, site, base, composer_asset, PHOTO)
    card = page.locator(f'[data-media-id="{composer_asset["id"]}"]')
    expect(card).to_be_visible()
    card.locator('img').evaluate('image => image.decode()')
    card.hover()
    card.locator('[data-media-action="add"]').click()
    layer = page.locator('.composer-layer.selected')
    expect(layer).to_have_count(1)
    layer.locator('img').evaluate('image => image.decode()')
    require(layer.locator('img').get_attribute('src') == composer_asset['url'], 'Composer did not use the exact newly uploaded original.')
    layer_id = layer.get_attribute('data-layer-id')
    for action in ['save', 'publish']:
        with page.expect_response(lambda response: '/api/scene.php' in response.url and response.request.method == 'POST') as captured:
            page.locator(f'[data-action="{action}"]').click()
        result = response_json(captured.value, 200, f'Composer {action}')
        require(result['ok'], f'Composer {action} failed without URL rewriting.')
        expect(page.locator('[data-action="save"]')).to_be_enabled()
    saved = response_json(context.request.get(base + '/api/scene.php?section=hero'), 200, 'Read saved scene')
    require(any(layer.get('mediaId') == composer_asset['id'] for layer in saved['scene']['devices'][device]['layers']),
            'The uploaded image layer did not persist in the saved scene.')
    page.reload(wait_until='networkidle')
    page.locator(f'[data-device="{device}"]').click()
    require(page.locator(f'[data-layer-id="{layer_id}"] img').get_attribute('src') == composer_asset['url'],
            'Composer reload lost the uploaded image path.')
    if EVIDENCE:
        page.screenshot(path=str(EVIDENCE / f'packaged-upload-{label}-composer.png'), full_page=True)
    public = context.new_page()
    public.goto(base + '/', wait_until='networkidle')
    public_image = public.locator('[data-scene="hero"] .public-scene-layer img')
    expect(public_image).to_have_count(1)
    public_image.evaluate('image => image.decode()')
    require(public_image.get_attribute('src') == composer_asset['url'], 'Published Hero did not display the new upload.')
    require(not page_errors, 'Upload/Composer produced uncaught JavaScript errors: ' + '; '.join(page_errors))
    context.close()
    return {
        'viewport': viewport, 'library_custom_thumbnail_upload': True, 'composer_upload': True,
        'missing_physical_file_404': True, 'literal_host_text_parser_negative_control': native_parser['name'],
        'native_missing_file_error_is_useful': bool(native_error), 'retry_buttons_restored': True,
        'original_bytes_and_image_decoding': True, 'draft_publish_reload_and_public_hero': True,
    }


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for actual packaged HTTP execution.')
    spec = importlib.util.spec_from_file_location('vvx_upload_package', ROOT / 'scripts/package.py')
    require(spec is not None and spec.loader is not None, 'Actual runtime package builder is missing.')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Keep upload screenshots/logs outside application files; the ignored engineering artifacts folder is allowed.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-packaged-media-upload-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary['fresh_zip_sha256'] = fresh['sha256']
        with ZipFile(archive) as zipped:
            require({'api/upload.php', 'api/scene.php'} <= set(zipped.namelist()),
                    'Customer ZIP is missing physical upload/scene endpoints; the old rewritten-only build fails this regression.')
            require(not any(name.startswith(('tests/', 'scripts/', 'docs/')) for name in zipped.namelist()),
                    'Engineering helpers must not enter the customer ZIP.')
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for label, prefix, viewport in [
                ('root-desktop', '', {'width': 1440, 'height': 1000}),
                ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844}),
            ]:
                document_root = work / label / 'www'
                site = document_root / prefix.lstrip('/')
                site.mkdir(parents=True)
                with ZipFile(archive) as zipped:
                    zipped.extractall(site)
                (site / '.vvx-test-runtime').write_text('Disposable packaged media-upload acceptance.\n')
                with socket.socket() as listener:
                    listener.bind(('127.0.0.1', 0))
                    port = listener.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                config_before = (site / 'config/app.php').read_bytes()
                lock_before = (site / 'storage/install.lock').read_bytes()
                owners_before = state(site)['admins']
                log_file = work / label / 'php-server.log'
                environment = os.environ.copy()
                environment.pop('PHP_CLI_SERVER_WORKERS', None)
                with log_file.open('w+') as log:
                    process = subprocess.Popen([php, '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                               cwd=work, env=environment, stdout=log, stderr=log)
                    try:
                        session = requests_session()
                        deadline = time.monotonic() + 15
                        while True:
                            require(process.poll() is None, 'The isolated PHP server exited before accepting requests.')
                            try:
                                if session.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                    break
                            except requests.RequestException:
                                pass
                            require(time.monotonic() < deadline, 'The isolated PHP server did not become ready.')
                            time.sleep(0.1)
                        session.close()
                        security = security_http(site, base, php)
                        case = browser_case(browser, site, base, label, viewport)
                        require((site / 'config/app.php').read_bytes() == config_before
                                and (site / 'storage/install.lock').read_bytes() == lock_before
                                and state(site)['admins'] == owners_before, 'Upload regression changed existing installation identity or owner credentials.')
                        summary['cases'][label] = {**security, **case, 'preserved_installation_and_owner': True}
                        print(f'Packaged media upload {label}: PASS (real endpoint, authenticated uploads, rejection, retry, draft/publish and decoded public image).', flush=True)
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_file, EVIDENCE / f'packaged-upload-{label}-http.log')
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'packaged-upload-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Packaged no-rewrite Media Library and Composer upload acceptance passed at root/desktop and nested/mobile.', flush=True)


if __name__ == '__main__':
    run()
