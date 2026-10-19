# Velvet Vixxen Development Roadmap

## Installer continuation with existing databases — 2026-10-19

The newest executable source remains on `feature/0.8.1-site-ux-hero-library`. This continuation removes the blanket rejection of databases containing Velvet Vixxen tables. The actual installer now handles empty databases, unrelated existing tables, compatible partial schemas, complete schemas and missing migration history. It validates existing definitions and owner credentials, applies only missing work, and preserves existing records, settings, scenes, owner password hashes, installation identity and recoverable security keys. Historical migration bytes are unchanged.

A failed attempt keeps completed schema work and its pending configuration for retry. Pending configuration is not publicly installed until final verification succeeds, including a process interruption after the lock was written. Automatic recovery and installer retirement share the setup guard and cannot replace a pending checkpoint or move the installer while it is running. Existing completed installations remain protected; incompatible schema or missing original encryption keys produce specific preservation errors, never an instruction to erase the database.

The owner's additional layout correction removes the extra Folders rule/inset, aligns Server/Folders headings and check cards, and aligns Password/Confirm Password fields despite the strength meter. Completion clears the spinner and page scroll lock. Installer CSS/JavaScript have their own updated cache query. The runtime repair contains **eight files: six PHP files, one stylesheet and one JavaScript file**, including the earlier folder correction. The fresh and upgrade packages are rebuilt; the earlier one-file Folder Repair ZIP does not contain this database/layout continuation.

Local and GitHub verification are complete: the full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite passed; the actual packaged installer passed 922 assertions on MySQL 8.0.46; recovery passed 134 assertions; and real desktop/mobile browser installation, retry, layout and completion tests passed. The new SQL parser cases and original failing layout are retained or reproduced as meaningful regressions. Executable source, tests and workflow are uploaded as [`e5b04daa132521be69c943151f8112c7cd2017e8`](https://github.com/afterburn25/velvet_vixxen/commit/e5b04daa132521be69c943151f8112c7cd2017e8), tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`. All seven required jobs passed in [GitHub run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907), whose official conclusion is **completed / success**. This includes PHP 8.2.34/8.3.35 source suites, 922 actual-package MySQL installer assertions on each version, real installer desktop/mobile acceptance, seven HTTP suites, twelve application-browser suites and gated packaging. The downloaded GitHub ZIPs and manifest are byte-identical to the saved customer downloads. Matching repair, fresh-install, upgrade and separate checksum-manifest downloads were saved on 2026-10-19. See `docs/INSTALLER_DATABASE_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json` (repository-relative paths). Earlier folder/cache evidence remains historical in the test report and its release record. No 0.9.0 work or production deployment is included.

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
