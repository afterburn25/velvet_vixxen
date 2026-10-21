#!/usr/bin/env python3
"""Real packaged owner and member notification controls on desktop and mobile."""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile

from PIL import Image
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vvx_notification_http', ROOT / 'tests/Http/notifications_http.py')
notifications = importlib.util.module_from_spec(spec)
spec.loader.exec_module(notifications)
messages, http = notifications.messages, notifications.http


def login(page, base):
    page.goto(base + '/admin/?page=login')
    page.get_by_label('Email address', exact=True).fill(http.OWNER)
    page.get_by_label('Password', exact=True).fill(http.PASSWORD)
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url(lambda url: 'page=login' not in url)


def submit(page, control, status=200):
    control.scroll_into_view_if_needed()
    with page.expect_response(lambda response: response.request.method == 'POST' and '/admin/' in response.url) as pending:
        control.click()
    http.check(pending.value.status in ((302, 303) if status == 200 else (status,)), 'Real notification form returned an unexpected status.')
    page.wait_for_load_state('domcontentloaded')


def browser_case(browser, case, viewport, evidence):
    start = http.checks
    if case['mode'] == 'mysql':
        owner = http.owner_login(case['base'])
        try:
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        finally:
            owner.close()
    errors, images, layouts = [], [], []
    context = browser.new_context(viewport=viewport)
    context.on('page', lambda item: item.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()
    base = case['base']
    url = base + '/admin/?page=marketing&tab=notifications'
    capture = case['capture']

    def shot(label):
        geometry = page.evaluate('''() => {
            const controls=[...document.querySelectorAll('main button,main input,main select')].filter(e=>e.offsetParent && e.type!=='hidden').map(e=>{
                const target=e.type==='checkbox' ? (e.closest('label') || e) : e;
                return {name:e.name,type:e.type,height:target.getBoundingClientRect().height};
            });
            return {width:innerWidth,document:document.documentElement.scrollWidth,
                minControl:Math.min(...controls.map(e=>e.height)),controls};
        }''')
        http.check(geometry['document'] <= geometry['width'] + 1, 'Notification UI overflows horizontally: ' + label)
        http.check(geometry['minControl'] >= 30, 'Notification controls are clipped or too small: ' + label + ' ' + repr(geometry))
        data = page.screenshot(full_page=False)
        with Image.open(io.BytesIO(data)) as decoded:
            decoded.load()
            http.check(decoded.size == (viewport['width'], viewport['height']), 'Screenshot dimensions differ from the actual viewport.')
        filename = 'notifications-' + case['label'] + '-' + label + '.png'
        if evidence:
            (evidence / filename).write_bytes(data)
        images.append({'filename': filename, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)})
        layouts.append({'state': label, **geometry})

    try:
        login(page, base)
        initial = notifications.state(case)
        initial_jobs = list((initial['raw'] or {}).get('jobs', {}).values())
        new_client_notices = 1 if case['mode'] == 'mysql' else 0
        http.check(len(initial_jobs) == new_client_notices, 'Unexpected notification state before workflow seeding.')
        if new_client_notices:
            notice = initial_jobs[0]
            http.check(notice['type'] == 'owner_security' and notice['status'] == 'queued'
                       and notice['context']['kind'] == 'suspicious_admin_login'
                       and notice['context']['url'] == 'admin/?page=security',
                       'The second actual owner client did not create its exact security notice.')
        expected_messages = len(notifications.TYPES) + new_client_notices
        page.goto(url)
        http.check('paused' in page.locator('[data-notification-state]').inner_text() and capture.connections == 0, 'Notifications did not start paused without sending.')
        page.locator('[data-notification-settings]').scroll_into_view_if_needed()
        shot('settings')
        stale = context.new_page(); stale.goto(url)
        page.get_by_label('Enable notification automation', exact=True).check()
        submit(page, page.get_by_role('button', name='Save Notification Settings', exact=True))
        http.check(notifications.state(case)['overview']['settings']['enabled'], 'Browser settings did not commit the master switch.')
        stale.get_by_label('Enable notification automation', exact=True).check()
        submit(stale, stale.get_by_role('button', name='Save Notification Settings', exact=True), 409)
        http.check('Notification settings changed' in stale.locator('body').inner_text() and stale.get_by_label('Enable notification automation', exact=True).is_checked(), 'Stale form was accepted or lost its attempted state.')
        original = page; page = stale
        shot('stale-settings')
        page = original; stale.close()
        seeds = notifications.fixture(case, 'seed')
        submit(page, page.get_by_role('button', name='Discover notifications', exact=True))
        discovered = notifications.state(case)
        discovered_jobs = list(discovered['raw']['jobs'].values())
        http.check(sorted(job['type'] for job in discovered_jobs)
                   == sorted(notifications.TYPES + ['owner_security'] * new_client_notices)
                   and all(job['status'] == 'queued' for job in discovered_jobs)
                   and discovered['overview']['counts']['queued'] == expected_messages,
                   'Browser discovery did not queue the six actual workflows and exact new-client security notice.')
        page.locator('[data-notification-journal]').scroll_into_view_if_needed()
        shot('queued')
        page.get_by_label('Notices per batch', exact=True).fill('10')
        submit(page, page.get_by_role('button', name='Run notification batch', exact=True))
        http.check(len(capture.payloads) == expected_messages and notifications.state(case)['overview']['counts']['sent'] == expected_messages, 'Browser run did not commit the actual SMTP outcomes.')
        page.locator('[data-notification-journal]').scroll_into_view_if_needed()
        shot('accepted')
        for payload in capture.payloads:
            mime, body, html = messages.parsed(payload)
            http.check('PRIVATE_' not in body and str(mime['Message-ID']).startswith('<vvx.') and html is None, 'Actual notice lost its privacy or stable outbound identity.')
        page.goto(base + '/route.php?vvx_route=account%2Flogin.php')
        if page.locator('#ageGate').is_visible():
            page.get_by_role('button', name='I am 18+ — Enter', exact=True).click()
        page.get_by_label('Email address', exact=True).fill('notice-reader@example.test')
        page.get_by_label('Password', exact=True).fill('Notification-Fixture-781!')
        page.get_by_role('button', name='Sign In', exact=True).click()
        page.wait_for_load_state('domcontentloaded')
        page.goto(base + '/route.php?vvx_route=account%2Fsecurity.php')
        preference = page.get_by_label('Email me when new content I can access is published.', exact=True)
        http.check(preference.is_checked(), 'The real member preference did not reflect saved consent.')
        preference.uncheck()
        page.get_by_role('button', name='Save Profile', exact=True).click()
        page.wait_for_load_state('domcontentloaded')
        http.check(not next(row for row in notifications.state(case)['members'] if row['id'] == seeds['reader'])['preferences']['newContentAlerts'], 'Member opt-out did not persist.')
        http.check(not page.get_by_label('Email me when new content I can access is published.', exact=True).is_checked(), 'The visible preference reverted to stale cached consent after saving.')
        page.locator('.member-form').first.scroll_into_view_if_needed()
        shot('member-preferences')
        signup_context = browser.new_context(viewport=viewport)
        try:
            signup = signup_context.new_page(); signup.goto(base + '/route.php?vvx_route=account%2Fregister.php')
            if signup.locator('#ageGate').is_visible():
                signup.get_by_role('button', name='I am 18+ — Enter', exact=True).click()
            checkbox = signup.get_by_label('Email me one reminder after 24 hours if I have not confirmed my account.', exact=True)
            http.check(checkbox.count() == 1 and not checkbox.is_checked(), 'Registration reminder is not a separate unchecked opt-in.')
            original = page; page = signup
            checkbox.scroll_into_view_if_needed(); shot('registration-opt-in')
            page = original
        finally:
            signup_context.close()
        notifications.fixture(case, 'consent', enabled=True)
        notifications.fixture(case, 'post', name='script-free-cancel')
        plain = browser.new_context(viewport=viewport, java_script_enabled=False)
        try:
            fallback = plain.new_page(); login(fallback, base); fallback.goto(url)
            submit(fallback, fallback.get_by_role('button', name='Discover notifications', exact=True))
            queued = fallback.locator('[data-notification-status="queued"]')
            http.check(queued.count() == 1, 'Script-free discovery did not queue the saved publication.')
            submit(fallback, queued.get_by_role('button', name='Cancel notification', exact=True))
            http.check(fallback.locator('[data-notification-status="canceled"]').count() == 1, 'Script-free cancel did not persist.')
        finally:
            plain.close()
        http.check(not errors, 'Notification browser raised JavaScript errors: ' + repr(errors))
        http.check(not capture.errors and len(capture.payloads) == expected_messages, 'Read-only/preferences/script-free actions sent additional mail.')
        return {'browser_checks': http.checks - start, 'viewport': viewport, 'screenshots': images, 'layouts': layouts,
                'captured_messages': expected_messages, 'new_client_security_notices': new_client_notices,
                'mysql_version': initial['mysql_version'], 'external_messages': 0, 'javascript_errors': errors,
                'script_free_discovery_cancel': True, 'member_optout': True, 'registration_default_unchecked': True}
    finally:
        context.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'notifications_browser', 'passed': False, 'cases': {},
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'playwright': importlib.metadata.version('playwright')}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-notifications-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            archive, identity = notifications.prepare_package(work)
            summary.update(identity)
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('VELVET_CHROMIUM') or None, args=['--no-sandbox'])
            summary['chromium'] = browser.version
            try:
                for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                    for path, prefix, viewport in (('root-desktop', '', {'width': 1440, 'height': 1000}),
                                                   ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})):
                        name = mode + '-' + path
                        with messages.runtime(work, archive, mode, name, prefix, evidence) as case:
                            summary['cases'][name] = browser_case(browser, case, viewport, evidence)
                        print('Notification browser ' + name + ': PASS', flush=True)
            finally:
                browser.close()
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'notifications-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
