#!/usr/bin/env python3
"""Actual packaged owner recovery on desktop or mobile, with an owned MySQL fixture.

The database gate supplies an extracted Fresh ZIP with a deliberately blocked
compensation. No router, replacement HTML or mocked database response is used.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import expect, sync_playwright

sys.dont_write_bytecode = True

ADDON_ID = 'fixture.mysql-lifecycle'
OWNER_EMAIL = 'addon-owner@example.test'
VIEWPORTS = {'desktop': {'width': 1440, 'height': 1000},
             'mobile': {'width': 390, 'height': 844}}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('runtime', type=Path)
    parser.add_argument('viewport', choices=VIEWPORTS)
    args = parser.parse_args()
    runtime = args.runtime.resolve()
    temporary_root = Path(tempfile.gettempdir()).resolve()
    if not runtime.is_relative_to(temporary_root) or not all(
        (runtime / marker).is_file()
        for marker in ('.vvx-addon-acceptance', '.vvx-installer-db-fixture')
    ):
        raise RuntimeError('An explicitly marked temporary packaged runtime is required.')
    fixture = Path(__file__).resolve().parents[1] / 'Http/addon_recovery_fixture.php'
    if not fixture.is_file():
        raise RuntimeError('The guarded add-on fixture helper is missing.')
    configured_evidence = os.environ.get('VELVET_SCREENSHOT_DIR', '').strip()
    evidence = Path(configured_evidence).resolve() if configured_evidence else Path(
        tempfile.mkdtemp(prefix='vvx-addon-browser-evidence-')
    )
    evidence.mkdir(parents=True, exist_ok=True)
    checks = 0

    def require(condition: bool, message: str) -> None:
        nonlocal checks
        if not condition:
            raise AssertionError(message)
        checks += 1

    def command(action: str, **values):
        result = subprocess.run(
            ['php', str(fixture), str(runtime), action],
            input=json.dumps(values), text=True, capture_output=True, check=True,
        )
        return json.loads(result.stdout)

    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    command('origin', port=port)
    origin = f'http://127.0.0.1:{port}'
    initial = command('snapshot')
    require(bool(initial['sql']) and bool(initial['lifecycle']),
            'The browser must begin with real SQL and lifecycle recovery markers.')
    require(initial['addon']['version'] == '1.0.0'
            and initial['addon']['status'] == 'quarantined',
            'The held fixture must preserve and quarantine the original package.')
    sql_record = runtime / 'storage/backups/addons/migrations' / (
        hashlib.sha256(ADDON_ID.encode()).hexdigest() + '.json'
    )

    def read_progress() -> dict:
        return json.loads(sql_record.read_text())

    progress = read_progress()
    require(progress['operation'] == 'compensate'
            and progress['steps'][0]['migration']['id'] == '003_held'
            and progress['steps'][0]['next'] == 1
            and progress['pendingStatement'] is False,
            'The negative control must contain a completed strict DROP and a known failed next statement.')
    version_result = subprocess.run(
        ['php', '-r',
         "require $argv[1]; echo json_encode(['php'=>PHP_VERSION,'version'=>VELVET_VIXXEN_VERSION,'build_id'=>VELVET_VIXXEN_BUILD_ID]);",
         str(runtime / 'includes/version.php')],
        text=True, capture_output=True, check=True,
    )
    summary = json.loads(version_result.stdout)
    summary.update({'viewport': args.viewport, 'dimensions': VIEWPORTS[args.viewport],
                    'playwright': importlib.metadata.version('playwright'),
                    'fixture_only': True, 'screenshots': []})
    failures: list[str] = []

    def screenshot(page, state: str) -> None:
        path = evidence / f'addon-recovery-{args.viewport}-{state}.png'
        position = page.evaluate('({x: scrollX, y: scrollY})')
        try:
            page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
            page.screenshot(path=str(path), full_page=True)
        finally:
            page.evaluate('position => window.scrollTo({top: position.y, left: position.x, behavior: "instant"})', position)
        summary['screenshots'].append(path.name)

    def safe_page(page) -> None:
        html = page.content()
        require('SQLSTATE' not in html and 'vvx_addon_recovery_parent' not in html
                and '/storage/backups/addons/' not in html,
                'The owner page exposed raw recovery SQL or a private recovery path.')
        geometry = page.evaluate(
            '({content: document.documentElement.scrollWidth, viewport: innerWidth})'
        )
        require(geometry['content'] <= geometry['viewport'] + 1,
                'The actual owner page overflows horizontally: ' + repr(geometry))

    def ready_control(page) -> dict:
        section = page.locator('section[aria-labelledby="addon-recovery-heading"]')
        expect(section).to_be_visible()
        expect(section).to_contain_text('stay disabled')
        expect(section).to_contain_text('cannot restore deleted data')
        button = section.get_by_role('button', name='Retry recorded recovery', exact=True)
        expect(button).to_be_visible()
        expect(button).to_be_enabled()
        button.scroll_into_view_if_needed()
        box = button.bounding_box()
        require(box is not None, 'The recovery action has no visible bounds.')
        viewport = VIEWPORTS[args.viewport]
        require(box['width'] >= 120 and 36 <= box['height'] <= 110,
                'The recovery action is too small or has a broken wrapped layout: ' + repr(box))
        require(box['x'] >= -1 and box['x'] + box['width'] <= viewport['width'] + 1
                and box['y'] >= -1 and box['y'] + box['height'] <= viewport['height'] + 1,
                'The recovery action is clipped outside the usable viewport.')
        text = button.evaluate("""element => {
            const range = document.createRange();
            range.selectNodeContents(element);
            const bounds = range.getBoundingClientRect();
            const button = element.getBoundingClientRect();
            return {left: bounds.left, right: bounds.right,
                    buttonLeft: button.left, buttonRight: button.right};
        }""")
        require(text['left'] >= text['buttonLeft'] - 1 and text['right'] <= text['buttonRight'] + 1,
                'The recovery button label is clipped inside its control.')
        # Exercise a real sequential keyboard round trip, followed by Enter.
        button.focus()
        page.keyboard.press('Shift+Tab')
        page.keyboard.press('Tab')
        expect(button).to_be_focused()
        require(button.evaluate("element => element.matches(':focus-visible')"),
                'Keyboard navigation did not expose the recovery button focus.')
        safe_page(page)
        return box

    def retry(page, keyboard: bool) -> None:
        button = page.get_by_role('button', name='Retry recorded recovery', exact=True)
        with page.expect_response(
            lambda response: urlparse(response.url).path == '/admin/addon-recovery.php'
            and response.request.method == 'POST'
        ) as submitted:
            with page.expect_navigation(wait_until='networkidle') as navigation:
                if keyboard:
                    button.press('Enter')
                else:
                    button.click()
        require(submitted.value.status == 302,
                'The visible owner action did not perform the real recovery POST.')
        require(navigation.value is not None and navigation.value.status == 200
                and parse_qs(urlparse(page.url).query) == {'page': ['addons']},
                'Recovery did not return to the actual Add-on Manager.')

    with tempfile.TemporaryFile(mode='w+') as server_log:
        server = subprocess.Popen(
            ['php', '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(runtime)],
            stdout=server_log, stderr=server_log,
        )
        try:
            deadline = time.monotonic() + 15
            while True:
                if server.poll() is not None:
                    raise RuntimeError('The isolated PHP server stopped before browser acceptance.')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.2):
                        break
                except OSError:
                    if time.monotonic() >= deadline:
                        raise RuntimeError('The isolated PHP server did not start.')
                    time.sleep(0.05)
            with sync_playwright() as playwright:
                options = {'headless': True, 'args': ['--no-sandbox']}
                executable = os.environ.get('VELVET_CHROMIUM', '').strip()
                if executable:
                    options['executable_path'] = executable
                browser = playwright.chromium.launch(**options)
                summary['browser'] = browser.version
                context = browser.new_context(viewport=VIEWPORTS[args.viewport],
                                              is_mobile=args.viewport == 'mobile',
                                              has_touch=args.viewport == 'mobile')
                page = context.new_page()
                page.on('pageerror', lambda error: failures.append(str(error)))
                page.on('console', lambda message: failures.append('console: ' + message.text)
                        if message.type == 'error' else None)
                page.on('response', lambda response: failures.append(
                    f'HTTP {response.status}: {urlparse(response.url).path}'
                ) if response.status >= 400 else None)
                try:
                    response = page.goto(origin + '/admin/?page=login', wait_until='networkidle')
                    require(response is not None and response.status == 200,
                            'The real owner sign-in page did not render.')
                    page.get_by_label('Email address').fill(OWNER_EMAIL)
                    page.get_by_label('Password', exact=True).fill('AddonFixtureOwner123!')
                    page.get_by_role('button', name='Sign in', exact=True).click()
                    page.wait_for_url('**/admin/')
                    if args.viewport == 'mobile':
                        page.get_by_role('button', name='Toggle menu', exact=True).click()
                    group = page.locator('[data-admin-group="extensions"]')
                    if group.get_attribute('open') is None:
                        group.locator('summary').click()
                    group.get_by_role('link', name='Add-ons', exact=True).click()
                    page.wait_for_url('**/admin/?page=addons')
                    expect(page.get_by_role('heading', name='Add-on recovery', exact=True)).to_be_visible()
                    require(command('snapshot') == initial,
                            'Opening the owner recovery page changed the held database or markers.')
                    summary['initial_button'] = ready_control(page)
                    screenshot(page, 'held')

                    retry(page, keyboard=True)
                    expect(page.locator('.admin-alert.error')).to_contain_text('Recovery could not complete.')
                    expect(page.get_by_role('button', name='Retry recorded recovery', exact=True)).to_be_visible()
                    progress = read_progress()
                    require(progress['steps'][0]['next'] == 1 and progress['pendingStatement'] is False,
                            'The blocked browser retry repeated the completed strict DROP or lost its boundary.')
                    blocked = command('snapshot')
                    require(blocked['ledger'] == initial['ledger'] and blocked['rows'] == initial['rows']
                            and blocked['addon'] == initial['addon'],
                            'The blocked retry changed the previous package or retained records.')
                    require(bool(blocked['sql']) and bool(blocked['lifecycle']),
                            'A failed retry erased either required recovery marker.')
                    summary['blocked_button'] = ready_control(page)
                    screenshot(page, 'blocked')

                    # The helper can remove only this owned fixture's FK blocker
                    # after verifying the exact known compensation cursor.
                    command('unblock-known-down')
                    retry(page, keyboard=False)
                    expect(page.locator('.admin-alert.success')).to_contain_text('Recovery completed.')
                    expect(page.get_by_role('heading', name='Add-on recovery', exact=True)).to_have_count(0)
                    expect(page.get_by_role('button', name='Retry recorded recovery', exact=True)).to_have_count(0)
                    card = page.locator('.addon-record')
                    expect(card).to_have_count(1)
                    expect(card.locator('.addon-record-top .addon-status-badge')).to_have_text('active')
                    expect(card.locator(':scope > small')).to_contain_text('v1.0.0')
                    recovered = command('snapshot')
                    expected_ledger = [row for row in initial['ledger']
                                       if row['migration_key'] == '001_records']
                    require(len(expected_ledger) == 1 and recovered['ledger'] == expected_ledger
                            and recovered['rows'] == initial['rows'],
                            'Successful owner recovery changed the original ledger or saved rows.')
                    require(recovered['addon']['version'] == '1.0.0'
                            and recovered['addon']['status'] == 'active'
                            and recovered['addon']['package_sha256'] == initial['addon']['package_sha256'],
                            'Recovery did not retain and reactivate the previous package identity.')
                    require(recovered['sql'] is None and recovered['lifecycle'] is None,
                            'Successful owner recovery left a pending marker.')
                    safe_page(page)
                    screenshot(page, 'recovered')
                    page.reload(wait_until='networkidle')
                    expect(page.get_by_role('button', name='Retry recorded recovery', exact=True)).to_have_count(0)
                    require(command('snapshot') == recovered,
                            'Reloading the successful result repeated recovery or changed stored state.')
                    require(not failures, 'The real page produced browser errors: ' + repr(failures))
                    summary['checks'] = checks
                    summary['status'] = 'passed'
                except BaseException:
                    screenshot(page, 'failure')
                    raise
                finally:
                    context.close()
                    browser.close()
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
    (evidence / f'addon-recovery-{args.viewport}.json').write_text(
        json.dumps(summary, indent=2) + '\n'
    )
    print(f'PASS add-on recovery browser {args.viewport}: {checks} checks; '
          f'actual packaged owner UI, known failed down retry, retained MySQL rows, '
          f'keyboard focus and usable controls; Chromium {summary["browser"]}; evidence {evidence}')


if __name__ == '__main__':
    main()
