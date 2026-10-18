# Nikki Wilder — The Velvet Vixxen

**Current packaged release: 0.5.0 — Subscriptions & PPV Commerce**

Velvet Vixxen is a full-stack PHP/MySQL creator platform built around the approved neon-pink Nikki Wilder design. The tested 0.5.0 package adds subscriptions, pay-per-view photo galleries and videos, rentals, bundles, coupons, receipts, refunds, billing history, signed webhooks, and automatic content entitlements on top of the Media Center and member system.

## Repository status

This repository is the roadmap, testing, decision, and session-handoff hub. The complete executable 0.5.0 source is **not yet imported into this branch**, so do not describe GitHub `main` as the 0.5.0 application release until issue #1 is completed and CI is green.

Active tracking:

- Source import and CI: issue #1
- 0.5.0 delivery and release gates: issue #5
- Working branch: `feature/0.5.0-subscriptions-ppv-commerce`
- Next milestone: `0.6.0 — Add-on Framework`

## Implemented in the tested 0.5.0 package

### Public and media experience

- Responsive pink/magenta/black design
- Home, Music, Photos, Videos, Membership, Store, Cart, Checkout, Account, and authentication routes
- Persistent audio player with albums, playlists, queue, seek, volume, shuffle, repeat, and remembered state
- Photo galleries with previews, full-screen viewer, keyboard navigation, zoom, captions, and mobile swipe
- Video library with posters, trailers, protected full media, captions, and Continue Watching
- Signed protected-media delivery with expiry, HMAC validation, path checks, MIME handling, and HTTP Range support

### Members and access

- Registration, email verification, login, recovery, reset, lockout, and revocable device sessions
- Exact 12-character minimum owner/member passwords
- Member dashboard, My Library, Favorites, playback history, profile/security settings, and device management
- Monthly/annual plan metadata, trials, benefits, and PPV discounts
- Unified access rules for public, registered, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted content

### Subscriptions and PPV commerce

- Public Store and session cart
- Member-only checkout
- Versioned payment-provider contract and registry
- Working sandbox adapter with approved, declined, and processing outcomes
- Monthly and annual subscription checkout
- PPV photo galleries and videos
- Time-limited rentals
- Subscriber prices and membership PPV discounts
- Photo/video bundles
- Coupons with scope, date, threshold, total-use, and per-member limits
- Configurable currency, tax percentage, store state, and receipt footer
- Orders, line items, transactions, receipts, billing history, and queued receipt email
- Automatic subscription and entitlement fulfillment
- Full refunds with related access revocation
- Cancel-at-period-end and resume-renewal controls
- Signed replay-safe webhooks for paid, refunded, and chargeback events
- Sandbox renewal runner; non-sandbox renewals wait for provider webhooks
- Commerce, Orders, Coupons, Bundles, Payment Settings, and Refund administration

### Installer and project structure

- One-page browser installer
- No installation key
- Exact 12-character minimum owner password
- PHP/MySQL and real filesystem preflight
- Safe `0755`/`0775` permission handling; no `0777`
- Eight migrations declaring 44 application tables
- Installation lock, boot verification, and `/install` cleanup or secure retirement
- Organized application folders instead of root-file sprawl

## Payment boundary

The bundled provider is **sandbox-only**. No live processor account, credentials, approval, hosted checkout, or production payment pass is claimed. A live adapter must use provider-hosted or tokenized collection and pass provider-specific purchase, renewal, decline, webhook, refund, chargeback, and failure-recovery tests before production use.

## Test evidence

The clean packaged source passed:

- 101 PHP syntax checks
- 6 public JavaScript syntax checks
- 5 Python test syntax checks
- non-database source suite
- installed-demo member and commerce HTTP acceptance
- 8-migration / 44-table manifest verification
- protected-media, member-security, webhook-security, structure, and repository-safety tests

Local MySQL was unavailable, so disposable MySQL 8 apply/verify/rollback belongs to GitHub CI. Local interactive Chromium was blocked by managed browser policy, so Playwright interaction belongs to GitHub CI. See `docs/TEST_REPORT.md`.

## Start every session here

1. `AGENTS.md`
2. `PROJECT_STATUS.md`
3. `SESSION_HANDOFF.md`
4. `docs/ROADMAP.md`
5. `docs/DECISIONS.md`
6. `docs/TESTING.md`
7. `docs/TEST_REPORT.md`
8. Open issues, pull requests, and CI status

Never commit production credentials, customer records, identity documents, payment secrets, private messages, runtime data, or private/paid media to this public repository.
