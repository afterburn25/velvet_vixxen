"""Real owner marketing-storage forms in an installed, extracted MySQL package.

Imported by the packaged browser suite. Every fixture mutation is CLI-only,
scoped to a marked temporary runtime and an initially empty owned test schema.
"""
from __future__ import annotations

import concurrent.futures
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import time
from urllib.parse import urlparse

from bs4 import BeautifulSoup
import requests

FIXTURE = Path(__file__).with_name('marketing_storage_fixture.php')
OWNER = 'owner@example.test'
PASSWORD = 'VelvetDemo12'
MIGRATION = '202610200015_marketing_sql_storage'
PACKAGED_MIGRATIONS = tuple(sorted(path.stem for path in
    (Path(__file__).resolve().parents[2] / 'database/migrations').glob('*.php')))
PRE015_MIGRATIONS = tuple(key for key in PACKAGED_MIGRATIONS if key < MIGRATION)
COLLECTIONS = ('posts', 'banners', 'landing_pages', 'links', 'referral_codes', 'contacts', 'segments',
               'campaigns', 'campaign_queue', 'events', 'consents', 'suppressions')
PRIVATE_MARKERS = ('confirmed-storage@example.test', 'pending-storage@example.test', 'retired-storage@example.test',
                   'reserved-storage-unsubscribe', 'reserved-storage-confirmation', 'Private fixture draft',
                   'legacy_private_note', 'marketing_storage_records', 'marketing_storage_state', 'SQLSTATE',
                   'PDOException', 'storage/private/marketing/data.json')
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def fixture(root: Path, action: str, php: str = 'php', **values):
    result = subprocess.run([php, str(FIXTURE), str(root), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=50, cwd='/tmp')
    check(result.returncode == 0, 'Guarded marketing storage fixture failed: ' + result.stderr[:1200])
    return json.loads(result.stdout)


def session():
    client = requests.Session()
    client.trust_env = False
    return client


def form_values(html: str, action: str | None = None):
    soup = BeautifulSoup(html, 'html.parser')
    form = (soup.select_one('form:has(input[name="action"][value="' + action + '"])')
            if action else soup.select_one('form'))
    check(form is not None, 'The requested real form is missing: ' + str(action))
    fields = {}
    for element in form.select('input[name], select[name], textarea[name]'):
        if element.has_attr('disabled') or element.get('type') in ('file', 'submit', 'button'):
            continue
        if element.get('type') in ('checkbox', 'radio') and not element.has_attr('checked'):
            continue
        if element.name == 'textarea':
            value = element.text
        elif element.name == 'select':
            option = element.select_one('option[selected]') or element.select_one('option')
            value = option.get('value', '') if option else ''
        else:
            value = element.get('value', '')
        fields[element['name']] = value
    return fields


def login(base: str, owner=None):
    owner = session() if owner is None else owner
    page = owner.get(base + '/admin/?page=login', timeout=10)
    fields = form_values(page.text)
    response = owner.post(base + '/admin/?page=login', data={'_csrf': fields['_csrf'],
                          'email': OWNER, 'password': PASSWORD}, timeout=15)
    check(response.status_code == 200 and 'page=login' not in response.url, 'The real owner sign-in failed.')
    return owner


def snapshot(site: Path, php: str = 'php'):
    result = fixture(site, 'read', php)
    return {key: result[key] for key in ('marketing', 'marketing_read_blocked', 'legacy_sha256', 'sql',
                                       'protected', 'unrelated', 'outbox', 'ledger')}


def privacy(html: str, site: Path):
    check(not any(marker in html for marker in PRIVATE_MARKERS) and str(site) not in html,
          'The owner storage status/review exposed private records or internal database/path details.')


def state(response, expected: str, site: Path):
    soup = BeautifulSoup(response.text, 'html.parser')
    panel = soup.select_one('[data-marketing-storage]')
    check(panel is not None and panel.get('data-storage-state') == expected,
          'Unexpected owner storage state: expected ' + expected + ', HTTP ' + str(response.status_code))
    check('no-store' in response.headers.get('Cache-Control', '') and response.headers.get('Pragma') == 'no-cache',
          'The private owner storage status or review can be cached.')
    privacy(response.text, site)
    return soup


def assert_counts(html: str, expected: dict):
    soup = BeautifulSoup(html, 'html.parser')
    rows = soup.select('[data-storage-count]')
    actual = {row['data-storage-count']: int(row.select_one('dd').get_text(strip=True)) for row in rows}
    check(actual == expected, 'The reviewed record totals differ from the separate-process source read.')


def review(owner, base: str, site: Path):
    url = base + '/admin/?page=marketing-storage'
    page = owner.get(url, timeout=15)
    fields = form_values(page.text, 'preview_marketing_storage')
    response = owner.post(url, data=fields, timeout=20)
    check(response.status_code == 200 and not response.history, 'Preview did not render the explicit owner review.')
    state(response, 'ready', site)
    form = form_values(response.text, 'migrate_marketing_storage')
    check(re.fullmatch('[a-f0-9]{64}', form.get('plan', '')) is not None, 'The reviewed plan is missing or malformed.')
    return response, form


def owner_revoked_while_waiting(owner, base: str, site: Path, php: str):
    """Observe the real move inside its locks before revoking the saved owner."""
    _, fields = review(owner, base, site)
    before = snapshot(site, php)
    directory = site / 'storage/private/marketing'
    with (directory / 'data.lock').open('a+b') as data_lock, (directory / 'delivery.lock').open('a+b') as delivery_lock:
        fcntl.flock(data_lock, fcntl.LOCK_EX)
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            pending = pool.submit(owner.post, base + '/admin/?page=marketing-storage', data=fields, timeout=25)
            try:
                try:
                    deadline = time.monotonic() + 10
                    entered = False
                    while not pending.done() and time.monotonic() < deadline:
                        try:
                            fcntl.flock(delivery_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                            fcntl.flock(delivery_lock, fcntl.LOCK_UN)
                        except BlockingIOError:
                            entered = True
                            break
                        time.sleep(.02)
                    # Taking the delivery lock proves the request passed its initial
                    # route owner/CSRF/review checks and entered the actual service.
                    check(entered, 'The real move never entered its delivery lock while waiting for the data lock.')
                    fixture(site, 'owner-status', php, status='demoted')
                finally:
                    fcntl.flock(data_lock, fcntl.LOCK_UN)
                response = pending.result(timeout=25)
                check(response.status_code == 403, 'An owner revoked while the actual move waited for a lock still committed.')
            finally:
                fixture(site, 'owner-status', php, status='owner')
    check(snapshot(site, php) == before, 'Owner revocation after the initial checks changed source, SQL, outbox or backups.')


def security_and_preview_case(site: Path, base: str, php: str):
    """Leaves a legitimate pre015 installation for the real browser update flow."""
    before_checks = checks
    check(urlparse(base).hostname == '127.0.0.1' and str(site).startswith('/tmp/')
          and (site / '.vvx-test-runtime').is_file(), 'A marked loopback package runtime is required.')
    initial = fixture(site, 'read', php)
    check(initial['status']['state'] == 'ready' and initial['status']['can_migrate']
          and initial['status']['mode'] == 'file', 'An explicit move must be required on the installed MySQL site.')
    check(initial['mysql_version'].startswith('8.0.') and initial['ledger'] == list(PACKAGED_MIGRATIONS)
          and len(PRE015_MIGRATIONS) == 14 and MIGRATION in PACKAGED_MIGRATIONS,
          'The HTTP fixture must use real MySQL 8 and every packaged migration in exact order.')
    check(len(initial['marketing']['campaign_queue']) == 2
          and initial['marketing']['settings']['campaign_delivery_enabled'], 'The seeded delivery safety case is missing.')
    owner, anonymous, member = login(base), session(), session()
    url = base + '/admin/?page=marketing-storage'
    try:
        baseline = snapshot(site, php)
        page = owner.get(url, timeout=15)
        soup = state(page, 'ready', site)
        check(page.status_code == 200 and soup.select_one('[data-storage-preview-form]')
              and not soup.select('[data-storage-counts], input[name="plan"]'), 'GET skipped the explicit review step.')
        check(soup.select_one('[data-admin-group="system"] a[aria-current="page"]').get('href') == url,
              'Marketing Storage is not the active System navigation item.')
        fields = form_values(page.text, 'preview_marketing_storage')
        response = owner.get(url + '&action=migrate_marketing_storage&plan=' + initial['status']['plan'], timeout=15)
        check(response.status_code == 200 and snapshot(site, php) == baseline, 'A GET modified marketing records or target state.')

        member_cookie = fixture(site, 'member-session', php)
        member.cookies.set(member_cookie['name'], member_cookie['id'], domain='127.0.0.1', path='/')
        for client, label in ((anonymous, 'Anonymous'), (member, 'Member-only')):
            response = client.get(url, allow_redirects=False, timeout=15)
            check(response.status_code in (302, 303) and 'page=login' in response.headers.get('Location', ''),
                  label + ' accessed the owner storage page.')
            response = client.post(url, data=fields, allow_redirects=False, timeout=15)
            check(response.status_code in (302, 303) and 'page=login' in response.headers.get('Location', ''),
                  label + ' posted an owner storage action.')
        for bad in ('', 'invalid-token'):
            response = owner.post(url, data={**fields, '_csrf': bad}, timeout=15)
            check(response.status_code == 419, 'Missing/invalid CSRF did not reject the storage preview.')
            state(response, 'ready', site)
        response = owner.post(url, data={'action': 'preview_marketing_storage', '_csrf[]': ['invalid']}, timeout=15)
        check(response.status_code == 419, 'Array-shaped CSRF was accepted or raised a PHP diagnostic.')
        for action in ('unrecognized_storage_action', ''):
            response = owner.post(url, data={**fields, 'action': action}, timeout=15)
            check(response.status_code == 400, 'An unknown storage action was not rejected.')
        response = owner.post(url, data={'_csrf': fields['_csrf'], 'action[]': ['preview_marketing_storage']}, timeout=15)
        check(response.status_code == 400, 'Array-shaped action was accepted or raised a PHP diagnostic.')
        response = owner.post(base + '/admin/?page=dashboard', data=fields, timeout=15)
        check(response.status_code == 400, 'A storage action ran through an unrelated admin page.')
        response = owner.put(url, data=fields, timeout=15)
        check(response.status_code == 405 and response.headers.get('Allow') == 'GET, POST', 'Unsupported storage method was accepted.')
        response = owner.post(url, data={'_csrf': fields['_csrf'], 'action': 'migrate_marketing_storage',
                                       'plan': initial['status']['plan']}, timeout=15)
        check(response.status_code == 409, 'A valid plan bypassed the explicit owner preview.')
        check(snapshot(site, php) == baseline, 'A rejected access/method/action changed persisted data.')

        response, move_fields = review(owner, base, site)
        assert_counts(response.text, initial['counts'])
        check(snapshot(site, php) == baseline, 'Preview copied records, created authority, or changed existing data.')
        fixture(site, 'change-source', php)
        changed = fixture(site, 'read', php)
        check(changed['counts'] == initial['counts']
              and changed['marketing']['meta']['revision'] == initial['marketing']['meta']['revision'],
              'The stale-review fixture must change a private field without changing totals or revision.')
        stale_baseline = snapshot(site, php)
        response = owner.post(url, data=move_fields, timeout=20)
        soup = state(response, 'ready', site)
        check(response.status_code == 409 and soup.select_one('.admin-alert.error')
              and not soup.select_one('[data-storage-migrate-form]'), 'A changed source was moved or left an approved stale form.')
        check(snapshot(site, php) == stale_baseline, 'A rejected stale review changed source, target, outbox or backups.')
        response = owner.post(url, data=move_fields, timeout=15)
        check(response.status_code == 409, 'A consumed stale review was reusable.')

        for malformed in ('', 'not-a-fingerprint', None):
            _, valid = review(owner, base, site)
            if malformed is None:
                del valid['plan']
                valid['plan[]'] = ['array-plan']
            else:
                valid['plan'] = malformed
            response = owner.post(url, data=valid, timeout=15)
            check(response.status_code == 409, 'A malformed reviewed plan was accepted.')
            check(snapshot(site, php) == stale_baseline, 'A malformed plan changed persisted state.')

        for owner_status in ('demoted', 'inactive'):
            _, valid = review(owner, base, site)
            fixture(site, 'owner-status', php, status=owner_status)
            try:
                for method, target in (('GET', url), ('POST', url), ('POST', base + '/admin/?page=dashboard')):
                    denied = owner.request(method, target, data=valid, allow_redirects=False, timeout=15)
                    check(denied.status_code == (403 if owner_status == 'demoted' else 302)
                          and (owner_status == 'demoted' or denied.headers.get('Location') == base + '/admin/?page=login'),
                          'A revoked owner retained storage authority through ' + method + ' ' + target)
            finally:
                fixture(site, 'owner-status', php, status='owner')
        check(snapshot(site, php) == stale_baseline, 'Revoked-owner requests changed the stored records.')
        revoked = owner.get(url, allow_redirects=False, timeout=15)
        check(revoked.status_code == 302 and revoked.headers.get('Location') == base + '/admin/?page=login',
              'Restoring the inactive account revived its storage session.')
        login(base, owner)
        owner_revoked_while_waiting(owner, base, site, php)
        removed = fixture(site, 'remove-empty-schema', php)
        check(removed['state'] == 'schema_needed', 'The pre015 fixture did not reach the missing-schema path.')
        response = owner.get(url, timeout=15)
        soup = state(response, 'schema_needed', site)
        check(response.status_code == 200 and not soup.select('[data-storage-preview-form], [data-storage-migrate-form]')
              and soup.select_one('.admin-content a[href="' + base + '/admin/?page=database-updates"]'),
              'An older database did not direct the owner to Database Updates before allowing a move.')
        old_baseline = snapshot(site, php)
        response = owner.post(url, data=fields, timeout=15)
        state(response, 'schema_needed', site)
        check(response.status_code == 409 and snapshot(site, php) == old_baseline,
              'Preview automatically created missing schema or moved records.')
        return {'checks': checks - before_checks, 'mysql_version': initial['mysql_version'],
                'active_owner_csrf_method_and_page_guards': True, 'read_only_get_and_preview': True,
                'active_owner_rechecked_after_actual_move_waited_for_lock': True,
                'all_record_fingerprint_stale_refusal': True, 'malformed_and_repeated_review_refusal': True,
                'missing_schema_database_updates_path': True, 'source_sql_outbox_backups_unchanged_on_rejection': True}
    finally:
        owner.close(); anonymous.close(); member.close()


if __name__ == '__main__':
    runtime = Path(os.environ.get('VELVET_TEST_RUNTIME', '')).resolve()
    result = security_and_preview_case(runtime, os.environ.get('VELVET_BASE_URL', '').rstrip('/'), 'php')
    print(json.dumps(result, indent=2))
