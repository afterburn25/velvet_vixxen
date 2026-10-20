#!/usr/bin/env python3
"""Reject reused release versions while allowing exact historical rebuilds."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import re
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def semantic(value: str) -> tuple[int, int, int]:
    if not isinstance(value, str) or not re.fullmatch(r"\d+\.\d+\.\d+", value):
        raise ValueError("Release versions must be numeric major.minor.patch identifiers")
    return tuple(map(int, value.split(".")))


def verify_progress(version: str, delivered: dict, actual: dict[str, str] | None) -> str:
    current, previous = semantic(version), semantic(delivered["version"])
    if current < previous:
        raise ValueError("The visible release version is older than the latest delivered release")
    if current > previous:
        return "advanced"
    expected = {item["filename"]: item["sha256"]
                for item in delivered["gated_artifact_verification"]["files"]}
    if not expected or actual != expected:
        raise ValueError("Changed release packages reuse the last delivered visible version; advance VERSION and all runtime identifiers")
    return "exact-rebuild"


def run() -> None:
    records = []
    for path in sorted((ROOT / "docs/releases").glob("*-delivery.json")):
        record = json.loads(path.read_text())
        # Older evidence uses another schema. The explicit user-directed
        # release-version policy starts with the verified 0.8.2 delivery.
        filename_version = re.match(r"^(\d+\.\d+\.\d+)-", path.name)
        if "version" not in record:
            if filename_version and semantic(filename_version[1]) >= (0, 8, 2):
                raise ValueError(f"Missing release identity in committed delivery evidence: {path.name}")
            continue
        if filename_version and semantic(filename_version[1]) >= (0, 8, 2) and record["version"] != filename_version[1]:
            raise ValueError(f"Mismatched committed delivery version: {path.name}")
        if semantic(record["version"]) < (0, 8, 2):
            continue
        if not {"gated_artifact_verification", "saved_download_verification"} <= record.keys():
            raise ValueError(f"Missing verification in committed release evidence: {path.name}")
        gated, saved = record["gated_artifact_verification"], record["saved_download_verification"]
        if not gated.get("files") or not saved.get("files"):
            raise ValueError(f"Incomplete committed release evidence: {path.name}")
        if not all(item.get("gated_bytes_equal_local") for item in gated["files"]) \
                or not all(item.get("saved_bytes_equal_gated_artifact") for item in saved["files"]):
            raise ValueError(f"Unverified committed release evidence: {path.name}")
        records.append(record)
    if not records:
        raise ValueError("Missing verified delivered-release baseline for the visible version policy")
    delivered = max(records, key=lambda record: semantic(record["version"]))
    version = (ROOT / "VERSION").read_text().strip()
    actual = None
    if semantic(version) == semantic(delivered["version"]):
        spec = importlib.util.spec_from_file_location("release_package", ROOT / "scripts/package.py")
        package = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(package)
        with tempfile.TemporaryDirectory(prefix="vvx-version-rebuild-") as temporary:
            output = Path(temporary)
            manifest = package.build(ROOT, output, version)
            names = [item["filename"] for item in manifest["packages"]] + [manifest["manifest_filename"]]
            actual = {name: hashlib.sha256((output / name).read_bytes()).hexdigest() for name in names}
    outcome = verify_progress(version, delivered, actual)
    # These boundaries distinguish a harmless exact rebuild from the former
    # practice of changing only the secondary build label.
    fixture = {"version": "0.8.2", "gated_artifact_verification": {
        "files": [{"filename": "release.zip", "sha256": "a" * 64}]}}
    assert verify_progress("0.8.3", fixture, None) == "advanced"
    assert verify_progress("0.8.2", fixture, {"release.zip": "a" * 64}) == "exact-rebuild"
    for bad_version, bad_files in [("0.8.1", None), ("0.8.2", {"release.zip": "b" * 64}),
                                   ("0.8.2", {"new-build.zip": "a" * 64}), ("0.8.2", None)]:
        try:
            verify_progress(bad_version, fixture, bad_files)
        except ValueError:
            pass
        else:
            raise AssertionError("An older or changed same-version release passed the version policy")
    print(f"Visible release version passed: {version}; last delivered {delivered['version']}; {outcome}.")


if __name__ == "__main__":
    run()
