#!/usr/bin/env python3
"""Real desktop/mobile owner controls, blocked visitor pages and no-JavaScript forms."""
import argparse, hashlib, importlib.util, json, os, tempfile
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('age_http', ROOT / 'tests/Http/age_policies_http.py')
age = importlib.util.module_from_spec(spec); spec.loader.exec_module(age)
http = age.http; protection = age.protection; check = http.check

def browser_case(browser, case, viewport, evidence):
    http_report = age.http_case(case); start = http.checks
    contexts = []; errors = []; outbound = []; shots = []; layouts = []
    def make_context(**options):
        context = browser.new_context(viewport=viewport, **options); contexts.append(context)
        def confine(route):
            host = urlparse(route.request.url).hostname
            if host not in [None, '127.0.0.1']:
                outbound.append(route.request.url); route.abort()
            else: route.continue_()
        context.route('**/*', confine); return context
    context = make_context(); visitor_context = make_context(); page = context.new_page(); visitor = visitor_context.new_page()
    for target in [page, visitor]: target.on('pageerror', lambda error: errors.append(str(error)))
    def submit(target, button, expected=200):
        with target.expect_navigation(wait_until='load'), target.expect_response(lambda response: response.request.method == 'POST') as response: button.click()
        check(response.value.status in ([200, 302, 303] if expected == 200 else [expected]), 'Actual policy/access form returned ' + str(response.value.status))
    def shot(target, name, javascript=True):
        target.wait_for_load_state('load')
        target.evaluate('async()=>{scrollTo(0,0);await new Promise(requestAnimationFrame);await new Promise(requestAnimationFrame)}' if javascript else 'scrollTo(0,0)')
        geometry = target.evaluate('''()=>({width:innerWidth,document:document.documentElement.scrollWidth,controls:[...(document.querySelector('[data-policies-workspace],#accessMain,#policyMain')||document).querySelectorAll('input:not([type=hidden]),button,select,textarea')].filter(x=>x.getBoundingClientRect().height>0).map(x=>({name:x.name||x.textContent.trim().slice(0,60),height:(x.type==='checkbox'?(x.closest('label')||x):x).getBoundingClientRect().height}))})''')
        check(geometry['document'] <= geometry['width'] + 1, 'Age/policies horizontal overflow: ' + name + repr(geometry))
        check(all(control['height'] >= 44 for control in geometry['controls']), 'Age/policies control below 44px: ' + name + repr(geometry))
        payload = target.screenshot(full_page=True); filename = 'age-policies-' + case['label'] + '-' + name + '.png'
        shots.append({'name': filename, 'sha256': hashlib.sha256(payload).hexdigest(), 'bytes': len(payload)}); layouts.append({'name': name, **geometry})
        if evidence: (evidence / filename).write_bytes(payload)
    def login(target):
        target.goto(case['base'] + '/admin/?page=login'); target.get_by_label('Email address', exact=True).fill(http.OWNER); target.get_by_label('Password', exact=True).fill(http.PASSWORD)
        submit(target, target.get_by_role('button', name='Sign in', exact=True)); check('page=login' not in target.url, 'Actual browser owner sign-in failed.')
    def fill_access(target, mode='disabled'):
        target.get_by_label('Age check', exact=True).select_option(mode)
        target.get_by_label('Adult threshold', exact=False).fill('18'); target.get_by_label('Verified session, minutes', exact=False).fill('60')
    try:
        login(page); page.goto(age.workspace(case)); shot(page, 'owner-documents')
        page.get_by_label('Policy title', exact=True).focus(); page.keyboard.press('Tab')
        check(page.evaluate('document.activeElement.name') == 'body', 'Keyboard navigation did not reach policy text.')
        page.get_by_label('Policy title', exact=True).fill('Browser reviewed <terms>')
        literal = '<script>window.policyInjected=true</script>\nSynthetic browser terms with a plain-text paragraph.'
        page.get_by_label('Policy text', exact=False).fill(literal); submit(page, page.get_by_role('button', name='Save policy draft', exact=True))
        check(page.locator('[data-policy-review]').inner_text() == literal and page.evaluate('typeof window.policyInjected') == 'undefined', 'The saved owner review executed or changed policy markup.')
        stale = context.new_page(); stale.on('pageerror', lambda error: errors.append(str(error))); stale.goto(page.url)
        revision = stale.locator('[data-policy-editor] input[name=revision]').input_value()
        page.get_by_label('Policy title', exact=True).fill('Current reviewed terms'); submit(page, page.get_by_role('button', name='Save policy draft', exact=True))
        stale.get_by_label('Policy title', exact=True).fill('Retained stale title'); submit(stale, stale.get_by_role('button', name='Save policy draft', exact=True), 409)
        check(stale.get_by_label('Policy title', exact=True).input_value() == 'Retained stale title' and stale.locator('[data-policy-editor] input[name=revision]').input_value() == revision, 'A rejected stale browser form was silently rebased.')
        shot(stale, 'stale-form'); stale.close()
        page.locator('[name=reviewed_policy]').check(); submit(page, page.get_by_role('button', name='Publish reviewed policy', exact=True))
        response = visitor.goto(age.policy_url(case, 'terms')); check(response.status == 200, 'Reviewed browser publication is unavailable.')
        check(visitor.locator('[data-public-policy]').inner_text() == literal and visitor.evaluate('typeof window.policyInjected') == 'undefined', 'Public policy escaped text did not remain inert.')
        shot(visitor, 'public-policy')
        submit(page, page.get_by_role('button', name='Unpublish policy', exact=True)); check(visitor.reload().status == 404, 'Actual Unpublish did not withdraw policy.')
        page.locator('[name=reviewed_policy]').check(); submit(page, page.get_by_role('button', name='Publish reviewed policy', exact=True))
        page.get_by_role('link', name='Age & location', exact=True).click(); fill_access(page)
        page.get_by_label('Yoti SDK ID', exact=True).fill('11111111-2222-4333-8444-555555555555'); page.get_by_label('Yoti API key', exact=False).fill('synthetic-browser-provider-key')
        page.locator('[name=reviewed_rules]').check(); submit(page, page.get_by_role('button', name='Save access settings', exact=True))
        check(page.get_by_label('Yoti API key', exact=False).input_value() == '' and 'A key is saved encrypted.' in page.locator('body').inner_text(), 'Browser provider secret was redisplayed or lost.')
        shot(page, 'provider-settings')
        fill_access(page, 'required'); page.locator('[name=clear_api_key]').check(); page.locator('[name=reviewed_rules]').check()
        submit(page, page.get_by_role('button', name='Save access settings', exact=True)); check(age.fixture(case, 'snapshot')['provider_key_saved'] is False, 'Clear saved provider key did not work.')
        response = visitor.goto(case['base'] + '/'); check(response.status == 503 and 'temporarily unavailable' in visitor.locator('h1').inner_text(), 'Required unconfigured provider failed open in the browser.')
        shot(visitor, 'required-unavailable')
        check(visitor.get_by_role('link', name='Account & privacy', exact=True).get_attribute('href').endswith('privacy.php'), 'Account privacy help link is absent.')
        visitor.get_by_role('link', name='Public policies', exact=True).click(); check(visitor.get_by_role('heading', name='Policies & help', exact=True).count() == 1, 'Blocked visitor could not open public help.')
        page.goto(age.workspace(case, 'access')); fill_access(page); page.locator('[name=geographic_enabled]').check()
        page.get_by_label('Trusted country header', exact=True).select_option('HTTP_CF_IPCOUNTRY'); page.get_by_label('Trusted immediate proxy addresses', exact=False).fill('127.0.0.1/32')
        page.get_by_label('Blocked country codes', exact=False).fill('US'); page.locator('[name=reviewed_rules]').check()
        submit(page, page.get_by_role('button', name='Save access settings', exact=True)); shot(page, 'location-settings')
        visitor_context.set_extra_http_headers({'CF-IPCountry': 'US'}); response = visitor.goto(case['base'] + '/')
        check(response.status == 403 and 'location' in visitor.locator('h1').inner_text(), 'Browser country block did not apply.'); shot(visitor, 'location-blocked')
        visitor.get_by_role('link', name='Report content or a concern', exact=True).click(); check(visitor.locator('[data-report-form]').count() == 1, 'Blocked visitor lost the real report form.')

        # The actual owner form works with JavaScript disabled, including review/publication.
        nojs_context = make_context(java_script_enabled=False); nojs = nojs_context.new_page(); nojs.on('pageerror', lambda error: errors.append(str(error)))
        login(nojs); nojs.goto(age.workspace(case, document='privacy')); nojs.get_by_label('Policy title', exact=True).fill('No-script privacy policy')
        nojs.get_by_label('Policy text', exact=False).fill('Synthetic no-script privacy wording.'); submit(nojs, nojs.get_by_role('button', name='Save policy draft', exact=True))
        nojs.locator('[name=reviewed_policy]').check(); submit(nojs, nojs.get_by_role('button', name='Publish reviewed policy', exact=True)); shot(nojs, 'no-script-owner', javascript=False)
        response = visitor.goto(age.policy_url(case, 'privacy')); check(response.status == 200 and visitor.locator('[data-public-policy]').inner_text() == 'Synthetic no-script privacy wording.', 'No-script reviewed publication failed under a location block.')
        page.goto(age.workspace(case, 'access')); fill_access(page); page.locator('[name=geographic_enabled]').uncheck(); page.locator('[name=reviewed_rules]').check()
        submit(page, page.get_by_role('button', name='Save access settings', exact=True))
        snapshot = age.fixture(case, 'snapshot'); check(snapshot['policies'] == snapshot['independent_policies'], 'Browser writes did not match independent policy storage.')
        check(snapshot['age_requests'] == [] and not outbound and not errors, 'Browser suite made an external request, started a live provider call, or had JavaScript errors: ' + repr(outbound + errors))
        return {'checks': http.checks - start, 'http_acceptance': http_report, 'php': snapshot['php'], 'mysql_version': snapshot['mysql_version'],
                'screenshots': shots, 'layouts': layouts, 'javascript_errors': errors, 'external_requests': outbound, 'keyboard_policy_editor': True,
                'no_javascript_save_review_publish': True, 'real_owner_controls': True, 'real_provider_requests': 0, 'smtp_connections': 0}
    finally:
        for current in contexts: current.close()

def run():
    parser = argparse.ArgumentParser(); parser.add_argument('--mode', choices=['file', 'mysql', 'both'], default='both'); args = parser.parse_args()
    evidence = http.evidence_directory(); report = {'server': 'real Apache + matching mod_php', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-age-browser-') as tmp, sync_playwright() as playwright:
        work = Path(tmp); archive, identity = age.prepare_package(work); report.update(identity)
        browser = playwright.chromium.launch(executable_path=os.environ.get('VELVET_CHROMIUM') or os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE') or None, headless=True, args=['--no-sandbox'])
        report['chromium'] = browser.version
        try:
            for mode in (['file', 'mysql'] if args.mode == 'both' else [args.mode]):
                for label, prefix, viewport in [('root-desktop', '', {'width': 1440, 'height': 1000}), ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})]:
                    name = mode + '-' + label
                    with protection.runtime(work, archive, mode, name, prefix, evidence) as case: report['cases'][name] = browser_case(browser, case, viewport, evidence)
                    print('Age/policy browser ' + name + ': PASS', flush=True)
        finally: browser.close()
    if evidence: (evidence / 'age-policies-browser-evidence.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
if __name__ == '__main__': run()
