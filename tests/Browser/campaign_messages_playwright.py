#!/usr/bin/env python3
"""Real packaged desktop/mobile branded campaign controls and owner-only SMTP tests."""
from __future__ import annotations

import argparse
from email import policy
from email.parser import BytesParser
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
spec = importlib.util.spec_from_file_location('vvx_campaign_messages_http', ROOT / 'tests/Http/campaign_messages_http.py')
messages = importlib.util.module_from_spec(spec)
spec.loader.exec_module(messages)
http = messages.http


def login(page, base):
    page.goto(base + '/admin/?page=login')
    page.get_by_label('Email address', exact=True).fill(http.OWNER)
    page.get_by_label('Password', exact=True).fill(http.PASSWORD)
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url(lambda url: 'page=login' not in url)


def submit(page, control, status=200):
    control.scroll_into_view_if_needed()
    try:
        with page.expect_response(lambda response: response.request.method == 'POST' and '/admin/' in response.url) as pending:
            control.click()
    except Exception:
        print(json.dumps({'url': page.url, 'form': control.evaluate('(e)=>({action:e.form?.getAttribute("action"),valid:e.form?.checkValidity(),button:e.outerHTML})')}, indent=2), flush=True)
        raise
    http.check(pending.value.status in ((302, 303) if status == 200 else (status,)), 'Real owner form returned an unexpected status.')
    page.wait_for_load_state('domcontentloaded')


def browser_case(browser, case, viewport, evidence):
    start = http.checks
    if case['mode'] == 'mysql':
        owner = http.owner_login(case['base'])
        try:
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        finally:
            owner.close()
    messages.seed_recipient(case)
    errors, images, layouts = [], [], []
    context = browser.new_context(viewport=viewport)
    context.on('page', lambda item: item.on('pageerror', lambda error: errors.append(str(error))))
    page = context.new_page()
    base = case['base']
    url = base + '/admin/?page=marketing&tab=campaigns'
    capture = case['capture']

    def shot(label):
        geometry = page.evaluate('''() => {
            const controls=[...document.querySelectorAll('button,input,select')].filter(e=>e.offsetParent && e.type!=='hidden').map(e=>{
                const target=e.type==='checkbox' ? (e.closest('label') || e) : e;
                return {name:e.name,type:e.type,height:target.getBoundingClientRect().height};
            });
            return {width:innerWidth,document:document.documentElement.scrollWidth,
                minControl:Math.min(...controls.map(e=>e.height)),controls};
        }''')
        http.check(geometry['document'] <= geometry['width'] + 1, 'Campaign UI overflows horizontally: ' + label)
        http.check(geometry['minControl'] >= 30, 'Campaign controls are clipped or too small: ' + label + ' ' + repr(geometry))
        data = page.screenshot(full_page=False)
        with Image.open(io.BytesIO(data)) as decoded:
            decoded.load()
            http.check(decoded.size == (viewport['width'], viewport['height']), 'Screenshot does not match its actual viewport.')
        filename = 'campaign-messages-' + case['label'] + '-' + label + '.png'
        if evidence:
            (evidence / filename).write_bytes(data)
        images.append({'filename': filename, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)})
        layouts.append({'state': label, **geometry})

    try:
        login(page, base)
        page.goto(url)
        brand = page.locator('[data-email-brand-form]')
        brand.get_by_label('Brand name', exact=True).fill('Browser brand <safe>')
        brand.get_by_label('Tagline', exact=True).fill('A saved reusable template')
        brand.get_by_label('Footer text', exact=True).fill('Browser frozen footer')
        brand.scroll_into_view_if_needed()
        shot('brand-editor')
        submit(page, brand.get_by_role('button', name='Save Email Brand', exact=True))
        editor = page.locator('[data-campaign-editor]')
        editor.get_by_label('Subject', exact=True).fill('Browser branded <campaign>')
        editor.get_by_label('Preheader', exact=True).fill('Browser preheader')
        editor.get_by_label('Message', exact=True).fill('Browser saved text <script>bad()</script>\nhttps://example.test/offer')
        editor.get_by_label('Email format', exact=True).select_option('branded')
        editor.get_by_label('Button label (optional)', exact=True).fill('Open membership')
        editor.get_by_label('Button destination (optional)', exact=True).fill('/membership/')
        editor.scroll_into_view_if_needed()
        shot('campaign-editor')
        submit(page, editor.get_by_role('button', name='Save Campaign', exact=True))
        card = page.locator('[data-campaign-id]').filter(has=page.get_by_role('heading', name='Browser branded <campaign>', exact=True))
        http.check(card.count() == 1, 'The saved campaign card is absent or duplicated.')
        campaign_id = card.get_attribute('data-campaign-id')
        before = messages.state(case)['marketing']
        card.get_by_role('link', name='Preview saved message', exact=True).click()
        http.check(page.locator('[data-campaign-message-preview]').count() == 1, 'Actual saved-message preview did not open.')
        http.check(page.locator('[data-preview-subject]').inner_text() == 'Browser branded <campaign>', 'Saved preview subject differs.')
        http.check(messages.state(case)['marketing'] == before and capture.connections == 0, 'Browser preview changed storage or sent mail.')
        shot('saved-preview')
        frame = page.frame_locator('iframe[title="Rendered saved campaign"]')
        http.check(frame.locator('a,script,img').count() == 0, 'Private preview permits active links, scripts or external images.')
        http.check(frame.get_by_text('Browser brand <safe>', exact=True).count() == 1, 'Browser preview did not render the actual saved brand.')
        page.locator('iframe').scroll_into_view_if_needed()
        shot('rendered-message')
        submit(page, page.get_by_role('button', name='Send test to owner', exact=True))
        http.check('Campaign test accepted' in page.locator('body').inner_text() and len(capture.payloads) == 1,
                   'Real browser owner test did not deliver exactly once.')
        http.check(capture.payloads[-1]['recipient'] == http.OWNER, 'Browser test emailed a subscriber.')
        message = BytesParser(policy=policy.default).parsebytes(capture.payloads[-1]['body'])
        http.check(message.is_multipart() and '<script>' not in message.get_body(preferencelist=('html',)).get_content(), 'Browser test did not preserve safe HTML and text alternatives.')
        card = page.locator('[data-campaign-id="' + campaign_id + '"]')
        submit(page, card.get_by_role('button', name='Queue Recipients', exact=True))
        frozen = messages.saved_campaign(case, 'Browser branded <campaign>')['delivery_snapshot']
        stale = context.new_page()
        stale.goto(url)
        stale_brand = stale.locator('[data-email-brand-form]')
        stale_brand.get_by_label('Brand name', exact=True).fill('Retained stale brand attempt')
        current = page.locator('[data-email-brand-form]')
        current.get_by_label('Brand name', exact=True).fill('New brand for later queues')
        submit(page, current.get_by_role('button', name='Save Email Brand', exact=True))
        submit(stale, stale_brand.get_by_role('button', name='Save Email Brand', exact=True), 409)
        http.check(stale_brand.get_by_label('Brand name', exact=True).input_value() == 'Retained stale brand attempt', 'Stale brand UI discarded the attempted value.')
        primary = page
        page = stale
        stale_brand.scroll_into_view_if_needed()
        shot('stale-brand')
        page = primary
        stale.close()
        http.check(messages.saved_campaign(case, 'Browser branded <campaign>')['delivery_snapshot'] == frozen, 'A later brand edit changed queued browser content.')
        page.get_by_label('Campaign email delivery', exact=True).check()
        submit(page, page.get_by_role('button', name='Save Marketing Settings', exact=True))
        submit(page, page.get_by_role('button', name='Run Due Campaign Batch', exact=True))
        http.check(len(capture.payloads) == 2 and capture.payloads[-1]['recipient'] == 'campaign-recipient@example.test', 'Real browser campaign batch did not send the exact eligible delivery.')
        _, _, html = messages.parsed(capture.payloads[-1])
        http.check('Browser brand &lt;safe&gt;' in html and 'New brand for later queues' not in html, 'Browser worker used a later template.')
        page.locator('[data-campaign-id="' + campaign_id + '"]').scroll_into_view_if_needed()
        shot('delivered')
        plain = browser.new_context(viewport=viewport, java_script_enabled=False)
        try:
            fallback = plain.new_page()
            login(fallback, base)
            fallback.goto(url)
            form = fallback.locator('[data-campaign-editor]')
            form.get_by_label('Subject', exact=True).fill('Script-free campaign')
            form.get_by_label('Message', exact=True).fill('Saved without JavaScript')
            form.get_by_label('Email format', exact=True).select_option('plain')
            submit(fallback, form.get_by_role('button', name='Save Campaign', exact=True))
            saved = fallback.locator('[data-campaign-id]').filter(has=fallback.get_by_role('heading', name='Script-free campaign', exact=True))
            saved.get_by_role('link', name='Preview saved message', exact=True).click()
            http.check(fallback.locator('iframe').count() == 0 and 'Saved without JavaScript' in fallback.locator('[data-campaign-plain]').inner_text(), 'Plain-text script-free preview failed.')
        finally:
            plain.close()
        http.check(not errors, 'Campaign browser raised JavaScript errors: ' + repr(errors))
        http.check(not capture.errors and len(capture.payloads) == 2, 'Read-only or script-free actions sent additional mail.')
        actual = messages.state(case)
        http.check((case['mode'] == 'mysql') == bool(actual['mysql_version']), 'Actual campaign database version is missing.')
        return {'browser_checks': http.checks - start, 'viewport': viewport, 'screenshots': images,
                'mysql_version': actual['mysql_version'],
                'layouts': layouts, 'captured_messages': 2, 'external_messages': 0, 'javascript_errors': errors,
                'script_free_save_preview': True, 'frozen_brand_delivery': True}
    finally:
        context.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'campaign_messages_browser', 'passed': False, 'cases': {},
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'playwright': importlib.metadata.version('playwright')}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-campaign-messages-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            archive, identity = messages.prepare_package(work)
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
                        print('Campaign message browser ' + name + ': PASS', flush=True)
            finally:
                browser.close()
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'campaign-messages-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
