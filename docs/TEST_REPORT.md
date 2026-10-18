# Velvet Vixxen 0.8.0 Test Report

## Release

**Nikki Wilder — The Velvet Vixxen 0.8.0**  
Milestone: **Marketing, Publishing & Analytics**

Runtime package:

```text
Nikki-Wilder-Velvet-Vixxen-0.8.0.zip
SHA-256: ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993
```

The public ZIP contains only files needed to install and operate the site. Repository files, GitHub workflows, development documentation, tests, test reports, screenshots, and SDK source are not included.

## Source and package validation

The completed runtime tree and a separate clean extraction of the final ZIP were validated.

- 203 runtime files
- 128 PHP files passed `php -l`
- 7 public JavaScript files passed `node --check`
- 12 ordered database migrations loaded successfully
- 75 unique application tables were declared
- ZIP integrity check passed
- Published SHA-256 check passed
- Configuration began uninstalled
- No installation lock or runtime data store was packaged
- No database password or generated application/security key was packaged
- No world-writable entries were present
- No `chmod(..., 0777)` call was present
- No obvious private signing key or production payment-key pattern was present
- No `.git`, `.github`, `/docs`, `/tests`, Python test files, project handoff files, or development reports were packaged

## Marketing file-store suite

Result: **PASS**

Verified:

- Marketing default settings
- Feature and retention setting updates
- Public and scheduled editorial posts
- Public visibility timing
- Newsletter single and double opt-in behavior
- Signed confirmation and unsubscribe tokens
- Subscriber tags and segmentation
- Campaign queue creation and delivery-state updates
- Timed promotional campaigns and banners
- Link-in-bio click accounting
- Referral clicks, orders, and attributed revenue
- Anonymous first-party analytics summaries
- Scheduled Hero composition snapshots
- Due-scene publishing
- Concurrent-safe scene claim status
- Published-scene persistence without stale file-store overwrite
- Scheduled-scene cancellation
- Analytics and consent-record retention cleanup
- Marketing dashboard statistics
- Marketing task-runner result structure

## Marketing HTTP acceptance

Result: **PASS**

Verified through real PHP HTTP requests:

- Homepage news and newsletter sections
- Blog index and post page
- Link-in-bio page
- Timed campaign landing page
- Newsletter subscription form and CSRF validation
- Same-origin analytics collection
- Foreign-origin analytics rejection
- Owner login
- Marketing administration
- Real editorial post creation and save
- Scheduled Hero/Footer scene administration
- Real scheduled Hero publication through the marketing task runner
- Analytics administration
- Posts, newsletter, referrals, and analytics CSV exports
- Referral-code capture

## Full-site HTTP smoke test

Result: **PASS**

The final runtime tree successfully rendered:

- Home
- Music
- Photo galleries
- Videos
- Membership
- Store
- Booking
- Blog
- Newsletter
- Link-in-bio
- Campaign landing page
- Account login and registration
- Cart
- Owner login

Feature-disable behavior was also verified. Blog, newsletter signup, link page, post detail, and redirect routes returned 404 when their corresponding modules were disabled, while the unsubscribe endpoint remained available for safety and compliance.

## Installer smoke test

Result: **PASS**

Verified:

- An uninstalled homepage redirects to `/install/`
- The simplified one-page installer renders
- Database, Owner and Website, and Server/Folder readiness sections render
- AJAX readiness recheck succeeds
- Readiness diagnostics contain system and file results
- Existing 0.7.3 installer hardening remains intact, including no install key, 12-character minimum passwords, safe permission handling, ownership-safe folder repair, migrations, configuration verification, installation lock, and installer cleanup

A complete MySQL installation was not executed locally because this environment provides PDO but not PDO MySQL or a MySQL/MariaDB server.

## Screenshot evidence

Eight screenshots were created from HTML fetched from the running local PHP application with its actual CSS and image assets:

- Desktop homepage
- Mobile homepage
- Blog
- Desktop link-in-bio page
- Mobile link-in-bio page
- Marketing administration
- Analytics administration
- Scheduled-scene administration

The browser environment is managed and direct local navigation may be blocked. The screenshots were rendered from fetched application output with local assets fulfilled inside Chromium; they are visual evidence, not a substitute for interactive Playwright acceptance.

## Environmental boundaries

- PHP available locally: 8.4.24
- Node available locally: 22.16.0
- Python available locally: 3.13.5
- PDO MySQL/MySQL/MariaDB unavailable locally
- Live migration apply/rollback remains a GitHub CI or hosting-environment gate
- Full interactive Chromium/Playwright navigation remains a CI gate
- The bundled payment adapter remains sandbox-only

## Final result

**PASS for all locally available source, runtime-package, file-mode, HTTP, installer, integrity, and security gates.**

The release remains subject to MySQL migration apply/verify/rollback and interactive browser acceptance in CI or a representative hosting environment.
