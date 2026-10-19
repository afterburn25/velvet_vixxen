# Velvet Vixxen Development Roadmap

## Installer folder and PHP cache correction — 2026-10-19

The nine reported missing static-folder failures were reproduced against the exact previous fresh ZIP with PHP running as a different unprivileged user. Runtime correction `776450964e5a1e9d26719d886d114ce3eef4037e` removes unused directory requirements, verifies real packaged assets without mkdir/chmod, and preserves genuine runtime writes and limited ownership repair. The older same-owner fixtures did not cover the failure.

A subsequent real persistent-PHP test found that timestamp-preserving ZIP extraction could leave the old installer cached in OPcache even after the corrected bytes were uploaded. The repaired service now has deterministic ZIP timestamp **2026-10-19 00:00:00** in both fresh and repair packages. Only that entry's timestamp changes; corrected PHP bytes, all other file metadata and the standard upgrade are unchanged. The new mandatory OPcache regression proves stale execution for three same-timestamp requests, then loads the corrected manifest immediately after extracting the real repair ZIP, without restarting PHP or explicitly invalidating its cache.

Final implementation and package source: [`eed4c5efe1f3be9aa2522f247adcd2a5e84c509a`](https://github.com/afterburn25/velvet_vixxen/commit/eed4c5efe1f3be9aa2522f247adcd2a5e84c509a), tree `a0be19e15f58d4576d78e1b5a820317f5242acab`. The integrated local PHP 8.3.6 suite **passed**. Both final GitHub runs, [PR 39283445755](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283445755) and [push 39283438479](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283438479), have complete successful archived execution logs for the repository/source contract, PHP 8.2.34/8.3.35 source and package suites (including both ownership and OPcache regressions), MySQL 8.0.46 apply/verify/rollback, seven HTTP suites, twelve Chromium browser suites, and runtime packaging. Both CI package builds match the corrected download hashes. At 16:21:51 UTC, GitHub's run/job metadata still reported `queued` with null conclusions despite those completed logs and uploaded artifacts; successful execution is established from the logs, and an official successful workflow conclusion is not claimed. Exact jobs, checkout provenance and artifacts are recorded in `docs/TEST_REPORT.md`.

The timestamp-aware repair ZIP, fresh ZIP and separate checksum manifest were successfully saved as replacement downloads. The repair ZIP is 6,400 bytes and contains only `install/services/FileSystemService.php`; the fresh ZIP has 313 runtime files. Current hashes are in `docs/releases/0.8.1-installer-folder-repair.json` and `RELEASE_MANIFEST.txt` (paths relative to the repository root). Follow `docs/INSTALLER_FOLDER_REPAIR.md` to resume the existing unfinished installer and click **Run Checks**. Earlier fresh/repair packages are superseded. Preserve all locked requirements; no 0.9.0 work is included.

Repository: `afterburn25/velvet_vixxen`. Status follows executable source and recorded tests. Historical package claims are reconciled in `SOURCE_RECOVERY.md`.

| Area | Current status | Remaining boundary |
| --- | --- | --- |
| Foundation, approved design and installer | Recovered executable source, identity/ownership/backup preservation fixes and regressions | Representative customer-hosting certification. |
| Media, members and entitlements | Recovered source plus real source/HTTP/browser regression suites | Production account/media hardening remains outside this development milestone. |
| Commerce and PPV | Sandbox lifecycle plus new merchandise catalog/stock/shipping/fulfillment and direct PPV/rental checkout | Approved live provider and live merchant testing. |
| Add-ons, licensing and updates | Recovered source, SDK and executable lifecycle/security tests | Production remote-service certification and marketplace work. |
| Messaging and Booking Builder | Private communications plus editable typed fields, validation and immutable submission snapshots | Production operating review; existing records remain protected. |
| Visual Composer and Hero Library | Direct editing, all-edge clipping, per-device geometry/backgrounds and 30 real standalone scenes; 90 public render checks passed | Verified final CI and runtime artifacts; owner content remains configurable. |
| Public and admin UX | Sticky shared navigation, About Me/editor, centered auth, persistent grouped admin and mobile controls | Verified final CI; preserve immediate-refresh and active-page regressions. |
| Installed database updates | Owner/CLI reviewed-plan update path with preserved identity/data and no destructive failure rollback; actual 12→14 upgrade tested | Actual MySQL 8 feature CI passed; representative hosting remains a production gate. |
| Basic publishing/marketing | Protected file-backed recovered implementation and shared admin shell | Advanced audiences/expiration, scheduled scenes, production sending, attribution, retention and MySQL persistence were not recovered as working code. |
| 0.8.1 user-facing continuation | Implemented and verified | All required source/database/HTTP/browser/safety/package gates passed; exact source, artifacts and checksums recorded. |
| 0.9 privacy, protection and operations | Planned; not started | Begin only after 0.8.1 verification or explicit user deferral. |
| 1.0 production release | Planned | Representative hosting and approved live-service certification. |

## Active 0.8.1 continuation — issue #13

The detailed acceptance checklist is `../INCOMPLETE_WORK.md`. The implementation lives on `feature/0.8.1-site-ux-hero-library`; source recovery checkpoint `43945af07c145c67db2f2883a4fa7f607215dc23` and PR #15 make it reviewable in GitHub.

The requested implementation sequence is now represented by executable code and focused evidence: source recovery; expanded Composer and 30-image library; Booking Builder; public navigation/About/auth; persistent admin categories; merchandise/direct PPV; manual Canva clarity. An installed update path applies migrations 013/014 safely to existing sites.

The full continuation is verified by GitHub run [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477) on runtime commit [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c). All six required jobs passed, including PHP 8.2/8.3, MySQL 8, all HTTP/browser suites and gated runtime distributions. `TEST_REPORT.md` and `../RELEASE_MANIFEST.txt` identify exact artifacts and hashes. No 0.9.0 implementation was started during this continuation.

Advanced marketing gaps remain explicitly documented in `SOURCE_RECOVERY.md`; they are not silently counted as delivered. Actual owner photos, biography/statistics and catalog content must be supplied by the owner. The source supplies working editors without inventing personal details, products or placeholder people.

## 0.9 — Privacy, Protection & Operations

- Performer/collaborator records and encrypted document references.
- Rights/release records and linked-media ownership metadata.
- Complaint, copyright, report, takedown, response, and appeal workflow.
- Age-assurance provider adapter and geographic controls.
- Administrator two-factor authentication and privileged-action checks.
- Audit logging, rate limiting, suspicious-session alerts, privacy export/deletion.
- Backup scheduling, retention, restore drills, diagnostics, and recovery mode.
- Accessibility and keyboard interaction review.

## 1.0 — Production Release

- Approved live payment-provider adapter and merchant testing.
- Production mail delivery and unsubscribe/suppression validation.
- Shared-hosting fresh install, upgrade, rollback, backup, and restore verification.
- PHP 8.2/8.3, MySQL/MariaDB, HTTP, browser, security, accessibility, and performance gates.
- Customer packages built from the committed source; operating documentation and actual running-build evidence.

A feature is complete only when implemented, tested, documented, and uploaded. No percentage is inferred from version numbers or table counts.
