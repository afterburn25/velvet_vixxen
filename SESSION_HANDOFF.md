# Session Handoff — Velvet Vixxen 0.8.0

## Resume point

Current completed runtime baseline: **0.7.1 — Ownership-Safe Installer Hotfix**

Current development milestone: **0.8.0 — Marketing, Publishing & Analytics**

Repository: `afterburn25/velvet_vixxen`

Branch: `feature/0.8.0-marketing-publishing-analytics`

Tracking issue: `#9`

Implementation plan: `docs/0.8.0_IMPLEMENTATION_PLAN.md`

The customer installation ZIP remains runtime-only. Repository documents, tests, SDK source, screenshots, and CI workflows stay outside it.

## Baseline artifact

Runtime package:

```text
Nikki-Wilder-Velvet-Vixxen-0.7.1.zip
```

SHA-256:

```text
a5c311e2462b422246eb8282142e33c4157d91dab74bfdf029d42fb476a72d06
```

The 0.7.1 installer repairs shared-hosting ownership mismatches for writable runtime directories without mode `0777`. Preserve this behavior in every later release.

## Locked decisions

- Approved neon-pink design; no new mockups unless explicitly requested
- One responsive browser installer page
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775`; never `0777`
- Ownership-safe writable-directory repair and content preservation
- Organized folders rather than root-level file sprawl
- One Entitlement Resolver for subscription, PPV, rental, bundle, gift, and complimentary access
- Add-ons extend registered APIs rather than patching core
- Private communications, booking/request data, internal notes, attachments, and subscriber records are sensitive runtime data
- Installation ZIPs contain only files required to install and run the live site
- Marketing email must honor consent, unsubscribe, and suppression records
- Private message bodies must never appear in email alerts or analytics payloads
- Analytics must not collect raw card data, protected-media contents, identity records, or attachment contents

## 0.8.0 implementation order

### 1. Database migration

Create the versioned marketing/publishing migration after the existing 0.7.0 migration. Finalize table names and rollback order before wiring the UI.

Planned areas:

- Posts, revisions, categories, tags, and associations
- Editorial calendar
- Campaigns, banners, and landing pages
- Newsletter lists, subscribers, consent, suppression, segments, templates, campaigns, recipients, and delivery events
- Link-in-bio records
- Referral codes and attribution
- Analytics events and daily rollups

### 2. Core services

Implement and test:

- `PublishingService`
- `MarketingService`
- `NewsletterService`
- `ReferralService`
- `AnalyticsService`

Keep storage implementations compatible with the existing MySQL and file-backed test architecture.

### 3. Public and administration interfaces

Add:

- Public Blog/Updates index and post pages
- Campaign landing route
- Link-in-bio route
- Owner Publishing editor
- Editorial Calendar
- Campaign and banner management
- Newsletter lists, segments, templates, campaigns, and delivery log
- Analytics dashboard and CSV exports

### 4. Scheduled task runner

Add the runtime operational script:

```text
scripts/run_marketing_tasks.php
```

It must be lock-aware and idempotent. It will process scheduled posts, expiration, banners, Visual Composer scenes, campaign email batches, automated email jobs, analytics rollups, and retention cleanup.

### 5. Tests

Add unit, feature, security, HTTP, MySQL, and browser coverage for:

- Editorial state and scheduling
- Revision restore
- Tier visibility
- Campaign activation and attribution
- Banner frequency caps
- Consent, unsubscribe, suppression, and segmentation
- Email template safety and signed links
- Analytics sensitive-field rejection
- Rollups and retention
- CSV formula-injection prevention
- Runtime-only package exclusions

## Current repository work completed

- 0.8.0 branch created
- Issue #9 created
- Detailed implementation plan committed
- README and PROJECT_STATUS updated to the 0.7.1 baseline and 0.8.0 development truth boundary

## Work not yet complete

- Executable 0.8.0 code
- Marketing/publishing database migration
- Public/admin interfaces
- Marketing scheduled-task runner
- New tests
- Runtime 0.8.0 ZIP
- MySQL and Playwright evidence

Do not represent the specification branch as the completed executable milestone.

## Runtime-only packaging rule

The installation ZIP may contain the application, installer, migrations, assets, default email/template assets, writable runtime directories, starter add-on packages, and operational runners. It must exclude:

- `.git` and `.github`
- `/docs` and `/tests`
- roadmap/status/handoff/contribution/security documents
- SDK source
- GitHub workflows
- development schema snapshots
- test/demo/cleanup/build scripts
- screenshots and test reports
- runtime credentials, generated keys, locks, logs, sessions, customer records, subscriber data, private messages, or attachments

## Known boundaries

- The payment provider remains sandbox-only until a production-approved adapter passes its lifecycle tests.
- PHP add-ons are not fully sandboxed on ordinary shared hosting.
- Local MySQL and Chromium evidence must be stated honestly based on the environment available to the implementing session.

## Definition of next successful checkpoint

A meaningful next checkpoint is the marketing/publishing migration plus tested core service skeletons—not another concept design. Update this file and `PROJECT_STATUS.md` with exact implemented files and test evidence when that checkpoint lands.
