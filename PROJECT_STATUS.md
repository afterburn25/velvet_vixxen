# Project Status — Nikki Wilder / The Velvet Vixxen

## Current packaged release

**0.7.3 — Installer Verification & Simplified Setup Hotfix**

The public installation package remains runtime-only: it contains only the files required to install and run the website. Repository documents, tests, SDK source, screenshots, and development tooling stay outside the customer ZIP.

## 0.7.3 corrections

- Fixed the reported final setup failure: `Final configuration verification failed`.
- Configuration writes now invalidate PHP stat/OPcache entries.
- The activated `config/app.php` bytes are read back and SHA-256 checked.
- Exact settings are loaded through a unique one-time verification path, avoiding a stale fixed-path cache after atomic replacement or ownership repair.
- Final verification checks installed state, version, installation ID, URL, security keys, database reconnection, seeded site settings, and install lock independently.
- Verification failures now identify the exact mismatched component instead of returning only a generic message.
- The one-page installer has been simplified to Database, Owner and Website, and Install.
- Server/folder details are collapsed and show only failures or warnings.
- Support/sender email defaults to the owner email, outgoing email defaults to Configure Later, currency defaults to USD, time zone defaults to America/Chicago, and the 18+ gate starts enabled.

## Preserved behavior

- No installation key.
- Exact 12-character minimum owner/member passwords.
- Safe `0755`/`0775` permissions; never `0777`.
- Automatic ownership-safe repair of panel-owned runtime folders.
- Versioned migrations, rollback attempts, installation lock, boot verification, and `/install` cleanup.
- Approved responsive design, Visual Composer, Canva workflow, Media Center, members/entitlements, subscriptions, PPV, add-ons, licensing/updates, messaging, booking, and member requests.

## Package evidence

- Runtime files: 186
- Runtime PHP files: 113
- Public JavaScript files: 6
- Database migrations: 11
- Declared tables: 64
- ZIP integrity: passed
- PHP/JavaScript syntax: passed
- Simplified installer HTTP rendering and AJAX recheck: passed
- Stale-cache and replaced-config-directory verification test: passed
- Shared-hosting ownership mismatch simulation: 75 directories, 0 failures, ready
- Runtime-only exclusions and clean uninstalled state: passed
- Package SHA-256: `202cb028ff7b61918d718a69604fa0a82db51ada0a0152379af8ba3b401a9eb0`

## Environmental limits

- Local PDO MySQL/MySQL remains unavailable, so live DDL execution is not claimed locally.
- Full interactive Playwright acceptance remains CI-owned.
- The payment provider remains sandbox-only.

## Repository truth boundary

- Repository: `afterburn25/velvet_vixxen`
- Hotfix branch: `fix/0.7.3-installer-verification`
- The complete executable source import and full MySQL/Playwright CI matrix remain separate tracked work.
- Never commit credentials, generated keys, customer records, private communications/attachments, signing keys, runtime state, or private/paid media.

## Next actions

1. Deploy and validate 0.7.3 on the affected shared-hosting account.
2. Import the complete clean engineering source separately from the runtime ZIP.
3. Run PHP 8.2/8.3, MySQL 8, HTTP, repository-safety, and Playwright CI.
4. Resume **0.8.0 — Marketing, Publishing & Analytics** after the installer hotfix is confirmed.
