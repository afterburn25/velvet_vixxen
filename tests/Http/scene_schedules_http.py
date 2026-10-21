#!/usr/bin/env python3
"""Real packaged scene scheduling: owner forms, CLI and independent committed readback.

The runtime uses its real clock. A genuine durable claim is deliberately left
unapplied by an exiting private fixture; only its saved lease is aged for the
recovery case. No public router, response, scene writer or scheduler is mocked.
"""
from __future__ import annotations

import argparse
import concurrent.futures
from copy import deepcopy
from datetime import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlencode
from zipfile import ZipFile
from zoneinfo import ZoneInfo

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


depth = load('vvx_schedule_depth_http', ROOT / 'tests/Http/composer_depth_http.py')
http = depth._base
check, runtime, route = http.check, http.runtime, http.route
OWNER, PASSWORD = http.OWNER, http.PASSWORD
FIXTURE = Path(__file__).with_name('scene_schedules_fixture.php')
RUNTIME_FILES = ('app/Core/SceneSchedule.php', 'app/Core/SceneScheduleStoreTrait.php', 'app/Core/Store.php',
                 'app/Console/run_scenes.php', 'app/Support/scene_schedule_admin.php',
                 'app/Views/admin/scene-schedules.php', 'app/Views/admin/scene-schedules-actions.php',
                 'app/Views/admin/composer.php', 'app/Views/admin/navigation.php', 'admin/index.php',
                 'app/Core/MarketingCalendar.php', 'app/Views/admin/marketing-calendar.php', 'public/assets/js/composer.js',
                 'public/assets/css/scene-schedules.css')


def prepare_package(work):
    archive, identity = depth.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(name in zipped.namelist() and zipped.read(name) == (ROOT / name).read_bytes()
                  for name in RUNTIME_FILES), 'The actual Fresh does not match every scene scheduling runtime source.')
    return archive, identity


def fixture(case, action='read', **values):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action], input=json.dumps(values),
                            text=True, capture_output=True, timeout=40, cwd='/tmp')
    check(result.returncode == 0, 'Guarded scene schedule fixture failed (' + action + '): ' + result.stderr[:1800])
    return json.loads(result.stdout)


def snapshot(case):
    return fixture(case)


def protected(before, after):
    check(before['protected'] == after['protected'] and before['unrelated_sha256'] == after['unrelated_sha256']
          and before['outbox_count'] == after['outbox_count'],
          'Scene scheduling changed configuration, uploads, backups, marketing, unrelated records or the mail outbox.')


def unchanged(before, after):
    protected(before, after)
    check(all(before[key] == after[key] for key in ('scenes', 'records', 'schedules', 'audit')),
          'A rejected/read-only/no-op request changed committed scenes, history, schedules or audit.')


def without_metadata(scene):
    return depth.without_metadata(scene)


def schedule_record(saved, section='hero'):
    return saved['schedules'].get(section, {'schema': 1, 'revision': 0, 'schedule': None, 'history': []})


def current(saved, section='hero'):
    return schedule_record(saved, section).get('schedule')


def terminal(saved, identity, section='hero'):
    matches = [item for item in schedule_record(saved, section).get('history', []) if item.get('id') == identity]
    check(len(matches) == 1, 'The completed/cancelled scene schedule is missing or duplicated in retained history.')
    return matches[0]


def local_time(epoch):
    return datetime.fromtimestamp(epoch, ZoneInfo('America/Chicago')).strftime('%Y-%m-%dT%H:%M:%S')


def schedule_url(base, section='hero'):
    return base + '/admin/?' + urlencode({'page': 'scene-schedules', 'section': section})


def page(owner, case, section='hero'):
    response = owner.get(schedule_url(case['base'], section), timeout=20)
    check(response.status_code == 200, 'The actual owner scene schedule page failed to open.')
    cache = response.headers.get('Cache-Control', '').lower()
    check('private' in cache and 'no-store' in cache, 'Saved schedule metadata can be publicly cached.')
    soup = BeautifulSoup(response.text, 'html.parser')
    check(soup.select_one('[data-scene-schedules][data-scene-section="' + section + '"]') is not None,
          'The owner schedule page lost its selected scene section.')
    check('America/Chicago' in response.text, 'The owner form hides its configured timezone.')
    return response


def form(owner, case, section='hero', selector='form[data-scene-schedule-form]'):
    return http.fields(page(owner, case, section).text, selector)


def accepted(response, label):
    check(response.status_code == 200 and response.history and response.request.method == 'GET'
          and not BeautifulSoup(response.text, 'html.parser').select('.admin-alert.error'),
          label + ' did not commit through the real owner POST/Redirect/GET form: ' + response.text[-250:])


def rejected(response, label):
    check(response.status_code in (400, 403, 409, 419, 422),
          label + ' was not explicitly refused.')
    check(not response.history, label + ' unexpectedly redirected as a successful mutation.')


def submit(owner, case, values, section='hero', success=True, label='Schedule save'):
    response = owner.post(schedule_url(case['base'], section), data=values, timeout=25)
    accepted(response, label) if success else rejected(response, label)
    return response


def create(owner, case, section='hero', start=None, end='', policy='keep'):
    values = form(owner, case, section)
    submitted = http.changed(values, startsAt=start or local_time(time.time() - 60), endsAt=end, returnPolicy=policy)
    submit(owner, case, submitted, section)
    job = current(snapshot(case), section)
    check(isinstance(job, dict) and job['status'] == 'scheduled', 'The real owner form did not persist a pending frozen schedule.')
    return job, submitted


def cancel(owner, case, section='hero'):
    values = form(owner, case, section, 'form[data-scene-schedule-cancel]')
    submit(owner, case, values, section, label='Schedule cancellation')
    return snapshot(case)


def cli(case, limit=10):
    result = subprocess.run([case['php'], str(case['site'] / 'app/Console/run_scenes.php'), '--limit=' + str(limit)],
                            capture_output=True, text=True, timeout=35, cwd='/tmp')
    check(result.returncode in (0, 2) and not result.stderr,
          'The real installed scene worker failed: ' + result.stderr[:1800])
    stats = json.loads(result.stdout)
    check(all(isinstance(stats.get(key), int) and stats[key] >= 0
              for key in ('claimed', 'activated', 'expired', 'skipped', 'held', 'recovered', 'unchanged')),
          'The bounded real CLI did not return its expected numeric outcome summary.')
    check(result.returncode == (2 if stats['held'] else 0), 'The scene worker exit status lost its explicit held outcome.')
    check(stats['claimed'] <= limit, 'The scene worker exceeded its explicit claim limit.')
    return stats


def public_scene(case, section='hero'):
    client = http.session()
    try:
        response = client.get(case['base'] + '/', timeout=20)
        check(response.status_code == 200, 'The actual homepage failed after a scheduling operation.')
        scripts = BeautifulSoup(response.text, 'html.parser').find_all('script')
        raw = next((script.get_text() for script in scripts if script.get_text().startswith('window.VELVET_CONFIG=')), '')
        check(raw.startswith('window.VELVET_CONFIG=') and raw.endswith(';'), 'The actual homepage lost its saved scene configuration.')
        return json.loads(raw[len('window.VELVET_CONFIG='):-1])['scenes'][section]
    finally:
        client.close()


def composer_token(owner, case):
    response = owner.get(case['base'] + '/admin/?page=composer', timeout=20)
    meta = BeautifulSoup(response.text, 'html.parser').select_one('meta[name="csrf-token"]')
    check(response.status_code == 200 and meta is not None, 'The real Composer did not provide an authenticated CSRF token.')
    return meta['content']


def save_scene(owner, case, section, scene, publish=False):
    return depth.post_scene(owner, case['base'], section, composer_token(owner, case),
                            'publish' if publish else 'save', scene)


def wait_until(epoch):
    # Observe an actual wall-clock expiration. This interval is intentionally
    # bounded below 15 seconds and never replaces the application's clock.
    remaining = epoch - time.time()
    check(remaining < 15, 'The real-time acceptance requested an unbounded wait.')
    while time.time() < epoch:
        time.sleep(min(.1, max(.001, epoch - time.time())))


def http_case(case, seeded=False):
    initial_checks = http.checks
    if not seeded:
        fixture(case, 'seed')
    owner, member, guest = http.owner_login(case['base']), http.member_login(case['base'], 'free'), http.session()
    try:
        before = snapshot(case)
        for section in ('hero', 'footer'):
            response = page(owner, case, section)
            check(BeautifulSoup(response.text, 'html.parser').select_one('a[href="' + case['base'] + '/admin/?page=composer&section=' + section + '"]') is not None,
                  'Scene scheduling lost its real Composer link or nested installation path.')
        for client in (member, guest):
            response = client.get(schedule_url(case['base']), timeout=20, allow_redirects=False)
            check(response.status_code in (302, 303, 401, 403)
                  and 'Scheduled hero snapshot' not in response.text, 'A nonowner can read saved scene scheduling metadata.')
        response = owner.put(schedule_url(case['base']), timeout=20)
        check(response.status_code == 405, 'A disallowed scene schedule method was accepted.')
        page(owner, case)
        owner.get(schedule_url(case['base']) + '&action=scene_schedule_run', timeout=20)
        unchanged(before, snapshot(case))

        values = form(owner, case)
        valid = http.changed(values, startsAt='2099-01-02T12:00:00', endsAt='', returnPolicy='keep')
        invalids = [http.changed(valid, _csrf='invalid'), http.changed(valid, startsAt='not-a-date'),
                    http.changed(valid, startsAt='2099-02-30T12:00:00'),
                    http.changed(valid, startsAt='2026-03-08T02:30:00'),
                    http.changed(valid, startsAt='2026-11-01T01:30:00'),
                    http.changed(valid, returnPolicy='unknown'), http.changed(valid, returnPolicy='restore_previous'),
                    http.changed(valid, endsAt='2099-01-01T12:00:00'),
                    http.changed(valid, expectedRevision='stale'),
                    http.changed(valid, expectedDraftRevision='stale'),
                    http.changed(valid, expectedPublishedRevision='stale'),
                    http.changed(valid, startsAt=None, **{'startsAt[]': '2099-01-02T12:00:00'})]
        for index, invalid in enumerate(invalids):
            snapshot_before = snapshot(case)
            submit(owner, case, invalid, success=False, label='Malformed/CSRF/stale schedule ' + str(index))
            unchanged(snapshot_before, snapshot(case))
        for client in (member, guest):
            response = client.post(schedule_url(case['base']), data=valid, allow_redirects=False, timeout=20)
            check(response.status_code in (302, 303, 401, 403), 'A nonowner submitted an owner schedule.')
        unchanged(before, snapshot(case))

        # A draft changed in another tab must invalidate the previously opened form.
        changed_draft = deepcopy(before['scenes']['hero']['draft'])
        changed_draft['title'] = 'Scheduled hero fresh draft'
        save_scene(owner, case, 'hero', changed_draft)
        changed_snapshot = snapshot(case)
        submit(owner, case, valid, success=False, label='Draft changed after scheduling form opened')
        unchanged(changed_snapshot, snapshot(case))

        future, future_values = create(owner, case, start='2099-01-02T12:00:00',
                                       end='2099-01-03T12:00:00', policy='restore_previous')
        pending = snapshot(case)
        check(without_metadata(future['scene']) == without_metadata(changed_draft), 'Scheduling did not freeze the saved draft.')
        check(pending['scenes'] == changed_snapshot['scenes'], 'Scheduling a future scene modified the draft, live scene or publication history.')
        submit(owner, case, future_values, success=False, label='Stale/overlapping second schedule')
        cancel_values = form(owner, case, selector='form[data-scene-schedule-cancel]')
        for invalid in (http.changed(cancel_values, _csrf='invalid'),
                        http.changed(cancel_values, expectedRevision='stale'),
                        http.changed(cancel_values, id='different-schedule')):
            submit(owner, case, invalid, success=False, label='Invalid or stale cancellation')
            unchanged(pending, snapshot(case))
        run_values = form(owner, case, selector='form[data-scene-schedule-run]')
        submit(owner, case, http.changed(run_values, _csrf='invalid'), success=False, label='Run due without valid CSRF')
        unchanged(pending, snapshot(case))
        stats = cli(case)
        check(stats['activated'] == 0 and stats['expired'] == 0, 'A future schedule activated before its start.')
        unchanged(pending, snapshot(case))
        month = '2099-01'
        calendar = owner.get(case['base'] + '/admin/?' + urlencode({'page': 'marketing', 'tab': 'calendar', 'month': month, 'type': 'scenes'}), timeout=20)
        check(calendar.status_code == 200 and future['snapshotTitle'] in calendar.text
              and schedule_url(case['base']) in calendar.text.replace('&amp;', '&'),
              'The real calendar does not show the saved schedule and correct editor destination.')
        unchanged(pending, snapshot(case))
        cancelled = cancel(owner, case)
        check(current(cancelled) is None and terminal(cancelled, future['id'])['status'] == 'cancelled'
              and cancelled['scenes'] == pending['scenes'], 'Pending cancellation altered live content or lost its outcome.')

        # A missed start/end window never flashes obsolete content onto the site.
        missed, _ = create(owner, case, start='2020-01-01T12:00:00', end='2020-01-02T12:00:00', policy='restore_previous')
        missed_before = snapshot(case)
        stats = cli(case)
        missed_after = snapshot(case)
        check(stats['skipped'] == 1 and current(missed_after) is None
              and missed_after['scenes'] == missed_before['scenes'], 'A missed schedule was published or retained as pending.')
        terminal(missed_after, missed['id'])

        # The real clock crosses an expiry after the immutable scene activates.
        # A later draft must survive both activation and restoration unchanged.
        original_live = deepcopy(missed_after['scenes']['hero']['published'])
        end_epoch = int(time.time()) + 8
        scheduled, _ = create(owner, case, end=local_time(end_epoch), policy='restore_previous')
        later_draft = deepcopy(changed_draft)
        later_draft['title'] = 'A newer draft kept while the scheduled scene runs'
        later_draft['scheduleFixture']['version'] = 'newer-draft'
        save_scene(owner, case, 'hero', later_draft)
        ready = snapshot(case)
        check(current(ready)['scene'] == scheduled['scene'], 'Saving a new draft rewrote the frozen schedule.')
        stats = cli(case)
        active = snapshot(case)
        check(stats['activated'] == 1 and current(active)['status'] == 'active', 'A due scene did not activate exactly once.')
        check(without_metadata(active['scenes']['hero']['published']) == without_metadata(scheduled['scene'])
              and without_metadata(active['scenes']['hero']['draft']) == without_metadata(later_draft)
              and len(active['scenes']['hero']['versions']) == len(ready['scenes']['hero']['versions']) + 1,
              'Activation lost the frozen scene, newer draft or one exact publication version.')
        check(without_metadata(public_scene(case)) == without_metadata(scheduled['scene']), 'The public homepage did not use the activated scene.')
        cli(case)
        unchanged(active, snapshot(case))
        wait_until(end_epoch + .1)
        stats = cli(case)
        ended = snapshot(case)
        check(stats['expired'] == 1 and current(ended) is None
              and without_metadata(ended['scenes']['hero']['published']) == without_metadata(original_live)
              and without_metadata(ended['scenes']['hero']['draft']) == without_metadata(later_draft)
              and len(ended['scenes']['hero']['versions']) == len(active['scenes']['hero']['versions']) + 1,
              'Real expiry did not restore the exact prior live scene while retaining the newer draft.')
        terminal(ended, scheduled['id'])
        check(without_metadata(public_scene(case)) == without_metadata(original_live), 'The public homepage did not return to its previous scene.')
        cli(case)
        unchanged(ended, snapshot(case))

        # Cancelling an active return keeps the scene that is currently live.
        active_cancel_job, _ = create(owner, case, end='2099-01-03T12:00:00', policy='restore_previous')
        cli(case)
        before_cancel_return = snapshot(case)
        check(current(before_cancel_return)['status'] == 'active', 'The return cancellation fixture did not become active.')
        after_cancel_return = cancel(owner, case)
        check(current(after_cancel_return) is None
              and after_cancel_return['scenes'] == before_cancel_return['scenes']
              and terminal(after_cancel_return, active_cancel_job['id'])['status'] == 'cancelled',
              'Cancelling an active return changed its current publication or draft.')
        cli(case)
        unchanged(after_cancel_return, snapshot(case))

        # Manual publication supersedes pending activation and an active return.
        manual_cases = []
        for active_first in (False, True):
            job, _ = create(owner, case, start=local_time(time.time() - 60) if active_first else '2099-01-02T12:00:00',
                            end='2099-01-03T12:00:00', policy='restore_previous')
            if active_first:
                cli(case)
            manual = deepcopy(later_draft)
            manual['title'] = 'Deliberate manual publication ' + str(active_first)
            manual['scheduleFixture']['version'] = 'manual-' + str(active_first)
            save_scene(owner, case, 'hero', manual, publish=True)
            published = snapshot(case)
            check(current(published) is None and terminal(published, job['id'])['status'] == 'superseded',
                  'Manual Publish did not supersede the previous schedule.')
            cli(case)
            unchanged(published, snapshot(case))
            manual_cases.append('active-return' if active_first else 'pending-activation')

        # Two independent real CLI processes may contend for one footer job.
        footer_job, _ = create(owner, case, section='footer')
        footer_before = snapshot(case)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as workers:
            outcomes = list(workers.map(lambda _: cli(case, 1), range(2)))
        footer_after = snapshot(case)
        check(sum(row['activated'] for row in outcomes) == 1 and current(footer_after, 'footer') is None
              and len(footer_after['scenes']['footer']['versions']) == len(footer_before['scenes']['footer']['versions']) + 1,
              'Contending worker processes duplicated or lost a footer publication.')
        check(without_metadata(footer_after['scenes']['footer']['published']) == without_metadata(footer_job['scene']),
              'Keep policy did not retain the frozen footer scene.')
        terminal(footer_after, footer_job['id'], 'footer')

        # The real explicit batch bound applies with both sections due.
        for section in ('hero', 'footer'):
            create(owner, case, section=section)
        two_due = snapshot(case)
        first_batch = cli(case, 1)
        half_done = snapshot(case)
        check(first_batch['claimed'] == 1 and first_batch['activated'] == 1
              and sum(current(half_done, section) is not None for section in ('hero', 'footer')) == 1,
              'The real limit=1 worker did not stop after one of two due scenes.')
        second_batch = cli(case, 1)
        both_done = snapshot(case)
        check(second_batch['claimed'] == 1 and second_batch['activated'] == 1
              and all(current(both_done, section) is None
                      and len(both_done['scenes'][section]['versions']) == len(two_due['scenes'][section]['versions']) + 1
                      for section in ('hero', 'footer')),
              'The next bounded invocation lost or duplicated the remaining due scene.')

        # Exercise a genuine durable claim left behind by a process exit. The
        # unexpired lease holds; explicitly aging the test lease permits recovery.
        interrupted, _ = create(owner, case)
        before_claim = snapshot(case)
        claimed = fixture(case, 'claim-and-exit', section='hero')
        processing = snapshot(case)
        check(claimed['claim'] is not None and current(processing)['status'] == 'processing'
              and processing['scenes'] == before_claim['scenes'], 'The genuine claim checkpoint published prematurely or was not durable.')
        cli(case)
        unchanged(processing, snapshot(case))
        fixture(case, 'expire-claim-lease', section='hero')
        stats = cli(case)
        recovered = snapshot(case)
        check(stats['recovered'] == 1 and stats['activated'] == 1 and current(recovered) is None
              and len(recovered['scenes']['hero']['versions']) == len(before_claim['scenes']['hero']['versions']) + 1,
              'An expired durable claim did not recover exactly once through the actual worker.')
        terminal(recovered, interrupted['id'])
        cli(case)
        unchanged(recovered, snapshot(case))

        # The current saved owner role governs both later requests and workers.
        held_job, _ = create(owner, case)
        reviewed_values = form(owner, case, selector='form[data-scene-schedule-cancel]')
        http.fixture(case['site'], 'owner-status', case['php'], status='demoted')
        revoked = snapshot(case)
        for method in ('get', 'post'):
            response = getattr(owner, method)(schedule_url(case['base']), **({'data': reviewed_values} if method == 'post' else {}),
                                               timeout=20, allow_redirects=False)
            check(response.status_code == 403 and held_job['snapshotTitle'] not in response.text,
                  'A stale demoted owner session read or mutated its schedule.')
        unchanged(revoked, snapshot(case))
        stats = cli(case)
        held = snapshot(case)
        check(stats['held'] == 1 and held['scenes'] == revoked['scenes']
              and current(held) is None and terminal(held, held_job['id'])['status'] == 'held',
              'A revoked owner schedule published or remained automatically runnable instead of being held in history.')
        http.fixture(case['site'], 'owner-status', case['php'], status='owner')
        reactivated = snapshot(case)
        cli(case)
        unchanged(reactivated, snapshot(case))

        # A section that has never published must keep its saved draft private.
        # The owned setup removes just the published row; all requests and the
        # scheduler below remain the unchanged actual installed package.
        fixture(case, 'draft-only', section='footer')
        draft_only = snapshot(case)
        check('published' not in draft_only['scenes']['footer']
              and bool(draft_only['scenes']['footer']['draft']), 'The draft-only fixture retained a published baseline.')
        check(public_scene(case, 'footer') in ({}, []), 'The public homepage leaked an unpublished footer draft.')
        no_baseline = http.changed(form(owner, case, 'footer'), startsAt='2099-01-02T12:00:00',
                                   endsAt='2099-01-03T12:00:00', returnPolicy='restore_previous')
        submit(owner, case, no_baseline, 'footer', success=False, label='Return policy without published baseline')
        unchanged(draft_only, snapshot(case))
        private_job, _ = create(owner, case, 'footer', start='2099-01-02T12:00:00')
        private_pending = snapshot(case)
        cli(case)
        check(public_scene(case, 'footer') in ({}, []), 'A future schedule exposed its unpublished frozen draft.')
        unchanged(private_pending, snapshot(case))
        private_cancelled = cancel(owner, case, 'footer')
        check(private_cancelled['scenes'] == draft_only['scenes']
              and terminal(private_cancelled, private_job['id'], 'footer')['status'] == 'cancelled',
              'Cancelling a draft-only schedule changed its unpublished saved scene.')
        final = snapshot(case)
        protected(before, final)
        check(case['capture'].connections == 0 and not case['capture'].payloads and not case['capture'].errors,
              'Scene scheduling attempted email delivery.')
        return {'checks': http.checks - initial_checks, 'mode': case['mode'], 'mysql_version': final['mysql_version'],
                'private_owner_forms_and_current_role': True, 'csrf_dates_dst_and_stale_forms_rejected': True,
                'calendar_reads_preserve_saved_state': True, 'immutable_saved_draft_activation': True,
                'real_clock_expiry_restores_previous_and_keeps_new_draft': True,
                'cancel_active_return_keeps_current_scene': True, 'two_due_sections_respect_batch_limit': True,
                'manual_publication_supersedes': manual_cases, 'two_real_cli_processes_one_publication': outcomes,
                'genuine_claim_checkpoint_exit': True, 'test_only_lease_aging': True,
                'expired_claim_real_cli_recovery': True, 'missed_window_never_published': True,
                'draft_only_future_schedule_stays_private': True,
                'revoked_owner_worker_held': True, 'protected_state_preserved': True,
                'smtp_connections': case['capture'].connections, 'smtp_messages': len(case['capture'].payloads)}
    finally:
        try:
            http.fixture(case['site'], 'owner-status', case['php'], status='owner')
        finally:
            for client in (owner, member, guest):
                client.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='file')
    args = parser.parse_args()
    evidence = http.evidence_directory()
    summary = {'report_kind': 'scene_schedules_http', 'passed': False,
               'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
               'fixture_sha256': hashlib.sha256(FIXTURE.read_bytes()).hexdigest(),
               'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True),
               'server': 'php -S without a router script', 'cases': {}}
    modes = ('file', 'mysql') if args.mode == 'both' else (args.mode,)
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-scene-schedule-http-') as temporary:
            work = Path(temporary)
            archive, identity = prepare_package(work)
            summary.update(identity)
            for mode in modes:
                for label, prefix in (('root', ''), ('nested', '/nested/velvet')):
                    name = mode + '-' + label
                    with runtime(work, archive, mode, name, prefix, evidence) as case:
                        summary['cases'][name] = http_case(case)
                    print('Scene schedule HTTP ' + name + ': PASS.', flush=True)
        summary['passed'] = True
        print(json.dumps(summary, indent=2), flush=True)
        print('Scene scheduling actual-package HTTP acceptance passed.', flush=True)
    except Exception as error:
        summary['failure'] = str(error)
        raise
    finally:
        if evidence:
            (evidence / 'scene-schedules-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')


if __name__ == '__main__':
    main()
