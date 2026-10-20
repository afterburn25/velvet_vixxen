#!/usr/bin/env python3
"""Exercise the actual packaged installer endpoint with localhost-only SMTP.

Uses only the Python standard library. Every mailbox is reserved example.test;
the SMTP listener binds to loopback and never forwards mail. No owner data or
credentials are required, and all PHP/site state stays in a temporary package.
"""
from __future__ import annotations

import base64
from contextlib import contextmanager
import http.cookiejar
import json
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
import urllib.error
import urllib.parse
import urllib.request
from zipfile import ZipFile


ROOT = Path(__file__).resolve().parents[2]
CHECKS = 0
PROVIDER_SENTINEL = 'provider-controlled-reply-fixture'


def require(condition: bool, message: str) -> None:
    global CHECKS
    CHECKS += 1
    if not condition:
        raise AssertionError(message)


class Capture(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def __init__(self):
        super().__init__(('127.0.0.1', 0), CaptureHandler)
        self.connections = 0
        self.messages: list[dict] = []
        self.reject_auth = False


class CaptureHandler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        self.connection.settimeout(10)
        self.server.connections += 1
        self.wfile.write(('220 ' + PROVIDER_SENTINEL + '\r\n').encode())
        message = {'from': '', 'to': '', 'body': '', 'username': ''}
        auth_step = 0
        reading_data = False
        while line := self.rfile.readline(65536):
            text = line.decode('utf-8', errors='replace').rstrip('\r\n')
            if reading_data:
                if text == '.':
                    self.server.messages.append(message)
                    self.wfile.write(b'250 Accepted by loopback fixture\r\n')
                    reading_data = False
                else:
                    message['body'] += text + '\n'
                continue
            if auth_step:
                if auth_step == 1:
                    message['username'] = base64.b64decode(text).decode()
                    self.wfile.write(b'334 UGFzc3dvcmQ6\r\n')
                    auth_step = 2
                else:
                    auth_step = 0
                    # The credential is never written to output or fixture logs.
                    response = ('535 ' if self.server.reject_auth else '235 ') + PROVIDER_SENTINEL + '\r\n'
                    self.wfile.write(response.encode())
                continue
            if text.startswith('EHLO '):
                self.wfile.write(b'250 localhost\r\n')
            elif text == 'AUTH LOGIN':
                self.wfile.write(b'334 VXNlcm5hbWU6\r\n')
                auth_step = 1
            elif text.startswith('MAIL FROM:'):
                message['from'] = text.removeprefix('MAIL FROM:')
                self.wfile.write(b'250 Sender accepted\r\n')
            elif text.startswith('RCPT TO:'):
                message['to'] = text.removeprefix('RCPT TO:')
                self.wfile.write(b'250 Recipient accepted\r\n')
            elif text == 'DATA':
                self.wfile.write(b'354 End with dot\r\n')
                reading_data = True
            elif text == 'QUIT':
                self.wfile.write(b'221 Bye\r\n')
                return
            else:
                self.wfile.write(b'500 Unexpected command\r\n')


def port() -> int:
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


@contextmanager
def php_server(site_parent: Path, log: Path):
    selected = port()
    with log.open('wb') as output:
        process = subprocess.Popen(['php', '-S', f'127.0.0.1:{selected}', '-t', str(site_parent)], stdout=output, stderr=subprocess.STDOUT)
        try:
            deadline = time.monotonic() + 8
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    raise RuntimeError('The disposable PHP server did not start.')
                try:
                    with socket.create_connection(('127.0.0.1', selected), timeout=0.2):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                raise RuntimeError('The disposable PHP server was not reachable.')
            yield f'http://127.0.0.1:{selected}'
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)


def request(opener, url: str, data: dict | None = None) -> tuple[int, str]:
    payload = urllib.parse.urlencode(data).encode() if data is not None else None
    headers = {'Accept': 'application/json'} if data is not None else {}
    try:
        response = opener.open(urllib.request.Request(url, data=payload, headers=headers), timeout=20)
    except urllib.error.HTTPError as response_error:
        response = response_error
    with response:
        return response.status, response.read().decode('utf-8')


def exercise(archive: Path, work: Path, nested: bool, capture: Capture) -> None:
    parent = work / ('nested-case' if nested else 'root-case')
    site = parent / 'a/site' if nested else parent
    site.mkdir(parents=True)
    with ZipFile(archive) as package:
        package.extractall(site)
    (site / '.vvx-test-runtime').write_text('Disposable installer mail acceptance.\n')
    log = work / ('nested-http.log' if nested else 'root-http.log')
    with php_server(parent, log) as origin:
        endpoint = origin + ('/a/site' if nested else '') + '/install/index.php'
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        status, page = request(opener, endpoint)
        require(status == 200, 'The actual fresh package installer did not load.')
        match = re.search(r'name="_csrf"\s+value="([^"]+)"', page)
        require(match is not None, 'The actual installer CSRF form was not present.')
        values = {'_csrf': match.group(1), 'action': 'test_mail', 'mail_mode': 'smtp',
                  'smtp_host': '127.0.0.1', 'smtp_port': str(capture.server_address[1]),
                  'smtp_encryption': 'none', 'smtp_username': 'sender+capture@example.test',
                  'smtp_password': 'local-fixture-passphrase', 'mail_from_email': '',
                  'mail_from_name': 'Example website', 'admin_email': 'owner@example.test'}

        for change, expected in [
            ({}, 'sender+capture@example.test'),
            ({'mail_from_email': 'Publisher <explicit+sender@example.test>'}, 'explicit+sender@example.test'),
            ({'smtp_username': 'non-mailbox-login'}, 'owner@example.test'),
            ({'smtp_username': '', 'smtp_password': ''}, 'owner@example.test'),
        ]:
            before = len(capture.messages)
            status, text = request(opener, endpoint, values | change)
            result = json.loads(text)
            require(status == 200 and result['ok'] is True, 'A valid SMTP installer test did not succeed.')
            require(len(capture.messages) == before + 1, 'The SMTP test did not transmit exactly one message.')
            actual = capture.messages[-1]
            require(actual['from'] == f'<{expected}>' and actual['to'] == '<owner@example.test>', 'The real SMTP envelope used the wrong sender or recipient.')
            require(f'<{expected}>' in actual['body'] and 'installer email test' in actual['body'], 'The SMTP test did not send the expected plain test email.')
            require(PROVIDER_SENTINEL not in text and values['smtp_password'] not in text, 'SMTP response data exposed a provider reply or credential.')

        for field in ('mail_from_email', 'admin_email', 'smtp_username', 'smtp_host', 'mail_from_name'):
            for control in ('\r\n', '&#13;', '&#x0a;'):
                before = capture.connections
                status, text = request(opener, endpoint, values | {field: control + (values[field] or 'sender@example.test')})
                require(status == 422 and json.loads(text)['ok'] is False, f'Raw installer input in {field} was normalized into a success.')
                require(capture.connections == before, f'Installer validation of {field} ran after an SMTP connection.')
        for invalid in ('not-an-address', 'mailto:', 'Invalid <>'):
            before = capture.connections
            status, text = request(opener, endpoint, values | {'mail_from_email': invalid})
            require(status == 422 and json.loads(text)['ok'] is False, 'An explicit invalid From triggered a fallback.')
            require(capture.connections == before, 'Invalid explicit sender reached SMTP.')

        before = capture.connections
        status, text = request(opener, endpoint, values | {'_csrf': 'incorrect'})
        require(status == 422 and json.loads(text)['ok'] is False and capture.connections == before, 'SMTP test accepted an invalid CSRF token.')
        status, text = request(opener, endpoint, values | {'mail_mode': 'disabled', 'smtp_host': '\r\n', 'mail_from_email': '&#13;'})
        require(status == 200 and json.loads(text)['mail']['mode'] == 'disabled' and capture.connections == before, 'Disabled mail did not remain optional and inert.')

        capture.reject_auth = True
        try:
            status, text = request(opener, endpoint, values)
            require(status == 422 and 'status 535' in json.loads(text)['message'], 'SMTP rejection did not produce a bounded useful diagnostic.')
            require(PROVIDER_SENTINEL not in text and values['smtp_password'] not in text, 'SMTP failure reflected a provider-controlled secret.')
        finally:
            capture.reject_auth = False

        # Invalid owner/support/sender values in the real install action must be
        # rejected before any database connection, with all state uninstalled.
        install = values | {'action': 'install_now', 'admin_name': 'Owner',
                            'admin_password': 'FixturePassword12', 'admin_password_confirmation': 'FixturePassword12',
                            'confirm_install': '1', 'db_host': '127.0.0.1', 'db_port': '9',
                            'db_name': 'vvx_mail_validation_fixture', 'db_user': 'fixture', 'db_password': '',
                            'base_url': origin + ('/a/site' if nested else ''), 'site_name': 'Example website',
                            'support_email': 'support@example.test', 'currency': 'USD', 'timezone': 'America/Chicago'}
        for field, diagnostic in [('admin_email', 'owner email'), ('support_email', 'support email'), ('mail_from_email', 'From email')]:
            before = capture.connections
            status, text = request(opener, endpoint, install | {field: '\r\nowner@example.test'})
            require(status == 422 and diagnostic in json.loads(text)['message'], 'The real installation request lost strict ' + field + ' validation.')
            require(capture.connections == before and not (site / 'config/app.php').exists()
                    and not (site / 'storage/install.lock').exists(), 'Invalid installation email changed site identity or contacted SMTP.')

        for logfile in (site / 'storage/logs').rglob('*'):
            if logfile.is_file():
                contents = logfile.read_text(errors='replace')
                require(PROVIDER_SENTINEL not in contents and values['smtp_password'] not in contents, 'Installer logs exposed provider-controlled replies or passwords.')
    require(not any(problem in log.read_text(errors='replace') for problem in ('PHP Fatal error', 'PHP Warning', 'Uncaught ')), 'The actual installer emitted a PHP warning or fatal error.')


def main() -> None:
    if shutil.which('php') is None:
        raise RuntimeError('PHP is required for packaged installer mail acceptance.')
    with tempfile.TemporaryDirectory(prefix='vvx-installer-smtp-') as name:
        work = Path(name)
        subprocess.run([sys.executable, str(ROOT / 'scripts/package.py'), '--output', str(work / 'packages')], check=True, stdout=subprocess.DEVNULL)
        archives = list((work / 'packages').glob('*Fresh-Install.zip'))
        require(len(archives) == 1, 'Exactly one fresh runtime package is required.')
        with Capture() as capture:
            worker = threading.Thread(target=capture.serve_forever, daemon=True)
            worker.start()
            try:
                for nested in (False, True):
                    exercise(archives[0], work, nested, capture)
            finally:
                capture.shutdown()
                worker.join(timeout=5)
    print(f'Packaged installer SMTP capture passed ({CHECKS} assertions; root and nested paths; loopback only).')


if __name__ == '__main__':
    main()
