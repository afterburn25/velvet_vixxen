# Architecture and Product Decisions

## 2026-10-20 — Shared Composer/public scene geometry (0.8.10 candidate)

- The owner reported that photos visible in Composer were cut off by the actual Hero. The original package reproduces a fixed 760px public height at 1680px width while Composer represents a 1440×760 canvas.
- Preserve the existing per-device design canvases and stored coordinates. Uniformly project the whole public scene into its actual width, with displayed height equal to saved height × actual width/design width. This also preserves background fitting and scales live typography, controls and effects together.
- Keep the public navigation outside the scene and stop the following paper section from overlapping the composed Hero's lower edge. Keep intended edge cropping; do not alter stored owner layouts, source images or publication state.
- Share the geometry helper between editor/public entry points and require it in package/update inventories starting at 0.8.10. Earlier inventories remain inspectable with their own identity.
- Require the focused actual-package root/nested 52-comparison matrix, the failing original-package control, retained Composer/browser gates and all seven required CI jobs. Local success does not establish merge, saved delivery or actual-host installation.
- The later owner footer request is part of this same unreleased increment: enlarge bottom-footer type while removing fixed minimum height and reducing padding; add a clearly blue underlined Cajun Web Pro credit opening a new tab on homepage/common renderers. Preserve existing mobile link visibility and allow content-driven wrapping. Verify 24 footer layouts and eight locally fulfilled new-tab clicks in the same packaged browser step.
- A real packaged phone click exposed the fixed player covering the homepage credit. Track actual player height/visibility and reserve clearance below page content without increasing the footer element. Verify real pointer hits, new tabs and hidden-player empty-catalogue responses.
- Foreground masking/depth controls and unrelated contact-card overflow remain separately scoped. This correction does not complete broader marketing #9 or the 0.9 milestone.

## 2026-10-20 — Bounded editorial history and deliberate restoration (0.8.9, verified)

- Add history to existing posts, banners and landing pages in their current marketing record/backend. Keep at most 20 earlier versions, newest first; capture the prior editorial state only when supported fields or visibility genuinely change. Creation and unchanged saves do not manufacture prior versions. Existing items begin retaining history on their first changed save after upgrade.
- Snapshot only an allowlist of editorial fields. Exclude IDs, permalinks, creation identity, arbitrary unknown fields, private account data and nested history. Preserve the current record's identity, order and unknown fields during restoration. Store capture time in UTC and the captured site timezone; retain explicit date offsets and interpret legacy local dates in that recorded timezone.
- Expose History and Review version only through the active-owner admin dispatcher, using saved-state-only reads, private/no-store/noindex/no-referrer responses and a final current-owner check after dependent reads. Public publication readers omit history. Reviewing is inert and creates no marketing events, messages, storage defaults or publication changes.
- Restore only a server-held retained version under the existing marketing mutation lock and current revision check. Revalidate historical content, current eligible public images, related-post identities and selected tiers; recheck the active owner after reference reads. Refuse stale or invalid restoration without changing current content or history.
- Force restored posts to draft and banners/pages to disabled. Review and explicit ordinary publication are separate owner actions. Preserve the replaced current editorial state as a new history entry when the restore changes it. A restore never requeues or sends campaign mail.
- Keep malformed or oversized history intact and hold ordinary editing/restoration for review. Safe withdrawal to draft/disabled remains possible without resetting damaged history. Do not claim a new snapshot for a withdrawal that could not safely capture one.
- Permanent deletion removes the item and its attached history. Deleted-item recovery, an editorial calendar and explicit post expiry remain separate work. No new database migration, backend cutover, public route or package-root exception is included.
- Delivered 0.8.9 / Build 20261020.10 after all seven jobs and 65 configured required steps passed in run 39510626159. Source `c07c02bd0b6957cba4e0c9d6722765e262e37157`, actual CI checkout `f22982b761358e12db2cacde64a0ce283ce7a6e2` and PR #25 merge `ca638ef486846d37c80606d5a012f07f23299dae` share tree `99ecb497aa99006568152c058c9b9c447625c16a`. Literal saved-download read-back passed at `2026-10-20T23:13:07.846261+00:00`; see [the release record](releases/0.8.9-editorial-history.md). Prior releases retain their historical identities and evidence. The separate 0.8.10 Composer geometry correction has passed local acceptance but awaits CI, merge and delivery; depth/masking remains pending.

## 2026-10-20 — Bounded editorial metadata and current asset references

- Keep metadata on the existing publication record in both file and MySQL marketing storage; add no schema migration or storage cutover.
- Publish only an explicit author display name. Never infer a public byline from private owner/account fields.
- Use saved IDs for existing public raster images. Validate the actual local file and current protection state, reject unsafe paths and symlinks, and omit an unavailable image without silently substituting another asset. A public image remains public even when selected for restricted content.
- Select related posts explicitly in a bounded saved order. Check source and target access/timing before returning related metadata. Selecting a draft does not publish it.
- Preserve omitted metadata for older callers, require locked revisions for explicit changes, and recheck current ownership after storage-dependent reference validation. Saved previews remain read-only, private and inert.
- Deliver this as 0.8.8 only after its exact source/package/CI/download acceptance. History/restore, calendar and post expiry remain separate next increments.


## Release versions advance with completed work (2026-10-19 America/Chicago)

- The user explicitly directed that visible release versions increase from now on as completed work ships. The rule began with delivered 0.8.2; later completed releases advance to 0.8.3, 0.8.4, or the appropriate next roadmap milestone.
- Date-based build IDs remain useful secondary diagnostics. They do not replace incrementing the release version.
- Keep application, canonical VERSION, installer stamps, upgrade compatibility and new package names consistent. Avoid hard-coded current-version copies where the canonical value can be used safely.
- Preserve prior release numbers, exact package hashes and pinned historical fixtures. Verify each new release before merging and delivering it.
- The 0.9 privacy/protection/operations scope remains a separate milestone; finishing a 0.8.x release does not claim that work is complete.

- Enforce the rule against the latest committed verified delivery record, rejecting changed same-version packages even when only their build labels change. Permit an exact byte-identical rebuild of an already delivered release.
- Keep an unchanged unfinished-installer overlay at its delivered repair version/build and hash; a new full-runtime release does not relabel that separate historical repair.

## Editorial visibility and private saved previews (0.8.7)

- Keep editorial access separate from media entitlements and newsletter audience/consent rules. Public, Members and Selected tiers apply to saved posts, banners and landing pages. Members includes active verified free accounts with a valid saved device session; selected tiers uses exact enabled plan IDs and current eligible subscriptions without an inferred hierarchy.
- An absent legacy access field remains public. Explicit malformed restrictions, unsupported policy versions, empty tier selections and unavailable selected plans hold access until deliberately repaired. Current account, device, plan and subscription state is read for each public request; malformed nonempty dates cannot grant access.
- Apply publication timing and access before listing limits, titles/excerpts, page metadata or campaign-view recording. Keep saved banner order and choose the first eligible banner. Send private/no-store headers before viewer-dependent HTML or generic denial responses.
- Use a separate active-owner saved preview inside the existing admin dispatcher. Recheck the saved owner's active role, use the authoritative backend without initializing missing marketing data, and omit publication mutations, marketing events, outbound mail, tracking and share tokens. Public owner flags do not create a bypass.
- Preserve locked publication revision/CSRF/method checks, immutable saved permalinks and unrelated fields. Recheck current owner and selected plan definitions inside the save lock. Keep safe withdrawal/deletion available when a policy needs repair. File and MySQL use the same policy and publication API; no new schema or automatic backend switch is needed.
- Advance the visible release to 0.8.7 / Build 20261020.8 and retain all existing gates. The release is not complete until the changed source, actual-package file/MySQL HTTP and desktop/mobile flows, exact GitHub tree/artifacts, merge and saved downloads have passed their recorded acceptance.

## Add-on migration recovery (0.8.3)

- Preflight the complete package migration plan and executable ownership boundary before migration SQL. New automatic migrations may add owned InnoDB objects; they may not rewrite retained data or adopt unrelated existing tables.
- MySQL DDL can commit independently. Persist exact statement progress and a separate lifecycle intent across SQL, files and registry activation. Keep prior files until activation commits and hold pending add-ons from activation/boot.
- Retry only definite recorded failures after current-owner/POST/CSRF and exact package/registry/definition validation. Unknown outcomes require review. Explicit rollback and purge can delete values; a database backup is necessary to restore deleted data.

## Grouped actions and guarded campaign delivery (2026-10-20)

- Store optional shared `scene.contentButtons` separately from per-device group geometry. Absence preserves automatic legacy Hero/Footer actions; an explicit empty list intentionally removes group buttons. The right inspector supports up to eight ordered live actions. Photo cutouts and independent button layers remain separate.
- Validate destinations before persistence and again when rendering legacy content. Keep automatic account/trailer behavior, escaped live HTML, owner-only draft preview and published history. Local links use the existing physical dispatcher and installation prefix.
- Campaign queueing freezes content and deduplicates normalized recipients. Current confirmed consent, suppression, schedule and enabled/paused state are checked again before body transmission; unsubscribe always remains available.
- Preserve protected JSON for this bounded increment. Use separate worker and short fresh-state mutation locks, atomic replacement and revision guards; do not hold a user's PHP session lock across SMTP waits. Marketing SQL storage remains explicit roadmap work.
- Treat transport acceptance, definite failure and unknown acknowledgement as distinct outcomes. Retry definite failures only through an explicit owner action with cooldown and attempt limits. Hold unknown or interrupted transmission without automatic resend; deliberate pre-transmission pauses do not spend an attempt.
- Reuse the existing installation-configured mail transport. Independent signup/delivery switches do not configure SMTP. An owner SMTP editor and production transport verification remain separate work. Customer runtime layout, private-state exclusions and sandbox-only payments stay locked.

## Organized root and central public routing correction (2026-10-19)

- The owner explicitly rejected the agent-authored physical root compatibility folders. Restore the pre-regression 13-directory fresh and nine-directory upgrade allowlists and four root files: `.htaccess`, `VERSION`, `index.php`, `route.php`.
- Keep public implementations under `public/routes`; use the physical `route.php` dispatcher and an allowlisted scalar `vvx_route` selector for generated URLs on hosts without rewrite support. Retain existing pretty-path aliases through actual server rewrite configuration.
- Check complete actual ZIP root directories independently of the packager's own allowlist. Passing action tests cannot excuse a directory regression.
- An existing .5 installation must be repaired in place: verify new runtime bytes and owner/CSRF boundaries; privately preserve exact known retired stub bytes as nonexecuting data; remove only those verified files and empty retired directories. Never move/delete custom siblings or unrelated state.

## Superseded public route compatibility decision (2026-10-19)

The first two bullets below are historical and explicitly rejected by the owner; do not implement or retain their root-file/folder exception.

- Keep protected implementations under `public/routes` and preserve existing public URLs with thin physical entry points. A host may dispatch nonexistent `.php` paths to FastCGI before rewrite rules can forward them. Every registered public page/API must be present in the runtime ZIP, including nested-installation paths and short account aliases.
- Keep `media.php` as the single additional root compatibility entry point for already-issued signed-media URLs; its implementation stays under `public/routes`. This is a narrow organized-source exception, not permission to move business logic into the root.
- Test actual extracted packages without the universal test router as well as the existing rewrite-router acceptance. Missing physical files must cause the negative controls to fail; readable application errors are distinct from a host's missing-file page.
- Parse upload and scene responses through one shared JSON helper. Preserve server validation messages, translate non-JSON/HTTP failures into useful errors, and re-enable failed controls for retry. A failed Full Page Preview must keep its new tab open with an explanation instead of immediately closing it.
- Store separate live button layers in the existing per-device scene JSON. Reuse geometry, ordering, history, hide/lock and copy controls; keep photo layers and the existing live text/button group independent. Validate button labels, colors, geometry and destinations before any scene write. Allow site-relative or HTTP(S) destinations, reject unsafe schemes and render labels as text.
- Ship a separately allowlisted Media/Composer repair for installed 0.8.1 sites, plus current fresh and upgrade distributions. Configuration, storage, uploads and all customer/private data remain outside the repair. No database migration or installer rerun is required for these controls and routing changes.

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

That recovery finding and the earlier dated campaign-storage decision describe their original baselines. The bounded 0.8.6 SQL storage increment is now implemented: migration 015 creates the additive schema, and a separate reviewed owner action moves the preserved marketing snapshot. File-mode compatibility remains. [Marketing Storage](MARKETING_STORAGE.md) documents the active-backend and failure boundaries. The bounded 0.8.7 [editorial access and saved-owner preview](EDITORIAL_ACCESS.md) increment is also complete. The bounded 0.8.8 [editorial metadata](EDITORIAL_METADATA.md) and 0.8.9 [editorial history/restore](EDITORIAL_HISTORY.md) increments are complete. [The roadmap](ROADMAP.md) starts the remaining #9 work with separately scoped calendar and explicit post expiry; deleted-item recovery remains separate. SQL persistence does not implement indexed analytics or complete 0.9.

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
