# Velvet Vixxen Development Roadmap

## Installer folder correction in progress — 2026-10-19

The owner reported fresh-install readiness failures for nine missing image, font/icon and template folders after downloading the earlier 0.8.1 package. The manifest required unused empty scaffolding that the runtime-only ZIP intentionally does not contain. On panel-owned read-only asset trees, PHP cannot create those folders. The earlier same-owner installer fixtures did not cover this extracted-package ownership case; the prior green runs below do not certify it.

The current correction removes those nine unused entries and unused `database/seeds`, checks actual packaged application/asset directories for presence/readability without creating or chmodding them, and preserves the existing writable-runtime probes and limited ownership repair. Missing real application folders remain blocking errors. A one-file installer repair ZIP will let an already-uploaded unfinished installation resume without replacing configuration, storage, uploads or database data. The complete fresh package will also contain the correction.

Local PHP 8.3.6 source/JavaScript syntax/security/package suites passed. The new real foreign-owner extracted-ZIP regression reproduced exactly the nine original errors, and the corrected service passed repeated probes, allowed ownership repair, required-write failures, missing real assets and failed-repair preservation. Fresh PHP 8.2/8.3, MySQL 8 and HTTP/browser CI is pending on the corrective commit; do not claim that new run has passed until its results are observed. The 0.8.1 features and locked requirements remain the scope; no 0.9.0 work is authorized by this correction.

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
