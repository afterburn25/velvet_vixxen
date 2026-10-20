#!/usr/bin/env python3
"""Real publication editors/public routes in actual customer ZIPs, no mail sends."""
from __future__ import annotations

import copy
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
from zipfile import ZipFile

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise AssertionError('Acceptance dependency is unavailable: ' + path.name)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


helpers = load('vvx_publication_package_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
http = load('vvx_publication_http', ROOT / 'tests/Http/publication_windows_http.py')
require, EVIDENCE = helpers.require, helpers.EVIDENCE


def screenshot(page, name):
    if EVIDENCE:
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        page.screenshot(path=str(EVIDENCE / ('publication-windows-' + name + '.png')), full_page=True)


def submit(page, button, error=None):
    with page.expect_navigation(wait_until='networkidle') as navigation:
        button.click()
    require(navigation.value is not None and navigation.value.status == 200, 'Owner action did not return a usable page.')
    if error:
        expect(page.locator('.admin-alert.error')).to_contain_text(error)
    else:
        expect(page.locator('.admin-alert.error')).to_have_count(0)
    return navigation.value


def layout(page, label):
    width = page.evaluate('({content: document.documentElement.scrollWidth, viewport: window.innerWidth})')
    require(width['content'] <= width['viewport'] + 1, label + ' has horizontal overflow: ' + repr(width))
    for element in page.locator('[data-publication-editor] input, [data-publication-editor] textarea, [data-publication-editor] select, [data-publication-editor] button, [data-publication-list] button, [data-publication-list] a').all():
        if element.is_visible():
            bounds = element.bounding_box()
            require(bounds is not None and bounds['width'] >= 15 and bounds['x'] >= -1 and bounds['x'] + bounds['width'] <= width['viewport'] + 1,
                    label + ' has a clipped or inaccessible publication control.')


def browser_case(browser, site: Path, base: str, label: str, viewport: dict):
    context = browser.new_context(viewport=viewport)
    errors = []
    context.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
    page, stale, public = context.new_page(), context.new_page(), context.new_page()
    protected = ['contacts', 'consents', 'campaigns', 'campaign_queue', 'segments', 'settings', 'owner_fixture_unrelated', 'links', 'referral_codes']
    preserved = {key: copy.deepcopy(http.state(site)[key]) for key in protected}
    data_file = site / 'storage/private/marketing/data.json'

    def open_editor(tab, collection):
        page.goto(base + '/admin/?page=marketing&tab=' + tab, wait_until='networkidle')
        return page.locator('form[data-publication-editor="' + collection + '"]')

    def public_page(destination, status):
        response = public.goto(helpers.route_url(base, destination), wait_until='networkidle')
        require(response is not None and response.status == status, 'Public window visibility is wrong: ' + destination)
        if public.locator('#ageGate:not([hidden])').count():
            public.locator('#ageEnter').click()

    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        form = open_editor('posts', 'posts')
        form.locator('[name="title"]').fill('Publication <post> & ' + label)
        form.locator('[name="slug"]').fill('window-post-' + label)
        form.locator('[name="body"]').fill('First saved paragraph.\nA second preserved paragraph.')
        form.locator('[name="excerpt"]').fill('Owner editable publication fixture.')
        form.locator('[name="status"]').select_option('published')
        form.locator('[name="publish_at"]').fill('2024-06-01T12:30')
        submit(page, form.get_by_role('button', name='Save Post', exact=True))
        post_id = form.locator('[name="id"]').input_value()
        revision = form.locator('[name="publication_revision"]').input_value()
        permalink = 'blog/post.php?slug=window-post-' + label
        expect(form.locator('[name="slug"]')).to_have_attribute('readonly', '')
        # Chromium's datetime-local value omits zero seconds; the HTTP suite
        # independently checks the exact saved UTC bytes and rendered value.
        expect(form.locator('[name="publish_at"]')).to_have_value(re.compile(r'^2024-06-01T12:30(?::00)?$'))
        row = page.locator('[data-publication-id="' + post_id + '"]')
        require(row.get_by_role('link', name='View', exact=True).evaluate('element => element.href') == helpers.route_url(base, permalink), 'Owner View did not retain the installation permalink.')
        public_page(permalink, 200)
        expect(public.get_by_role('heading', name='Publication <post> & ' + label, exact=True)).to_be_visible()
        stale.goto(page.url, wait_until='networkidle')
        form.locator('[name="title"]').fill('Publication edited ' + label)
        form.locator('[name="publish_at"]').fill('2030-06-01T12:30')
        submit(page, form.get_by_role('button', name='Save Post', exact=True))
        require(form.locator('[name="id"]').input_value() == post_id and form.locator('[name="publication_revision"]').input_value() != revision, 'Post edit lost its identity/revision.')
        public_page(permalink, 404)
        old_form = stale.locator('form[data-publication-editor="posts"]')
        old_form.locator('[name="title"]').fill('Stale attempted title')
        before = data_file.read_bytes()
        submit(stale, old_form.get_by_role('button', name='Save Post', exact=True), 'This publication changed')
        require(data_file.read_bytes() == before and old_form.locator('[name="publication_revision"]').input_value() == revision, 'Browser stale edit overwrote or refreshed its revision.')
        expect(old_form.locator('[name="title"]')).to_have_value('Stale attempted title')
        form.locator('[name="publish_at"]').fill('2024-06-01T12:30')
        submit(page, form.get_by_role('button', name='Save Post', exact=True))
        layout(page, label + ' post editor')
        screenshot(page, label + '-post-editor')
        submit(page, page.locator('[data-publication-id="' + post_id + '"]').get_by_role('button', name='Move to draft', exact=True))
        public_page(permalink, 404)
        submit(page, page.locator('[data-publication-id="' + post_id + '"]').get_by_role('button', name='Publish', exact=True))
        public_page(permalink, 200)
        expect(public.locator('.marketing-content')).to_contain_text('A second preserved paragraph.')

        banner = open_editor('banners', 'banners')
        banner.locator('[name="text"]').fill('First promotion ' + label)
        banner.locator('[name="url"]').fill('/membership/?fixture=' + label)
        banner.locator('[name="starts_at"]').fill('2024-01-01T00:00')
        banner.locator('[name="ends_at"]').fill('2030-01-01T00:00')
        submit(page, banner.get_by_role('button', name='Save Banner', exact=True))
        banner_id = banner.locator('[name="id"]').input_value()
        public_page('blog/', 200)
        expect(public.locator('[data-marketing-banner]')).to_contain_text('First promotion ' + label)
        page.get_by_role('link', name='Create another banner', exact=True).click()
        banner.locator('[name="text"]').fill('Second promotion ' + label)
        submit(page, banner.get_by_role('button', name='Save Banner', exact=True))
        expect(page.locator('[data-publication-id="' + banner_id + '"] [data-publication-state]')).to_contain_text('shown now')
        second_id = banner.locator('[name="id"]').input_value()
        expect(page.locator('[data-publication-id="' + second_id + '"] [data-publication-state]')).to_contain_text('waiting behind')
        layout(page, label + ' banner editor and overlap list')
        screenshot(page, label + '-banners-overlap')
        submit(page, page.locator('[data-publication-id="' + banner_id + '"]').get_by_role('button', name='Disable', exact=True))
        public_page('blog/', 200)
        expect(public.locator('[data-marketing-banner]')).to_contain_text('Second promotion ' + label)
        expect(public.locator('[data-marketing-banner]')).not_to_contain_text('First promotion ' + label)
        page.locator('[data-publication-id="legacy-window-banner"]').get_by_role('link', name='Edit', exact=True).click()
        expect(banner.locator('[name="starts_at"]')).to_have_attribute('type', 'text')
        expect(banner.locator('[name="starts_at"]')).to_have_value('invalid-legacy-start')
        layout(page, label + ' legacy date repair')
        screenshot(page, label + '-legacy-date-repair')
        banner.locator('[name="starts_at"]').fill('2024-02-30T00:00')
        before = data_file.read_bytes()
        submit(page, banner.get_by_role('button', name='Save Banner', exact=True), 'real publication date')
        require(data_file.read_bytes() == before, 'Browser accepted an impossible repaired date.')
        banner.locator('[name="starts_at"]').fill('2024-01-01T00:00')
        banner.locator('[name="enabled"]').uncheck()
        submit(page, banner.get_by_role('button', name='Save Banner', exact=True))
        require(banner.locator('[name="id"]').input_value() == 'legacy-window-banner', 'Legacy banner repair changed stable identity.')

        landing = open_editor('campaigns', 'landing_pages')
        landing.locator('[name="title"]').fill('Landing <window> & ' + label)
        landing.locator('[name="slug"]').fill('window-page-' + label)
        landing.locator('[name="body"]').fill('Saved promotion body with a stable address.')
        landing.locator('[name="cta_label"]').fill('Join fixture ' + label)
        landing.locator('[name="cta_url"]').fill('/membership/?fixture=window-' + label)
        landing.locator('[name="starts_at"]').fill('2030-01-01T00:00')
        landing.locator('[name="ends_at"]').fill('2031-01-01T00:00')
        submit(page, landing.get_by_role('button', name='Save Page', exact=True))
        page_id = landing.locator('[name="id"]').input_value()
        destination = 'campaign/?slug=window-page-' + label
        public_page(destination, 404)
        landing.locator('[name="starts_at"]').fill('2024-01-01T00:00')
        landing.locator('[name="ends_at"]').fill('2030-01-01T00:00')
        submit(page, landing.get_by_role('button', name='Save Page', exact=True))
        public_page(destination, 200)
        expect(public.get_by_role('heading', name='Landing <window> & ' + label, exact=True)).to_be_visible()
        cta = public.get_by_role('link', name='Join fixture ' + label, exact=True)
        require(cta.evaluate('element => element.href') == helpers.route_url(base, 'membership/?fixture=window-' + label), 'Edited landing page CTA left the installation.')
        layout(page, label + ' landing editor')
        screenshot(page, label + '-landing-editor')
        landing.locator('[name="ends_at"]').fill('2025-01-01T00:00')
        submit(page, landing.get_by_role('button', name='Save Page', exact=True))
        public_page(destination, 404)
        expect(page.locator('[data-publication-id="' + page_id + '"] [data-publication-state]')).to_have_text('Ended')
        landing.locator('[name="ends_at"]').fill('')
        submit(page, landing.get_by_role('button', name='Save Page', exact=True))
        submit(page, page.locator('[data-publication-id="' + page_id + '"]').get_by_role('button', name='Disable', exact=True))
        public_page(destination, 404)
        require(all(http.state(site)[key] == value for key, value in preserved.items()), 'Publication browser actions changed frozen campaigns or unrelated records.')
        require(not errors, 'Publication browser raised JavaScript errors: ' + repr(errors))
        require(all(row['attempts'] == 0 for row in http.state(site)['campaign_queue']), 'Browser acceptance attempted mail delivery.')
        return {'viewport': viewport, 'stable_edit_permalink': True, 'stale_browser_form_preserved': True,
                'publication_before_during_after': True, 'banner_precedence_disable': True, 'legacy_date_repair': True,
                'no_horizontal_overflow_or_clipped_controls': True, 'frozen_campaigns_preserved': True, 'external_messages_sent': 0}
    except Exception:
        screenshot(page, label + '-failure')
        raise
    finally:
        context.close()


def run():
    php = shutil.which('php')
    require(php is not None, 'PHP is required for packaged publication acceptance.')
    package = load('vvx_publication_actual_package', ROOT / 'scripts/package.py')
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'), 'Evidence must stay outside tracked/customer runtime.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
        (EVIDENCE / 'publication-windows-browser-evidence.json').unlink(missing_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'), 'server': 'php -S without router fallback', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-publication-package-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary.update(build_id=built['build_id'], fresh_zip_sha256=fresh['sha256'])
        with ZipFile(archive) as zipped:
            entries = set(zipped.namelist())
            require({'app/Core/MarketingPublication.php', 'app/Core/MarketingAudience.php', 'app/Core/MarketingStore.php',
                     'app/Core/SceneButton.php', 'includes/marketing_admin.php', 'app/Views/admin/marketing-publication-editor.php',
                     'app/Views/admin/marketing-publication-list.php', 'public/routes/blog/index.php', 'public/routes/blog/post.php'} <= entries,
                    'Actual customer ZIP is missing publication dependencies.')
            require(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'blog/', 'campaign/')) for name in entries), 'Customer package shipped fixtures or retired root routes.')
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for label, prefix, viewport in [('root-http', '', None), ('root-desktop', '', {'width': 1440, 'height': 1000}),
                                            ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})]:
                document_root = work / label / 'www'
                site = document_root / prefix.lstrip('/')
                site.mkdir(parents=True)
                with ZipFile(archive) as zipped:
                    zipped.extractall(site)
                (site / '.vvx-test-runtime').write_text('Disposable packaged publication window acceptance.\n')
                with socket.socket() as available:
                    available.bind(('127.0.0.1', 0))
                    port = available.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                http.initialize(php, site)
                preserved = {name: (site / name).read_bytes() for name in ('config/app.php', 'storage/install.lock')}
                environment = os.environ.copy()
                environment.pop('PHP_CLI_SERVER_WORKERS', None)
                log_path = work / label / 'php-server.log'
                with log_path.open('w+') as log:
                    process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                               cwd=work, env=environment, stdout=log, stderr=log)
                    try:
                        ready = http.session()
                        deadline = time.monotonic() + 15
                        while True:
                            require(process.poll() is None, 'Disposable packaged PHP server exited.')
                            try:
                                if ready.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                    break
                            except requests.RequestException:
                                pass
                            require(time.monotonic() < deadline, 'Disposable packaged PHP server did not become ready.')
                            time.sleep(0.1)
                        ready.close()
                        summary['cases'][label] = http.run_http(site, base, php) if viewport is None else browser_case(browser, site, base, label, viewport)
                        require(all((site / name).read_bytes() == data for name, data in preserved.items()), 'Publication acceptance changed installation identity or disabled-mail configuration.')
                        require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()), 'Publication HTTP produced PHP diagnostics.')
                        print('Publication windows ' + label + ': PASS (actual customer package; disabled mail).', flush=True)
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_path, EVIDENCE / ('publication-windows-' + label + '-http.log'))
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'publication-windows-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Publication window owner HTTP and packaged desktop/mobile acceptance passed. External messages sent: 0.', flush=True)


if __name__ == '__main__':
    run()
