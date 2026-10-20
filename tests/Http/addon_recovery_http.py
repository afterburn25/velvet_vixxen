#!/usr/bin/env python3
"""Real owner/CSRF recovery acceptance; called by the exclusively owned MySQL gate.

Uses the actual extracted customer runtime and only Python's standard library.
"""
from __future__ import annotations

import hashlib
import http.cookiejar
import json
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Inputs(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.csrf = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        if tag == 'input' and fields.get('name') == '_csrf':
            self.csrf = fields.get('value')


def main():
    runtime = Path(sys.argv[1]).resolve()
    if not runtime.is_relative_to(Path(tempfile.gettempdir()).resolve()) or not (runtime / '.vvx-addon-acceptance').is_file():
        raise RuntimeError('A marked temporary add-on acceptance runtime is required.')
    fixture = Path(__file__).with_name('addon_recovery_fixture.php')
    checks = 0

    def expect(condition, message):
        nonlocal checks
        if not condition:
            raise AssertionError(message)
        checks += 1

    def command(action, **data):
        result = subprocess.run(['php', str(fixture), str(runtime), action], input=json.dumps(data),
                                text=True, capture_output=True, check=True)
        return json.loads(result.stdout)

    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    command('origin', port=port)
    origin = f'http://127.0.0.1:{port}'
    endpoint = '/admin/addon-recovery.php'
    fields = {'addon_id': 'fixture.mysql-lifecycle'}
    cookies = http.cookiejar.CookieJar()
    owner = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(cookies), NoRedirect())
    anonymous = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def request(client, path, data=None, method=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(origin + path, data=body, method=method)
        try:
            response = client.open(req, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode('utf-8')

    with tempfile.TemporaryFile(mode='w+') as log:
        server = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(runtime)],
                                  stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 10
            while True:
                try:
                    code, _, html = request(owner, '/admin/?page=login')
                    break
                except (urllib.error.URLError, ConnectionError):
                    if time.monotonic() >= deadline:
                        raise RuntimeError('The disposable PHP server did not start.')
                    time.sleep(0.05)
            expect(code == 200, 'Real admin login did not render.')
            csrf = Inputs(html).csrf
            expect(bool(csrf), 'Real sign-in CSRF input is missing.')
            snapshot = command('snapshot')
            expect(snapshot['sql'] and snapshot['lifecycle'], 'Both recovery markers must exist before HTTP acceptance.')
            code, headers, _ = request(anonymous, endpoint, fields)
            expect(code == 302 and 'page=login' in headers.get('Location', ''), 'Anonymous recovery was not rejected.')
            expect(command('snapshot') == snapshot, 'Anonymous request changed recovery state.')
            code, headers, _ = request(owner, '/admin/?page=login', {
                '_csrf': csrf, 'email': 'addon-owner@example.test', 'password': 'AddonFixtureOwner123!',
            })
            expect(code == 302 and '/admin/' in headers.get('Location', ''), 'Real fixture owner sign-in failed.')
            code, _, html = request(owner, '/admin/?page=addons')
            expect(code == 200 and 'Retry recorded recovery' in html and endpoint in html, 'The owner recovery form did not render.')
            expect('cannot restore deleted data' in html, 'Schema/data rollback limitation was omitted from the form.')
            expect('vvx_addon_recovery_parent' not in html and '/storage/backups/addons/' not in html, 'Private recovery SQL or paths leaked into the page.')
            csrf = Inputs(html).csrf
            expect(bool(csrf), 'Owner recovery form has no actual CSRF token.')
            for method in ('GET', 'PUT'):
                code, headers, _ = request(owner, endpoint, method=method)
                expect(code == 405 and headers.get('Allow') == 'POST', f'{method} was allowed to recover.')
                expect(command('snapshot') == snapshot, f'{method} changed recovery state.')
            for invalid in (fields, fields | {'_csrf': 'invalid-fixture-token'}):
                code, _, _ = request(owner, endpoint, invalid)
                expect(code == 419, 'Invalid CSRF was accepted.')
                expect(command('snapshot') == snapshot, 'Invalid CSRF changed recovery state.')
            for role, active in (('editor', True), ('owner', False)):
                command('owner', role=role, active=active)
                code, _, _ = request(owner, endpoint, fields | {'_csrf': csrf})
                expect(code == 403, 'A stale owner session bypassed the current account boundary.')
                expect(command('snapshot') == snapshot, 'An unauthorized current account changed recovery state.')
            command('owner', role='owner', active=True)
            code, _, _ = request(owner, endpoint, {'addon_id[]': 'fixture.mysql-lifecycle', '_csrf': csrf})
            expect(code == 400, 'Array-shaped add-on identity was accepted.')
            expect(command('snapshot') == snapshot, 'Malformed identity changed recovery state.')

            # The same known failed statement may fail again; its earlier strict
            # DROP must remain recorded as complete rather than being replayed.
            code, headers, _ = request(owner, endpoint, fields | {'_csrf': csrf})
            expect(code == 302 and 'page=addons' in headers.get('Location', ''), 'Owner retry did not return to the Add-on Manager.')
            code, _, html = request(owner, '/admin/?page=addons')
            expect(code == 200 and 'Recovery could not complete.' in html, 'A still-blocked retry did not show a safe owner error.')
            expect('SQLSTATE' not in html and 'vvx_addon_recovery_parent' not in html, 'Raw SQL failure leaked into the owner response.')
            progress = json.loads((runtime / 'storage/backups/addons/migrations' / (hashlib.sha256(fields['addon_id'].encode()).hexdigest() + '.json')).read_text())
            expect(progress['steps'][0]['next'] == 1 and progress['pendingStatement'] is False, 'Retry forgot the known failed down boundary.')
            after_failed = command('snapshot')
            expect(after_failed['ledger'] == snapshot['ledger'] and after_failed['rows'] == snapshot['rows'], 'Blocked recovery changed retained history or rows.')
            command('unblock-known-down')
            code, headers, _ = request(owner, endpoint, fields | {'_csrf': csrf})
            expect(code == 302 and 'page=addons' in headers.get('Location', ''), 'Allowed owner retry did not complete its redirect.')
            code, _, html = request(owner, '/admin/?page=addons')
            expect(code == 200 and 'Recovery completed.' in html and 'Retry recorded recovery' not in html, 'Successful recovery did not clear the pending owner form.')
            recovered = command('snapshot')
            expect(recovered['sql'] is None and recovered['lifecycle'] is None, 'Owner recovery left an unresolved marker.')
            expect(recovered['addon']['version'] == '1.0.0' and recovered['addon']['status'] == 'active', 'Owner recovery did not restore the previous active package.')
            expect(recovered['rows'] == snapshot['rows'] and len(recovered['ledger']) == 1, 'Owner recovery changed retained rows or left newer ledger entries.')
        finally:
            command('owner', role='owner', active=True)
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
    print(f'PASS add-on recovery HTTP: {checks} assertions; real owner, stale role, inactive account, method, CSRF and exact down retry')


if __name__ == '__main__':
    main()
