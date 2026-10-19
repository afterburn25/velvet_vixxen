# Velvet Vixxen Development Roadmap

Repository: `afterburn25/velvet_vixxen`. Status follows executable source and recorded tests. Historical package claims are reconciled in `SOURCE_RECOVERY.md`.

| Area | Current status | Remaining boundary |
| --- | --- | --- |
| Foundation, approved design and installer | Recovered executable source, identity/ownership/backup preservation fixes and regressions | Representative customer-hosting certification. |
| Media, members and entitlements | Recovered source plus real source/HTTP/browser regression suites | Production account/media hardening remains outside this development milestone. |
| Commerce and PPV | Sandbox lifecycle plus new merchandise catalog/stock/shipping/fulfillment and direct PPV/rental checkout | Approved live provider and live merchant testing. |
| Add-ons, licensing and updates | Recovered source, SDK and executable lifecycle/security tests | Production remote-service certification and marketplace work. |
| Messaging and Booking Builder | Private communications plus editable typed fields, validation and immutable submission snapshots | Production operating review; existing records remain protected. |
| Visual Composer and Hero Library | Direct editing, all-edge clipping, per-device geometry/backgrounds and 30 real standalone scenes; 90 public render checks passed | Final combined feature CI and artifacts. |
| Public and admin UX | Sticky shared navigation, About Me/editor, centered auth, persistent grouped admin and mobile controls | Final combined feature CI. |
| Installed database updates | Owner/CLI reviewed-plan update path with preserved identity/data and no destructive failure rollback; actual 12→14 upgrade tested | Actual MySQL 8 feature CI, representative hosting. |
| Basic publishing/marketing | Protected file-backed recovered implementation and shared admin shell | Advanced audiences/expiration, scheduled scenes, production sending, attribution, retention and MySQL persistence were not recovered as working code. |
| 0.8.1 user-facing continuation | Implemented; final release gates in progress | Green PHP 8.2/8.3, MySQL 8, HTTP/browser, safety/package CI, final ZIP checksums and source/docs upload. |
| 0.9 privacy, protection and operations | Planned; not started | Begin only after 0.8.1 verification or explicit user deferral. |
| 1.0 production release | Planned | Representative hosting and approved live-service certification. |

## Active 0.8.1 continuation — issue #13

The detailed acceptance checklist is `../INCOMPLETE_WORK.md`. The implementation lives on `feature/0.8.1-site-ux-hero-library`; source recovery checkpoint `43945af07c145c67db2f2883a4fa7f607215dc23` and PR #15 make it reviewable in GitHub.

The requested implementation sequence is now represented by executable code and focused evidence: source recovery; expanded Composer and 30-image library; Booking Builder; public navigation/About/auth; persistent admin categories; merchandise/direct PPV; manual Canva clarity. An installed update path applies migrations 013/014 safely to existing sites.

Current concrete work is the stable combined source/HTTP/browser run, actual feature PHP 8.2/8.3 and MySQL 8 CI, repository/private-data scans, and final runtime-only fresh/upgrade distributions from committed source. Record exact results and checksums in `TEST_REPORT.md`, the handoff and issue #13. Do not call 0.8.1 complete merely because focused suites passed.

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

## Verification correction checkpoint

Correction commit [`1bb7f1903ac01252f22a5a564f18764998781678`](https://github.com/afterburn25/velvet_vixxen/commit/1bb7f1903ac01252f22a5a564f18764998781678), GitHub run [39211611678](https://github.com/afterburn25/velvet_vixxen/actions/runs/39211611678), verified PHP 8.2.34/8.3.35, MySQL 8.0.46 (14 migrations / 82 tables, 32 installed-upgrade checks, 12 booking checks, merchandise transactions and complete rollback), all seven HTTP suites and all three Composer suites on CI Chromium 151. The 30-scene library completed all 90 published renders. The prior SQL privilege and Composer focus failures are fixed and verified.

That run then exposed an Admin category-state race during immediate refresh; the gated package job was skipped. The current correction saves native category-state changes before the next browser task, flushes pending changes on page exit, respects an explicitly collapsed active category and checks immediate refresh plus keyboard activation. A complete green run and final committed-source ZIP checksums are still required. Detailed failure history remains in `docs/TEST_REPORT.md`.
