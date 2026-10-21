#!/usr/bin/env python3
"""Actual installed-package signed provider events; ephemeral keys and loopback mail only."""
from __future__ import annotations

import argparse
import concurrent.futures
import fcntl
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import time
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vvx_provider_notifications', ROOT / 'tests/Http/notifications_http.py')
notifications = importlib.util.module_from_spec(spec)
spec.loader.exec_module(notifications)
messages, http = notifications.messages, notifications.http
check = http.check
FIXTURE = Path(__file__).with_name('mail_provider_fixture.php')
FILES = ('app/Core/SendGridSignature.php', 'app/Core/MailProviderService.php', 'app/Core/MarketingProviderEvents.php',
         'app/Core/NotificationProviderEvents.php', 'app/Core/MarketingStore.php', 'app/Core/NotificationService.php',
         'app/Core/PrivateState.php', 'app/Console/run_mail_events.php', 'app/Support/public_routes.php',
         'public/routes/api/mail-events.php', 'admin/index.php', 'app/Views/admin/marketing.php',
         'app/Views/admin/marketing-actions.php', 'app/Views/admin/marketing-provider.php', 'app/Views/admin/marketing-provider-actions.php')


def prepare_package(work):
    archive, identity = notifications.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in FILES), 'Fresh differs from the exact provider runtime under review.')
    return archive, identity


def fixture(case, action, *, observe=False, **values):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action, json.dumps(values)], text=True,
                            capture_output=True, timeout=30, cwd='/tmp')
    if observe:
        if result.returncode != 0:
            raise AssertionError('Owned provider observation failed: ' + result.stderr[:1500])
    else:
        check(result.returncode == 0, 'Owned provider fixture failed: ' + result.stderr[:1500])
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
                if item.get('schema') == 1 and 'events' in item and 'settings' in item:
                    candidates.append(item)
                for child in item.values():
                    collect(child)
            elif isinstance(item, list):
                for child in item:
                    collect(child)
        collect(value['committed_sql'] if case['mode'] == 'mysql' else value['committed_file'])
        check(len(candidates) == 1 and candidates[0] == raw, 'Fresh provider reads differ from independent committed file/SQL bytes.')
    return value


def durable(value):
    return {key: value[key] for key in ('raw', 'marketing', 'notifications', 'committed_file', 'committed_sql')}


def page(owner, case):
    response = owner.get(case['base'] + '/admin/?page=marketing&tab=provider', timeout=20)
    check(response.status_code == 200 and 'data-provider-settings' in response.text, 'Actual provider controls did not open.')
    http.private(response, True)
    return response


def form(owner, case, selector='[data-provider-settings]'):
    return dict(http.fields(page(owner, case).text, selector))


def post(owner, case, values):
    return owner.post(case['base'] + '/admin/?page=marketing&tab=provider', data=values, timeout=30)


def enable(owner, case, public_key):
    values = form(owner, case)
    values.update(enabled='1', public_key=public_key)
    response = post(owner, case, values)
    check(response.status_code == 200 and response.history and 'Provider-event settings saved.' in response.text, 'Actual owner provider enable failed.')
    return values


def event(identifier, kind, message_id, email='alpha@example.test', **extra):
    return {'sg_event_id': identifier, 'event': kind, 'smtp-id': message_id, 'email': email, 'timestamp': int(time.time()),
            'ip': '192.0.2.78', 'url': 'https://example.test/PRIVATE_PROVIDER_URL', 'useragent': 'PRIVATE_PROVIDER_UA',
            'reason': 'PRIVATE_PROVIDER_REASON', **extra}


def sign(case, events, timestamp=None):
    raw = json.dumps(events, separators=(',', ':'), ensure_ascii=False) + '\r\n'
    return fixture(case, 'sign', raw=raw, timestamp=str(int(time.time()) if timestamp is None else timestamp))


def endpoint(case):
    return case['base'] + '/route.php?vvx_route=api%2Fmail-events.php'


def receipt(client, case, signed, raw=None):
    response = client.post(endpoint(case), data=(signed['raw'] if raw is None else raw).encode(), headers={
        'Content-Type': 'application/json', 'X-Twilio-Email-Event-Webhook-Signature': signed['signature'],
        'X-Twilio-Email-Event-Webhook-Timestamp': signed['timestamp']}, timeout=30, allow_redirects=False)
    check(response.status_code not in (301, 302, 303, 307, 308) and 'no-store' in response.headers.get('Cache-Control', ''), 'Provider endpoint redirected or permitted caching.')
    return response


def accept(client, case, events):
    response = receipt(client, case, sign(case, events))
    check(response.status_code == 200 and response.json()['ok'], 'A correctly signed event was rejected: ' + response.text[:300])
    return response.json()


def worker(case, kind='provider', limit=10):
    filename = {'provider': 'run_mail_events.php', 'campaign': 'run_marketing.php', 'notification': 'run_notifications.php'}[kind]
    result = subprocess.run([case['php'], str(case['site'] / 'app/Console' / filename), '--limit=' + str(limit)],
                            text=True, capture_output=True, timeout=35, cwd='/tmp')
    check(result.returncode in ((0,) if kind == 'provider' else (0, 2)), 'Actual worker failed: ' + result.stdout[:800] + result.stderr[:800])
    return json.loads(result.stdout)


def suppression(saved, email):
    return any(row['email'] == email and row.get('active', True) for row in saved['marketing']['suppressions'])


def captured(case):
    saved = state(case)
    identifiers = {}
    for payload in case['capture'].payloads:
        mime, plain, html = messages.parsed(payload)
        identifier = str(mime['Message-ID'])
        campaigns = [row for row in saved['marketing']['campaign_queue'] if row.get('message_id') == identifier]
        notices = [row for row in saved['notifications']['jobs'].values() if row['message_id'] == identifier]
        check(len(campaigns) + len(notices) == 1 and (campaigns or notices)[0]['status'] in ('sent', 'unknown'), 'Captured Message-ID lacks an actual durable outbound result.')
        check(payload['recipient'] in {'alpha@example.test', 'beta@example.test', 'unknown@example.test'} and 'PRIVATE_PROVIDER_' not in plain, 'Local delivery violated recipient or generic-notification privacy.')
        identifiers[('campaign' if campaigns else 'notification', payload['recipient'])] = identifier
    return identifiers


def interrupt_verified(case, signed, identifier):
    """Kill only our child after real private-state commit while the real effect lock waits."""
    lock_path = case['site'] / 'storage/private/marketing/data.lock'
    check(lock_path.is_file() and not lock_path.is_symlink(), 'The actual owned marketing lock is absent.')
    with lock_path.open('r+b') as locked:
        fcntl.flock(locked, fcntl.LOCK_EX)
        process = subprocess.Popen([case['php'], str(FIXTURE), str(case['site']), 'receive', json.dumps(signed)],
                                   text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd='/tmp')
        pending = None
        try:
            deadline = time.monotonic() + 6
            while time.monotonic() < deadline:
                raw = fixture(case, 'private-only', observe=True)['raw']
                pending = raw.get('events', {}).get(identifier)
                if pending and pending['status'] == 'pending':
                    break
                if process.poll() is not None:
                    raise AssertionError('The owned interrupted receiver exited before durable staging.')
                time.sleep(.04)
            check(pending is not None and pending['status'] == 'pending' and process.poll() is None, 'The actual verified event did not commit before its effect wait.')
            check(pending['recipient_ciphertext'] and '@example.test' not in pending['recipient_ciphertext'], 'Pending verified event lost its encrypted recipient.')
        finally:
            if process.poll() is None:
                process.kill()
            process.communicate(timeout=5)
            fcntl.flock(locked, fcntl.LOCK_UN)
    return pending


def http_case(case):
    start = http.checks
    owner, guest = http.owner_login(case['base']), http.session()
    capture = case['capture']
    try:
        if case['mode'] == 'mysql':
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        initial = state(case)
        initial_page = page(owner, case)
        check(durable(state(case)) == durable(initial) and capture.connections == 0, 'Provider GET initialized data or contacted SMTP.')
        values = dict(http.fields(initial_page.text, '[data-provider-settings]'))
        check(guest.get(initial_page.url, timeout=15).status_code == 403 and guest.post(initial_page.url, data=values, timeout=15).status_code == 403, 'Guest accessed private provider controls.')
        check(guest.get(endpoint(case), timeout=15, allow_redirects=False).status_code == 405, 'Webhook GET did not enforce POST.')
        check(guest.post(endpoint(case), data='[]', timeout=15).status_code == 415, 'Webhook accepted a non-JSON content type.')
        check(guest.post(endpoint(case), data='[]', headers={'Content-Type': 'application/json'}, timeout=15).status_code == 503, 'Disabled webhook accepted events.')
        key = fixture(case, 'key')['public_key']
        values.update(enabled='1', public_key=key)
        check(post(owner, case, {**values, '_csrf': 'invalid'}).status_code == 419, 'Bad CSRF changed provider authorization.')
        check(post(owner, case, {**values, 'public_key': 'SG.not-a-public-key'}).status_code == 422, 'An API-key-shaped value was accepted as an ECDSA public key.')
        check(durable(state(case)) == durable(initial), 'Rejected provider forms changed durable state.')
        stale = enable(owner, case, key)
        enabled = durable(state(case))
        check(post(owner, case, stale).status_code == 409 and durable(state(case)) == enabled, 'Stale provider authorization overwrote the saved revision.')
        check(owner.post(case['base'] + '/admin/?page=marketing&tab=posts', data=stale, timeout=15).status_code == 400, 'Provider action crossed into another owner editor.')
        seed = fixture(case, 'seed')
        check(seed['campaign_queued'] == 2 and seed['discovery']['queued'] == 2, 'Actual opted-in campaign and notification sources were not queued.')
        check(worker(case, 'campaign')['accepted'] == 2 and worker(case, 'notification')['delivery']['accepted'] == 2 and len(capture.payloads) == 4, 'Real workers did not deliver the exact four initial messages.')
        ids = captured(case)
        alpha, beta = ids[('campaign', 'alpha@example.test')], ids[('notification', 'beta@example.test')]
        future = fixture(case, 'campaign', name='future')
        next_content = fixture(case, 'post', name='future')
        check(future['campaign_queued'] == 2 and next_content['discovery']['queued'] == 2, 'Future suppression work was not genuinely queued.')
        before = durable(state(case))
        signed = sign(case, [event('signature-bytes', 'delivered', alpha)])
        check(receipt(guest, case, signed, signed['raw'].rstrip()).status_code == 401, 'The signature accepted changed original bytes.')
        check(receipt(guest, case, {**signed, 'signature': 'invalid'}).status_code == 401, 'An invalid signature was accepted.')
        for timestamp in (int(time.time()) - 86701, int(time.time()) + 600):
            check(receipt(guest, case, sign(case, [event('bad-header-time', 'delivered', alpha)], timestamp)).status_code == 401, 'An out-of-window signature was accepted.')
        check(receipt(guest, case, sign(case, [event('partial-valid', 'delivered', alpha), {'invalid': True}])).status_code == 400, 'An invalid batch was partly accepted.')
        check(receipt(guest, case, sign(case, [event('expired-event', 'delivered', alpha, timestamp=int(time.time()) - 8 * 86400)])).status_code == 400, 'Expired event metadata was accepted.')
        check(receipt(guest, case, signed, 'x' * (1048576 + 1)).status_code == 413, 'An oversized original payload was accepted.')
        check(durable(state(case)) == before, 'Rejected signatures or malformed batches changed state.')
        queued = next(row for row in state(case)['marketing']['campaign_queue'] if row['campaign_id'] == future['campaign'] and row['email'] == 'alpha@example.test')
        check(accept(guest, case, [event('not-sent', 'bounce', queued['message_id'], type='bounce'),
              event('wrong-recipient', 'bounce', alpha, 'stranger@example.test', type='bounce'), event('missing-id', 'bounce', '', type='bounce')])['unmatched'] == 3, 'Unsent, foreign-recipient or missing identities became trusted proof.')
        check(not suppression(state(case), 'alpha@example.test') and not suppression(state(case), 'stranger@example.test'), 'Unmatched events suppressed a recipient.')
        check(accept(guest, case, [event('blocked', 'bounce', alpha, type='blocked'), event('deferred', 'deferred', beta, 'beta@example.test')])['processed'] == 2, 'Temporary provider outcomes were not recorded.')
        check(not suppression(state(case), 'alpha@example.test') and not suppression(state(case), 'beta@example.test'), 'A temporary block or deferral became permanent suppression.')
        accept(guest, case, [event('accepted', 'delivered', alpha)])
        hard = event('hard-bounce', 'bounce', alpha, type='bounce')
        complaint = event('complaint', 'spamreport', beta, 'beta@example.test')
        check(accept(guest, case, [hard, complaint])['processed'] == 2, 'Actual campaign bounce and notification complaint were not applied.')
        suppressed = state(case)
        check(suppression(suppressed, 'alpha@example.test') and suppression(suppressed, 'beta@example.test'), 'Verified permanent feedback did not suppress both actual recipients.')
        check(all(row['status'] == 'suppressed' for row in suppressed['marketing']['campaign_queue'] if row['campaign_id'] == future['campaign']), 'Verified feedback did not stop future campaign rows.')
        check(all(row['status'] == 'skipped' for row in suppressed['notifications']['jobs'].values() if row['source_id'] == 'post:' + next_content['post']), 'Verified feedback did not stop future optional notification rows.')
        check({row['delivery_kind'] for row in suppressed['raw']['events'].values() if row['id'] in {hashlib.sha256(('sendgrid|' + label).encode()).hexdigest() for label in ('hard-bounce', 'complaint')}} == {'campaign', 'notification'}, 'The provider adapter did not correlate both actual message families.')
        before = durable(suppressed)
        check(accept(guest, case, [hard, complaint])['duplicates'] == 2 and durable(state(case)) == before, 'Exact signed duplicate receipts repeated effects.')
        check(receipt(guest, case, sign(case, [{**hard, 'event': 'delivered'}])).status_code == 409 and durable(state(case)) == before, 'Reused event identity changed its meaning or partially committed.')
        accept(guest, case, [event('late-delivery', 'delivered', alpha), event('provider-unsubscribe', 'unsubscribe', beta, 'beta@example.test'), event('ignore-open', 'open', alpha), event('ignore-click', 'click', alpha)])
        updated = state(case)
        check(next(row for row in updated['marketing']['campaign_queue'] if row.get('message_id') == alpha)['provider_status'] == 'hard_bounce' and suppression(updated, 'alpha@example.test'), 'A later positive outcome cleared permanent suppression.')
        check(updated['overview']['counts']['ignored'] == 2 and all(row['recipient_ciphertext'] is None for row in updated['raw']['events'].values()), 'Completed or ignored provider metadata retained encrypted recipients.')
        check('PRIVATE_PROVIDER_' not in json.dumps(updated['raw']) and '@example.test' not in json.dumps(updated['raw']) and '192.0.2.' not in json.dumps(updated['raw']), 'The journal retained raw event/private fields.')
        check(worker(case, 'campaign')['accepted'] == 0 and worker(case, 'notification')['delivery']['accepted'] == 0 and len(capture.payloads) == 4, 'Suppressed work reached the actual mail transport.')
        unknown = fixture(case, 'unknown-contact', name='unknown')
        check(unknown['campaign_queued'] == 1, 'Suppression did not exclude old recipients from a new campaign.')
        capture.mode = 'unknown'
        check(worker(case, 'campaign')['unknown'] == 1 and len(capture.payloads) == 5, 'The actual SMTP lost-acknowledgement condition was not reached.')
        capture.mode = 'accepted'
        unknown_id = captured(case)[('campaign', 'unknown@example.test')]
        accept(guest, case, [event('unknown-accepted', 'delivered', unknown_id, 'unknown@example.test')])
        check(next(row for row in state(case)['marketing']['campaign_queue'] if row.get('message_id') == unknown_id)['status'] == 'unknown', 'Provider feedback reclassified the unknown SMTP result as retryable.')
        recovery = event('interrupted-verified', 'unsubscribe', unknown_id, 'unknown@example.test')
        recovery_id = hashlib.sha256(b'sendgrid|interrupted-verified').hexdigest()
        interrupt_verified(case, sign(case, [recovery]), recovery_id)
        check(worker(case)['processed'] == 1, 'Actual reconciliation CLI did not resume a killed verified receipt.')
        recovered = state(case)
        check(recovered['raw']['events'][recovery_id]['status'] == 'processed' and recovered['raw']['events'][recovery_id]['recipient_ciphertext'] is None and suppression(recovered, 'unknown@example.test'), 'Recovered effects were incomplete or retained recipient ciphertext.')
        check(worker(case)['examined'] == 0 and accept(guest, case, [recovery])['duplicates'] == 1, 'Repeated recovery created duplicate effects.')
        # Two independent processes race on the same new signed event. Actual
        # PrivateState and MarketingStorage locks must serialize durable effects.
        concurrent_receipt = sign(case, [event('concurrent-receipt', 'delivered', unknown_id, 'unknown@example.test')])
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            outcomes = list(pool.map(lambda _: fixture(case, 'receive', **concurrent_receipt), range(2)))
        check(sum(row['duplicates'] for row in outcomes) == 1, 'Concurrent verified receivers did not deduplicate one event identity.')
        final = state(case)
        final_id = hashlib.sha256(b'sendgrid|concurrent-receipt').hexdigest()
        check(final['raw']['events'][final_id]['status'] == 'processed' and final_id in final['marketing']['settings']['mail_provider_effects'], 'Concurrent verified effects were not durably committed.')
        check(worker(case, 'campaign')['accepted'] == 0 and worker(case, 'notification')['delivery']['accepted'] == 0 and len(capture.payloads) == 5, 'Feedback or recovery caused mail retransmission.')
        page(owner, case)
        reconcile_form = form(owner, case, '[data-provider-reconcile]')
        before = durable(state(case))
        http.fixture(case['site'], 'owner-status', case['php'], status='demoted')
        check(owner.get(initial_page.url, timeout=15).status_code == 403 and post(owner, case, reconcile_form).status_code == 403 and post(owner, case, stale).status_code == 403, 'A revoked owner read the journal or processed private effects.')
        check(durable(state(case)) == before, 'Revoked-owner requests changed provider state.')
        http.fixture(case['site'], 'owner-status', case['php'], status='owner')
        return {'checks': http.checks - start, 'mode': case['mode'], 'mysql_version': final['mysql_version'],
                'captured_messages': 5, 'external_messages': 0, 'actual_campaign_and_notification_correlation': True,
                'original_byte_signatures': True, 'replay_idempotent': True, 'permanent_suppression': True,
                'temporary_not_permanent': True, 'unknown_no_retry': True, 'killed_verified_receiver_recovered': True,
                'two_actual_receivers_once': True, 'independent_committed_readback': True,
                'verified_events': len(final['raw']['events']), 'provider_production_contact': False}
    finally:
        capture.mode = 'accepted'; owner.close(); guest.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='file')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'mail_provider_http', 'passed': False, 'cases': {},
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'fixture_sha256': hashlib.sha256(FIXTURE.read_bytes()).hexdigest()}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-mail-provider-http-') as temporary:
            work = Path(temporary)
            archive, identity = prepare_package(work)
            summary.update(identity)
            for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                for label, prefix in (('root', ''), ('nested', '/nested/velvet')):
                    with messages.runtime(work, archive, mode, mode + '-' + label, prefix, evidence) as case:
                        summary['cases'][mode + '-' + label] = http_case(case)
                    print('Mail provider ' + mode + '-' + label + ': PASS', flush=True)
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'mail-provider-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
