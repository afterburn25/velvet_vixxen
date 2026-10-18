# Nikki Wilder — The Velvet Vixxen

**Current packaged release: 0.7.3 — Installer Verification & Simplified Setup Hotfix**

Velvet Vixxen is a full-stack PHP/MySQL creator platform using the approved neon-pink Nikki Wilder design. It combines a visual site composer, Canva-ready templates, music, photo galleries, video, member accounts, subscriptions, PPV commerce, installable commercial add-ons, and private communication/request workflows.

## 0.7.3 installer hotfix

- Corrects the shared-hosting failure reported as `Final configuration verification failed`.
- Verifies the exact activated configuration bytes and invalidates PHP stat/OPcache entries after atomic replacement.
- Loads the final configuration through a unique one-time verification path so a stale fixed-path cache cannot return the earlier uninstalled configuration.
- Verifies installed state, version, installation ID, base URL, security keys, database reconnection, seeded site settings, and install lock independently.
- Reports the exact failed verification component rather than only a generic final error.
- Simplifies the single-page installer to three visible sections: Database, Owner and Website, and Install.
- Collapses technical server/folder diagnostics and shows only warnings or failures.
- Defaults support/sender email to the owner email and outgoing email to Configure Later.

## Existing 0.7.x platform

- Private member-to-owner conversations and protected attachments
- Content requests, quotes, statuses, and owner/member replies
- Public and member booking/appearance inquiries
- Music, photo galleries, video, memberships, subscriptions, PPV, rentals, bundles, and coupons
- Add-on installation, rollback, commercial licensing, and signed remote updates
- Eleven migrations / 64 declared application tables

## Runtime-only installation ZIP

The public release ZIP contains only files required to install and run the website. It excludes `.git`, `.github`, repository documents, `/docs`, `/tests`, SDK source, screenshots, release reports, development schema snapshots, and test/demo/build scripts. Operational billing and add-on task runners remain included.

## Locked installation requirements

- One responsive browser installer page
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775` permissions; never `0777`
- Ownership-safe runtime-folder repair for shared hosting
- Versioned migrations and rollback attempts
- Installation lock and `/install` deletion or secure retirement
- Organized folders rather than root-level file sprawl

## 0.7.3 verification evidence

- 113 runtime PHP files passed syntax validation
- 6 public JavaScript files passed syntax validation
- 11 migrations / 64 declared application tables
- Simplified installer HTTP render and AJAX recheck passed
- Configuration verification passed with CLI OPcache enabled and timestamp validation disabled
- Configuration verification passed after replacing the parent `/config` directory
- Shared-hosting ownership simulation: 75 checked directories, 0 failures, installer ready
- Runtime package: 186 files
- Package SHA-256: `202cb028ff7b61918d718a69604fa0a82db51ada0a0152379af8ba3b401a9eb0`

Local live MySQL DDL execution is not claimed because PDO MySQL/MySQL was unavailable. Interactive Playwright remains CI-owned. Payment remains sandbox-only.

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

Hotfix branch: `fix/0.7.3-installer-verification`

The runtime package and repository source/CI are separate truth boundaries. Do not describe `main` as the complete executable 0.7.3 source until clean source import, full CI, review, and merge are directly verified.

Never commit credentials, customer/member records, private conversations, booking/request records, attachments, identity documents, payment secrets, raw license keys, signing private keys, runtime state, or private/paid media.

## Next milestone

**0.8.0 — Marketing, Publishing & Analytics**, after the 0.7.3 installer hotfix is confirmed on the affected host.
