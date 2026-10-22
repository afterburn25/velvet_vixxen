#!/usr/bin/env python3
"""Actual Fresh consent, attribution, business audiences and report acceptance.

Only owned temporary installations, loopback PHP/SMTP and initially empty
MySQL 8 schemas are permitted. Registration and checkout use the real public
forms. The independent fixture reads committed state without application APIs.
"""
from __future__ import annotations

import argparse
import concurrent.futures
from contextlib import contextmanager
from datetime import datetime, timezone
from email import policy
from email.parser import Parser
import hashlib
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
from urllib.parse import parse_qs, urlencode, urlparse
from zipfile import ZipFile

from bs4 import BeautifulSoup
import requests

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(__file__).with_name('marketing_attribution_fixture.php')
MEMBER = 'attribution-member@example.test'
PASSWORD = 'AttributionFixture123!'
sys.dont_write_bytecode = True
checks = 0


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


depth = load('vvx_attribution_package', ROOT / 'tests/Http/composer_depth_http.py')
http = depth._base
session, owner_login, route, fields, changed = http.session, http.owner_login, http.route, http.fields, http.changed
RUNTIME_FILES = (
    'app/Core/MarketingPrivacy.php', 'app/Core/MarketingAttribution.php', 'app/Core/MarketingAttributionTrait.php',
    'app/Core/MarketingAttributionAdminTrait.php', 'app/Core/MarketingAttributionBridge.php',
    'app/Core/MarketingAnalytics.php', 'app/Core/MarketingAnalyticsTrait.php', 'app/Core/MarketingBusinessStoreTrait.php',
    'app/Core/MarketingMeasurementSqlRepository.php', 'app/Core/MarketingAudience.php', 'app/Core/MarketingStore.php',
    'app/Core/MarketingStorage.php', 'app/Core/MarketingSqlRepository.php', 'app/Core/MemberAuth.php',
    'app/Core/MemberStoreTrait.php', 'app/Core/CommerceService.php', 'app/Core/CommerceStoreTrait.php', 'app/Core/Store.php',
    'app/Console/run_analytics.php', 'database/migrations/202610210016_marketing_measurements.php',
    'public/routes/privacy/choices.php', 'public/routes/campaign/click.php', 'public/routes/api/marketing-event.php',
    'public/routes/campaign/index.php', 'public/routes/newsletter/confirm.php', 'public/routes/newsletter/index.php',
    'public/routes/links/index.php', 'includes/marketing.php', 'includes/marketing_admin.php',
    'app/Support/public_routes.php', 'app/Views/admin/marketing.php', 'app/Views/admin/marketing-actions.php',
    'app/Views/admin/marketing-audiences.php', 'app/Views/admin/marketing-analytics.php',
    'app/Views/admin/marketing-attribution.php', 'app/Views/admin/marketing-link-bio.php',
    'app/Views/admin/marketing-measurement-actions.php', 'app/Views/public/partials/footer.php',
    'app/Views/public/partials/header.php', 'public/home.php', 'public/assets/css/marketing.css',
)


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def fixture(case, action='read'):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action], input='{}',
                            text=True, capture_output=True, timeout=55, cwd='/tmp')
    check(result.returncode == 0, 'The owned attribution fixture failed: ' + result.stderr[:1800])
    return json.loads(result.stdout)


def prepare_package(work):
    archive, identity = depth.prepare_package(work)
    with ZipFile(archive) as zipped:
        for name in RUNTIME_FILES:
            check(zipped.read(name) == (ROOT / name).read_bytes(), 'Packaged attribution source differs: ' + name)
    return archive, identity


@contextmanager
def runtime(work, archive, mode, label, prefix, evidence=None):
    php = shutil.which('php')
    check(php is not None, 'PHP is required for actual-package attribution acceptance.')
    if mode == 'mysql':
        check(os.environ.get('VELVET_TEST_DB_NAME') and os.environ.get('VELVET_TEST_DB_USER'),
              'An initially empty exclusively owned local MySQL 8 schema is required.')
    document_root = work / label / 'www'
    site = document_root / prefix.lstrip('/')
    site.mkdir(parents=True)
    with ZipFile(archive) as zipped:
        zipped.extractall(site)
    (site / '.vvx-test-runtime').write_text('Owned disposable packaged attribution acceptance.\n')
    with socket.socket() as available:
        available.bind(('127.0.0.1', 0))
        port = available.getsockname()[1]
    base = f'http://127.0.0.1:{port}{prefix}'
    smtp = load('vvx_attribution_smtp_' + label.replace('-', '_'), ROOT / 'tests/Http/mail_settings_http.py')
    smtp.OWNER = MEMBER  # The existing capture rejects every other recipient.
    capture = smtp.Capture()
    threading.Thread(target=capture.serve_forever, daemon=True).start()
    process = None
    log_path = work / label / 'php-server.log'
    case = {'site': site, 'base': base, 'php': php, 'capture': capture, 'mode': mode, 'label': label, 'expected_mail': 0}
    try:
        http.fixture(site, 'setup-' + mode, php, base=base, smtp_port=capture.server_address[1])
        with log_path.open('w+') as log:
            environment = os.environ.copy()
            environment.pop('PHP_CLI_SERVER_WORKERS', None)
            process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S',
                                        f'127.0.0.1:{port}', '-t', str(document_root)],
                                       cwd=work, env=environment, stdout=log, stderr=log)
            probe = session()
            deadline = time.monotonic() + 15
            try:
                while True:
                    check(process.poll() is None, 'The owned PHP server exited before acceptance.')
                    try:
                        if probe.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                            break
                    except requests.RequestException:
                        pass
                    if time.monotonic() >= deadline:
                        raise AssertionError('The actual packaged PHP server did not start.')
                    time.sleep(.1)
            finally:
                probe.close()
            yield case
            log.flush()
            check(len(capture.payloads) == case['expected_mail'] and capture.connections == case['expected_mail']
                  and not capture.errors and all(item['recipient'] == MEMBER for item in capture.payloads),
                  'Unexpected recipient, connection or message reached local SMTP.')
            check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()),
                  'Actual-package attribution raised PHP diagnostics.')
    finally:
        if process is not None:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        capture.release.set()
        capture.shutdown()
        capture.server_close()
        if mode == 'mysql' and (site / 'storage/temp/editorial-access-ownership.json').is_file():
            http.fixture(site, 'cleanup-mysql', php)
        if evidence and log_path.is_file():
            shutil.copyfile(log_path, evidence / ('marketing-attribution-' + label + '-http.log'))


def initialize(case):
    owner = owner_login(case['base'])
    if case['mode'] == 'mysql':
        http.activate_mysql(owner, case['base'], case['site'], case['php'])
    seed = fixture(case, 'seed')
    return owner, seed


def form(html, action):
    soup = BeautifulSoup(html, 'html.parser')
    item = soup.select_one('input[name="action"][value="' + action + '"]')
    check(item is not None, 'The actual owner action form is missing: ' + action)
    return fields(str(item.find_parent('form')))


def measured(snapshot):
    return [item for item in snapshot['marketing']['events'] if item.get('measurement_version') == 1]


def assert_unchanged(before, after, label):
    check(before['marketing'] == after['marketing'], label + ' changed saved marketing data.')
    check(before['protected'] == after['protected'], label + ' changed protected installed state.')


def reject(response, label):
    check(response.status_code in (200, 400, 403, 409, 419, 422) and not response.history
          and BeautifulSoup(response.text, 'html.parser').select_one('.admin-alert.error') is not None,
          label + ' did not retain a visible rejected form.')


def exercise(case):
    baseline = checks + http.checks
    owner, seed = initialize(case)
    base = case['base']
    guest = session()
    analytics = base + '/admin/?page=marketing&tab=analytics'
    referrals = base + '/admin/?page=marketing&tab=referrals'
    links_admin = base + '/admin/?page=marketing&tab=links'
    privacy = route(base, 'privacy/choices.php')
    links = route(base, 'links/?ref=launch')
    before = fixture(case)
    for url in (analytics, referrals, links_admin):
        response = guest.get(url, allow_redirects=False, timeout=15)
        check(response.status_code in (302, 303, 401, 403), 'A guest read owner attribution controls.')
    page = owner.get(analytics, timeout=20)
    check(page.status_code == 200 and 'Consented measurement' in page.text and 'no-store' in page.headers.get('Cache-Control', ''), 'The report lacks a private owner view.')
    legacy_count = len(before['marketing']['events']) - len(measured(before))
    check(legacy_count > 0 and str(len(before['marketing']['events'])) + ' of 20000 retained raw events' in page.text
          and str(legacy_count) + ' retained legacy events count toward storage usage' in page.text, 'Owner capacity hid retained legacy data.')
    settings = form(page.text, 'save_analytics_configuration')
    denied = owner.post(analytics, data=changed(settings, _csrf='wrong'), allow_redirects=False, timeout=20)
    check(denied.status_code == 419, 'Invalid CSRF changed analytics settings.')
    http.fixture(case['site'], 'owner-status', case['php'], status='demoted')
    try:
        for method in ('GET', 'POST'):
            response = owner.request(method, analytics, data=settings if method == 'POST' else None, allow_redirects=False, timeout=20)
            check(response.status_code == 403 or (response.status_code == 302 and response.headers.get('Location') == base + '/admin/'),
                  'A demoted current owner retained analytics access.')
            check('Consented measurement' not in response.text and 'retention_days' not in response.text, 'A demoted response exposed owner report controls.')
    finally:
        http.fixture(case['site'], 'owner-status', case['php'], status='owner')
    assert_unchanged(before, fixture(case), 'Unauthorized reporting requests')
    response = guest.get(links, timeout=15)
    check(response.status_code == 200 and not guest.cookies.get('velvet_attribution'), 'A no-consent page visit created an attribution cookie.')
    response = guest.get(seed['tracked_url'], allow_redirects=False, timeout=15)
    check(response.status_code == 303 and response.headers['Location'] == route(base, 'shop')
          and response.headers.get('Referrer-Policy') == 'no-referrer'
          and 'no-store' in response.headers.get('Cache-Control', ''), 'No-consent tracking lost safe redirect/privacy headers.')
    response = guest.get(seed['encoded_tracked_url'], allow_redirects=False, timeout=15)
    check(response.status_code == 303 and response.headers['Location'] == 'https://example.test/a%20b?label=two%20words'
          and not guest.cookies.get('velvet_attribution'), 'Safe encoded-space redirect changed its destination or collected without consent.')
    assert_unchanged(before, fixture(case), 'No-consent public requests')
    unknown = guest.get(route(base, 'campaign/click.php?t=' + '0' * 64), timeout=15)
    check(unknown.status_code == 404 and seed['delivery_id'] not in unknown.text, 'An unknown opaque token exposed delivery state.')
    page = guest.get(privacy, timeout=15)
    check(page.status_code == 200 and BeautifulSoup(page.text, 'html.parser').select_one('[data-measurement-status]').get_text() == 'off', 'Unknown privacy choice did not default off.')
    privacy_fields = fields(page.text, '[data-measurement-choices] form')
    denied = guest.post(privacy, data=changed(privacy_fields, csrf='wrong', choice='allow'), timeout=15)
    check(denied.status_code == 419 and not guest.cookies.get('velvet_measurement'), 'Invalid privacy CSRF permitted measurement.')
    page = guest.get(privacy, timeout=15)
    allowed = guest.post(privacy, data=changed(fields(page.text, '[data-measurement-choices] form'), choice='allow'), timeout=20)
    check(allowed.status_code == 200 and 'now allowed' in allowed.text, 'Actual privacy form did not persist consent.')
    cookies = {item.name: item for item in guest.cookies}
    check(cookies['velvet_measurement'].path == (urlparse(base).path or '') + '/'
          and cookies['velvet_measurement'].has_nonstandard_attr('HttpOnly')
          and cookies['velvet_attribution'].has_nonstandard_attr('HttpOnly'), 'Measurement cookies escaped this installation or are script-readable.')
    guest.get(links, timeout=15)
    captured = fixture(case)
    visits = captured['marketing']['attribution_v1']['visits']
    check(len(visits) == 1 and next(iter(visits.values()))['referral_code'] == 'launch', 'Enabled referral was not captured after consent.')
    guest.get(route(base, 'links/?ref=unknown'), timeout=15)
    after = fixture(case)
    check(next(iter(after['marketing']['attribution_v1']['visits'].values()))['referral_code'] == 'launch', 'Unknown referral replaced a validated saved origin.')
    api = route(base, 'api/marketing-event.php')
    api_before = fixture(case)
    response = guest.post(api, json={'event':'page_view','path':'/links/'}, headers={'Origin':'https://outside.example.test'}, timeout=15)
    check(response.status_code == 403, 'Cross-origin request created a measurement.')
    response = guest.post(api, json={'event':'page_view','path':'/links/','email':'never-store@example.test'}, headers={'Origin':base}, timeout=15)
    check(response.status_code == 400, 'Public API accepted arbitrary event properties.')
    response = guest.post(api, json={'event':'purchase','path':'/links/'}, headers={'Origin':base}, timeout=15)
    check(response.status_code == 400, 'Public API forged a purchase conversion.')
    assert_unchanged(api_before, fixture(case), 'Rejected event API requests')
    response = guest.post(api, json={'event':'cta_click','path':'/links/?email=never-store@example.test'}, headers={'Origin':base}, timeout=15)
    check(response.status_code == 200 and response.json()['ok'], 'Consented same-origin public event failed.')
    for _ in range(2):
        response = guest.get(seed['tracked_url'], allow_redirects=False, timeout=15)
        check(response.status_code == 303, 'A consenting campaign click failed to redirect.')
    after = fixture(case)
    check(sum(item['name'] == 'campaign_click' for item in measured(after)) == 1, 'Repeated campaign click was counted twice.')
    check('never-store' not in json.dumps(measured(after)), 'Measurement retained a URL query or submitted identity.')
    page = owner.get(referrals, timeout=15)
    assignment = changed(form(page.text, 'save_coupon_attribution'), coupon_code='ATTRIBUTION10', campaign_id=seed['coupon_campaign_id'], enabled='1')
    response = owner.post(referrals, data=assignment, timeout=20)
    check(response.status_code == 200 and response.history and 'Coupon attribution saved' in response.text, 'Actual coupon assignment did not save.')
    committed = fixture(case)
    response = owner.post(referrals, data=assignment, timeout=15)
    reject(response, 'Stale coupon mapping')
    assert_unchanged(committed, fixture(case), 'Stale coupon mapping')
    page = owner.get(referrals, timeout=15)
    duplicate = changed(form(page.text, 'save_referral'), code='LAUNCH', label='Duplicate', enabled='1')
    reject(owner.post(referrals, data=duplicate, timeout=15), 'Duplicate referral')
    assert_unchanged(committed, fixture(case), 'Duplicate referral')
    registration = route(base, 'account/register.php')
    page = guest.get(registration, timeout=15)
    response = guest.post(registration, data=changed(fields(page.text, '.member-form'), display_name='Synthetic Attribution Member',
        email=MEMBER, password=PASSWORD, password_confirmation=PASSWORD, terms='1'), timeout=25)
    check(response.status_code == 200 and 'Your account was created' in response.text, 'The real registration form did not create the reserved member.')
    case['expected_mail'] = 1
    check(len(case['capture'].payloads) == 1, 'Registration did not send exactly one local verification message.')
    message = Parser(policy=policy.default).parsestr(case['capture'].payloads[0]['body'])
    body = message.get_body(preferencelist=('plain',)).get_content() if message.is_multipart() else message.get_content()
    candidates = re.findall(r'http://127\.0\.0\.1:[0-9]+[^\s<>"\']+', body)
    verification = [url for url in candidates if 'account/verify.php' in url or 'account%2Fverify.php' in url]
    check(len(verification) == 1 and verification[0].startswith(base + '/'), 'The real local verification message lacks its exact installation link.')
    response = guest.get(verification[0], timeout=15)
    check(response.status_code == 200 and 'confirmed' in response.text.lower(), 'The real member verification route failed.')
    # Verification signs the member in. Exercise password login separately
    # through the ordinary logout route, preserving the independent choice cookie.
    guest.get(route(base, 'account/logout.php'), timeout=15)
    login = route(base, 'account/login.php')
    page = guest.get(login, timeout=15)
    response = guest.post(login, data=changed(fields(page.text, '.member-form'), email=MEMBER, password=PASSWORD, **{'return':'/membership/'}), timeout=20)
    check(response.status_code == 200 and 'account%2Flogin' not in response.url, 'The verified member could not sign in.')
    page = guest.get(route(base, 'membership/'), timeout=15)
    start_forms = BeautifulSoup(page.text, 'html.parser').select('form')
    selected = [item for item in start_forms if item.select_one('input[name="id"][value="' + seed['plan_id'] + '"]')]
    check(len(selected) == 1, 'The genuine membership checkout start form is missing.')
    response = guest.post(route(base, 'checkout/'), data=fields(str(selected[0])), timeout=20)
    check(response.status_code == 200 and 'Sandbox checkout' in response.text, 'The real direct membership checkout did not open.')
    checkout_url = response.url
    response = guest.post(checkout_url, data=changed(fields(response.text, '.coupon-form'), coupon='ATTRIBUTION10'), timeout=20)
    check(response.status_code == 200 and 'ATTRIBUTION10' in response.text, 'The actual checkout did not apply the saved coupon.')
    response = guest.post(checkout_url, data=changed(fields(response.text, 'form.checkout-card'), provider='sandbox', sandbox_result='success', confirmation='1'), timeout=25)
    check(response.status_code == 200 and ('Payment complete' in response.text or 'Order confirmed' in response.text or 'Thank you' in response.text), 'The real local sandbox checkout did not complete.')
    case['expected_mail'] = 2
    purchased = fixture(case)
    check(len(purchased['members']) == 1 and len(purchased['orders']) == 1 and purchased['orders'][0]['status'] == 'paid'
          and float(purchased['orders'][0]['grandTotal']) == 18, 'Committed account/order state differs from real checkout outcome.')
    member_origin = purchased['members'][0]['preferences']['_attribution']
    order_origin = purchased['orders'][0]['metadata']['attribution']
    check(member_origin['referral_code'] == order_origin['referral_code'] == 'launch'
          and member_origin['campaign_id'] == seed['campaign_id'] and order_origin['campaign_id'] == seed['coupon_campaign_id']
          and order_origin['delivery_id'] is None, 'Durable registration/order origin or coupon priority is incorrect.')
    names = [item['name'] for item in measured(purchased)]
    check(all(names.count(name) == 1 for name in ('registration','checkout','purchase')), 'Actual public business routes omitted or duplicated conversions.')
    check(len(case['capture'].payloads) == 2 and not case['capture'].errors, 'Registration/receipt did not remain two reserved local messages.')
    fixture(case, 'defer-purchase-projection')
    deferred = fixture(case)
    check(deferred['members'] == purchased['members'] and deferred['orders'] == purchased['orders']
          and not any(item['name']=='purchase' for item in measured(deferred)), 'The owned deferred-projection fixture altered its canonical business source.')
    barrier = threading.Barrier(2)
    def concurrent_worker(_):
        barrier.wait(timeout=10)
        return subprocess.run([case['php'], str(case['site'] / 'app/Console/run_analytics.php'), '--limit=250'],
                              text=True, capture_output=True, timeout=40, cwd=case['site'])
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as workers:
        outcomes = list(workers.map(concurrent_worker, range(2)))
    for outcome in outcomes:
        check(outcome.returncode == 0, 'A real concurrent projection worker failed: ' + outcome.stderr[:400])
    stats = [json.loads(outcome.stdout) for outcome in outcomes]
    recovered = fixture(case)
    check(sum(item['projected'] for item in stats)==1 and all(item['examined']<=250 for item in stats)
          and sum(item['name']=='purchase' for item in measured(recovered))==1,
          'Two real CLI workers lost or duplicated a deferred purchase projection.')
    check(recovered['members']==purchased['members'] and recovered['orders']==purchased['orders']
          and len(case['capture'].payloads)==2, 'Projection recovery changed business sources or resent a message.')
    page = owner.get(analytics + '&compare=1', timeout=20)
    check(page.status_code == 200 and 'Previous period' in page.text and 'USD 18.00' in page.text
          and ('mysql-indexed' if case['mode'] == 'mysql' else 'Report source: file') in page.text, 'Actual report lost comparison, currency or selected backend.')
    funnel = BeautifulSoup(page.text, 'html.parser').select_one('[data-measurement-funnel]').get_text(' ', strip=True)
    check(all(stage + ': 1' in funnel for stage in ('page view','registration','checkout','purchase')), 'HTTP report did not show the ordered consenting-browser funnel.')
    export = form(page.text, 'export_measurement_csv')
    csv = owner.post(analytics, data=export, timeout=20)
    check(csv.status_code == 200 and csv.headers.get('Content-Type', '').startswith('text/csv')
          and '1800,USD' in csv.text and not any(value in csv.text for value in (MEMBER, member_origin['actor'], seed['delivery_id'])), 'CSV export lost exact aggregates or exposed identities.')
    filtered = owner.get(analytics + '&campaign=' + seed['coupon_campaign_id'] + '&event=purchase', timeout=20)
    check(filtered.status_code == 200 and 'USD 18.00' in filtered.text, 'Filtered campaign/purchase report lost coupon conversion.')
    page = owner.get(links_admin, timeout=15)
    image_form = form(page.text, 'save_link_bio_presentation')
    image_form = changed(image_form, featured_asset_id=seed['public_image_id'], featured_alt='Synthetic public featured image')
    response = owner.post(links_admin, data=image_form, timeout=20)
    check(response.status_code == 200 and response.history and 'featured image saved' in response.text, 'The public featured image did not save.')
    public = guest.get(route(base, 'links/'), timeout=15)
    image = BeautifulSoup(public.text, 'html.parser').select_one('img[alt="Synthetic public featured image"]')
    check(image is not None and image['src'].startswith(base + '/'), 'The public links page lost the safe featured image or nested installation path.')
    before_private = fixture(case)
    page = owner.get(links_admin, timeout=15)
    private_form = changed(form(page.text, 'save_link_bio_presentation'), featured_asset_id=seed['private_image_id'], featured_alt='Must stay private')
    reject(owner.post(links_admin, data=private_form, timeout=20), 'Private featured image')
    assert_unchanged(before_private, fixture(case), 'Private featured image')
    reject(owner.post(links_admin, data=image_form, timeout=20), 'Stale featured image')
    assert_unchanged(before_private, fixture(case), 'Stale featured image')
    before_delete = fixture(case)
    page = guest.get(privacy, timeout=15)
    response = guest.post(privacy, data=changed(fields(page.text, '[data-measurement-choices] form'), choice='deny'), timeout=20)
    check(response.status_code == 200 and 'Retained measurements linked to this browser have been removed' in response.text
          and not guest.cookies.get('velvet_attribution'), 'The public opt-out did not remove the visitor association.')
    erased = fixture(case)
    check(not measured(erased) and not erased['marketing']['attribution_v1']['conversions']
          and len(erased['marketing']['attribution_v1']['erased_actors']) == 1, 'Privacy deletion retained linked measurements or omitted anti-resurrection state.')
    check(before_delete['members'] == erased['members'] and before_delete['orders'] == erased['orders']
          and all(before_delete['marketing'][name] == erased['marketing'][name] for name in ('contacts','consents','suppressions')),
          'Measurement deletion changed business records or independent newsletter rights.')
    for _ in range(3):
        worker = subprocess.run([case['php'], str(case['site'] / 'app/Console/run_analytics.php'), '--limit=1'],
                                text=True, capture_output=True, timeout=30, cwd=case['site'])
        check(worker.returncode == 0, 'The actual bounded analytics CLI failed: ' + worker.stderr[:400])
        stats = json.loads(worker.stdout)
        check(stats['examined'] <= 1 and stats['projected'] == 0, 'CLI ignored batch limit or recreated erased attribution.')
    final = fixture(case)
    check(not measured(final) and final['protected'] == before['protected'] and len(case['capture'].payloads) == 2,
          'Maintenance changed installed files, recreated measurements or sent mail.')
    result = {'checks': checks + http.checks - baseline, 'mode':case['mode'], 'mysql_version':final['mysql_version'],
              'private_default':True, 'signed_choice_scoped_cookie':True, 'real_registration':True, 'real_sandbox_checkout':True,
              'coupon_priority':True, 'ordered_funnel':True, 'safe_csv':True, 'current_owner_csrf':True,
              'encoded_space_destination':True,
              'two_real_cli_workers':True, 'deferred_purchase_recovered_once':True,
              'private_media_refused':True, 'featured_public_image':True, 'deletion_prevents_reconciliation':True,
              'protected_installed_state_unchanged':True, 'smtp_messages':len(case['capture'].payloads), 'smtp_external':False,
              'report_backend':'mysql-indexed' if case['mode']=='mysql' else 'file'}
    owner.close()
    guest.close()
    return result


def evidence_directory():
    value = os.environ.get('VELVET_ATTRIBUTION_EVIDENCE_DIR') or os.environ.get('VELVET_SCREENSHOT_DIR')
    if not value:
        return None
    path = Path(value).resolve()
    check(not path.is_relative_to(ROOT) or path.is_relative_to(ROOT / 'artifacts'), 'Keep fixture evidence outside runtime source.')
    path.mkdir(parents=True, exist_ok=True)
    return path


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file','mysql','both'), default='mysql')
    options = parser.parse_args()
    evidence = evidence_directory()
    report = {'php': subprocess.check_output(['php','-r','echo PHP_VERSION;'], text=True), 'server':'php -S without router',
              'test_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'fixture_sha256':hashlib.sha256(FIXTURE.read_bytes()).hexdigest(), 'cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-attribution-http-') as temporary:
        work = Path(temporary)
        archive, identity = prepare_package(work)
        report.update(identity)
        for mode in (('file','mysql') if options.mode == 'both' else (options.mode,)):
            for path_label, prefix in (('root',''),('nested','/nested/velvet')):
                label = mode + '-' + path_label
                with runtime(work, archive, mode, label, prefix, evidence) as case:
                    report['cases'][label] = exercise(case)
                print(label + ': ' + str(report['cases'][label]['checks']) + ' actual-package attribution checks passed.', flush=True)
    report['checks'] = sum(case['checks'] for case in report['cases'].values())
    if evidence:
        (evidence / 'marketing-attribution-http.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    run()
