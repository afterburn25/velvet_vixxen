#!/usr/bin/env python3
"""Real Email & SMTP owner workflow in extracted root/nested customer ZIPs.

Only marked temporary sites, reserved accounts and loopback SMTP capture are
used. There is no mocked UI, development router or external message delivery.
"""
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
import threading
import time
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


helpers = load('vvx_mail_package_helpers', ROOT / 'tests/Browser/packaged_media_upload_playwright.py')
mail_http = load('vvx_mail_http_helpers', ROOT / 'tests/Http/mail_settings_http.py')
require = helpers.require
EVIDENCE = helpers.EVIDENCE


def screenshot(page, label: str) -> None:
    if EVIDENCE:
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        page.screenshot(path=str(EVIDENCE / f'mail-settings-{label}.png'), full_page=True)


def submit(page, button, label: str) -> None:
    with page.expect_navigation(wait_until='networkidle') as navigation:
        button.click()
    require(navigation.value is not None and navigation.value.status == 200,
            label + ' did not complete through the actual owner action.')
    expect(page.locator('.admin-alert.error')).to_have_count(0)


def no_overflow(page, label: str) -> None:
    geometry = page.evaluate('({content: document.documentElement.scrollWidth, viewport: innerWidth})')
    require(geometry['content'] <= geometry['viewport'] + 1, label + ' overflows horizontally: ' + repr(geometry))


def browser_case(browser, site: Path, base: str, label: str, viewport: dict, capture) -> dict:
    initial = mail_http.fixture(site, 'read')
    installed_lock = (site / 'storage/install.lock').read_bytes()
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill(mail_http.OWNER)
        page.get_by_label('Password', exact=True).fill('VelvetDemo12')
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        if viewport['width'] < 900:
            page.get_by_role('button', name='Toggle menu', exact=True).click()
        group = page.locator('[data-admin-group="system"]')
        if group.get_attribute('open') is None:
            group.locator('summary').click()
        link = group.get_by_role('link', name='Email & SMTP', exact=True)
        require(link.get_attribute('href') == base + '/admin/?page=mail-settings',
                'Email settings navigation lost its System group or installation prefix.')
        link.click()
        page.wait_for_url('**/admin/?page=mail-settings')
        expect(page.get_by_role('heading', name='Email & SMTP', exact=True)).to_be_visible()
        expect(page.locator('[data-mail-saved-mode]')).to_have_text('Delivery disabled')
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        expect(page.locator('[data-mail-smtp]')).to_be_hidden()
        no_overflow(page, label + ' disabled state')
        screenshot(page, label + '-disabled')

        editor = page.locator('[data-mail-editor]')
        editor.locator('[name="mode"]').select_option('smtp')
        expect(page.locator('[data-mail-smtp]')).to_be_visible()
        editor.locator('[name="host"]').fill('127.0.0.1')
        editor.locator('[name="port"]').fill(str(capture.server_address[1]))
        editor.locator('[name="encryption"]').select_option('none')
        editor.locator('[name="username"]').fill(mail_http.SMTP_USERNAME)
        editor.locator('[name="password"]').fill(mail_http.FIXTURE_SMTP_PASSWORD)
        editor.locator('[name="from_email"]').fill('')
        editor.locator('[name="from_name"]').fill('Fixture <owner> & Mail')
        expect(page.locator('[data-mail-unsaved]')).to_contain_text('unsaved changes')
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        require(capture.connections == 0, 'Editing SMTP fields connected to a server.')
        submit(page, page.locator('[data-mail-save]'), 'Save SMTP settings')
        expect(page.locator('[data-mail-saved-mode]')).to_have_text('SMTP server')
        expect(editor.locator('[name="from_email"]')).to_have_value(mail_http.SMTP_USERNAME)
        expect(editor.locator('[name="password"]')).to_have_value('')
        expect(editor.locator('[name="password"]')).to_have_attribute('placeholder', 'Password saved — leave blank to keep it')
        expect(page.locator('[data-mail-test]')).to_be_enabled()
        require(mail_http.FIXTURE_SMTP_PASSWORD not in page.content() and 'vvx1:' not in page.content()
                and page.locator('owner').count() == 0, 'Saved password/ciphertext or unescaped sender markup appeared in the page.')
        require(capture.connections == 0, 'Saving settings sent a test email as a side effect.')
        saved = mail_http.fixture(site, 'read')
        require({key: value for key, value in saved['config'].items() if key != 'mail'}
                == {key: value for key, value in initial['config'].items() if key != 'mail'}
                and saved['config']['mail']['fixture_future_mail_option'] == {'retained': True},
                'The real owner form replaced unrelated configuration or future mail fields.')
        page.reload(wait_until='networkidle')
        expect(editor.locator('[name="host"]')).to_have_value('127.0.0.1')
        expect(editor.locator('[name="password"]')).to_have_value('')
        expect(page.locator('[data-mail-test]')).to_be_enabled()
        no_overflow(page, label + ' SMTP state')
        screenshot(page, label + '-saved-smtp')

        # A real browser edit must prevent sending stale saved settings until
        # Save has finished, including the persistent pageshow handler.
        editor.locator('[name="from_name"]').fill('Retained fixture connection')
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        page.evaluate('window.dispatchEvent(new PageTransitionEvent("pageshow", {persisted: true}))')
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        submit(page, page.locator('[data-mail-save]'), 'Save with retained password')
        expect(editor.locator('[name="password"]')).to_have_value('')
        require(capture.connections == 0, 'Saving retained credentials sent a message.')
        submit(page, page.locator('[data-mail-test]'), 'Explicit local owner test')
        expect(page.locator('[data-mail-test-result]')).to_contain_text('Test accepted')
        require(len(capture.payloads) == 1 and capture.authentications[-1]
                == (mail_http.SMTP_USERNAME, mail_http.FIXTURE_SMTP_PASSWORD),
                'The actual owner button did not authenticate using its saved password.')
        require(capture.payloads[-1]['recipient'] == mail_http.OWNER
                and mail_http.FIXTURE_SMTP_PASSWORD not in capture.payloads[-1]['body'],
                'The real browser test sent to another recipient or included credentials.')
        require('check your' in page.locator('[data-mail-test-result]').inner_text().lower(),
                'The result confused transport acceptance with proven inbox receipt.')
        page.reload(wait_until='networkidle')
        require(len(capture.payloads) == 1, 'Refreshing the successful test resent an email.')
        no_overflow(page, label + ' test result')
        screenshot(page, label + '-test-accepted')

        editor.locator('[name="username"]').fill('')
        editor.locator('[name="from_email"]').fill('')
        editor.locator('[name="clear_password"]').check()
        expect(editor.locator('[name="password"]')).to_have_value('')
        submit(page, page.locator('[data-mail-save]'), 'Remove saved credential')
        expect(page.locator('[data-mail-test-result]')).to_have_count(0)
        expect(page.locator('#mail-password-help')).to_contain_text('No SMTP password is saved.')
        expect(editor.locator('[name="from_email"]')).to_have_value(mail_http.OWNER)
        require(len(capture.payloads) == 1, 'Clearing a saved password sent email.')

        editor.locator('[name="mode"]').select_option('php')
        expect(page.locator('[data-mail-smtp]')).to_be_hidden()
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        submit(page, page.locator('[data-mail-save]'), 'Save PHP mail selection without sending')
        expect(page.locator('[data-mail-saved-mode]')).to_have_text('PHP mail')
        require(len(capture.payloads) == 1, 'Changing delivery method sent email.')
        editor.locator('[name="mode"]').select_option('disabled')
        editor.locator('[name="from_email"]').fill('')
        submit(page, page.locator('[data-mail-save]'), 'Disable email delivery')
        expect(page.locator('[data-mail-saved-mode]')).to_have_text('Delivery disabled')
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        expect(page.locator('[data-mail-smtp]')).to_be_hidden()
        page.reload(wait_until='networkidle')
        expect(editor.locator('[name="mode"]')).to_have_value('disabled')
        expect(page.locator('[data-mail-test]')).to_be_disabled()
        no_overflow(page, label + ' final disabled state')
        final = mail_http.fixture(site, 'read')
        require(final['owner'] == initial['owner'] and final['mailOutbox'] == initial['mailOutbox']
                and (site / 'storage/install.lock').read_bytes() == installed_lock,
                'Email UI changed owner identity, member mail outbox or installation lock.')
        require(not capture.errors and not errors, 'Real owner email workflow raised SMTP/JavaScript errors: ' + repr(capture.errors + errors))
        return {'viewport': viewport, 'system_navigation': True, 'saved_smtp_and_sender_fallback': True,
                'no_send_on_save': True, 'password_blank_retains_and_explicit_clear': True,
                'no_credential_echo': True, 'dirty_form_disables_test': True,
                'real_saved_credential_authentication_and_owner_only_test': True,
                'refresh_does_not_resend': True, 'php_mail_selected_without_sending': True,
                'saved_change_clears_previous_test_success': True,
                'disabled_delivery_persists': True, 'configuration_identity_preserved': True,
                'no_horizontal_overflow': True, 'uncaught_javascript_errors': errors,
                'local_capture_messages': len(capture.payloads), 'external_messages_sent': 0}
    except Exception:
        screenshot(page, label + '-failure')
        raise
    finally:
        context.close()


def run() -> None:
    php = shutil.which('php')
    require(php is not None, 'PHP is required for real packaged mail acceptance.')
    package = load('vvx_mail_actual_package', ROOT / 'scripts/package.py')
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Screenshots and logs must remain outside tracked/runtime source.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'),
               'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-mail-ui-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        summary.update({'build_id': built['build_id'], 'fresh_zip_sha256': fresh['sha256']})
        with ZipFile(archive) as zipped:
            entries = set(zipped.namelist())
            require({'admin/index.php', 'app/Core/MailSettingsService.php', 'app/Core/EmailAddress.php',
                     'app/Views/admin/mail-settings.php', 'app/Views/admin/mail-settings-actions.php',
                     'app/Support/mail_settings_admin.php', 'public/assets/js/mail-settings.js',
                     'public/assets/css/mail-settings.css'} <= entries, 'Actual package is missing mail editor dependencies.')
            require(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'mail/', 'api/')) for name in entries),
                    'Customer package shipped test fixtures or restored rejected root folders.')
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
                (site / '.vvx-test-runtime').write_text('Disposable packaged email settings acceptance.\n')
                with socket.socket() as available:
                    available.bind(('127.0.0.1', 0))
                    port = available.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                subprocess.run([php, str(helpers.FIXTURE), str(site), 'setup', base], check=True, capture_output=True, text=True)
                mail_http.fixture(site, 'initialize')
                capture = mail_http.Capture()
                thread = threading.Thread(target=capture.serve_forever, daemon=True)
                thread.start()
                log_path = work / label / 'php-server.log'
                with log_path.open('w+') as log:
                    environment = os.environ.copy()
                    environment.pop('PHP_CLI_SERVER_WORKERS', None)
                    process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                                                '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                               cwd=work, env=environment, stdout=log, stderr=log)
                    try:
                        client = mail_http.session()
                        deadline = time.monotonic() + 15
                        while True:
                            require(process.poll() is None, 'Disposable packaged PHP server exited.')
                            try:
                                if client.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                    break
                            except requests.RequestException:
                                pass
                            require(time.monotonic() < deadline, 'Packaged PHP server did not start.')
                            time.sleep(.1)
                        client.close()
                        summary['cases'][label] = browser_case(browser, site, base, label, viewport, capture)
                        log.flush()
                        require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()),
                                'Mail owner UI produced PHP diagnostics.')
                        print(f'Mail settings {label}: PASS (actual package, owner forms, credentials, local SMTP test, no overflow).', flush=True)
                    finally:
                        capture.release.set()
                        capture.shutdown()
                        capture.server_close()
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)
                        if EVIDENCE:
                            shutil.copyfile(log_path, EVIDENCE / f'mail-settings-{label}-http.log')
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'mail-settings-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Mail settings desktop/mobile acceptance passed in actual root/nested packages. External messages sent: 0.', flush=True)


if __name__ == '__main__':
    run()
