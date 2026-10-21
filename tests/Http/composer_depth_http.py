#!/usr/bin/env python3
"""Actual Fresh scene-depth persistence and rejection on file/MySQL, root/nested.

The browser gate imports the owned runtime, pinned-package and independent
readback helpers. No HTTP route, renderer or saved response is substituted.
"""
from __future__ import annotations

import argparse
from copy import deepcopy
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
from zipfile import ZipFile

from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
checks = 0


def check(condition, message):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(message)


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_base = load('vvx_depth_runtime', ROOT / 'tests/Http/editorial_access_http.py')
session, owner_login, member_login, route = _base.session, _base.owner_login, _base.member_login, _base.route
runtime, evidence_directory = _base.runtime, _base.evidence_directory
OWNER, PASSWORD = _base.OWNER, _base.PASSWORD
UI_FILES = ('app/Core/Store.php', 'app/Core/SceneDepth.php', 'app/Core/SceneButton.php',
            'app/Views/admin/composer.php', 'admin/index.php', 'public/home.php',
            'public/routes/api/scene.php', 'public/assets/js/scene-depth.js',
            'public/assets/js/scene-geometry.js', 'public/assets/js/composer.js',
            'public/assets/js/site.js', 'public/assets/css/composer.css')


def prepare_package(work: Path):
    package = load('vvx_depth_package', ROOT / 'scripts/package.py')
    identity = package.package_identity({name: (ROOT / name).read_bytes() for name in ('VERSION', 'includes/version.php')})
    expected = os.environ.get('VELVET_EXPECTED_FRESH_SHA256', '').lower()
    if os.environ.get('VELVET_FRESH_PACKAGE'):
        archive = Path(os.environ['VELVET_FRESH_PACKAGE']).resolve()
        check(archive.is_file() and bool(re.fullmatch(r'[a-f0-9]{64}', expected)),
              'Pin the supplied actual Fresh ZIP with VELVET_EXPECTED_FRESH_SHA256.')
    else:
        built = package.build(ROOT, work / 'packages', identity[0])
        archive = work / 'packages' / next(item['filename'] for item in built['packages'] if item['kind'] == 'fresh')
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if expected:
        check(digest == expected, 'The actual Fresh archive differs from its pinned expected hash.')
    with ZipFile(archive) as zipped:
        files = {name: zipped.read(name) for name in zipped.namelist() if not name.endswith('/')}
    version, build = package.package_identity(files)
    check((version, build) == identity, 'The actual Fresh identity differs from the canonical source.')
    package.validate(files, 'fresh', version)
    mismatched = [name for name in UI_FILES if name not in files or files[name] != (ROOT / name).read_bytes()]
    check(not mismatched, 'The tested Fresh archive differs from runtime sources: ' + ', '.join(mismatched))
    check(not any(name.startswith(('tests/', 'scripts/', 'docs/')) for name in files),
          'Private acceptance tests or fixtures leaked into the customer package.')
    frozen = work / 'verified-customer-fresh.zip'
    shutil.copyfile(archive, frozen)
    check(hashlib.sha256(frozen.read_bytes()).hexdigest() == digest, 'Fresh changed while its immutable test copy was made.')
    return frozen, {'version': version, 'build_id': build, 'fresh_zip_sha256': digest,
                    'expected_fresh_zip_sha256': expected or digest, 'externally_pinned_fresh': bool(expected),
                    'archive_runtime_matches_sources': True}


# Read-only CLI observer. It loads no bootstrap/session and never calls Store.
# SQL values are read on an independent connection after the HTTP commit.
PHP_READ_STATE = r'''
$root = realpath((string) ($argv[1] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime') || !is_file($root . '/storage/temp/editorial-access-records.json')) {
    throw new RuntimeException('A marked initialized disposable scene runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 32, JSON_THROW_ON_ERROR);
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)) {
    throw new RuntimeException('The test does not own this runtime.');
}
$saved = require $root . '/config/app.php';
$mode = $ownership['mode'];
if (($saved['storage_mode'] ?? '') !== $mode || ($saved['base_url'] ?? '') !== ($ownership['base'] ?? '')
    || parse_url((string) $saved['base_url'], PHP_URL_SCHEME) !== 'http'
    || parse_url((string) $saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== ($ownership['smtp_port'] ?? null)
    || !is_file($root . '/storage/install.lock')) throw new RuntimeException('The owned loopback configuration changed.');
$pdo = null;
if ($mode === 'mysql') {
    if ((getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1') !== '127.0.0.1') throw new RuntimeException('Loopback MySQL is required.');
    require $argv[2];
    [$db, $pdo] = vvx_installer_fixture_database();
    if (($ownership['database'] ?? '') !== $db['name'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['host'] ?? '') !== '127.0.0.1' || ($saved['db']['user'] ?? '') !== $db['user']
        || (int) ($saved['db']['port'] ?? 0) !== $db['port']) throw new RuntimeException('The owned test database changed.');
}
$result = ['mode' => $mode, 'scenes' => [], 'records' => [], 'audit' => [], 'protected' => [],
    'outbox_count' => 0, 'mysql_version' => $pdo?->getAttribute(PDO::ATTR_SERVER_VERSION)];
foreach (['config/app.php', 'storage/install.lock', 'uploads/editorial-existing.bin',
    'storage/private/editorial-existing.bin', 'storage/backups/editorial-existing.txt'] as $relative) {
    $result['protected'][$relative] = hash_file('sha256', $root . '/' . $relative);
}
$unrelated = [];
if ($pdo !== null) {
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows = $pdo->query('SELECT * FROM ' . vvx_installer_fixture_quote((string) $table))->fetchAll(PDO::FETCH_ASSOC);
        if (in_array($table, ['scenes', 'scene_versions'], true)) {
            usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            $result['records'][$table] = $rows;
            continue;
        }
        $keep = [];
        foreach ($rows as $row) {
            if ($table === 'audit_log' && str_starts_with((string) $row['action_key'], 'scene.')) {
                $result['audit'][] = $row;
            } else {
                if (in_array($table, ['members', 'member_sessions'], true)) unset($row['last_seen_at']);
                $keep[] = json_encode($row, JSON_THROW_ON_ERROR);
            }
        }
        sort($keep, SORT_STRING);
        $unrelated[$table] = $keep;
    }
    foreach ($result['records']['scenes'] as $row) {
        $result['scenes'][$row['section_key']][$row['status']] = json_decode((string) $row['scene_json'], true, 512, JSON_THROW_ON_ERROR);
    }
    foreach ($result['records']['scene_versions'] as $row) {
        $result['scenes'][$row['section_key']]['versions'][] = ['id' => (string) $row['id'],
            'scene' => json_decode((string) $row['scene_json'], true, 512, JSON_THROW_ON_ERROR)];
    }
    $result['outbox_count'] = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    $result['protected']['preserved-file-store'] = hash_file('sha256', $root . '/storage/data.json');
} else {
    $unrelated = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
    $result['scenes'] = $unrelated['scenes'] ?? [];
    $result['records'] = $result['scenes'];
    unset($unrelated['scenes']);
    $retained = [];
    foreach ($unrelated['audit'] ?? [] as $row) {
        if (str_starts_with((string) ($row['action'] ?? ''), 'scene.')) $result['audit'][] = $row;
        else $retained[] = $row;
    }
    $unrelated['audit'] = $retained;
    foreach ($unrelated['memberSessions'] ?? [] as $id => $row) unset($unrelated['memberSessions'][$id]['lastSeenAt']);
    foreach ($unrelated['members'] ?? [] as $id => $row) unset($unrelated['members'][$id]['lastSeenAt']);
    $result['outbox_count'] = count($unrelated['mailOutbox'] ?? []);
}
foreach (['hero', 'footer'] as $section) $result['scenes'][$section]['versions'] ??= [];
$result['unrelated_sha256'] = hash('sha256', json_encode($unrelated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
'''


def snapshot(site: Path, php='php'):
    result = subprocess.run([php, '-r', PHP_READ_STATE, str(site), str(ROOT / 'tests/Installer/database_fixture.php')],
                            capture_output=True, text=True, cwd='/tmp', timeout=30)
    check(result.returncode == 0, 'Independent owned scene readback failed: ' + result.stderr[:1800])
    return json.loads(result.stdout)


def protected(before, after):
    check(before['protected'] == after['protected'] and before['unrelated_sha256'] == after['unrelated_sha256']
          and before['outbox_count'] == after['outbox_count'],
          'Scene work changed unrelated records, credentials, config, uploads, backups or mail outbox.')


def unchanged(before, after):
    protected(before, after)
    check(before['scenes'] == after['scenes'] and before['records'] == after['records'] and before['audit'] == after['audit'],
          'A rejected/read-only scene request changed saved scenes, committed history or audit.')


def without_metadata(scene):
    return {key: value for key, value in scene.items() if key not in ('section', 'updatedAt')}


def response_json(response, expected, label):
    status = response.status_code if hasattr(response, 'status_code') else response.status
    check(status == expected, f'{label}: expected HTTP{expected}, got {status}.')
    result = response.json()
    check(isinstance(result, dict) and result.get('ok') is (expected == 200), label + ' returned an unexpected JSON envelope.')
    if expected != 200:
        check(bool(result.get('message')), label + ' did not explain its refusal.')
    return result


def read_scene(owner, base, section):
    return response_json(owner.get(route(base, 'api/scene.php?section=' + section), timeout=15), 200, 'Scene read')


def post_scene(owner, base, section, token, action, scene=None, version=None, expected=200):
    payload = {'action': action}
    if scene is not None:
        payload['scene'] = scene
    if version is not None:
        payload['versionId'] = version
    response = owner.post(route(base, 'api/scene.php?section=' + section),
                          headers={'X-CSRF-Token': token}, json=payload, timeout=20)
    return response_json(response, expected, section + ' ' + action)


POINTS = [{'x': 18, 'y': 55}, {'x': 87.5, 'y': 55}, {'x': 95, 'y': 93}, {'x': 8, 'y': 93}]


def depth_scene(original, section):
    scene = deepcopy(original)
    scene['depthAcceptance'] = {'section': section, 'unrelatedCustomField': ['retained', 42]}
    source = scene['background']['src']
    for device, depth in [('desktop', 30), ('tablet', 55), ('mobile', 85)]:
        view = scene['devices'][device]
        # Remove only fixture backgrounds that would intentionally override this source.
        view['background'] = deepcopy(scene['background'])
        view['perspective'] = {'x': 70, 'y': 35}
        view['foreground'] = {'enabled': True, 'source': source, 'points': deepcopy(POINTS)}
        photo = {'id': f'depth-{section}-{device}', 'src': 'models/defaults/model-1.png', 'x': 82, 'y': 76,
                 'width': 30, 'rotation': 0, 'z': -3, 'depth': depth, 'behindForeground': True,
                 'visible': device != 'tablet', 'locked': device == 'tablet', 'custom': {'keep': True}}
        view['layers'] = [photo, {'id': f'legacy-{section}-{device}', 'src': photo['src'],
                                 'x': -42, 'y': 130, 'width': 220, 'custom': {'legacy': True}}]
    return scene


def invalid_scenes(scene):
    cases = []
    def layer(name, key, value):
        copy = deepcopy(scene)
        copy['devices']['desktop']['layers'][0][key] = value
        cases.append((name, copy))
    for name, value in [('negative', -1), ('overflow', 100.01), ('string', '30'), ('true', True),
                        ('false', False), ('null', None), ('array', [])]:
        layer('depth-' + name, 'depth', value)
    for name, value in [('number', 1), ('string', 'true'), ('null', None)]:
        layer('behind-' + name, 'behindForeground', value)
    for name, value in [('null', None), ('missing-axis', {'x': 50}), ('unknown-key', {'x': 50, 'y': 35, 'z': 1}),
                        ('boolean', {'x': True, 'y': 35}), ('string', {'x': 50, 'y': '35'}),
                        ('outside', {'x': -1, 'y': 35})]:
        copy = deepcopy(scene)
        copy['devices']['tablet']['perspective'] = value
        cases.append(('perspective-' + name, copy))
    masks = [('null', None), ('array', []), ('unknown-property', {'untrusted': True}),
             ('enabled-number', {'enabled': 1}), ('enabled-string', {'enabled': 'true'}),
             ('mismatched-source', {'source': 'images/defaults/different-room.jpg'}),
             ('remote-source', {'source': 'https://example.test/room.png'}), ('path-traversal', {'source': '../room.png'}),
             ('too-few', {'points': POINTS[:2]}),
             ('too-many', {'points': [{'x': 50 + 40 * math.cos(i * 2 * math.pi / 65),
                                      'y': 50 + 40 * math.sin(i * 2 * math.pi / 65)} for i in range(65)]}),
             ('crossing', {'points': [{'x': 0, 'y': 0}, {'x': 100, 'y': 100}, {'x': 0, 'y': 100}, {'x': 100, 'y': 0}]}),
             ('zero-area', {'points': [{'x': 0, 'y': 0}, {'x': 50, 'y': 50}, {'x': 100, 'y': 100}]}),
             ('duplicate', {'points': [POINTS[0], POINTS[1], POINTS[2], POINTS[0]]}),
             ('point-string', {'points': [{'x': '18', 'y': 55}, POINTS[1], POINTS[2]]}),
             ('point-bool', {'points': [{'x': True, 'y': 55}, POINTS[1], POINTS[2]]}),
             ('point-overflow', {'points': [{'x': 101, 'y': 55}, POINTS[1], POINTS[2]]}),
             ('point-key', {'points': [{'x': 18, 'y': 55, 'extra': 1}, POINTS[1], POINTS[2]]}),
             ('disabled-invalid', {'enabled': False, 'points': POINTS[:2]})]
    for name, updates in masks:
        copy = deepcopy(scene)
        copy['devices']['mobile']['foreground'] = ({**copy['devices']['mobile']['foreground'], **updates}
                                                  if isinstance(updates, dict) else updates)
        cases.append(('foreground-' + name, copy))
    for name, updates in [('button-depth', {'depth': 1}), ('button-behind', {'behindForeground': True})]:
        copy = deepcopy(scene)
        copy['devices']['desktop']['layers'].append({'id': name, 'type': 'button', 'label': 'Booking',
                                                    'url': 'booking/', **updates})
        cases.append((name, copy))
    return cases


def http_case(item):
    site, base, php = item['site'], item['base'], item['php']
    owner, guest, member = owner_login(base), session(), member_login(base, 'free')
    try:
        page = owner.get(base + '/admin/?page=composer', timeout=15)
        token = BeautifulSoup(page.text, 'html.parser').select_one('meta[name="csrf-token"]')['content']
        baseline = snapshot(site, php)
        for client, name in [(guest, 'guest'), (member, 'member-only')]:
            for method in ('get', 'post'):
                response_json(getattr(client, method)(route(base, 'api/scene.php?section=hero'), timeout=15),
                              401, name + ' scene ' + method)
        for headers in ({}, {'X-CSRF-Token': 'invalid'}):
            response_json(owner.post(route(base, 'api/scene.php?section=hero'), headers=headers,
                                     json={'action': 'publish', 'scene': {}}, timeout=15), 419, 'Scene CSRF refusal')
        response_json(owner.put(route(base, 'api/scene.php?section=hero'), headers={'X-CSRF-Token': token}, timeout=15),
                      405, 'Scene method refusal')
        response_json(owner.get(route(base, 'api/scene.php?section=unknown'), timeout=15), 404, 'Unknown scene refusal')
        unchanged(baseline, snapshot(site, php))
        cases = {}
        for section in ('hero', 'footer'):
            original = read_scene(owner, base, section)['scene']
            legacy = deepcopy(original)
            legacy['depthAcceptance'] = {'legacyMissingDepthFields': True}
            post_scene(owner, base, section, token, 'save', legacy)
            saved = snapshot(site, php)
            check(without_metadata(saved['scenes'][section]['draft']) == without_metadata(legacy),
                  'Legacy scenes acquired unsolicited depth fields or lost unrelated data.')
            scene = depth_scene(original, section)
            before = snapshot(site, php)
            post_scene(owner, base, section, token, 'save', scene)
            draft = snapshot(site, php)
            check(without_metadata(draft['scenes'][section]['draft']) == without_metadata(scene),
                  'Independent committed draft readback differs from valid depth/mask fields.')
            check(before['scenes'][section].get('published') == draft['scenes'][section].get('published')
                  and before['scenes'][section]['versions'] == draft['scenes'][section]['versions'],
                  'Saving depth changes published content or version history.')
            check(read_scene(owner, base, section)['scene'] == draft['scenes'][section]['draft'],
                  'The API does not read the independently committed scene.')
            published_result = post_scene(owner, base, section, token, 'publish', scene)
            version = published_result['versions'][0]['id']
            published = snapshot(site, php)
            check(without_metadata(published['scenes'][section]['published']) == without_metadata(scene)
                  and len(published['scenes'][section]['versions']) == len(before['scenes'][section]['versions']) + 1,
                  'Publish did not commit the complete scene and exactly one restorable version.')
            malformed = invalid_scenes(scene)
            for name, bad in malformed:
                for operation in ('save', 'publish'):
                    post_scene(owner, base, section, token, operation, bad, expected=422)
                    unchanged(published, snapshot(site, php))
            disabled = deepcopy(scene)
            disabled['devices']['desktop']['foreground']['enabled'] = False
            disabled['devices']['desktop']['foreground']['source'] = 'images/defaults/old-room.jpg'
            disabled['devices']['desktop']['layers'].append({'id': 'neutral-button', 'type': 'button',
                'label': 'Booking', 'url': 'booking/', 'depth': 0, 'behindForeground': False})
            post_scene(owner, base, section, token, 'save', disabled)
            disabled_saved = snapshot(site, php)['scenes'][section]['draft']
            check(disabled_saved['devices']['desktop']['foreground'] == disabled['devices']['desktop']['foreground'],
                  'Disabling a previous source mask lost its valid editable shape.')
            check(disabled_saved['devices']['desktop']['layers'][-1]['depth'] == 0
                  and disabled_saved['devices']['desktop']['layers'][-1]['behindForeground'] is False,
                  'Neutral explicit button depth fields were not accepted.')
            later = deepcopy(scene)
            del later['devices']['desktop']['foreground']
            later['devices']['mobile']['layers'][0]['depth'] = 9
            post_scene(owner, base, section, token, 'publish', later)
            check('foreground' not in snapshot(site, php)['scenes'][section]['published']['devices']['desktop'],
                  'Clear did not remove the saved foreground field.')
            restored = post_scene(owner, base, section, token, 'restore', version=version)
            after = snapshot(site, php)
            check(without_metadata(after['scenes'][section]['published']) == without_metadata(scene)
                  and without_metadata(restored['scene']) == without_metadata(scene),
                  'Version restore failed to recover depth, source-image vertices, hidden/locked layers and independent devices.')
            check(len(after['scenes'][section]['versions']) == len(published['scenes'][section]['versions']) + 2,
                  'Publish and restore did not each append one history version.')
            post_scene(owner, base, section, token, 'restore', version='not-a-version', expected=422)
            unchanged(after, snapshot(site, php))
            protected(baseline, after)
            cases[section] = {'malformed_cases': len(malformed), 'rejected_saves_and_publishes': len(malformed) * 2,
                              'independent_committed_readback': True, 'legacy_unchanged': True,
                              'disabled_mask_retained': True, 'clear_and_restore': True}
        final = snapshot(site, php)
        protected(baseline, final)
        check(item['capture'].connections == 0 and not item['capture'].payloads and not item['capture'].errors,
              'Scene actions contacted SMTP.')
        return {'mode': item['mode'], 'nested': bool(base.split(':', 2)[-1].count('/')),
                'mysql_version': final['mysql_version'], 'sections': cases,
                'unrelated_sha256': final['unrelated_sha256'], 'smtp_connections': item['capture'].connections}
    finally:
        owner.close()
        guest.close()
        member.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('file', 'mysql', 'both'), default='file')
    args = parser.parse_args()
    evidence = evidence_directory()
    summary = {'cases': {}, 'test_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest()}
    with tempfile.TemporaryDirectory(prefix='vvx-composer-depth-http-') as temporary:
        work = Path(temporary)
        archive, identity = prepare_package(work)
        summary.update(identity)
        for mode in ('file', 'mysql') if args.mode == 'both' else (args.mode,):
            for name, prefix in [('root', ''), ('nested', '/members/velvet')]:
                label = mode + '-' + name
                with runtime(work, archive, mode, 'depth-http-' + label, prefix, evidence) as item:
                    summary['cases'][label] = http_case(item)
        summary['assertions'] = checks
        if evidence:
            (evidence / 'composer-depth-http-evidence.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary, indent=2))


if __name__ == '__main__':
    main()
