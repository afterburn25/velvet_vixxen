"""Campaign delivery against disposable HTTP apps and a localhost-only SMTP capture."""
from __future__ import annotations

import concurrent.futures
import json
import os
import re
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import parse_qs, urlparse

import requests
from html.parser import HTMLParser

ROOT = Path(os.environ['VELVET_TEST_RUNTIME']).resolve()
BASE = os.environ['VELVET_BASE_URL'].rstrip('/')
assert ROOT.name.startswith('vvx-test-') and (ROOT / '.vvx-test-runtime').is_file()
assert urlparse(BASE).hostname == '127.0.0.1', 'Local disposable HTTP service is required.'
FIXTURE = ROOT / 'tests/Http/campaign_delivery_fixture.php'
checks = 0


def check(condition, message):
    global checks
    checks += 1
    assert condition, message


def fixture(action, **values):
    result = subprocess.run(['php', str(FIXTURE), str(ROOT), action], input=json.dumps(values), text=True,
                            capture_output=True, timeout=20, cwd='/tmp')
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def state():
    return json.loads((ROOT / 'storage/private/marketing/data.json').read_text())


def deliveries(campaign):
    return [row for row in state()['campaign_queue'] if row['campaign_id'] == campaign['id']]


def cli(campaign=None, limit=10):
    command = ['php', str(ROOT / 'app/Console/run_marketing.php'), f'--limit={limit}']
    if campaign is not None:
        command.append('--campaign=' + campaign['id'])
    result = subprocess.run(command, capture_output=True, text=True, timeout=25, cwd='/tmp')
    assert result.returncode in (0, 2), result.stderr
    return json.loads(result.stdout)


def public_url(route, base=BASE):
    from urllib.parse import urlencode
    return base + '/route.php?' + urlencode({'vvx_route': route})


class InputParser(HTMLParser):
    def __init__(self, field_name):
        super().__init__()
        self.field_name = field_name
        self.value = None

    def handle_starttag(self, tag, attributes):
        values = dict(attributes)
        if tag == 'input' and values.get('name') == self.field_name:
            self.value = values.get('value')


def token(response, name='_csrf'):
    parser = InputParser(name)
    parser.feed(response.text)
    assert parser.value, response.text[:250]
    return parser.value


def post(owner, csrf, action, base=BASE, **values):
    response = owner.post(base + '/admin/?page=marketing&tab=campaigns',
                          data={'_csrf': csrf, 'action': action, **values}, timeout=20)
    assert response.status_code == 200, (response.status_code, response.text[:150])
    return response


class CaptureHandler(socketserver.StreamRequestHandler):
    def handle(self):
        def reply(value):
            self.wfile.write((value + '\r\n').encode())
            self.wfile.flush()
        self.request.settimeout(12)
        reply('220 Local campaign capture')
        try:
            while line := self.rfile.readline():
                command = line.decode('utf-8', 'replace').rstrip('\r\n')
                if command.startswith('EHLO '):
                    reply('250 Local capture')
                elif command.startswith('MAIL FROM:'):
                    reply('250 Sender accepted')
                elif command.startswith('RCPT TO:'):
                    if not command.endswith('@example.test>'):
                        self.server.errors.append('Non-fixture recipient reached capture')
                        reply('550 Fixture recipients only')
                    else:
                        reply('250 Recipient accepted')
                elif command == 'DATA':
                    if self.server.delay:
                        self.server.handshake.set()
                        if not self.server.release.wait(12):
                            return
                    reply('354 Send message')
                    lines = []
                    while line := self.rfile.readline():
                        if line == b'.\r\n':
                            break
                        lines.append(line)
                    else:
                        return  # Revoked claim closed before sending any body.
                    payload = b''.join(lines).decode('utf-8', 'replace')
                    self.server.payloads.append(payload)
                    mode = self.server.mode
                    if mode == 'unknown':
                        return  # Acknowledgement is deliberately lost after DATA.
                    reply({'temporary': '450 Temporary fixture rejection', 'permanent': '550 Permanent fixture rejection'}.get(mode, '250 Accepted by local capture'))
                elif command == 'QUIT':
                    reply('221 Goodbye')
                    return
                else:
                    self.server.errors.append('Unexpected SMTP command')
                    reply('500 Unexpected command')
        except (BrokenPipeError, ConnectionResetError, TimeoutError):
            return


class Capture(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        super().__init__(('127.0.0.1', 0), CaptureHandler)
        self.payloads = []
        self.errors = []
        self.mode = 'accepted'
        self.delay = False
        self.handshake = threading.Event()
        self.release = threading.Event()


capture = Capture()
thread = threading.Thread(target=capture.serve_forever, daemon=True)
thread.start()
secondary = None
log = None
with tempfile.TemporaryDirectory(prefix='vvx-campaign-http-') as mount:
    try:
        # Mount the same real runtime at a physical nested path, without a router
        # fallback, and use its independent PHP worker for concurrent owner actions.
        nested = Path(mount) / 'nested/site'
        nested.parent.mkdir(parents=True)
        nested.symlink_to(ROOT, target_is_directory=True)
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            secondary_port = available.getsockname()[1]
        NESTED = f'http://127.0.0.1:{secondary_port}/nested/site'
        log = open(Path(mount) / 'server.log', 'w+')
        secondary = subprocess.Popen(['php', '-S', f'127.0.0.1:{secondary_port}', '-t', mount], stdout=log, stderr=log)
        for _ in range(60):
            try:
                if requests.get(NESTED + '/admin/?page=login', timeout=1).status_code == 200:
                    break
            except requests.ConnectionError:
                time.sleep(.05)
        else:
            raise AssertionError('Secondary physical HTTP runtime did not start.')
        guest = requests.Session()
        response = guest.post(BASE + '/admin/?page=marketing&tab=campaigns', data={'action': 'run_campaign_batch'}, allow_redirects=False, timeout=10)
        check(response.status_code in (302, 303, 403), 'Guest invoked campaign delivery.')
        owner = requests.Session()
        login = owner.get(BASE + '/admin/?page=login', timeout=10)
        signed_in = owner.post(BASE + '/admin/?page=login', data={'_csrf': token(login), 'email': 'admin@example.com', 'password': 'VelvetDemo12'}, timeout=10)
        page = owner.get(BASE + '/admin/?page=marketing&tab=campaigns', timeout=10)
        csrf = token(page)
        check('Email delivery is disabled' in page.text, 'Owner sees no disabled transport status.')
        fixture('homepage_signup', enabled=True)
        first = fixture('contact', email='first@example.test', confirmed=True)
        fixture('contact', email='pending@example.test', confirmed=False)
        fixture('contact', email='suppressed@example.test', confirmed=True)
        fixture('suppress', email='suppressed@example.test')
        fixture('contact', email='revoked@example.test', confirmed=True)
        fixture('revoke', email='revoked@example.test')
        before = len(state()['campaigns'])
        invalid = post(owner, csrf, 'save_campaign', subject='Invalid empty body', body='', queue='1')
        check(len(state()['campaigns']) == before and 'Enter a subject' in invalid.text, 'Empty draft became frozen work.')
        post(owner, csrf, 'save_campaign', subject='Owner HTTP campaign', body='Owner-authored campaign message.', queue='1')
        campaign = state()['campaigns'][-1]
        check(len(deliveries(campaign)) == 1, 'Owner queue bypassed confirmation, suppression or revocation.')
        before = state()
        bad = owner.post(BASE + '/admin/?page=marketing&tab=campaigns', data={'_csrf': 'wrong', 'action': 'run_campaign_batch'}, timeout=10)
        check('session token expired' in bad.text and state()['campaign_queue'] == before['campaign_queue'], 'CSRF rejection mutated deliveries.')
        held = post(owner, csrf, 'run_campaign_batch', batch_limit='3')
        check('Email delivery is disabled' in held.text and deliveries(campaign)[0]['attempts'] == 0, 'Disabled mail consumed an owner-run attempt.')
        check(cli(campaign)['held'] is not None, 'Disabled CLI lost held status.')
        fixture('configure', mode=' smtp ', port=capture.server_address[1], base_url=BASE)
        accepted = post(owner, csrf, 'run_campaign_batch', batch_limit='1')
        check('1 accepted by the mail transport' in accepted.text and deliveries(campaign)[0]['status'] == 'sent', 'Owner batch did not transmit through configured SMTP.')
        check(len(capture.payloads) == 1 and 'Owner-authored campaign message.' in capture.payloads[0], 'SMTP capture did not receive the real campaign body.')
        post(owner, csrf, 'queue_campaign', campaign_id=campaign['id'])
        check(cli(campaign)['accepted'] == 0 and len(capture.payloads) == 1 and len(deliveries(campaign)) == 1, 'Repeated queue/run duplicated accepted email.')
        check(not list((ROOT / 'storage/private/marketing/outbox').glob('*.json')), 'New runner still prepared undispatched outbox files.')

        fixture('configure', mode='smtp', port=capture.server_address[1], base_url=NESTED)
        nested_campaign = fixture('campaign', subject='Nested unsubscribe')
        check(cli(nested_campaign)['accepted'] == 1, 'CLI from another working directory failed.')
        nested_link = re.search(r'Unsubscribe from campaign emails: (\S+)', capture.payloads[-1]).group(1)
        check(nested_link.startswith(NESTED + '/route.php?') and parse_qs(urlparse(nested_link).query).get('vvx_route') == ['newsletter/unsubscribe.php'], 'Nested unsubscribe URL bypassed the physical dispatcher.')
        post(owner, csrf, 'save_marketing_settings', campaign_delivery_enabled='1')
        check(guest.get(public_url('newsletter'), timeout=10).status_code == 404, 'Disabled signup is still public.')
        check(guest.get(public_url('newsletter/confirm.php') + '&token=invalid', timeout=10).status_code == 404, 'Disabled confirmation is still public.')
        home = guest.get(BASE + '/', timeout=10)
        check('Join the Mailing List' not in home.text, 'Homepage links to disabled signup.')
        independent = fixture('campaign', subject='Delivery while signup disabled')
        check(cli(independent)['accepted'] == 1, 'Disabling new signup also stopped consented campaign delivery.')
        unsubscribe = guest.get(nested_link, timeout=10)
        check(unsubscribe.status_code == 200 and 'You have been unsubscribed' in unsubscribe.text, 'Real nested unsubscribe stopped working when signup was disabled.')
        post(owner, csrf, 'save_marketing_settings', newsletter_signup_enabled='1', campaign_delivery_enabled='1')
        check('Join the Mailing List' in guest.get(BASE + '/', timeout=10).text, 'Homepage did not restore enabled signup.')
        fixture('homepage_signup', enabled=False)
        check('Join the Mailing List' not in guest.get(BASE + '/', timeout=10).text, 'Homepage ignored its existing newsletter visibility setting.')
        check(guest.get(public_url('newsletter'), timeout=10).status_code == 200, 'Homepage-only visibility setting disabled the standalone signup route.')
        fixture('homepage_signup', enabled=True)
        first = fixture('contact', email='first@example.test', confirmed=True)
        waiting = fixture('campaign', subject='Scheduled in future', scheduled_at=(datetime.now(timezone.utc) + timedelta(hours=1)).isoformat())
        before_count = len(capture.payloads)
        check(cli(waiting)['processed'] == 0 and len(capture.payloads) == before_count and deliveries(waiting)[0]['attempts'] == 0, 'Future campaign was sent early.')
        paused = fixture('campaign', subject='Owner pause')
        post(owner, csrf, 'pause_campaign', campaign_id=paused['id'])
        check(cli(paused)['processed'] == 0, 'Paused campaign was sent.')
        post(owner, csrf, 'resume_campaign', campaign_id=paused['id'])
        check(cli(paused)['accepted'] == 1, 'Resumed due campaign did not send.')
        disabled = fixture('campaign', subject='Independent campaign switch')
        post(owner, csrf, 'save_marketing_settings', newsletter_signup_enabled='1')
        check(cli(disabled)['held'] is not None and guest.get(public_url('newsletter'), timeout=10).status_code == 200, 'Campaign switch affected signup or failed to hold email.')
        post(owner, csrf, 'save_marketing_settings', newsletter_signup_enabled='1', campaign_delivery_enabled='1')
        check(cli(disabled)['accepted'] == 1, 'Reenabled campaign delivery lost waiting work.')

        for mode in ['temporary', 'permanent']:
            capture.mode = mode
            failed = fixture('campaign', subject='Definite ' + mode + ' rejection')
            check(cli(failed)['failed'] == 1 and deliveries(failed)[0]['status'] == 'failed', 'A definite SMTP rejection became accepted or unknown.')
            count = len(capture.payloads)
            check(cli(failed)['processed'] == 0 and len(capture.payloads) == count, 'A failed message retried without owner action.')
            post(owner, csrf, 'retry_campaign', campaign_id=failed['id'])
            check(cli(failed)['processed'] == 0, 'Owner retry ignored cooldown.')
            fixture('cooldown', campaign_id=failed['id'])
            capture.mode = 'accepted'
            check(cli(failed)['accepted'] == 1 and deliveries(failed)[0]['attempts'] == 2, 'Definite failure retry did not use configured SMTP.')
        capture.mode = 'unknown'
        unknown = fixture('campaign', subject='Lost acknowledgement')
        check(cli(unknown)['unknown'] == 1 and deliveries(unknown)[0]['status'] == 'unknown', 'Lost DATA acknowledgement was treated as retryable failure.')
        count = len(capture.payloads)
        post(owner, csrf, 'retry_campaign', campaign_id=unknown['id'])
        post(owner, csrf, 'queue_campaign', campaign_id=unknown['id'])
        check(cli(unknown)['processed'] == 0 and len(capture.payloads) == count, 'Ambiguous acknowledgement caused a duplicate transmission.')
        capture.mode = 'accepted'
        interrupted = fixture('campaign', subject='Interrupted transmission')
        fixture('stage', campaign_id=interrupted['id'], status='sending')
        check(cli(interrupted)['recovered_claims'] == 1 and deliveries(interrupted)[0]['status'] == 'unknown', 'Interrupted send was silently retried.')
        unstarted = fixture('campaign', subject='Interrupted before transmission')
        fixture('stage', campaign_id=unstarted['id'], status='claimed')
        check(cli(unstarted)['accepted'] == 1, 'An interrupted pre-transmission claim could not resume.')
        duplicate = fixture('campaign', subject='Legacy duplicate queue')
        fixture('duplicate', campaign_id=duplicate['id'])
        count = len(capture.payloads)
        duplicate_result = cli(duplicate)
        check(duplicate_result['accepted'] == 1 and duplicate_result['suppressed'] == 1 and len(capture.payloads) == count + 1, 'Legacy duplicate entries produced two emails.')

        # Two live worker invocations and an unsubscribe while SMTP waits for DATA.
        race_campaign = fixture('campaign', subject='Unsubscribe during handshake')
        capture.delay = True; capture.handshake.clear(); capture.release.clear()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            running = pool.submit(cli, race_campaign)
            check(capture.handshake.wait(10), 'SMTP did not reach its delayed handshake.')
            check(cli(race_campaign)['busy'] is True, 'Concurrent runner acquired the delivery mutex.')
            fixture('edit_post')
            response = guest.get(public_url('newsletter/unsubscribe.php', NESTED) + '&token=' + first['contact']['unsubscribe_token'], timeout=5)
            check(response.status_code == 200, 'Concurrent unsubscribe was blocked by delivery storage lock.')
            count = len(capture.payloads); capture.release.set()
            check(running.result(timeout=15)['suppressed'] == 1 and len(capture.payloads) == count, 'Revoked claim transmitted a message body.')
        check(state()['posts'][-1]['title'] == 'Concurrent content was retained', 'Finishing a batch overwrote a concurrent content edit.')
        capture.delay = False
        first = fixture('contact', email='first@example.test', confirmed=True)

        # Both requests share the authenticated owner's cookie and CSRF token.
        # The second PHP worker uses the same on-disk session, proving the batch
        # releases the session lock while it waits for SMTP.
        owner_pause = fixture('campaign', subject='Owner pause during handshake')
        capture.delay = True; capture.handshake.clear(); capture.release.clear()
        owner_parallel = requests.Session(); owner_parallel.cookies.update(owner.cookies)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            running = pool.submit(post, owner, csrf, 'run_campaign_batch', campaign_id=owner_pause['id'], batch_limit='1')
            check(capture.handshake.wait(10), 'Owner batch did not reach delayed SMTP.')
            paused_response = post(owner_parallel, csrf, 'pause_campaign', base=NESTED, campaign_id=owner_pause['id'])
            check('Campaign paused' in paused_response.text, 'Owner pause was blocked by the running batch session.')
            count = len(capture.payloads); capture.release.set(); running.result(timeout=15)
            check(deliveries(owner_pause)[0]['status'] == 'queued' and len(capture.payloads) == count and deliveries(owner_pause)[0]['attempts'] == 0, 'Owner pause did not cancel its claimed message without consuming an attempt.')
        capture.delay = False
        post(owner, csrf, 'resume_campaign', campaign_id=owner_pause['id'])
        check(cli(owner_pause)['accepted'] == 1 and deliveries(owner_pause)[0]['attempts'] == 1, 'Owner could not resume a canceled pre-transmission claim as its first attempt.')

        fixture('contact', email='second@example.test', confirmed=True)
        fixture('contact', email='third@example.test', confirmed=True)
        bounded = fixture('campaign', subject='Bounded queue')
        check(bounded['queued_count'] == 3, 'Bounded fixture did not have three recipients.')
        check(cli(bounded, limit=1)['accepted'] == 1 and sum(row['status'] == 'queued' for row in deliveries(bounded)) == 2, 'Batch limit was ignored.')
        check(cli(bounded, limit=2)['accepted'] == 2 and cli(bounded)['processed'] == 0, 'Resuming bounded work duplicated or lost recipients.')
        count = len(capture.payloads)
        newsletter = guest.get(public_url('newsletter'), timeout=10)
        newsletter_csrf = token(newsletter, 'csrf')
        suppressed_signup = guest.post(public_url('newsletter'), data={'csrf': newsletter_csrf, 'email': 'suppressed@example.test', 'consent': '1'}, timeout=10)
        check(suppressed_signup.status_code == 200 and len(capture.payloads) == count, 'Suppressed signup sent a confirmation email.')
        before = state()['campaign_queue']
        fixture('owner_status', active=False)
        rejected = owner.post(BASE + '/admin/?page=marketing&tab=campaigns', data={'_csrf': csrf, 'action': 'run_campaign_batch'}, timeout=10, allow_redirects=False)
        check(rejected.status_code in (302, 303, 403) and state()['campaign_queue'] == before, 'Inactive owner ran a batch.')
        fixture('owner_status', active=True)
        check(not capture.errors, 'SMTP fixture recorded an unexpected command or non-fixture recipient.')
    finally:
        capture.release.set()
        capture.shutdown(); capture.server_close(); thread.join(timeout=3)
        if secondary is not None:
            secondary.terminate()
            try:
                secondary.wait(timeout=5)
            except subprocess.TimeoutExpired:
                secondary.kill(); secondary.wait(timeout=5)
        if log is not None:
            log.close()
print(f'Campaign SMTP/HTTP regressions passed ({checks} assertions; localhost capture only).')
