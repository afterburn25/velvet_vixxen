#!/usr/bin/env python3
"""Actual owner Campaign UI and disabled-public-route behavior in customer ZIPs.

Mail stays disabled throughout. Reserved synthetic contacts are created through
the CLI fixture in a marked temporary package. SMTP delivery/concurrency is
covered separately; this suite never sends an external message.
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
spec = importlib.util.spec_from_file_location('vvx_campaign_browser_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
assert spec is not None and spec.loader is not None
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)
require = helpers.require
EVIDENCE = helpers.EVIDENCE
FIXTURE = ROOT / 'tests/Http/campaign_delivery_fixture.php'


def marketing(site: Path) -> dict:
    return json.loads((site / 'storage/private/marketing/data.json').read_text())


def fixture(php: str, site: Path, action: str, values: dict) -> dict:
    output = subprocess.check_output([php, str(FIXTURE), str(site), action], input=json.dumps(values), text=True)
    return json.loads(output)


def form(page, action: str):
    return page.locator(f'form:has(input[name="action"][value="{action}"])')


def campaign(page, identifier: str):
    return page.locator(f'[data-campaign-id="{identifier}"]')


def submit(page, button, label: str) -> None:
    with page.expect_navigation(wait_until='networkidle') as navigation:
        button.click()
    require(navigation.value is not None and navigation.value.status == 200,
            f'{label} did not reach the real owner action handler.')
    expect(page.locator('.admin-alert.error')).to_have_count(0)


def switches(page, signup: bool, delivery: bool) -> None:
    current = form(page, 'save_marketing_settings')
    current.locator('[name="newsletter_signup_enabled"]').set_checked(signup)
    current.locator('[name="campaign_delivery_enabled"]').set_checked(delivery)
    submit(page, current.get_by_role('button', name='Save Marketing Settings', exact=True), 'Marketing switches')
    expect(current.locator('[name="newsletter_signup_enabled"]')).to_be_checked(checked=signup)
    expect(current.locator('[name="campaign_delivery_enabled"]')).to_be_checked(checked=delivery)


def screenshot(page, name: str, target=None) -> None:
    if EVIDENCE:
        output = str(EVIDENCE / f'campaign-delivery-{name}.png')
        if target is not None:
            target.screenshot(path=output)
        else:
            page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
            page.screenshot(path=output, full_page=True)


def browser_case(browser, site: Path, base: str, label: str, viewport: dict, contacts: list[dict]) -> dict:
    context = browser.new_context(viewport=viewport)
    visitor = browser.new_context(viewport=viewport)
    errors = []
    for current in (context, visitor):
        current.on('page', lambda page: page.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()
    public = visitor.new_page()
    owner_url = base + '/admin/?page=marketing&tab=campaigns'
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill('owner@example.test')
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        page.goto(owner_url, wait_until='networkidle')
        expect(page.locator('[data-campaign-transport-status]')).to_contain_text('Email delivery is disabled')
        expect(page.get_by_role('button', name='Run Due Campaign Batch', exact=True)).to_be_disabled()
        create = form(page, 'save_campaign')
        subject = f'Campaign <layout> & {label}'
        body = 'A synthetic campaign for browser acceptance.\nOnly reserved fixture addresses are queued.'
        create.locator('[name="subject"]').fill(subject)
        create.locator('[name="preheader"]').fill('Actual owner form, mail disabled.')
        create.locator('[name="body"]').fill(body)
        create.locator('[name="scheduled_at"]').fill('2030-01-15T18:30')
        create.locator('[name="queue"]').check()
        screenshot(page, f'{label}-new-campaign', create)
        submit(page, create.get_by_role('button', name='Save Campaign', exact=True), 'Save Campaign')
        saved = marketing(site)
        require(len(saved['campaigns']) == 1, 'The actual owner form did not save exactly one campaign.')
        record = saved['campaigns'][0]
        identifier = record['id']
        # Native form submission serializes textarea newlines as CRLF.
        require(record['subject'] == subject and record['body'].replace('\r\n', '\n') == body
                and record['preheader'] == 'Actual owner form, mail disabled.'
                and record['scheduled_at'] == '2030-01-15T18:30:00+00:00',
                'Campaign subject, preheader, message or UTC schedule was not preserved: '
                + repr({key: record.get(key) for key in ('subject', 'body', 'preheader', 'scheduled_at')}))
        require(len(saved['campaign_queue']) == 2
                and all(row['status'] == 'queued' and row['attempts'] == 0 for row in saved['campaign_queue']),
                'The owner form queued someone other than the two confirmed, consented contacts.')
        row = campaign(page, identifier)
        expect(row.get_by_role('heading', name=subject, exact=True)).to_be_visible()
        expect(row.locator('layout')).to_have_count(0)
        expect(row.locator('[data-campaign-counts]')).to_contain_text('2 queued · 0 processing · 0 accepted')
        # A normal refresh after Save must not resubmit creation/queue a second
        # campaign. The real navigation must finish on a GET response.
        page.reload(wait_until='networkidle')
        require(len(marketing(site)['campaigns']) == 1 and len(marketing(site)['campaign_queue']) == 2,
                'Refreshing the saved campaign resubmitted creation and duplicated queued campaign work.')
        expect(campaign(page, identifier)).to_have_count(1)
        before_queue = copy.deepcopy(marketing(site)['campaign_queue'])
        submit(page, row.get_by_role('button', name='Queue Recipients', exact=True), 'Queue Recipients')
        require(marketing(site)['campaign_queue'] == before_queue, 'Repeated Queue Recipients duplicated or rewrote deliveries.')
        submit(page, row.get_by_role('button', name='Pause Campaign', exact=True), 'Pause Campaign')
        expect(row.get_by_role('button', name='Resume Campaign', exact=True)).to_be_visible()
        require(marketing(site)['campaigns'][0]['delivery_paused'] is True, 'Pause did not persist.')
        page.reload(wait_until='networkidle')
        expect(row.get_by_role('button', name='Resume Campaign', exact=True)).to_be_visible()
        submit(page, row.get_by_role('button', name='Resume Campaign', exact=True), 'Resume Campaign')
        require(marketing(site)['campaigns'][0]['delivery_paused'] is False
                and marketing(site)['campaigns'][0]['scheduled_at'] == record['scheduled_at'],
                'Resume did not persist or removed the campaign schedule.')
        screenshot(page, f'{label}-queued-campaign', row)

        # Disabled UI is backed by the real handler, even if a stale/malicious
        # client submits its protected form directly. Mail remains disabled.
        before_held = copy.deepcopy(marketing(site)['campaign_queue'])
        csrf = form(page, 'run_campaign_batch').locator('[name="_csrf"]').input_value()
        held = context.request.post(owner_url, form={'_csrf': csrf, 'action': 'run_campaign_batch', 'batch_limit': '3'})
        require(held.status == 200 and 'Email delivery is disabled' in held.text(),
                'The protected run handler did not report its disabled-mail hold.')
        require(marketing(site)['campaign_queue'] == before_held, 'Disabled mail consumed or rewrote a queued delivery attempt.')
        require(not helpers.state(site).get('mailOutbox'), 'Campaign UI created transactional messages while mail was disabled.')

        switches(page, False, True)
        require(marketing(site)['settings'] == {'newsletter_signup_enabled': False, 'campaign_delivery_enabled': True},
                'Disabling public signup also changed campaign delivery.')
        pending_before = next(item for item in marketing(site)['contacts'] if item['id'] == contacts[2]['contact']['id'])
        for path in ('newsletter/', 'newsletter/confirm.php?token=' + contacts[2]['token']):
            response = public.goto(helpers.route_url(base, path), wait_until='networkidle')
            require(response is not None and response.status == 404, 'A disabled signup/confirmation page remained available.')
            require(public.locator('body').inner_text().strip() == 'Not found.', 'Disabled newsletter leaked its public form or contact state.')
        require(next(item for item in marketing(site)['contacts'] if item['id'] == contacts[2]['contact']['id']) == pending_before,
                'A disabled confirmation link changed its pending contact.')

        switches(page, True, False)
        require(marketing(site)['settings'] == {'newsletter_signup_enabled': True, 'campaign_delivery_enabled': False},
                'Pausing campaign delivery also disabled public signup.')
        response = public.goto(helpers.route_url(base, 'newsletter/'), wait_until='networkidle')
        require(response is not None and response.status == 200, 'Public signup did not remain available while campaign delivery was paused.')
        expect(public.get_by_role('button', name='Subscribe', exact=True)).to_be_visible()
        expect(page.locator('[data-campaign-transport-status]')).to_contain_text('Campaign email delivery is paused')
        expect(page.get_by_role('button', name='Run Due Campaign Batch', exact=True)).to_be_disabled()
        switches(page, False, False)
        page.reload(wait_until='networkidle')
        expect(form(page, 'save_marketing_settings').locator('[name="newsletter_signup_enabled"]')).not_to_be_checked()
        expect(form(page, 'save_marketing_settings').locator('[name="campaign_delivery_enabled"]')).not_to_be_checked()
        screenshot(page, f'{label}-independent-switches', page.locator('[data-campaign-delivery]'))

        unsubscribe = helpers.route_url(base, 'newsletter/unsubscribe.php?token=' + contacts[0]['contact']['unsubscribe_token'])
        response = public.goto(unsubscribe, wait_until='networkidle')
        require(response is not None and response.status == 200, 'Disabling signup/delivery disabled an existing unsubscribe link.')
        expect(public.get_by_role('heading', name='You have been unsubscribed', exact=True)).to_be_visible()
        unsubscribed = marketing(site)
        require(next(item for item in unsubscribed['contacts'] if item['id'] == contacts[0]['contact']['id'])['status'] == 'unsubscribed',
                'The real unsubscribe page did not persist the contact opt-out.')
        require(sum(item['status'] == 'suppressed' for item in unsubscribed['campaign_queue']) == 1
                and sum(item['status'] == 'queued' for item in unsubscribed['campaign_queue']) == 1
                and all(item['attempts'] == 0 for item in unsubscribed['campaign_queue']),
                'Unsubscribe did not immediately stop the waiting recipient while preserving unrelated queued work.')
        screenshot(public, f'{label}-unsubscribe')
        page.goto(owner_url, wait_until='networkidle')
        expect(row.locator('[data-campaign-counts]')).to_contain_text('1 queued · 0 processing · 0 accepted')
        expect(row.locator('[data-campaign-counts]')).to_contain_text('1 suppressed')
        width = page.evaluate('({content: document.documentElement.scrollWidth, viewport: window.innerWidth})')
        require(width['content'] <= width['viewport'] + 1,
                f'{label} Campaign administration overflows horizontally: {width}')
        require(not errors, 'Campaign browser behavior raised uncaught JavaScript errors: ' + '; '.join(errors))
        return {
            'viewport': viewport, 'real_campaign_save_and_confirmed_queue': True,
            'subject_escaping_message_schedule_preserved': True, 'refresh_does_not_duplicate_campaign': True,
            'queue_idempotence': True, 'pause_resume_persist_and_preserve_schedule': True,
            'disabled_batch_ui_and_server_hold_zero_attempts': True, 'independent_switches_persist': True,
            'signup_confirmation_404_without_mutation_when_disabled': True,
            'signup_available_with_campaign_delivery_paused': True,
            'unsubscribe_available_with_both_disabled_and_waiting_email_suppressed': True,
            'no_horizontal_overflow': True, 'uncaught_javascript_errors': errors,
            'external_messages_sent': 0,
        }
    except Exception:
        screenshot(page, f'{label}-failure-admin')
        screenshot(public, f'{label}-failure-public')
        raise
    finally:
        visitor.close()
        context.close()


def run() -> None:
    php = shutil.which('php')
    require(php is not None and FIXTURE.is_file(), 'PHP and the isolated campaign contact fixture are required.')
    package_spec = importlib.util.spec_from_file_location('vvx_campaign_ui_package', ROOT / 'scripts/package.py')
    require(package_spec is not None and package_spec.loader is not None, 'Actual runtime package builder is missing.')
    package = importlib.util.module_from_spec(package_spec)
    package_spec.loader.exec_module(package)
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Campaign screenshots/logs must stay outside customer runtime and tracked source.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'),
               'server': 'php -S without any router script', 'mail_mode': 'disabled', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-campaign-ui-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary.update({'build_id': built['build_id'], 'fresh_zip_sha256': fresh['sha256']})
        with ZipFile(archive) as zipped:
            entries = set(zipped.namelist())
            require({'route.php', 'app/Core/CampaignDeliveryService.php', 'public/routes/newsletter/unsubscribe.php'} <= entries,
                    'The customer ZIP is missing actual campaign/newsletter runtime dependencies.')
            require(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'newsletter/')) for name in entries),
                    'Campaign packaging restored rejected root routes or shipped engineering files.')
        options = {'headless': True}
        executable = os.environ.get('VELVET_CHROMIUM') or os.environ.get('VV_TEST_CHROME')
        if executable:
            options['executable_path'] = executable
        browser = playwright.chromium.launch(**options)
        summary['browser'] = browser.version
        try:
            for label, prefix, viewport in [('root-desktop', '', {'width': 1440, 'height': 1000}),
                                            ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})]:
                document_root = work / label / 'www'
                site = document_root / prefix.lstrip('/')
                site.mkdir(parents=True)
                with ZipFile(archive) as zipped:
                    zipped.extractall(site)
                (site / '.vvx-test-runtime').write_text('Disposable packaged Campaign UI acceptance.\n')
                with socket.socket() as listener:
                    listener.bind(('127.0.0.1', 0))
                    port = listener.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                contacts = [fixture(php, site, 'contact', {'email': email, 'confirmed': confirmed})
                            for email, confirmed in [('first@example.test', True), ('second@example.test', True), ('pending@example.test', False)]]
                preserved = {name: (site / name).read_bytes() for name in ('config/app.php', 'storage/install.lock')}
                owners = helpers.state(site)['admins']
                environment = os.environ.copy()
                environment.pop('PHP_CLI_SERVER_WORKERS', None)
                log_file = work / label / 'php-server.log'
                with log_file.open('w+') as log:
                    process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                                                '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                               cwd=work, env=environment, stdout=log, stderr=log)
                    try:
                        session = helpers.requests_session()
                        deadline = time.monotonic() + 15
                        while True:
                            require(process.poll() is None, 'Disposable PHP server exited before becoming ready.')
                            try:
                                if session.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                    break
                            except requests.RequestException:
                                pass
                            require(time.monotonic() < deadline, 'Disposable PHP server did not become ready.')
                            time.sleep(0.1)
                        session.close()
                        summary['cases'][label] = browser_case(browser, site, base, label, viewport, contacts)
                        require(all((site / name).read_bytes() == value for name, value in preserved.items())
                                and helpers.state(site)['admins'] == owners,
                                'Campaign UI changed the disabled-mail configuration, installation identity or owner.')
                        require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_file.read_text()),
                                'Campaign UI execution produced PHP diagnostics.')
                        print(f'Campaign delivery UI {label}: PASS (real owner forms, queue/pause/resume, disabled-mail hold, '
                              'independent switches, disabled signup and available unsubscribe).', flush=True)
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_file, EVIDENCE / f'campaign-delivery-{label}-http.log')
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'campaign-delivery-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Campaign desktop/mobile owner and public-switch acceptance passed with disabled mail in actual root/nested customer packages.', flush=True)


if __name__ == '__main__':
    run()
