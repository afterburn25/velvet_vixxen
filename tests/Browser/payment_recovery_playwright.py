#!/usr/bin/env python3
"""Actual packaged payment recovery controls, guarded follow-ups and script-free forms."""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import tempfile
from types import SimpleNamespace

from PIL import Image
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vvx_payment_recovery_http', ROOT / 'tests/Http/payment_recovery_http.py')
recovery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(recovery)
http, check = recovery.http, recovery.check


def login(page, base):
    page.goto(base + '/admin/?page=login')
    page.get_by_label('Email address', exact=True).fill(http.OWNER)
    page.get_by_label('Password', exact=True).fill(http.PASSWORD)
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url(lambda url: 'page=login' not in url)


def submit(page, button, *, keyboard=False):
    button.scroll_into_view_if_needed()
    if keyboard:
        button.focus()
    with page.expect_response(lambda response: response.request.method == 'POST' and '/admin/' in response.url) as pending:
        button.press('Enter') if keyboard else button.click()
    check(pending.value.status in (302, 303), 'A real payment recovery form did not redirect after its accepted action.')
    page.wait_for_load_state('domcontentloaded')
    check(page.locator('[data-payment-recovery]').count() == 1, 'An accepted action did not return to the recovery screen.')


def browser_case(browser, case, viewport, evidence):
    recovery.initialize(case)
    start = http.checks
    capture, records = case['capture'], case['records']
    errors, images, layouts, focus_checks = [], [], [], []
    context = browser.new_context(viewport=viewport)
    context.on('page', lambda item: item.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()

    def shot(label):
        # DOMContentLoaded can precede stylesheets, particularly with scripts
        # disabled. Wait for real resources; never inject CSS or relax geometry.
        page.wait_for_load_state('load')
        styles = page.evaluate('''() => ['admin.css','payment-recovery.css','admin-ux.css'].map(name => {
            const links=[...document.querySelectorAll('link[rel="stylesheet"]')]
                .filter(link=>new URL(link.href).pathname.endsWith('/css/'+name));
            let rules=0;try{rules=links.length===1&&links[0].sheet?links[0].sheet.cssRules.length:0;}catch{}
            return {name,count:links.length,rules};
        })''')
        check(all(item['count'] == 1 and item['rules'] > 0 for item in styles),
              'Payment recovery stylesheet is missing or unreadable: ' + label + ' ' + repr(styles))
        geometry = page.evaluate('''() => {
            const root=document.querySelector('[data-payment-recovery]');
            const controls=[...root.querySelectorAll('a,button,input,select')].filter(e=>{
                const s=getComputedStyle(e);return e.type!=='hidden'&&s.display!=='none'&&s.visibility!=='hidden'&&e.getClientRects().length;
            }).map(e=>{const r=e.getBoundingClientRect();return {tag:e.tagName,name:e.name||e.textContent.trim().slice(0,70),
                width:r.width,height:r.height,left:r.left,right:r.right};});
            return {width:innerWidth,document:document.documentElement.scrollWidth,controls,
                minWidth:Math.min(...controls.map(e=>e.width)),minHeight:Math.min(...controls.map(e=>e.height))};
        }''')
        check(geometry['document'] <= geometry['width'] + 1, 'Payment recovery overflows horizontally: ' + label)
        check(geometry['minHeight'] >= 44 and geometry['minWidth'] >= 44,
              'Payment recovery has a target smaller than 44px: ' + label + ' ' + repr(geometry))
        check(all(row['left'] >= -1 and row['right'] <= geometry['width'] + 1 for row in geometry['controls']),
              'A payment recovery control extends beyond the viewport: ' + label)
        # A single review can extend below the phone viewport; retain its status,
        # explanation and action together. Keep the 50-record list capture bounded.
        full_page = page.locator('[data-recovery-record]').count() == 1
        data = page.screenshot(full_page=full_page)
        with Image.open(io.BytesIO(data)) as decoded:
            decoded.load()
            dimensions = list(decoded.size)
        check(dimensions[0] == viewport['width'] and (dimensions[1] >= viewport['height'] if full_page else dimensions[1] == viewport['height']),
              'The actual capture has the wrong viewport width or page height.')
        filename = 'payment-recovery-' + case['label'] + '-' + label + '.png'
        if evidence:
            (evidence / filename).write_bytes(data)
        images.append({'filename': filename, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data),
                       'width': dimensions[0], 'height': dimensions[1], 'full_page': full_page, 'decoded': True})
        layouts.append({'state': label, 'loaded_stylesheets': styles, **geometry})

    def open_record(record_id=None, **query):
        before = recovery.snapshot(case); connections = capture.connections
        response = page.goto(recovery.workspace(case, **({'record': record_id} if record_id else {}), **query))
        check(response.status == 200 and page.locator('[data-payment-recovery]').count() == 1,
              'The actual recovery page did not open in the browser.')
        recovery.private(response)
        recovery.safe_projection(SimpleNamespace(text=page.content()), before)
        recovery.assert_quiet(case, before, connections)

    def focus_observation(label, expected):
        state = page.evaluate('''() => {const e=document.activeElement,s=getComputedStyle(e);return {
            tag:e.tagName,name:e.name||'',text:e.textContent.trim(),visible:e.matches(':focus-visible'),
            outlineStyle:s.outlineStyle,outlineWidth:parseFloat(s.outlineWidth),outlineColor:s.outlineColor};}''')
        check(expected(state) and state['visible'] and state['outlineStyle'] != 'none' and state['outlineWidth'] >= 2,
              'Keyboard focus is missing, unclear or on the wrong control: ' + label + ' ' + repr(state))
        focus_checks.append({'state': label, **state})

    def filter_order(label):
        open_record(scope='all')
        form = page.locator('.payment-recovery-filters')
        form.get_by_role('combobox', name=re.compile('^Show')).select_option('all')
        form.locator('input[name=order]').fill(records['orders'][label])
        before = recovery.snapshot(case); connections = capture.connections
        form.get_by_role('button', name='Apply filters', exact=True).click()
        page.wait_for_load_state('domcontentloaded')
        recovery.assert_quiet(case, before, connections)
        check(page.locator('[data-recovery-record]').count() == 1
              and page.locator('[data-recovery-record]').get_attribute('data-recovery-record') == records['operations'][label],
              'The real browser order filter selected another operation.')

    def apply(label, keyboard=False):
        order_id, operation_id = records['orders'][label], records['operations'][label]
        before = recovery.snapshot(case); connections = capture.connections
        check(before['journal'][operation_id]['state'] == 'confirmed' and not recovery.transactions(before, order_id),
              'Browser recovery did not begin at a genuine confirmed, unapplied operation.')
        open_record(operation_id, scope='all')
        submit(page, page.get_by_role('button', name='Check payment outcome', exact=True), keyboard=keyboard)
        after = recovery.snapshot(case); transactions = recovery.transactions(after, order_id)
        check(after['journal'][operation_id]['state'] == 'applied' and after['orders'][order_id]['status'] == 'paid'
              and len(transactions) == 1 and transactions[0]['amount'] == 19.25 and transactions[0]['currency'] == 'USD',
              'Browser recovery did not apply the exact original financial facts once.')
        check(len([row for row in after['entitlements'] if row['sourceId'] == order_id and row['status'] == 'active']) == 1,
              'Browser recovery duplicated or missed its owned entitlement.')
        check(all(recovery.effect(after, order_id, kind)['state'] == 'pending' for kind in ('receipt', 'addon'))
              and capture.connections == connections and after['addon_boots'] == before['addon_boots']
              and after['addon_deliveries'] == before['addon_deliveries'],
              'Browser outcome recovery executed deferred delivery work.')
        check(page.locator('.payment-recovery-state').inner_text() == 'Applied'
              and page.get_by_role('button', name='Check payment outcome', exact=True).count() == 0,
              'Applied records still expose another payment recovery action.')
        recovery.safe_projection(SimpleNamespace(text=page.content()), after)
        page.reload(); page.wait_for_load_state('domcontentloaded')
        recovery.assert_quiet(case, after, connections)
        return after

    try:
        login(page, case['base'])
        open_record(scope='all')
        first = page.locator('[data-recovery-record]').evaluate_all('(rows)=>rows.map(row=>row.dataset.recoveryRecord)')
        check(len(first) == 50, 'The first browser keyset page did not contain 50 records.')
        page.get_by_role('combobox', name=re.compile('^Show')).focus()
        page.keyboard.press('Tab'); focus_observation('order-filter', lambda row: row['name'] == 'order')
        page.keyboard.press('Tab'); focus_observation('apply-filter', lambda row: row['tag'] == 'BUTTON' and row['text'] == 'Apply filters')
        page.keyboard.press('Tab'); focus_observation('review-link', lambda row: row['tag'] == 'A' and row['text'] == 'Review record')
        page.get_by_role('heading', name='Find saved records', exact=True).scroll_into_view_if_needed()
        shot('records')
        before = recovery.snapshot(case); connections = capture.connections
        page.get_by_role('link', name='Next page', exact=True).click()
        second = page.locator('[data-recovery-record]').evaluate_all('(rows)=>rows.map(row=>row.dataset.recoveryRecord)')
        check(len(second) == 11 and first + second == sorted(before['journal'])
              and page.get_by_role('link', name='Next page', exact=True).count() == 0,
              'Real browser keyset navigation truncated, duplicated or skipped records.')
        recovery.assert_quiet(case, before, connections)
        filter_order('confirmed')
        check('USD 19.25' in page.locator('[data-recovery-record]').inner_text(), 'The exact original amount is missing in the browser list.')
        page.get_by_role('link', name=re.compile('^Review Payment record ')).focus()
        page.keyboard.press('Enter')
        page.wait_for_url(lambda url: 'record=' + records['operations']['confirmed'] in url, wait_until='domcontentloaded')
        check('record=' + records['operations']['confirmed'] in page.url, 'Keyboard review opened the wrong record.')
        page.get_by_role('button', name='Check payment outcome', exact=True).focus()
        page.keyboard.press('Shift+Tab'); page.keyboard.press('Tab')
        focus_observation('outcome-action', lambda row: row['text'] == 'Check payment outcome')
        shot('confirmed')
        after = apply('confirmed', keyboard=True)
        page.locator('[data-recovery-record]').scroll_into_view_if_needed(); shot('applied')
        order_id = records['orders']['confirmed']; receipt = recovery.effect(after, order_id, 'receipt')
        open_record(receipt['id'], scope='all'); shot('followups-pending')
        old_messages, old_deliveries = len(capture.payloads), len(after['addon_deliveries'])
        submit(page, page.get_by_role('button', name='Run eligible order follow-ups', exact=True))
        delivered = recovery.snapshot(case)
        check(all(recovery.effect(delivered, order_id, kind)['state'] == 'done' for kind in ('receipt', 'addon'))
              and delivered['financial_sha256'] == after['financial_sha256']
              and len(capture.payloads) == old_messages + 1 and len(delivered['addon_deliveries']) == old_deliveries + 1,
              'Explicit browser follow-ups did not deliver once without repeating financial effects.')
        check(page.get_by_role('button', name='Run eligible order follow-ups', exact=True).count() == 0,
              'Completed follow-ups still expose a resend control.')
        shot('followups-completed')
        open_record(receipt['id'], scope='all')

        pending_id, pending_order = records['operations']['pending'], records['orders']['pending']
        before = recovery.snapshot(case); connections = capture.connections
        open_record(pending_id)
        submit(page, page.get_by_role('button', name='Check payment outcome', exact=True))
        unknown = recovery.snapshot(case)
        check(unknown['journal'][pending_id]['state'] == 'unknown' and unknown['orders'][pending_order]['status'] == 'processing'
              and unknown['financial_sha256'] == before['financial_sha256'] and set(unknown['journal']) == set(before['journal'])
              and capture.connections == connections and unknown['addon_deliveries'] == before['addon_deliveries'],
              'Unknown browser lookup created confirmation, another operation or side effects.')
        check(page.locator('.payment-recovery-state').inner_text() == 'Outcome unknown', 'The unknown result is not explained in the owner screen.')
        shot('outcome-unknown')

        after = apply('temporary'); order_id = records['orders']['temporary']; receipt = recovery.effect(after, order_id, 'receipt')
        old_messages, old_connections = len(capture.payloads), capture.connections
        recovery.temporary_mail(capture)
        open_record(receipt['id'], scope='all')
        submit(page, page.get_by_role('button', name='Run eligible order follow-ups', exact=True))
        retryable = recovery.snapshot(case)
        check(recovery.effect(retryable, order_id, 'receipt')['state'] == 'retryable' and len(capture.payloads) == old_messages
              and capture.connections == old_connections + 1 and page.locator('.payment-recovery-state').inner_text() == 'Safe to retry',
              'The real SMTP 421 did not produce an actionable retryable receipt.')
        shot('receipt-retryable')
        submit(page, page.get_by_role('button', name='Run eligible order follow-ups', exact=True))
        retried = recovery.snapshot(case)
        check(recovery.effect(retried, order_id, 'receipt')['state'] == 'done' and len(capture.payloads) == old_messages + 1
              and capture.connections == old_connections + 2, 'The browser receipt retry did not transmit exactly once.')

        after = apply('uncertain'); order_id = records['orders']['uncertain']; receipt = recovery.effect(after, order_id, 'receipt')
        old_messages, old_deliveries = len(capture.payloads), len(after['addon_deliveries'])
        capture.mode = 'unknown'; recovery.fixture(case, 'addon-mode', value='unknown')
        open_record(receipt['id'], scope='all')
        submit(page, page.get_by_role('button', name='Run eligible order follow-ups', exact=True))
        held = recovery.snapshot(case)
        check(all(recovery.effect(held, order_id, kind)['state'] == 'held' for kind in ('receipt', 'addon'))
              and len(capture.payloads) == old_messages + 1 and len(held['addon_deliveries']) == old_deliveries + 1,
              'Ambiguous actual deliveries were not held in the browser flow.')
        capture.mode = 'accepted'; recovery.fixture(case, 'addon-mode', value='accepted')
        for kind in ('receipt', 'addon'):
            open_record(recovery.effect(held, order_id, kind)['id'], scope='all')
            check(page.locator('.payment-recovery-state').inner_text() == 'Held for review'
                  and page.locator('.payment-recovery-action form').count() == 0,
                  'An ambiguous delivery exposes an automatic resend control.')
            shot(kind + '-held')
        check(len(capture.payloads) == old_messages + 1 and len(recovery.snapshot(case)['addon_deliveries']) == old_deliveries + 1,
              'Viewing a held delivery resent it after transport recovery.')

        open_record(records['operations']['script-free'], scope='all')
        session = recovery.owner_session_id(case, context.cookies())
        recovery.fixture(case, 'age-session', id=session)
        open_record(records['operations']['script-free'], scope='all')
        check(page.get_by_role('heading', name='Confirm your identity before taking action', exact=True).count() == 1
              and page.locator('.payment-recovery-action form').count() == 0,
              'Expired recent authentication still exposes browser recovery actions.')
        shot('confirmation-required')
        page.get_by_role('link', name='Confirm in Security', exact=True).click()
        confirm = page.locator('form').filter(has=page.locator('input[name=action][value=security_confirm]'))
        confirm.locator('input[name=password]').fill(http.PASSWORD)
        with page.expect_response(lambda response: response.request.method == 'POST' and 'page=security' in response.url) as pending:
            confirm.locator('button').click()
        check(pending.value.status in (302, 303), 'The real Security password confirmation failed in the browser.')
        page.wait_for_load_state('domcontentloaded')
        open_record(records['operations']['script-free'], scope='all')
        check(page.get_by_role('button', name='Check payment outcome', exact=True).count() == 1,
              'Actual owner password confirmation did not restore the eligible action.')

        plain = browser.new_context(viewport=viewport, java_script_enabled=False)
        plain.on('page', lambda item: item.on('pageerror', lambda error: errors.append(str(error))))
        primary = page
        try:
            page = plain.new_page(); login(page, case['base'])
            filter_order('script-free')
            # Force a real slow stylesheet fetch after a script-free navigation.
            # Geometry must be measured after load, not just DOMContentLoaded.
            network = plain.new_cdp_session(page)
            network.send('Network.enable')
            network.send('Network.setCacheDisabled', {'cacheDisabled': True})
            network.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 750,
                         'downloadThroughput': -1, 'uploadThroughput': -1})
            page.get_by_role('link', name=re.compile('^Review Payment record ')).click(no_wait_after=True)
            page.wait_for_url(lambda url: 'record=' + records['operations']['script-free'] in url,
                              wait_until='domcontentloaded')
            shot('no-script-confirmed')
            network.send('Network.emulateNetworkConditions', {'offline': False, 'latency': 0,
                         'downloadThroughput': -1, 'uploadThroughput': -1})
            network.send('Network.setCacheDisabled', {'cacheDisabled': False})
            network.detach()
            after = apply('script-free'); order_id = records['orders']['script-free']; receipt = recovery.effect(after, order_id, 'receipt')
            open_record(receipt['id'], scope='all')
            old_messages, old_deliveries = len(capture.payloads), len(after['addon_deliveries'])
            submit(page, page.get_by_role('button', name='Run eligible order follow-ups', exact=True))
            complete = recovery.snapshot(case)
            check(all(recovery.effect(complete, order_id, kind)['state'] == 'done' for kind in ('receipt', 'addon'))
                  and complete['financial_sha256'] == after['financial_sha256'] and len(capture.payloads) == old_messages + 1
                  and len(complete['addon_deliveries']) == old_deliveries + 1,
                  'Script-free forms did not apply and then deliver the eligible follow-ups once.')
            shot('no-script-completed')
        finally:
            page = primary; plain.close()
        final = recovery.snapshot(case)
        check(not errors and not capture.errors, 'The browser or owned SMTP transport reported errors.')
        check((case['mode'] == 'mysql') == bool(final['mysql_version']), 'Actual SQL runtime identity is missing.')
        return {'browser_checks': http.checks - start, 'viewport': viewport, 'mysql_version': final['mysql_version'],
                'php_cli_version': final['php_cli_version'], 'php_http_version': case['php_http_version'],
                'initial_keyset_records': len(first) + len(second),
                'screenshots': images, 'layouts': layouts, 'keyboard_focus': focus_checks, 'javascript_errors': errors,
                'readonly_get_no_mutation_or_addons_or_smtp': True, 'private_allowlisted_projection': True,
                'real_keyset_and_order_filter': True, 'confirmed_local_apply_once_separate_followups': True,
                'unknown_never_confirmed_or_duplicated': True, 'actual_smtp_421_retry_once': True,
                'ambiguous_mail_and_addon_held_without_resend': True, 'recent_confirmation_through_security': True,
                'script_free_filter_review_apply_and_followups': True, 'delayed_script_free_css_load_before_measurement': True,
                'required_stylesheets_checked': ['admin.css','payment-recovery.css','admin-ux.css'], 'minimum_target_pixels': 44,
                'smtp_connections': capture.connections, 'captured_messages': len(capture.payloads),
                'addon_deliveries': len(final['addon_deliveries']), 'external_messages': 0}
    except Exception as error:
        if evidence:
            failure = {'passed': False, 'case': case['label'], 'browser_checks': http.checks - start,
                       'failure': str(error), 'screenshots': images, 'layouts': layouts, 'keyboard_focus': focus_checks}
            try:
                data = page.screenshot(full_page=False)
                with Image.open(io.BytesIO(data)) as decoded:
                    decoded.load(); dimensions = list(decoded.size)
                filename = 'payment-recovery-' + case['label'] + '-failure.png'
                (evidence / filename).write_bytes(data)
                failure['failure_screenshot'] = {'filename': filename, 'sha256': hashlib.sha256(data).hexdigest(),
                                                 'bytes': len(data), 'dimensions': dimensions, 'decoded': True}
            except Exception as capture_error:
                failure['capture_failure'] = str(capture_error)
            (evidence / ('payment-recovery-' + case['label'] + '-partial.json')).write_text(json.dumps(failure, indent=2) + '\n')
        raise
    finally:
        capture.mode = 'accepted'
        context.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    args = parser.parse_args(); evidence = http.evidence_directory()
    report = {'report_kind': 'payment_recovery_browser', 'passed': False, 'cases': {},
              'playwright': importlib.metadata.version('playwright'),
              'server': 'Actual extracted Fresh with the PHP HTTP server'}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-payment-recovery-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary); archive, identity = recovery.prepare_package(work); report.update(identity)
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('VELVET_CHROMIUM') or None,
                                                 args=['--no-sandbox'])
            report['chromium'] = browser.version
            try:
                for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                    for path, prefix, viewport in (('root-desktop', '', {'width': 1440, 'height': 1000}),
                                                   ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})):
                        label = mode + '-' + path
                        with recovery.runtime(work, archive, mode, label, prefix, evidence) as case:
                            report['cases'][label] = browser_case(browser, case, viewport, evidence)
                        print('Payment recovery browser ' + label + ': PASS', flush=True)
            finally:
                browser.close()
        report['passed'] = True; print(json.dumps(report, indent=2))
    except Exception as error:
        report['failure'] = str(error); raise
    finally:
        if evidence:
            (evidence / 'payment-recovery-browser-evidence.json').write_text(json.dumps(report, indent=2) + '\n')


if __name__ == '__main__':
    main()
