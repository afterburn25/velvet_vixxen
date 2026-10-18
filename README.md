# Nikki Wilder — The Velvet Vixxen

**Current packaged milestone: 0.6.0 — Add-on Framework**

Velvet Vixxen is a PHP/MySQL creator platform with the approved neon-pink design, Hero/Footer Visual Composer, Canva workflow, music/photo/video Media Center, member accounts, unified entitlements, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, and sandbox commerce.

## 0.6.0 additions

- Add-on ZIP upload with inspect-before-install review
- Versioned `addon.json` manifest and semantic versions
- Core/PHP compatibility, dependencies, conflicts, and explicit permissions
- SHA-256 checksums and optional Ed25519 package signatures
- Rejection of unsafe paths, duplicate entries, private keys, environment files, and blocking package findings
- Install, activate, deactivate, update, rollback, preserve-data uninstall, and purge uninstall
- Add-on-owned database migrations and tracked rollback
- Pre-change backups, failed-change restoration, boot-failure quarantine, and global safe mode
- Extension APIs for admin pages/navigation, public routes, widgets, events, filters, services, namespaced settings, and scheduled tasks
- Free, trial, and signed offline commercial-license foundations
- Admin lifecycle logs, diagnostics, starter source/ZIP, developer guide, and package schema
- Nine migrations declaring 48 application tables

## Installer rules retained

- One complete responsive browser page
- No installation key
- 12-character minimum owner and member passwords
- Real filesystem probes
- Safe `0755`/`0775` handling; never `0777`
- Installation lock, boot verification, and `/install` cleanup or secure retirement
- Organized application folders

## Security boundary

Add-ons execute server-side PHP and are not claimed to be fully sandboxed. Checksums, signatures, permissions, package validation, backups, quarantine, safe mode, and rollback reduce risk but do not make an unknown package trustworthy. Install only packages from trusted sources. Never commit private signing keys, production credentials, customer data, runtime state, or private/paid media.

The payment adapter remains sandbox-only. No live processor or merchant approval is claimed. Online license activation, customer downloads, remote update feeds, and the marketplace remain future milestones.

## Local package evidence

- `bash scripts/test.sh` — passed
- `bash scripts/test_http.sh` — passed
- 121 PHP files, 6 public JavaScript files, and 7 Python HTTP/Playwright files passed syntax validation
- Migration manifest passed: 9 migrations / 48 tables
- Extracted release package passed source tests, HTTP acceptance, cleanup, repository safety, and SHA-256 verification
- Package SHA-256: `8462746ee90ec0ec054e756e92afc63a9fc90e9f47f6bea39e7b0a6a156f065c`

Local PDO MySQL/MySQL was unavailable, so live DDL execution is assigned to CI. Local Chromium navigation was blocked by managed policy, so four Playwright suites are assigned to CI. These limitations are not represented as passed local checks.

## Repository truth boundary

`afterburn25/velvet_vixxen` is the communication and testing hub. The complete executable 0.6.0 source is not yet in this documentation branch. Issue #1 tracks source import; issue #7 tracks 0.6.0 delivery and release gates. Do not claim `main` contains this release until source import, full CI, review, and merge actually occur.

Read `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, and `docs/TEST_REPORT.md` before continuing.

## Next milestone

**0.6.1 — Commercial Licensing & Remote Updates**: online activation/deactivation, site limits, trials and grace periods, update entitlement, customer downloads, signed remote update feeds, release channels, outage-tolerant caching, and lifecycle/security tests.