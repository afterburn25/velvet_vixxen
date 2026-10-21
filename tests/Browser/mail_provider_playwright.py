#!/usr/bin/env python3
"""Actual packaged SendGrid settings and verified-event review on desktop and mobile."""
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
spec = importlib.util.spec_from_file_location('vvx_provider_http', ROOT / 'tests/Http/mail_provider_http.py')
provider = importlib.util.module_from_spec(spec)
spec.loader.exec_module(provider)
messages, http = provider.messages, provider.http


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
    http.check(pending.value.status in ((302, 303) if status == 200 else (status,)), 'Actual provider form returned an unexpected HTTP status.')
    page.wait_for_load_state('domcontentloaded')


def browser_case(browser, case, viewport, evidence):
    start = http.checks
    if case['mode'] == 'mysql':
        owner = http.owner_login(case['base'])
        try:
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        finally:
            owner.close()
    errors, images, layouts, actions = [], [], [], []
    context = browser.new_context(viewport=viewport)
    context.on('page', lambda item: item.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()
    base = case['base']
    url = base + '/admin/?page=marketing&tab=provider'
    capture = case['capture']
    client = http.session()
    page.on('request', lambda request: actions.append(request.post_data) if request.method == 'POST' and '/admin/' in request.url else None)

    def shot(label):
        geometry = page.evaluate('''() => {
            const controls=[...document.querySelectorAll('main button,main input,main textarea')].filter(e=>e.offsetParent && e.type!=='hidden').map(e=>{
                const target=e.type==='checkbox' ? (e.closest('label') || e) : e;
                return {name:e.name,type:e.type,height:target.getBoundingClientRect().height};
            });
            return {width:innerWidth,document:document.documentElement.scrollWidth,
                minControl:Math.min(...controls.map(e=>e.height)),controls};
        }''')
        http.check(geometry['document'] <= geometry['width'] + 1, 'Provider UI overflows horizontally: ' + label)
        http.check(geometry['minControl'] >= 30, 'Provider controls are clipped or too small: ' + label + ' ' + repr(geometry))
        data = page.screenshot(full_page=False)
        with Image.open(io.BytesIO(data)) as decoded:
            decoded.load()
            http.check(decoded.size == (viewport['width'], viewport['height']), 'Provider screenshot differs from the actual viewport.')
        filename = 'mail-provider-' + case['label'] + '-' + label + '.png'
        if evidence:
            (evidence / filename).write_bytes(data)
        images.append({'filename': filename, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)})
        layouts.append({'state': label, **geometry})

    try:
        login(page, base)
        before = provider.durable(provider.state(case))
        page.goto(url)
        http.check('Provider events are disabled.' in page.locator('[data-provider-state]').inner_text() and capture.connections == 0, 'Provider controls did not start disabled and read-only.')
        http.check(provider.durable(provider.state(case)) == before, 'Read-only browser view initialized private provider state.')
        http.check(page.locator('[data-provider-endpoint]').inner_text() == provider.endpoint(case), 'Displayed webhook URL differs from the real root/nested dispatcher endpoint.')
        page.locator('[data-provider-settings]').scroll_into_view_if_needed(); shot('settings')
        stale = context.new_page(); stale.goto(url)
        key = provider.fixture(case, 'key')['public_key']
        page.get_by_label('Public verification key', exact=True).fill('SG.invalid-fixture-key')
        page.get_by_label('Enable signed SendGrid events', exact=True).check()
        submit(page, page.get_by_role('button', name='Save Provider Settings', exact=True), 422)
        http.check(page.get_by_label('Public verification key', exact=True).input_value() == 'SG.invalid-fixture-key' and not provider.state(case)['overview']['settings']['enabled'], 'Rejected key was accepted or the attempted form was lost.')
        page.get_by_label('Public verification key', exact=True).fill(key)
        submit(page, page.get_by_role('button', name='Save Provider Settings', exact=True))
        http.check(provider.state(case)['overview']['settings']['enabled'] and 'does not configure SendGrid' in page.locator('[data-provider-state]').inner_text(), 'Browser enable failed or suggested a verified production connection.')
        page.locator('[data-provider-settings]').scroll_into_view_if_needed(); shot('enabled')
        stale.get_by_label('Enable signed SendGrid events', exact=True).check()
        stale.get_by_label('Public verification key', exact=True).fill(key)
        submit(stale, stale.get_by_role('button', name='Save Provider Settings', exact=True), 409)
        http.check('Provider settings changed' in stale.locator('body').inner_text() and stale.get_by_label('Public verification key', exact=True).input_value() == key, 'Stale browser form overwrote current settings or lost the attempted key.')
        current = page; page = stale
        page.locator('[data-provider-settings]').scroll_into_view_if_needed(); shot('stale-settings')
        page = current; stale.close()
        seed = provider.fixture(case, 'seed')
        http.check(seed['campaign_queued'] == 2 and seed['discovery']['queued'] == 2, 'Real browser provider sources were not queued.')
        http.check(provider.worker(case, 'campaign')['accepted'] == 2 and provider.worker(case, 'notification')['delivery']['accepted'] == 2 and len(capture.payloads) == 4, 'The four actual outbound identities were not produced.')
        identifiers = provider.captured(case)
        alpha = identifiers[('campaign', 'alpha@example.test')]
        beta = identifiers[('notification', 'beta@example.test')]
        provider.accept(client, case, [provider.event('browser-bounce', 'bounce', alpha, type='bounce'),
                                      provider.event('browser-complaint', 'spamreport', beta, 'beta@example.test')])
        page.reload()
        http.check(page.locator('[data-provider-status="processed"]').count() == 2 and 'Hard bounce' in page.locator('[data-provider-journal]').inner_text() and 'Spam complaint' in page.locator('[data-provider-journal]').inner_text(), 'Browser journal did not show the two real signed outcomes.')
        http.check('alpha@example.test' not in page.locator('[data-provider-journal]').inner_text() and 'PRIVATE_PROVIDER_' not in page.content(), 'Private event payload data appeared in the browser journal.')
        page.locator('[data-provider-status="processed"]').first.scroll_into_view_if_needed(); shot('verified-feedback')
        recovery = provider.event('browser-interruption', 'unsubscribe', alpha)
        recovery_id = hashlib.sha256(b'sendgrid|browser-interruption').hexdigest()
        provider.interrupt_verified(case, provider.sign(case, [recovery]), recovery_id)
        page.reload()
        http.check(page.locator(f'[data-provider-event="{recovery_id}"]').get_attribute('data-provider-status') == 'pending', 'Interrupted verified receipt was not visibly pending.')
        page.locator(f'[data-provider-event="{recovery_id}"]').scroll_into_view_if_needed(); shot('pending-recovery')
        submit(page, page.get_by_role('button', name='Resume verified pending events', exact=True))
        http.check(page.locator(f'[data-provider-event="{recovery_id}"]').get_attribute('data-provider-status') == 'processed' and provider.state(case)['raw']['events'][recovery_id]['recipient_ciphertext'] is None, 'Real browser recovery failed to commit effects and remove temporary ciphertext.')
        page.locator(f'[data-provider-event="{recovery_id}"]').scroll_into_view_if_needed(); shot('recovered')
        page.get_by_label('Enable signed SendGrid events', exact=True).uncheck()
        submit(page, page.get_by_role('button', name='Save Provider Settings', exact=True))
        paused = provider.durable(provider.state(case))
        http.check(provider.receipt(client, case, provider.sign(case, [recovery])).status_code == 503 and provider.durable(provider.state(case)) == paused, 'Disabled provider still accepted signed receipts.')
        plain = browser.new_context(viewport=viewport, java_script_enabled=False)
        try:
            fallback = plain.new_page(); login(fallback, base); fallback.goto(url)
            fallback.get_by_label('Enable signed SendGrid events', exact=True).check()
            submit(fallback, fallback.get_by_role('button', name='Save Provider Settings', exact=True))
            submit(fallback, fallback.get_by_role('button', name='Resume verified pending events', exact=True))
            http.check(provider.state(case)['overview']['settings']['enabled'] and fallback.locator('[data-provider-status="processed"]').count() == 3, 'Script-free provider settings or reconciliation failed.')
        finally:
            plain.close()
        http.check(any('action=mail_provider_settings' in body for body in actions) and any('action=mail_provider_reconcile' in body for body in actions), 'Browser acceptance bypassed the real owner form actions.')
        http.check(not errors and not capture.errors and len(capture.payloads) == 4, 'Provider browser raised JavaScript errors or sent extra messages.')
        final = provider.state(case)
        return {'browser_checks': http.checks - start, 'viewport': viewport, 'mysql_version': final['mysql_version'],
                'screenshots': images, 'layouts': layouts, 'captured_messages': 4, 'external_messages': 0,
                'javascript_errors': errors, 'script_free_settings_and_reconcile': True, 'exact_accessible_labels': True,
                'actual_signed_campaign_and_notification_events': True, 'killed_verified_receiver_recovered_in_browser': True,
                'real_post_actions': ['mail_provider_settings', 'mail_provider_reconcile'], 'provider_production_contact': False}
    finally:
        client.close(); context.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'mail_provider_browser', 'passed': False, 'cases': {},
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'playwright': importlib.metadata.version('playwright')}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-mail-provider-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            archive, identity = provider.prepare_package(work)
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
                        print('Mail provider browser ' + name + ': PASS', flush=True)
            finally:
                browser.close()
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'mail-provider-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
