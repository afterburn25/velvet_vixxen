#!/usr/bin/env python3
"""Probe the real fresh ZIP as PHP under a different owner from static assets.

Linux CI may use passwordless sudo for this isolated permission fixture. Running
PHP as root, or chmodding same-owner fixtures to 0555, cannot reproduce this bug:
the old installer changes those permissions back. Refuse an ineffective fixture.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import pwd
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
REPORTED_PATHS = {
    'images/backgrounds', 'images/branding', 'images/gallery-covers',
    'images/icons', 'images/placeholders', 'public/assets/fonts',
    'public/assets/icons', 'templates/email', 'templates/visual-composer',
}
WRITABLE_ROOTS = {'addons', 'config', 'database', 'storage', 'uploads'}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def snapshot(root: Path) -> dict[str, tuple[object, ...]]:
    """Compare paths, bytes, modes and ownership; ignore legitimate private writes."""
    entries = {}
    for path in sorted(root.rglob('*')):
        relative = path.relative_to(root)
        if relative.parts[0] in WRITABLE_ROOTS or relative.parts[0].startswith('.vvx-private-repair-'):
            continue
        metadata = path.lstat()
        require(not path.is_symlink(), f'Unexpected fixture symlink: {relative}')
        digest = hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None
        entries[relative.as_posix()] = (stat.S_IMODE(metadata.st_mode), metadata.st_uid, metadata.st_gid, digest)
    return entries


def assign_tree(path: Path, uid: int, gid: int) -> None:
    for child in [path, *path.rglob('*')]:
        require(not child.is_symlink(), f'Refusing permission changes through a link: {child.name}')
        try:
            os.chown(child, uid, gid)
        except OSError as error:
            raise RuntimeError('Cannot establish the foreign-owner fixture. A permission-capable Linux environment is required; root inside a single-UID sandbox is insufficient.') from error
        os.chmod(child, 0o755 if child.is_dir() else 0o644)


def run(args: argparse.Namespace) -> None:
    require(os.geteuid() == 0, 'Foreign ownership fixture requires root or passwordless sudo; PHP itself must run unprivileged.')
    nobody = pwd.getpwnam('nobody')
    require(nobody.pw_uid != 0 and nobody.pw_gid != 0, 'A real unprivileged fixture identity is required.')

    spec = importlib.util.spec_from_file_location('velvet_package_preflight', ROOT / 'scripts/package.py')
    require(spec is not None and spec.loader is not None, 'Package builder is unavailable.')
    package = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package)

    with tempfile.TemporaryDirectory(prefix='vvx-packaged-preflight-') as temporary:
        work = Path(temporary)
        os.chmod(work, 0o755)
        (work / '.vvx-packaged-preflight-fixture').write_text('Disposable packaged-installer permission regression.\n')
        helper = work / 'probe.php'
        shutil.copyfile(Path(__file__).with_suffix('.php'), helper)
        os.chmod(helper, 0o644)
        php_temp = work / 'php-temp'
        php_temp.mkdir()
        assign_tree(php_temp, nobody.pw_uid, nobody.pw_gid)

        source = ROOT
        if args.legacy_filesystem:
            # The captured prior service enters the package BEFORE the real ZIP
            # build. No installed source or user-supplied path is modified.
            legacy = args.legacy_filesystem.read_bytes()
            require(b'final class FileSystemService' in legacy, 'Legacy input is not a FileSystemService source file.')
            source = work / 'legacy-package-source'
            source.mkdir()
            for relative, payload in package.runtime_files(ROOT, 'fresh').items():
                destination = source / relative
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(payload)
            (source / 'install/services/FileSystemService.php').write_bytes(legacy)
            # Historical reproduction includes the original archive metadata.
            # Change only this in-memory legacy build; corrected builds retain
            # the later component timestamp required for OPcache invalidation.
            package.INSTALLER_FILESYSTEM_TIMESTAMP = (2026, 1, 1, 0, 0, 0)

        version = (source / 'VERSION').read_text().strip()
        built = package.build(source, work / 'packages', version)
        fresh = next(entry for entry in built['packages'] if entry['kind'] == 'fresh')
        archive = work / 'packages' / fresh['filename']
        with ZipFile(archive) as zipped:
            require(zipped.testzip() is None, 'The fresh ZIP failed integrity verification.')
            require(not any(name.startswith(('tests/', 'docs/', 'scripts/', '.github/')) for name in zipped.namelist()),
                    'Engineering files entered the runtime ZIP.')
            require(not any(name.rstrip('/') in REPORTED_PATHS for name in zipped.namelist()),
                    'Unused scaffolding was added to the customer ZIP to hide the installer bug.')

        def fixture(name: str, *, block_writes: bool = False, missing: tuple[str, ...] = ()) -> Path:
            root = work / name
            root.mkdir()
            with ZipFile(archive) as zipped:
                zipped.extractall(root)
            for relative in missing:
                require((root / relative).is_dir(), f'The package did not contain the real static fixture: {relative}')
                shutil.rmtree(root / relative)
            assign_tree(root, 0, 0)
            if not block_writes:
                for relative in WRITABLE_ROOTS:
                    require((root / relative).is_dir(), f'Packaged runtime root missing: {relative}')
                    assign_tree(root / relative, nobody.pw_uid, nobody.pw_gid)
            return root

        def invoke(root: Path, action: str = 'probe') -> dict:
            def drop_privileges() -> None:
                os.setgroups([])
                os.setgid(nobody.pw_gid)
                os.setuid(nobody.pw_uid)
                os.umask(0o022)

            result = subprocess.run([
                args.php, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                '-d', f'sys_temp_dir={php_temp}', str(helper), str(root), action,
            ], cwd=work, text=True, capture_output=True, preexec_fn=drop_privileges, timeout=60)
            require(result.returncode == 0, f'Unprivileged package probe failed ({root.name}): {result.stderr.strip()}')
            require(not result.stderr.strip(), f'PHP emitted unexpected diagnostics ({root.name}): {result.stderr.strip()}')
            return json.loads(result.stdout)

        extracted = fixture('fresh-extracted')
        manifest = invoke(extracted, 'manifest')
        before = snapshot(extracted)
        for attempt in range(1, 4):
            result = invoke(extracted)
            failures = {row['path']: row for row in result['rows'] if row['status'] == 'fail'}
            if args.legacy_filesystem:
                require(not result['ready'] and set(failures) == REPORTED_PATHS,
                        'Legacy installer did not reproduce exactly the nine reported failures: ' + json.dumps(failures))
                require(all(row['message'] == 'Directory could not be created.' and row['mode'] == '----'
                            for row in failures.values()), 'Legacy failure messages differ from the customer report.')
            else:
                require(result['ready'] and not failures,
                        f'Packaged fresh installer failed initial probe/recheck {attempt}: ' + json.dumps(failures))
                require(not REPORTED_PATHS.intersection(manifest), 'Unused static scaffolding still blocks the installer.')
                require('database/seeds' not in manifest, 'Unused database seed scaffolding remains required.')
                require(all(row['status'] == 'pass' and row['required'] for row in result['rows']),
                        'A package requirement was silently skipped or downgraded.')
                require(all(row['mode'] in {'0755', '0775'} for row in result['rows']),
                        'Installer produced unexpected or unsafe directory modes.')
            require(snapshot(extracted) == before,
                    f'Static assets, modes or ownership changed during initial probe/recheck {attempt}.')
            require(all(not (extracted / relative).exists() for relative in REPORTED_PATHS),
                    'Installer created unused static directories.')

        if args.legacy_filesystem:
            print('PASS legacy reproduction: the packaged prior installer fails exactly all nine reported paths on initial probe and two rechecks; static bytes/modes/ownership unchanged.')
            print(json.dumps({'legacy_service_sha256': hashlib.sha256(args.legacy_filesystem.read_bytes()).hexdigest(),
                              'fresh_package_sha256': fresh['sha256'], 'failures': sorted(REPORTED_PATHS)}))
            return

        denied = fixture('blocked-runtime', block_writes=True)
        denied_before = snapshot(denied)
        result = invoke(denied)
        rows = {row['path']: row for row in result['rows']}
        require(not result['ready'], 'Unwritable runtime directories incorrectly passed the install gate.')
        require(all(rows[path]['status'] == 'fail' and rows[path]['required'] and not rows[path]['writable']
                    for path in WRITABLE_ROOTS), 'A required runtime write failure was ignored.')
        require(all(row['status'] == 'pass' for row in rows.values() if not manifest[row['path']]['writable']),
                'Valid static directories incorrectly failed when private runtime writes were blocked.')
        require(snapshot(denied) == denied_before, 'Blocked runtime repair changed static files or permissions.')

        repairable = fixture('repairable-runtime', block_writes=True)
        os.chown(repairable, nobody.pw_uid, nobody.pw_gid)
        sentinels = {}
        for relative in sorted(WRITABLE_ROOTS):
            path = repairable / relative / 'retained-fixture.txt'
            sentinels[path] = ('Preserve this synthetic ' + relative + ' fixture during ownership repair.\n').encode()
            path.write_bytes(sentinels[path])
            os.chmod(path, 0o644)
        repair_before = snapshot(repairable)
        result = invoke(repairable)
        rows = {row['path']: row for row in result['rows']}
        require(result['ready'] and all(row['status'] == 'pass' for row in rows.values()),
                'Existing runtime ownership repair failed: ' + json.dumps(rows))
        require(all('Ownership mismatch repaired automatically' in rows[path]['message'] for path in WRITABLE_ROOTS),
                'The positive fixture did not exercise every allowed runtime ownership repair.')
        require(all(path.read_bytes() == payload and path.stat().st_uid == nobody.pw_uid
                    for path, payload in sentinels.items()), 'Ownership repair lost or failed to copy retained runtime data.')
        require(snapshot(repairable) == repair_before, 'Runtime ownership repair altered static bytes/modes/owners.')

        missing_migrations = fixture('repair-missing-migrations', block_writes=True, missing=('database/migrations',))
        os.chown(missing_migrations, nobody.pw_uid, nobody.pw_gid)
        retained = missing_migrations / 'database/retained-fixture.txt'
        retained.write_bytes(b'Keep this exact original database tree when the package is incomplete.\n')
        os.chmod(retained, 0o644)
        database_before = snapshot(missing_migrations / 'database')
        database_inode = (missing_migrations / 'database').stat().st_ino
        missing_before = snapshot(missing_migrations)
        result = invoke(missing_migrations)
        rows = {row['path']: row for row in result['rows']}
        require(not result['ready'] and rows['database']['status'] == 'fail'
                and 'The original directory was restored.' in rows['database']['message'],
                'Incomplete database source did not fail ownership repair with original-tree rollback.')
        require(rows['database/migrations']['status'] == 'fail' and not (missing_migrations / 'database/migrations').exists(),
                'Ownership repair manufactured empty missing migration source.')
        require((missing_migrations / 'database').stat().st_ino == database_inode
                and snapshot(missing_migrations / 'database') == database_before,
                'Failed ownership repair did not restore the exact original database tree and retained contents.')
        require(snapshot(missing_migrations) == missing_before, 'Failed runtime ownership repair altered static assets.')

        removed = ('images/hero', 'public/assets/css', 'templates/canva')
        incomplete = fixture('incomplete-extraction', missing=removed)
        incomplete_before = snapshot(incomplete)
        result = invoke(incomplete)
        failures = {row['path']: row for row in result['rows'] if row['status'] == 'fail'}
        expected_missing = {path for path in manifest if any(path == missing or path.startswith(missing + '/') for missing in removed)}
        require(not result['ready'] and set(failures) == expected_missing,
                'Missing real assets did not block installation at the expected paths: ' + json.dumps(failures))
        require(all(row['required'] for row in failures.values()), 'Missing real static assets became optional.')
        require(all(not (incomplete / relative).exists() for relative in removed),
                'Installer created empty directories in place of missing real package assets.')
        require(snapshot(incomplete) == incomplete_before, 'Incomplete extraction probe changed static contents.')

        print('PASS packaged installer: real fresh ZIP, foreign-owner 0755 static assets, initial probe plus two rechecks, unchanged asset hashes/modes/owners, runtime ownership repair preserves data, incomplete repair restores originals, required write failures and missing runtime assets block installation.')
        print(json.dumps({'fresh_package_sha256': fresh['sha256'], 'runtime_files': fresh['files'],
                          'php_uid': nobody.pw_uid, 'static_owner_uid': 0,
                          'read_only_parents': ['images', 'public/assets', 'templates']}))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--legacy-filesystem', type=Path,
                        help='Read-only captured prior service; prove its actual packaged preflight fails at the nine customer paths.')
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--root-worker', action='store_true', help=argparse.SUPPRESS)
    args = parser.parse_args()
    if not args.php or not Path(args.php).is_file():
        raise SystemExit('PHP is required for the packaged-installer regression.')
    args.php = str(Path(args.php).resolve())
    if args.legacy_filesystem:
        args.legacy_filesystem = args.legacy_filesystem.resolve(strict=True)
        if not args.legacy_filesystem.is_file():
            raise SystemExit('Legacy source must be a file; it will only be read.')
    if os.geteuid() != 0:
        sudo = shutil.which('sudo')
        if args.root_worker or not sudo or subprocess.run([sudo, '-n', 'true'], capture_output=True).returncode != 0:
            raise SystemExit('Cannot establish real foreign ownership. Run this isolated test with root or passwordless sudo; no passing fallback or skip is permitted.')
        # Ubuntu GitHub runners provide passwordless sudo. Only the temporary
        # fixture orchestrator is elevated; every actual PHP probe drops to nobody.
        command = [sudo, '-n', sys.executable, str(Path(__file__).resolve()), '--root-worker', '--php', args.php]
        if args.legacy_filesystem:
            command += ['--legacy-filesystem', str(args.legacy_filesystem)]
        raise SystemExit(subprocess.run(command, check=False).returncode)
    run(args)


if __name__ == '__main__':
    main()
