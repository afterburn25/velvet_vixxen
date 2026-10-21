#!/usr/bin/env python3
"""Real desktop/mobile owner scheduling controls in extracted customer packages."""
from __future__ import annotations

import argparse
from copy import deepcopy
import hashlib
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('vvx_scene_schedule_http', ROOT / 'tests/Http/scene_schedules_http.py')
assert spec and spec.loader
schedules = importlib.util.module_from_spec(spec)
spec.loader.exec_module(schedules)
http, check = schedules.http, schedules.check


def owner_login(page, base):
    page.goto(base + '/admin/?page=login', wait_until='networkidle')
    page.get_by_label('Email address').fill(http.OWNER)
    page.get_by_label('Password', exact=True).fill(http.PASSWORD)
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url(base + '/admin/')


def navigate(page, case, section='hero'):
    response = page.goto(schedules.schedule_url(case['base'], section), wait_until='networkidle')
    check(response is not None and response.status == 200, 'The real browser scheduling page failed to open.')
    expect(page.locator('[data-scene-schedules]')).to_have_attribute('data-scene-section', section)
    return response


def fill(page, start='2099-01-02T12:00:01', end='2099-01-03T12:00:02', policy='restore_previous'):
    form = page.locator('[data-scene-schedule-form]')
    # Native datetime-local inputs normalize zero seconds to minutes. Use that
    # equivalent valid representation so Playwright does not reject :00 itself.
    start = start[:-3] if start.endswith(':00') else start
    end = end[:-3] if end.endswith(':00') else end
    form.get_by_label('Starts at', exact=True).fill(start)
    form.get_by_label('Ends at (optional)', exact=True).fill(end)
    form.get_by_label('After the end time', exact=True).select_option(policy)


def submit(page, control, label, status=200):
    with page.expect_navigation(wait_until='networkidle') as event:
        control.click()
    response = event.value
    check(response is not None and response.status == status, label + ' returned an unexpected browser response.')
    if status == 200:
        check(response.request.method == 'GET' and response.request.redirected_from is not None
              and response.request.redirected_from.method == 'POST', label + ' omitted the real POST/Redirect/GET flow.')
        expect(page.locator('.admin-alert.error')).to_have_count(0)
    else:
        check(response.request.method == 'POST' and response.request.redirected_from is None,
              label + ' redirected a rejected stale form.')
        expect(page.locator('.admin-alert.error')).to_be_visible()


def geometry(page, label):
    measured = page.evaluate('''() => ({viewport: innerWidth, content: document.documentElement.scrollWidth,
      controls: [...document.querySelectorAll(`.scene-schedule-page input:not([type=hidden]), .scene-schedule-page select,
        .scene-schedule-page button, .scene-schedule-page a, .scene-schedule-page summary`)]
        .filter(element => element.getClientRects().length).map(element => {
          const rect = element.getBoundingClientRect();
          return {name: element.innerText || element.getAttribute('aria-label') || element.name,
                  left: rect.left, right: rect.right, height: rect.height};
        })})''')
    check(measured['content'] <= measured['viewport'] + 1, label + ' has horizontal page overflow: ' + repr(measured))
    check(all(item['left'] >= -1 and item['right'] <= measured['viewport'] + 1 and item['height'] >= 39
              for item in measured['controls']), label + ' has clipped or undersized controls: ' + repr(measured))
    return measured


def screenshot(page, evidence, label):
    if evidence is None:
        return None
    page.evaluate('window.scrollTo({top: 0, left: 0, behavior: "instant"})')
    filename = 'scene-schedules-' + label + '.png'
    page.screenshot(path=str(evidence / filename), full_page=True)
    check((evidence / filename).stat().st_size > 1000, 'The required scene-schedule screenshot is empty.')
    return filename


def browser_case(browser, case, viewport, evidence):
    initial_checks = http.checks
    schedules.fixture(case, 'seed')
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    errors, actions = [], []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('request', lambda request: actions.extend(parse_qs(request.post_data or '').get('action', []))
            if request.method == 'POST' and request.resource_type == 'document' else None)
    screenshots, layouts = [], []
    other = None
    try:
        owner_login(page, case['base'])
        # This independent owner session supplies the later concurrent draft
        # edit. Authenticate before the protected baseline: logins correctly
        # create their own unrelated admin.login audit entry.
        other = http.owner_login(case['base'])
        navigate(page, case)
        before = schedules.snapshot(case)
        expect(page.locator('[data-scene-schedule-draft-title]')).to_have_text('Scheduled hero snapshot')
        layouts.append(geometry(page, 'Empty schedule editor'))
        screenshots.append(screenshot(page, evidence, case['label'] + '-editor'))
        start_input = page.get_by_label('Starts at', exact=True)
        start_input.focus()
        page.keyboard.press('Tab')
        page.keyboard.press('Shift+Tab')
        check(start_input.evaluate('(element) => document.activeElement === element'), 'The schedule start field is not keyboard reachable.')

        # A real successful create through date, expiry and return-policy controls.
        fill(page)
        submit(page, page.get_by_role('button', name='Schedule saved draft', exact=True), 'Create future schedule')
        saved = schedules.snapshot(case)
        job = schedules.current(saved)
        check(job is not None and job['status'] == 'scheduled'
              and saved['scenes'] == before['scenes'], 'The browser created a schedule by prematurely changing a scene.')
        expect(page.locator('[data-scene-schedule-current]')).to_have_attribute('data-schedule-status', 'scheduled')
        expect(page.locator('[data-schedule-title]')).to_have_text('Scheduled hero snapshot')
        expect(page.get_by_role('button', name='Schedule saved draft', exact=True)).to_be_disabled()
        layouts.append(geometry(page, 'Pending scheduled scene'))
        screenshots.append(screenshot(page, evidence, case['label'] + '-scheduled'))

        # Real calendar controls reveal the schedule, then its editor link returns.
        page.get_by_role('link', name='View publishing calendar', exact=True).click()
        page.wait_for_load_state('networkidle')
        calendar_form = page.locator('[data-calendar-filter-form]')
        calendar_form.get_by_label('Month', exact=True).fill('2099-01')
        calendar_form.get_by_label('Content type', exact=True).select_option('scenes')
        with page.expect_navigation(wait_until='networkidle'):
            calendar_form.get_by_role('button', name='Show calendar', exact=True).click()
        check(page.locator('[data-calendar-edit][href="' + schedules.schedule_url(case['base']) + '"]').count() >= 1,
              'The actual calendar has no link to the scheduled Hero.')
        screenshots.append(screenshot(page, evidence, case['label'] + '-calendar'))
        page.locator('[data-calendar-edit][href="' + schedules.schedule_url(case['base']) + '"]').first.click()
        page.wait_for_url(schedules.schedule_url(case['base']))
        schedules.unchanged(saved, schedules.snapshot(case))
        submit(page, page.get_by_role('button', name='Cancel schedule', exact=True), 'Cancel pending schedule')
        cancelled = schedules.snapshot(case)
        check(schedules.current(cancelled) is None and cancelled['scenes'] == saved['scenes'],
              'The real browser cancellation changed the saved scene.')
        expect(page.locator('[data-schedule-history-id="' + job['id'] + '"]')).to_contain_text('Cancelled')

        # A stale browser form retains its dates and its original guards. An
        # independent ordinary Composer API request supplies the concurrent edit.
        fill(page, start='2099-02-02T12:00:03', end='2099-02-03T12:00:04')
        stale_revision = page.locator('[data-scene-schedule-form] input[name=expectedDraftRevision]').input_value()
        later = deepcopy(cancelled['scenes']['hero']['draft'])
        later['title'] = 'A draft saved in another owner tab'
        schedules.save_scene(other, case, 'hero', later)
        changed = schedules.snapshot(case)
        submit(page, page.get_by_role('button', name='Schedule saved draft', exact=True), 'Reject stale browser schedule', status=409)
        expect(page.get_by_label('Starts at', exact=True)).to_have_value('2099-02-02T12:00:03')
        expect(page.get_by_label('Ends at (optional)', exact=True)).to_have_value('2099-02-03T12:00:04')
        expect(page.locator('[data-scene-schedule-form] input[name=expectedDraftRevision]')).to_have_value(stale_revision)
        schedules.unchanged(changed, schedules.snapshot(case))
        layouts.append(geometry(page, 'Rejected stale schedule'))
        screenshots.append(screenshot(page, evidence, case['label'] + '-stale-form'))
        page.get_by_role('link', name='Reload saved scene', exact=True).click()
        page.wait_for_load_state('networkidle')
        expect(page.locator('[data-scene-schedule-draft-title]')).to_have_text(later['title'])

        # Actual owner Run due scenes action publishes the saved snapshot once.
        fill(page, start=schedules.local_time(time.time() - 60), end='', policy='keep')
        submit(page, page.get_by_role('button', name='Schedule saved draft', exact=True), 'Create due schedule')
        due = schedules.snapshot(case)
        due_job = schedules.current(due)
        submit(page, page.get_by_role('button', name='Run due scenes now', exact=True), 'Run due scene')
        published = schedules.snapshot(case)
        check(schedules.current(published) is None
              and schedules.without_metadata(published['scenes']['hero']['published']) == schedules.without_metadata(due_job['scene'])
              and len(published['scenes']['hero']['versions']) == len(due['scenes']['hero']['versions']) + 1,
              'The real Run due scenes button did not publish exactly the saved snapshot.')
        submit(page, page.get_by_role('button', name='Run due scenes now', exact=True), 'Repeat due check')
        schedules.unchanged(published, schedules.snapshot(case))
        screenshots.append(screenshot(page, evidence, case['label'] + '-completed'))

        # The Composer Schedule action saves both unsaved drafts before selecting
        # the correct footer scheduling page. Real controls supply both edits.
        page.get_by_role('link', name='Open in Composer', exact=False).click()
        page.wait_for_load_state('networkidle')
        expect(page.locator('#visualComposer')).to_be_visible()
        page.locator('[data-section="hero"]').click()
        page.locator('[data-property-tab="background"]').click()
        page.locator('[data-control="sceneTitle"]').fill('Composer Hero retained before scheduling')
        page.locator('[data-control="sceneTitle"]').press('Tab')
        page.locator('[data-section="footer"]').click()
        page.locator('[data-control="sceneTitle"]').fill('Composer Footer selected for scheduling')
        page.locator('[data-control="sceneTitle"]').press('Tab')
        with page.expect_navigation(wait_until='networkidle'):
            page.locator('[data-action="schedule"]').click()
        check(parse_qs(urlparse(page.url).query).get('section') == ['footer'], 'Composer Schedule opened the wrong section.')
        composer_saved = schedules.snapshot(case)
        check(composer_saved['scenes']['hero']['draft']['title'] == 'Composer Hero retained before scheduling'
              and composer_saved['scenes']['footer']['draft']['title'] == 'Composer Footer selected for scheduling'
              and composer_saved['scenes']['hero']['published'] == published['scenes']['hero']['published']
              and composer_saved['scenes']['footer']['published'] == published['scenes']['footer']['published'],
              'Composer Schedule lost an unsaved draft or published either scene.')
        expect(page.locator('[data-scene-schedule-draft-title]')).to_have_text('Composer Footer selected for scheduling')
        layouts.append(geometry(page, 'Footer scheduling from Composer'))
        screenshots.append(screenshot(page, evidence, case['label'] + '-footer'))
        schedules.protected(before, composer_saved)
        check(not errors, 'Scene schedule browser flow raised JavaScript errors: ' + repr(errors))
        check(set(('scene_schedule_create', 'scene_schedule_cancel', 'scene_schedule_run')) <= set(actions),
              'The browser omitted one of the actual schedule owner POST actions.')

        # Scheduling and cancellation remain usable with JavaScript disabled.
        plain_context = browser.new_context(viewport=viewport, java_script_enabled=False)
        try:
            plain = plain_context.new_page()
            owner_login(plain, case['base'])
            navigate(plain, case, 'footer')
            plain_before = schedules.snapshot(case)
            fill(plain)
            submit(plain, plain.get_by_role('button', name='Schedule saved draft', exact=True), 'No-script schedule creation')
            scripted_before = schedules.snapshot(case)
            check(schedules.current(scripted_before, 'footer') is not None, 'No-script form did not persist the footer schedule.')
            submit(plain, plain.get_by_role('button', name='Cancel schedule', exact=True), 'No-script schedule cancellation')
            no_script_after = schedules.snapshot(case)
            check(schedules.current(no_script_after, 'footer') is None
                  and no_script_after['scenes'] == scripted_before['scenes'], 'No-script cancellation changed the live scene.')
            schedules.protected(plain_before, no_script_after)
        finally:
            plain_context.close()
        check(case['capture'].connections == 0 and not case['capture'].payloads, 'Scene schedule controls attempted SMTP.')
        return {'checks': http.checks - initial_checks, 'viewport': viewport, 'mode': case['mode'],
                'real_post_actions': sorted(set(actions)), 'layouts': layouts,
                'calendar_navigation_and_editor_link': True, 'stale_form_values_and_guards_retained': True,
                'run_due_is_idempotent': True, 'composer_saves_both_drafts_before_selected_schedule': True,
                'script_free_create_cancel': True, 'screenshots': [name for name in screenshots if name],
                'javascript_errors': errors, 'smtp_connections': case['capture'].connections,
                'mysql_version': composer_saved['mysql_version']}
    finally:
        if other is not None:
            other.close()
        context.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='both')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'scene_schedules_browser', 'cases': {}, 'passed': False,
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True),
               'playwright': importlib.metadata.version('playwright'),
               'server': 'actual extracted Fresh, php -S without router'}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-scene-schedule-browser-') as temporary, sync_playwright() as playwright:
            work = Path(temporary)
            archive, identity = schedules.prepare_package(work)
            summary.update(identity)
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('VELVET_CHROMIUM') or None,
                                                  args=['--no-sandbox'])
            summary['chromium'] = browser.version
            try:
                for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
                    for path, prefix, viewport in (('root-desktop', '', {'width': 1440, 'height': 1000}),
                                                   ('nested-mobile', '/nested/velvet', {'width': 390, 'height': 844})):
                        name = mode + '-' + path
                        with schedules.runtime(work, archive, mode, 'schedules-browser-' + name, prefix, evidence) as case:
                            case['label'] = name
                            summary['cases'][name] = browser_case(browser, case, viewport, evidence)
                        print('Scene schedules actual-package browser ' + name + ': PASS.', flush=True)
            finally:
                browser.close()
        summary['passed'] = True
        print(json.dumps(summary, indent=2), flush=True)
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'scene-schedules-browser-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
