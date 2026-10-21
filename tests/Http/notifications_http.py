#!/usr/bin/env python3
"""Installed-package optional notifications; actual durable state and local SMTP only."""
from __future__ import annotations

import argparse
import concurrent.futures
from copy import deepcopy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from zipfile import ZipFile

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vvx_notification_messages', ROOT / 'tests/Http/campaign_messages_http.py')
messages = importlib.util.module_from_spec(spec)
spec.loader.exec_module(messages)
http = messages.http
check = http.check
FIXTURE = Path(__file__).with_name('notifications_fixture.php')
TYPES = ['new_content', 'renewal_reminder', 'renewal', 'payment_failure', 'pending_registration', 'owner_security']
FILES = ('app/Core/PrivateState.php', 'app/Core/NotificationService.php', 'app/Core/NotificationDiscovery.php',
         'app/Core/NotificationStoreTrait.php', 'app/Core/Store.php', 'app/Core/MarketingStore.php',
         'public/assets/css/member.css', 'app/Core/MemberAuth.php', 'app/Core/MailService.php', 'app/Core/CampaignMessage.php',
         'app/Console/run_notifications.php', 'public/routes/account/register.php', 'public/routes/account/security.php',
         'admin/index.php', 'app/Views/admin/marketing.php', 'app/Views/admin/marketing-actions.php',
         'app/Views/admin/marketing-notifications.php', 'app/Views/admin/marketing-notifications-actions.php')


def prepare_package(work):
    archive, identity = messages.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in FILES), 'Fresh contains different notification runtime bytes.')
    return archive, identity


def fixture(case, action, **values):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action, json.dumps(values)], text=True,
                            capture_output=True, timeout=30, cwd='/tmp')
    check(result.returncode == 0, 'Owned notification fixture failed: ' + result.stderr[:1500])
    return json.loads(result.stdout)


def state(case):
    value = fixture(case, 'inspect')
    raw = value['raw']
    if raw:
        candidates = []
        def collect(item):
            if isinstance(item, str):
                try:
                    decoded = json.loads(item)
                except (ValueError, TypeError):
                    return
                if isinstance(decoded, (dict, list)):
                    collect(decoded)
            elif isinstance(item, dict):
                if item.get('schema') == 1 and 'jobs' in item and 'content_work' in item:
                    candidates.append(item)
                for child in item.values():
                    collect(child)
            elif isinstance(item, list):
                for child in item:
                    collect(child)
        collect(value['committed_sql'] if case['mode'] == 'mysql' else value['committed_file'])
        check(len(candidates) == 1 and candidates[0] == raw, 'Fresh API reads differ from independent committed notification bytes.')
    return value


def page(owner, case):
    response = owner.get(case['base'] + '/admin/?page=marketing&tab=notifications', timeout=20)
    check(response.status_code == 200 and 'data-notification-settings' in response.text, 'The actual notification page did not open.')
    http.private(response, True)
    return response


def post(owner, case, values):
    return owner.post(case['base'] + '/admin/?page=marketing&tab=notifications', data=values, timeout=35)


def form(owner, case, selector):
    return dict(http.fields(page(owner, case).text, selector))


def enable(owner, case):
    values = form(owner, case, '[data-notification-settings]')
    values.update(enabled='1', **{'types[]': TYPES})
    response = post(owner, case, values)
    check(response.status_code == 200 and response.history and 'Notification settings saved.' in response.text, 'Real owner notification settings could not be saved.')
    return values


def discover(owner, case):
    response = post(owner, case, form(owner, case, '[data-notification-discover]'))
    check(response.status_code == 200 and response.history, 'The real owner discovery action failed.')
    return state(case)


def run(owner, case, limit=10):
    values = form(owner, case, '[data-notification-run]')
    values['batch_limit'] = str(limit)
    return post(owner, case, values)


def worker(case, limit=10):
    result = subprocess.run([case['php'], str(case['site'] / 'app/Console/run_notifications.php'), '--limit=' + str(limit)],
                            text=True, capture_output=True, timeout=35, cwd='/tmp')
    check(result.returncode in (0, 2), 'The actual notification worker failed: ' + result.stdout[:1000] + result.stderr[:1000])
    return json.loads(result.stdout)


def create_one(owner, case, name):
    source = fixture(case, 'post', name=name)['post']
    saved = discover(owner, case)
    matching = [job for job in saved['raw']['jobs'].values() if job['source_id'] == 'post:' + source]
    check(len(matching) == 1 and matching[0]['status'] == 'queued', 'A new opted-in publication did not queue exactly one notice.')
    return matching[0]


def http_case(case):
    start = http.checks
    owner, guest = http.owner_login(case['base']), http.session()
    capture = case['capture']
    try:
        if case['mode'] == 'mysql':
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        initial = state(case)
        initial_page = page(owner, case)
        check(state(case)['raw'] == initial['raw'] and capture.connections == 0, 'GET initialized the journal or contacted SMTP.')
        settings = dict(http.fields(initial_page.text, '[data-notification-settings]'))
        settings.update(enabled='1', **{'types[]': TYPES})
        check(guest.get(initial_page.url, timeout=15).status_code == 403, 'Guest could view the private notification journal.')
        check(guest.post(initial_page.url, data=settings, timeout=15).status_code == 403, 'Guest could change notification delivery.')
        check(post(owner, case, {**settings, '_csrf': 'invalid'}).status_code == 419 and state(case)['raw'] == initial['raw'], 'Invalid CSRF changed notification settings.')
        old = enable(owner, case)
        after_enable = state(case)['raw']
        check(post(owner, case, old).status_code == 409 and state(case)['raw'] == after_enable, 'Stale master settings overwrote the latest revision.')
        check(owner.post(case['base'] + '/admin/?page=marketing&tab=posts', data=old, timeout=15).status_code == 400, 'Notification actions crossed into another editor.')
        seeds = fixture(case, 'seed')
        discovered = discover(owner, case)
        check(discovered['overview']['counts']['queued'] == 6, 'Real source discovery did not produce exactly six eligible workflows: ' + repr([job['type'] for job in discovered['raw']['jobs'].values()]))
        check(discover(owner, case)['raw'] == discovered['raw'], 'Repeated source discovery changed already planned work.')
        check('PRIVATE_BODY_SENTINEL' not in json.dumps(discovered['raw']) and 'PRIVATE_TITLE_SENTINEL' not in json.dumps(discovered['raw']), 'Notification journal copied private source text.')
        response = run(owner, case)
        delivered = state(case)
        check(response.status_code == 200 and response.history and len(capture.payloads) == 6, 'Actual owner run did not transmit six eligible notices once: ' + repr({'http_status': response.status_code, 'messages': len(capture.payloads), 'jobs': [(job['type'], job['status'], job['last_error']) for job in delivered['raw']['jobs'].values()]}))
        check(delivered['overview']['counts']['sent'] == 6, 'Transport results were not committed.')
        message_ids = set()
        verification_url = None
        for payload in capture.payloads:
            mime, text, html = messages.parsed(payload)
            identifier = str(mime['Message-ID'])
            message_ids.add(identifier)
            check(any(job['message_id'] == identifier and job['status'] == 'sent' for job in delivered['raw']['jobs'].values()), 'Captured message lacks its durable stable identity.')
            check('PRIVATE_' not in text and html is None, 'Generic notification exposed private content or added unreviewed HTML.')
            check(payload['recipient'] in {'owner@example.test', 'notice-reader@example.test', 'notice-pending@example.test'}, 'A non-consenting recipient received a notice.')
            if payload['recipient'] == 'notice-pending@example.test':
                verification_url = next((line for line in text.splitlines() if 'vvx_route=account%2Fverify' in line or 'vvx_route=account/verify' in line), None)
                check('24 hours' in text and 'no further registration reminders' in text, 'One-time registration reminder is not explicit.')
        check(len(message_ids) == 6 and verification_url and verification_url.startswith(case['base'] + '/'), 'Stable identities or the real nested verification link were lost.')
        verified = guest.get(verification_url, timeout=20)
        check(verified.status_code == 200 and next(row for row in state(case)['members'] if row['id'] == seeds['pending'])['status'] == 'active', 'Reminder link did not complete the actual member verification flow.')
        check(worker(case)['delivery']['accepted'] == 0 and len(capture.payloads) == 6, 'Repeated worker resent accepted notices.')
        canceled = create_one(owner, case, 'cancel')
        cancel_form = form(owner, case, f'[data-notification-id="{canceled["id"]}"] form')
        cancel_form['action'] = 'notification_cancel'
        check(post(owner, case, cancel_form).status_code == 200, 'Real owner cancel failed.')
        check(post(owner, case, cancel_form).status_code == 409, 'Replayed cancel did not reject the stale revision.')
        check(worker(case)['delivery']['accepted'] == 0 and state(case)['raw']['jobs'][canceled['id']]['status'] == 'canceled' and len(capture.payloads) == 6, 'Canceled notice was rediscovered or sent.')
        unknown = create_one(owner, case, 'unknown')
        capture.mode = 'unknown'
        check(run(owner, case, 1).status_code == 200 and len(capture.payloads) == 7, 'Unknown-outcome transport fixture was not reached.')
        unknown_saved = state(case)['raw']['jobs'][unknown['id']]
        check(unknown_saved['status'] == 'unknown', 'Lost DATA acknowledgement became a known failure.')
        capture.mode = 'accepted'
        check(worker(case)['delivery']['accepted'] == 0 and len(capture.payloads) == 7, 'Unknown message was automatically retransmitted.')
        revoked = create_one(owner, case, 'consent-withdrawal')
        run_form = form(owner, case, '[data-notification-run]')
        run_form['batch_limit'] = '1'
        capture.delay = True; capture.handshake.clear(); capture.release.clear()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            waiting = pool.submit(post, owner, case, run_form)
            check(capture.handshake.wait(10), 'The real pre-body SMTP handshake was not reached.')
            fixture(case, 'consent', enabled=False)
            capture.release.set()
            check(waiting.result(timeout=25).status_code == 200, 'Consent-withdrawal response failed.')
        check(len(capture.payloads) == 7 and state(case)['raw']['jobs'][revoked['id']]['status'] == 'skipped', 'Fresh opt-out did not stop transmission after the handshake.')
        fixture(case, 'consent', enabled=True)
        capture.delay = False
        owner_revoked = create_one(owner, case, 'owner-withdrawal')
        run_form = form(owner, case, '[data-notification-run]'); run_form['batch_limit'] = '1'
        capture.delay = True; capture.handshake.clear(); capture.release.clear()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            waiting = pool.submit(post, owner, case, run_form)
            check(capture.handshake.wait(10), 'Owner revocation did not reach actual SMTP.')
            http.fixture(case['site'], 'owner-status', case['php'], status='demoted')
            capture.release.set()
            check(waiting.result(timeout=25).status_code == 403, 'Demoted owner did not fail closed after the handshake.')
        check(len(capture.payloads) == 7 and state(case)['raw']['jobs'][owner_revoked['id']]['status'] == 'queued', 'Demoted owner transmitted or lost queued work.')
        http.fixture(case['site'], 'owner-status', case['php'], status='owner')
        capture.delay = False
        check(worker(case, 1)['delivery']['accepted'] == 1 and len(capture.payloads) == 8, 'Fresh worker could not resume work held before body transmission.')
        interrupted = create_one(owner, case, 'interrupted-claim')
        claim = fixture(case, 'claim')
        check(claim['id'] == interrupted['id'], 'Fixture interrupted the wrong real claim.')
        fixture(case, 'expire-claim', id=claim['id'], sending=False)
        recovered = worker(case, 1)['delivery']
        check(recovered['recovered'] == 1 and recovered['accepted'] == 1 and len(capture.payloads) == 9, 'A real expired pre-body claim was not safely resumed.')
        interrupted_sending = create_one(owner, case, 'interrupted-body')
        claim = fixture(case, 'claim')
        fixture(case, 'expire-claim', id=claim['id'], sending=True)
        recovered = worker(case, 1)['delivery']
        check(recovered['recovered'] == 1 and recovered['accepted'] == 0 and len(capture.payloads) == 9
              and state(case)['raw']['jobs'][interrupted_sending['id']]['status'] == 'unknown', 'Interrupted transmission was incorrectly replayed.')
        concurrent_job = create_one(owner, case, 'concurrent-workers')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            outcomes = list(pool.map(lambda _: worker(case, 1), range(2)))
        check(sum(row['delivery']['accepted'] for row in outcomes) == 1 and len(capture.payloads) == 10, 'Concurrent actual workers sent the same durable notice twice.')
        check(state(case)['raw']['jobs'][concurrent_job['id']]['status'] == 'sent', 'Concurrent completion was not committed.')
        return {'checks': http.checks - start, 'mode': case['mode'], 'mysql_version': delivered['mysql_version'],
                'source_workflows': 6, 'captured_messages': len(capture.payloads), 'external_messages': 0,
                'fresh_consent_and_owner_before_body': True, 'real_verification_link': True,
                'unknown_no_retry': True, 'interrupted_claim_recovered': True, 'interrupted_body_held': True,
                'two_actual_workers_once': True, 'independent_committed_readback': True}
    finally:
        capture.release.set(); owner.close(); guest.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='file')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'notifications_http', 'passed': False, 'cases': {},
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'fixture_sha256': hashlib.sha256(FIXTURE.read_bytes()).hexdigest()}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-notifications-http-') as temporary:
            work = Path(temporary)
            archive, identity = prepare_package(work)
            summary.update(identity)
            for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                for label, prefix in (('root', ''), ('nested', '/nested/velvet')):
                    with messages.runtime(work, archive, mode, mode + '-' + label, prefix, evidence) as case:
                        summary['cases'][mode + '-' + label] = http_case(case)
                    print('Notifications ' + mode + '-' + label + ': PASS', flush=True)
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'notifications-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
