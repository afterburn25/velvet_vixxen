#!/usr/bin/env python3
"""Actual packaged Media Center edits in file/MySQL root desktop/nested mobile sites."""
from __future__ import annotations

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
from urllib.parse import urlencode
from zipfile import ZipFile

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


helpers = load('vvx_media_package_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
http = load('vvx_media_http_helpers', ROOT / 'tests/Http/media_center_http.py')
require = helpers.require
EVIDENCE = helpers.EVIDENCE


def screenshot(page, label: str):
    if EVIDENCE:
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        page.screenshot(path=str(EVIDENCE / ('media-persistence-' + label + '.png')), full_page=True)


def no_overflow(page, label: str):
    geometry = page.evaluate('({content: document.documentElement.scrollWidth, viewport: innerWidth})')
    require(geometry['content'] <= geometry['viewport'] + 1, label + ' overflows horizontally: ' + repr(geometry))


def playlist_geometry(page, ids: list[str], label: str):
    rows = page.evaluate('''ids => ids.map(id => {
      const row = [...document.querySelectorAll('[data-playlist-track]')].find(item => item.dataset.playlistTrack === id);
      const rect = element => { const r = element.getBoundingClientRect(); return {left:r.left,right:r.right,width:r.width,height:r.height}; };
      const title = row.querySelector('label > span');
      return {id,row:rect(row),checkbox:rect(row.querySelector('input[type=checkbox]')),title:rect(title),
        titleContent:title.scrollWidth,titleViewport:title.clientWidth,
        buttons:[...row.querySelectorAll('[data-move-playlist-track]')].map(rect),viewport:innerWidth};
    })''', ids)
    for geometry in rows:
        row, box, title = geometry['row'], geometry['checkbox'], geometry['title']
        require(16 <= box['width'] <= 24 and 16 <= box['height'] <= 24,
                label + ' checkbox inherits full-width form styling: ' + repr(geometry))
        require(title['width'] >= 80 and geometry['titleContent'] <= geometry['titleViewport'] + 1
                and 34 <= row['height'] <= 136,
                label + ' track title is squeezed, clipped or stretched into a tall row: ' + repr(geometry))
        require(row['left'] >= -1 and row['right'] <= geometry['viewport'] + 1
                and all(button['width'] >= 32 and button['height'] >= 32 and button['left'] >= row['left'] - 1
                        and button['right'] <= row['right'] + 1 for button in geometry['buttons']),
                label + ' playlist arrows are clipped outside their row or viewport: ' + repr(geometry))
    return rows


def submit(page, button, label: str, rejected=False):
    with page.expect_navigation(wait_until='networkidle') as navigation:
        button.click()
    require(navigation.value is not None and navigation.value.status == 200, label + ' did not complete through the real owner action.')
    if rejected:
        expect(page.locator('.admin-alert.error')).to_be_visible()
    else:
        require(page.locator('.admin-alert.error').count() == 0,
                label + ' returned an application error: ' + ' '.join(page.locator('.admin-alert.error').all_inner_texts()))
        expect(page.locator('.admin-alert.error')).to_have_count(0)
        expect(page.locator('.admin-alert.success')).to_be_visible()


def assert_gallery(site: Path, ids: list[str], caption: str, tags: list[str]):
    saved = http.fixture(site, 'read')
    gallery = saved['gallery']
    require([str(item['assetId']) for item in gallery['items']] == ids, 'A separate PHP read found different gallery membership/order.')
    require(gallery['items'][0]['caption'] == caption and gallery['items'][0]['asset']['tags'] == tags,
            'Saved caption/tags differ from the actual browser input.')
    require(str(gallery['coverAssetId']) == ids[0] and [bool(item['isPreview']) for item in gallery['items']] == [True, False],
            'Removed cover fallback or preview membership is wrong.')
    require(saved['unrelated'] == {'preserve': True}, 'Media edits lost unrelated saved settings.')
    require(saved['plan_ids'] == [str(saved['seed']['plan']['id'])], 'A browser gallery edit cleared omitted membership-plan restrictions.')
    if saved['mode'] == 'mysql':
        rows = saved['sql']['items']
        require([str(row['asset_id']) for row in rows] == ids and [int(row['sort_order']) for row in rows] == [10, 20]
                and [int(row['is_preview']) for row in rows] == [1, 0] and rows[0]['caption'] == caption,
                'Actual committed MySQL gallery rows differ from browser order/caption/preview.')
        asset = next(row for row in saved['sql']['assets'] if str(row['id']) == ids[0])
        require(json.loads(asset['tags_json']) == tags, 'Browser tag edits did not commit to the actual MySQL asset row.')
        require(saved['sql']['plan_ids'] == [str(saved['seed']['plan']['id'])], 'Actual SQL membership-plan restrictions changed after a browser edit.')
    return saved


def assert_playlist(site: Path, ids: list[str]):
    saved = http.fixture(site, 'read')
    require(saved['playlist']['trackIds'] == ids, 'A separate PHP read lost exact browser playlist membership/order.')
    if saved['mode'] == 'mysql':
        require([str(row['track_id']) for row in saved['sql']['tracks']] == ids
                and [int(row['sort_order']) for row in saved['sql']['tracks']] == list(range(10, 10 * len(ids) + 1, 10)),
                'Actual committed MySQL playlist rows differ from the browser order.')
    return saved


def browser_case(browser, site: Path, base: str, label: str, viewport: dict):
    initial = http.fixture(site, 'read')
    asset = {key: str(value['id']) for key, value in initial['seed']['assets'].items()}
    track = {key: str(value['id']) for key, value in initial['seed']['tracks'].items()}
    gallery_id, playlist_id = str(initial['gallery']['id']), str(initial['playlist']['id'])
    gallery_url = base + '/admin/?' + urlencode({'page': 'galleries', 'edit': gallery_id})
    playlist_url = base + '/admin/?' + urlencode({'page': 'music', 'edit_playlist': playlist_id})
    public_gallery = http.route(base, 'photos/view.php?slug=' + initial['gallery']['slug'])
    public_music = http.route(base, 'music')
    config_bytes = (site / 'config/app.php').read_bytes()
    install_bytes = (site / 'storage/install.lock').read_bytes()
    context = browser.new_context(viewport=viewport)
    guest_context = browser.new_context(viewport=viewport)
    member_context = browser.new_context(viewport=viewport)
    page = context.new_page(); guest = guest_context.new_page(); member = member_context.new_page()
    errors = []
    for current in (page, guest, member):
        current.on('pageerror', lambda error: errors.append(str(error)))
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill(http.OWNER)
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        if viewport['width'] < 900:
            page.get_by_role('button', name='Toggle menu', exact=True).click()
        group = page.locator('[data-admin-group="content"]')
        if group.get_attribute('open') is None:
            group.locator('summary').click()
        link = group.get_by_role('link', name='Photo Galleries', exact=True)
        require(link.get_attribute('href') == base + '/admin/?page=galleries', 'Content & Media navigation lost the installation prefix.')
        link.click()
        page.wait_for_url('**/admin/?page=galleries')
        page.locator('a[href="' + gallery_url + '"]').filter(has_text='Edit').click()
        page.wait_for_url(gallery_url)
        editor = page.locator('[data-gallery-editor]')
        expect(editor.locator('[data-gallery-asset]')).to_have_count(3)
        page.wait_for_function('Array.from(document.querySelectorAll("[data-gallery-asset] img")).every(img => img.complete && img.naturalWidth > 0)')
        stale_gallery = context.new_page()
        stale_gallery.goto(gallery_url, wait_until='networkidle')
        photo = lambda key: editor.locator('[data-gallery-asset="' + asset[key] + '"]')
        photo('b').locator('textarea').fill('Undo preserves this unsaved caption')
        photo('b').locator('[name^="asset_tags"]').fill('undo, fixture')
        photo('b').locator('[data-remove-gallery-asset]').click()
        expect(editor.locator('[data-gallery-asset]')).to_have_count(2)
        editor.locator('[data-undo-gallery-removal]').click()
        expect(photo('b').locator('textarea')).to_have_value('Undo preserves this unsaved caption')
        expect(photo('b').locator('[name^="asset_tags"]')).to_have_value('undo, fixture')
        photo('c').locator('[data-move-gallery-asset="earlier"]').click()
        photo('c').locator('[data-move-gallery-asset="earlier"]').click()
        photo('b').locator('[data-remove-gallery-asset]').click()
        photo('c').locator('textarea').fill(http.CAPTION)
        photo('c').locator('[name^="asset_tags"]').fill('studio, sunset')
        editor.locator('[name="preview_count"]').fill('2')
        expect(editor.locator('[data-gallery-preview]:visible')).to_have_count(2)
        editor.locator('[name="preview_count"]').fill('1')
        expect(editor.locator('[data-gallery-preview]:visible')).to_have_count(1)
        expect(photo('c').locator('[data-gallery-position]')).to_have_text('Photo 1')
        require(json.loads(editor.locator('[name="asset_order"]').input_value()) == [asset['c'], asset['a']], 'Accessible reorder/remove controls produced the wrong authoritative order.')
        require([str(item['assetId']) for item in http.fixture(site, 'read')['gallery']['items']] == [asset['a'], asset['b'], asset['c']],
                'Unsaved reorder/removal changed committed data.')
        no_overflow(page, label + ' edited gallery')
        submit(page, editor.get_by_role('button', name='Save Gallery', exact=True), 'Save gallery')
        saved = assert_gallery(site, [asset['c'], asset['a']], http.CAPTION, ['studio', 'sunset'])
        require(saved['assets']['b']['tags'] == ['original-b'], 'Removing B committed its unsaved tags or deleted the independent asset.')
        require((site / 'storage/private/media/images/media-acceptance/b.png').is_file(), 'Gallery removal deleted the library image file.')
        page.reload(wait_until='networkidle')
        expect(photo('c').locator('textarea')).to_have_value(http.CAPTION)
        expect(photo('c').locator('[name^="asset_tags"]')).to_have_value('studio, sunset')
        expect(photo('b')).to_have_count(0)
        editor.locator('[name="title"]').fill('Browser retained gallery')
        submit(page, editor.get_by_role('button', name='Save Gallery', exact=True), 'Save a subsequent gallery edit')
        assert_gallery(site, [asset['c'], asset['a']], http.CAPTION, ['studio', 'sunset'])
        before_stale = http.snapshot(site)
        stale_gallery.locator('[data-gallery-editor] [name="title"]').fill('Stale form must fail')
        submit(stale_gallery, stale_gallery.get_by_role('button', name='Save Gallery', exact=True), 'Reject stale gallery', rejected=True)
        require(http.snapshot(site) == before_stale, 'A real stale browser tab changed gallery data or tags.')
        stale_gallery.close()
        no_overflow(page, label + ' saved gallery')
        screenshot(page, label + '-gallery-saved')

        require(guest.goto(public_gallery, wait_until='networkidle').status == 200, 'Saved gallery is not reachable through the actual physical dispatcher.')
        expect(guest.locator('[data-gallery-grid] .gallery-photo')).to_have_count(2)
        expect(guest.locator('[data-lightbox-item]')).to_have_count(1)
        expect(guest.locator('.gallery-photo.locked')).to_have_count(1)
        guest.locator('[data-lightbox-item] .photo-open').click()
        expect(guest.locator('[data-lightbox]')).to_be_visible()
        expect(guest.locator('[data-lightbox-caption]')).to_have_text(http.CAPTION)
        expect(guest.locator('[data-lightbox-caption] em')).to_have_count(0)
        guest.wait_for_function('document.querySelector("[data-lightbox-image]").naturalWidth > 0')
        expect(guest.locator('[data-lightbox-count]')).to_have_text('1 / 1')
        screenshot(guest, label + '-literal-caption-preview')
        guest.locator('[data-lightbox-close]').click()
        no_overflow(guest, label + ' public gallery')

        page.goto(playlist_url, wait_until='networkidle')
        playlist = page.locator('form:has(input[name="action"][value="save_audio_playlist"])')
        expect(playlist).to_be_visible()
        playlist_geometry(page, list(track.values()), label + ' initial playlist')
        playlist.locator('[data-playlist-track="' + track['draft'] + '"] input').check()
        playlist.locator('[data-playlist-track="' + track['second'] + '"] [data-move-playlist-track="earlier"]').click()
        submit(page, playlist.get_by_role('button', name='Save Playlist', exact=True), 'Save exact playlist order')
        assert_playlist(site, [track['second'], track['first'], track['draft']])
        page.reload(wait_until='networkidle')
        playlist.locator('[data-playlist-track="' + track['draft'] + '"] input').uncheck()
        playlist.locator('[name="status"]').select_option('scheduled')
        expect(playlist.locator('[name="scheduled_at"]')).to_have_attribute('required', '')
        playlist.locator('[name="scheduled_at"]').fill('2031-06-15T12:00')
        playlist.locator('[name="published_at"]').fill('')
        submit(page, playlist.get_by_role('button', name='Save Playlist', exact=True), 'Schedule playlist and remove draft track')
        saved = assert_playlist(site, [track['second'], track['first']])
        require(saved['playlist']['scheduledAt'] == '2031-06-15 12:00:00' and saved['playlist']['publishedAt'] is None,
                'The browser schedule did not persist in the configured site timezone.')
        page.reload(wait_until='networkidle')
        expect(playlist.locator('[name="scheduled_at"]')).to_have_value('2031-06-15T12:00')
        expect(playlist.locator('[data-playlist-track="' + track['draft'] + '"] input')).not_to_be_checked()
        no_overflow(page, label + ' scheduled playlist')
        geometry = playlist_geometry(page, list(track.values()), label + ' saved playlist')
        screenshot(page, label + '-playlist-scheduled')
        page.goto(gallery_url, wait_until='networkidle')
        editor.locator('[name="status"]').select_option('scheduled')
        editor.locator('[name="scheduled_at"]').fill('2031-06-15T12:00')
        editor.locator('[name="published_at"]').fill('')
        submit(page, editor.get_by_role('button', name='Save Gallery', exact=True), 'Schedule gallery')
        require(guest.goto(public_gallery, wait_until='networkidle').status == 404, 'A scheduled gallery is visible before publication.')
        guest.goto(public_music, wait_until='networkidle')
        expect(guest.locator('[data-playlist-card="' + playlist_id + '"]')).to_have_count(0)
        submit(page, page.get_by_role('button', name='Run Due Media Now', exact=True), 'Run while schedules are future')
        expect(page.locator('.admin-alert.success')).to_contain_text('0 due media item(s) published.')
        for url, action in ((gallery_url, 'save_gallery'), (playlist_url, 'save_audio_playlist')):
            page.goto(url, wait_until='networkidle')
            form = page.locator('form:has(input[name="action"][value="' + action + '"])')
            form.locator('[name="scheduled_at"]').fill('2001-06-15T12:00')
            submit(page, form.locator('button[type="submit"], button.admin-button.primary').last,
                   'Save a due schedule')
        stale_playlist = context.new_page(); stale_playlist.goto(playlist_url, wait_until='networkidle')
        require(guest.goto(public_gallery, wait_until='networkidle').status == 404, 'A public GET performed due publication.')
        submit(page, page.get_by_role('button', name='Run Due Media Now', exact=True), 'Explicit owner publication')
        expect(page.locator('.admin-alert.success')).to_contain_text('2 due media item(s) published.')
        published = assert_playlist(site, [track['second'], track['first']])
        require(published['gallery']['status'] == published['playlist']['status'] == 'published'
                and published['gallery']['scheduledAt'] == published['playlist']['scheduledAt'] == '2001-06-15 12:00:00'
                and published['gallery']['publishedAt'] == published['playlist']['publishedAt'] == '2001-06-15 12:00:00',
                'Owner publishing did not persist exact status/scheduled/published dates.')
        before_repeat = http.snapshot(site)
        submit(page, page.get_by_role('button', name='Run Due Media Now', exact=True), 'Repeat owner publication')
        expect(page.locator('.admin-alert.success')).to_contain_text('0 due media item(s) published.')
        require(http.snapshot(site) == before_repeat, 'A repeated browser due action changed media again.')
        stale_playlist.locator('form:has(input[value="save_audio_playlist"]) [name="status"]').select_option('draft')
        submit(stale_playlist, stale_playlist.get_by_role('button', name='Save Playlist', exact=True), 'Reject pre-publication playlist tab', rejected=True)
        require(http.snapshot(site) == before_repeat, 'A stale browser tab reverted scheduled publication.')
        stale_playlist.close()

        guest.goto(public_music, wait_until='networkidle')
        card = guest.locator('[data-playlist-card="' + playlist_id + '"]')
        expect(card).to_be_visible(); expect(card.locator('[data-play-playlist]')).to_have_count(0)
        expect(card.get_by_role('link', name='Sign in to play', exact=True)).to_be_visible()
        member_session = http.fixture(site, 'member-session')
        member_context.add_cookies([{'name': member_session['name'], 'value': member_session['id'], 'domain': '127.0.0.1', 'path': '/'}])
        member.goto(public_music, wait_until='networkidle')
        member_card = member.locator('[data-playlist-card="' + playlist_id + '"]')
        queue = member_card.locator('[data-play-playlist]')
        expect(queue).to_be_visible()
        require(json.loads(queue.get_attribute('data-play-playlist')) == [track['first']],
                'Registered playlist queue exposed draft/subscription tracks to a member without a plan.')
        no_overflow(guest, label + ' locked playlist'); no_overflow(member, label + ' registered playlist')
        screenshot(member, label + '-member-playlist')
        page.goto(playlist_url, wait_until='networkidle')
        playlist.locator('[name="access_mode"]').select_option('subscription')
        submit(page, playlist.get_by_role('button', name='Save Playlist', exact=True), 'Restrict playlist to subscribers')
        member.reload(wait_until='networkidle')
        expect(member_card.locator('[data-play-playlist]')).to_have_count(0)
        guest.reload(wait_until='networkidle')
        expect(card.locator('[data-play-playlist]')).to_have_count(0)
        playlist.locator('[name="status"]').select_option('draft')
        playlist.locator('[name="scheduled_at"]').fill('')
        playlist.locator('[name="published_at"]').fill('')
        submit(page, playlist.get_by_role('button', name='Save Playlist', exact=True), 'Clear dates on a draft')
        cleared = assert_playlist(site, [track['second'], track['first']])
        require(cleared['playlist']['scheduledAt'] is None and cleared['playlist']['publishedAt'] is None, 'Explicit date clearing did not persist.')
        page.goto(gallery_url, wait_until='networkidle')
        photo('c').locator('textarea').fill(''); photo('c').locator('[name^="asset_tags"]').fill('')
        submit(page, editor.get_by_role('button', name='Save Gallery', exact=True), 'Explicitly clear caption and tags')
        assert_gallery(site, [asset['c'], asset['a']], '', [])
        require((site / 'config/app.php').read_bytes() == config_bytes and (site / 'storage/install.lock').read_bytes() == install_bytes,
                'Media Center edits changed installation configuration or identity.')
        require(not errors, 'Real packaged Media Center raised JavaScript errors: ' + repr(errors))
        return {'mode': initial['mode'], 'viewport': viewport, 'mysql_version': initial.get('mysql_version'),
                'real_package_form_navigation': True, 'exact_gallery_ids_order_captions_tags_preview_cover': True,
                'remove_undo_and_subsequent_save': True, 'literal_lightbox_caption_and_private_preview': True,
                'real_sql_row_readback': initial['mode'] == 'mysql', 'exact_playlist_order_and_removal': True,
                'schedule_not_due_then_due_owner_action': True, 'publication_idempotence_and_stale_tabs': True,
                'guest_registered_and_subscription_access': True, 'explicit_metadata_date_clearing': True,
                'usable_playlist_checkbox_titles_arrows': geometry,
                'no_horizontal_overflow': True, 'uncaught_javascript_errors': errors}
    except Exception:
        screenshot(page, label + '-failure')
        raise
    finally:
        context.close(); guest_context.close(); member_context.close()


def run():
    php = shutil.which('php')
    require(php is not None, 'PHP is required for actual package Media Center acceptance.')
    require(os.environ.get('VELVET_TEST_DB_NAME') and os.environ.get('VELVET_TEST_DB_USER'),
            'Configure an explicitly disposable MySQL 8 database; file-only execution is not a passing substitute.')
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'), 'Keep browser evidence outside tracked/runtime source.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    package = load('vvx_media_actual_package', ROOT / 'scripts/package.py')
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'), 'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-media-ui-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary.update({'version': built['version'], 'build_id': built['build_id'], 'fresh_zip_sha256': fresh['sha256']})
        with ZipFile(archive) as zipped:
            entries = set(zipped.namelist())
            require({'app/Core/MediaInput.php', 'app/Support/media_admin.php', 'app/Console/run_media.php',
                     'app/Views/admin/media-center.php', 'public/assets/css/media-admin-edit.css', 'public/assets/js/admin-media.js'} <= entries,
                    'The actual Fresh package omitted a Media Center dependency.')
            require(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'photos/', 'music/', 'api/')) for name in entries),
                    'The actual customer package shipped fixtures or rejected root route folders.')
        options = {'headless': True}
        if os.environ.get('VELVET_CHROMIUM'):
            options['executable_path'] = os.environ['VELVET_CHROMIUM']
        browser = playwright.chromium.launch(**options); summary['browser'] = browser.version
        try:
            for mode in ('file', 'mysql'):
                for device, prefix, viewport in [('root-desktop', '', {'width': 1440, 'height': 1000}),
                                                  ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})]:
                    label = mode + '-' + device
                    document_root = work / label / 'www'; site = document_root / prefix.lstrip('/')
                    site.mkdir(parents=True)
                    with ZipFile(archive) as zipped: zipped.extractall(site)
                    (site / '.vvx-test-runtime').write_text('Disposable packaged Media Center acceptance.\n')
                    with socket.socket() as available:
                        available.bind(('127.0.0.1', 0)); port = available.getsockname()[1]
                    base = f'http://127.0.0.1:{port}{prefix}'
                    log_path = work / label / 'php-server.log'
                    try:
                        if mode == 'file':
                            subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                            http.fixture(site, 'initialize')
                        else:
                            http.fixture(site, 'setup-mysql', base)
                        with log_path.open('w+') as log:
                            environment = os.environ.copy(); environment.pop('PHP_CLI_SERVER_WORKERS', None)
                            process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S',
                                                        f'127.0.0.1:{port}', '-t', str(document_root)],
                                                       cwd=work, env=environment, stdout=log, stderr=log)
                            try:
                                client = http.session(); deadline = time.monotonic() + 15
                                while True:
                                    require(process.poll() is None, 'Disposable packaged PHP server exited.')
                                    try:
                                        if client.get(base + '/admin/?page=login', timeout=1).status_code == 200: break
                                    except requests.RequestException: pass
                                    require(time.monotonic() < deadline, 'Actual packaged PHP server did not start.')
                                    time.sleep(.1)
                                client.close()
                                summary['cases'][label] = browser_case(browser, site, base, label, viewport)
                                log.flush()
                                require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()),
                                        'Media Center acceptance raised PHP diagnostics.')
                                print('Media Center ' + label + ': PASS (actual ZIP/forms, exact committed data, schedules and access).', flush=True)
                            finally:
                                process.terminate()
                                try: process.wait(timeout=10)
                                except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
                    finally:
                        if mode == 'mysql' and (site / 'storage/temp/media-database-ownership.json').is_file():
                            http.fixture(site, 'cleanup-mysql')
                        if EVIDENCE and log_path.is_file():
                            shutil.copyfile(log_path, EVIDENCE / ('media-persistence-' + label + '-http.log'))
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'media-persistence-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Media Center packaged acceptance passed: four file/MySQL root-desktop/nested-mobile cases.', flush=True)


if __name__ == '__main__':
    run()
