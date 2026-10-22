#!/usr/bin/env python3
"""Actual Apache privacy forms, complete ZIPs and interrupted real store stages."""
import argparse, hashlib, importlib.util, io, json, os, subprocess, tempfile, time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zipfile import ZipFile
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('privacy_age_http', ROOT / 'tests/Http/age_policies_http.py')
age = importlib.util.module_from_spec(spec); spec.loader.exec_module(age)
protection = age.protection; http = age.http; check = http.check
FIXTURE = ROOT / 'tests/Http/member_privacy_fixture.php'
STAGES = ['freeze', 'attribution', 'core', 'communications', 'files', 'newsletter', 'notifications', 'provider', 'protection', 'complete']
FILES = ['app/Core/MemberPrivacyService.php', 'app/Core/MemberPrivacyArchive.php', 'app/Core/MemberPrivacyProjection.php', 'app/Core/MemberPrivacyDomains.php',
         'app/Core/MemberPrivacyStoreTrait.php', 'app/Core/MarketingPrivacyErasure.php', 'app/Core/MarketingStorage.php', 'app/Core/MarketingAttributionTrait.php',
         'app/Core/NotificationService.php', 'app/Core/MailProviderService.php', 'app/Core/ProtectionRecords.php', 'app/Core/MemberAuth.php',
         'app/Core/CommunicationStoreTrait.php', 'app/Core/PrivateCommunicationFile.php', 'app/Support/member_privacy.php', 'app/Support/public_routes.php',
         'app/Views/admin/privacy.php', 'app/Views/admin/navigation.php', 'admin/index.php', 'app/Console/run_privacy.php', 'app/Docs/MEMBER_PRIVACY.md',
         'public/routes/account/privacy.php', 'public/routes/communication/privacy-download.php', 'public/routes/communication/download.php',
         'public/assets/css/member-privacy.css', 'public/assets/css/privacy-admin.css']
SOURCES = ['tests/Feature/member_privacy.php', 'tests/Feature/member_privacy_store.php', 'tests/Http/member_privacy_fixture.php',
           'tests/Http/member_privacy_http.py', 'tests/Browser/member_privacy_playwright.py']

def prepare_package(work):
    archive, identity = age.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in FILES), 'The privacy acceptance package differs from its runtime sources.')
    identity['source_sha256'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in SOURCES if (ROOT / name).is_file()}
    return archive, identity

def fixture(case, action, **values):
    result = subprocess.run([case['php'], str(FIXTURE), str(case['site']), action], input=json.dumps(values), text=True, capture_output=True, timeout=50)
    check(result.returncode == 0, 'Owned privacy fixture failed (' + action + '): ' + result.stderr[:2000]); protection.chown_tree(case['site'])
    return json.loads(result.stdout)

def state(case):
    saved = fixture(case, 'snapshot')
    if saved['journal']:
        check(json.loads(saved['committed_privacy'])['data'] == saved['journal'], 'Privacy API differs from independently read durable file/SQL bytes.')
    return saved

def member_url(case): return http.route(case['base'], 'account/privacy.php')
def owner_url(case, request=''): return case['base'] + '/admin/?page=privacy' + ('&request=' + request if request else '')
def download_url(case, request): return http.route(case['base'], 'communication/privacy-download.php?id=' + request)
def form(response, selector): return dict(http.fields(response.text, selector))

def private(response):
    http.private(response)
    check(response.headers.get('Referrer-Policy') == 'no-referrer' and response.headers.get('Pragma') == 'no-cache'
          and all(value.strip() == 'nosniff' for value in response.headers.get('X-Content-Type-Options', '').split(',')) and 'noindex' in response.headers.get('X-Robots-Tag', ''),
          'A private account response lost its no-referrer, noindex, nosniff or no-cache header: ' + repr({key: response.headers.get(key) for key in ['Referrer-Policy', 'Pragma', 'X-Content-Type-Options', 'X-Robots-Tag']}))

def request(member, case, kind):
    page = member.get(member_url(case), timeout=20); values = form(page, '[data-privacy-request="' + kind + '"]')
    response = member.post(member_url(case), data={**values, 'password': http.MEMBER_PASSWORD, 'confirmed': '1'}, timeout=30)
    check(response.status_code == 200 and bool(response.history), 'Actual password-confirmed member request failed: ' + response.text[-800:])
    rows = state(case)['journal']['requests']; matches = [row for row in rows.values() if row['kind'] == kind and row['status'] == 'requested']
    check(len(matches) == 1, 'Actual form did not create one pending request.'); return matches[0]['id']

def retention():
    return {'reason': 'Retain exact financial facts and safety evidence for a documented operator review. External billing and backups reviewed.',
            'review_on': (datetime.now(timezone.utc) + timedelta(days=30)).date().isoformat(),
            **{key: '1' for key in ['financial_reviewed', 'safety_reviewed', 'backups_reviewed', 'billing_reviewed', 'open_orders_reviewed', 'guest_requests_reviewed']}}

def approve(owner, case, request_id):
    page = owner.get(owner_url(case, request_id), timeout=25); values = form(page, '[data-privacy-approve]')
    response = owner.post(owner_url(case, request_id), data={**values, **retention()}, timeout=30)
    check(response.status_code == 200 and bool(response.history), 'Actual reviewed owner approval failed: ' + response.text[-1000:]); return response

def worker(case, limit=10):
    result = subprocess.run([case['php'], str(case['site'] / 'app/Console/run_privacy.php'), '--limit=' + str(limit)], text=True, capture_output=True, timeout=40)
    check(result.returncode in [0, 2], 'The actual privacy CLI failed: ' + result.stderr[:1800] + result.stdout[:500]); protection.chown_tree(case['site'])
    return json.loads(result.stdout)

def verify_zip(response):
    check(response.status_code == 200 and response.headers.get('Content-Type') == 'application/zip', 'The current member did not receive a private ZIP.')
    private(response)
    check(response.headers.get('X-Archive-SHA256') == hashlib.sha256(response.content).hexdigest() and int(response.headers['Content-Length']) == len(response.content), 'Download headers do not describe the actual checked bytes.')
    with ZipFile(io.BytesIO(response.content)) as archive:
        check(archive.testzip() is None, 'The returned archive failed independent ZIP CRC validation.')
        exported = json.loads(archive.read('member-data.json')); text = json.dumps(exported)
        check(len(exported['orders']) == 1007 and exported['orders'][0]['grandTotalMinor'] == 4119, 'Complete financial export truncated rows or changed exact cents.')
        check(len(archive.namelist()) == 4 and len(exported['attachments']) == 3, 'The archive omitted member-visible files or included staff attachments.')
        check(not any(value in text for value in ['PRIVATE_', 'UNRELATED_', 'passwordHash', 'tokenHash', 'receipt_hash', 'recipient_ciphertext', 'privacy-other@example.test']), 'Private staff, credential, guest or another member data leaked into the ZIP.')
        check(exported['reports'][0]['description'] == 'My own report text.' and len(exported['optionalNotifications']) == 1 and len(exported['mailProviderFeedback']) == 1, 'The complete allowlist omitted a linked private subsystem.')
        for name, metadata in exported['attachmentFiles'].items():
            data = archive.read(name); check(metadata['size'] == len(data) and metadata['sha256'] == hashlib.sha256(data).hexdigest(), 'Attachment manifest differs from the returned bytes.')
    return hashlib.sha256(response.content).hexdigest()

def gate_exports(case, owner, member, other, guest, request_id):
    age.activate_protection(case, owner); access = age.workspace(case, 'access')
    checks = {}
    for label, changes, expected in [('age', {'mode': 'required', 'clear_api_key': '1'}, 503),
        ('location', {'mode': 'disabled', 'geographic_enabled': '1', 'trusted_proxies': '127.0.0.1/32', 'blocked_countries': 'US'}, 403)]:
        values = age.access_values(owner.get(access, timeout=20), **changes)
        response = owner.post(access, data=values, timeout=30); check(response.status_code == 200 and bool(response.history), 'Actual access restriction was not activated: ' + label)
        check(member.get(http.route(case['base'], 'account/library.php'), timeout=20).status_code == expected, 'Ordinary member content bypassed the active ' + label + ' rule.')
        check(member.get(member_url(case), timeout=20).status_code == 200, 'Own account privacy utility was blocked by ' + label)
        verify_zip(member.get(download_url(case, request_id), timeout=25))
        check(other.get(download_url(case, request_id), timeout=20).status_code == 404 and guest.get(download_url(case, request_id), timeout=20).status_code == 403,
              'Privacy exemption authorized anonymous or another member under ' + label)
        checks[label + '_own_zip_allowed'] = True
    values = age.access_values(owner.get(access, timeout=20), mode='disabled')
    check(owner.post(access, data=values, timeout=30).status_code == 200, 'The owned test access rule could not be cleared.')
    return checks

def interrupt_stage(case, request_id, stage, index):
    ready = case['site'] / 'storage/temp/privacy-stage-ready.json'; release = case['site'] / 'storage/temp/privacy-stage-release'
    ready.unlink(missing_ok=True); release.unlink(missing_ok=True)
    process = subprocess.Popen([case['php'], str(FIXTURE), str(case['site']), 'pause-worker'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    process.stdin.write(json.dumps({'stage': stage})); process.stdin.close(); process.stdin = None
    try:
        deadline = time.monotonic() + 25
        while not ready.is_file():
            if process.poll() is not None:
                stdout, stderr = process.communicate(); raise AssertionError('Paused worker ended before its durable side effect: ' + stdout + stderr)
            check(time.monotonic() < deadline, 'The owned stage pause did not reach its actual side effect: ' + stage); time.sleep(.05)
        mark = json.loads(ready.read_text()); check(mark == {'stage': stage, 'id': request_id}, 'The fault injection paused an unrelated request.')
        during = state(case)['journal']['requests'][request_id]; check(during['stage'] == index and during['status'] == 'processing', 'A killed-stage test did not stop before journal commit.')
        concurrent = worker(case, 1); check(concurrent['busy'] and concurrent['processed'] == 0, 'A concurrent actual CLI crossed the exclusive privacy worker lock.')
        if stage == 'core': protection.owner_status(case, 'inactive')
        process.kill(); process.wait(timeout=5)
        resumed = worker(case, 1)
        if stage == 'core':
            held = state(case)['journal']['requests'][request_id]
            check(resumed['held'] == 1 and resumed['processed'] == 0 and held['stage'] == index and held['status'] == 'held', 'Disabling the approving owner between durable effects did not hold resumption.')
            protection.owner_status(case, 'owner'); fresh = http.owner_login(case['base'])
            try: approve(fresh, case, request_id)
            finally: fresh.close()
            resumed = worker(case, 1)
        after = state(case)['journal']['requests'][request_id]
        check(resumed['processed'] == 1 and resumed['held'] == 0 and after['stage'] == index + 1 and stage in after['completed_stages'], 'The actual killed worker could not resume idempotently at ' + stage)
        return {'stage': stage, 'process_killed': True, 'concurrent_busy': True, 'resumed_stage': after['stage'], 'owner_disable_held_and_reviewed': stage == 'core'}
    finally:
        if process.poll() is None: process.kill(); process.wait(timeout=5)
        process.communicate(); ready.unlink(missing_ok=True); release.unlink(missing_ok=True)

def http_case(case):
    start = http.checks; records = fixture(case, 'seed'); owner = http.owner_login(case['base'])
    member = http.member_login(case['base'], 'privacy'); other = http.member_login(case['base'], 'privacy-other'); guest = http.session()
    try:
        initial = state(case); check(initial['order_count'] == 1007 and not initial['journal'], 'The fixture lacks a complete untouched privacy account.')
        page = member.get(member_url(case), timeout=20); private(page); check(page.status_code == 200 and 'Account &amp; privacy' in page.text, 'Actual member privacy page is absent.')
        owner_page = owner.get(owner_url(case), timeout=20); private(owner_page)
        check(state(case)['all_sha256'] == initial['all_sha256'], 'Ordinary member/owner privacy GET mutated durable application state.')
        check(guest.get(member_url(case), timeout=20).status_code == 403 and guest.get(owner_url(case), timeout=20).status_code == 403, 'Guest opened private account or owner controls.')
        check(member.put(member_url(case), timeout=20).status_code == 405 and owner.put(owner_url(case), timeout=20).status_code == 405, 'Unsupported privacy methods were accepted.')
        values = form(page, '[data-privacy-request="export"]')
        for changes, code in [({'password': http.MEMBER_PASSWORD, 'confirmed': '1', '_csrf': 'bad'}, 419), ({'password': 'wrong', 'confirmed': '1'}, 403), ({'password': http.MEMBER_PASSWORD}, 400)]:
            response = member.post(member_url(case), data={**values, **changes}, timeout=20); check(response.status_code == code, 'A rejected member privacy form had status ' + str(response.status_code) + ' instead of ' + str(code) + ': ' + BeautifulSoup(response.text, 'html.parser').get_text(' ', strip=True)[-1200:])
            check(state(case)['all_sha256'] == initial['all_sha256'], 'Rejected member privacy input wrote application data.')
        canceled = request(member, case, 'export'); cancel_page = member.get(member_url(case), timeout=20); cancel_values = form(cancel_page, '[data-request-id="' + canceled + '"] [data-privacy-cancel]')
        check(member.post(member_url(case), data={**cancel_values, 'password': 'wrong', 'confirmed': '1'}, timeout=20).status_code == 403, 'Cancellation skipped actual password verification.')
        check(member.post(member_url(case), data={**cancel_values, 'password': http.MEMBER_PASSWORD, 'confirmed': '1'}, timeout=20).status_code == 200, 'Real pending cancellation failed.')
        exported = request(member, case, 'export'); review = owner.get(owner_url(case, exported), timeout=25); values = form(review, '[data-privacy-approve]')
        before = state(case); check(owner.post(owner_url(case, exported), data={**values, '_csrf': 'bad'}, timeout=25).status_code == 419, 'Owner approval skipped CSRF.'); check(state(case)['all_sha256'] == before['all_sha256'], 'Rejected owner input wrote application data.')
        fixture(case, 'change-inventory'); check(owner.post(owner_url(case, exported), data=values, timeout=25).status_code == 409, 'A stale inventory was approved.')
        protection.fixture(case, 'age-owner-session', cookie=owner.cookies.get('velvet_vixxen_admin'))
        check(owner.get(owner_url(case, exported), timeout=25).status_code == 428, 'Owner inventory review did not require recent authentication.')
        owner.close(); owner = http.owner_login(case['base']); approve(owner, case, exported)
        before = state(case); check(member.post(member_url(case), data={**cancel_values, 'id': exported, 'password': http.MEMBER_PASSWORD, 'confirmed': '1'}, timeout=20).status_code == 409, 'The member canceled already approved work.'); check(state(case)['all_sha256'] == before['all_sha256'], 'A rejected approved cancellation mutated durable state.')
        protection.owner_status(case, 'demoted'); check(owner.get(owner_url(case), timeout=20).status_code == 403, 'A demoted owner retained privacy access.')
        held = worker(case, 1); check(held['held'] == 1 and not (case['site'] / 'storage/private/member-exports' / (exported + '.zip')).exists(), 'The worker acted on a no-longer-current owner approval.')
        protection.owner_status(case, 'owner'); owner.close(); owner = http.owner_login(case['base']); approve(owner, case, exported)
        result = owner.post(owner_url(case), data=form(owner.get(owner_url(case), timeout=20), '[data-privacy-run]'), timeout=35)
        check(result.status_code == 200 and bool(result.history) and state(case)['journal']['requests'][exported]['status'] == 'complete', 'Actual owner Run approved work did not complete the export.')
        archive_hash = verify_zip(member.get(download_url(case, exported), timeout=25)); metadata = state(case)['journal']['requests'][exported]['archive']
        check(metadata['expires_at'] - metadata['created_at'] == 604800 and metadata['sha256'] == archive_hash, 'The download does not have its exact seven-day lifetime.')
        check(other.get(download_url(case, exported), timeout=20).status_code == 404 and owner.get(download_url(case, exported), timeout=20).status_code == 403 and guest.get(download_url(case, exported), timeout=20).status_code == 403, 'Owner, other member or anonymous visitor obtained this member ZIP.')
        check(member.post(download_url(case, exported), timeout=20).status_code == 405, 'Unsupported private download method was accepted.')
        check(guest.get(case['base'] + '/storage/private/member-exports/' + exported + '.zip', timeout=20).status_code in [403, 404], 'The real Apache rule exposed a raw private archive.')
        for kind, key, allowed in [('message', 'message_attachment', True), ('message', 'internal_attachment', False), ('request', 'request_attachment', True), ('request', 'staff_request_attachment', False)]:
            url = http.route(case['base'], 'communication/download.php?kind=' + kind + '&id=' + records[key]); response = member.get(url, timeout=20)
            check(response.status_code == (200 if allowed else 403), 'Ordinary attachment visibility boundary failed: ' + key)
        if case['mode'] == 'mysql':
            check(records['message_attachment'] == records['request_attachment'], 'SQL same-id collision fixture was not real.')
            check(member.get(http.route(case['base'], 'communication/download.php?id=' + records['message_attachment']), timeout=20).status_code == 404, 'Untyped SQL same-id attachment ambiguity was accepted.')
        fixture(case, 'tamper-archive', id=exported); check(member.get(download_url(case, exported), timeout=20).status_code == 404, 'A changed CRC passed after the outer hash was updated.'); fixture(case, 'restore-archive', id=exported)
        blocked = gate_exports(case, owner, member, other, guest, exported)
        fixture(case, 'expire-archive', id=exported); check(member.get(download_url(case, exported), timeout=20).status_code == 404, 'An expired export remained downloadable before maintenance.')
        check(worker(case, 1)['expired'] == 1 and not (case['site'] / 'storage/private/member-exports' / (exported + '.zip')).exists(), 'Actual worker did not remove the expired private ZIP.')
        current_export = request(member, case, 'export'); approve(owner, case, current_export); check(worker(case, 1)['completed'] == 1, 'Replacement export did not complete before closure.')
        erased = request(member, case, 'erase'); values = form(owner.get(owner_url(case, erased), timeout=25), '[data-privacy-approve]')
        check(owner.post(owner_url(case, erased), data=values, timeout=25).status_code == 400, 'Account closure skipped mandatory retention review.'); approve(owner, case, erased)
        before = state(case); recoveries = []
        writer_ready = case['site'] / 'storage/temp/privacy-writer-ready'; writer_release = case['site'] / 'storage/temp/privacy-writer-release'
        delayed = subprocess.Popen([case['php'], str(FIXTURE), str(case['site']), 'pause-delayed-writer'], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            deadline = time.monotonic() + 10
            while not writer_ready.is_file():
                check(delayed.poll() is None and time.monotonic() < deadline, 'The actual stale writer did not pause before closure.'); time.sleep(.05)
            for index, stage in enumerate(STAGES):
                recoveries.append(interrupt_stage(case, erased, stage, index))
                if stage == 'freeze':
                    check(member.get(member_url(case), timeout=20).status_code == 403 and member.get(download_url(case, current_export), timeout=20).status_code == 403, 'Freeze left a device or previously approved ZIP accessible.')
            writer_release.touch(); stdout, stderr = delayed.communicate(timeout=15)
            check(delayed.returncode == 0 and all(json.loads(stdout).values()), 'The real previously paused writer recreated removed data: ' + stdout + stderr[:800])
        finally:
            if delayed.poll() is None: delayed.kill(); delayed.wait(timeout=5)
            delayed.communicate(); writer_ready.unlink(missing_ok=True); writer_release.unlink(missing_ok=True)
        after = state(case); row = after['journal']['requests'][erased]
        check(row['status'] == 'complete' and row['stage'] == 10 and not row['cleanup'] and not row['archive_cleanup'], 'Completed ten-stage closure retained temporary cleanup material.')
        check(after['financial_sha256'] == before['financial_sha256'] and after['order_count'] == 1007, 'Account closure changed exact financial facts.')
        check(after['other_sha256'] == before['other_sha256'] and after['guest_sha256'] == before['guest_sha256'], 'Closure changed another member or unlinked guest.')
        check(set(after['files']) == {'shared.txt'} and not (case['site'] / 'storage/private/member-exports' / (current_export + '.zip')).exists(), 'Closure retained owned private files or deleted a shared file.')
        check(after['reports'] == {'hold': True, 'status': 'taken_down', 'reporter': None, 'name': '', 'evidence': 'PRIVATE_RETAINED_SAFETY_EVIDENCE'}, 'Closure failed to anonymize the reporter or preserve the safety hold/evidence.')
        check(after['notification_count'] == 0 and after['newsletter_contact_count'] == 0 and after['provider'][0]['recipient_ciphertext'] is None, 'Personal notification, newsletter or provider material survived closure.')
        late = fixture(case, 'late-writers'); check(all(late.values()), 'A delayed writer recreated erased personal fields: ' + repr(late))
        check(worker(case, 10)['processed'] == 0 and case['capture'].connections == 0 and not case['capture'].payloads, 'Completed privacy work ran again or contacted SMTP.')
        return {'checks': http.checks - start, 'mysql_version': after['mysql_version'], 'orders_exported': 1007, 'archive_sha256': archive_hash,
                'readonly_get_no_mutation': True, 'real_password_and_csrf': True, 'fresh_recent_owner_approval': True,
                'archive_sha256_crc_verified': True, 'seven_day_expiry': True, 'member_only_private_download': True,
                'staff_attachment_denied': True, 'sql_same_id_ambiguity_denied': case['mode'] == 'mysql',
                'killed_worker_recoveries': recoveries, 'ten_stages_durable': True, 'concurrent_worker_exclusion': True,
                'financial_facts_preserved': True, 'other_members_unchanged': True, 'guest_records_unchanged': True, 'shared_file_preserved': True,
                'safety_holds_retained': True, 'delayed_writers_blocked': True, 'real_paused_writer_blocked': True, 'smtp_connections': 0, **blocked}
    finally:
        for client in [owner, member, other, guest]: client.close()

def run():
    parser = argparse.ArgumentParser(); parser.add_argument('--mode', choices=['file', 'mysql', 'both'], default='both'); args = parser.parse_args()
    evidence = http.evidence_directory(); report = {'server': 'real Apache + matching mod_php', 'cases': {}}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-privacy-http-') as tmp:
        work = Path(tmp); archive, identity = prepare_package(work); report.update(identity)
        for mode in (['file', 'mysql'] if args.mode == 'both' else [args.mode]):
            for label, prefix in [('root', ''), ('nested', '/nested/velvet')]:
                name = mode + '-' + label
                with protection.runtime(work, archive, mode, name, prefix, evidence) as case: report['cases'][name] = http_case(case)
                print('Privacy HTTP ' + name + ': PASS', flush=True)
    if evidence: (evidence / 'member-privacy-http-evidence.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
if __name__ == '__main__': run()
