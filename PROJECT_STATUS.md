# Project Status — Nikki Wilder / The Velvet Vixxen

## Current packaged release

**0.7.0 — Messaging, Booking & Member Requests**

A clean runtime-only package has been built and verified. It contains only the files required to install and run Velvet Vixxen; repository, documentation, tests, screenshots, SDK source, and development scripts are distributed separately rather than inside the installation ZIP.

## Completed and locally verified

### Foundation, design, media, and commerce

- One-page browser installer with no install key and exact 12-character minimum owner/member passwords
- Safe `0755`/`0775` permission handling; no `0777`
- Approved responsive neon-pink design, Visual Composer, and Canva-compatible workflow
- Music, photo galleries, videos, protected media, members, plans, entitlements, subscriptions, PPV, rentals, bundles, coupons, receipts, refunds, and sandbox webhooks
- Validated add-on lifecycle plus commercial licensing and signed remote-update foundations

### 0.7.0 communication center

- Member-to-owner private message threads
- Protected communication attachments stored outside public delivery paths
- Read/unread state, status, priority, internal notes, and activity history
- Saved owner reply templates
- Member content-request intake with category, details, budget range, quote, status, and owner replies
- Public and signed-in booking/appearance inquiry workflow
- Booking dates, location, venue/platform, budget, protected reference files, status, priority, and internal notes
- In-app member notifications and optional mail-outbox updates
- Communication blocking and configuration controls
- Member dashboard communication summaries and history
- Owner queues for messages, content requests, and booking requests
- Responsive public and administration interfaces

## Database and release evidence

- 11 versioned migrations
- 64 declared application tables
- 149 PHP source/test files passed syntax validation
- 6 public JavaScript files passed syntax validation
- 10 Python HTTP/Playwright files passed syntax validation
- `bash scripts/test.sh`: passed
- `bash scripts/test_http.sh`: passed
- Runtime-only ZIP contains 185 files, including 112 PHP runtime files
- Package SHA-256: `98247f13d7a36f77f65100f0e86ba3dfc2741e84e75b40b02a9b93a4e12c7b2b`

## Runtime-only ZIP policy

The installable ZIP intentionally excludes `.git`, `.github`, `/docs`, `/tests`, add-on SDK source, GitHub Actions, roadmap/status/handoff files, screenshots, release reports, test/demo/cleanup scripts, and the development SQL schema snapshot. Operational billing and add-on task runners remain included because the live site requires them.

## Environmental limits

- Local PDO MySQL/MySQL is unavailable. Live DDL execution is not claimed locally. CI must apply all 11 migrations, verify 64 tables, roll back, and require an empty disposable database afterward.
- Direct local interactive Chromium is blocked by a managed browser policy. The policy was not bypassed. HTTP acceptance passed; full Playwright acceptance remains CI-owned.
- The payment provider remains sandbox-only.
- The licensing hub remains a self-hosted foundation rather than a separately deployed marketplace SaaS.

## Repository truth boundary

- Repository: `afterburn25/velvet_vixxen`
- Documentation/delivery branch: `feature/0.7.0-messaging-booking-requests`
- The complete executable 0.7.0 source is not yet claimed as imported into GitHub.
- Do not call `main` the 0.7.0 executable release until source import, CI, review, and merge are directly verified.
- Never commit production credentials, customer records, identity documents, payment secrets, raw license keys, signing private keys, private messages/attachments, runtime state, or private/paid media.

## Next actions

1. Import the complete clean 0.7.0 source outside the public installation ZIP.
2. Run PHP 8.2/8.3, MySQL 8, HTTP, repository-safety, and Playwright CI.
3. Correct CI-only findings and record exact evidence.
4. Merge only after required checks are green.
5. Begin **0.8.0 — Marketing, Publishing & Analytics**.
