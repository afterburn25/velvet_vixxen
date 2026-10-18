# Session Handoff — Velvet Vixxen 0.8.0

## Resume point

Current completed runtime milestone: **0.8.0 — Marketing, Publishing & Analytics**

Repository: `afterburn25/velvet_vixxen`

Branch: `feature/0.8.0-marketing-publishing-analytics`

Tracking issue: `#9`

The customer installation ZIP is runtime-only. Repository documents, tests, SDK source, screenshots, and CI workflows stay outside it. The packaged runtime and GitHub source-import state are separate truth boundaries.

## Runtime artifact

```text
Nikki-Wilder-Velvet-Vixxen-0.8.0.zip
SHA-256: ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993
```

Package profile:

```text
203 runtime files
128 PHP runtime files
7 public JavaScript files
12 migrations
75 declared application tables
```

## Locked decisions

- Approved neon-pink design; no new mockups unless explicitly requested
- One simplified responsive browser installer page
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775`; never `0777`
- Ownership-safe writable-directory repair and content preservation
- Organized folders rather than root-level file sprawl
- One Entitlement Resolver for subscription, PPV, rental, bundle, gift, and complimentary access
- Add-ons extend registered APIs rather than patching core
- Customer ZIPs contain only files needed to install and run the live site
- Marketing messages must honor consent, confirmation, unsubscribe, and suppression state
- Unsubscribe remains available even when public newsletter signup is disabled
- Private message bodies, protected-media contents, identity documents, and raw payment data never enter email alerts or analytics payloads
- Scheduled marketing work must be lock-aware and idempotent
- A failed or repeated scene-task run must not publish the same scene twice or overwrite a newly published file-store scene with stale state

## 0.8.0 implementation map

```text
app/Core/MarketingStoreTrait.php
app/Core/MarketingService.php
app/Views/admin/marketing.php
api/analytics.php
blog/index.php
blog/post.php
campaign/index.php
links/index.php
links/go.php
newsletter/index.php
newsletter/confirm.php
newsletter/unsubscribe.php
public/assets/css/marketing.css
public/assets/js/marketing.js
scripts/run_marketing.php
database/migrations/202610180012_marketing_publishing_analytics.php
```

Additional integrations landed in the homepage, header/footer navigation, registration, checkout, installer manifest/seeds, default data, asset URL handling, administration routing, and CSV export handling.

## Delivered behavior

### Publishing

- Blog, personal-update, and announcement content
- Draft, scheduled, published, archived, and expiring states
- Public/member/subscriber visibility
- Categories, tags, SEO fields, featured artwork, and featured placement
- Public blog index and post pages
- Owner post creation and editing

### Newsletter and campaigns

- Single- and double-opt-in newsletter flows
- Signed confirmation and unsubscribe tokens
- Subscriber tags, segments, consent, and suppression state
- Campaign queues and delivery-state updates
- Timed promotions, site banners, and campaign landing pages
- Safe disable behavior: public routes return 404 when modules are disabled, but unsubscribe remains available

### Scenes, links, referrals, and analytics

- Scheduled Hero/Footer scene snapshots, publication, and cancellation
- Concurrent-safe `scheduled -> processing` scene claim
- Link-in-bio page and click accounting
- Referral clicks, orders, and attributed revenue
- Same-origin first-party analytics endpoint
- Foreign-origin analytics rejection
- Analytics summaries, dashboard, CSV exports, and retention cleanup
- Consent-record retention controls

## Verification completed

- 128 PHP runtime files passed syntax validation
- 7 public JavaScript files passed syntax validation
- 12 migrations / 75 tables passed the manifest check
- Marketing file-store suite passed
- Marketing HTTP suite passed
- Full-site HTTP smoke passed
- Simplified installer smoke passed
- Extracted final ZIP passed integrity, checksum, exclusions, clean-state, and security checks
- Final package contains no credentials, generated keys, installation lock, sessions, logs, customer/subscriber records, or private/paid media

## Environmental limits

- PHP 8.4.24, Node 22.16.0, and Python 3.13.5 were available locally.
- PDO MySQL and MySQL/MariaDB were unavailable. Live migration apply/verify/rollback is not claimed locally.
- Direct interactive browser navigation is managed in the packaging environment. Screenshots were rendered from real fetched application HTML and assets; full Playwright acceptance remains CI-owned.
- Payment remains sandbox-only.

## Repository work still required

- Import the complete clean 0.8.0 engineering source separately from the runtime ZIP.
- Run PHP 8.2/8.3, disposable MySQL 8, HTTP, repository-safety, and interactive Playwright jobs.
- Correct CI-only findings.
- Update this handoff with the imported commit and exact workflow evidence.
- Merge only after the required gates are green.

## Runtime-only packaging rule

The installation ZIP may contain the application, installer, migrations, assets, default email/template assets, writable runtime directories, starter add-on packages, and operational runners. It must exclude:

- `.git` and `.github`
- `/docs` and `/tests`
- roadmap/status/handoff/contribution/security documents
- SDK source
- GitHub workflows
- development schema snapshots
- test/demo/cleanup/build tooling
- screenshots and test reports
- runtime credentials, generated keys, locks, logs, sessions, customer records, subscriber data, private messages, or attachments

## Next milestone

**0.9.0 — Compliance, Privacy & Operational Hardening**

Planned next areas include performer/collaborator release records, complaint and takedown workflows, age-assurance/geographic adapters, two-factor authentication, privileged-action confirmation, backup/restore validation, health diagnostics, recovery mode, rate limiting, suspicious-access alerts, accessibility, and privacy review.
