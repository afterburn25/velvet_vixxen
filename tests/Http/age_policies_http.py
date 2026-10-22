#!/usr/bin/env python3
"""Extracted-package age/policies on real Apache, with no live provider requests."""
import argparse, hashlib, importlib.util, json, os, subprocess, tempfile
from pathlib import Path
from zipfile import ZipFile
from urllib.parse import unquote
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('age_protection_http', ROOT / 'tests/Http/protection_records_http.py')
protection = importlib.util.module_from_spec(spec); spec.loader.exec_module(protection)
http = protection.http; check = http.check
FILES = ['app/Core/AgeAccess.php', 'app/Core/GeographicPolicy.php', 'app/Core/PublicPolicies.php', 'app/Core/YotiAgeProvider.php',
         'app/Support/policies_admin.php', 'app/Support/public_routes.php', 'app/Views/admin/policies.php', 'app/Views/admin/policies-actions.php',
         'app/Views/public/access.php', 'app/Views/public/partials/legal-links.php', 'includes/age_access.php', 'includes/bootstrap.php',
         'public/routes/age/index.php', 'public/routes/policies/index.php', 'public/assets/css/policies.css', 'admin/index.php', '.htaccess']
SOURCES = ['tests/Feature/age_policies.php', 'tests/Http/age_policies_fixture.php', 'tests/Http/age_policies_http.py', 'tests/Browser/age_policies_playwright.py']

def prepare_package(work):
    archive, identity = protection.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in FILES), 'Age/policy acceptance package differs from runtime sources.')
    identity['source_sha256'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in SOURCES if (ROOT / name).is_file()}
    return archive, identity

def fixture(case, action, **values):
    result = subprocess.run([case['php'], str(ROOT / 'tests/Http/age_policies_fixture.php'), str(case['site']), action], input=json.dumps(values), text=True, capture_output=True, timeout=40)
    check(result.returncode == 0, 'Age/policy fixture failed: ' + result.stderr[:1800]); protection.chown_tree(case['site'])
    return json.loads(result.stdout)

def workspace(case, tab='documents', document='terms'):
    return case['base'] + '/admin/?page=policies&tab=' + tab + '&document=' + document

def policy_url(case, document=''):
    return case['base'] + '/route.php?vvx_route=policies/index.php' + ('&document=' + document if document else '')

def age_url(case): return case['base'] + '/route.php?vvx_route=age/index.php'
def form(response, action): return protection.form(response, action)

def access_values(response, **changes):
    values = {**form(response, 'policies_access_save'), 'mode': 'disabled', 'minimum_age': '18', 'session_minutes': '60', 'sdk_id': '', 'api_key': '',
              'country_header': 'HTTP_CF_IPCOUNTRY', 'trusted_proxies': '', 'blocked_countries': '', 'reviewed_rules': '1'}
    values.pop('geographic_enabled', None); values.pop('clear_api_key', None); values.update(changes); return values

def activate_protection(case, owner):
    url = protection.workspace(case); page = owner.get(url, timeout=20); values = form(page, 'protection_activate')
    response = owner.post(url, data={**values, 'confirm_private': '1'}, timeout=30)
    check(response.status_code == 200 and bool(response.history), 'Actual protected-media activation failed: ' + response.text[-900:])

def http_case(case):
    start = http.checks; owner = http.owner_login(case['base']); guest = http.session(); outsider = http.session()
    records = json.loads((case['site'] / 'storage/temp/protection-fixture-records.json').read_text())
    try:
        url = workspace(case); access = workspace(case, 'access'); initial = fixture(case, 'snapshot')
        check(initial['policies'] == initial['independent_policies'] == [] and initial['settings'] is None, 'Acceptance did not start from untouched age/policy defaults.')
        check((initial['mysql_version'] is not None) == (case['mode'] == 'mysql'), 'Actual SQL engine version is missing or mislabeled.')
        response = owner.get(url, timeout=20); http.private(response)
        check(response.status_code == 200 and 'Save policy draft' in response.text, 'Current owner policy editor is unavailable.')
        check(fixture(case, 'snapshot') == initial, 'Read-only owner policy GET initialized state.')
        check(outsider.get(url, timeout=20).status_code == 403, 'Guest read an owner policy draft.')
        check(owner.put(url, timeout=20).status_code == 405, 'Unsupported policy method was accepted.')
        check(guest.get(case['base'] + '/policies/', timeout=20).status_code == 200 and guest.get(case['base'] + '/age/', timeout=20).status_code == 200, 'Real Apache age/policy aliases are missing.')
        for name in ['terms', 'privacy', 'adults', 'dmca', 'support']:
            check(guest.get(policy_url(case, name), timeout=20).status_code == 404, 'An unpublished policy was made public.')
        check(guest.post(policy_url(case), timeout=20).status_code == 405, 'Public policy accepted a mutation method.')
        check(guest.get(policy_url(case, '../config'), timeout=20).status_code == 404, 'Arbitrary policy path was accepted.')
        home = guest.get(case['base'] + '/', timeout=20); check(home.status_code == 200, 'Default-off settings blocked existing content.')
        links = BeautifulSoup(home.text, 'html.parser').select('.legal-links a')
        check(len(links) == 6 and all(link.get('href') != '#' for link in links) and any('privacy%2Fchoices.php' in link['href'] for link in links), 'Footer lost a policy destination or Privacy choices.')
        values = form(response, 'policies_draft_save')
        check(owner.post(url, data={**values, '_csrf': 'wrong', 'title': 'Rejected', 'body': 'Rejected'}, timeout=20).status_code == 419, 'Policy mutation accepted invalid CSRF.')
        check(owner.post(case['base'] + '/admin/?page=dashboard', data=values, timeout=20).status_code == 400, 'Policy action crossed into another owner page.')
        check(owner.get(url + '&action=policies_publish', timeout=20).status_code == 200 and fixture(case, 'snapshot') == initial, 'GET action changed a policy.')
        text = '<script>window.policyInjected=true</script>\nSynthetic reviewed policy.'
        response = owner.post(url, data={**values, 'title': 'Synthetic <terms>', 'body': text}, timeout=20)
        check(response.status_code == 200 and bool(response.history), 'Real owner draft save failed.')
        snapshot = fixture(case, 'snapshot'); check(snapshot['policies'] == snapshot['independent_policies'] and snapshot['policies']['documents']['terms']['draft']['body'] == text, 'Independent storage did not contain the saved draft.')
        check(guest.get(policy_url(case, 'terms'), timeout=20).status_code == 404, 'Draft text was public before reviewed publication.')
        publish = form(response, 'policies_publish')
        check(owner.post(url, data=publish, timeout=20).status_code == 422, 'Missing owner review published a policy.')
        check(owner.post(url, data={**values, 'title': 'Stale', 'body': 'STALE_PRIVATE_BODY'}, timeout=20).status_code == 409, 'A stale policy form overwrote the current draft.')
        response = owner.post(url, data={**publish, 'reviewed_policy': '1'}, timeout=20)
        check(response.status_code == 200 and bool(response.history), 'Reviewed publication failed.')
        public = guest.get(policy_url(case, 'terms'), timeout=20); http.private(public)
        check(public.headers.get('Referrer-Policy') == 'no-referrer', 'Apache replaced the private policy referrer policy.')
        check(public.status_code == 200 and '<script>window.policyInjected=true</script>' not in public.text and '&lt;script&gt;window.policyInjected=true&lt;/script&gt;' in public.text, 'Public policy markup was not safely escaped.')
        check(owner.post(url, data={**form(response, 'policies_draft_save'), 'title': 'Private next draft', 'body': 'PRIVATE_NEXT_DRAFT'}, timeout=20).status_code == 200, 'Next draft save failed.')
        check('PRIVATE_NEXT_DRAFT' not in guest.get(policy_url(case, 'terms'), timeout=20).text, 'Unpublished later draft replaced the public snapshot.')
        response = owner.get(url, timeout=20); check(owner.post(url, data=form(response, 'policies_unpublish'), timeout=20).status_code == 200, 'Policy withdrawal failed.')
        check(guest.get(policy_url(case, 'terms'), timeout=20).status_code == 404, 'Withdrawn policy remained public.')
        for name in ['terms', 'privacy', 'adults', 'dmca', 'support']:
            selected = workspace(case, document=name); response = owner.get(selected, timeout=20)
            response = owner.post(selected, data={**form(response, 'policies_draft_save'), 'title': 'Published ' + name, 'body': 'SYNTHETIC_PUBLIC_' + name}, timeout=20)
            response = owner.post(selected, data={**form(response, 'policies_publish'), 'reviewed_policy': '1'}, timeout=20)
            check(response.status_code == 200 and 'SYNTHETIC_PUBLIC_' + name in guest.get(policy_url(case, name), timeout=20).text, 'The wrong document was published: ' + name)
        current = fixture(case, 'snapshot'); check(current['policies'] == current['independent_policies'], 'Five policy publications differ from independent storage.')
        before_access = owner.get(access, timeout=20); values = access_values(before_access, mode='required')
        check(owner.post(access, data=values, timeout=20).status_code == 409, 'Required age checks enabled without protected media.')
        activate_protection(case, owner)
        raw = case['base'] + '/uploads/protection-picture.png'; guarded = case['base'] + '/route.php?vvx_route=protected-asset.php&file=' + hashlib.sha256(b'uploads/protection-picture.png').hexdigest()
        check(guest.get(raw, timeout=20).status_code in [403, 404], 'Raw uploaded media remained public after activation.')
        check(guest.get(guarded, timeout=20).status_code == 200 and guest.get(records['signed_url'], timeout=20).status_code == 200, 'Pre-rule guarded/signed fixtures were not really accessible.')
        response = owner.get(access, timeout=20); old_values = access_values(response)
        values = access_values(response, sdk_id='11111111-2222-4333-8444-555555555555', api_key='synthetic-http-provider-key')
        response = owner.post(access, data=values, timeout=20)
        check(response.status_code == 200 and 'synthetic-http-provider-key' not in response.text and 'A key is saved encrypted.' in response.text, 'Access settings disclosed or did not save the provider secret.')
        check('synthetic-http-provider-key' not in (case['site'] / 'config/app.php').read_text(), 'Provider secret was stored in plaintext.')
        check(owner.post(access, data=old_values, timeout=20).status_code == 409, 'Stale access settings overwrote current credentials.')
        for changes in [{'minimum_age': '17'}, {'trusted_proxies': '0.0.0.0/0'}, {'trusted_proxies': '::/0'}, {'mode': 'made-up'}, {'country_header': 'HTTP_X_FORWARDED_FOR'}]:
            response = owner.get(access, timeout=20); check(owner.post(access, data=access_values(response, **changes), timeout=20).status_code == 422, 'Invalid access rules were accepted: ' + str(changes))
        response = owner.get(access, timeout=20); response = owner.post(access, data=access_values(response, mode='required', clear_api_key='1'), timeout=20)
        check(response.status_code == 200, 'Required-but-unconfigured state was not saved deliberately.')
        required = fixture(case, 'snapshot'); check(required['provider_key_saved'] is False and required['age_requests'] == [], 'Unconfigured acceptance could contact a real provider.')
        for target in [case['base'] + '/', case['base'] + '/public/home.php', guarded, records['signed_url'], case['base'] + '/route.php?vvx_route=api/scene.php&section=hero', case['base'] + '/route.php?vvx_route=videos/watch.php&slug=protection-fixture-video']:
            blocked = guest.get(target, timeout=20); http.private(blocked)
            check(blocked.status_code == 503 and 'SYNTHETIC_PRIVATE_VIDEO_BYTES' not in blocked.text and not blocked.content.startswith(b'\x89PNG'), 'Required unavailable age rule exposed ordinary or signed content: ' + target)
        alias = case['site'] / 'age-fixture-link'
        alias.symlink_to(case['site'], target_is_directory=True)
        try: check(guest.get(case['base'] + '/age-fixture-link/index.php', timeout=20).status_code == 503, 'A physical symlinked homepage bypassed the age gate.')
        finally: alias.unlink()
        check(guest.head(guarded, timeout=20).status_code == 503, 'HEAD bypassed required age checks.')
        check(guest.get(guarded, headers={'Range': 'bytes=0-7'}, timeout=20).status_code == 503, 'Range bypassed required age checks.')
        page = guest.get(age_url(case) + '&sessionId=aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee&result=true&verified=1', timeout=20)
        check(page.headers.get('Referrer-Policy') == 'no-referrer', 'Apache replaced the private age referrer policy.')
        check(page.status_code == 503 and fixture(case, 'snapshot')['age_requests'] == [], 'Forged provider GET created a grant or provider request.')
        check(guest.put(age_url(case), timeout=20).status_code == 405, 'Unsupported verification method was accepted.')
        token = BeautifulSoup(owner.get(access, timeout=20).text, 'html.parser').select_one('meta[name=csrf-token]')['content']
        check(owner.post(age_url(case), data={'action': 'age_complete', '_csrf': token}, timeout=20).status_code == 503, 'Unconfigured completion granted age access.')
        check(owner.post(age_url(case), data={'action': 'age_start', '_csrf': 'wrong'}, timeout=20).status_code == 419, 'Age start accepted invalid CSRF.')
        fixture(case, 'forge-session', cookie=guest.cookies.get('velvet_vixxen_admin'))
        check(guest.get(guarded, timeout=20).status_code == 503, 'Forged client/server acknowledgement bypassed required age.')
        help_targets = ['policies/index.php', 'reports/index.php', 'privacy/choices.php', 'account/login.php', 'account/forgot.php']
        for target in help_targets:
            check(guest.get(case['base'] + '/route.php?vvx_route=' + target, timeout=20).status_code == 200, 'An age block removed account/report help: ' + target)
        help_link = BeautifulSoup(guest.get(age_url(case), timeout=20).text, 'html.parser').find('a', string='Account & privacy')
        check(help_link and unquote(help_link['href']).endswith('account/privacy.php'), 'Account privacy help points to protected member content.')
        response = owner.get(access, timeout=20); response = owner.post(access, data=access_values(response, geographic_enabled='1', trusted_proxies='127.0.0.1/32', blocked_countries='US'), timeout=20)
        check(response.status_code == 200, 'Trusted immediate-proxy geography settings failed to save.')
        for headers in [{}, {'CF-IPCountry': 'US'}, {'CF-IPCountry': 'XX'}, {'CF-IPCountry': 'gb'}, {'X-Forwarded-For': '8.8.8.8', 'CF-IPCountry': 'US'}]:
            blocked = guest.get(guarded, headers=headers, timeout=20); check(blocked.status_code == 403, 'Blocked/unknown country served guarded content: ' + str(headers))
        allowed = guest.get(guarded, headers={'CF-IPCountry': 'GB'}, timeout=20)
        check(allowed.status_code == 200 and hashlib.sha256(allowed.content).hexdigest() == records['image_sha256'], 'Allowed trusted country did not retain exact private-media bytes.')
        check(guest.get(records['signed_url'], headers={'CF-IPCountry': 'US'}, timeout=20).status_code == 403, 'Old signed media URL bypassed country denial.')
        check(guest.get(policy_url(case, 'privacy'), timeout=20).status_code == 200 and guest.get(case['base'] + '/reports/', timeout=20).status_code == 200, 'Location block removed published policy or reporting.')
        response = owner.get(access, timeout=20); response = owner.post(access, data=access_values(response, geographic_enabled='1', trusted_proxies='203.0.113.0/24', blocked_countries='US'), timeout=20)
        check(response.status_code == 200 and guest.get(guarded, headers={'CF-IPCountry': 'GB', 'X-Forwarded-For': '203.0.113.5'}, timeout=20).status_code == 403, 'A forged forwarding chain was treated as the immediate peer.')
        fixture(case, 'corrupt-access'); check(guest.get(guarded, timeout=20).status_code == 503, 'Malformed saved access settings failed open.'); fixture(case, 'restore-access')
        response = owner.get(access, timeout=20); response = owner.post(access, data=access_values(response), timeout=20)
        check(response.status_code == 200 and guest.get(guarded, timeout=20).status_code == 200, 'Deliberate disable did not restore protected delivery.')
        saved = fixture(case, 'snapshot'); check(saved['policies'] == current['policies'] and saved['age_requests'] == [], 'Access tests changed policy wording or contacted provider startup.')
        protected = saved['protection_sha256']
        protection.owner_status(case, 'demoted'); check(owner.get(url, timeout=20).status_code == 403, 'Demoted owner still read policies.'); protection.owner_status(case, 'owner')
        owner.close(); owner = http.owner_login(case['base']); response = owner.get(url, timeout=20); expired = form(response, 'policies_draft_save')
        protection.fixture(case, 'age-owner-session', cookie=owner.cookies.get('velvet_vixxen_admin'))
        response = owner.get(url, timeout=20); check('Confirm your identity' in response.text and 'data-policy-editor' not in response.text, 'Old owner session still exposed editable drafts.')
        check(owner.post(url, data={**expired, 'title': 'Expired', 'body': 'Must not save'}, timeout=20).status_code == 428, 'Old owner session changed a policy.')
        final = fixture(case, 'snapshot'); check(final['policies'] == saved['policies'] and final['protection_sha256'] == protected, 'Rejected requests changed policies or protection state.')
        return {'checks': http.checks - start, 'php': initial['php'], 'mysql_version': initial['mysql_version'], 'mode': case['mode'],
                'independent_policy_readback': True, 'real_apache_raw_guarded_signed_media': True, 'actual_owner_forms': True, 'safe_plain_text': True,
                'draft_review_publish_withdraw': True, 'fresh_owner_recent_csrf_revision': True, 'immediate_peer_only': True,
                'required_unavailable_fails_closed': True, 'provider_result_unit_binding_separate': True, 'real_provider_requests': 0, 'smtp_connections': 0}
    finally: owner.close(); guest.close(); outsider.close()

def run():
    parser = argparse.ArgumentParser(); parser.add_argument('--mode', choices=['file', 'mysql', 'both'], default='both'); args = parser.parse_args()
    evidence = http.evidence_directory(); report = {'server': 'real Apache prefork + matching mod_php', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-age-http-') as tmp:
        work = Path(tmp); archive, identity = prepare_package(work); report.update(identity)
        for mode in (['file', 'mysql'] if args.mode == 'both' else [args.mode]):
            for label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
                name = mode + '-' + label
                with protection.runtime(work, archive, mode, name, prefix, evidence) as case: report['cases'][name] = http_case(case)
                print('Age/policy HTTP ' + name + ': PASS', flush=True)
    if evidence: (evidence / 'age-policies-http-evidence.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
if __name__ == '__main__': run()
