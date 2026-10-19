## 0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise (2026-10-19)

- Recover the checksum-verified 0.8.1 executable runtime into the repository and restore engineering tests/SDK.
- Reconcile the separate 0.7.4 Visual Composer fixes with the newer router/recovery source.
- Validate matching installation identities and prevent reinstall over retained configuration or keys.
- Preserve active configuration and unique repair-backup data; add focused recovery regressions.
- Build reproducible runtime-only fresh-install and state-preserving upgrade packages.
- Require real source, database, HTTP, and browser checks instead of a passing missing-source skip.
- Add direct Composer controls, 0.5–400% resizing, reachable handles, all-edge clipping/lower-edge crop, live text/button geometry and independent device backgrounds/layouts.
- Bundle 30 standalone luxury backgrounds and thumbnails with a searchable validated library, draft previews and publication rollback.
- Add Booking Form Builder with typed field management, client/server validation, live phone formatting and immutable inquiry snapshots.
- Add dedicated About Me/editor, shared sticky public navigation, centered account/recovery layouts and persistent grouped administration.
- Add merchandise products/images/variants/stock/shipping, merchandise-only cart, private delivery details and owner fulfillment/tracking. Protect stock from stale editor saves.
- Separate membership and direct PPV/rental checkout from merchandise; preserve My Library and refund/chargeback revocation.
- Add reviewed owner/CLI database updates for existing sites, with locks and no destructive automatic rollback on failed upgrade DDL.
- Document and display Canva's manual saved-link/export/import workflow with separate cutouts and live functional content.
- Centralize asset cache identity without changing the 0.8.1 compatibility version; render nonimage Media Library assets as file cards.
- Preserve selected Composer resize/move nodes and keyboard focus through image loads and canvas resizing; correct About page margins and empty sidebar layout.
- Reconcile status/roadmap/handoff claims with executable source and observed evidence; final combined release gates remain in progress.

# Changelog

## 0.8.0 — Marketing, Publishing & Analytics

**Historical package-era claims:** this section records the earlier handoff and is not a certification of the recovered implementation. `docs/SOURCE_RECOVERY.md` identifies missing audiences/expiration, scheduled-scene automation, attribution/retention and MySQL marketing persistence. The verified baseline schema has 78 tables, not 75. Current results are in `docs/TEST_REPORT.md`.

### Added

- Blog, personal-update, and announcement publishing
- Draft, scheduled, published, archived, and expiring editorial states
- Public, registered-member, and subscriber visibility
- Categories, tags, SEO metadata, featured images, and featured placement
- Public Blog/Updates index and post pages
- Newsletter single- and double-opt-in flows
- Signed confirmation and unsubscribe tokens
- Subscriber tags, segmentation, consent, suppression, campaign queues, and delivery-state tracking
- Timed promotional banners and campaign landing pages
- Scheduled Hero/Footer Visual Composer scenes
- Lock-aware and idempotent marketing task runner
- Link-in-bio page and click tracking
- Referral clicks, order attribution, and attributed-revenue foundations
- Same-origin first-party analytics and foreign-origin rejection
- Marketing and Analytics administration
- Posts, newsletter, referrals, and analytics CSV exports
- Analytics and consent-record retention controls
- Twelfth migration, bringing the declared schema to 75 tables

### Changed

- Homepage now supports active promotional banners, latest posts, and newsletter signup
- Header/footer navigation now exposes Updates and Links
- Registration and checkout preserve referral attribution
- Built-in root image assets and uploaded image assets now resolve through the correct URL paths
- Disabled marketing modules return 404, while unsubscribe remains available for safety and compliance
- Public installation ZIP retains only runtime files and now includes `scripts/run_marketing.php`

### Fixed

- Scheduled file-store scene publication no longer overwrites a newly published scene with stale store data
- MySQL scheduled-scene processing atomically claims `scheduled -> processing` before publication
- Built-in post and campaign artwork no longer incorrectly resolves under `/uploads/images/`

### Testing

- 128 runtime PHP files passed syntax validation
- 7 public JavaScript files passed syntax validation
- Migration manifest passed: 12 migrations / 75 tables
- Marketing file-store, Marketing HTTP, full-site HTTP smoke, and installer smoke suites passed
- Extracted runtime ZIP passed integrity, checksum, clean-state, exclusions, and security checks

### Limitations

- Live local MySQL DDL execution remains CI-owned because PDO MySQL/MySQL/MariaDB was unavailable
- Full interactive Playwright acceptance remains CI or representative-hosting work
- Payment processing remains sandbox-only

## 0.7.3 — Installer Verification & Simplified Setup Hotfix

- Fixed stale OPcache/path-cache behavior during final configuration verification
- Added exact configuration byte/hash verification and one-time verification loading
- Simplified the installer to Database, Owner and Website, and Install sections
- Collapsed detailed server/folder diagnostics unless attention is required

## 0.7.2 — Installer Email Validation Hotfix

- Accepted normalized valid email addresses containing pasted whitespace or common invisible Unicode characters
- Stopped validating hidden sender/SMTP fields when outgoing email is configured later
- Defaulted support and sender email to the owner email

## 0.7.1 — Ownership-Safe Installer Hotfix

- Added ownership-safe replacement of panel-owned runtime directories that PHP could read but not write
- Preserved existing contents while creating PHP-owned writable replacements
- Added all missing upload, storage, add-on, backup, cache, log, session, and temporary directories
- Removed development-only docs/tests folder checks from the customer installer
- Added ownership-safe `/install` retirement without `0777`

## 0.7.0 — Messaging, Booking & Member Requests

- Added private member-to-owner message threads with protected attachments
- Added content-request intake, budgets, quotes, statuses, and owner/member replies
- Added public and signed-in booking/appearance inquiry workflows
- Added notifications, saved replies, communication blocks, owner queues, and settings
- Added eleventh migration / 64 declared tables
- Adopted the runtime-only public installation ZIP policy

## 0.6.1 — Commercial Add-on Licensing & Remote Updates

- Added commercial license products, issuance, activation, site limits, domain binding, encrypted local keys, offline grace, Ed25519-signed responses, release channels, protected downloads, remote update review, and rollback
- Added tenth migration / 55 declared tables

## 0.6.0 — Add-on Framework

- Added validated add-on ZIP inspection, permissions, compatibility, dependencies, conflicts, checksums, signatures, migrations, backups, Safe Mode, lifecycle management, routes, widgets, events, filters, services, settings, and scheduled tasks
- Added ninth migration / 48 declared tables

## 0.5.0 — Subscriptions & PPV Commerce

- Added Store, cart, sandbox checkout, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, billing, refunds, signed webhooks, and commerce administration
- Added eighth migration / 44 declared tables

## 0.4.0 — Members & Entitlements

- Added registration, verification, login/recovery, device sessions, dashboard, My Library, plans, subscriptions, direct entitlements, unified access resolution, and synchronized media state
- Added seventh migration / 38 declared tables

## 0.3.0 — Media Center

- Added persistent music player, albums/playlists, photo galleries/lightbox, video library/player, protected media, scheduling, and media administration

## 0.2.2 — One-Page Browser Installer

- Replaced the wizard with one responsive installer page while preserving no install key, 12-character minimum passwords, safe permissions, migrations, rollback, lock, and cleanup

## 0.2.0–0.2.1 — Design, Composer, Canva & Installer Foundation

- Added the approved homepage, Hero/Footer composer, Canva templates/import, owner login, age gate, browser installer, organized folders, and migration foundation
