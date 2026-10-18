# Agent Instructions — Velvet Vixxen

## Source of truth

Repository: `afterburn25/velvet_vixxen`

Current packaged target: **0.8.0 — Marketing, Publishing & Analytics**

Delivery branch: `feature/0.8.0-marketing-publishing-analytics`

Read `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, `docs/TEST_REPORT.md`, `RELEASE_MANIFEST.txt`, and `SECURITY.md` before changing code.

The runtime package and repository source-import state are separate truth boundaries. Verify the live branch, issue, pull request, workflow, merge, tag, and release state before making claims.

## Locked requirements

- Preserve the approved neon-pink/magenta/black design.
- Do not create more mockups unless explicitly requested.
- Keep the installer on one simplified responsive browser page.
- Require no installation key.
- Use a 12-character minimum password, not 14.
- Never use `chmod 0777`.
- Preserve ownership-safe writable-directory repair.
- Keep application files organized.
- Preserve Visual Composer, Canva workflow, Media Center, members, entitlements, PPV/subscriptions, add-ons, licensing, communications, publishing, newsletter, promotions, referrals, and analytics.
- Customer ZIPs contain only files required to install and run the live site.
- Repository docs, tests, SDK source, workflows, screenshots, and development tooling remain outside the customer ZIP.

## 0.8.0 invariants

- Marketing modules can be disabled independently; disabled public routes return 404.
- Unsubscribe remains available even when public newsletter signup is disabled.
- Newsletter campaigns honor confirmation, consent, unsubscribe, and suppression state.
- Private message bodies never enter email alerts or analytics payloads.
- Analytics reject foreign-origin collection and prohibited sensitive fields.
- Analytics never contain raw card data, protected-media contents, identity documents, or attachment contents.
- Referral attribution is preserved through registration and checkout without exposing private data.
- Scheduled scene publication is lock-aware, idempotent, and uses a `processing` claim state.
- File-store scene publication must re-read the scene store after publishing and must not overwrite fresh scene data with stale state.
- State-changing owner and public requests require CSRF or signed-token validation as appropriate.
- CSV exports must escape spreadsheet formula prefixes.
- Retention cleanup must not remove active consent/suppression records needed for compliance.

## Implementation map

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

## Required release gates

At minimum, verify:

- PHP syntax for every runtime/source PHP file
- JavaScript syntax for public scripts
- Migration manifest and declared-table uniqueness
- Publishing state and visibility behavior
- Newsletter confirmation, unsubscribe, segmentation, and suppression
- Promotions, links, referrals, and attribution
- Same-origin analytics and foreign-origin rejection
- Scene scheduling, idempotency, and stale-state prevention
- Installer regression
- Runtime-only package exclusions and clean state
- Repository secret, generated-key, private-data, and unsafe-permission scan

CI must additionally run PHP 8.2/8.3, disposable MySQL apply/verify/rollback, and interactive desktop/mobile browser acceptance. Record exact evidence in status, handoff, roadmap, testing, and test-report documents.

## Public repository safety

Never commit production credentials, generated secrets, payment tokens, raw license keys, signing private keys, private conversations, request/booking data, owner notes, subscriber/customer records, consent exports, identity documents, attachments, runtime logs/sessions/backups, installed add-ons, or private/paid media.

## Truthfulness

Distinguish among locally tested package behavior, CI-prepared behavior, planned work, and real production-service integration. Do not claim source import, MySQL, Playwright, live payments, production email delivery, public licensing SaaS, merge, tag, or release without direct evidence.

## Next milestone

After 0.8.0 source import and CI, proceed to **0.9.0 — Compliance, Privacy & Operational Hardening**.
