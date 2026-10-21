#!/usr/bin/env python3
"""Actual packaged campaign previews, templates and owner tests; loopback capture only."""
from __future__ import annotations

import argparse
import concurrent.futures
from contextlib import contextmanager
from copy import deepcopy
from email import policy
from email.parser import BytesParser
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
from zipfile import ZipFile

from bs4 import BeautifulSoup
import requests

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


depth = load('vvx_campaign_message_depth', ROOT / 'tests/Http/composer_depth_http.py')
http = depth._base
check = http.check
FILES = ('app/Core/CampaignMessage.php', 'app/Core/MarketingCampaignMessages.php', 'app/Core/CampaignTestService.php',
         'app/Core/MailService.php', 'app/Core/CampaignDeliveryService.php', 'app/Core/MarketingStore.php',
         'admin/index.php', 'app/Views/admin/marketing.php', 'app/Views/admin/marketing-actions.php',
         'app/Views/admin/marketing-campaign-form.php', 'app/Views/admin/marketing-email-brand.php',
         'app/Views/admin/campaign-message-preview.php')
FIXTURE = Path(__file__).with_name('campaign_messages_fixture.php')


def prepare_package(work):
    archive, identity = depth.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in FILES), 'Fresh does not contain exact campaign runtime bytes.')
    return archive, identity


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.server.connections += 1
        self.request.settimeout(15)
        recipient = ''

        def reply(value):
            self.wfile.write((value + '\r\n').encode())
            self.wfile.flush()

        reply('220 Local campaign message capture')
        try:
            while raw := self.rfile.readline():
                line = raw.decode('utf-8', 'strict').strip('\r\n')
                if line.startswith('EHLO '):
                    reply('250 Local capture')
                elif line.startswith('MAIL FROM:'):
                    check(line == 'MAIL FROM:<owner@example.test>', 'Non-fixture sender reached capture.')
                    reply('250 Sender accepted')
                elif line.startswith('RCPT TO:'):
                    recipient = line[len('RCPT TO:'):].strip('<>')
                    if not re.fullmatch(r'[a-zA-Z0-9._+-]+@example\.test', recipient):
                        self.server.errors.append('Non-fixture recipient')
                        reply('550 Reserved fixture mailboxes only')
                        return
                    reply('250 Recipient accepted')
                elif line == 'DATA':
                    if self.server.delay:
                        self.server.handshake.set()
                        if not self.server.release.wait(15):
                            self.server.errors.append('Handshake was not released')
                            return
                    reply('354 Send body')
                    body = []
                    while raw := self.rfile.readline():
                        if raw == b'.\r\n':
                            break
                        body.append(raw[1:] if raw.startswith(b'..') else raw)
                    else:
                        return
                    self.server.payloads.append({'recipient': recipient, 'body': b''.join(body)})
                    if self.server.mode == 'unknown':
                        return
                    reply('550 Rejected by local capture' if self.server.mode == 'rejected' else '250 Accepted')
                elif line == 'QUIT':
                    reply('221 Bye')
                    return
                else:
                    self.server.errors.append('Unexpected SMTP command')
                    return
        except (BrokenPipeError, ConnectionResetError, TimeoutError):
            return


class Capture(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        super().__init__(('127.0.0.1', 0), Handler)
        self.connections = 0
        self.payloads, self.errors = [], []
        self.mode, self.delay = 'accepted', False
        self.handshake, self.release = threading.Event(), threading.Event()


@contextmanager
def runtime(work, archive, mode, label, prefix, evidence):
    """Reuse the guarded installer/storage fixture, with a send-aware SMTP observer."""
    php = shutil.which('php')
    check(php is not None, 'PHP is required.')
    document_root = work / label / 'www'
    site = document_root / prefix.lstrip('/')
    site.mkdir(parents=True)
    with ZipFile(archive) as zipped:
        zipped.extractall(site)
    (site / '.vvx-test-runtime').write_text('Owned campaign message acceptance.\n')
    with socket.socket() as available:
        available.bind(('127.0.0.1', 0))
        port = available.getsockname()[1]
    base = f'http://127.0.0.1:{port}{prefix}'
    capture = Capture()
    threading.Thread(target=capture.serve_forever, daemon=True).start()
    process = None
    log_path = work / label / 'php-server.log'
    try:
        http.fixture(site, 'setup-' + mode, php, base=base, smtp_port=capture.server_address[1])
        with log_path.open('w+') as log:
            environment = os.environ.copy()
            environment.pop('PHP_CLI_SERVER_WORKERS', None)
            process = subprocess.Popen([php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S',
                                        f'127.0.0.1:{port}', '-t', str(document_root)],
                                       cwd=work, env=environment, stdout=log, stderr=log)
            client = http.session()
            try:
                deadline = time.monotonic() + 15
                while time.monotonic() < deadline:
                    try:
                        if client.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                            break
                    except requests.RequestException:
                        time.sleep(.05)
                else:
                    raise AssertionError('The actual packaged HTTP server did not start.')
            finally:
                client.close()
            case = dict(site=site, base=base, php=php, capture=capture, mode=mode, label=label)
            yield case
            log.flush()
            check(not capture.errors, 'Unexpected local SMTP capture behavior: ' + repr(capture.errors))
            check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()), 'Packaged PHP emitted diagnostics.')
    finally:
        capture.release.set()
        if process is not None:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill(); process.wait(timeout=5)
        capture.shutdown(); capture.server_close()
        if mode == 'mysql' and (site / 'storage/temp/editorial-access-ownership.json').is_file():
            http.fixture(site, 'cleanup-mysql', php)
        if evidence and log_path.exists():
            shutil.copyfile(log_path, evidence / ('campaign-messages-' + label + '-http.log'))


def state(case):
    return http.fixture(case['site'], 'read', case['php'])


def private_fixture(case, action):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action], text=True,
                            capture_output=True, timeout=25, cwd='/tmp')
    check(result.returncode == 0, 'Owned campaign fixture failed: ' + result.stderr[:1000])
    return json.loads(result.stdout)


def age_tests(case):
    return private_fixture(case, 'age-tests')


def seed_recipient(case):
    seeded = private_fixture(case, 'seed-recipient')
    check(seeded == {'recipient': 'campaign-recipient@example.test', 'confirmed': True}, 'The actual consented recipient was not seeded.')


def campaign_page(owner, case):
    page = owner.get(case['base'] + '/admin/?page=marketing&tab=campaigns', timeout=20)
    check(page.status_code == 200 and 'data-campaign-editor' in page.text, 'Actual campaign editor did not open.')
    return page


def post(owner, case, values):
    return owner.post(case['base'] + '/admin/?page=marketing&tab=campaigns', data=values, timeout=30)


def saved_campaign(case, subject):
    matches = [row for row in state(case)['marketing']['campaigns'] if row.get('subject') == subject]
    check(len(matches) == 1, 'Expected exactly one saved campaign.')
    return matches[0]


def preview(owner, case, campaign_id):
    page = campaign_page(owner, case)
    link = BeautifulSoup(page.text, 'html.parser').select_one(f'[data-campaign-id="{campaign_id}"] [data-campaign-message-preview]')
    check(link is not None, 'Real saved-message preview link is absent.')
    response = owner.get(link['href'], timeout=20)
    check(response.status_code == 200 and 'data-campaign-plain' in response.text, 'Private saved-message preview failed.')
    http.private(response, True)
    return response


def parsed(payload):
    message = BytesParser(policy=policy.default).parsebytes(payload['body'])
    text = message.get_body(preferencelist=('plain',)).get_content().replace('\r\n', '\n').rstrip('\n')
    html = message.get_body(preferencelist=('html',))
    return message, text, None if html is None else html.get_content().replace('\r\n', '\n').rstrip('\n')


def http_case(case):
    start = http.checks
    owner = http.owner_login(case['base'])
    guest = http.session()
    capture = case['capture']
    try:
        if case['mode'] == 'mysql':
            http.activate_mysql(owner, case['base'], case['site'], case['php'])
        seed_recipient(case)
        baseline = state(case)
        page = campaign_page(owner, case)
        brand = dict(http.fields(page.text, '[data-email-brand-form]'))
        brand.update(brand_name='Message fixture <brand>', tagline='Saved branded email', accent='#f52d91', footer='Frozen footer <safe>')
        before = state(case)['marketing']
        denied = guest.post(case['base'] + '/admin/?page=marketing&tab=campaigns', data=brand, allow_redirects=False, timeout=15)
        check(denied.status_code in (302, 303, 403) and state(case)['marketing'] == before, 'Guest changed email branding.')
        denied = post(owner, case, {**brand, '_csrf': 'invalid'})
        check(denied.status_code == 419 and state(case)['marketing'] == before, 'Bad CSRF changed branding.')
        response = post(owner, case, brand)
        check(response.status_code == 200 and response.history and 'Email brand saved.' in response.text, 'Real brand editor failed.')
        stale = post(owner, case, {**brand, 'brand_name': 'Stale overwrite'})
        check(stale.status_code == 409 and 'Stale overwrite' in stale.text, 'Stale brand did not preserve the rejected attempt.')
        fields = dict(http.fields(campaign_page(owner, case).text, '[data-campaign-editor]'))
        subject = 'Actual saved campaign <safe>'
        fields.update(subject=subject, preheader='A shared preview', body='Saved text <script>alert(1)</script>\nhttps://example.test/offer\n' + ('Long message line ' * 100),
                      cta_label='Visit membership', cta_url='/membership/', format='branded', audience_selection='all')
        response = post(owner, case, fields)
        check(response.status_code == 200 and response.history, 'Campaign draft save failed.')
        record = saved_campaign(case, subject)
        initial = state(case)
        rendered = preview(owner, case, record['id'])
        check(state(case)['marketing'] == initial['marketing'] and capture.connections == 0, 'Preview mutated storage or contacted SMTP.')
        soup = BeautifulSoup(rendered.text, 'html.parser')
        iframe = soup.select_one('iframe[sandbox]')
        check(iframe is not None and '<script>' not in iframe['srcdoc'] and '<a ' not in iframe['srcdoc'], 'Preview allows script or active links.')
        check('Message fixture &lt;brand&gt;' in iframe['srcdoc'] and 'Frozen footer &lt;safe&gt;' in iframe['srcdoc'], 'Preview did not use saved branding safely.')
        expected_text = soup.select_one('[data-campaign-plain]').get_text()
        test_fields = dict(http.fields(rendered.text, '[data-campaign-test-form]'))
        denied = guest.get(rendered.url, timeout=15)
        check(denied.status_code == 403 and subject not in denied.text, 'Guest could read private campaign content.')
        check(owner.post(rendered.url, data=test_fields, timeout=15).status_code == 405, 'Preview accepted a mutation method.')
        wrong_revision = owner.get(rendered.url.replace('revision=', 'revision=0', 1), timeout=15)
        check(wrong_revision.status_code == 409 and capture.connections == 0, 'Preview ignored its saved-content revision.')
        denied = post(owner, case, {**test_fields, '_csrf': 'wrong'})
        check(denied.status_code == 419 and capture.connections == 0, 'Test sent without CSRF.')
        result = post(owner, case, {**test_fields, 'recipient': 'attacker@example.test'})
        check(result.status_code == 200 and 'Campaign test accepted' in result.text and len(capture.payloads) == 1, 'Actual owner test was not transmitted once.')
        payload = capture.payloads[-1]
        message, sent_text, sent_html = parsed(payload)
        check(max(map(len, payload['body'].split(b'\r\n'))) <= 998, 'Rendered MIME message exceeded SMTP line limits.')
        check(payload['recipient'] == http.OWNER and str(message['Subject']) == '[Test] ' + subject, 'Test recipient or subject was not constrained.')
        check(sent_text == expected_text and sent_html is not None and 'Message fixture &lt;brand&gt;' in sent_html, 'Sent MIME content differs from preview or lost HTML/text alternatives.')
        check('<script>' not in sent_html and 'campaign-preview-not-a-subscriber' in sent_text, 'Test leaked an executable body or real unsubscribe token.')
        after = state(case)
        journal = after['marketing']['settings']['campaign_test_journal']
        check(journal[test_fields['test_request']]['status'] == 'sent' and str(message['Message-ID']) == journal[test_fields['test_request']]['message_id'], 'Test outcome and stable message ID were not committed.')
        check(after['marketing']['campaign_queue'] == initial['marketing']['campaign_queue'] and after['outbox'] == initial['outbox'], 'Owner test queued subscribers or changed transactional mail.')
        replay = post(owner, case, test_fields)
        check(replay.status_code == 409 and len(capture.payloads) == 1, 'One-use test replay sent duplicate mail.')
        cooldown = dict(http.fields(preview(owner, case, record['id']).text, '[data-campaign-test-form]'))
        check(post(owner, case, cooldown).status_code == 429 and len(capture.payloads) == 1, 'Test cooldown was bypassed.')
        age_tests(case)
        unknown_form = dict(http.fields(preview(owner, case, record['id']).text, '[data-campaign-test-form]'))
        capture.mode = 'unknown'
        unknown = post(owner, case, unknown_form)
        check(unknown.status_code == 200 and 'outcome is unknown' in unknown.text and len(capture.payloads) == 2, 'Lost SMTP acknowledgement did not hold the test.')
        check(state(case)['marketing']['settings']['campaign_test_journal'][unknown_form['test_request']]['status'] == 'unknown', 'Unknown transport result was not durable.')
        check(post(owner, case, unknown_form).status_code == 409 and len(capture.payloads) == 2, 'Unknown test was retried.')
        capture.mode = 'accepted'
        age_tests(case)
        revoked_form = dict(http.fields(preview(owner, case, record['id']).text, '[data-campaign-test-form]'))
        capture.delay = True
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            waiting = pool.submit(post, owner, case, revoked_form)
            check(capture.handshake.wait(10), 'The real SMTP handshake was not reached.')
            http.fixture(case['site'], 'owner-status', case['php'], status='demoted')
            capture.release.set()
            check(waiting.result(timeout=25).status_code == 403, 'Revoked test response did not fail closed.')
        check(len(capture.payloads) == 2 and state(case)['marketing']['settings']['campaign_test_journal'][revoked_form['test_request']]['status'] == 'skipped', 'A revoked owner transmitted after the SMTP handshake.')
        http.fixture(case['site'], 'owner-status', case['php'], status='owner')
        capture.delay = False
        edit = owner.get(case['base'] + '/admin/?page=marketing&tab=campaigns&edit_campaign=' + record['id'], timeout=20)
        edit_fields = dict(http.fields(edit.text, '[data-campaign-editor]'))
        stale_fields = deepcopy(edit_fields)
        edit_fields['body'] = 'Changed saved message'
        check(post(owner, case, edit_fields).status_code == 200, 'Current saved text could not be updated.')
        before = state(case)['marketing']
        stale = post(owner, case, {**stale_fields, 'body': 'Stale unsaved attempt'})
        check(stale.status_code == 409 and 'Stale unsaved attempt' in stale.text and state(case)['marketing'] == before, 'Stale content overwrote a newer draft or lost attempted text.')
        check(owner.get(rendered.url, timeout=15).status_code == 409, 'An old message preview remained valid after editing.')
        page = campaign_page(owner, case)
        queue = dict(http.fields(page.text, f'[data-campaign-id="{record["id"]}"] form'))
        queue['action'] = 'queue_campaign'
        check(post(owner, case, queue).status_code == 200, 'Real campaign queue failed.')
        queued = saved_campaign(case, subject)
        frozen = deepcopy(queued['delivery_snapshot'])
        check(frozen['template']['brand_name'] == 'Message fixture <brand>', 'Queue omitted the saved branded template.')
        brand_next = dict(http.fields(campaign_page(owner, case).text, '[data-email-brand-form]'))
        brand_next['brand_name'] = 'Future brand only'
        check(post(owner, case, brand_next).status_code == 200, 'Later brand save failed.')
        check(saved_campaign(case, subject)['delivery_snapshot'] == frozen, 'Later branding altered the frozen queued template.')
        preview_queued = preview(owner, case, record['id'])
        check('Message fixture &lt;brand&gt;' in BeautifulSoup(preview_queued.text, 'html.parser').select_one('iframe')['srcdoc'], 'Queued preview used current rather than frozen branding.')
        settings = dict(http.fields(campaign_page(owner, case).text, '[data-campaign-delivery] form'))
        settings['campaign_delivery_enabled'] = '1'
        check(post(owner, case, settings).status_code == 200, 'The real owner delivery switch could not be enabled.')
        result = subprocess.run([case['php'], str(case['site'] / 'app/Console/run_marketing.php'), '--limit=1', '--campaign=' + record['id']],
                                text=True, capture_output=True, timeout=30, cwd='/tmp')
        check(result.returncode == 0 and json.loads(result.stdout)['accepted'] == 1 and len(capture.payloads) == 3,
              'Actual campaign worker did not send the frozen message once: ' + result.stdout[:1000] + result.stderr[:1000])
        sent_message, sent_body, sent_html = parsed(capture.payloads[-1])
        delivery = next(row for row in state(case)['marketing']['campaign_queue'] if row['campaign_id'] == record['id'])
        check(capture.payloads[-1]['recipient'] == 'campaign-recipient@example.test' and str(sent_message['Message-ID']) == delivery['message_id'], 'Campaign stable ID or eligible recipient was wrong.')
        check('Message fixture &lt;brand&gt;' in sent_html and 'Future brand only' not in sent_html and 'Changed saved message' in sent_body, 'Actual queued content was changed after freezing.')
        check('campaign-preview-not-a-subscriber' not in sent_body and 'token=' in sent_body, 'Subscriber delivery lacked its own unsubscribe link.')
        repeated = subprocess.run([case['php'], str(case['site'] / 'app/Console/run_marketing.php'), '--limit=1', '--campaign=' + record['id']],
                                  text=True, capture_output=True, timeout=30, cwd='/tmp')
        check(repeated.returncode == 0 and json.loads(repeated.stdout)['accepted'] == 0 and len(capture.payloads) == 3, 'Repeated worker sent accepted content again.')
        final = state(case)
        check(final['protected'] == baseline['protected'] and final['outbox'] == baseline['outbox'] and final['unrelated'] == baseline['unrelated'], 'Campaign work changed unrelated installed records.')
        for key in ('posts', 'banners', 'landing_pages', 'contacts', 'consents', 'suppressions'):
            check(final['marketing'][key] == baseline['marketing'][key], 'Campaign controls altered unrelated ' + key)
        if case['mode'] == 'mysql':
            http.independent_sql(final)
        return {'checks': http.checks - start, 'mode': case['mode'], 'accepted_test_messages': 1,
                'unknown_test_messages': 1, 'revoked_before_transmission': True, 'campaign_messages': 1,
                'captured_messages': len(capture.payloads), 'stable_message_ids': True, 'external_messages': 0,
                'private_revision_bound_preview': True, 'frozen_template_preserved': True}
    finally:
        capture.release.set()
        owner.close(); guest.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='file')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'campaign_messages_http', 'passed': False, 'cases': {},
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'fixture_sha256': hashlib.sha256(FIXTURE.read_bytes()).hexdigest()}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-campaign-messages-http-') as temporary:
            work = Path(temporary)
            archive, identity = prepare_package(work)
            summary.update(identity)
            for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                for label, prefix in (('root', ''), ('nested', '/nested/velvet')):
                    with runtime(work, archive, mode, mode + '-' + label, prefix, evidence) as case:
                        summary['cases'][mode + '-' + label] = http_case(case)
                    print('Campaign messages ' + mode + '-' + label + ': PASS', flush=True)
        summary['passed'] = True
        print(json.dumps(summary, indent=2))
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'campaign-messages-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
