# Project Status — Nikki Wilder / The Velvet Vixxen

## Current application build

**0.3.0 — Media Center** has been implemented, packaged, and locally verified in the active development session.

The executable 0.3.0 source has **not yet been imported into this GitHub repository**. That remaining repository task is tracked by issue #1. Until the import commit lands and its full CI matrix passes, do not describe the GitHub branch itself as the complete 0.3.0 source release.

## Implemented in the packaged 0.3.0 build

- Approved responsive neon-pink public design
- Hero and Footer Visual Composer with desktop/tablet/mobile arrangements
- Canva template and import workflow
- Owner authentication and administration foundation
- Single-page browser installer
- No installation key and exact 12-character minimum owner password
- Real filesystem probes, safe permission handling, versioned migrations, installation lock, and `/install` cleanup
- Organized folders including `/admin`, `/app`, `/database`, `/docs`, `/images`, `/includes`, `/install`, `/public`, `/storage`, `/templates`, `/tests`, and `/uploads`
- Public Music, Photos, Gallery, Videos, and Video Watch routes
- Persistent audio player with queue, seek, volume, shuffle, repeat, remembered state, and Media Session metadata
- Photo-gallery administration, ordering, previews, scheduling, and full-screen keyboard/zoom/mobile-swipe viewer
- Video poster, trailer, protected full media, captions, scheduling, player, and Continue Watching state
- Signed expiring protected-media delivery
- Homepage featured tracks, galleries, and video
- Six migrations declaring 34 application tables

## Verified local testing

- 60 PHP files pass syntax validation
- 6 public JavaScript files pass syntax validation
- Smoke, installer, single-page installer, migration manifest, Media Center, protected-media, organized-folder, and repository-safety tests pass
- Real Chromium acceptance passes desktop/mobile Home, Music, Photos, Gallery, Videos, owner login, and actual Track/Gallery/Video save flows
- The clean release package contains no installed lock, database credentials, signing keys, sessions, logs, or runtime customer data

The local packaging environment does not provide PDO MySQL or a MySQL/MariaDB server, so live DDL execution was not claimed there. The source contains a disposable MySQL 8 apply/verify/rollback test for CI once source import is complete.

## Repository communication hub

- Repository: `afterburn25/velvet_vixxen`
- Last-known-good documentation branch: `main`
- Prepared implementation branch: `feature/0.3.0-media-center`
- Source import task: issue #1
- Media Center tracking: issue #2
- Release test-gate tracking: issue #3
- Repository contract CI: passing on `main`

## Features still planned after source import

- Member registration, verification, recovery, and customer dashboard
- Membership tiers and unified entitlements
- Recurring subscriptions
- PPV photo-gallery and PPV-video checkout and rentals
- Payment-provider adapters, webhooks, refunds, and chargebacks
- Add-on ZIP manager, commercial licensing, and marketplace
- Messaging, booking workflow, marketing automation, and compliance vault

## Next release target

**0.4.0 — Members & Entitlements**, after issue #1 is completed and the imported 0.3.0 source passes GitHub CI.

This repository is public. Production credentials, identity documents, private customer information, payment secrets, private messages, runtime logs, and private or paid media must never be committed.
