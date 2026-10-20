#!/usr/bin/env python3
"""Actual owner replies and serialized loopback SMTP, in source or Fresh ZIPs.

Direct invocation builds/extracts the customer package and checks root/nested
installs. The existing HTTP harness may also select this module as one suite.
Only marked temporary fixtures and reserved recipient addresses are accepted.
"""
from __future__ import annotations

from email import policy
from email.parser import BytesParser
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
from urllib.parse import urlencode, urlparse
from zipfile import ZipFile

import requests
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def session():
    result = requests.Session()
    result.trust_env = False
    return result


def token(response):
    field = BeautifulSoup(response.text, 'html.parser').select_one('input[name="_csrf"]')
    check(field is not None and bool(field.get('value')), 'The real form has no CSRF token.')
    return field['value']


def route(base, destination):
    return base + '/route.php?' + urlencode({'vvx_route': destination})


class CaptureHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(10)
        self.server.connections += 1
        recipient = None

        def reply(value):
            self.wfile.write((value + '\r\n').encode())
            self.wfile.flush()

        reply('220 Local private-reply capture')
        try:
            while line := self.rfile.readline():
                command = line.decode('ascii', 'replace').rstrip('\r\n')
                if command.startswith('EHLO '):
                    reply('250 Local fixture')
                elif command == 'MAIL FROM:<notices@example.test>':
                    reply('250 Sender accepted')
                elif command in ('RCPT TO:<member@example.test>', 'RCPT TO:<guest@example.test>'):
                    recipient = command[len('RCPT TO:<'):-1]
                    reply('250 Recipient accepted')
                elif command == 'DATA' and recipient is not None:
                    reply('354 Send message')
                    payload = []
                    while line := self.rfile.readline():
                        if line == b'.\r\n':
                            break
                        payload.append(line[1:] if line.startswith(b'..') else line)
                    else:
                        self.server.errors.append('SMTP message ended before the DATA terminator.')
                        return
                    self.server.payloads.append((recipient, b''.join(payload)))
                    reply('250 Captured locally')
                elif command == 'QUIT':
                    reply('221 Goodbye')
                    return
                else:
                    self.server.errors.append('Unexpected SMTP command or non-fixture address.')
                    reply('550 Reserved fixture only')
                    return
        except (OSError, TimeoutError):
            self.server.errors.append('Local SMTP capture disconnected unexpectedly.')


class Capture(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        super().__init__(('127.0.0.1', 0), CaptureHandler)
        self.payloads, self.errors = [], []
        self.connections = 0


def run_http(site: Path, base: str, php: str):
    global checks
    checks = 0
    check(site.resolve().is_relative_to(Path(tempfile.gettempdir()).resolve())
          and (site / '.vvx-test-runtime').is_file() and urlparse(base).hostname == '127.0.0.1',
          'A marked temporary localhost installation is required.')
    nonce = secrets.token_hex(8)
    capture = Capture()
    worker = threading.Thread(target=capture.serve_forever, daemon=True)
    worker.start()
    accounts = [session() for _ in range(3)]
    owner, member, guest = accounts
    try:
        setup = subprocess.run([php, str(ROOT / 'tests/Http/private_reply_email_fixture.php'), str(site)],
                               input=json.dumps({'port': capture.server_address[1], 'nonce': nonce}),
                               capture_output=True, text=True, timeout=20)
        check(setup.returncode == 0, 'Guarded private-reply fixture failed: ' + setup.stderr[:500])
        fixture = json.loads(setup.stdout)

        def state():
            return json.loads((site / 'storage/data.json').read_text())

        original = state()
        before_preferences = original['members'][fixture['member_id']]['preferences']
        original_outbox = len(original.get('mailOutbox', {}))
        config_bytes = (site / 'config/app.php').read_bytes()
        lock_bytes = (site / 'storage/install.lock').read_bytes()
        page = owner.get(base + '/admin/?page=login', timeout=10)
        logged = owner.post(base + '/admin/?page=login', data={'_csrf': token(page), 'email': 'owner@example.test',
                            'password': 'VelvetDemo12'}, timeout=10)
        check(logged.status_code == 200 and 'page=login' not in logged.url, 'Actual owner login failed.')
        member_login = member.get(route(base, 'account/login.php'), timeout=10)
        logged = member.post(route(base, 'account/login.php'), data={'_csrf': token(member_login),
                             'email': 'member@example.test', 'password': 'VelvetMember12', 'return': '/account/'}, timeout=10)
        check(logged.status_code == 200 and 'account/login' not in logged.url, 'Actual member login failed.')

        member_thread = fixture['member_thread']
        owner_url = base + '/admin/?page=messages&thread=' + member_thread
        page = owner.get(owner_url, timeout=10)
        payload = {'action': 'admin_send_message', 'thread_id': member_thread, 'message': 'REJECTED-' + nonce}
        before_messages = len(state().get('messages', {}))
        rejected = guest.post(owner_url, data=payload, allow_redirects=False, timeout=10)
        check(rejected.status_code in (302, 303, 403), 'An unauthenticated owner reply was accepted.')
        rejected = owner.post(owner_url, data={**payload, '_csrf': 'invalid-fixture-token'}, timeout=10)
        check('session token expired' in rejected.text and len(state().get('messages', {})) == before_messages
              and not capture.payloads, 'The owner reply bypassed CSRF or sent rejected content.')

        for kind, thread_id, recipient in [('member', member_thread, 'member@example.test'),
                                           ('guest', fixture['guest_thread'], 'guest@example.test')]:
            subject_marker = kind.upper() + '-PRIVATE-SUBJECT-' + nonce
            body_marker = kind.upper() + '-PRIVATE-BODY-' + nonce
            filename = kind + '-PRIVATE-ATTACHMENT-' + nonce + '.txt'
            owner_url = base + '/admin/?page=messages&thread=' + thread_id
            page = owner.get(owner_url, timeout=10)
            check(page.status_code == 200 and subject_marker in page.text, 'Owner cannot read the seeded private subject.')
            sent = owner.post(owner_url, data={'_csrf': token(page), 'action': 'admin_send_message',
                              'thread_id': thread_id, 'message': body_marker},
                              files={'attachments[]': (filename, b'Synthetic private attachment contents.', 'text/plain')}, timeout=15)
            check(sent.status_code == 200 and 'Reply sent.' in sent.text and body_marker in sent.text,
                  'The actual owner reply failed or erased in-site message content.')
            check(len(capture.payloads) == (1 if kind == 'member' else 2), 'The owner reply sent the wrong number of SMTP messages.')
            delivered_to, raw = capture.payloads[-1]
            check(delivered_to == recipient, 'The notice used the wrong member/guest address.')
            message = BytesParser(policy=policy.default).parsebytes(raw)
            decoded = str(message['Subject']) + '\n' + message.get_content()
            exposed = [label for label, marker in [('private subject', subject_marker), ('private body', body_marker),
                                                    ('attachment filename', filename)]
                       if marker in decoded or marker.encode() in raw]
            check(not exposed, 'Serialized ' + kind + ' notice exposed: ' + ', '.join(exposed))
            check(str(message['Subject']) == 'New private reply', 'The notice subject is not generic.')
            links = re.findall(r'http://127\.0\.0\.1:[0-9]+/[^\s]+', message.get_content())
            check(len(links) == 1 and links[0].startswith(base + '/'), 'The notice lost its installation-prefixed website link.')
            current = state()
            saved = [row for row in current['messages'].values() if row['threadId'] == thread_id and row['body'] == body_marker]
            check(len(saved) == 1 and saved[0]['visibility'] == 'public'
                  and current['messageThreads'][thread_id]['subject'] == subject_marker, 'Private message/subject persistence changed.')
            attachments = [row for row in current['messageAttachments'].values() if row['messageId'] == saved[0]['id']]
            check(len(attachments) == 1 and attachments[0]['originalName'] == filename
                  and attachments[0]['filePath'].startswith('private:'), 'Protected attachment persistence changed.')
            outbox = [row for row in current['mailOutbox'].values() if row['recipientEmail'] == recipient and row['template'] == 'communication.reply']
            check(len(outbox) == 1 and outbox[0]['status'] == 'sent' and subject_marker not in json.dumps(outbox)
                  and body_marker not in json.dumps(outbox), 'The reply transport result/outbox retained private content.')
            if kind == 'member':
                check('Sign in' in message.get_content(), 'Member notice does not explain authenticated access.')
                visible = member.get(links[0], timeout=10)
                check(visible.status_code == 200 and body_marker in visible.text and subject_marker in visible.text,
                      'The member notice link does not open the authorized private conversation.')
                hidden = guest.get(links[0], allow_redirects=False, timeout=10)
                check(hidden.status_code in (302, 303, 403) and body_marker not in hidden.text,
                      'The member notice link bypassed guest authentication.')
            else:
                check('booking inquiry' in message.get_content() and 'contact us' in message.get_content().lower()
                      and 'Sign in' not in message.get_content() and thread_id not in message.get_content(),
                      'Guest notice falsely promises access to an authenticated conversation.')
                contact = guest.get(links[0], timeout=10)
                check(contact.status_code == 200 and 'contact_email' in contact.text and body_marker not in contact.text,
                      'The guest notice does not lead to the public booking contact form.')

            internal_marker = kind.upper() + '-INTERNAL-NOTE-' + nonce
            page = owner.get(owner_url, timeout=10)
            before = state()
            count = len(capture.payloads)
            connections = capture.connections
            noted = owner.post(owner_url, data={'_csrf': token(page), 'action': 'admin_send_message', 'thread_id': thread_id,
                               'message': internal_marker, 'internal_note': '1'}, timeout=10)
            check(noted.status_code == 200 and 'Internal note saved.' in noted.text, 'Internal note did not save.')
            after = state()
            check(len(capture.payloads) == count and capture.connections == connections
                  and after.get('mailOutbox') == before.get('mailOutbox'), 'An internal note connected to SMTP or queued email.')
            check(after.get('memberNotifications') == before.get('memberNotifications'), 'An internal note notified a member.')
            check(any(row['threadId'] == thread_id and row['body'] == internal_marker and row['visibility'] == 'internal'
                      for row in after['messages'].values()), 'Internal note persistence changed.')
            if kind == 'member':
                check(internal_marker not in member.get(links[0], timeout=10).text, 'The member could read an internal note.')

        final = state()
        check(len(final.get('mailOutbox', {})) == original_outbox + 2 and len(capture.payloads) == 2
              and not capture.errors, 'Local capture/outbox outcomes diverged: ' + repr(capture.errors))
        check(final['members'][fixture['member_id']]['preferences'] == before_preferences,
              'Reply privacy changed member preferences.')
        audit = [row['action'] for row in final['audit'][len(original.get('audit', [])):]]
        check(audit.count('communication.reply.sent') == 2 and audit.count('communication.note.added') == 2,
              'Owner reply/note auditing changed.')
        check((site / 'config/app.php').read_bytes() == config_bytes and (site / 'storage/install.lock').read_bytes() == lock_bytes,
              'Reply actions changed installation configuration or identity.')
        return {'assertions': checks, 'member_and_guest_private_subject_body_absent': True,
                'attachment_names_absent': True, 'in_site_content_and_protection_preserved': True,
                'internal_notes_send_none': True, 'local_smtp_messages': 2, 'external_messages_sent': 0}
    finally:
        for account in accounts:
            account.close()
        capture.shutdown()
        capture.server_close()
        worker.join(timeout=3)


def run():
    php = shutil.which('php')
    check(php is not None, 'PHP is required for private-reply acceptance.')
    if os.environ.get('VELVET_TEST_RUNTIME'):
        result = run_http(Path(os.environ['VELVET_TEST_RUNTIME']), os.environ['VELVET_BASE_URL'].rstrip('/'), php)
        print('Private reply email HTTP: ' + json.dumps(result), flush=True)
        return
    spec = importlib.util.spec_from_file_location('vvx_private_reply_package', ROOT / 'scripts/package.py')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)
    evidence = Path(os.environ['VELVET_SCREENSHOT_DIR']).resolve() if os.environ.get('VELVET_SCREENSHOT_DIR') else None
    if evidence:
        check(not evidence.is_relative_to(ROOT) or evidence.is_relative_to(ROOT / 'artifacts'), 'Evidence must stay outside source/runtime files.')
        evidence.mkdir(parents=True, exist_ok=True)
    summary = {'php': subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True), 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-private-reply-') as temporary:
        work = Path(temporary)
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        fresh = next(item for item in built['packages'] if item['kind'] == 'fresh')
        summary.update({'build_id': built['build_id'], 'fresh_zip_sha256': fresh['sha256']})
        archive = work / 'packages' / fresh['filename']
        for label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
            document_root = work / label / 'www'
            site = document_root / prefix.lstrip('/')
            site.mkdir(parents=True)
            with ZipFile(archive) as zipped:
                check('admin/index.php' in zipped.namelist() and not any(name.startswith(('tests/', 'scripts/')) for name in zipped.namelist()),
                      'Customer package is missing runtime or contains test tooling.')
                zipped.extractall(site)
            (site / '.vvx-test-runtime').write_text('Disposable private-reply SMTP acceptance.\n')
            with socket.socket() as available:
                available.bind(('127.0.0.1', 0))
                port = available.getsockname()[1]
            base = f'http://127.0.0.1:{port}{prefix}'
            subprocess.run([php, str(ROOT / 'tests/Http/media_upload_fixture.php'), str(site), 'setup', base], check=True, capture_output=True, text=True)
            log_path = work / label / 'http.log'
            environment = os.environ.copy()
            environment.pop('PHP_CLI_SERVER_WORKERS', None)
            with log_path.open('w+') as log:
                server = subprocess.Popen([php, '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(document_root)],
                                          cwd=work, env=environment, stdout=log, stderr=log)
                try:
                    ready = session()
                    deadline = time.monotonic() + 15
                    while True:
                        check(server.poll() is None, 'The disposable PHP server stopped.')
                        try:
                            if ready.get(base + '/admin/?page=login', timeout=1).status_code == 200:
                                break
                        except requests.RequestException:
                            pass
                        check(time.monotonic() < deadline, 'The disposable PHP server was not ready.')
                        time.sleep(0.1)
                    ready.close()
                    summary['cases'][label] = run_http(site, base, php)
                    check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', log_path.read_text()), 'Reply acceptance produced PHP diagnostics.')
                    print('Packaged private reply email ' + label + ': ' + json.dumps(summary['cases'][label]), flush=True)
                finally:
                    server.terminate()
                    try:
                        server.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        server.kill()
                        server.wait(timeout=5)
                    if evidence:
                        shutil.copyfile(log_path, evidence / ('private-reply-email-' + label + '-http.log'))
    if evidence:
        (evidence / 'private-reply-email-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print('Actual packaged private reply email privacy passed. External messages sent: 0.', flush=True)


if __name__ == '__main__':
    run()
