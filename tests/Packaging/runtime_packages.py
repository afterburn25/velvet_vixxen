#!/usr/bin/env python3
"""Exercise real distributions and upgrade overlays against disposable state."""
from __future__ import annotations

import importlib.util
import hashlib
import json
import shutil
import tempfile
from pathlib import Path
from unittest.mock import patch
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("velvet_package", ROOT / "scripts/package.py")
package = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(package)

REPAIR_FILES = {
    "app/Core/MigrationRunner.php", "app/Core/MigrationResumeSchema.php",
    "includes/installation_recovery.php",
    "install/services/FileSystemService.php", "install/services/InstallerService.php",
    "install/views/single-page.php",
    "public/assets/css/installer.css",
    "public/assets/js/installer.js",
}
PUBLIC_ROUTE_FILES = {
    "about/index.php", "account/billing.php", "account/forgot.php", "account/index.php",
    "account/library.php", "account/login.php", "account/logout.php", "account/register.php",
    "account/reset.php", "account/security.php", "account/verify.php",
    "api/addon-license.php", "api/marketing-event.php", "api/member-state.php",
    "api/payment-webhook.php", "api/scene.php", "api/upload.php",
    "blog/index.php", "blog/post.php", "booking/index.php", "campaign/index.php",
    "cart/index.php", "checkout/index.php", "checkout/success.php", "collections/index.php",
    "communication/download.php", "create-account/index.php", "extension/index.php",
    "join/index.php", "links/index.php", "login/index.php", "media.php",
    "membership/index.php", "messages/index.php", "music/index.php", "newsletter/confirm.php",
    "newsletter/index.php", "newsletter/unsubscribe.php", "photos/index.php", "photos/view.php",
    "receipt/index.php", "recover/index.php", "requests/index.php", "shop/index.php",
    "videos/index.php", "videos/watch.php",
}
COMPOSER_REPAIR_FILES = {
    *PUBLIC_ROUTE_FILES, "admin/index.php", "admin/update.php", "public/router.php",
    "app/Core/SceneButton.php", "app/Core/Store.php", "app/Core/RepairBackupService.php",
    "app/Core/RuntimeBuildService.php", "app/Support/functions.php",
    "app/Views/admin/composer.php", "app/Views/admin/media-library.php",
    "app/Views/admin/navigation.php", "app/Views/admin/update-status.php",
    "app/Views/admin/marketing.php", "includes/marketing.php", "includes/marketing_public_shell.php",
    "includes/version.php", "includes/runtime-build.json", "public/routes/links/index.php", "public/routes/campaign/index.php",
    "public/assets/css/site.css", "public/assets/css/composer.css",
    "public/assets/js/api-client.js", "public/assets/js/admin.js",
    "public/assets/js/composer.js", "public/assets/js/site.js", "public/assets/js/marketing.js",
}
BUILD_ID = "20261019.5"
RUNTIME_BUILD_PATH = "includes/runtime-build.json"
VERIFIED_BASELINES = [
    "e5b04daa132521be69c943151f8112c7cd2017e8",
    "db759342e401617c9933c13b81a147ad9f8fd515",
]
LEGACY_TIMESTAMP = (2026, 1, 1, 0, 0, 0)
REPAIR_TIMESTAMPS = {
    relative: ((2026, 10, 19, 0, 0, 0) if relative == "install/services/FileSystemService.php"
               else (2026, 10, 19, 16, 0, 0))
    for relative in REPAIR_FILES
}
REPAIR_TIMESTAMPS.update({
    relative: (2026, 10, 19, 19, 30, 0) for relative in COMPOSER_REPAIR_FILES
})


def metadata(entry) -> tuple:
    return entry.date_time, entry.external_attr, entry.create_system, entry.compress_type


def run() -> None:
    with tempfile.TemporaryDirectory(prefix="vvx-package-test-") as temporary:
        work = Path(temporary)
        source = work / "source"
        source.mkdir()
        for relative, content in package.runtime_files(ROOT, "fresh").items():
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
        stale_inventory = b'{"fixture":"STALE INVENTORY MUST BE REGENERATED"}\n'
        (source / RUNTIME_BUILD_PATH).write_bytes(stale_inventory)

        protected = {
            "config/app.php": b"<?php return ['installed'=>true,'installation_id'=>'test-installation'];\n",
            "storage/install.lock": b'{"installed":true,"installation_id":"test-installation"}\n',
            "storage/data.json": b'{"fixture":"existing member and order data"}\n',
            "storage/private/member-proof.txt": b"PRIVATE FIXTURE MUST NEVER SHIP\n",
            "storage/logs/production.log": b"PRIVATE LOG FIXTURE\n",
            "storage/sessions/session-test": b"PRIVATE SESSION FIXTURE\n",
            "uploads/images/customer-photo.bin": b"UNIQUE UPLOAD FIXTURE\n",
            "config/app.php.previous": b"PREVIOUS CONFIG FIXTURE\n",
            "database/backups/customer-data.sql": b"PRIVATE DATABASE BACKUP FIXTURE\n",
        }
        for relative, content in protected.items():
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)

        version = (source / "VERSION").read_text().strip()
        first = package.build(source, work / "first", version)
        second = package.build(source, work / "second", version)
        with patch.object(package, "INSTALLER_FILESYSTEM_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "INSTALLER_DATABASE_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "COMPOSER_REPAIR_TIMESTAMP", LEGACY_TIMESTAMP):
            legacy_dates = package.build(source, work / "legacy-dates", version)
        releases = {release["kind"]: release for release in first["packages"]}
        assert first["build_id"] == BUILD_ID == second["build_id"], "Package build identity is missing or ambiguous"
        assert first["manifest_filename"] == f"Velvet-Vixxen-{version}-Build-{BUILD_ID}-package-manifest.json"
        assert json.loads((work / "first" / first["manifest_filename"]).read_text()) == first, "Separate manifest differs from the returned build inventory"
        assert (source / RUNTIME_BUILD_PATH).read_bytes() == stale_inventory, "Packaging wrote generated state back into its source checkout"
        assert set(releases) == {"fresh", "upgrade", "installer-repair", "composer-repair"}, "A required 0.8.1 runtime distribution is missing"
        assert package.INSTALLER_REPAIR_FILES == REPAIR_FILES, "Installer repair dependencies differ from the reviewed allowlist"
        assert package.PUBLIC_ROUTE_FILES == PUBLIC_ROUTE_FILES, "Physical public entry points differ from the reviewed compatibility scope"
        assert package.COMPOSER_REPAIR_FILES == COMPOSER_REPAIR_FILES, "Media and Composer repair dependencies differ from the reviewed allowlist"
        with ZipFile(work / "first" / releases["upgrade"]["filename"]) as upgrade:
            expected_inventory = {
                "schema": 1, "product": "Velvet Vixxen", "version": version, "build_id": BUILD_ID,
                "files": {
                    name: hashlib.sha256(upgrade.read(name)).hexdigest()
                    for name in sorted(upgrade.namelist()) if name != RUNTIME_BUILD_PATH
                },
            }
            inventory_bytes = upgrade.read(RUNTIME_BUILD_PATH)
            assert json.loads(inventory_bytes) == expected_inventory, "Runtime inventory does not describe the complete actual Upgrade ZIP"
            assert inventory_bytes != stale_inventory, "A stale source inventory entered the package"
            assert len(expected_inventory["files"]) > len(COMPOSER_REPAIR_FILES), "Repair-only hashes cannot verify an unknown installed baseline"
            assert "app/Core/UploadService.php" in expected_inventory["files"], "Inventory omitted an unchanged upload dependency"
            assert RUNTIME_BUILD_PATH not in expected_inventory["files"], "Runtime inventory cannot hash itself"
            assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS or name.startswith("registry/")
                           for name in expected_inventory["files"]), "Runtime inventory includes installed or private state"
        for release in first["packages"]:
            archive = work / "first" / release["filename"]
            assert archive.read_bytes() == (work / "second" / release["filename"]).read_bytes(), "Package is not reproducible"
            with ZipFile(archive) as zipped:
                entries = set(zipped.namelist())
                compatibility = json.loads(zipped.comment)
                if release["kind"] != "installer-repair":
                    assert release["build_id"] == compatibility["build_id"] == BUILD_ID
                    assert f"-{version}-Build-{BUILD_ID}-" in release["filename"], "Customer ZIP filename hides its actual build"
                    assert zipped.read(RUNTIME_BUILD_PATH) == inventory_bytes, "Fresh/Upgrade/Repair inventories must have identical bytes"
                    assert f"const VELVET_VIXXEN_BUILD_ID = '{BUILD_ID}';".encode() in zipped.read("includes/version.php")
                    assert f"const VELVET_VIXXEN_ASSET_VERSION = '{version}-{BUILD_ID}';".encode() in zipped.read("includes/version.php")
                else:
                    assert RUNTIME_BUILD_PATH not in entries and "build_id" not in compatibility and "build_id" not in release, "Installer-only repair was mislabeled as a new complete runtime build"
                assert not entries.intersection(protected), "Installed/private state was packaged"
                assert not any(name.startswith((".github/", "docs/", "tests/", "scripts/", "addons/sdk/")) for name in entries), "Development files entered runtime ZIP"
                assert zipped.testzip() is None, "Archive integrity failure"
                assert all(not Path(name).is_absolute() and ".." not in Path(name).parts for name in entries), "Unsafe archive path"
                assert all(entry.create_system == 3 and entry.external_attr >> 16 == 0o100644 for entry in zipped.infolist()), "Runtime ZIP entries must be regular files with safe 0644 permissions"
                with ZipFile(work / "legacy-dates" / release["filename"]) as previous:
                    assert zipped.comment == previous.comment and zipped.namelist() == previous.namelist(), "Timestamp correction changed package metadata or scope"
                    for entry in zipped.infolist():
                        old = previous.getinfo(entry.filename)
                        assert zipped.read(entry) == previous.read(old), "Timestamp correction changed runtime file content"
                        assert (entry.external_attr, entry.create_system, entry.compress_type) == (old.external_attr, old.create_system, old.compress_type), "Timestamp correction changed runtime file permissions or archive format"
                        if entry.filename in REPAIR_TIMESTAMPS:
                            assert entry.date_time == REPAIR_TIMESTAMPS[entry.filename] > old.date_time == LEGACY_TIMESTAMP, "Corrected runtime dependency lacks its reviewed advanced component timestamp"
                        else:
                            assert entry.date_time == old.date_time == LEGACY_TIMESTAMP, "Focused repairs changed another component's timestamp"
                if release["kind"] == "fresh":
                    assert "config/app.example.php" in entries and "install/index.php" in entries
                elif release["kind"] == "upgrade":
                    assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS for name in entries)
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
                        for relative in REPAIR_FILES | COMPOSER_REPAIR_FILES:
                            if Path(relative).parts[0] not in package.PRESERVED_UPGRADE_ROOTS:
                                assert relative in entries, "Upgrade is missing a required runtime repair dependency"
                                assert zipped.read(relative) == fresh.read(relative), "Upgrade repair dependency differs from the complete fresh package"
                                assert metadata(zipped.getinfo(relative)) == metadata(fresh.getinfo(relative)), "Fresh and upgrade repair dependencies have different timestamps, permissions, or format"
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
                elif release["kind"] == "installer-repair":
                    assert entries == REPAIR_FILES, "Installer repair must contain exactly the reviewed database and filesystem dependencies"
                    assert release["filename"] == "Nikki-Wilder-Velvet-Vixxen-0.8.1-Installer-Database-Repair.zip"
                    assert release["files"] == len(REPAIR_FILES) and release["php_files"] == 6 and release["javascript_files"] == 1
                    assert release["size_bytes"] == 51241 and release["sha256"] == "9cf165faf4f324e4682875bcfde50300a42382467bca700dac0d4e4fd835fc38", "Previously delivered Installer Database Repair bytes changed"
                    compatibility = json.loads(zipped.comment)
                    assert compatibility["kind"] == "installer-repair" and compatibility["version"] == "0.8.1"
                    assert compatibility["supported_from"] == ["0.8.1"] and compatibility["installation_state"] == "incomplete", "Installer repair compatibility must stay limited to an unfinished 0.8.1 installation"
                    assert release["supported_from"] == ["0.8.1"] and release["installation_state"] == "incomplete"

                    # Apply the small repair to an already extracted, unfinished
                    # installation. Every other file must remain byte-identical.
                    incomplete = work / "incomplete"
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
                        current_dependencies = {relative: fresh.read(relative) for relative in REPAIR_FILES}
                        for relative, content in current_dependencies.items():
                            assert zipped.read(relative) == content, "Repair dependency differs from the complete fresh package"
                            assert metadata(zipped.getinfo(relative)) == metadata(fresh.getinfo(relative)), "Fresh and repair dependencies have different timestamps, permissions, or format"
                        fresh.extractall(incomplete)
                    unfinished_state = {
                        "config/app.php": b"<?php return ['installed'=>false,'fixture'=>'unfinished setup'];\n",
                        "storage/temp/installer-fixture.json": b'{"stage":"database","fixture":true}\n',
                        "uploads/images/owner-cutout.bin": b"PRIVATE UNFINISHED-INSTALL UPLOAD FIXTURE\n",
                        "database/backups/preserved-fixture.bin": b"PRE-EXISTING BACKUP FIXTURE\n",
                    }
                    for relative, content in unfinished_state.items():
                        target = incomplete / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(content)
                    for relative in REPAIR_FILES:
                        target = incomplete / relative
                        if relative == "app/Core/MigrationResumeSchema.php":
                            target.unlink()  # Older installations do not contain the new helper.
                        elif relative.endswith(".css"):
                            target.write_bytes(b"/* prior installer stylesheet fixture */\n")
                        elif relative.endswith(".js"):
                            target.write_bytes(b"// prior installer script fixture\n")
                        else:
                            target.write_bytes(b"<?php /* prior installer dependency fixture */\n")
                    before = {path.relative_to(incomplete).as_posix(): path.read_bytes() for path in incomplete.rglob("*") if path.is_file()}
                    assert "install/index.php" in before and "install/services/RequirementChecker.php" in before
                    zipped.extractall(incomplete)
                    after = {path.relative_to(incomplete).as_posix(): path.read_bytes() for path in incomplete.rglob("*") if path.is_file()}
                    expected = dict(before)
                    expected.update(current_dependencies)
                    assert after == expected, "Installer repair changed existing configuration, storage, uploads, application assets, database files, or other installer files"
                    assert not (incomplete / "storage/install.lock").exists(), "Repair unexpectedly installed or locked the unfinished site"
                else:
                    assert entries == COMPOSER_REPAIR_FILES, "Media and Composer repair must contain exactly its reviewed runtime dependencies"
                    assert release["filename"] == f"Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-{BUILD_ID}-Media-Composer-Repair.zip"
                    assert release["files"] == len(COMPOSER_REPAIR_FILES) == 72
                    assert release["php_files"] == 64 and release["javascript_files"] == 5
                    compatibility = json.loads(zipped.comment)
                    assert compatibility["kind"] == "composer-repair" and compatibility["version"] == "0.8.1"
                    assert compatibility["supported_from"] == ["0.8.1"] and compatibility["installation_state"] == "installed", "Media and Composer repair compatibility must stay limited to installed 0.8.1 sites"
                    assert release["supported_from"] == ["0.8.1"] and release["installation_state"] == "installed"
                    assert release["verified_source_commits"] == compatibility["verified_source_commits"] == VERIFIED_BASELINES, "Repair compatibility must identify tested source baselines"
                    assert release["unknown_baseline"] == compatibility["unknown_baseline"] and "complete Upgrade ZIP" in release["unknown_baseline"], "Unknown reused version labels need the complete upgrade guidance"
                    assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS for name in entries), "Media and Composer repair includes installer or installed state"

                    # Deliver this repair to an existing site with its installer
                    # retired. New public entry points/helpers must be added while
                    # every unrelated runtime/private file stays byte-identical.
                    repaired_site = work / "composer-installed"
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh, \
                            ZipFile(work / "first" / releases["upgrade"]["filename"]) as upgrade:
                        current_dependencies = {relative: fresh.read(relative) for relative in COMPOSER_REPAIR_FILES}
                        for relative, content in current_dependencies.items():
                            assert zipped.read(relative) == content == upgrade.read(relative), "Media and Composer repair differs from fresh or upgrade source"
                            assert metadata(zipped.getinfo(relative)) == metadata(fresh.getinfo(relative)) == metadata(upgrade.getinfo(relative)), "Media and Composer repair metadata differs from fresh or upgrade"
                        fresh.extractall(repaired_site)
                    shutil.rmtree(repaired_site / "install")
                    for relative, content in protected.items():
                        target = repaired_site / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(content)
                    for relative in COMPOSER_REPAIR_FILES:
                        target = repaired_site / relative
                        if relative in PUBLIC_ROUTE_FILES | {
                            "public/assets/js/api-client.js", "app/Core/SceneButton.php", "admin/update.php",
                            "app/Core/RuntimeBuildService.php", "app/Views/admin/update-status.php", RUNTIME_BUILD_PATH,
                        }:
                            target.unlink()  # Earlier packages have no physical public entry points/new helpers.
                        elif relative.endswith(".js"):
                            target.write_bytes(b"// prior Composer runtime fixture\n")
                        elif relative.endswith(".css"):
                            target.write_bytes(b"/* prior Composer stylesheet fixture */\n")
                        else:
                            target.write_bytes(b"<?php /* prior Composer runtime fixture */\n")
                    before = {path.relative_to(repaired_site).as_posix(): path.read_bytes() for path in repaired_site.rglob("*") if path.is_file()}
                    zipped.extractall(repaired_site)
                    after = {path.relative_to(repaired_site).as_posix(): path.read_bytes() for path in repaired_site.rglob("*") if path.is_file()}
                    expected = dict(before)
                    expected.update(current_dependencies)
                    assert after == expected, "Media and Composer repair changed configuration, storage, uploads, backups or unrelated application files"
                    assert not (repaired_site / "install").exists(), "Media and Composer repair reintroduced the retired installer"
                    assert all(after[relative] == content for relative, content in protected.items()), "Media and Composer repair altered installed/private state"

        repair_files = {relative: (source / relative).read_bytes() for relative in REPAIR_FILES}
        for files, repair_version in [
            ({}, version),
            *[(dict((path, content) for path, content in repair_files.items() if path != omitted), version)
              for omitted in REPAIR_FILES],
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

        composer_files = package.runtime_files(source, "composer-repair")
        for files, repair_version in [
            ({}, version),
            *[(dict((path, content) for path, content in composer_files.items() if path != omitted), version)
              for omitted in COMPOSER_REPAIR_FILES],
            ({**composer_files, "install/index.php": b"<?php /* extra installer */\n"}, version),
            ({**composer_files, "config/app.php": b"<?php /* private installed configuration */\n"}, version),
            (composer_files, "0.8.0"),
            (composer_files, "0.8.2"),
        ]:
            try:
                package.validate(files, "composer-repair", repair_version)
            except ValueError:
                pass
            else:
                raise AssertionError("Invalid Media and Composer repair contents or version were accepted")

        # A partial overwrite must never be certified by the inventory. Both
        # changed repair files and unchanged full-runtime dependencies matter.
        full_files = package.runtime_files(source, "upgrade")
        for kind, original in [("upgrade", full_files), ("composer-repair", composer_files)]:
            malformed_inventories = [
                {**expected_inventory, "build_id": "20261019.4"},
                {**expected_inventory, "schema": True},
                {**expected_inventory, "files": {name: digest for name, digest in expected_inventory["files"].items() if name != "app/bootstrap.php"}},
                {**expected_inventory, "files": {**expected_inventory["files"], "config/app.php": "0" * 64}},
                {**expected_inventory, "files": {**expected_inventory["files"], RUNTIME_BUILD_PATH: "0" * 64}},
                {**expected_inventory, "files": {**expected_inventory["files"], "api/upload.php": "0" * 64}},
            ]
            for inventory in malformed_inventories:
                broken = {**original, RUNTIME_BUILD_PATH: json.dumps(inventory).encode()}
                try:
                    package.validate(broken, kind, version)
                except ValueError:
                    pass
                else:
                    raise AssertionError("An invalid or mismatched runtime build inventory was accepted")
            changed = {**original, "admin/index.php": b"<?php /* prior installed template */\n"}
            try:
                package.validate(changed, kind, version)
            except ValueError:
                pass
            else:
                raise AssertionError("Mixed old/new runtime bytes were accepted with a current inventory")

        dependency = source / "app/Core/UploadService.php"
        original_dependency = dependency.read_bytes()
        dependency.write_bytes(original_dependency + b"\n// Disposable inventory change fixture.\n")
        updated_inventory = json.loads(package.runtime_files(source, "composer-repair")[RUNTIME_BUILD_PATH])
        expected_updated = json.loads(inventory_bytes)
        expected_updated["files"]["app/Core/UploadService.php"] = hashlib.sha256(dependency.read_bytes()).hexdigest()
        assert updated_inventory == expected_updated, "Repair packaging reused a stale inventory after an unchanged dependency changed"
        dependency.write_bytes(original_dependency)

        version_source = source / "includes/version.php"
        original_version = version_source.read_bytes()
        for invalid_version in [
            original_version.replace(f"const VELVET_VIXXEN_BUILD_ID = '{BUILD_ID}';\n".encode(), b""),
            original_version.replace(f"'{version}-{BUILD_ID}'".encode(), b"'0.8.1-20261019.4'"),
            original_version.replace(f"const VELVET_VIXXEN_BUILD_ID = '{BUILD_ID}';".encode(), b"const VELVET_VIXXEN_BUILD_ID = '2026.5';"),
        ]:
            version_source.write_bytes(invalid_version)
            try:
                package.runtime_files(source, "upgrade")
            except ValueError:
                pass
            else:
                raise AssertionError("A missing, inconsistent or malformed runtime build identity was accepted")
        version_source.write_bytes(original_version)

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
    print("Runtime packages passed: distinct build identity and filenames, identical complete generated runtime inventory with stale/mixed/invalid rejection, clean fresh install, protected upgrade overlay, byte-identical eight-file database/folder/layout repair, exact Media and Composer repair with update verification, matching dependencies and advanced timestamps, safe 0644 entries, reproducibility, archive integrity, and symlink rejection.")
    for release in first["packages"]:
        print(f"{release['kind']}: {release['size_bytes']} bytes, SHA-256 {release['sha256']}")


if __name__ == "__main__":
    run()
