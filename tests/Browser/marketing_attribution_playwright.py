#!/usr/bin/env python3
"""Real packaged privacy, analytics, referral, audience and featured-image controls."""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('vvx_attribution_http', ROOT / 'tests/Http/marketing_attribution_http.py')
assert spec and spec.loader
attribution = importlib.util.module_from_spec(spec)
spec.loader.exec_module(attribution)
check, http = attribution.check, attribution.http


def submit(page, button, label):
    with page.expect_navigation(wait_until='networkidle') as result:
        button.click()
    response = result.value
    check(response is not None and response.status == 200 and response.request.method == 'GET'
          and response.request.redirected_from is not None and response.request.redirected_from.method == 'POST',
          label + ' did not use the real POST/Redirect/GET flow.')
    expect(page.locator('.admin-alert.error')).to_have_count(0)


def geometry(page, label):
    result = page.evaluate('''() => ({viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll(`.marketing-card input:not([type=hidden]):not([type=checkbox]):not([type=radio]),
        .marketing-card select,.marketing-card textarea,.marketing-card button`)]
        .filter(element => element.getClientRects().length).map(element => {
          const r=element.getBoundingClientRect();return {name:element.name || element.innerText,left:r.left,right:r.right,height:r.height};
        })})''')
    check(result['content'] <= result['viewport'] + 1, label + ' has page overflow: ' + repr(result))
    check(all(item['left'] >= -1 and item['right'] <= result['viewport'] + 1 and item['height'] >= 39
              for item in result['controls']), label + ' has clipped or undersized form controls: ' + repr(result))
    return result


def capture(page, evidence, label):
    if evidence is None:
        return None
    page.evaluate('window.scrollTo({top:0,left:0,behavior:"instant"})')
    filename = 'marketing-attribution-' + label + '.png'
    page.screenshot(path=str(evidence / filename), full_page=True)
    check((evidence / filename).stat().st_size > 1000, 'The browser screenshot is empty.')
    return {'file':filename,'sha256':hashlib.sha256((evidence / filename).read_bytes()).hexdigest()}


def browser_case(browser, case, viewport, evidence):
    initial = attribution.checks + http.checks
    owner, seed = attribution.initialize(case)
    owner.close()
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors, actions, screenshots, layouts = [], [], [], []
    page.on('pageerror', lambda error: errors.append(str(error)))
    from urllib.parse import parse_qs
    page.on('request', lambda request: actions.extend(parse_qs(request.post_data or '').get('action', []))
            if request.method == 'POST' and request.resource_type == 'document' else None)
    base = case['base']
    before = attribution.fixture(case)
    try:
        privacy = attribution.route(base, 'privacy/choices.php')
        page.goto(privacy, wait_until='networkidle')
        enter = page.locator('#ageEnter')
        if enter.is_visible():
            enter.click()
        expect(page.locator('[data-measurement-status]')).to_have_text('off')
        check(not attribution.measured(attribution.fixture(case)), 'Opening privacy choices created anonymous measurements.')
        layouts.append(geometry(page, 'Privacy choices'))
        screenshots.append(capture(page, evidence, case['label'] + '-privacy-off'))
        allow = page.get_by_role('button', name='Allow optional measurement', exact=True)
        allow.focus()
        page.keyboard.press('Tab')
        page.keyboard.press('Shift+Tab')
        check(allow.evaluate('(element)=>document.activeElement===element'), 'Privacy choice is not keyboard reachable.')
        allow.click()
        page.wait_for_load_state('networkidle')
        expect(page.locator('[data-measurement-status]')).to_have_text('allowed')
        page.goto(attribution.route(base, 'links/?ref=launch'), wait_until='networkidle')
        saved = attribution.fixture(case)
        check(len(saved['marketing']['attribution_v1']['visits']) == 1
              and next(iter(saved['marketing']['attribution_v1']['visits'].values()))['referral_code'] == 'launch',
              'Actual browser consent/referral controls did not save the validated origin.')
        page.goto(seed['tracked_url'], wait_until='networkidle')
        check(page.url == attribution.route(base, 'shop'), 'The browser opaque campaign link did not open its declared nested-safe destination.')
        page.goto(base + '/admin/?page=login', wait_until='networkidle')
        page.get_by_label('Email address').fill(http.OWNER)
        page.get_by_label('Password', exact=True).fill(http.PASSWORD)
        page.get_by_role('button', name='Sign in', exact=True).click()
        page.wait_for_url(base + '/admin/')
        analytics = base + '/admin/?page=marketing&tab=analytics'
        page.goto(analytics, wait_until='networkidle')
        expect(page.locator('[data-consented-analytics]')).to_be_visible()
        expect(page.locator('[data-report-backend]')).to_contain_text('mysql-indexed' if case['mode']=='mysql' else 'file')
        expect(page.locator('[data-measurement-capacity]')).to_contain_text('retained raw events')
        page.get_by_label('Compare the preceding period of the same length').check()
        with page.expect_navigation(wait_until='networkidle'):
            page.get_by_role('button', name='Update report', exact=True).click()
        expect(page.get_by_role('columnheader', name='Previous period', exact=True)).to_be_visible()
        layouts.append(geometry(page, 'Comparison report'))
        screenshots.append(capture(page, evidence, case['label'] + '-analytics'))
        with page.expect_download() as download_result:
            page.get_by_role('button', name='Export aggregate CSV', exact=True).click()
        download = download_result.value
        check(download.suggested_filename == 'measurement-report.csv', 'Browser aggregate download used an unexpected name.')
        csv = Path(download.path()).read_text()
        check('section,label,count_or_minor_units' in csv and '@example.test' not in csv,
              'The actual browser CSV download leaked identities or omitted the aggregate schema.')
        page.get_by_label('Raw event retention, days (7–365)').fill('30')
        page.get_by_label('Anonymous daily total retention, days (at least raw retention; at most 1,095)').fill('90')
        submit(page, page.get_by_role('button', name='Save measurement settings', exact=True), 'Save retention')
        settings = attribution.fixture(case)['marketing']['analytics_v1']
        check(settings['retention_days']==30 and settings['rollup_days']==90, 'Browser retention choices were not persisted.')
        referrals = base + '/admin/?page=marketing&tab=referrals'
        page.goto(referrals, wait_until='networkidle')
        page.get_by_label('Code', exact=True).fill('browser-launch')
        page.get_by_label('Label', exact=True).fill('Browser referral')
        submit(page, page.get_by_role('button', name='Save Referral', exact=True), 'Save referral')
        coupon = page.locator('[data-coupon-attribution]')
        coupon.get_by_label('Saved coupon', exact=True).select_option('ATTRIBUTION10')
        coupon.get_by_label('Saved campaign', exact=True).select_option(seed['coupon_campaign_id'])
        submit(page, coupon.get_by_role('button', name='Save coupon attribution', exact=True), 'Save coupon assignment')
        check(attribution.fixture(case)['marketing']['attribution_v1']['coupons']['ATTRIBUTION10']['campaign_id'] == seed['coupon_campaign_id'],
              'Browser coupon controls did not retain the intended saved campaign.')
        layouts.append(geometry(page, 'Referral and coupon controls'))
        screenshots.append(capture(page, evidence, case['label'] + '-referrals'))
        audiences = base + '/admin/?page=marketing&tab=audiences'
        page.goto(audiences, wait_until='networkidle')
        form = page.locator('[data-audience-editor]')
        form.get_by_label('Audience name', exact=True).fill('Browser business audience')
        form.locator('[data-business-audience] summary').click()
        form.get_by_label('Membership', exact=True).select_option('active')
        form.locator('input[name="plan_ids[]"][value="' + seed['plan_id'] + '"]').check()
        form.get_by_label('Require paid purchases', exact=True).check()
        form.get_by_label('Minimum total in minor currency units', exact=True).fill('1800')
        form.get_by_label('Exact saved referral codes, one per line', exact=True).fill('launch')
        with page.expect_navigation(wait_until='networkidle'):
            form.get_by_role('button', name='Preview Audience', exact=True).click()
        expect(page.locator('[data-audience-preview]')).to_contain_text('0 eligible newsletter contacts')
        check(not any(row.get('name') == 'Browser business audience' for row in attribution.fixture(case)['marketing']['segments']),
              'Preview saved audience state.')
        form = page.locator('[data-audience-editor]')
        if form.locator('[data-business-audience]').get_attribute('open') is None:
            form.locator('[data-business-audience] summary').click()
        layouts.append(geometry(page, 'Business audience criteria'))
        screenshots.append(capture(page, evidence, case['label'] + '-audience'))
        submit(page, form.get_by_role('button', name='Save Audience', exact=True), 'Save business audience')
        segments = attribution.fixture(case)['marketing']['segments']
        saved = next(item for item in segments if item.get('name') == 'Browser business audience')
        check(saved['rules']['version']==2 and saved['rules']['membership']=='active'
              and saved['rules']['plan_ids']==[seed['plan_id']] and saved['rules']['purchase']['min_total_minor']==1800
              and saved['rules']['referral_codes']==['launch'], 'Browser saved different business audience predicates.')
        page.goto(base + '/admin/?page=marketing&tab=links', wait_until='networkidle')
        page.get_by_label('Public featured image', exact=True).select_option(seed['public_image_id'])
        page.get_by_label('Image description', exact=True).fill('Synthetic browser featured image')
        submit(page, page.get_by_role('button', name='Save featured image', exact=True), 'Save featured image')
        layouts.append(geometry(page, 'Featured image editor'))
        screenshots.append(capture(page, evidence, case['label'] + '-featured-editor'))
        page.goto(attribution.route(base, 'links/'), wait_until='networkidle')
        image = page.get_by_role('img', name='Synthetic browser featured image', exact=True)
        image.scroll_into_view_if_needed()
        expect(image).to_be_visible()
        check(image.evaluate('(image)=>image.complete&&image.naturalWidth>0&&image.naturalHeight>0'), 'Public browser image did not decode from its actual served source.')
        screenshots.append(capture(page, evidence, case['label'] + '-public-links'))
        page.goto(privacy, wait_until='networkidle')
        page.get_by_role('button', name='Decline and remove measurements', exact=True).click()
        page.wait_for_load_state('networkidle')
        expect(page.locator('[data-measurement-status]')).to_have_text('off')
        erased = attribution.fixture(case)
        check(not attribution.measured(erased) and len(erased['marketing']['attribution_v1']['erased_actors'])==1,
              'Real browser opt-out did not remove its linked measurements.')
        check(erased['protected']==before['protected'] and erased['outbox_count']==before['outbox_count']
              and case['capture'].connections==0 and not errors, 'Browser controls changed installed state, sent mail or raised JavaScript errors.')
        required = ['save_analytics_configuration','save_referral','save_coupon_attribution','save_segment','save_link_bio_presentation','export_measurement_csv']
        check(all(action in actions for action in required), 'A required browser interaction was bypassed.')
        return {'checks':attribution.checks+http.checks-initial,'mode':case['mode'],'viewport':viewport,
                'layouts':layouts,'screenshots':[item for item in screenshots if item], 'actions':actions,
                'javascript_errors':errors,'smtp_messages':0,'privacy_live_controls':True,'real_csv_download':True,
                'real_business_audience_controls':True,'real_public_image_decode':True,'mysql_version':erased['mysql_version']}
    finally:
        context.close()


def run():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode',choices=('file','mysql','both'),default='both')
    args=parser.parse_args()
    evidence=attribution.evidence_directory()
    report={'test_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
            'http_test_sha256':hashlib.sha256((ROOT/'tests/Http/marketing_attribution_http.py').read_bytes()).hexdigest(),
            'fixture_sha256':hashlib.sha256(attribution.FIXTURE.read_bytes()).hexdigest(),
            'php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),
            'playwright':importlib.metadata.version('playwright'),'cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-attribution-browser-') as temporary, sync_playwright() as playwright:
        work=Path(temporary)
        archive,identity=attribution.prepare_package(work)
        report.update(identity)
        launch={'headless':True,'args':['--no-sandbox']}
        executable=os.environ.get('VELVET_CHROMIUM_EXECUTABLE')
        if executable:
            launch['executable_path']=executable
        browser=playwright.chromium.launch(**launch)
        report['chromium']=browser.version
        try:
            for mode in (('file','mysql') if args.mode=='both' else (args.mode,)):
                for layout,prefix,viewport in [('root-desktop','',{'width':1440,'height':1000}),('nested-mobile','/nested/velvet',{'width':390,'height':844})]:
                    label=mode+'-'+layout
                    with attribution.runtime(work,archive,mode,label,prefix,evidence) as case:
                        report['cases'][label]=browser_case(browser,case,viewport,evidence)
                    print(label+': '+str(report['cases'][label]['checks'])+' browser/control checks passed.',flush=True)
        finally:
            browser.close()
    report['checks']=sum(case['checks'] for case in report['cases'].values())
    if evidence:
        (evidence/'marketing-attribution-browser.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,indent=2))


if __name__=='__main__':
    run()
