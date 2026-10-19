#!/usr/bin/env python3
"""Verify a real repair ZIP invalidates cached PHP after timestamp-preserving unzip."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
SERVICE = 'install/services/FileSystemService.php'
LEGACY_TIMESTAMP = (2026, 1, 1, 0, 0, 0)
# Small explicit cached-code marker, not a second copy of production source.
LEGACY_FIXTURE = b'''<?php
declare(strict_types=1);
namespace Velvet\\Installer;
final class FileSystemService
{
    public const LEGACY_OVERLAY_FIXTURE = true;
    public function directoryManifest(): array
    {
        return ['images/backgrounds' => ['required' => true, 'writable' => false]];
    }
}
'''


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def run() -> None:
    php = shutil.which('php')
    unzip = shutil.which('unzip')
    require(php is not None and unzip is not None,
            'PHP with OPcache and unzip are required; no skipped cache regression is permitted.')
    spec = importlib.util.spec_from_file_location('velvet_package_opcache', ROOT / 'scripts/package.py')
    require(spec is not None and spec.loader is not None, 'Package builder is unavailable.')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)

    with tempfile.TemporaryDirectory(prefix='vvx-opcache-package-') as temporary:
        work = Path(temporary)
        site = work / 'site'
        site.mkdir()
        (work / '.vvx-opcache-fixture').write_text('Disposable localhost OPcache regression.\n')
        shutil.copyfile(Path(__file__).with_suffix('.php'), site / 'index.php')
        built = package.build(ROOT, work / 'packages', (ROOT / 'VERSION').read_text().strip())
        repair = next(entry for entry in built['packages'] if entry['kind'] == 'installer-repair')
        repair_zip = work / 'packages' / repair['filename']
        with ZipFile(repair_zip) as zipped:
            require(set(zipped.namelist()) == package.INSTALLER_REPAIR_FILES and SERVICE in zipped.namelist(),
                    'Installer repair must contain the reviewed dependencies, including the repaired filesystem service.')
            corrected = zipped.read(SERVICE)
            repair_timestamp = zipped.getinfo(SERVICE).date_time
        require(corrected == (ROOT / SERVICE).read_bytes(), 'Repair ZIP did not contain current executable source.')
        require(repair_timestamp > LEGACY_TIMESTAMP, 'Repair component ZIP timestamp must advance past the cached original.')
        corrected_hash = hashlib.sha256(corrected).hexdigest()

        def fixture_zip(name: str, payload: bytes) -> Path:
            destination = work / name
            with ZipFile(destination, 'w', compression=ZIP_DEFLATED) as zipped:
                entry = ZipInfo(SERVICE, date_time=LEGACY_TIMESTAMP)
                entry.create_system = 3
                entry.external_attr = 0o100644 << 16
                zipped.writestr(entry, payload)
            return destination

        def extract(archive: Path) -> None:
            # Actual unzip preserves DOS/ZIP timestamps, as hosting extraction
            # can do. ZipFile.extractall alone would hide this bug with new mtimes.
            subprocess.run([unzip, '-oq', str(archive), '-d', str(site)], check=True, timeout=15)

        extract(fixture_zip('cached-legacy.zip', LEGACY_FIXTURE))
        old_mtime = int((site / SERVICE).stat().st_mtime)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        environment = os.environ.copy()
        environment.pop('PHP_CLI_SERVER_WORKERS', None)
        with (work / 'php-server.log').open('w+') as log:
            process = subprocess.Popen([
                php, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0',
                '-d', 'opcache.file_update_protection=0',
                '-S', f'127.0.0.1:{port}', '-t', str(site),
            ], cwd=work, env=environment, stdout=log, stderr=log)
            localhost = urllib.request.build_opener(urllib.request.ProxyHandler({}))

            def request() -> dict:
                with localhost.open(f'http://127.0.0.1:{port}/', timeout=5) as response:
                    return json.load(response)

            try:
                deadline = time.monotonic() + 10
                while True:
                    if process.poll() is not None:
                        log.seek(0)
                        raise RuntimeError('PHP HTTP fixture exited: ' + log.read())
                    try:
                        request()
                        break
                    except urllib.error.HTTPError as error:
                        raise RuntimeError('PHP OPcache fixture failed: ' + error.read().decode(errors='replace')) from error
                    except urllib.error.URLError:
                        if time.monotonic() >= deadline:
                            raise RuntimeError('PHP HTTP fixture did not become ready.')
                        time.sleep(0.025)

                warm = request()
                require(warm['opcache_enabled'] and warm['cached'] and warm['cache_hits'] >= 1,
                        'The fixture did not execute a genuinely cached PHP class.')
                require(warm['legacy_fixture'] and warm['old_manifest'] and not warm['hero_manifest'],
                        'The original cached marker was not active.')
                require(warm['validate_timestamps'] == '1' and warm['revalidate_freq'] == '0',
                        'Timestamp validation must be active on every request.')
                require(warm['file_mtime'] == old_mtime and warm['cache_timestamp'] == old_mtime,
                        'The fixture did not cache the extracted original timestamp.')

                # Negative control: corrected bytes with the old timestamp must
                # remain stale, proving this fixture exercises the delivery risk.
                extract(fixture_zip('same-timestamp-control.zip', corrected))
                stale = [request() for _ in range(3)]
                require(all(row['pid'] == warm['pid'] and row['cached'] and row['legacy_fixture']
                            and row['old_manifest'] and not row['hero_manifest'] for row in stale),
                        'Same-timestamp negative control did not reproduce stale cached execution.')
                require(all(row['source_sha256'] == corrected_hash and row['file_size'] != warm['file_size']
                            and row['file_mtime'] == old_mtime and row['cache_timestamp'] == old_mtime
                            for row in stale), 'The negative control did not preserve mtime while replacing source bytes.')
                require(stale[-1]['cache_hits'] > stale[0]['cache_hits'] >= 2,
                        'Negative control did not reuse the cached original class across requests.')

                # Positive: use the real customer repair ZIP, with no cache reset,
                # process restart, explicit invalidation, or source modifications.
                extract(repair_zip)
                new_mtime = int((site / SERVICE).stat().st_mtime)
                expected_mtime = int(time.mktime((*repair_timestamp, 0, 0, -1)))
                require(new_mtime == expected_mtime and new_mtime > old_mtime,
                        'Actual repair extraction did not preserve its advanced ZIP timestamp.')
                refreshed = [request() for _ in range(2)]
                require(all(row['pid'] == warm['pid'] and row['opcache_enabled'] and row['cached']
                            and not row['legacy_fixture'] and not row['old_manifest'] and row['hero_manifest']
                            for row in refreshed), 'Actual repair ZIP did not load corrected code in the same PHP process.')
                require(all(row['source_sha256'] == corrected_hash and row['file_mtime'] == new_mtime
                            and row['cache_timestamp'] == new_mtime for row in refreshed),
                        'The repaired cache did not correspond to the exact delivered source and timestamp.')
                print('PASS packaged OPcache repair: real cached legacy class; same-mtime corrected bytes remain stale; actual repair ZIP advances mtime and loads corrected manifest on the next HTTP request without restarting PHP.')
                print(json.dumps({'php_version': warm['php_version'], 'repair_sha256': repair['sha256'],
                                  'legacy_zip_timestamp': LEGACY_TIMESTAMP, 'repair_zip_timestamp': repair_timestamp,
                                  'negative_control_requests': len(stale), 'corrected_requests': len(refreshed)}))
            finally:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)


if __name__ == '__main__':
    run()
