#!/usr/bin/env python3
"""Audience owner HTTP and desktop/mobile UI in actual disposable customer ZIPs.

All sites use file storage and disabled mail. The CLI fixture lives in source,
outside the served package; no test endpoints, real addresses or mail sends.
"""
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


helpers = load('vvx_audience_package_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
http = load('vvx_audience_http', ROOT / 'tests/Http/marketing_audiences_http.py')
require = helpers.require
EVIDENCE = helpers.EVIDENCE


def screenshot(page, name: str, target=None):
    if EVIDENCE:
        path = str(EVIDENCE / ('marketing-audiences-' + name + '.png'))
        # Capture the complete real page from its top. Element screenshots of
        # tall mobile forms otherwise include the sticky header halfway through
        # the crop and hide fields that are available through normal scrolling.
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        page.screenshot(path=path, full_page=True)


def submit(page, button, expected_error: str | None = None):
    with page.expect_navigation(wait_until='networkidle') as navigation:
        button.click()
    require(navigation.value is not None and navigation.value.status == 200, 'Owner action did not return a usable real page.')
    if expected_error:
        expect(page.locator('.admin-alert.error')).to_contain_text(expected_error)
    else:
        expect(page.locator('.admin-alert.error')).to_have_count(0)
    return navigation.value


def no_overflow(page, label: str):
    width = page.evaluate('({content: document.documentElement.scrollWidth, viewport: window.innerWidth})')
    require(width['content'] <= width['viewport'] + 1, label + ' overflows horizontally: ' + repr(width))
    controls = []
    for field in page.locator('form[data-audience-editor] input, form[data-audience-editor] textarea, form[data-campaign-editor] input, form[data-campaign-editor] textarea, form[data-campaign-editor] select').all():
        if field.is_visible():
            box = field.bounding_box()
            name = field.get_attribute('name')
            if field.get_attribute('type') == 'checkbox':
                # The visible indicator is inside a larger native label target.
                # Measure and exercise that target instead of applying text-field
                # width requirements to the deliberately compact indicator.
                target = field.locator('xpath=ancestor::label[1]')
                require(target.count() == 1 and target.is_visible(), label + ' checkbox lacks its associated label: ' + str(name))
                target_box = target.bounding_box()
                require(box is not None and box['width'] >= 18 and box['height'] >= 18
                        and target_box is not None and target_box['width'] >= 44 and target_box['height'] >= 44
                        and target_box['x'] >= -1 and target_box['x'] + target_box['width'] <= width['viewport'] + 1
                        and box['x'] >= target_box['x'] - 1 and box['y'] >= target_box['y'] - 1
                        and box['x'] + box['width'] <= target_box['x'] + target_box['width'] + 1
                        and box['y'] + box['height'] <= target_box['y'] + target_box['height'] + 1,
                        label + ' has an inaccessible or clipped checkbox target: ' + repr({'name': name, 'indicator': box, 'label': target_box}))
                checked = field.is_checked()
                target.click()
                require(field.is_checked() != checked, label + ' label click did not toggle its checkbox: ' + str(name))
                field.focus()
                expect(field).to_be_focused()
                field.press('Space')
                require(field.is_checked() == checked, label + ' keyboard Space did not restore its checkbox state: ' + str(name))
                controls.append({'name': name, 'indicator': box, 'label_target': target_box,
                                 'label_click_toggled': True, 'keyboard_space_restored': True})
            else:
                require(box is not None and box['width'] >= 20 and box['x'] >= -1 and box['x'] + box['width'] <= width['viewport'] + 1,
                        label + ' has an inaccessible or clipped form control: ' + repr({'name': name, 'box': box}))
                controls.append({'name': name, 'box': box})
    return {'label': label, 'width': width, 'controls': controls}


def browser_case(browser, php: str, site: Path, base: str, label: str, viewport: dict) -> dict:
    contacts = [http.fixture(php, site, 'contact', email=email, source=source, confirmed=confirmed)
                for email, source, confirmed in [('first@example.test', 'alpha', True), ('second@example.test', 'alpha', True),
                                                  ('beta@example.test', 'beta', True), ('pending@example.test', 'alpha', False)]]
    context = browser.new_context(viewport=viewport)
    errors, layouts = [], []
    context.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()
    stale_page = context.new_page()
    audience_url = base + '/admin/?page=marketing&tab=audiences'
    campaign_url = base + '/admin/?page=marketing&tab=campaigns'
    data_file = site / 'storage/private/marketing/data.json'
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(audience_url, wait_until='networkidle')
        expect(page.get_by_role('link', name='Audiences', exact=True)).to_have_attribute('aria-current', 'page')
        audience = page.locator('form[data-audience-editor]')
        audience.locator('[name="name"]').fill('June alpha <owner> & ' + label)
        audience.locator('[name="sources"]').fill('alpha')
        audience.locator('[name="confirmed_after"]').fill('2024-06-01T00:00')
        audience.locator('[name="confirmed_before"]').fill('2024-07-01T00:00')
        before = data_file.read_bytes()
        submit(page, audience.get_by_role('button', name='Preview Audience', exact=True))
        expect(page.locator('[data-audience-preview]')).to_contain_text('2 eligible newsletter contacts')
        require(data_file.read_bytes() == before, 'Owner audience preview saved data.')
        layouts.append(no_overflow(page, label + ' audience preview'))
        screenshot(page, label + '-source-date-preview', page.locator('.marketing-admin-grid'))
        response = submit(page, audience.get_by_role('button', name='Save Audience', exact=True))
        require(response.request.method == 'GET' and len(http.state(site)['segments']) == 1, 'Audience save did not persist once and redirect to GET.')
        segment = copy.deepcopy(http.state(site)['segments'][0])
        revision = audience.locator('[name="segment_revision"]').input_value()
        edit_url = page.url
        stale_page.goto(edit_url, wait_until='networkidle')
        expect(audience.locator('[name="sources"]')).to_have_value('alpha')
        page.reload(wait_until='networkidle')
        require(len(http.state(site)['segments']) == 1, 'Refresh duplicated the saved audience.')

        page.goto(campaign_url, wait_until='networkidle')
        expect(page.get_by_role('button', name='Run Due Campaign Batch', exact=True)).to_be_disabled()
        campaign = page.locator('form[data-campaign-editor]')
        subject = 'Audience <layout> & ' + label
        body = 'Packaged owner campaign.\nOnly reserved fixture contacts; disabled mail.'
        campaign.locator('[name="subject"]').fill(subject)
        campaign.locator('[name="preheader"]').fill('Éditions réservées — fixture preview')
        campaign.locator('[name="body"]').fill(body)
        campaign.locator('[name="audience_selection"]').select_option(segment['id'] + ':' + revision)
        campaign.locator('[name="scheduled_at"]').fill('2030-01-15T18:30')
        campaign.locator('[name="queue"]').check()
        before = data_file.read_bytes()
        submit(page, campaign.get_by_role('button', name='Preview Campaign Audience', exact=True))
        expect(page.locator('[data-campaign-audience-preview]')).to_contain_text('2 eligible newsletter contacts')
        require(data_file.read_bytes() == before, 'Campaign preview saved a draft or queued work.')
        layouts.append(no_overflow(page, label + ' campaign preview'))
        screenshot(page, label + '-campaign-preview', campaign)
        http.fixture(php, site, 'contact', email='late-alpha@example.test', source='alpha')
        submit(page, campaign.get_by_role('button', name='Save Campaign', exact=True), 'draft was saved, but no new recipients were queued')
        require(len(http.state(site)['campaigns']) == 1 and http.state(site)['campaign_queue'] == [], 'Stale preview partially queued or duplicated the draft.')
        identifier = http.state(site)['campaigns'][0]['id']
        expect(campaign.locator('[name="id"]')).to_have_value(identifier)
        expect(campaign.locator('[name="audience_selection"]')).to_have_value(segment['id'] + ':' + revision)
        submit(page, campaign.get_by_role('button', name='Preview Campaign Audience', exact=True))
        expect(page.locator('[data-campaign-audience-preview]')).to_contain_text('3 eligible newsletter contacts')
        response = submit(page, campaign.get_by_role('button', name='Save Campaign', exact=True))
        require(response.request.method == 'GET', 'Campaign queue did not redirect to GET.')
        queued = copy.deepcopy(http.state(site)['campaigns'][0])
        rows = copy.deepcopy(http.state(site)['campaign_queue'])
        require(len(http.state(site)['campaigns']) == 1 and len(rows) == 3
                and sorted(row['email'] for row in rows) == ['first@example.test', 'late-alpha@example.test', 'second@example.test'],
                'Campaign retry did not queue exactly the refreshed preview.')
        require(queued['body'].replace('\r\n', '\n') == body and queued['scheduled_at'] == '2030-01-15T18:30:00+00:00'
                and queued['audience_snapshot']['rules'] == segment['rules'], 'Campaign content, schedule or frozen rules changed.')
        card = page.locator('[data-campaign-id="' + identifier + '"]')
        expect(card.get_by_role('heading', name=subject, exact=True)).to_be_visible()
        expect(card.locator('layout')).to_have_count(0)
        page.reload(wait_until='networkidle')
        require(http.state(site)['campaign_queue'] == rows and len(http.state(site)['campaigns']) == 1, 'Refreshing a queued campaign resubmitted its draft.')

        page.goto(edit_url, wait_until='networkidle')
        audience.locator('[name="name"]').fill('Beta after queue')
        audience.locator('[name="sources"]').fill('beta')
        submit(page, audience.get_by_role('button', name='Save Audience', exact=True))
        latest = data_file.read_bytes()
        submit(stale_page, stale_page.locator('form[data-audience-editor]').get_by_role('button', name='Save Audience', exact=True), 'This audience changed')
        require(data_file.read_bytes() == latest, 'Stale browser tab overwrote the newer segment.')
        submit(page, page.locator('[data-audience-id="' + segment['id'] + '"]').get_by_role('button', name='Remove Audience', exact=True))
        require(http.state(site)['segments'] == [] and http.state(site)['campaign_queue'] == rows
                and http.state(site)['campaigns'][0]['audience_snapshot'] == queued['audience_snapshot'], 'Audience edit/removal changed the saved queued campaign.')
        http.fixture(php, site, 'contact', email='new-alpha@example.test', source='alpha')
        http.fixture(php, site, 'contact', email='new-beta@example.test', source='beta')
        require(http.state(site)['campaign_queue'] == rows, 'A later contact was appended without an owner queue action.')
        page.goto(campaign_url, wait_until='networkidle')
        expect(card.locator('[data-campaign-audience]')).to_contain_text(segment['name'])
        submit(page, card.get_by_role('button', name='Preview Recipients', exact=True))
        expect(card.locator('[data-queue-audience-preview]')).to_contain_text('1 new eligible recipients can be added')
        submit(page, card.get_by_role('button', name='Queue Recipients', exact=True))
        require(len(http.state(site)['campaign_queue']) == 4 and http.state(site)['campaign_queue'][:3] == rows
                and http.state(site)['campaign_queue'][-1]['email'] == 'new-alpha@example.test', 'Explicit requeue did not append only the frozen-rule match.')
        before = data_file.read_bytes()
        submit(page, card.get_by_role('button', name='Queue Recipients', exact=True))
        require(data_file.read_bytes() == before, 'Repeated owner queue rewrote existing work.')
        layouts.append(no_overflow(page, label + ' frozen campaign'))
        screenshot(page, label + '-frozen-requeue', card)

        # Public opt-out remains decisive for a selected recipient even after
        # the original named audience has been removed.
        page.goto(helpers.route_url(base, 'newsletter/unsubscribe.php?token=' + contacts[0]['contact']['unsubscribe_token']), wait_until='networkidle')
        expect(page.get_by_role('heading', name='You have been unsubscribed', exact=True)).to_be_visible()
        current = http.state(site)['campaign_queue']
        require(sum(row['status'] == 'suppressed' for row in current) == 1 and sum(row['status'] == 'queued' for row in current) == 3
                and all(row['attempts'] == 0 for row in current), 'Opt-out did not suppress only its selected waiting delivery.')
        require(not helpers.state(site).get('mailOutbox'), 'Audience browser operations created transactional mail.')
        require(not errors, 'Audience owner UI raised JavaScript errors: ' + '; '.join(errors))
        return {'viewport': viewport, 'source_date_preview': True, 'stale_preview_retry': True,
                'stale_segment_tab_rejected': True, 'queued_rules_survive_edit_delete': True,
                'explicit_append_only_requeue': True, 'public_unsubscribe_suppresses_selected_delivery': True,
                'no_horizontal_overflow_or_clipped_controls': True, 'layouts': layouts,
                'mail_mode': 'disabled', 'external_messages_sent': 0}
    except Exception:
        screenshot(page, label + '-failure')
        raise
    finally:
        context.close()


def run():
    php = shutil.which('php')
    require(php is not None, 'PHP is required for packaged audience acceptance.')
    package = load('vvx_audience_actual_package', ROOT / 'scripts/package.py')
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'), 'Evidence must stay outside tracked/customer runtime.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'), 'server': 'php -S without router fallback', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-audience-package-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary.update(build_id=built['build_id'], fresh_zip_sha256=fresh['sha256'])
        with ZipFile(archive) as zipped:
            entries = set(zipped.namelist())
            require({'app/Core/MarketingAudience.php', 'app/Core/MarketingStore.php', 'includes/marketing_admin.php',
                     'app/Views/admin/marketing-audiences.php', 'app/Views/admin/marketing-campaign-form.php'} <= entries,
                    'Actual customer ZIP is missing audience dependencies.')
            require(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'newsletter/')) for name in entries), 'Package shipped fixture tools or retired root route folders.')
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for label, prefix, viewport in [('root-http', '', None), ('root-desktop', '', {'width': 1440, 'height': 1000}),
                                            ('root-mobile', '', {'width': 390, 'height': 844}),
                                            ('nested-desktop', '/nested/velvet', {'width': 1440, 'height': 1000}),
                                            ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})]:
                document_root = work / label / 'www'
                site = document_root / prefix.lstrip('/')
                site.mkdir(parents=True)
                with ZipFile(archive) as zipped:
                    zipped.extractall(site)
                (site / '.vvx-test-runtime').write_text('Disposable packaged newsletter audience acceptance.\n')
                with socket.socket() as available:
                    available.bind(('127.0.0.1', 0))
                    port = available.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
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
                        summary['cases'][label] = http.run_http(site, base, php) if viewport is None else browser_case(browser, php, site, base, label, viewport)
                        require(all((site / name).read_bytes() == data for name, data in preserved.items()), 'Audience acceptance changed disabled-mail configuration or installation identity.')
                        require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()), 'Audience HTTP produced PHP diagnostics.')
                        print('Newsletter audiences ' + label + ': PASS (actual customer package; disabled mail).', flush=True)
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_path, EVIDENCE / ('marketing-audiences-' + label + '-http.log'))
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'marketing-audiences-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Newsletter audience owner HTTP and packaged desktop/mobile acceptance passed. External messages sent: 0.', flush=True)


if __name__ == '__main__':
    run()
