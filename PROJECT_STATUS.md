# Project Status — Nikki Wilder / The Velvet Vixxen

## Authoritative release

**0.2.2 — One-Page Browser Installer**

## Complete in this release

- Approved pink public homepage foundation
- Hero and Footer Visual Composer
- Responsive layer arrangements
- Media library and custom thumbnails
- Canva template library and import workflow
- Owner authentication and settings
- Fancy single-page browser installer
- All installer sections visible on one responsive page
- Inline requirement, database, and optional email testing
- AJAX installation and live progress without Next/Back installer pages
- No setup key
- Exact 12-character minimum owner password
- Folder creation, safe chmod attempts, and real filesystem probes
- Versioned migrations, checksums, and clean-install rollback attempts
- Database privilege verification
- Installation lock, database state, boot verification, and installer removal
- Proper folder organization with `/includes` and `/images`
- Database foundations for add-ons, Media Center, subscriptions, PPV photo galleries, PPV videos, orders, and entitlements

## Foundation present but user-facing module not yet complete

- Music player, albums, songs, and playlists
- Photo-gallery publishing and lightbox
- Video publishing, trailers, and streaming player
- Member registration and member dashboard
- Recurring subscriptions
- PPV photo-gallery and PPV-video checkout
- Payment-processor adapters
- Add-on upload/install/activate/update UI
- Commercial add-on licensing and marketplace

## Next release target

**0.3.0 — Media Center**

The next build implements music, photo, and video administration and public playback/viewing on top of the schema installed by 0.2.2.

## Repository and testing

- Authoritative repository: `afterburn25/velvet_vixxen`
- Last-known-good branch: `main`
- Active milestone work belongs on `feature/<version>-<topic>` branches
- Automated source tests: `.github/workflows/ci.yml`
- Local test entry point: `bash scripts/test.sh`
- MySQL integration test: `php tests/Database/migrations_mysql.php` with disposable test-database environment variables
- This repository is public; production secrets, identity documents, customer data, and private or paid media must never be committed
