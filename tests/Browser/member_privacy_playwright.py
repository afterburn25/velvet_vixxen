#!/usr/bin/env python3
"""Real privacy forms and downloads on Apache, with desktop/mobile PNG evidence."""
import argparse, hashlib, importlib.util, json, os, tempfile
from pathlib import Path
from types import SimpleNamespace
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('privacy_http', ROOT / 'tests/Http/member_privacy_http.py')
privacy = importlib.util.module_from_spec(spec); spec.loader.exec_module(privacy)
http = privacy.http; check = http.check; protection = privacy.protection

def browser_case(browser, case, viewport, evidence):
    start = http.checks; records = privacy.fixture(case, 'seed'); shots = []; layouts = []; errors = []
    owner_context = browser.new_context(viewport=viewport); member_context = browser.new_context(viewport=viewport)
    owner = owner_context.new_page(); member = member_context.new_page()
    for page in [owner, member]: page.on('pageerror', lambda error: errors.append(str(error)))

    def submit(page, button, status=200):
        with page.expect_response(lambda response: response.request.method == 'POST') as response: button.click()
        page.wait_for_load_state('domcontentloaded')
        check(response.value.status in ([200, 302, 303] if status == 200 else [status]), 'A real privacy control returned status ' + str(response.value.status))

    def shot(page, name):
        page.evaluate('''async()=>{scrollTo(0,0);await new Promise(requestAnimationFrame);await new Promise(requestAnimationFrame)}''')
        geometry = page.evaluate('''()=>({width:innerWidth,document:document.documentElement.scrollWidth,controls:[...(document.querySelector('[data-member-privacy],[data-owner-privacy]')?.querySelectorAll('input:not([type=hidden]),textarea,select,button')||[])].filter(e=>e.getClientRects().length).map(e=>({tag:e.tagName,type:e.type,left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width}))})''')
        check(geometry['document'] <= geometry['width'] + 1 and all(control['width'] > 0 and control['left'] >= -1 and control['right'] <= geometry['width'] + 1 for control in geometry['controls']), 'Privacy controls overflowed or collapsed in ' + name)
        layouts.append({'name': name, **geometry})
        if evidence:
            path = evidence / ('member-privacy-' + case['label'] + '-' + name + '.png'); page.screenshot(path=str(path), full_page=True)
            shots.append({'name': name, 'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()})

    try:
        owner.goto(case['base'] + '/admin/?page=login'); owner.locator('input[name=email]').fill(http.OWNER); owner.locator('input[name=password]').fill(http.PASSWORD)
        submit(owner, owner.locator('form button[type=submit],form button').first)
        member.goto(http.route(case['base'], 'account/login.php'))
        if member.locator('#ageGate').is_visible(): member.locator('#ageEnter').click()
        member.locator('input[name=email]').fill('privacy@example.test'); member.locator('input[name=password]').fill(http.MEMBER_PASSWORD)
        submit(member, member.locator('.member-form button').first)
        member.goto(privacy.member_url(case)); owner.goto(privacy.owner_url(case)); before = privacy.state(case)
        member.reload(); owner.reload()
        check(privacy.state(case)['all_sha256'] == before['all_sha256'], 'Real browser privacy overview GET changed durable application state.')
        shot(member, 'member-overview'); shot(owner, 'owner-overview')
        # This copied signed-in session runs request and password cancellation
        # through the real HTML controls with JavaScript disabled.
        no_script = browser.new_context(viewport=viewport, java_script_enabled=False, storage_state=member_context.storage_state())
        try:
            plain = no_script.new_page(); plain.goto(privacy.member_url(case)); form = plain.locator('[data-privacy-request="export"]')
            form.get_by_label('Current password', exact=True).fill(http.MEMBER_PASSWORD); form.locator('input[type=checkbox]').check(); submit(plain, form.get_by_role('button', name='Request account export', exact=True))
            pending = plain.locator('[data-request-status=requested]').first; pending.locator('summary').click(); cancel = pending.locator('[data-privacy-cancel]')
            cancel.get_by_label('Current password', exact=True).fill(http.MEMBER_PASSWORD); cancel.locator('input[type=checkbox]').check(); submit(plain, cancel.get_by_role('button', name='Cancel pending request', exact=True))
            check(plain.locator('[data-request-status=canceled]').count() == 1, 'No-script password-confirmed privacy cancellation failed.')
        finally: no_script.close()
        member.reload(); form = member.locator('[data-privacy-request="export"]'); form.get_by_label('Current password', exact=True).fill('wrong'); form.locator('input[type=checkbox]').check()
        submit(member, form.get_by_role('button', name='Request account export', exact=True), 403)
        check(member.locator('[data-privacy-error]').count() == 1, 'Wrong password has no accessible privacy error.')
        form = member.locator('[data-privacy-request="export"]'); form.get_by_label('Current password', exact=True).fill(http.MEMBER_PASSWORD); form.locator('input[type=checkbox]').check()
        submit(member, form.get_by_role('button', name='Request account export', exact=True))
        exported = member.locator('[data-request-status=requested]').first.get_attribute('data-request-id')
        owner.goto(privacy.owner_url(case, exported)); shot(owner, 'export-review')
        check('1,007' in owner.locator('[data-privacy-review]').inner_text(), 'The actual owner review hid the complete order inventory.')
        submit(owner, owner.get_by_role('button', name='Approve private export', exact=True))
        submit(owner, owner.get_by_role('button', name='Run approved work', exact=True))
        member.reload(); shot(member, 'export-ready')
        with member.expect_download() as download: member.get_by_role('link', name='Download private ZIP', exact=True).click()
        downloaded = Path(download.value.path()).read_bytes(); response = member.request.get(privacy.download_url(case, exported))
        check(response.status == 200 and downloaded == response.body(), 'The actual browser download differs from the authorized checked response.')
        archive_hash = privacy.verify_zip(SimpleNamespace(status_code=response.status, headers=http.requests.structures.CaseInsensitiveDict(response.headers), content=response.body(), url=response.url))
        closure = member.locator('[data-privacy-request="erase"]'); closure.get_by_label('Current password', exact=True).fill(http.MEMBER_PASSWORD); closure.locator('input[type=checkbox]').check()
        submit(member, closure.get_by_role('button', name='Request account closure', exact=True))
        erased = member.locator('[data-request-status=requested]').first.get_attribute('data-request-id'); owner.goto(privacy.owner_url(case, erased)); shot(owner, 'closure-review')
        review = owner.locator('[data-privacy-approve]'); values = privacy.retention(); review.get_by_label('Retention reason', exact=True).fill(values['reason']); review.get_by_label('Next retention review date', exact=True).fill(values['review_on'])
        for checkbox in review.locator('input[type=checkbox]').all(): checkbox.check()
        submit(owner, review.get_by_role('button', name='Approve account closure', exact=True)); before = privacy.state(case)
        submit(owner, owner.get_by_role('button', name='Run approved work', exact=True)); after = privacy.state(case); shot(owner, 'closure-complete')
        row = after['journal']['requests'][erased]
        check(row['status'] == 'complete' and row['stage'] == 10 and set(row['completed_stages']) == set(privacy.STAGES), 'The real owner control did not finish all ten durable stages.')
        check(after['financial_sha256'] == before['financial_sha256'] and after['other_sha256'] == before['other_sha256'] and after['guest_sha256'] == before['guest_sha256'], 'The browser closure changed financial facts or an unrelated identity.')
        check(member.request.get(privacy.member_url(case)).status == 403 and member.request.get(privacy.download_url(case, exported)).status == 403, 'Account closure left its browser session or export accessible.')
        check(not errors and case['capture'].connections == 0, 'Privacy browser errors or SMTP traffic: ' + repr(errors))
        return {'checks': http.checks - start, 'mysql_version': after['mysql_version'], 'orders_exported': 1007, 'archive_sha256': archive_hash,
                'screenshots': shots, 'layouts': layouts, 'javascript_errors': errors, 'readonly_get_no_mutation': True,
                'real_password_confirmed_request': True, 'no_script_request_and_cancel': True, 'real_owner_review_and_approval': True,
                'actual_browser_private_zip': True, 'ten_stage_owner_run': True, 'closed_session_and_export_denied': True,
                'financial_facts_preserved': True, 'other_members_unchanged': True, 'smtp_connections': 0}
    finally: owner_context.close(); member_context.close()

def run():
    parser = argparse.ArgumentParser(); parser.add_argument('--mode', choices=['file', 'mysql', 'both'], default='both'); args = parser.parse_args()
    evidence = http.evidence_directory(); report = {'server': 'real Apache + matching mod_php', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-privacy-browser-') as tmp, sync_playwright() as playwright:
        work = Path(tmp); archive, identity = privacy.prepare_package(work); report.update(identity)
        browser = playwright.chromium.launch(executable_path=os.environ.get('VELVET_CHROMIUM') or os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE') or None, headless=True, args=['--no-sandbox'])
        report['chromium_version'] = browser.version
        try:
            for mode in (['file', 'mysql'] if args.mode == 'both' else [args.mode]):
                for label, prefix, viewport in [('root-desktop', '', {'width': 1440, 'height': 1000}), ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})]:
                    name = mode + '-' + label
                    with protection.runtime(work, archive, mode, name, prefix, evidence) as case: report['cases'][name] = browser_case(browser, case, viewport, evidence)
                    print('Privacy browser ' + name + ': PASS', flush=True)
        finally: browser.close()
    if evidence: (evidence / 'member-privacy-browser-evidence.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
if __name__ == '__main__': run()
