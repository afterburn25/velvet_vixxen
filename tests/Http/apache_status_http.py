#!/usr/bin/env python3
"""Retain expired-form status and refusal semantics on real packaged mod_php."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('apache_status_protection', ROOT / 'tests/Http/protection_records_http.py')
protection = importlib.util.module_from_spec(spec)
spec.loader.exec_module(protection)
http = protection.http
check = http.check
RUNTIME = ['app/Support/functions.php', 'app/Support/protection_admin.php', 'admin/index.php',
           'admin/addon-recovery.php', 'admin/repair.php', 'app/Views/admin/login.php',
           'app/Views/admin/operations.php', 'public/routes/api/upload.php']


def protected_files(site):
    result = {}
    for relative in ['config/app.php', 'storage/install.lock', 'uploads/protection-picture.png',
                     'storage/private/media/fixture/protected.mp4']:
        path = site / relative
        check(path.is_file() and not path.is_symlink(), 'A fixture preservation sentinel is missing.')
        result[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def run_case(case):
    start = http.checks
    guest = http.session()
    owner = http.owner_login(case['base'])
    statuses = {}
    before = protected_files(case['site'])
    protection_before = protection.fixture(case, 'snapshot')
    try:
        requests = [
            ('login', guest, '/admin/?page=login', {'email': http.OWNER, 'password': http.PASSWORD}),
            ('security', owner, '/admin/?page=security', {'action': 'security_begin'}),
            ('operations', owner, '/admin/?page=operations', {'action': 'operations_setup'}),
            ('deleted-content', owner, '/admin/?page=marketing&tab=deleted',
             {'action': 'editorial_deleted_restore', 'collection': 'posts', 'recovery_id': 'a' * 32}),
            ('notifications', owner, '/admin/?page=marketing&tab=notifications', {'action': 'notification_save'}),
            ('provider-events', owner, '/admin/?page=marketing&tab=provider', {'action': 'mail_provider_save'}),
            ('scene-schedules', owner, '/admin/?page=scene-schedules', {'action': 'scene_schedule_run', 'section': 'hero'}),
            ('protection', owner, '/admin/?page=protection', {'action': 'protection_enable'}),
            ('mail-settings', owner, '/admin/?page=mail-settings', {'action': 'mail_settings_save'}),
            ('addon-recovery', owner, '/admin/addon-recovery.php', {'addon_id': 'fixture'}),
            ('runtime-repair', owner, '/admin/repair.php', {'action': 'refresh_php_cache'}),
            ('upload-json', owner, '/route.php?vvx_route=api/upload.php', {}),
            ('public-report', guest, '/route.php?vvx_route=reports/index.php', {'action': 'report_create'}),
        ]
        for label, client, path, fields in requests:
            response = client.post(case['base'] + path, data={**fields, '_csrf': 'invalid-fixture-token'},
                                   timeout=25, allow_redirects=False)
            statuses[label] = response.status_code
            check(response.status_code == 419, label + ' lost its expired-form response on actual Apache: ' + str(response.status_code))
            check('expired' in response.text.lower(), label + ' omitted the actionable expired-form explanation.')
            if label == 'upload-json':
                check(response.headers.get('Content-Type', '').startswith('application/json')
                      and response.json()['ok'] is False, 'The upload refusal stopped being structured JSON.')
        check(protected_files(case['site']) == before, 'Expired forms changed installation identity or uploaded bytes.')
        check(protection.fixture(case, 'snapshot') == protection_before, 'Expired forms changed protection records or publication state.')
        check(not (case['site'] / 'storage/private/operations/gate.json').exists(), 'An expired form configured the operations gate.')
        check(case['capture'].connections == 0, 'An expired form contacted SMTP.')
        return {'checks': http.checks - start, 'statuses': statuses, 'preserved_files': before,
                'protection_state_preserved': True, 'operations_setup_refused': True, 'smtp_connections': 0}
    finally:
        guest.close()
        owner.close()


def run():
    evidence = http.evidence_directory()
    report = {'server': 'real Apache prefork and matching mod_php', 'cases': {},
              'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest()}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-http-status-') as tmp:
        work = Path(tmp)
        archive, identity = protection.prepare_package(work)
        report.update(identity)
        with ZipFile(archive) as zipped:
            check(all(zipped.read(name) == (ROOT / name).read_bytes() for name in RUNTIME),
                  'Status acceptance differs from the actual packaged application.')
        for label, prefix in [('file-root', ''), ('file-nested', '/nested/velvet')]:
            with protection.runtime(work, archive, 'file', label, prefix, evidence) as case:
                report['cases'][label] = run_case(case)
    if evidence:
        (evidence / 'apache-status-evidence.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    run()
