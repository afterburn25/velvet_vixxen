# Project Status — Nikki Wilder / The Velvet Vixxen

## Current packaged release

**0.7.0 — Messaging, Booking & Member Requests**

The clean 0.7.0 package contains the approved public design, Visual Composer, Canva workflow, Media Center, members and entitlements, sandbox subscriptions/PPV commerce, the commercial Add-on Framework, and a private communications/request center.

## Completed in the tested package

### Communications

- Private member-to-owner conversations
- Read/unread tracking, priority, status, close/reopen/archive behavior, protected attachments, and history
- Content requests with categories, custom questions, budgets, due dates, quotes, owner workflow, member acceptance/cancellation, and linked conversations
- Public and member booking inquiries with requested dates, locations, venues, budgets, quotes, confirmation/cancellation, and history
- Hashed and encrypted guest booking-status tokens
- Member notification center and unread counters
- Saved replies, contact blocks, moderation reports, and owner-only notes
- Owner queues for messages, content requests, bookings, reports, categories, and saved replies
- Authenticated CSV exports

### Foundation retained

- Single-page browser installer, no key, exact 12-character minimum passwords, safe permissions, install lock, and `/install` cleanup
- Music, galleries, video, protected media, member accounts, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, and refunds
- Add-on install/update/rollback lifecycle, Safe Mode, commercial licensing, signed remote updates, and release channels
- Eleven migrations declaring 66 application tables

## Local evidence

- `bash scripts/test.sh`: passed
- `bash scripts/test_http.sh`: passed
- PHP syntax: 160 files
- Public JavaScript syntax: 6 files
- Python HTTP/Playwright syntax: 11 files
- Migration manifest: 11 migrations / 66 tables
- Members, Commerce, Add-on, Add-on Licensing, and Communications HTTP suites passed
- Clean extracted package passed source tests, HTTP acceptance, cleanup, repository safety, and checksum verification
- Package SHA-256: `8f4d0497c7463c4526cea52fbb66cf55d59c4cc76ac03e34eb380c3310fb8c96`

## Environmental limits

- Local PDO MySQL/MySQL was unavailable; live DDL execution is not claimed. CI must apply all eleven migrations, verify 66 tables, roll back, and leave the disposable database empty.
- Local Chromium navigation returned `net::ERR_BLOCKED_BY_ADMINISTRATOR`; six Playwright suites are configured for CI.
- Payment remains sandbox-only.
- Messaging is asynchronous; websocket chat, calling, and automatic quote payment are not claimed.

## Repository truth boundary

- Repository: `afterburn25/velvet_vixxen`
- Documentation/delivery branch: `feature/0.7.0-messaging-booking-requests`
- The complete executable 0.7.0 source is not yet claimed as imported into GitHub.
- Do not call `main` the 0.7.0 executable release until source import, CI, review, and merge are directly verified.
- Never commit private conversations, request/booking data, internal notes, guest tokens, attachments, customer records, credentials, raw license keys, signing private keys, runtime state, or private/paid media.

## Next actions

1. Import the complete clean 0.7.0 source.
2. Run PHP 8.2/8.3, MySQL 8, HTTP, repository-safety, and six Playwright jobs.
3. Correct CI-only findings and record exact evidence.
4. Merge only after required checks are green.
5. Begin **0.8.0 — Marketing, Publishing & Analytics**.
