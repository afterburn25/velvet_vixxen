#!/usr/bin/env python3
"""Actual Fresh-package Marketing Storage on MySQL: root desktop/nested mobile.

The real installer, owner login, Database Updates, preview, move and later
marketing edit run against a private MySQL 8 fixture. A loopback SMTP capture
never relays messages and must receive zero connections throughout the move.
"""
from __future__ import annotations

import hashlib
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
from urllib.parse import parse_qs
from zipfile import ZipFile

import requests
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
EVIDENCE = Path(os.environ['VELVET_SCREENSHOT_DIR']).resolve() if os.environ.get('VELVET_SCREENSHOT_DIR') else None
sys.dont_write_bytecode = True


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


http = load('vvx_marketing_storage_http', ROOT / 'tests/Http/marketing_storage_http.py')
smtp = load('vvx_marketing_storage_smtp', ROOT / 'tests/Http/mail_settings_http.py')
require = http.check
UI_FILES = ('admin/index.php', 'app/Support/marketing_storage_admin.php',
            'app/Views/admin/marketing-storage-actions.php', 'app/Views/admin/marketing-storage.php',
            'app/Views/admin/navigation.php', 'app/Views/admin/marketing.php')


def screenshot(page, label: str):
    if EVIDENCE:
        page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
        page.screenshot(path=str(EVIDENCE / ('marketing-storage-' + label + '.png')), full_page=True)


def geometry(page, label: str):
    measured = page.evaluate('''() => ({
      content: document.documentElement.scrollWidth, viewport: innerWidth,
      controls: [...document.querySelectorAll('.admin-content .admin-button')].map(element => {
        const box = element.getBoundingClientRect();
        return {text: element.textContent.trim(), left: box.left, right: box.right, width: box.width, height: box.height};
      })
    })''')
    require(measured['content'] <= measured['viewport'] + 1, label + ' overflows horizontally: ' + repr(measured))
    require(all(control['height'] >= 42 and control['width'] >= 80 and control['left'] >= -1
                and control['right'] <= measured['viewport'] + 1 for control in measured['controls']),
            label + ' has a clipped or undersized action: ' + repr(measured))
    return measured


def keyboard_focus(page, control):
    control.focus()
    page.keyboard.press('Shift+Tab')
    page.keyboard.press('Tab')
    require(control.evaluate('(element) => document.activeElement === element && element.matches(":focus-visible")'),
            'The owner action cannot be reached with visible keyboard focus.')


def storage_navigation(page, base: str, viewport: dict):
    if viewport['width'] < 900 and not page.locator('body').evaluate('(element) => element.classList.contains("sidebar-open")'):
        page.get_by_role('button', name='Toggle menu', exact=True).click()
    group = page.locator('[data-admin-group="system"]')
    if group.get_attribute('open') is None:
        group.locator('summary').click()
    link = group.get_by_role('link', name='Marketing Storage', exact=True)
    require(link.get_attribute('href') == base + '/admin/?page=marketing-storage',
            'System navigation lost the installation prefix.')
    link.click()
    page.wait_for_url(base + '/admin/?page=marketing-storage')
    expect(page.get_by_role('heading', name='Marketing Storage', exact=True)).to_be_visible()
    expect(page.locator('[data-admin-group="system"] a[aria-current="page"]')).to_have_attribute('href', base + '/admin/?page=marketing-storage')


def submit(page, control, label: str, expected=200, redirect=False):
    with page.expect_navigation(wait_until='networkidle') as navigation:
        control.click()
    response = navigation.value
    require(response is not None and response.status == expected, label + ' returned an unexpected HTTP status.')
    if redirect:
        require(response.request.method == 'GET' and response.request.redirected_from is not None
                and response.request.redirected_from.method == 'POST', label + ' did not use POST/Redirect/GET.')
    else:
        require(response.request.method == 'POST', label + ' did not submit the real owner form.')
    if expected >= 400:
        expect(page.locator('.admin-alert.error')).to_be_visible()
    else:
        require(page.locator('.admin-alert.error').count() == 0,
                label + ' returned an owner error: ' + ' '.join(page.locator('.admin-alert.error').all_inner_texts()))


def independent_sql_snapshot(saved: dict):
    """Reconstruct fixture rows from independent PDO output, not domain returns."""
    states = saved['sql'].get('marketing_storage_state', [])
    require(len(states) == 1 and states[0]['status'] == 'active' and len(saved['sql']['authority']) == 1,
            'The move did not commit one active SQL state and authority record.')
    state = states[0]
    header = json.loads(state['header_json'])
    layout = json.loads(state['layout_json'])
    records = saved['sql']['marketing_storage_records']
    collections = {}
    for collection in http.COLLECTIONS:
        rows = sorted((row for row in records if row['collection'] == collection), key=lambda row: int(row['position']))
        require([int(row['position']) for row in rows] == list(range(len(rows))), 'SQL record positions are not contiguous.')
        require([json.loads(row['entry_key_json']) for row in rows] == list(range(len(rows))),
                'The fixture collection keys or original ordering changed in MySQL.')
        collections[collection] = [json.loads(row['payload_json']) for row in rows]
    reconstructed = {key: collections[key] if key in collections else header[key] for key in layout}
    require(reconstructed == saved['marketing'] and list(reconstructed) == list(saved['marketing']),
            'Independent SQL rows/header/layout do not reproduce the exact saved marketing snapshot.')
    require(len(records) == sum(saved['counts'].values()) and int(state['revision']) == saved['marketing']['meta']['revision'],
            'Committed SQL record count or revision differs from the independent domain read.')
    require(state['source_sha256'] == saved['legacy_sha256'], 'The preserved original file differs from the committed migration source.')
    return reconstructed


def browser_case(browser, site: Path, base: str, label: str, viewport: dict, capture, php: str):
    initial = http.fixture(site, 'read', php)
    require(initial['status']['state'] == 'schema_needed' and initial['ledger'] == list(http.PRE015_MIGRATIONS),
            'The browser must start on the explicit pre015 Database Updates path.')
    pending_count = len(http.PACKAGED_MIGRATIONS) - len(http.PRE015_MIGRATIONS)
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors, actions = [], []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def record_action(request):
        if request.method == 'POST':
            action = parse_qs(request.post_data or '').get('action', [])
            if action:
                actions.append(action[0])

    page.on('request', record_action)
    try:
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill(http.OWNER)
        page.get_by_label('Password', exact=True).fill(http.PASSWORD)
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url('**/admin/')
        storage_navigation(page, base, viewport)
        expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'schema_needed')
        expect(page.locator('[data-storage-preview-form], [data-storage-migrate-form]')).to_have_count(0)
        http.privacy(page.content(), site)
        measured_missing = geometry(page, label + ' Database Updates guidance')
        database_link = page.get_by_role('link', name='Open Database Updates', exact=True)
        require(database_link.get_attribute('href') == base + '/admin/?page=database-updates', 'The recovery link lost the nested installation prefix.')
        keyboard_focus(page, database_link)
        screenshot(page, label + '-database-update-needed')
        database_link.click()
        page.wait_for_url('**/admin/?page=database-updates')
        expect(page.get_by_role('heading', name=str(pending_count) + ' pending updates', exact=True)).to_be_visible()
        updater = page.locator('form:has(input[name="action"][value="apply_database_updates"])')
        updater.locator('[name="backup_reference"]').fill('Reserved isolated MySQL fixture backup — ' + label)
        updater.locator('[name="backup_confirmed"]').check()
        submit(page, updater.get_by_role('button', name='Apply ' + str(pending_count) + ' Pending Updates', exact=True), 'Apply packaged database update', redirect=True)
        expect(page.locator('.admin-alert.success').first).to_contain_text(str(pending_count) + ' database update(s) applied')
        updated = http.fixture(site, 'read', php)
        require(updated['status']['state'] == 'ready' and updated['ledger'] == list(http.PACKAGED_MIGRATIONS),
                'The actual owner Database Updates POST did not apply every pending packaged migration.')
        require(updated['marketing'] == initial['marketing'] and updated['protected'] == initial['protected']
                and updated['outbox'] == initial['outbox'] and updated['unrelated'] == initial['unrelated'],
                'The database update altered existing marketing, configuration, backups, outbox or unrelated rows.')

        storage_navigation(page, base, viewport)
        expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'ready')
        expect(page.locator('[data-storage-counts], input[name="plan"]')).to_have_count(0)
        before_preview = http.snapshot(site, php)
        preview = page.get_by_role('button', name='Preview Move', exact=True)
        keyboard_focus(page, preview)
        submit(page, preview, 'Preview the actual marketing move')
        http.assert_counts(page.content(), updated['counts'])
        http.privacy(page.content(), site)
        require(http.snapshot(site, php) == before_preview, 'The browser preview changed committed records or source files.')
        measured_review = geometry(page, label + ' reviewed records')
        keyboard_focus(page, page.get_by_role('button', name='Move to MySQL', exact=True))
        screenshot(page, label + '-review')

        http.fixture(site, 'change-source', php)
        changed = http.fixture(site, 'read', php)
        stale_baseline = http.snapshot(site, php)
        submit(page, page.get_by_role('button', name='Move to MySQL', exact=True), 'Reject a stale browser review', expected=409)
        expect(page.locator('[data-storage-migrate-form]')).to_have_count(0)
        expect(page.get_by_role('button', name='Preview Move', exact=True)).to_be_visible()
        require(http.snapshot(site, php) == stale_baseline, 'The rejected stale browser tab changed source or SQL records.')
        http.privacy(page.content(), site)

        submit(page, page.get_by_role('button', name='Preview Move', exact=True), 'Review the changed records')
        http.assert_counts(page.content(), changed['counts'])
        move_form = page.locator('[data-storage-migrate-form]')
        repeated_fields = {name: move_form.locator('[name="' + name + '"]').input_value() for name in ('_csrf', 'action', 'plan')}
        submit(page, move_form.get_by_role('button', name='Move to MySQL', exact=True), 'Move the reviewed marketing records', redirect=True)
        expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'mysql')
        expect(page.locator('.admin-alert.success')).to_contain_text('Marketing is now stored in MySQL')
        expect(page.locator('[data-storage-preview-form], [data-storage-migrate-form]')).to_have_count(0)
        migrated = http.fixture(site, 'read', php)
        require(migrated['marketing'] == changed['marketing'], 'The actual browser move lost or rewrote a saved marketing field.')
        require(migrated['legacy_sha256'] == changed['legacy_sha256'] and migrated['protected'] == initial['protected']
                and migrated['outbox'] == initial['outbox'] and migrated['unrelated'] == initial['unrelated'],
                'Moving records changed the original file, configuration, backups, outbox or unrelated records.')
        require(migrated['status']['source_preserved'] and not migrated['status']['can_migrate'],
                'The successful move did not retain its source or still offered another import.')
        independent_sql_snapshot(migrated)
        http.assert_counts(page.content(), migrated['counts'])
        http.privacy(page.content(), site)
        measured_mysql = geometry(page, label + ' active MySQL storage')
        screenshot(page, label + '-mysql-active')
        stable = http.snapshot(site, php)
        page.reload(wait_until='networkidle')
        require(http.snapshot(site, php) == stable, 'Reloading the completed move changed records or repeated the import.')
        replay = context.request.post(base + '/admin/?page=marketing-storage', form=repeated_fields)
        require(replay.status == 409 and http.snapshot(site, php) == stable, 'Replaying the original move duplicated or changed records.')

        # Exercise a normal existing owner form after SQL activation, then prove
        # its row committed to MySQL while the archived source stayed unchanged.
        page.get_by_role('link', name='Open Marketing', exact=True).click()
        page.wait_for_url('**/admin/?page=marketing')
        expect(page.get_by_role('link', name='Manage Marketing Storage', exact=True)).to_have_attribute('href', base + '/admin/?page=marketing-storage')
        page.locator('.marketing-tabs').get_by_role('link', name='Links', exact=True).click()
        page.wait_for_url('**/admin/?page=marketing&tab=links')
        link_form = page.locator('form:has(input[name="action"][value="save_link"])')
        link_form.locator('[name="label"]').fill('Browser SQL link — ' + label)
        link_form.locator('[name="url"]').fill('/')
        link_form.locator('[name="sort"]').fill('35')
        submit(page, link_form.get_by_role('button', name='Save Link', exact=True), 'Save marketing through the active SQL store', redirect=True)
        saved = http.fixture(site, 'read', php)
        require(len(saved['marketing']['links']) == len(migrated['marketing']['links']) + 1
                and saved['marketing']['links'][-1]['label'] == 'Browser SQL link — ' + label,
                'The ordinary owner Marketing form did not save after the move.')
        require(saved['marketing']['links'][:-1] == migrated['marketing']['links']
                and all(saved['marketing'][key] == migrated['marketing'][key]
                        for key in (*[key for key in http.COLLECTIONS if key != 'links'], 'settings', 'retained_legacy_header')),
                'A normal SQL marketing edit altered unrelated audiences, consent, schedules or delivery records.')
        require(saved['legacy_sha256'] == changed['legacy_sha256'] and saved['protected'] == initial['protected']
                and saved['outbox'] == initial['outbox'], 'A later SQL edit changed the preserved source, backups or outbox.')
        independent_sql_snapshot(saved)
        storage_navigation(page, base, viewport)
        http.assert_counts(page.content(), saved['counts'])
        stable = http.snapshot(site, php)
        held = http.fixture(site, 'hold-active-state', php)
        require(held['state'] == 'blocked', 'The active SQL failure probe did not hold marketing access.')
        try:
            response = page.reload(wait_until='networkidle')
            require(response is not None and response.status == 200, 'The independent owner storage page failed with its marketing table unavailable.')
            expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'blocked')
            expect(page.locator('[data-storage-preview-form], [data-storage-migrate-form]')).to_have_count(0)
            http.privacy(page.content(), site)
            geometry(page, label + ' active SQL unavailable')
            screenshot(page, label + '-sql-access-held')
            blocked = http.fixture(site, 'read', php)
            require(blocked['marketing_read_blocked'] and blocked['marketing'] is None,
                    'An unavailable active SQL store silently read the stale original file.')
            require(http.fixture(site, 'probe-blocked-write', php)['rejected'],
                    'An unavailable active SQL store silently wrote to the old file.')
            require(http.fixture(site, 'read', php)['legacy_sha256'] == stable['legacy_sha256'],
                    'The blocked write modified the preserved original file.')
        finally:
            http.fixture(site, 'restore-active-state', php)
        page.reload(wait_until='networkidle')
        expect(page.locator('[data-marketing-storage]')).to_have_attribute('data-storage-state', 'mysql')
        require(http.snapshot(site, php) == stable, 'Restoring the held fixture table lost or rewrote active SQL records.')
        require(capture.connections == 0 and not capture.payloads and not capture.errors,
                'The move or its review connected to SMTP or sent a message.')
        require(not errors, 'The owner marketing-storage browser flow raised JavaScript errors: ' + repr(errors))
        require({'apply_database_updates', 'preview_marketing_storage', 'migrate_marketing_storage', 'save_link'} <= set(actions),
                'The browser did not submit every required real owner form.')
        return {'mysql_version': saved['mysql_version'], 'real_owner_post_actions': actions,
                'missing_schema_then_actual_database_update': True, 'review_counts_and_keyboard_focus': True,
                'stale_browser_review_refused': True, 'exact_sql_import_and_independent_readback': True,
                'refresh_and_replay_safe': True, 'ordinary_marketing_form_committed_to_sql': True,
                'source_config_uploads_backups_and_outbox_preserved': True,
                'active_sql_loss_blocks_reads_and_writes_without_file_fallback': True,
                'smtp_connections': capture.connections, 'smtp_messages': len(capture.payloads),
                'record_counts_after_edit': saved['counts'], 'javascript_errors': errors,
                'geometry': {'missing_schema': measured_missing, 'review': measured_review, 'mysql': measured_mysql}}
    finally:
        context.close()


def run():
    php = shutil.which('php')
    require(php is not None, 'PHP is required for actual-package marketing storage acceptance.')
    require(os.environ.get('VELVET_TEST_DB_NAME') and os.environ.get('VELVET_TEST_DB_USER'),
            'Configure a disposable, empty MySQL 8 test database; another engine or a skipped SQL case is not a pass.')
    if EVIDENCE:
        require(not EVIDENCE.is_relative_to(ROOT) or EVIDENCE.is_relative_to(ROOT / 'artifacts'),
                'Keep screenshots and private fixture logs outside tracked/runtime source.')
        EVIDENCE.mkdir(parents=True, exist_ok=True)
    package = load('vvx_marketing_storage_package', ROOT / 'scripts/package.py')
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'), 'server': 'php -S without any router script', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-marketing-storage-ui-') as temporary, sync_playwright() as playwright:
        work = Path(temporary)
        if os.environ.get('VELVET_FRESH_PACKAGE'):
            archive = Path(os.environ['VELVET_FRESH_PACKAGE']).resolve()
            require(archive.is_file(), 'The requested actual Fresh package does not exist.')
        else:
            built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
            fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
            archive = work / 'packages' / fresh['filename']
        with ZipFile(archive) as zipped:
            files = {name: zipped.read(name) for name in zipped.namelist() if not name.endswith('/')}
        version, build_id = package.package_identity(files)
        package.validate(files, 'fresh', version)
        require(set(UI_FILES) | {'app/Core/MarketingStorage.php', 'app/Core/MarketingSqlRepository.php',
                'app/Core/MarketingStorageException.php', 'database/migrations/' + http.MIGRATION + '.php'} <= files.keys(),
                'The actual Fresh archive omitted a marketing-storage runtime dependency.')
        require(all(files[name] == (ROOT / name).read_bytes() for name in UI_FILES),
                'The supplied Fresh archive does not contain the exact owner runtime files under test.')
        require(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'api/', 'mail/')) for name in files),
                'The customer package shipped fixtures or rejected root route folders.')
        summary.update({'version': version, 'build_id': build_id, 'fresh_zip_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
                        'archive_ui_matches_sources': True})
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
                (site / '.vvx-test-runtime').write_text('Disposable packaged marketing-storage acceptance.\n')
                with socket.socket() as available:
                    available.bind(('127.0.0.1', 0)); port = available.getsockname()[1]
                base = f'http://127.0.0.1:{port}{prefix}'
                capture = smtp.Capture()
                thread = threading.Thread(target=capture.serve_forever, daemon=True)
                thread.start()
                log_path = work / label / 'php-server.log'
                process = None
                try:
                    http.fixture(site, 'setup-mysql', php, base=base, smtp_port=capture.server_address[1])
                    with log_path.open('w+') as log:
                        environment = os.environ.copy(); environment.pop('PHP_CLI_SERVER_WORKERS', None)
                        process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S',
                                                    f'127.0.0.1:{port}', '-t', str(document_root)],
                                                   cwd=work, env=environment, stdout=log, stderr=log)
                        client = http.session(); deadline = time.monotonic() + 15
                        try:
                            while True:
                                require(process.poll() is None, 'The disposable packaged PHP server exited.')
                                try:
                                    if client.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                        break
                                except requests.RequestException:
                                    pass
                                require(time.monotonic() < deadline, 'The actual packaged PHP server did not start.')
                                time.sleep(.1)
                        finally:
                            client.close()
                        http_result = http.security_and_preview_case(site, base, php)
                        summary['cases'][label] = {'viewport': viewport, 'http': http_result,
                                                  'browser': browser_case(browser, site, base, label, viewport, capture, php)}
                        require(capture.connections == 0 and not capture.payloads and not capture.errors,
                                'A storage action connected to SMTP or sent a message.')
                        log.flush()
                        require(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()),
                                'Actual package marketing-storage acceptance raised PHP diagnostics.')
                        print('Marketing Storage ' + label + ': PASS (actual ZIP/MySQL/owner POSTs, preserved records, zero SMTP).', flush=True)
                finally:
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill(); process.wait(timeout=5)
                    capture.release.set(); capture.shutdown(); capture.server_close()
                    if (site / 'storage/temp/marketing-storage-ownership.json').is_file():
                        http.fixture(site, 'cleanup-mysql', php)
                    if EVIDENCE and log_path.is_file():
                        shutil.copyfile(log_path, EVIDENCE / ('marketing-storage-' + label + '-http.log'))
        finally:
            browser.close()
    if EVIDENCE:
        (EVIDENCE / 'marketing-storage-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2), flush=True)
    print('Marketing Storage packaged acceptance passed: MySQL root-desktop and nested-mobile cases.', flush=True)


if __name__ == '__main__':
    run()
