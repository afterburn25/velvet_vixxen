#!/usr/bin/env python3
"""Actual Fresh editorial access acceptance, without a Playwright dependency.

CI: python3 tests/Http/editorial_access_http.py --mode mysql
Requires requests, beautifulsoup4, PHP and an initially empty loopback MySQL8
test schema through VELVET_TEST_DB_*. The browser runner imports these helpers
but owns separate extracted runtimes and repeats every HTTP assertion.
"""
from __future__ import annotations

import argparse
import concurrent.futures
from contextlib import contextmanager
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import select
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from urllib.parse import parse_qsl, quote, urlencode, urlparse
from zipfile import ZipFile

from bs4 import BeautifulSoup
import requests

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(__file__).with_name('editorial_access_fixture.php')
VERSION, BUILD = '0.9.0', '20261021.4'
OWNER, PASSWORD, MEMBER_PASSWORD = 'owner@example.test', 'VelvetDemo12', 'VelvetMember12'
COLLECTIONS = ('posts', 'banners', 'landing_pages', 'links', 'referral_codes', 'contacts', 'segments',
               'campaigns', 'campaign_queue', 'events', 'consents', 'suppressions')
FROZEN = ('settings', 'links', 'referral_codes', 'contacts', 'segments', 'campaigns', 'campaign_queue',
          'consents', 'suppressions', 'retained_unknown_header')
POLICIES = ('legacy', 'public', 'members', 'gold', 'silver', 'mixed', 'unknown', 'malformed',
            'empty-tiers', 'numeric-id', 'alias-id', 'draft', 'future', 'ended', 'disabled')
MEMBERS = ('free', 'gold', 'silver', 'trial', 'grace', 'expired', 'cancelled', 'paused', 'ended',
           'expired-grace', 'future', 'revoked', 'inactive', 'unverified', 'deleted',
           'just-started', 'just-expired', 'current-grace', 'grace-ended', 'future-verified')
UI_FILES = ('admin/index.php', 'app/Core/MarketingAccess.php', 'app/Core/MarketingPublication.php',
            'app/Core/MarketingStore.php', 'app/Core/MarketingStorage.php', 'app/Core/MemberStoreTrait.php',
            'includes/marketing.php', 'includes/marketing_public_shell.php',
            'includes/marketing_admin.php', 'app/Views/admin/marketing-preview.php',
            'app/Views/admin/marketing-publication-list.php', 'app/Views/public/partials/header.php',
            'public/assets/css/marketing.css',
            'app/Views/admin/marketing-actions.php', 'app/Views/admin/marketing-publication-editor.php',
            'public/routes/blog/index.php', 'public/routes/blog/post.php', 'public/routes/campaign/index.php')
checks = 0
sys.dont_write_bytecode = True


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def fixture(site: Path, action: str, php: str = 'php', _count=True, **values):
    result = subprocess.run([php, str(FIXTURE), str(site), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=55, cwd='/tmp')
    message = 'Guarded editorial fixture failed (' + action + '): ' + result.stderr[:1800]
    if _count:
        check(result.returncode == 0, message)
    elif result.returncode != 0:
        raise AssertionError(message)
    return json.loads(result.stdout)


def session():
    client = requests.Session()
    client.trust_env = False
    return client


def route(base: str, destination: str):
    parsed = urlparse(destination)
    return base + '/route.php?' + urlencode([('vvx_route', parsed.path.strip('/')), *parse_qsl(parsed.query)], quote_via=quote)


def fields(html: str, selector: str = 'form'):
    form = BeautifulSoup(html, 'html.parser').select_one(selector)
    check(form is not None, 'The actual form is missing: ' + selector)
    values = []
    for element in form.select('input[name], select[name], textarea[name]'):
        if element.has_attr('disabled') or element.get('type') in ('file', 'submit', 'button'):
            continue
        if element.get('type') in ('checkbox', 'radio') and not element.has_attr('checked'):
            continue
        if element.name == 'select':
            selected = element.select('option[selected]') or element.select('option')[:1]
            values.extend((element['name'], option.get('value', option.get_text())) for option in selected)
        else:
            values.append((element['name'], element.get_text() if element.name == 'textarea' else element.get('value', '')))
    return values


def changed(values, **updates):
    result = [(key, value) for key, value in values if key not in updates]
    for key, value in updates.items():
        if value is not None:
            result.extend((key, item) for item in value) if isinstance(value, list) else result.append((key, value))
    return result


def owner_login(base: str):
    owner = session()
    page = owner.get(base + '/admin/?page=login', timeout=15)
    values = dict(fields(page.text))
    response = owner.post(base + '/admin/?page=login', data={'_csrf': values['_csrf'],
                          'email': OWNER, 'password': PASSWORD}, timeout=20)
    check(response.status_code == 200 and 'page=login' not in response.url, 'The real owner login failed.')
    return owner


def member_login(base: str, name: str):
    client = session()
    url = route(base, 'account/login.php')
    page = client.get(url, timeout=15)
    values = dict(fields(page.text, '.member-form'))
    response = client.post(url, data={'_csrf': values['_csrf'], 'email': name + '@example.test',
                           'password': MEMBER_PASSWORD, 'return': '/blog/'}, allow_redirects=False, timeout=20)
    check(response.status_code in (302, 303), 'The real member password login failed for ' + name)
    return client


def private(response, preview=False):
    cache = response.headers.get('Cache-Control', '').lower()
    check('private' in cache and 'no-store' in cache, 'Viewer-dependent editorial content can be cached: ' + response.url)
    if preview:
        check(response.headers.get('Pragma') == 'no-cache' and response.headers.get('Expires') == '0'
              and 'noindex' in response.headers.get('X-Robots-Tag', '').lower()
              and 'nofollow' in response.headers.get('X-Robots-Tag', '').lower()
              and response.headers.get('Referrer-Policy') == 'no-referrer',
              'The saved preview is missing its private/noindex/referrer policy.')


def rejected_form(response, label: str):
    errors = BeautifulSoup(response.text, 'html.parser').select('.admin-alert.error')
    check(response.status_code in (200, 400, 403, 409, 419, 422) and not response.history
          and any(error.get_text(strip=True) for error in errors),
          label + ' did not render the retained explicit validation-error form without a redirect.')


def preview_url(base: str, collection: str, name: str = 'draft'):
    kind = {'posts': 'post', 'banners': 'banner', 'landing_pages': 'page'}[collection]
    return base + '/admin/?' + urlencode({'page': 'marketing-preview', 'collection': collection,
                                         'id': 'editorial-' + kind + '-' + name})


def editor_url(base: str, collection: str, name: str = 'draft'):
    kind = {'posts': 'post', 'banners': 'banner', 'landing_pages': 'page'}[collection]
    tab = {'posts': 'posts', 'banners': 'banners', 'landing_pages': 'campaigns'}[collection]
    return base + '/admin/?' + urlencode({'page': 'marketing', 'tab': tab, 'edit_' + kind: 'editorial-' + kind + '-' + name})


def record(snapshot: dict, collection: str, name: str):
    kind = {'posts': 'post', 'banners': 'banner', 'landing_pages': 'page'}[collection]
    matches = [item for item in snapshot['marketing'][collection] if item['id'] == 'editorial-' + kind + '-' + name]
    check(len(matches) == 1, 'The reserved record is missing or duplicated.')
    return matches[0]


def protected(before: dict, after: dict):
    check(before['protected'] == after['protected'] and before['unrelated'] == after['unrelated']
          and before['outbox'] == after['outbox'], 'Editorial actions changed configuration, uploads, backup, unrelated rows or outbox.')
    check(all(before['marketing'][key] == after['marketing'][key] for key in FROZEN),
          'Editorial actions changed a frozen audience, contact, consent, opt-out, campaign or queue snapshot.')
    for collection in ('posts', 'banners', 'landing_pages'):
        check(record(before, collection, 'legacy') == record(after, collection, 'legacy')
              and 'access' not in record(after, collection, 'legacy'),
              'An absent legacy policy was rewritten or its original record changed.')
    check(before['marketing']['events'][0] == after['marketing']['events'][0], 'The preexisting event record changed.')
    if before['mode'] == 'mysql' and before['status']['state'] == 'mysql':
        check(before['legacy_sha256'] == after['legacy_sha256'], 'SQL editorial changes rewrote the preserved original JSON file.')


def independent_sql(snapshot: dict):
    data = snapshot['sql']
    check(snapshot['mode'] == 'mysql' and snapshot['status']['state'] == 'mysql' and data is not None,
          'This acceptance did not actually activate SQL marketing storage.')
    states, rows = data['marketing_storage_state'], data['marketing_storage_records']
    check(len(states) == 1 and states[0]['status'] == 'active' and len(data['authority']) == 1,
          'An active SQL state and independent authority record were not committed.')
    state = states[0]
    header, layout = json.loads(state['header_json']), json.loads(state['layout_json'])
    decoded = {}
    for collection in COLLECTIONS:
        entries = sorted((row for row in rows if row['collection'] == collection), key=lambda row: int(row['position']))
        check([int(row['position']) for row in entries] == list(range(len(entries)))
              and [json.loads(row['entry_key_json']) for row in entries] == list(range(len(entries))),
              'SQL collection keys or ordering changed: ' + collection)
        decoded[collection] = [json.loads(row['payload_json']) for row in entries]
    reconstructed = {key: decoded[key] if key in decoded else header[key] for key in layout}
    check(reconstructed == snapshot['marketing'] and list(reconstructed) == list(snapshot['marketing']),
          'Independent SQL payload/header/layout readback does not reproduce the saved marketing snapshot.')
    check(state['source_sha256'] == snapshot['legacy_sha256']
          and int(state['revision']) == snapshot['marketing']['meta']['revision'], 'SQL source preservation or revision differs.')


def activate_mysql(owner, base: str, site: Path, php: str):
    before = fixture(site, 'read', php)
    check(before['status']['state'] == 'ready' and not before['sql']['marketing_storage_state']
          and not before['sql']['marketing_storage_records'] and not before['sql']['authority'],
          'MySQL acceptance must start with actual installer schema and unmoved file data.')
    url = base + '/admin/?page=marketing-storage'
    page = owner.get(url, timeout=15)
    values = fields(page.text, 'form[data-storage-preview-form]')
    review = owner.post(url, data=values, timeout=20)
    check(review.status_code == 200, 'The real owner storage review failed.')
    move = fields(review.text, 'form[data-storage-migrate-form]')
    response = owner.post(url, data=move, timeout=25)
    check(response.status_code == 200 and response.history and response.request.method == 'GET',
          'The actual owner SQL move failed to commit and redirect.')
    after = fixture(site, 'read', php)
    independent_sql(after)
    check(before['marketing'] == after['marketing'] and before['legacy_sha256'] == after['legacy_sha256'],
          'The actual SQL cutover changed the legacy publication/policy snapshot.')
    protected(before, after)
    return after


def public_record(client, base: str, kind: str, name: str, allowed: bool):
    path = '/blog/post.php' if kind == 'post' else '/campaign/'
    url = route(base, path + '?slug=editorial-' + kind + '-' + name)
    response = client.get(url, timeout=15)
    private(response)
    title = 'Editorial ' + kind + ' ' + name
    if allowed:
        check(response.status_code == 200 and title in response.text and 'PRIVATE_BODY_' + kind + '_' + name in response.text,
              'An eligible reader lost the saved ' + kind + ' ' + name)
    else:
        check(response.status_code == 404 and not any(marker in response.text for marker in
              (title, 'PRIVATE_BODY_' + kind + '_' + name, 'PRIVATE_EXCERPT_' + kind + '_' + name,
               'PRIVATE_SEO_' + kind + '_' + name, 'PRIVATE_META_' + kind + '_' + name)),
              'A denied record exposed its status, title, body, excerpt or metadata: ' + url)
        soup = BeautifulSoup(response.text, 'html.parser')
        check(soup.title is not None and ('Update Not Found' if kind == 'post' else 'Campaign Not Found') in soup.title.get_text(),
              'Denied editorial content did not use the generic absent-record response.')
    return response


def public_matrix(clients: dict, base: str, site: Path, php: str):
    results = {}
    for name, client in clients.items():
        member = name not in ('guest', 'owner', 'revoked', 'inactive', 'unverified', 'deleted', 'future-verified')
        gold = name in ('gold', 'trial', 'grace', 'just-started', 'current-grace')
        silver = name == 'silver'
        allowed = {'legacy', 'public'} | ({'members'} if member else set()) | ({'gold', 'mixed'} if gold else set()) | ({'silver'} if silver else set())
        page = client.get(route(base, 'blog/'), timeout=15)
        private(page)
        soup = BeautifulSoup(page.text, 'html.parser')
        titles = {element.get_text(strip=True) for element in soup.select('.marketing-grid .marketing-card h2')}
        check(page.status_code == 200 and titles == {'Editorial post ' + policy for policy in allowed},
              'Blog filtering leaked or suppressed titles/excerpts for ' + name + ': ' + repr(titles))
        for policy in set(POLICIES) - allowed:
            check('PRIVATE_EXCERPT_post_' + policy not in page.text and 'PRIVATE_CATEGORY_' + policy not in page.text,
                  'A denied post leaked excerpt/category into the listing for ' + name)
        banner = 'gold' if gold else ('silver' if silver else ('members' if member else 'legacy'))
        check('EDITORIAL_BANNER_' + banner in page.text and 'EDITORIAL_BANNER_malformed' not in page.text
              and 'EDITORIAL_BANNER_future' not in page.text,
              'The first eligible banner was not chosen for ' + name)
        if not gold:
            check('EDITORIAL_BANNER_gold' not in page.text, 'An ineligible reader received the tier banner.')
        policies = POLICIES if name in ('guest', 'free', 'gold', 'silver', 'owner') else ('gold', 'members')
        for policy in policies:
            for kind in ('post', 'page'):
                public_record(client, base, kind, policy, policy in allowed)
        results[name] = {'member_access': member, 'gold_access': gold, 'silver_access': silver,
                         'visible_post_policies': sorted(allowed), 'banner': banner}
    for name in ('guest', 'free', 'gold'):
        expected = {'guest': 'legacy', 'free': 'members', 'gold': 'gold'}[name]
        for path in ('/newsletter/', '/links/'):
            response = clients[name].get(route(base, path), timeout=15)
            private(response)
            check(response.status_code == 200 and 'EDITORIAL_BANNER_' + expected in response.text,
                  'A secondary marketing shell ignored editorial banner access: ' + name + path)
    saved = fixture(site, 'read', php)
    check(saved['guest_post_limit'] == ['editorial-post-public'],
          'The actual package applied the post limit before removing newer inaccessible records.')
    return results


def non_utc_membership_cases(matrix: dict, saved: dict):
    """Cross-check real HTTP decisions against API-created, near-now dates.

    Subscription SQL DATETIME values are UTC. Verification SQL DATETIME values
    use the connection timezone, so its independent SQL epoch is inspected
    separately. File records retain their explicit ISO offsets.
    """
    check(saved['site_timezone'] == 'America/Chicago', 'The actual installed runtime did not use the non-UTC site timezone.')
    clock = saved['membership_clock']
    check(clock['fixture_epoch'] <= clock['observed_epoch'] < clock['fixture_epoch'] + 1800,
          'Near-boundary fixture dates are no longer near the actual HTTP requests.')
    check(clock['free_verification_method'] == 'markMemberVerified', 'The free member bypassed the real verification API.')
    is_sql = saved['mode'] == 'mysql'
    if is_sql:
        check(isinstance(saved['sql_timezone'], dict)
              and set(saved['sql_timezone']) == {'session_timezone', 'system_timezone', 'session_offset_seconds'},
              'The actual SQL connection timezone was not recorded.')
    else:
        check(saved['sql_timezone'] is None, 'The file context unexpectedly inspected SQL timezone state.')

    def iso_epoch(value):
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
        check(parsed.tzinfo is not None, 'An API-created file date lost its explicit timezone offset.')
        return int(parsed.timestamp())

    verification_epochs = {}
    for name in ('free', 'future-verified'):
        observed = clock['verification'][name]
        check(isinstance(observed['raw'], str) and bool(observed['raw']), 'A verification date disappeared from the real Store.')
        if is_sql:
            check(re.fullmatch(r'\d{4}-\d\d-\d\d \d\d:\d\d:\d\d', observed['raw']) is not None
                  and isinstance(observed['sql_epoch'], int), 'The actual SQL verification representation was not inspected.')
            verification_epochs[name] = observed['sql_epoch']
        else:
            verification_epochs[name] = iso_epoch(observed['raw'])
    check(clock['fixture_epoch'] <= verification_epochs['free'] <= clock['observed_epoch'],
          'Real markMemberVerified did not persist a current verification instant.')
    check(verification_epochs['future-verified'] > clock['observed_epoch'] + 1800,
          'The future-verification negative control was not future-dated in its storage timezone.')
    check(matrix['free']['member_access'] and not matrix['free']['gold_access'],
          'A just-verified free member did not receive exactly member-level access.')
    check(not matrix['future-verified']['member_access'] and not matrix['future-verified']['gold_access'],
          'A future verification date granted member or tier access.')

    expected = {'just-started': True, 'just-expired': False, 'current-grace': True, 'grace-ended': False}
    check(set(clock['subscription_inputs']) == set(expected) and set(clock['subscriptions']) == set(expected),
          'A required near-boundary subscription was omitted from the fixture.')
    decisions = {}
    for name, tier_access in expected.items():
        supplied = clock['subscription_inputs'][name]
        rows = clock['subscriptions'][name]
        bounds = {}
        check(len(rows) == 1 and rows[0]['status'] == supplied['status']
              and str(rows[0]['memberId']) == str(supplied['memberId'])
              and str(rows[0]['planId']) == str(supplied['planId']),
              'The real Store did not preserve the intended near-boundary subscription: ' + name)
        for key in ('currentPeriodStart', 'currentPeriodEnd', 'graceEndsAt'):
            actual, source = rows[0][key], supplied[key]
            if source is None:
                check(actual is None, 'An absent grace bound was changed: ' + name)
                bounds[key] = None
                continue
            expected_epoch = iso_epoch(source)
            bounds[key] = expected_epoch - clock['fixture_epoch']
            if is_sql:
                check(isinstance(actual, str) and re.fullmatch(r'\d{4}-\d\d-\d\d \d\d:\d\d:\d\d', actual) is not None,
                      'The SQL subscription fixture did not exercise a raw UTC DATETIME: ' + name + key)
                actual_epoch = int(datetime.strptime(actual, '%Y-%m-%d %H:%M:%S').replace(tzinfo=timezone.utc).timestamp())
            else:
                actual_epoch = iso_epoch(actual)
            check(actual_epoch == expected_epoch, 'Subscription storage shifted the API-supplied instant: ' + name + key)
        check(bounds == {'currentPeriodStart': -120 if name == 'just-started' else -7200,
                         'currentPeriodEnd': 3600 if name == 'just-started' else -120,
                         'graceEndsAt': 3600 if name == 'current-grace' else (-120 if name == 'grace-ended' else None)},
              'A near-boundary control no longer uses the intended relative instants: ' + name)
        check(matrix[name]['member_access'] and matrix[name]['gold_access'] is tier_access,
              'A recent subscription/grace boundary produced the wrong member/tier HTTP result: ' + name)
        decisions[name] = {'member_access': True, 'gold_access': tier_access, 'status': supplied['status'],
                           'bounds_seconds_from_fixture': bounds}
    return {'site_timezone': saved['site_timezone'], 'sql_timezone': saved['sql_timezone'],
            'free_verified_via_store_api': True, 'free_verification_near_now': True,
            'future_verification_refused': True, 'subscription_instants_preserved': True,
            'sql_connection_verification_dates_checked': is_sql,
            'sql_utc_subscription_dates_checked': is_sql, 'file_iso_subscription_dates_checked': not is_sql,
            'expired_and_grace_ended_keep_member_access_only': True, 'decisions': decisions}


def preview_cases(owner, clients: dict, base: str, site: Path, php: str):
    before = fixture(site, 'read', php)
    for collection, kind in (('posts', 'post'), ('banners', 'banner'), ('landing_pages', 'page')):
        url = preview_url(base, collection)
        response = owner.get(url + '&ref=EDITORIAL&title=UNSAVED_PREVIEW_INJECTION&body=UNSAVED_PREVIEW_INJECTION', timeout=15)
        private(response, True)
        soup = BeautifulSoup(response.text, 'html.parser')
        check(response.status_code == 200 and soup.select_one('[data-marketing-preview]') is not None
              and ('EDITORIAL_BANNER_draft' if kind == 'banner' else 'PRIVATE_BODY_' + kind + '_draft') in response.text
              and 'UNSAVED_PREVIEW_INJECTION' not in response.text,
              'The owner preview did not render only the saved draft: ' + collection)
        check(not soup.select('[data-marketing-event]') and 'marketing.js' not in response.text and 'site.js' not in response.text,
              'The private saved preview contains public tracking hooks or boot scripts.')
        if kind in ('banner', 'page'):
            inert = soup.select('[data-preview-inert]')
            check(bool(inert) and all(not element.has_attr('href') and not element.has_attr('onclick') for element in inert),
                  'A preview call to action can navigate into a tracking/public route.')
        rejected = owner.post(url, data={'title': 'MUST_NOT_SAVE', '_csrf': 'invalid'}, timeout=15)
        private(rejected, True)
        check(rejected.status_code == 405, 'The saved preview accepted a non-GET request.')
        for name in ('guest', 'free'):
            denied = clients[name].get(url, allow_redirects=False, timeout=15)
            private(denied, True)
            check(denied.status_code == 403 and 'Preview unavailable.' in denied.text
                  and 'PRIVATE_BODY_' not in denied.text and 'EDITORIAL_BANNER_' not in denied.text,
                  'A nonowner received private saved content or a revealing preview response.')
    bad_urls = [base + '/admin/?page=marketing-preview&collection=contacts&id=editorial-post-draft',
                base + '/admin/?page=marketing-preview&collection=posts&id=unknown-saved-record',
                base + '/admin/?page=marketing-preview&collection[]=posts&id=editorial-post-draft',
                base + '/admin/?page=marketing-preview&collection=posts&id[]=editorial-post-draft']
    for url in bad_urls:
        denied = owner.get(url, timeout=15)
        private(denied, True)
        check(denied.status_code == 404 and 'Preview unavailable.' in denied.text and 'PRIVATE_BODY_' not in denied.text,
              'Invalid saved-preview identifiers did not produce the generic404.')
    for status in ('demoted', 'inactive'):
        fixture(site, 'owner-status', php, status=status)
        try:
            denied = owner.get(preview_url(base, 'posts'), allow_redirects=False, timeout=15)
            private(denied, True)
            check(denied.status_code == 403 and 'Preview unavailable.' in denied.text, 'A changed owner retained preview access: ' + status)
        finally:
            fixture(site, 'owner-status', php, status='owner')
    stale = fixture(site, 'stale-owner-session', php)
    stale_client = session()
    try:
        stale_client.cookies.set(stale['name'], stale['id'], domain='127.0.0.1', path='/')
        denied = stale_client.get(preview_url(base, 'posts'), allow_redirects=False, timeout=15)
        private(denied, True)
        check(denied.status_code == 403, 'A stale owner identity inherited the current owner email/role.')
    finally:
        stale_client.close()
    forged = clients['guest'].get(preview_url(base, 'posts') + '&owner=1&preview=1&access_mode=public',
                                  headers={'X-Owner': '1', 'X-Preview': '1'}, timeout=15)
    check(forged.status_code == 403, 'Client preview/owner flags created authority.')
    after = fixture(site, 'read', php)
    check(before['marketing'] == after['marketing'] and before['sql'] == after['sql']
          and before['legacy_sha256'] == after['legacy_sha256'],
          'Preview success/denial/method checks wrote publication, page-view, referral, campaign or SQL records.')
    protected(before, after)


def noninitializing_preview(owner, base: str, site: Path, php: str, mode: str):
    before = fixture(site, 'storage-files', php)
    held = fixture(site, 'hold-source', php)
    try:
        response = owner.get(preview_url(base, 'posts'), timeout=15)
        private(response, True)
        check(response.status_code == (200 if mode == 'mysql' else 404),
              'Saved preview did not distinguish absent file storage from authoritative SQL without a local guard.')
        check(('PRIVATE_BODY_post_draft' in response.text) == (mode == 'mysql'),
              'The absent-source saved preview served the wrong data source.')
        check(fixture(site, 'storage-files', php) == held,
              'Saved preview initialized a missing marketing directory, file, SQL guard, authority or rows.')
        if mode == 'mysql':
            blocked = fixture(site, 'hold-sql-state', php)
            try:
                denied = owner.get(preview_url(base, 'posts'), timeout=15)
                private(denied, True)
                check(denied.status_code == 404 and 'Preview unavailable.' in denied.text
                      and fixture(site, 'storage-files', php) == blocked,
                      'A preview with unavailable authoritative SQL recreated state or exposed archived content.')
            finally:
                fixture(site, 'restore-sql-state', php)
    finally:
        fixture(site, 'restore-source', php)
    check(fixture(site, 'storage-files', php) == before, 'Restoring a held source did not preserve exact storage bytes/rows.')
    if mode == 'mysql':
        for hold, restore in (('hold-sql-state', 'restore-sql-state'), ('hold-sql-authority', 'restore-sql-authority')):
            blocked = fixture(site, hold, php)
            try:
                denied = owner.get(preview_url(base, 'posts'), timeout=15)
                private(denied, True)
                check(denied.status_code == 404 and 'Preview unavailable.' in denied.text and 'PRIVATE_BODY_' not in denied.text,
                      'Required SQL intent allowed preview fallback when authoritative storage was unavailable.')
                check(fixture(site, 'storage-files', php) == blocked,
                      'A refused preview changed preserved files, SQL authority/state or outbox.')
            finally:
                fixture(site, restore, php)
        check(fixture(site, 'storage-files', php) == before, 'SQL failure acceptance did not restore exact fixture state.')


def second_root_sql_preview(owner, base: str, site: Path, php: str):
    """A second authorized package root has SQL authority but no local marketing directory."""
    original = fixture(site, 'storage-files', php)
    prefix = urlparse(base).path.rstrip('/')
    with tempfile.TemporaryDirectory(prefix='vvx-editorial-second-root-') as temporary:
        work = Path(temporary)
        document_root = work / 'www'
        clone = document_root / prefix.lstrip('/')
        shutil.copytree(site, clone)
        check((clone / '.vvx-test-runtime').is_file()
              and all((clone / name).read_bytes() == (site / name).read_bytes() for name in UI_FILES),
              'The second root must be an exact copy of the actual extracted runtime under test.')
        shutil.rmtree(clone / 'storage/private/marketing')
        immutable = {name: hashlib.sha256((clone / name).read_bytes()).hexdigest()
                     for name in ('config/app.php', 'storage/install.lock', *UI_FILES)}
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            port = available.getsockname()[1]
        new_base = f'http://127.0.0.1:{port}{prefix}'
        environment = os.environ.copy()
        environment.pop('PHP_CLI_SERVER_WORKERS', None)
        client = session()
        client.cookies.update(owner.cookies)
        with (work / 'php-server.log').open('w+') as log:
            process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S',
                                        f'127.0.0.1:{port}', '-t', str(document_root)],
                                       cwd=work, env=environment, stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 15
                while True:
                    if process.poll() is not None or time.monotonic() >= deadline:
                        raise AssertionError('The second marked package root did not start.')
                    try:
                        response = client.get(preview_url(new_base, 'posts'), timeout=1)
                        break
                    except requests.RequestException:
                        time.sleep(.1)
                private(response, True)
                check(response.status_code == 200 and 'PRIVATE_BODY_post_draft' in response.text
                      and response.url.startswith(new_base + '/admin/'),
                      'An active owner at the second package root could not read the authoritative saved SQL preview.')
                check(not (clone / 'storage/private/marketing').exists()
                      and fixture(site, 'storage-files', php) == original,
                      'Second-root preview created a local SQL guard, file/directory or changed authoritative rows.')
                blocked = fixture(site, 'hold-sql-state', php)
                try:
                    denied = client.get(preview_url(new_base, 'posts'), timeout=15)
                    private(denied, True)
                    check(denied.status_code == 404 and 'Preview unavailable.' in denied.text
                          and not (clone / 'storage/private/marketing').exists()
                          and fixture(site, 'storage-files', php) == blocked,
                          'Second-root preview fell back or initialized storage after authoritative SQL failed.')
                finally:
                    fixture(site, 'restore-sql-state', php)
                check(all(hashlib.sha256((clone / name).read_bytes()).hexdigest() == digest
                          for name, digest in immutable.items()), 'Second-root preview changed its package/config/installation files.')
            finally:
                client.close()
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        check(fixture(site, 'storage-files', php) == original, 'Second-root SQL failure acceptance did not preserve the exact original state.')


def owner_revoked_during_catalog_read(owner, base: str, site: Path, php: str):
    """Observe the actual request blocked on a real MySQL table lock, then revoke."""
    results = []
    url = editor_url(base, 'posts')
    for action, status in (('preview', 'demoted'), ('save', 'inactive'), ('enable', 'demoted')):
        page = owner.get(url, timeout=15)
        selector = ('form[data-publication-editor="posts"]' if action == 'save' else
                    '[data-publication-id="editorial-post-draft"] form:has(input[name="action"][value="set_publication_enabled"])')
        values = fields(page.text, selector) if action != 'preview' else None
        before = fixture(site, 'read', php)
        token = secrets.token_hex(8)
        release = site / ('storage/temp/editorial-catalog-' + token + '.release')
        worker = subprocess.Popen([php, str(FIXTURE), str(site), 'catalog-lock'],
                                  stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd='/tmp')
        try:
            worker.stdin.write(json.dumps({'token': token}))
            worker.stdin.close()
            check(bool(select.select([worker.stdout], [], [], 10)[0]), 'The owned MySQL catalog lock did not become ready.')
            ready = worker.stdout.readline()
            check(bool(ready) and json.loads(ready).get('locked') is True, 'The fixture did not acquire its real MySQL WRITE table lock.')
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                pending = (pool.submit(owner.get, preview_url(base, 'posts'), timeout=25)
                           if action == 'preview' else pool.submit(owner.post, url, data=values, timeout=25))
                try:
                    deadline = time.monotonic() + 10
                    waiting = []
                    observed = {}
                    while not pending.done() and time.monotonic() < deadline:
                        observed = fixture(site, 'catalog-wait', php, _count=False)
                        waiting = observed['waiting_connections']
                        if waiting:
                            break
                        time.sleep(.02)
                    check(len(waiting) == 1 and not pending.done(),
                          'The real ' + action + ' request never reached a blocked membership catalog SELECT: ' + repr(observed))
                    fixture(site, 'owner-status', php, status=status)
                finally:
                    release.write_text('Release the exclusively owned editorial catalog lock.\n')
                response = pending.result(timeout=25)
                check(response.status_code == 403, 'An owner revoked during the blocked catalog read completed ' + action + '.')
                if action == 'preview':
                    private(response, True)
                    check(response.text == 'Preview unavailable.', 'Revoked preview emitted saved content after catalog wait.')
        finally:
            if not release.exists():
                release.write_text('Release the exclusively owned editorial catalog lock after failure.\n')
            try:
                worker.wait(timeout=8)
            except subprocess.TimeoutExpired:
                worker.terminate()
                worker.wait(timeout=5)
            stderr = worker.stderr.read()
            check(worker.returncode == 0, 'The owned catalog lock worker failed: ' + stderr[:1000])
            worker.stdout.close()
            worker.stderr.close()
            fixture(site, 'owner-status', php, status='owner')
            release.unlink(missing_ok=True)
        after = fixture(site, 'read', php)
        check(before['marketing'] == after['marketing'] and before['sql'] == after['sql']
              and before['legacy_sha256'] == after['legacy_sha256'] and before['outbox'] == after['outbox'],
              'Revocation during catalog wait changed saved marketing, events, SQL payload or mail state.')
        protected(before, after)
        results.append({'action': action, 'owner_change': status, 'observed_catalog_select_wait': True,
                        'http_status': 403, 'exact_state_preserved': True})
    return results


def editor_cases(owner, clients: dict, base: str, site: Path, php: str):
    before_all = fixture(site, 'read', php)
    actions = []
    for collection in ('posts', 'banners', 'landing_pages'):
        url = editor_url(base, collection)
        page = owner.get(url, timeout=15)
        selector = 'form[data-publication-editor="' + collection + '"]'
        values = fields(page.text, selector)
        soup = BeautifulSoup(page.text, 'html.parser')
        mode = soup.select_one(selector + ' select[data-editorial-access]')
        check(mode is not None and mode.get('name') == 'access_mode' and dict(values)['access_mode'] == 'tiers',
              'The saved restricted policy did not reopen accurately: ' + collection)
        links = soup.select('a[data-publication-preview]')
        check(any(element.get('href') == preview_url(base, collection) for element in links),
              'The saved preview link lost its record identity or installation prefix.')
        old = fixture(site, 'read', php)
        variants = [changed(values, _csrf='invalid'), changed(values, access_mode='unknown'),
                    changed(values, access_mode='tiers', **{'access_plan_ids[]': []}),
                    changed(values, access_mode=None, **{'access_mode[]': 'public'}),
                    changed(values, **{'access_plan_ids[]': None, 'access_plan_ids': old['plans']['gold']}),
                    changed(values, **{'access_plan_ids[]': ['999999999999999999']}),
                    changed(values, **{'access_plan_ids[]': ['0' + old['plans']['gold']]}),
                    changed(values, **{'access_plan_ids[]': [old['plans']['gold'], old['plans']['gold']]})]
        for variant, values_bad in enumerate(variants):
            rejected = owner.post(url, data=values_bad, timeout=20)
            rejected_form(rejected, 'Malformed policy/form/CSRF input ' + collection + '/' + str(variant))
            current = fixture(site, 'read', php)
            check(current['marketing'] == old['marketing'] and current['legacy_sha256'] == old['legacy_sha256']
                  and current['sql'] == old['sql'], 'A rejected editor submission changed saved data.')
        get_attempt = owner.get(url + '&action=save_post&access_mode=public&title=GET_MUST_NOT_SAVE', timeout=15)
        check(get_attempt.status_code == 200 and fixture(site, 'read', php)['marketing'] == old['marketing'],
              'A GET changed the saved editorial policy.')
        denied = clients['free'].post(url, data=values, allow_redirects=False, timeout=15)
        check(denied.status_code in (302, 303, 401, 403) and fixture(site, 'read', php)['marketing'] == old['marketing'],
              'A member session submitted an owner publication edit.')
        original = record(old, collection, 'draft')
        submitted = changed(values, access_mode='members', **{'access_plan_ids[]': []})
        saved = owner.post(url, data=submitted, timeout=20)
        check(saved.status_code == 200 and saved.history and saved.request.method == 'GET',
              'A valid access change failed to use POST/Redirect/GET: ' + collection)
        current = fixture(site, 'read', php)
        updated = record(current, collection, 'draft')
        check(updated['access'] == {'version': 1, 'mode': 'members', 'plan_ids': []}
              and updated['legacy_private_note'] == original['legacy_private_note']
              and updated['seo_title'] == original['seo_title'], 'Saving access lost policy or unknown/editorial metadata.')
        stale = owner.post(url, data=changed(values, access_mode='public', **{'access_plan_ids[]': []}), timeout=20)
        rejected_form(stale, 'Stale saved policy revision')
        check(fixture(site, 'read', php)['marketing'] == current['marketing'],
              'An old policy revision overwrote a newer saved restriction.')
        fresh = fields(owner.get(url, timeout=15).text, selector)
        omitted = changed(fresh, access_mode=None, **{'access_plan_ids[]': None})
        kept = owner.post(url, data=omitted, timeout=20)
        check(kept.status_code == 200 and record(fixture(site, 'read', php), collection, 'draft')['access'] == updated['access'],
              'Omitted legacy form fields silently reset a saved restriction.')
        fresh = fields(owner.get(url, timeout=15).text, selector)
        restored = owner.post(url, data=changed(fresh, access_mode='tiers', **{'access_plan_ids[]': [old['plans']['gold']]}), timeout=20)
        check(restored.status_code == 200 and record(fixture(site, 'read', php), collection, 'draft')['access'] == original['access'],
              'The selected-tier policy did not round-trip through the actual owner form.')
        actions.append(dict(values)['action'])
    protected(before_all, fixture(site, 'read', php))
    return actions


def changes_next_request(clients: dict, owner, base: str, site: Path, php: str):
    client = clients['gold']
    fixture(site, 'subscription-state', php, member='gold', status='cancelled')
    public_record(client, base, 'post', 'gold', False)
    public_record(client, base, 'post', 'members', True)
    fixture(site, 'subscription-state', php, member='gold', status='active', plan='silver')
    public_record(client, base, 'page', 'gold', False)
    public_record(client, base, 'page', 'silver', True)
    fixture(site, 'subscription-state', php, member='gold', status='active', plan='gold')
    public_record(client, base, 'post', 'gold', True)
    url = editor_url(base, 'posts')
    reviewed = owner.get(url, timeout=15)
    stale_editor = fields(reviewed.text, 'form[data-publication-editor="posts"]')
    stale_enable = fields(reviewed.text, '[data-publication-id="editorial-post-draft"] form:has(input[name="action"][value="set_publication_enabled"])')
    check(dict(stale_enable).get('enabled') == '1', 'The catalogue-drift enable case must start with a disabled saved draft.')
    fixture(site, 'plan-state', php, plan='gold', state='disabled')
    try:
        public_record(client, base, 'post', 'gold', False)
        public_record(client, base, 'page', 'mixed', False)
        unchanged = fixture(site, 'read', php)
        for reviewed_values in (stale_editor, stale_enable):
            denied = owner.post(url, data=reviewed_values, timeout=20)
            rejected_form(denied, 'Tier disabled after the owner opened save/enable form')
            check(fixture(site, 'read', php)['marketing'] == unchanged['marketing'],
                  'A stale tier catalogue allowed an owner save/enable to publish unavailable access.')
        page = owner.get(url, timeout=15)
        values = fields(page.text, 'form[data-publication-editor="posts"]')
        check(dict(values)['access_mode'] == 'tiers' and BeautifulSoup(page.text, 'html.parser').select_one('[data-editorial-access-problem]') is not None,
              'A disabled selected tier was silently converted to Public in the owner editor.')
        before = fixture(site, 'read', php)
        rejected = owner.post(url, data=values, timeout=20)
        rejected_form(rejected, 'Unavailable saved tier')
        check(fixture(site, 'read', php)['marketing'] == before['marketing'],
              'A saved unavailable tier was accepted without an explicit valid replacement.')
    finally:
        fixture(site, 'plan-state', php, plan='gold', state='active')
    public_record(client, base, 'post', 'gold', True)
    fixture(site, 'plan-state', php, plan='lost', state='remove')
    public_record(client, base, 'post', 'mixed', False)
    public_record(client, base, 'page', 'mixed', False)
    # A malformed saved policy must reopen as an explicit review choice, not
    # Public, and cannot be normalized by a forged or omitted browser value.
    malformed = owner.get(editor_url(base, 'posts', 'malformed'), timeout=15)
    values = dict(fields(malformed.text, 'form[data-publication-editor="posts"]'))
    check(values.get('access_mode') == '' and BeautifulSoup(malformed.text, 'html.parser').select_one('[data-editorial-access-problem]') is not None,
          'A malformed saved access policy was silently shown as Public.')


def http_case(site: Path, base: str, php: str, capture, activate=True):
    start = checks
    initial = fixture(site, 'read', php)
    clients = {'guest': session(), 'owner': owner_login(base)}
    try:
        if initial['mode'] == 'mysql' and activate:
            initial = activate_mysql(clients['owner'], base, site, php)
        elif initial['mode'] == 'mysql':
            independent_sql(initial)
        else:
            check(initial['status']['state'] == 'file' and initial['sql'] is None, 'The file context unexpectedly used SQL storage.')
        for name in MEMBERS:
            clients[name] = member_login(base, name)
        for name in ('revoked', 'inactive', 'unverified', 'deleted', 'future-verified'):
            fixture(site, 'member-state', php, member=name, state=name)
        matrix = public_matrix(clients, base, site, php)
        time_edges = non_utc_membership_cases(matrix, fixture(site, 'read', php))
        preview_cases(clients['owner'], clients, base, site, php)
        noninitializing_preview(clients['owner'], base, site, php, initial['mode'])
        if initial['mode'] == 'mysql':
            second_root_sql_preview(clients['owner'], base, site, php)
        catalog_waits = owner_revoked_during_catalog_read(clients['owner'], base, site, php) if initial['mode'] == 'mysql' else []
        actions = editor_cases(clients['owner'], clients, base, site, php)
        changes_next_request(clients, clients['owner'], base, site, php)
        final = fixture(site, 'read', php)
        protected(initial, final)
        if initial['mode'] == 'mysql':
            independent_sql(final)
        check(capture.connections == 0 and not capture.payloads and not capture.errors,
              'Editorial reads, previews or saves attempted SMTP delivery.')
        return {'checks': checks - start, 'storage_mode': final['status']['mode'], 'mysql_version': final['mysql_version'],
                'site_timezone': final['site_timezone'], 'membership_time_edges': time_edges,
                'member_matrix': matrix, 'actual_member_password_logins': True,
                'actual_owner_sql_activation': initial['mode'] == 'mysql',
                'independent_sql_readback': initial['mode'] == 'mysql',
                'publication_access_and_timing_before_titles_metadata_and_limits': True,
                'first_eligible_banner_on_all_marketing_shells': True,
                'subscription_plan_member_and_device_changes_on_next_request': True,
                'malformed_unknown_and_lost_plan_policies_hold': True,
                'generic404_and_private_no_store': True,
                'active_owner_saved_previews_all_three_collections': True,
                'preview_method_nonowner_stale_owner_and_client_flags_refused': True,
                'preview_writes_no_analytics_referrals_campaign_events_or_mail': True,
                'missing_marketing_storage_preview_never_initializes_files_or_guards': True,
                'sql_preview_without_local_guard_is_read_only_and_storage_failure_holds': initial['mode'] == 'mysql',
                'mysql_owner_revocation_during_real_catalog_waits': catalog_waits,
                'csrf_form_shape_and_stale_policy_edits_refused': True,
                'omitted_policy_fields_preserve_restrictions': True,
                'legacy_absent_policy_and_frozen_marketing_records_preserved': True,
                'source_config_uploads_backups_and_outbox_preserved': True,
                'owner_edit_actions': actions, 'smtp_connections': capture.connections,
                'smtp_messages': len(capture.payloads), 'original_json_sha256': initial['legacy_sha256'],
                'final_json_sha256': final['legacy_sha256']}
    finally:
        for client in clients.values():
            client.close()


def prepare_package(work: Path):
    package = load('vvx_editorial_package', ROOT / 'scripts/package.py')
    expected = os.environ.get('VELVET_EXPECTED_FRESH_SHA256', '').lower()
    exploratory = os.environ.get('VELVET_EDITORIAL_EXPLORATORY') == '1'
    if os.environ.get('VELVET_FRESH_PACKAGE'):
        archive = Path(os.environ['VELVET_FRESH_PACKAGE']).resolve()
        check(archive.is_file(), 'The supplied actual Fresh ZIP does not exist.')
        check(bool(re.fullmatch(r'[a-f0-9]{64}', expected)) or exploratory,
              'Pin a supplied Fresh ZIP with VELVET_EXPECTED_FRESH_SHA256 for final acceptance.')
    else:
        built = package.build(ROOT, work / 'packages', VERSION)
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
    actual = hashlib.sha256(archive.read_bytes()).hexdigest()
    if expected:
        check(actual == expected, 'The actual Fresh ZIP differs from its expected immutable hash.')
    with ZipFile(archive) as zipped:
        files = {name: zipped.read(name) for name in zipped.namelist() if not name.endswith('/')}
    version, build = package.package_identity(files)
    check((version, build) == (VERSION, BUILD), 'Editorial acceptance requires 0.9.0 / Build20261021.4.')
    package.validate(files, 'fresh', version)
    check(set(UI_FILES) <= files.keys() and all(files[name] == (ROOT / name).read_bytes() for name in UI_FILES),
          'The actual Fresh ZIP does not contain the exact editorial runtime sources under test.')
    check(not any(name.startswith(('tests/', 'scripts/', 'docs/', 'api/', 'mail/', 'blog/', 'campaign/')) for name in files),
          'The customer archive contains private fixtures or disallowed root route folders.')
    frozen = work / 'verified-customer-fresh.zip'
    shutil.copyfile(archive, frozen)
    check(hashlib.sha256(frozen.read_bytes()).hexdigest() == actual, 'The supplied archive changed while its immutable test copy was created.')
    return frozen, {'version': version, 'build_id': build, 'fresh_zip_sha256': actual,
                     'expected_fresh_zip_sha256': expected or actual, 'externally_pinned_fresh': bool(expected),
                     'exploratory': exploratory, 'archive_runtime_matches_sources': True}


@contextmanager
def runtime(work: Path, archive: Path, mode: str, label: str, prefix: str, evidence: Path | None):
    php = shutil.which('php')
    check(php is not None, 'PHP is required for actual-package editorial access acceptance.')
    if mode == 'mysql':
        check(os.environ.get('VELVET_TEST_DB_NAME') and os.environ.get('VELVET_TEST_DB_USER'),
              'An empty disposable MySQL8 test database is required; SQL cannot be skipped.')
    document_root = work / label / 'www'
    site = document_root / prefix.lstrip('/')
    site.mkdir(parents=True)
    with ZipFile(archive) as zipped:
        zipped.extractall(site)
    (site / '.vvx-test-runtime').write_text('Disposable packaged editorial access acceptance.\n')
    with socket.socket() as available:
        available.bind(('127.0.0.1', 0))
        port = available.getsockname()[1]
    base = f'http://127.0.0.1:{port}{prefix}'
    smtp = load('vvx_editorial_smtp_' + label.replace('-', '_'), ROOT / 'tests/Http/mail_settings_http.py')
    capture = smtp.Capture()
    thread = threading.Thread(target=capture.serve_forever, daemon=True)
    thread.start()
    process = None
    log_path = work / label / 'php-server.log'
    try:
        fixture(site, 'setup-' + mode, php, base=base, smtp_port=capture.server_address[1])
        with log_path.open('w+') as log:
            environment = os.environ.copy()
            environment.pop('PHP_CLI_SERVER_WORKERS', None)
            process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S',
                                        f'127.0.0.1:{port}', '-t', str(document_root)],
                                       cwd=work, env=environment, stdout=log, stderr=log)
            client = session()
            deadline = time.monotonic() + 15
            try:
                while True:
                    if process.poll() is not None:
                        raise AssertionError('The disposable packaged PHP server exited.')
                    try:
                        if client.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                            break
                    except requests.RequestException:
                        pass
                    if time.monotonic() >= deadline:
                        raise AssertionError('The actual packaged PHP server did not start.')
                    time.sleep(.1)
            finally:
                client.close()
            yield {'site': site, 'base': base, 'php': php, 'capture': capture, 'mode': mode, 'label': label}
            log.flush()
            check(capture.connections == 0 and not capture.payloads and not capture.errors, 'An editorial action contacted SMTP.')
            check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()),
                  'Actual-package editorial acceptance raised PHP diagnostics.')
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
            fixture(site, 'cleanup-mysql', php)
        if evidence and log_path.is_file():
            shutil.copyfile(log_path, evidence / ('editorial-access-' + label + '-http.log'))


def evidence_directory():
    value = os.environ.get('VELVET_SCREENSHOT_DIR') or os.environ.get('VELVET_EDITORIAL_EVIDENCE_DIR')
    evidence = Path(value).resolve() if value else None
    if evidence:
        check(not evidence.is_relative_to(ROOT) or evidence.is_relative_to(ROOT / 'artifacts'),
              'Keep private fixture logs and evidence outside tracked/runtime source.')
        evidence.mkdir(parents=True, exist_ok=True)
    return evidence


def run():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='mysql')
    options = parser.parse_args()
    evidence = evidence_directory()
    summary = {'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without any router script', 'playwright_required': False, 'cases': {}}
    modes = ('file', 'mysql') if options.mode == 'both' else (options.mode,)
    with tempfile.TemporaryDirectory(prefix='vvx-editorial-http-') as temporary:
        work = Path(temporary)
        archive, identity = prepare_package(work)
        summary.update(identity)
        for mode in modes:
            for path_label, prefix in (('root', ''), ('nested', '/nested/velvet')):
                label = mode + '-' + path_label
                with runtime(work, archive, mode, label, prefix, evidence) as case:
                    summary['cases'][label] = http_case(case['site'], case['base'], case['php'], case['capture'])
                print('Editorial Access HTTP ' + label + ': PASS.', flush=True)
    if evidence:
        (evidence / 'editorial-access-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2), flush=True)
    print('Editorial access actual-package HTTP acceptance passed.', flush=True)


if __name__ == '__main__':
    run()
