# Nikki Wilder — The Velvet Vixxen

**Current packaged release: 0.7.0 — Messaging, Booking & Member Requests**

Velvet Vixxen is a full-stack PHP/MySQL creator platform using the approved neon-pink Nikki Wilder design. It combines a visual site composer, Canva-ready templates, music, photo galleries, video, member accounts, subscriptions, PPV commerce, installable commercial add-ons, and private communication/request workflows.

## 0.7.0 additions

- Private member-to-owner conversation threads
- Protected message and request attachments
- Read/unread state, statuses, priorities, owner replies, internal notes, and activity history
- Saved reply templates
- Content requests with category, budget range, requested date, quote, status, and member history
- Public and signed-in booking/appearance inquiries with dates, city, venue/platform, budget, details, and reference files
- Member notifications and mail-outbox integration
- Communication blocks and configurable attachment/intake controls
- Owner queues for messages, content requests, booking requests, and communication settings
- Eleventh migration / 64 declared application tables

## Runtime-only installation ZIP

The public release ZIP contains only files required to install and run the website. It intentionally excludes:

- `.git` and `.github`
- repository roadmaps, handoff documents, and GitHub Actions workflows
- `/docs` and `/tests`
- add-on SDK source
- screenshots and release reports
- test, demo, cleanup, and build scripts
- the development-only SQL schema snapshot

Operational scripts required by the live site remain included: `scripts/run_billing.php` and `scripts/run_addon_tasks.php`.

## Locked installation requirements

- One-page browser installer
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775` permissions; never `0777`
- Versioned database migrations
- Installation lock and `/install` deletion or secure retirement
- Organized folders rather than root-level file sprawl

## Test commands

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
bash scripts/clean_runtime.sh
php tests/Security/repository_safety.php
```

Verified local evidence:

- 149 PHP source/test files passed syntax validation
- 6 public JavaScript files passed syntax validation
- 10 Python HTTP/Playwright files passed syntax validation
- 11 migrations / 64 declared application tables
- complete non-database source/security suite passed
- Members, Commerce, Add-on, Licensing/Updates, and Communications HTTP suites passed
- runtime-only ZIP contains 185 files, including 112 PHP runtime files
- package SHA-256: `98247f13d7a36f77f65100f0e86ba3dfc2741e84e75b40b02a9b93a4e12c7b2b`

Local MySQL execution is not claimed because PDO MySQL/MySQL was unavailable. Direct local Chromium navigation is blocked by a managed policy; Playwright remains CI-owned. Payment remains sandbox-only.

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

Branch target: `feature/0.7.0-messaging-booking-requests`

The clean packaged 0.7.0 build and this documentation branch are separate truth boundaries. Do not describe `main` as the complete executable 0.7.0 source until clean source import, full CI, review, and merge are directly verified.

Never commit production credentials, customer/member records, private conversations, booking/request records, internal notes, attachments, identity documents, payment secrets, raw license keys, signing private keys, runtime state, or private/paid media.

## Next milestone

**0.8.0 — Marketing, Publishing & Analytics**
