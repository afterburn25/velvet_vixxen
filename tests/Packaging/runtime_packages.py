#!/usr/bin/env python3
"""Exercise real distributions and upgrade overlays against disposable state."""
from __future__ import annotations

import importlib.util
import json
import shutil
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("velvet_package", ROOT / "scripts/package.py")
package = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(package)


def run() -> None:
    with tempfile.TemporaryDirectory(prefix="vvx-package-test-") as temporary:
        work = Path(temporary)
        source = work / "source"
        source.mkdir()
        for relative, content in package.runtime_files(ROOT, "fresh").items():
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)

        protected = {
            "config/app.php": b"<?php return ['installed'=>true,'installation_id'=>'test-installation'];\n",
            "storage/install.lock": b'{"installed":true,"installation_id":"test-installation"}\n',
            "storage/data.json": b'{"fixture":"existing member and order data"}\n',
            "storage/private/member-proof.txt": b"PRIVATE FIXTURE MUST NEVER SHIP\n",
            "storage/logs/production.log": b"PRIVATE LOG FIXTURE\n",
            "storage/sessions/session-test": b"PRIVATE SESSION FIXTURE\n",
            "uploads/images/customer-photo.bin": b"UNIQUE UPLOAD FIXTURE\n",
            "config/app.php.previous": b"PREVIOUS CONFIG FIXTURE\n",
        }
        for relative, content in protected.items():
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)

        version = (source / "VERSION").read_text().strip()
        first = package.build(source, work / "first", version)
        second = package.build(source, work / "second", version)
        releases = {release["kind"]: release for release in first["packages"]}
        assert set(releases) == {"fresh", "upgrade", "installer-repair"}, "A required 0.8.1 runtime distribution is missing"
        for release in first["packages"]:
            archive = work / "first" / release["filename"]
            assert archive.read_bytes() == (work / "second" / release["filename"]).read_bytes(), "Package is not reproducible"
            with ZipFile(archive) as zipped:
                entries = set(zipped.namelist())
                assert not entries.intersection(protected), "Installed/private state was packaged"
                assert not any(name.startswith((".github/", "docs/", "tests/", "scripts/", "addons/sdk/")) for name in entries), "Development files entered runtime ZIP"
                assert zipped.testzip() is None, "Archive integrity failure"
                assert all(not Path(name).is_absolute() and ".." not in Path(name).parts for name in entries), "Unsafe archive path"
                assert all(entry.create_system == 3 and entry.external_attr >> 16 == 0o100644 for entry in zipped.infolist()), "Runtime ZIP entries must be regular files with safe 0644 permissions"
                if release["kind"] == "fresh":
                    assert "config/app.example.php" in entries and "install/index.php" in entries
                elif release["kind"] == "upgrade":
                    assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS for name in entries)
                    installed = work / "installed"
                    installed.mkdir()
                    for relative, content in protected.items():
                        target = installed / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(content)
                    zipped.extractall(installed)
                    assert all((installed / relative).read_bytes() == content for relative, content in protected.items()), "Upgrade altered installed state"
                    assert not (installed / "install").exists(), "Upgrade reintroduced installer"
                    assert (installed / "VERSION").read_text().strip() == version
                    lock = json.loads((installed / "storage/install.lock").read_text())
                    assert lock["installed"] and lock["installation_id"] == "test-installation"
                else:
                    service_path = "install/services/FileSystemService.php"
                    assert entries == {service_path}, "Installer repair must contain exactly the corrected filesystem service"
                    assert release["filename"] == "Nikki-Wilder-Velvet-Vixxen-0.8.1-Installer-Folder-Repair.zip"
                    assert release["files"] == release["php_files"] == 1 and release["javascript_files"] == 0
                    metadata = json.loads(zipped.comment)
                    assert metadata["kind"] == "installer-repair" and metadata["version"] == "0.8.1"
                    assert metadata["supported_from"] == ["0.8.1"] and metadata["installation_state"] == "incomplete", "Installer repair compatibility must stay limited to an unfinished 0.8.1 installation"
                    assert release["supported_from"] == ["0.8.1"] and release["installation_state"] == "incomplete"

                    # Apply the small repair to an already extracted, unfinished
                    # installation. Every other file must remain byte-identical.
                    incomplete = work / "incomplete"
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
                        current_service = fresh.read(service_path)
                        assert zipped.read(service_path) == current_service, "Repair service differs from the complete fresh package"
                        fresh.extractall(incomplete)
                    unfinished_state = {
                        "config/app.php": b"<?php return ['installed'=>false,'fixture'=>'unfinished setup'];\n",
                        "storage/temp/installer-fixture.json": b'{"stage":"filesystem","fixture":true}\n',
                        "uploads/images/owner-cutout.bin": b"PRIVATE UNFINISHED-INSTALL UPLOAD FIXTURE\n",
                        "database/backups/preserved-fixture.bin": b"PRE-EXISTING BACKUP FIXTURE\n",
                    }
                    for relative, content in unfinished_state.items():
                        target = incomplete / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(content)
                    (incomplete / service_path).write_bytes(b"<?php /* prior installer filesystem service fixture */\n")
                    before = {path.relative_to(incomplete).as_posix(): path.read_bytes() for path in incomplete.rglob("*") if path.is_file()}
                    assert "install/index.php" in before and "install/services/RequirementChecker.php" in before
                    zipped.extractall(incomplete)
                    after = {path.relative_to(incomplete).as_posix(): path.read_bytes() for path in incomplete.rglob("*") if path.is_file()}
                    expected = dict(before)
                    expected[service_path] = current_service
                    assert after == expected, "Installer repair changed existing configuration, storage, uploads, application assets, database files, or other installer files"
                    assert not (incomplete / "storage/install.lock").exists(), "Repair unexpectedly installed or locked the unfinished site"

        repair_files = {"install/services/FileSystemService.php": (source / "install/services/FileSystemService.php").read_bytes()}
        for files, repair_version in [
            ({}, version),
            ({**repair_files, "install/index.php": b"<?php /* extra file */\n"}, version),
            (repair_files, "0.8.0"),
            (repair_files, "0.8.2"),
        ]:
            try:
                package.validate(files, "installer-repair", repair_version)
            except ValueError:
                pass
            else:
                raise AssertionError("Invalid installer repair contents or version were accepted")

        # Refuse a source symlink even when the target would otherwise ship.
        outside = work / "outside.php"
        outside.write_text("<?php /* fixture outside source */\n")
        link = source / "app" / "unsafe.php"
        link.symlink_to(outside)
        try:
            package.build(source, work / "unsafe", version)
        except ValueError as error:
            assert "symlink" in str(error)
        else:
            raise AssertionError("Symlinked source was accepted")
    print("Runtime packages passed: clean fresh install, protected upgrade overlay, exact one-file unfinished-installer repair, safe 0644 entries, reproducibility, archive integrity, and symlink rejection.")


if __name__ == "__main__":
    run()
