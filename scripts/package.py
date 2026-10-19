#!/usr/bin/env python3
"""Build reproducible runtime installation, upgrade, and focused repair packages."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT_FILES = {".htaccess", "index.php", "route.php", "VERSION"}
RUNTIME_ROOTS = {
    "admin", "app", "addons", "config", "database", "images", "includes",
    "install", "public", "storage", "templates", "uploads", "vendor",
}
PRESERVED_UPGRADE_ROOTS = {"config", "storage", "uploads", "install"}
INSTALLER_REPAIR_VERSION = "0.8.1"
INSTALLER_REPAIR_FILES = {"install/services/FileSystemService.php"}
APPROVED_UPLOADS = {
    "uploads/.htaccess", "uploads/index.html",
    "uploads/audio/demo/neon-heartbeat.mp3",
    "uploads/audio/demo/pink-room-dreams.mp3",
    "uploads/audio/demo/velvet-after-dark.mp3",
    "uploads/captions/demo/velvet-trailer.vtt",
    "uploads/videos/demo/velvet-trailer.mp4",
    "uploads/videos/demo/velvet-feature.mp4",
    "uploads/images/demo/album-cover.jpg", "uploads/images/demo/video-poster.jpg",
    *(f"uploads/images/demo/gallery-{number}.jpg" for number in range(1, 6)),
}
FORBIDDEN_SUFFIXES = (".log", ".tmp", ".previous", ".backup", ".bak", ".pem", ".key", ".sql")


def eligible(relative: str, kind: str) -> bool:
    """An allowlist prevents runtime data from entering any distribution."""
    if kind == "installer-repair":
        return relative in INSTALLER_REPAIR_FILES
    path = Path(relative)
    parts = path.parts
    if not parts or path.is_absolute() or ".." in parts:
        return False
    if len(parts) == 1:
        return relative in ROOT_FILES
    top = parts[0]
    if top not in RUNTIME_ROOTS:
        return False
    if any(part.startswith(".") and part != ".htaccess" for part in parts):
        return False
    if path.name.endswith(FORBIDDEN_SUFFIXES) or ".tmp-" in path.name:
        return False
    if kind == "upgrade" and top in PRESERVED_UPGRADE_ROOTS:
        return False
    if top == "config":
        return relative in {"config/.htaccess", "config/app.example.php"}
    if top == "storage":
        return path.name == ".htaccess" or relative == "storage/defaults/data.json"
    if top == "uploads":
        return relative in APPROVED_UPLOADS
    if top == "database":
        return relative == "database/.htaccess" or (
            len(parts) == 3 and parts[1] == "migrations" and path.suffix == ".php"
        ) or relative == "database/backups/.htaccess"
    if top == "addons":
        return relative == "addons/.htaccess" or relative in {
            "addons/staging/.htaccess", "addons/installed/.htaccess",
            "addons/packages/velvet-welcome-banner-1.0.0.zip",
            "addons/packages/velvet-welcome-banner-1.0.1.zip",
        }
    return True


def runtime_files(root: Path, kind: str) -> dict[str, bytes]:
    files: dict[str, bytes] = {}
    root = root.resolve()
    for path in sorted(root.rglob("*")):
        relative = path.relative_to(root).as_posix()
        if not eligible(relative, kind):
            continue
        if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != root):
            raise ValueError(f"Package source contains a symlink: {relative}")
        if path.is_file():
            if not path.resolve().is_relative_to(root):
                raise ValueError(f"Package file escapes source root: {relative}")
            files[relative] = path.read_bytes()
    return files


def validate(files: dict[str, bytes], kind: str, version: str) -> None:
    if kind == "installer-repair":
        if version != INSTALLER_REPAIR_VERSION:
            raise ValueError("The installer folder repair applies only to unfinished 0.8.1 installations")
        required = INSTALLER_REPAIR_FILES
    else:
        required = ROOT_FILES | {
            "app/bootstrap.php", "includes/bootstrap.php", "includes/version.php",
            "includes/installation_recovery.php", "public/router.php", "public/home.php",
            "app/Core/RepairBackupService.php", "public/assets/js/composer.js",
        }
        if kind == "fresh":
            required |= {"config/app.example.php", "install/index.php", "storage/defaults/data.json"}
    missing = required - files.keys()
    if missing:
        raise ValueError("Required runtime source is missing: " + ", ".join(sorted(missing)))
    if kind != "installer-repair":
        if files["VERSION"].decode().strip() != version:
            raise ValueError("Package version does not match VERSION")
        if version.encode() not in files["includes/version.php"]:
            raise ValueError("includes/version.php does not match VERSION")
    for name, content in files.items():
        if not eligible(name, kind):
            raise ValueError(f"Disallowed package entry: {name}")
        if re.search(rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\r?\n", content):
            raise ValueError(f"Private key material detected in {name}")
    if "config/app.php" in files or "storage/install.lock" in files:
        raise ValueError("Installed state must never enter a distribution")
    if kind == "fresh":
        defaults = json.loads(files["storage/defaults/data.json"])
        private_collections = {
            "admins", "members", "orders", "orderItems", "subscriptions", "entitlements",
            "memberSessions", "memberTokens", "memberEvents", "memberMediaState",
            "messages", "messageThreads", "messageAttachments", "contentRequests",
            "bookingRequests", "requestAttachments", "memberNotifications", "mailOutbox",
            "paymentTransactions", "paymentWebhookEvents", "couponRedemptions", "audit",
            "addonLicenses", "issuedAddonLicenses", "addonLicenseActivations",
            "addonLicenseTransactions", "addonDownloadTokens", "addonBackups",
        }
        if any(defaults.get(key) for key in private_collections):
            raise ValueError("Fresh-install defaults contain runtime or private records")


def build(root: Path, output: Path, version: str) -> dict[str, object]:
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Use a numeric major.minor.patch version")
    output.mkdir(parents=True, exist_ok=True)
    result: dict[str, object] = {"version": version, "packages": []}
    variants = [("fresh", "Fresh-Install"), ("upgrade", "Upgrade-from-0.8.x")]
    if version == INSTALLER_REPAIR_VERSION:
        variants.append(("installer-repair", "Installer-Folder-Repair"))
    for kind, suffix in variants:
        files = runtime_files(root, kind)
        validate(files, kind, version)
        filename = f"Nikki-Wilder-Velvet-Vixxen-{version}-{suffix}.zip"
        archive = output / filename
        compatibility = {
            "supported_from": ["0.8.0", "0.8.1"] if kind == "upgrade" else [],
        }
        if kind == "installer-repair":
            compatibility = {
                "supported_from": [INSTALLER_REPAIR_VERSION],
                "installation_state": "incomplete",
            }
        with ZipFile(archive, "w", compression=ZIP_DEFLATED, compresslevel=9) as zipped:
            zipped.comment = json.dumps({
                "product": "Velvet Vixxen", "version": version, "kind": kind,
                **compatibility,
            }, separators=(",", ":")).encode()
            for relative, content in files.items():
                entry = ZipInfo(relative, date_time=(2026, 1, 1, 0, 0, 0))
                entry.create_system = 3
                entry.external_attr = 0o100644 << 16
                entry.compress_type = ZIP_DEFLATED
                zipped.writestr(entry, content, compress_type=ZIP_DEFLATED, compresslevel=9)
        with ZipFile(archive) as zipped:
            if zipped.testzip() is not None:
                raise ValueError(f"Archive integrity check failed: {filename}")
            if set(zipped.namelist()) != set(files):
                raise ValueError(f"Archive entry verification failed: {filename}")
        result["packages"].append({
            "kind": kind, "filename": filename, "size_bytes": archive.stat().st_size,
            "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(),
            "files": len(files), "php_files": sum(name.endswith(".php") for name in files),
            "javascript_files": sum(name.endswith(".js") for name in files),
            "entry_sha256": {name: hashlib.sha256(data).hexdigest() for name, data in files.items()},
            **(compatibility if kind == "installer-repair" else {}),
        })
    (output / f"Velvet-Vixxen-{version}-package-manifest.json").write_text(
        json.dumps(result, indent=2) + "\n"
    )
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    root = args.root.resolve()
    version = (root / "VERSION").read_text().strip()
    result = build(root, args.output.resolve(), version)
    print(json.dumps({"version": result["version"], "packages": [
        {key: value for key, value in entry.items() if key != "entry_sha256"}
        for entry in result["packages"]
    ]}, indent=2))


if __name__ == "__main__":
    main()
