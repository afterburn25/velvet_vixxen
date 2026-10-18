# Session Handoff — Velvet Vixxen 0.7.0

## Resume point

Current completed package: **0.7.0 — Messaging, Booking & Member Requests**

Repository: `afterburn25/velvet_vixxen`

Branch: `feature/0.7.0-messaging-booking-requests`

Use the clean 0.7.0 source tree as the development baseline. The public installation ZIP is runtime-only and deliberately excludes repository, documentation, testing, screenshot, and SDK material.

## Locked decisions

- Approved neon-pink design; no new mockups unless explicitly requested
- Single responsive browser installer page
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775`; never `0777`
- Organized folders rather than root-level file sprawl
- One Entitlement Resolver for subscription, PPV, rental, bundle, gift, and complimentary access
- Add-ons extend registered APIs rather than patching core
- Private messages, booking/request data, internal notes, and attachments are sensitive runtime data
- Installable ZIPs contain only files needed to install and run the actual site
- Repository docs/tests/SDKs/screenshots remain outside the installation ZIP

## 0.7.0 implementation map

```text
app/Core/CommunicationStoreTrait.php
app/Core/CommunicationUploadService.php
app/Views/admin/communications.php
booking/index.php
messages/index.php
requests/index.php
communication/download.php
public/assets/css/communication.css
public/assets/css/admin.css
public/assets/css/member.css
database/migrations/202610180011_messaging_booking_requests.php
tests/Unit/communications.php
tests/Feature/communications.php
tests/Http/communications_http.py
```

## Delivered behavior

- Private member-to-owner conversations and owner replies
- Protected communication attachments
- Read/unread counts, statuses, priorities, notes, blocks, and history
- Saved replies
- Content-request intake, budget and quote fields, status workflows, and member history
- Public/member booking and appearance inquiries with protected reference files
- Owner message, request, booking, and communication-settings administration
- Member notifications and mail-outbox integration
- Dashboard communication summaries
- 11 migrations / 64 declared tables

## Required validation commands

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
bash scripts/clean_runtime.sh
php tests/Security/repository_safety.php
```

Current local result: all commands passed.

Runtime package SHA-256:

```text
98247f13d7a36f77f65100f0e86ba3dfc2741e84e75b40b02a9b93a4e12c7b2b
```

## Runtime-only packaging rule

The installation ZIP may contain the application, installer, migrations, assets, protected writable folders, starter add-on packages, and operational task runners. It must exclude:

- `.git` and `.github`
- `/docs` and `/tests`
- roadmap/status/handoff/contribution/security documents
- add-on SDK source
- GitHub workflows
- development SQL schema snapshot
- test/demo/cleanup/build scripts
- screenshots and test reports
- runtime credentials, generated keys, locks, logs, sessions, customer records, private messages, or attachments

## Known boundaries

- MySQL DDL execution was not available locally; CI owns live MySQL validation.
- Direct interactive Chromium is blocked by managed policy; CI owns Playwright acceptance.
- Payment remains sandbox-only until a production-approved provider adapter passes its lifecycle tests.
- PHP add-ons are not fully sandboxed on ordinary shared hosting.

## Repository work still required

- Import the complete clean 0.7.0 source separately from the runtime installation ZIP.
- Run PHP 8.2/8.3, MySQL 8, HTTP, repository-safety, and Playwright CI.
- Correct CI-only findings and record exact imported commit/workflow evidence.
- Merge only after required gates are green.

## Next milestone

**0.8.0 — Marketing, Publishing & Analytics**

Do not merge documentation-only work as though it contains the executable source.
