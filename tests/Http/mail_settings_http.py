"""Owner email configuration against real HTTP and loopback-only SMTP.

The SMTP fixture accepts only reserved test addresses and never relays mail.
Importing this module does not start a service or execute the HTTP suite.
"""
from __future__ import annotations

import base64
import concurrent.futures
import json
import os
from pathlib import Path
import re
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
from urllib.parse import urlparse

from bs4 import BeautifulSoup
import requests

FIXTURE = Path(__file__).with_name('mail_settings_fixture.php')
OWNER = 'owner@example.test'
SMTP_USERNAME = 'smtp-owner@example.test'
FIXTURE_SMTP_PASSWORD = 'Synthetic-mail-password-42!'
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def fixture(root: Path, action: str, **values):
    result = subprocess.run(['php', str(FIXTURE), str(root), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=20, cwd='/tmp')
    check(result.returncode == 0, 'Guarded mail fixture failed: ' + result.stderr[:500])
    return json.loads(result.stdout)


def session():
    current = requests.Session()
    current.trust_env = False
    return current


def form_values(html: str, action: str | None = None):
    soup = BeautifulSoup(html, 'html.parser')
    form = (soup.select_one('form:has(input[name="action"][value="' + action + '"])')
            if action else soup.select_one('form'))
    check(form is not None, 'The actual requested owner form is missing.')
    fields = {}
    for field in form.select('input[name], select[name], textarea[name]'):
        if field.get('type') in ('checkbox', 'radio') and not field.has_attr('checked'):
            continue
        if field.name == 'select':
            option = field.select_one('option[selected]') or field.select_one('option')
            value = option.get('value', '') if option else ''
        elif field.name == 'textarea':
            value = field.text
        else:
            value = field.get('value', '')
        fields[field['name']] = value
    return fields


def login(base: str, owner=None):
    owner = session() if owner is None else owner
    page = owner.get(base + '/admin/?page=login', timeout=10)
    fields = form_values(page.text)
    result = owner.post(base + '/admin/?page=login', data={'_csrf': fields['_csrf'],
                        'email': OWNER, 'password': 'VelvetDemo12'}, timeout=10)
    check(result.status_code == 200 and 'page=login' not in result.url, 'Real owner login failed.')
    return owner


def owner_page(owner, base: str):
    response = owner.get(base + '/admin/?page=mail-settings', timeout=10)
    check(response.status_code == 200 and 'data-mail-editor' in response.text,
          'The actual owner Email & SMTP page did not load.')
    return response


def post(owner, base: str, fields: dict, redirects=True, timeout=20):
    return owner.post(base + '/admin/?page=mail-settings', data=fields,
                      allow_redirects=redirects, timeout=timeout)


def save(owner, base: str, **changes):
    fields = form_values(owner_page(owner, base).text, 'save_mail_settings')
    fields.update(changes)
    response = post(owner, base, fields)
    check(response.status_code == 200 and 'Email settings saved.' in response.text,
          'Owner mail save did not succeed through the real action.')
    check(bool(response.history) and response.history[0].status_code in (302, 303)
          and response.request.method == 'GET', 'Successful mail save did not redirect to GET.')
    return response


class CaptureHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.server.connections += 1
        self.request.settimeout(12)

        def reply(line):
            self.wfile.write((line + '\r\n').encode())
            self.wfile.flush()

        reply('220 Local mail settings capture')
        credentials = []
        authenticating = 0
        recipient = ''
        sender = ''
        try:
            while raw := self.rfile.readline():
                command = raw.decode('utf-8', 'replace').rstrip('\r\n')
                if authenticating:
                    try:
                        credentials.append(base64.b64decode(command, validate=True).decode())
                    except (ValueError, UnicodeError):
                        self.server.errors.append('Invalid fixture AUTH encoding')
                        reply('535 Authentication denied')
                        return
                    if authenticating == 1:
                        authenticating = 2
                        reply('334 UGFzc3dvcmQ6')
                    else:
                        authenticating = 0
                        self.server.authentications.append(tuple(credentials))
                        if tuple(credentials) != (SMTP_USERNAME, FIXTURE_SMTP_PASSWORD) or self.server.mode == 'auth-rejected':
                            reply('535 Private-fixture-server-diagnostic-must-not-be-shown')
                            return
                        reply('235 Authentication accepted')
                elif command.startswith('EHLO '):
                    reply('250-Local fixture')
                    reply('250 AUTH LOGIN')
                elif command == 'AUTH LOGIN':
                    authenticating = 1
                    reply('334 VXNlcm5hbWU6')
                elif command.startswith('MAIL FROM:<') and command.endswith('>'):
                    sender = command[len('MAIL FROM:<'):-1]
                    if not sender.endswith('@example.test'):
                        self.server.errors.append('Non-fixture sender reached capture')
                        reply('550 Fixture senders only')
                        return
                    reply('250 Sender accepted')
                elif command.startswith('RCPT TO:<') and command.endswith('>'):
                    recipient = command[len('RCPT TO:<'):-1]
                    if recipient != OWNER:
                        self.server.errors.append('Test was addressed to someone other than the fixture owner')
                        reply('550 Fixture owner only')
                        return
                    reply('250 Recipient accepted')
                elif command == 'DATA':
                    if self.server.delay:
                        self.server.handshake.set()
                        if not self.server.release.wait(12):
                            self.server.errors.append('Concurrent action did not release the SMTP fixture')
                            return
                    reply('354 Send message')
                    lines = []
                    while raw := self.rfile.readline():
                        if raw == b'.\r\n':
                            break
                        lines.append(raw)
                    else:
                        return  # A revoked owner/settings revision must close before the body.
                    payload = b''.join(lines).decode('utf-8', 'replace')
                    self.server.payloads.append({'sender': sender, 'recipient': recipient, 'body': payload})
                    if self.server.mode == 'unknown':
                        return
                    reply({'rejected': '550 Private-fixture-server-diagnostic-must-not-be-shown',
                           'temporary': '450 Temporary fixture rejection'}.get(self.server.mode, '250 Accepted by local capture'))
                elif command == 'QUIT':
                    reply('221 Goodbye')
                    return
                else:
                    self.server.errors.append('Unexpected SMTP command')
                    reply('500 Unexpected command')
                    return
        except (BrokenPipeError, ConnectionResetError, TimeoutError, OSError):
            return


class Capture(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        super().__init__(('127.0.0.1', 0), CaptureHandler)
        self.payloads = []
        self.authentications = []
        self.errors = []
        self.connections = 0
        self.mode = 'accepted'
        self.delay = False
        self.handshake = threading.Event()
        self.release = threading.Event()


def run():
    root = Path(os.environ['VELVET_TEST_RUNTIME']).resolve()
    base = os.environ['VELVET_BASE_URL'].rstrip('/')
    check(root.name.startswith('vvx-test-') and (root / '.vvx-test-runtime').is_file()
          and root.is_relative_to(Path(tempfile.gettempdir()).resolve()), 'A marked disposable runtime is required.')
    check(urlparse(base).hostname == '127.0.0.1', 'Only loopback HTTP may be tested.')
    fixture(root, 'initialize')
    initial = fixture(root, 'read')
    lock = (root / 'storage/install.lock').read_bytes()
    capture = Capture()
    thread = threading.Thread(target=capture.serve_forever, daemon=True)
    thread.start()
    secondary = None
    accounts = []
    try:
        guest = session()
        accounts.append(guest)
        denied = guest.get(base + '/admin/?page=mail-settings', allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 401, 403), 'Guest read owner mail settings.')
        denied = post(guest, base, {'action': 'send_mail_test'}, redirects=False)
        check(denied.status_code in (302, 303, 401, 403), 'Guest invoked owner mail test.')
        member_cookie = fixture(root, 'member-session')
        member = session()
        accounts.append(member)
        member.cookies.set(member_cookie['name'], member_cookie['id'], domain='127.0.0.1', path='/')
        denied = member.get(base + '/admin/?page=mail-settings', allow_redirects=False, timeout=10)
        check(denied.status_code in (302, 303, 401, 403), 'Member-only session read owner mail settings.')

        owner = login(base)
        accounts.append(owner)
        first = owner_page(owner, base)
        check('Delivery disabled' in first.text and 'Email delivery is disabled' in first.text,
              'Disabled transport has no visible owner status.')
        check('no-store' in first.headers.get('Cache-Control', ''), 'Mail configuration page permits caching.')
        old_fields = form_values(first.text, 'save_mail_settings')
        for method in ('PUT', 'PATCH', 'DELETE'):
            denied = owner.request(method, base + '/admin/?page=mail-settings', timeout=10)
            check(denied.status_code == 405, method + ' reached the mail editor.')
        for action in ('save_mail_settings', 'send_mail_test'):
            fields = form_values(first.text, action)
            fields['_csrf'] = 'wrong-fixture-token'
            denied = post(owner, base, fields)
            check(denied.status_code == 419, action + ' accepted invalid CSRF.')
        check(fixture(root, 'read')['config'] == initial['config'] and capture.connections == 0,
              'Unauthorized/invalid method/CSRF requests changed configuration or connected to SMTP.')

        for role, active in [('editor', True), ('owner', False)]:
            fixture(root, 'owner', role=role, active=active)
            for method in ('GET', 'POST'):
                denied = owner.request(method, base + '/admin/?page=mail-settings', data=old_fields,
                                       allow_redirects=False, timeout=10)
                check(denied.status_code == (403 if active else 302)
                      and (active or denied.headers.get('Location') == base + '/admin/?page=login'),
                      'A stale demoted/inactive session retained mail access.')
            fixture(root, 'owner', role='owner', active=True)
        check(capture.connections == 0, 'A stale owner session invoked the transport.')
        revoked = owner.get(base + '/admin/?page=mail-settings', allow_redirects=False, timeout=10)
        check(revoked.status_code == 302 and revoked.headers.get('Location') == base + '/admin/?page=login',
              'Restoring an inactive account revived its mail-settings session.')
        login(base, owner)

        configured = save(owner, base, mode='smtp', host='127.0.0.1', port=str(capture.server_address[1]),
                          encryption='none', username=SMTP_USERNAME, password=FIXTURE_SMTP_PASSWORD,
                          from_email='', from_name='Fixture <owner> & Mail')
        configured_state = fixture(root, 'read')
        check(capture.connections == 0, 'Saving SMTP settings sent or connected to email.')
        check(configured_state['config']['mail']['from_email'] == SMTP_USERNAME, 'Blank SMTP From did not use the SMTP username.')
        check({k: v for k, v in configured_state['config'].items() if k != 'mail'}
              == {k: v for k, v in initial['config'].items() if k != 'mail'}, 'Mail save replaced unrelated configuration or keys.')
        check(configured_state['config']['mail']['fixture_future_mail_option'] == {'retained': True}, 'Unknown future mail options were discarded.')
        check(FIXTURE_SMTP_PASSWORD not in configured.text and 'vvx1:' not in configured.text
              and form_values(configured.text, 'save_mail_settings')['password'] == '', 'Saved credential or ciphertext appeared in HTML.')
        check(BeautifulSoup(configured.text, 'html.parser').select_one('owner') is None, 'Sender text was rendered as markup.')
        before = (root / 'config/app.php').read_bytes()
        stale = post(owner, base, old_fields)
        check('changed' in stale.text.lower() and (root / 'config/app.php').read_bytes() == before,
              'A stale form overwrote newer SMTP settings.')
        for changes in ({'from_email': 'owner@example.test\r\nBcc: other@example.test'},
                        {'from_email': 'owner&#13;@example.test'}, {'host': 'smtp://127.0.0.1'},
                        {'port': '25.5'}, {'port': '0'}, {'port': '65536'}, {'from_name': 'Line\nBreak'}):
            fields = form_values(owner_page(owner, base).text, 'save_mail_settings')
            fields.update(changes)
            invalid = post(owner, base, fields)
            check('admin-alert error' in invalid.text and (root / 'config/app.php').read_bytes() == before,
                  'Invalid sender/server input changed the saved configuration.')

        # Blank-password retention is verified by real authenticated delivery.
        retained = save(owner, base, password='', from_name='Retained password fixture')
        check(capture.connections == 0 and 'Password saved' in retained.text, 'Blank password did not retain the saved credential.')
        test_fields = form_values(retained.text, 'send_mail_test')
        test_fields['recipient'] = 'not-the-owner@example.test'
        test_fields['subject'] = 'UNTRUSTED TEST SUBJECT'
        test_fields['body'] = 'UNTRUSTED TEST BODY'
        accepted = post(owner, base, test_fields)
        check(accepted.status_code == 200 and 'Test accepted' in accepted.text and len(capture.payloads) == 1,
              'Explicit owner test was not accepted by the real local transport.')
        check(capture.authentications[-1] == (SMTP_USERNAME, FIXTURE_SMTP_PASSWORD), 'Retained credential did not authenticate.')
        payload = capture.payloads[-1]
        check(payload['recipient'] == OWNER and payload['sender'] == SMTP_USERNAME
              and 'Velvet Vixxen email settings test' in payload['body']
              and all(value not in payload['body'] for value in (FIXTURE_SMTP_PASSWORD, 'UNTRUSTED TEST SUBJECT', 'UNTRUSTED TEST BODY')),
              'Test delivery trusted arbitrary recipient/content or leaked credentials.')
        repeated = post(owner, base, test_fields)
        check(repeated.status_code == 409 and len(capture.payloads) == 1, 'One-use test token duplicated a message.')
        cooldown_fields = form_values(owner_page(owner, base).text, 'send_mail_test')
        held = post(owner, base, cooldown_fields)
        check(held.status_code == 429 and len(capture.payloads) == 1, 'New test token bypassed the per-session cooldown.')
        owner_page(owner, base)
        check(len(capture.payloads) == 1, 'Refreshing test results resent a message.')

        for mode, text, added in [('auth-rejected', '535', 0), ('rejected', '550', 1), ('unknown', 'unknown', 1)]:
            capture.mode = mode
            current = login(base)
            accounts.append(current)
            fields = form_values(owner_page(current, base).text, 'send_mail_test')
            count = len(capture.payloads)
            result = post(current, base, fields)
            check(result.status_code == 200 and 'Test needs attention' in result.text and text in result.text.lower()
                  and len(capture.payloads) == count + added, 'Real SMTP ' + mode + ' outcome was misreported.')
            check('Private-fixture-server-diagnostic-must-not-be-shown' not in result.text and FIXTURE_SMTP_PASSWORD not in result.text,
                  'Raw SMTP diagnostics or credentials leaked through the test result.')
            repeated = post(current, base, fields)
            check(repeated.status_code == 409 and len(capture.payloads) == count + added, 'Failed/unknown test automatically repeated.')

        capture.mode = 'accepted'
        stale_test_owner = login(base)
        accounts.append(stale_test_owner)
        stale_test = form_values(owner_page(stale_test_owner, base).text, 'send_mail_test')
        fixture(root, 'unrelated')
        connections = capture.connections
        stale_test_result = post(stale_test_owner, base, stale_test)
        check(stale_test_result.status_code == 409 and capture.connections == connections,
              'A stale test revision connected to SMTP.')
        save(owner, base, from_name='Concurrent settings retained')
        check(fixture(root, 'read')['config']['mail_acceptance_fixture']['unrelated']
              == 'a concurrent owner setting was preserved', 'A mail save erased a concurrent unrelated setting.')

        # A second real worker must save using the very same PHP session while
        # the first waits at SMTP DATA. A held session lock makes this fail.
        with tempfile.TemporaryDirectory(prefix='vvx-mail-http-worker-') as temporary:
            with socket.socket() as available:
                available.bind(('127.0.0.1', 0))
                port = available.getsockname()[1]
            second_base = f'http://127.0.0.1:{port}'
            with (Path(temporary) / 'server.log').open('w+') as log:
                environment = os.environ.copy()
                environment.pop('PHP_CLI_SERVER_WORKERS', None)
                secondary = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root)],
                                             cwd=temporary, env=environment, stdout=log, stderr=log)
                for _ in range(100):
                    try:
                        if guest.get(second_base + '/admin/?page=login', timeout=.5).status_code == 200:
                            break
                    except requests.RequestException:
                        time.sleep(.05)
                else:
                    raise AssertionError('Second physical PHP worker did not start.')
                for revoke in ('disable', 'demote'):
                    save(owner, base, mode='smtp', username=SMTP_USERNAME, password='', from_email=SMTP_USERNAME)
                    testing = login(base)
                    accounts.append(testing)
                    fields = form_values(owner_page(testing, base).text, 'send_mail_test')
                    parallel = session()
                    accounts.append(parallel)
                    parallel.cookies.update(testing.cookies)
                    parallel_fields = form_values(owner_page(parallel, second_base).text, 'save_mail_settings')
                    capture.delay = True
                    capture.handshake.clear()
                    capture.release.clear()
                    count = len(capture.payloads)
                    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
                        future = executor.submit(post, testing, base, fields, False)
                        try:
                            check(capture.handshake.wait(7), 'Test never reached the real SMTP DATA boundary.')
                            if revoke == 'disable':
                                parallel_fields['mode'] = 'disabled'
                                started = time.monotonic()
                                changed = post(parallel, second_base, parallel_fields, redirects=False, timeout=5)
                                check(changed.status_code in (302, 303) and time.monotonic() - started < 4,
                                      'SMTP wait locked the owner session and prevented disabling mail.')
                            else:
                                fixture(root, 'owner', role='editor', active=True)
                        finally:
                            capture.release.set()
                        completed = future.result(timeout=10)
                    capture.delay = False
                    fixture(root, 'owner', role='owner', active=True)
                    check(completed.status_code in (302, 303) and len(capture.payloads) == count,
                          'Owner/settings revocation during SMTP handshake still transmitted a body.')
                    result_page = owner_page(testing, base)
                    if revoke == 'disable':
                        check('data-mail-test-result' not in result_page.text,
                              'A test result from superseded settings was displayed after disable.')
                    else:
                        check('canceled before message transmission' in result_page.text,
                              'Canceled owner test was not reported accurately.')
                secondary.terminate()
                secondary.wait(timeout=10)
                secondary = None
                log.flush()
                check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ', (Path(temporary) / 'server.log').read_text()),
                      'Concurrent mail handlers produced PHP diagnostics.')

        cleared = save(owner, base, mode='smtp', username='', password='', clear_password='1', from_email='')
        check('No SMTP password is saved.' in cleared.text, 'Explicit credential removal did not persist.')
        clear_test_owner = login(base)
        accounts.append(clear_test_owner)
        count = len(capture.payloads)
        auth_count = len(capture.authentications)
        cleared_result = post(clear_test_owner, base, form_values(owner_page(clear_test_owner, base).text, 'send_mail_test'))
        check('Test accepted' in cleared_result.text and len(capture.payloads) == count + 1
              and len(capture.authentications) == auth_count and capture.payloads[-1]['sender'] == OWNER,
              'Explicitly cleared credentials still authenticated or blank From lost its owner fallback.')
        disabled = save(owner, base, mode='disabled', from_email='', password='')
        count = capture.connections
        forced = post(owner, base, form_values(disabled.text, 'send_mail_test'))
        check('Email delivery is disabled' in forced.text and capture.connections == count,
              'Forging the disabled test form invoked SMTP.')
        final = fixture(root, 'read')
        check(final['owner'] == initial['owner'] and (root / 'storage/install.lock').read_bytes() == lock,
              'Mail administration changed the owner record or installation lock.')
        check(final['mailOutbox'] == initial['mailOutbox'], 'Transport-only tests changed the transactional member outbox.')
        audit = json.dumps(final['audit'])
        check(FIXTURE_SMTP_PASSWORD not in audit and 'vvx1:' not in audit
              and 'Private-fixture-server-diagnostic-must-not-be-shown' not in audit, 'Mail activity logging leaked secrets or SMTP details.')
        check(not capture.errors, 'Local SMTP fixture recorded an unexpected protocol/recipient: ' + repr(capture.errors))
        print(f'Mail settings HTTP: {checks} assertions passed; real owner auth/save/revision/credentials, SMTP outcomes, '
              'one-use tests/cooldown and concurrent disable/demotion. External messages sent: 0.', flush=True)
    finally:
        capture.release.set()
        capture.shutdown()
        capture.server_close()
        if secondary is not None:
            secondary.terminate()
            try:
                secondary.wait(timeout=10)
            except subprocess.TimeoutExpired:
                secondary.kill()
                secondary.wait(timeout=5)
        for account in accounts:
            account.close()


if __name__ == '__main__':
    run()
