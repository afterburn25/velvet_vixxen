# Session Handoff — Velvet Vixxen 0.7.0

## Resume point

Current packaged milestone: **0.7.0 — Messaging, Booking & Member Requests**

Repository: `afterburn25/velvet_vixxen`

Branch: `feature/0.7.0-messaging-booking-requests`

The package and GitHub repository are separate truth boundaries. Verify live branch, pull request, commit, workflow, merge, and release state before making claims.

## Locked decisions

- Approved neon-pink design; no new mockups unless explicitly requested
- Single responsive browser installer page
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775`; never `0777`
- Organized folders
- One Entitlement Resolver for subscription, PPV, rental, bundle, gift, and complimentary access
- Add-ons extend registered APIs rather than patching core
- Private messages, booking/request data, internal notes, moderation data, guest tokens, and attachments are sensitive runtime data
- Email alerts omit private message body content
- Quotes do not automatically charge members or form final contracts

## 0.7.0 implementation map

```text
app/Core/CommunicationStoreTrait.php
app/Core/CommunicationService.php
app/Core/RequestAttachmentService.php
app/Views/admin/communications.php
booking/index.php
account/messages.php
account/message.php
account/requests.php
account/request.php
account/booking.php
account/notifications.php
requests/attachment.php
admin/export.php
public/assets/css/communication.css
public/assets/css/admin-communication.css
database/migrations/202610180011_messaging_booking_requests.php
tests/Unit/communication.php
tests/Feature/messaging_booking_requests.php
tests/Security/communication_security.php
tests/Http/communications_http.py
tests/Browser/messaging_booking_playwright.py
```

## Security behavior

- Member access is ownership-scoped.
- Attachments are stored under `storage/private/request-attachments/` and served only after authorization.
- Guest booking status uses a high-entropy token; lookup uses its SHA-256 hash.
- Guest/member pages never expose owner-only notes.
- Contact blocks stop new contact while preserving history.
- CSV exports require owner authentication.

## Local verification

```text
bash scripts/test.sh      PASS
bash scripts/test_http.sh PASS
```

Validated: 160 PHP files, 6 public JavaScript files, 11 Python files, 11 migrations, 66 tables, member/commerce/add-on/licensing/communications HTTP workflows, protected attachments, tokenized guest status, moderation, blocks, notifications, saved replies, status/quote transitions, exports, CSRF, and authentication.

Release SHA-256: `8f4d0497c7463c4526cea52fbb66cf55d59c4cc76ac03e34eb380c3310fb8c96`

## Limits

- No local PDO MySQL/MySQL; CI owns live DDL validation.
- Local interactive Chromium is blocked by managed policy; CI owns six Playwright suites.
- Payment remains sandbox-only.
- Messaging is asynchronous; no websocket presence, calling, or automatic quote payment is claimed.
- The licensing hub is not a deployed public marketplace.

## Repository work still required

- Import the complete clean 0.7.0 source.
- Run PHP 8.2/8.3, MySQL 8, HTTP, repository-safety, and six Playwright jobs.
- Correct CI-only findings.
- Update this file with imported commit and exact workflow evidence.
- Merge only after all required gates are green.

## Next milestone

**0.8.0 — Marketing, Publishing & Analytics**

Do not merge documentation-only work as though it contains the executable source.
