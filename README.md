# Nikki Wilder — The Velvet Vixxen

**Current packaged release: 0.7.0 — Messaging, Booking & Member Requests**

Velvet Vixxen is a full-stack PHP/MySQL creator platform using the approved neon-pink Nikki Wilder design. It combines a visual site composer, Canva-ready templates, music, photo galleries, video, member accounts, subscriptions, PPV commerce, installable commercial add-ons, and a private communication/request center.

## 0.7.0 additions

- Private member-to-owner conversation threads
- Read/unread state, priorities, statuses, attachments, and history
- Content requests with categories, custom intake questions, budgets, due dates, quotes, acceptance/cancellation, and owner notes
- Public or member booking inquiries with guest status links, dates, locations, quotes, confirmation/cancellation, and history
- Protected request attachments outside public asset folders
- Member notifications and unread badges
- Saved owner replies, contact blocks, moderation reports, and internal notes
- Owner queues and authenticated CSV exports
- Eleventh migration / 66 declared application tables

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

Current local package evidence: 160 PHP files, 6 public JavaScript files, 11 Python HTTP/Playwright files, 11 migrations, 66 tables, source suite passed, and all five installed-demo HTTP suites passed.

Local MySQL execution is not claimed because PDO MySQL/MySQL was unavailable. A direct local Chromium run returned `net::ERR_BLOCKED_BY_ADMINISTRATOR`; six Playwright suites remain CI-owned.

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

Branch target: `feature/0.7.0-messaging-booking-requests`

The clean packaged 0.7.0 build and this documentation branch are separate truth boundaries. Do not describe `main` as the complete executable 0.7.0 source until the clean source import, full CI, review, and merge are directly verified.

Never commit production credentials, customer/member records, private conversations, booking/request records, internal notes, guest tokens, request attachments, identity documents, payment secrets, raw license keys, signing private keys, runtime state, or private/paid media.

## Next milestone

**0.8.0 — Marketing, Publishing & Analytics**
