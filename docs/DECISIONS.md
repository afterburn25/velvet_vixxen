# Architecture and Product Decisions

## Product identity and design

- Product: Nikki Wilder — The Velvet Vixxen
- The approved neon-pink/magenta/black direction is the design source of truth.
- No additional mockups are created unless explicitly requested.
- User/model photographs remain replaceable layers; default public artwork stays people-free.
- Navigation, forms, paywalls, players, pricing, publishing controls, and accessibility remain live HTML/JavaScript.

## Installer and hosting

- Installation occurs on a single responsive browser page with simplified, collapsible sections.
- No installation key is required.
- Owner and member passwords have a 12-character minimum.
- File checks use real write/read/rename/delete probes.
- Safe permission attempts are `0755`/`0775`; `0777` is never accepted.
- Ownership mismatches on writable runtime folders are repaired without requiring world-writable permissions.
- Installation runs versioned migrations, verifies final configuration and boot, creates a lock, and deletes or securely retires `/install`.
- Files remain organized by responsibility.

## Distribution packaging

- The public installation ZIP contains only files required to install and run the actual site.
- Repository metadata, GitHub workflows, docs, tests, SDK source, screenshots, reports, and development-only tooling do not ship in the customer installation ZIP.
- Operational billing, add-on-task, and marketing runners remain included because the installed site requires them.
- The complete engineering source, tests, documentation, and SDK are committed to this repository. Runtime packages are generated from that same source; they exclude development files and installed data.

## Media, access, and commerce

- Music, photos, and video are first-class content types.
- One Entitlement Resolver handles public, member, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted access.
- Protected originals remain outside public paths and use signed/authorized delivery.
- Raw card numbers are never stored.
- Payment providers use adapters; the bundled provider is sandbox-only.

## Add-ons and licensing

- Add-ons never patch core files and are not described as fully sandboxed.
- Every package declares identity, compatibility, permissions, dependencies, conflicts, and integrity data.
- Unsafe paths, symlinks, package bombs, checksum failures, and invalid signatures are blocked.
- Remote updates enter the ordinary permission/compatibility review and backup/rollback lifecycle.
- Update/support expiration or a temporary service outage does not intentionally disable installed local functionality.
- Private signing keys, raw license keys, and protected releases never enter public source control.

## Messaging, requests, and booking

- Private messaging is asynchronous and account-scoped; it is not represented as real-time websocket chat.
- Conversations, requests, bookings, notifications, blocks, and attachments have explicit owner/member relationships.
- Email alerts announce activity and link to the site; they do not include private message bodies.
- Communication attachments live outside public asset paths and are delivered only through authorization checks.
- Guest/member pages never expose owner-internal notes or unrelated records.
- Contact blocks stop new activity while preserving prior history.
- Content-request and booking quotes are workflow records, not automatic charges or final legal agreements.
- Saved replies are owner conveniences; sending remains an explicit owner action.

## Publishing and marketing

These are design requirements; advanced audience/expiration, scheduled scenes, attribution, retention and MySQL marketing persistence were not recovered as implemented capabilities. Current code/evidence governs completion claims.

- Editorial content uses explicit draft, scheduled, published, archived, and expiration states.
- Public visibility is resolved by publication timing and audience rules rather than by obscured URLs alone.
- Newsletter consent, confirmation, unsubscribe, and suppression are distinct records and must be respected by every campaign send.
- Unsubscribe remains available even when newsletter signup is disabled.
- Email delivery uses the configured PHP-mail/SMTP abstraction; no production email provider is assumed.
- Private message bodies and protected content never appear in campaign or transactional email payloads.
- Promotional banners and campaign pages use live HTML around optional artwork rather than flattening functional content into images.
- Scheduled Visual Composer scenes use snapshots, a claim state, and idempotent task processing.
- File-store scene processing must re-read saved scene state after publication to avoid stale overwrite.
- Link-in-bio clicks and referral events are recorded without exposing private member data publicly.

## Analytics and privacy

- Analytics are first-party and same-origin by default.
- Foreign-origin collection requests are rejected.
- Analytics payloads exclude raw card data, payment credentials, protected-media contents, private-message bodies, attachments, identity records, and exact sensitive documents.
- Collection can be disabled independently from other marketing features.
- Analytics and consent data have configurable retention controls.
- CSV exports protect against spreadsheet formula injection.
- Screenshots support visual review but do not replace interactive browser acceptance.

## Repository and evidence

- Repository: `afterburn25/velvet_vixxen`.
- GitHub documents, issues, pull requests, tests, and handoff files are the continuity hub.
- A local/package milestone and a GitHub branch are separate truth boundaries.
- Do not claim source import, CI success, MySQL, Playwright, merge, tag, live payments, production email delivery, or deployed licensing without direct evidence.
- Public source never contains credentials, generated keys, raw license keys, signing private keys, customer/member/subscriber data, private conversations, booking/request records, owner notes, attachments, identity documents, runtime state, or private/paid media.

## Source recovery and progress discipline (2026-10-19)

- The latest runtime baseline was recovered from a checksum-verified 0.8.1 package; separate 0.7.4 composer fixes require a targeted merge.
- Upload the latest source, tests, and progress documentation at every completed session. Missing application source is a failed CI condition.
- Historical implementation claims are checked against actual code. The recovery record identifies unimplemented UI/store/marketing work; these remain unfinished until code and tests land.
- An installed site requires a matching configuration/JSON lock identity. A malformed or unrelated lock never proves installation.
- Recovery never downgrades a valid active configuration to an older backup. Existing configuration/identity/key evidence blocks destructive fresh reinstallation; a complete pending checkpoint can continue while preserving its original identity and keys.
- Repair backups containing unique, different, unreadable, unknown, or symlink entries cannot be deleted by the cleanup UI. Deletion requires a fully verified duplicate and explicit owner confirmation.
- Fresh-install distributions exclude active config/app.php. Upgrade distributions exclude config, storage, uploads, and install entirely.
- Source and installed-demo tests run in disposable copies, never in a real site or the active engineering checkout.

## 0.8.1 continuation decisions

- Each device stores its own background/geometry, photo layers and live HTML text/button region. Full Page Preview saves both drafts without publishing them. All public scene edges clip separately movable photos.
- The luxury library counts only valid individual image/thumbnail pairs. Generation provenance, masters and screenshots remain outside runtime packages.
- Booking field definitions use stable identifiers/revisions. Each inquiry retains the original question labels/options and answers even after later edits or deletions.
- Merchandise carts contain physical catalog lines only. Membership and PPV/rental use separate checkout intents; direct purchases do not clear the merchandise cart. Stock reservations are serialized, terminal payment events are idempotent, and stale owner edits cannot overwrite newer inventory.
- Shipping uses configured per-item charges and private order addresses. Automatic carrier labels, region-specific taxes and live payments are not implied.
- Installed database updates apply pending versioned migrations after identity/plan checks and explicit operator backup acknowledgment. Failure records are retained; destructive down SQL never runs automatically on an existing site.
- Browser asset cache identity is distinct from the 0.8.1 core/add-on compatibility version.
- Canva is a saved-link/manual export/import bridge. Functional HTML and transparent owner photos remain separate from decorative artwork.

## Installer package ownership correction (2026-10-19)

- Mandatory static directory checks must match actual packaged contents; empty design scaffolding is not a runtime prerequisite.
- PHP reads application/assets/templates; it does not require write access to those panel-owned trees. Missing required packaged directories remain failures, not empty directories manufactured by preflight.
- Automatic ownership repair remains restricted to the existing writable runtime roots. Do not copy or rotate public assets merely to create unused folders.
- Installer regressions must extract the real customer ZIP and execute PHP as a different unprivileged owner; a same-owner temporary directory is insufficient evidence of shared-hosting behavior.
- The historical folder-only correction used a runtime-only one-file overlay. The subsequent database/layout correction uses the explicit eight-file repair allowlist. It never replaces configuration, storage, uploads, database contents or installed application data.

## Existing-database installation and final activation (2026-10-19)

- The user requires installation to continue with empty or nonempty databases. Unrelated tables remain untouched; compatible application tables and migration history are reused. Conflicting known definitions/checksums receive specific preservation errors.
- Never run automatic destructive down migrations on installer failure. MySQL DDL may already be committed; retain completed schema/ledger work and stable pending configuration so a later browser request can retry.
- Existing admin records require the current active owner email/password. Do not reset the owner hash or create a replacement owner around failed authentication.
- Keep original matching security keys and installation identity from staged configuration or verified backups. If existing encrypted/HMAC license records require missing original keys, preserve the data and request restoration of that configuration.
- Seed missing defaults only. Existing settings, scene versions/drafts, plans, media, templates, provider configuration and saved records are authoritative. Recreating missing primary installation metadata uses the effective preserved URL and original installation time.
- Pending setup is not publicly installed, including the interval after its completion lock is written. Clear the pending marker only after final configuration/database/lock verification. Setup, automatic recovery and installer retirement cooperate through a per-root lock; concurrent roots also serialize access to the selected MySQL database.
- Keep the one-page form, no installation key, 12-character minimum passwords and safe permissions. Fix alignment in the actual existing interface without creating mockups or changing the approved design.
- The runtime repair includes exactly its reviewed PHP/CSS/JavaScript dependencies. Customer configuration, storage, uploads, database records, docs, tests and evidence never enter the overlay. Advance changed component ZIP timestamps and browser asset queries so deployed fixes load after extraction.
