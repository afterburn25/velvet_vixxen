#!/usr/bin/env python3
"""Actual packaged owner payment recovery, independent file/SQL reads and loopback delivery."""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
from urllib.parse import urlencode
from zipfile import ZipFile

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vvx_payment_recovery_messages', ROOT / 'tests/Http/campaign_messages_http.py')
messages = importlib.util.module_from_spec(spec)
spec.loader.exec_module(messages)
http, check = messages.http, messages.http.check
FIXTURE = Path(__file__).with_name('payment_recovery_fixture.php')
RUNTIME_FILES = (
    'admin/index.php', 'app/Views/admin/navigation.php', 'app/Views/admin/commerce.php',
    'app/Views/admin/payment-recovery.php', 'app/Views/admin/payment-recovery-actions.php',
    'app/Support/payment_recovery_admin.php', 'public/assets/css/payment-recovery.css',
    'app/Core/Store.php', 'app/Core/PaymentStoreTrait.php', 'app/Core/PaymentRecoveryService.php',
    'app/Core/PaymentRecoveryAuthorizationException.php', 'app/Core/PaymentOperationService.php',
    'app/Core/PaymentCommerceTrait.php', 'app/Core/CommerceService.php', 'app/Core/AddonRuntime.php',
    'app/Payments/PaymentContract.php', 'app/Payments/SandboxPaymentProvider.php',
    'database/migrations/202610220018_payment_journal.php',
)
PRODUCERS = ('tests/Http/payment_recovery_http.py', 'tests/Http/payment_recovery_fixture.php',
             'tests/Browser/payment_recovery_playwright.py')
PRIVATE_MARKERS = ('RECOVERY_PRIVATE_CUSTOMER_NAME', 'recovery-buyer@example.test',
                   'RECOVERY_PRIVATE_ORDER_METADATA', 'RECOVERY_PRIVATE_ADDRESS', 'RECOVERY_RAW_PAYMENT_INPUT')


def prepare_package(work):
    archive, identity = messages.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in RUNTIME_FILES),
              'Fresh does not contain the exact recovery runtime and migration.')
        check(zipped.testzip() is None, 'Fresh ZIP failed its CRC check.')
    identity['source_sha256'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in PRODUCERS}
    identity['runtime_sha256'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in RUNTIME_FILES}
    return archive, identity


@contextmanager
def runtime(work, archive, mode, label, prefix, evidence):
    with messages.runtime(work, archive, mode, label, prefix, evidence) as case:
        log = work / label / 'php-server.log'
        version = re.search(r'PHP ([0-9]+\.[0-9]+\.[0-9]+(?:[^ ]*)?) Development Server', log.read_text())
        check(version is not None, 'The actual PHP HTTP server did not report its version.')
        case['php_http_version'] = version.group(1)
        yield case
        check(not re.search(r'PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught ', log.read_text()),
              'Payment recovery emitted PHP diagnostics.')


def fixture(case, action, **values):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action], input=json.dumps(values),
                            capture_output=True, text=True, cwd='/tmp', timeout=90)
    check(result.returncode == 0, 'Owned recovery fixture failed: ' + result.stderr[:1800])
    return json.loads(result.stdout)


def snapshot(case):
    return fixture(case, 'read')


def initialize(case):
    if case['mode'] == 'mysql':
        owner = http.owner_login(case['base'])
        try:
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        finally:
            owner.close()
    case['records'] = fixture(case, 'seed')
    observed = snapshot(case)
    check(len(observed['journal']) == 61 and len(case['records']['operations']) == 61,
          'Real prepared/confirmed sandbox fixtures are incomplete.')
    check(case['capture'].connections == 0 and not observed['addon_deliveries'] and observed['addon_boots'] == 0,
          'Fixture preparation unexpectedly transmitted mail or executed an addon.')
    return observed


def workspace(case, **values):
    return case['base'] + '/admin/?' + urlencode({'page': 'payment-recovery', **values})


def private(response):
    headers = {key.lower(): value for key, value in response.headers.items()}
    cache = headers.get('cache-control', '').lower()
    check('private' in cache and 'no-store' in cache and headers.get('pragma') == 'no-cache'
          and headers.get('referrer-policy') == 'no-referrer'
          and all(word in headers.get('x-robots-tag', '').lower() for word in ('noindex', 'nofollow')),
          'Payment recovery lacks its private response policy.')


def safe_projection(response, state):
    forbidden = list(PRIVATE_MARKERS)
    for row in state['journal'].values():
        if row['kind'] == 'operation':
            forbidden.extend(row['data']['binding'][key] for key in ('account', 'installation'))
            result = row['data']['result']
            if result and result['transactionId']:
                forbidden.append(result['transactionId'])
    check(not any(value in response.text for value in set(forbidden)),
          'Owner recovery rendered private customer, provider binding or raw result data.')


def page(owner, case, **values):
    response = owner.get(workspace(case, **values), timeout=20)
    check(response.status_code == 200 and 'data-payment-recovery' in response.text,
          'The actual payment recovery page did not open.')
    private(response)
    return response


def form(response):
    return dict(http.fields(response.text, '.payment-recovery-action form'))


def submit(owner, response, values=None, expected=200):
    result = owner.post(response.url, data=form(response) if values is None else values, timeout=35)
    check(result.status_code == expected and (bool(result.history) if expected == 200 else not result.history),
          'The real recovery form returned an unexpected status: ' + str(result.status_code))
    return result


def transactions(state, order_id):
    return [row for row in state['transactions'] if row['orderId'] == order_id]


def effect(state, order_id, kind):
    rows = [row for row in state['journal'].values() if row['kind'] == 'effect'
            and row['orderId'] == order_id and row['data']['effect'] == kind]
    check(len(rows) == 1, 'Expected one genuine ' + kind + ' obligation for the owned order.')
    return rows[0]


def owner_session_id(case, cookies):
    ids = [cookie['value'] for cookie in cookies
           if re.fullmatch(r'[a-zA-Z0-9,-]{20,128}', cookie['value'])
           and (case['site'] / 'storage/sessions' / ('sess_' + cookie['value'])).is_file()]
    check(len(ids) == 1, 'The genuine saved owner session could not be identified.')
    return ids[0]


def confirm_owner(owner, case):
    url = case['base'] + '/admin/?page=security'
    current = owner.get(url, timeout=20)
    values = dict(http.fields(current.text, 'form:has(input[name=action][value=security_confirm])'))
    response = owner.post(url, data={**values, 'password': http.PASSWORD, 'code': ''}, timeout=20)
    check(response.status_code == 200 and response.history, 'The actual owner password confirmation failed.')


class TemporaryGreeting(messages.Handler):
    """Real local SMTP 421 rejection before DATA, followed by normal capture."""
    def handle(self):
        if self.server.temporary_failures:
            self.server.temporary_failures -= 1
            self.server.connections += 1
            self.wfile.write(b'421 Owned temporary transport unavailable\r\n')
            self.wfile.flush()
            return
        super().handle()


def temporary_mail(capture):
    capture.temporary_failures = 1
    capture.RequestHandlerClass = TemporaryGreeting


def assert_quiet(case, before, connections, *, full=True):
    after = snapshot(case)
    check(after['financial_sha256'] == before['financial_sha256']
          and (not full or after['all_sha256'] == before['all_sha256'])
          and after['protected'] == before['protected']
          and after['addon_boots'] == before['addon_boots']
          and after['addon_deliveries'] == before['addon_deliveries']
          and case['capture'].connections == connections,
          'A read or rejected action changed committed records or reached external effects.')
    return after


def apply_confirmed(owner, case, label, forged=False):
    records = case['records']; operation_id = records['operations'][label]; order_id = records['orders'][label]
    before = snapshot(case); connections = case['capture'].connections
    check(before['journal'][operation_id]['state'] == 'confirmed' and not transactions(before, order_id),
          'Confirmed recovery did not start before local application.')
    response = page(owner, case, record=operation_id, scope='all')
    values = form(response)
    if forged:
        values.update(operation_id=records['operations']['script-free'], order_id=records['orders']['script-free'],
                      amount='0.01', amountMinor='1', currency='EUR', provider='foreign-provider')
    result = submit(owner, response, values)
    after = snapshot(case); rows = transactions(after, order_id)
    check(after['journal'][operation_id]['state'] == 'applied' and after['orders'][order_id]['status'] == 'paid'
          and len(rows) == 1 and rows[0]['status'] == 'paid' and rows[0]['amount'] == 19.25 and rows[0]['currency'] == 'USD',
          'Confirmed recovery did not apply the exact original financial facts once.')
    check(len([x for x in after['entitlements'] if x['sourceId'] == order_id and x['status'] == 'active']) == 1,
          'Confirmed recovery did not grant exactly one owned entitlement.')
    check(all(effect(after, order_id, kind)['state'] == 'pending' for kind in ('receipt', 'addon'))
          and after['addon_boots'] == before['addon_boots'] and after['addon_deliveries'] == before['addon_deliveries']
          and case['capture'].connections == connections, 'Outcome recovery executed deferred mail or addons.')
    if forged:
        check(after['journal'][records['operations']['script-free']] == before['journal'][records['operations']['script-free']],
              'Client-supplied alternate operation or order changed the execution target.')
    submit(owner, response, values, expected=409)
    assert_quiet(case, after, connections)
    safe_projection(result, after)
    return after


def http_case(case):
    initialize(case)
    start = http.checks
    owner, guest = http.owner_login(case['base']), http.session()
    capture, records = case['capture'], case['records']
    try:
        before = snapshot(case); connections = capture.connections; initial = page(owner, case)
        safe_projection(initial, before)
        check(len(BeautifulSoup(initial.text, 'html.parser').select('[data-recovery-record]')) == 50,
              'The first genuine keyset page does not contain 50 records.')
        seen, current = [], page(owner, case, scope='all')
        while True:
            soup = BeautifulSoup(current.text, 'html.parser')
            ids = [row['data-recovery-record'] for row in soup.select('[data-recovery-record]')]
            check(0 < len(ids) <= 50 and not set(ids).intersection(seen), 'Keyset traversal duplicated or exceeded a page.')
            seen.extend(ids)
            next_link = next((a for a in soup.select('.payment-recovery-pagination a') if a.get_text(strip=True) == 'Next page'), None)
            if next_link is None:
                break
            current = owner.get(next_link['href'], timeout=20)
            check(current.status_code == 200, 'The actual Next page link failed.')
        check(seen == sorted(before['journal']) and len(seen) == 61, 'Keyset traversal truncated or skipped saved operations.')
        filtered = page(owner, case, order=records['orders']['confirmed'], scope='all')
        check([x['data-recovery-record'] for x in BeautifulSoup(filtered.text, 'html.parser').select('[data-recovery-record]')]
              == [records['operations']['confirmed']], 'The order filter included another order.')
        target = page(owner, case, record=records['operations']['confirmed'])
        safe_projection(target, before)
        check('USD 19.25' in target.text and records['operations']['confirmed'] in target.text,
              'The safe operation ID or original amount is missing.')
        assert_quiet(case, before, connections)

        values = form(target); connections = capture.connections
        for response, label in (
            (guest.get(target.url, timeout=20), 'Anonymous read'),
            (guest.post(target.url, data=values, timeout=20), 'Anonymous action'),
        ):
            check(response.status_code == 403 and records['operations']['confirmed'] not in response.text, label + ' exposed owner recovery.')
        assert_quiet(case, before, connections)
        member = http.member_login(case['base'], 'recovery-buyer')
        try:
            authority_before = snapshot(case); authority_connections = capture.connections
            check(member.get(target.url, timeout=20).status_code == 403, 'A regular member opened owner recovery.')
            check(member.post(target.url, data=values, timeout=20).status_code == 403, 'A regular member ran owner recovery.')
            assert_quiet(case, authority_before, authority_connections)
        finally:
            member.close()
        before = snapshot(case)
        submit(owner, target, {**values, '_csrf': 'invalid'}, expected=419)
        submit(owner, target, {**values, 'record': records['operations']['temporary']}, expected=400)
        submit(owner, target, {**values, 'action': 'save_payment_provider'}, expected=400)
        check(owner.post(case['base'] + '/admin/?page=orders', data=values, timeout=20).status_code == 400,
              'A payment recovery action executed through another admin page.')
        check(owner.put(target.url, data=values, timeout=20).status_code == 405, 'Recovery accepted an unsupported mutation method.')
        check(owner.get(target.url + '&action=payment_recovery_reconcile', timeout=20).status_code == 200,
              'Read-only query parameters broke record review.')
        for query in ({'record': 'pay_' + 'f' * 32}, {'record': records['operations']['confirmed'], 'order': records['orders']['temporary']}):
            check(owner.get(workspace(case, **query), timeout=20).status_code == 404, 'Missing or wrong-order record was not denied.')
        for suffix in ('&record[]=bad', '&after[]=bad', '&scope=invalid', '&order[]=bad'):
            check(owner.get(workspace(case) + suffix, timeout=20).status_code == 400, 'Malformed recovery input was accepted.')
        if case['mode'] == 'mysql':
            check(owner.get(workspace(case, order='not-a-number'), timeout=20).status_code == 400, 'SQL order filter accepted a nonnumeric identity.')
        assert_quiet(case, before, connections)

        session = owner_session_id(case, [{'value': c.value} for c in owner.cookies])
        fixture(case, 'age-session', id=session)
        before = snapshot(case); expired = page(owner, case, record=records['operations']['confirmed'])
        check('Confirm your identity before taking action' in expired.text and not BeautifulSoup(expired.text, 'html.parser').select('.payment-recovery-action form'),
              'An expired owner confirmation still exposes recovery actions.')
        submit(owner, target, values, expected=428)
        assert_quiet(case, before, connections)
        confirm_owner(owner, case)
        for authority in ('demoted', 'inactive', 'password-changed'):
            target = page(owner, case, record=records['operations']['confirmed']); values = form(target)
            fixture(case, 'owner-state', value=authority); before = snapshot(case)
            check(owner.post(target.url, data=values, timeout=20).status_code == 403, 'A changed saved owner retained action authority: ' + authority)
            check(owner.get(target.url, timeout=20).status_code == 403, 'A changed saved owner retained read authority: ' + authority)
            assert_quiet(case, before, connections)
            fixture(case, 'owner-state', value='owner'); owner.close(); owner = http.owner_login(case['base'])
        before = snapshot(case)
        fixture(case, 'hold-journal')
        try:
            unavailable = owner.get(workspace(case, scope='all'), timeout=20)
            check(unavailable.status_code == 503 and 'Payment records unavailable' in unavailable.text
                  and 'No matching records on this page' not in unavailable.text, 'Unreadable storage masqueraded as an empty recovery list.')
        finally:
            fixture(case, 'restore-journal')
        assert_quiet(case, before, connections)

        pending_id, pending_order = records['operations']['pending'], records['orders']['pending']
        before = snapshot(case); pending_page = page(owner, case, record=pending_id)
        submit(owner, pending_page); after = snapshot(case)
        check(after['journal'][pending_id]['state'] == 'unknown' and after['orders'][pending_order]['status'] == 'processing'
              and after['financial_sha256'] == before['financial_sha256'] and set(after['journal']) == set(before['journal']),
              'Unknown sandbox lookup invented confirmation, another operation or financial effects.')
        check(capture.connections == connections and after['addon_deliveries'] == before['addon_deliveries'], 'Unknown lookup reached external follow-ups.')
        submit(owner, page(owner, case, record=pending_id)); repeated = snapshot(case)
        check(repeated['financial_sha256'] == before['financial_sha256'] and repeated['journal'][pending_id]['state'] == 'unknown',
              'Repeated unknown lookup created a duplicate charge or claimed success.')

        after = apply_confirmed(owner, case, 'confirmed', forged=True)
        normal_order = records['orders']['confirmed']; receipt = effect(after, normal_order, 'receipt')
        old_messages, old_deliveries = len(capture.payloads), len(after['addon_deliveries'])
        delivery_page = page(owner, case, record=receipt['id'], scope='all')
        delivery_values = form(delivery_page); submit(owner, delivery_page)
        delivered = snapshot(case)
        check(len(capture.payloads) == old_messages + 1 and capture.payloads[-1]['recipient'] == 'recovery-buyer@example.test'
              and len(delivered['addon_deliveries']) == old_deliveries + 1
              and all(effect(delivered, normal_order, kind)['state'] == 'done' for kind in ('receipt', 'addon')),
              'Eligible follow-ups did not deliver one real receipt and addon callback.')
        check(delivered['financial_sha256'] == after['financial_sha256'], 'External follow-ups changed financial facts.')
        connections = capture.connections
        submit(owner, delivery_page, delivery_values, expected=409); assert_quiet(case, delivered, connections)
        open_filtered = page(owner, case, order=normal_order)
        check(not BeautifulSoup(open_filtered.text, 'html.parser').select('[data-recovery-record]'), 'Completed records remained in the open-only view.')
        check(len(BeautifulSoup(page(owner, case, order=normal_order, scope='all').text, 'html.parser').select('[data-recovery-record]')) == 3,
              'All records lost the completed operation or its follow-ups.')

        after = apply_confirmed(owner, case, 'temporary'); order_id = records['orders']['temporary']
        receipt = effect(after, order_id, 'receipt'); old_messages, old_connections = len(capture.payloads), capture.connections
        temporary_mail(capture)
        submit(owner, page(owner, case, record=receipt['id'], scope='all'))
        retryable = snapshot(case)
        check(effect(retryable, order_id, 'receipt')['state'] == 'retryable' and len(capture.payloads) == old_messages
              and capture.connections == old_connections + 1, 'The actual initial SMTP 421 was not classified as safely retryable.')
        retry_page = page(owner, case, record=receipt['id'], scope='all'); check('Safe to retry' in retry_page.text, 'Retryable receipt is unclear in the owner review.')
        submit(owner, retry_page); retried = snapshot(case)
        check(effect(retried, order_id, 'receipt')['state'] == 'done' and len(capture.payloads) == old_messages + 1
              and capture.connections == old_connections + 2, 'A safe receipt retry did not transmit exactly once.')

        after = apply_confirmed(owner, case, 'uncertain'); order_id = records['orders']['uncertain']
        receipt = effect(after, order_id, 'receipt'); old_messages, old_deliveries = len(capture.payloads), len(after['addon_deliveries'])
        capture.mode = 'unknown'; fixture(case, 'addon-mode', value='unknown')
        submit(owner, page(owner, case, record=receipt['id'], scope='all')); held = snapshot(case)
        check(all(effect(held, order_id, kind)['state'] == 'held' for kind in ('receipt', 'addon'))
              and len(capture.payloads) == old_messages + 1 and len(held['addon_deliveries']) == old_deliveries + 1,
              'Actually delivered but unacknowledged follow-ups were not held.')
        capture.mode = 'accepted'; fixture(case, 'addon-mode', value='accepted'); connections = capture.connections
        held_baseline = snapshot(case)
        for kind in ('receipt', 'addon'):
            row = effect(held, order_id, kind); current = page(owner, case, record=row['id'], scope='all')
            check('Held for review' in current.text and not BeautifulSoup(current.text, 'html.parser').select('.payment-recovery-action form'),
                  'An ambiguous follow-up exposes an automatic resend control.')
            csrf = BeautifulSoup(current.text, 'html.parser').select_one('meta[name=csrf-token]')['content']
            submit(owner, current, {'_csrf': csrf, 'action': 'payment_recovery_retry_effects', 'record': row['id']}, expected=409)
        assert_quiet(case, held_baseline, connections)
        check(len(capture.payloads) == old_messages + 1 and len(snapshot(case)['addon_deliveries']) == old_deliveries + 1,
              'A held outcome was resent after the transport became healthy.')

        after = apply_confirmed(owner, case, 'addon-retry'); order_id = records['orders']['addon-retry']
        addon = effect(after, order_id, 'addon'); old_messages, old_deliveries = len(capture.payloads), len(after['addon_deliveries'])
        fixture(case, 'addon-mode', value='safe')
        submit(owner, page(owner, case, record=addon['id'], scope='all')); safe = snapshot(case)
        check(effect(safe, order_id, 'addon')['state'] == 'retryable' and len(safe['addon_deliveries']) == old_deliveries
              and len(capture.payloads) == old_messages + 1, 'Safe mode did not leave an unexecuted addon follow-up retryable.')
        fixture(case, 'addon-mode', value='enabled')
        submit(owner, page(owner, case, record=addon['id'], scope='all')); final = snapshot(case)
        check(effect(final, order_id, 'addon')['state'] == 'done' and len(final['addon_deliveries']) == old_deliveries + 1
              and len(capture.payloads) == old_messages + 1, 'Addon retry repeated a completed receipt or missed the eligible callback.')
        check(not capture.errors and final['protected'] == before['protected'], 'The owned capture or protected fixture files changed unexpectedly.')
        check((case['mode'] == 'mysql') == bool(final['mysql_version']), 'The actual SQL engine version is missing.')
        return {'checks': http.checks - start, 'mysql_version': final['mysql_version'], 'php_cli_version': final['php_cli_version'],
                'php_http_version': case['php_http_version'],
                'initial_keyset_records': len(seen), 'readonly_get_no_mutation_or_addons_or_smtp': True,
                'owner_csrf_recent_confirmation_and_current_saved_identity': True, 'cross_page_and_record_actions_denied': True,
                'private_allowlisted_projection': True, 'unavailable_storage_explicit': True, 'unknown_never_confirmed_or_duplicated': True,
                'confirmed_local_apply_once_separate_followups': True, 'untrusted_action_parameters_ignored': True,
                'actual_smtp_421_retry_once': True, 'ambiguous_mail_and_addon_held_without_resend': True,
                'safe_mode_addon_retry_without_receipt_resend': True, 'smtp_connections': capture.connections,
                'captured_messages': len(capture.payloads), 'addon_deliveries': len(final['addon_deliveries']), 'external_messages': 0}
    finally:
        fixture(case, 'owner-state', value='owner')
        owner.close(); guest.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    args = parser.parse_args(); evidence = http.evidence_directory()
    report = {'report_kind': 'payment_recovery_http', 'passed': False, 'server': 'Actual extracted Fresh with the PHP HTTP server', 'cases': {}}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-payment-recovery-http-') as temporary:
            work = Path(temporary); archive, identity = prepare_package(work); report.update(identity)
            for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                for path, prefix in (('root', ''), ('nested', '/nested/velvet')):
                    label = mode + '-' + path
                    with runtime(work, archive, mode, label, prefix, evidence) as case:
                        report['cases'][label] = http_case(case)
                    print('Payment recovery HTTP ' + label + ': PASS', flush=True)
        report['passed'] = True; print(json.dumps(report, indent=2))
    except Exception as error:
        report['failure'] = str(error); raise
    finally:
        if evidence:
            (evidence / 'payment-recovery-http-evidence.json').write_text(json.dumps(report, indent=2) + '\n')


if __name__ == '__main__':
    main()
