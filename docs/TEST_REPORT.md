# Test Report — 0.7.0 Messaging, Booking & Member Requests

## Local source suite

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Result: **passed**

Verified:

- PHP syntax: 160 source files
- Public JavaScript syntax: 6 files
- Python HTTP/Playwright syntax: 11 files
- Smoke and one-page installer regression
- Migration manifest: 11 migrations / 66 application tables
- Media, member, entitlement, commerce, add-on, licensing, and communication unit/feature tests
- Protected media, member security, payment webhook, add-on package, licensing, communication, organized-folder, and repository-safety tests

Communication coverage includes ownership, blocks, thread statuses, read/unread state, saved replies, category questions, request/booking quotes and acceptance, notifications, history, private notes, moderation, attachment allowlists/size limits, protected paths, guest-token lookup and rejection, member/owner attachment authorization, export authentication, and CSRF.

## Installed-demo HTTP acceptance

```bash
VELVET_TEST_PORT=8789 bash scripts/test_http.sh
```

Result: **passed**

Passed suites:

- Members & Entitlements
- Subscriptions & PPV Commerce
- Add-on Framework
- Commercial Add-on Licensing & Remote Updates
- Messaging, Booking & Member Requests

The communications suite verified public booking, member dashboard/inbox/new conversation/reply, content-request quote acceptance, booking confirmation, notifications, owner queues and saved replies, request/booking updates, moderation, authenticated CSV exports, and private guest-token status.

## Clean package verification

The final ZIP was extracted into a separate clean directory. The extracted copy started uninstalled, passed the complete source suite, passed all five HTTP suites, was cleaned back to an uninstalled state, passed repository safety, and passed checksum verification.

Release SHA-256: `8f4d0497c7463c4526cea52fbb66cf55d59c4cc76ac03e34eb380c3310fb8c96`

## Browser status

Six Playwright suites exist and pass Python syntax validation. **Local interactive Chromium is not claimed.** A direct local run using `/usr/bin/chromium` returned `net::ERR_BLOCKED_BY_ADMINISTRATOR` at first navigation. The managed policy was not bypassed. Static captures document real server-rendered output but do not replace interactive acceptance.

## MySQL status

**Local live DDL execution is not claimed.** PDO MySQL and a MySQL/MariaDB server were unavailable. CI must apply all eleven migrations, verify 66 tables and communication columns, exercise an add-on-owned migration, roll back everything, remove the registry, and leave the disposable database empty.

## Security boundaries

- Private attachments are outside public asset folders and require authorization.
- Member access follows the linked conversation/request/booking ownership.
- Guest booking access requires the matching private token.
- Guest/member pages omit internal notes and unrelated account data.
- Public guest tokens use high entropy; lookup uses a SHA-256 hash.
- Email alerts omit private body text.
- Blocks prevent new contact without destroying history.
- CSV exports require owner authentication.
- Payment remains sandbox-only.

## Repository gates still required

The complete executable source must be imported before GitHub can run PHP 8.2/8.3, MySQL 8, HTTP, repository safety, and six Playwright jobs against this milestone. Project status and session handoff must record the exact imported commit and workflow evidence before merge.
