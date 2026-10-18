# Architecture and Product Decisions

## Product identity and design

- Product: Nikki Wilder — The Velvet Vixxen
- The approved neon-pink/magenta/black direction is the design source of truth.
- No additional mockups are created unless explicitly requested.
- User/model photographs remain replaceable layers; default public artwork stays people-free.
- Navigation, forms, paywalls, players, pricing, and accessibility remain live HTML/JavaScript.

## Installer and hosting

- Installation occurs on a single responsive browser page.
- No installation key is required.
- Owner and member passwords have a 12-character minimum.
- File checks use real write/read/rename/delete probes.
- Safe permission attempts are `0755`/`0775`; `0777` is never accepted.
- Installation runs versioned migrations, verifies boot, creates a lock, and deletes or securely retires `/install`.
- Files remain organized by responsibility.

## Distribution packaging

- The public installation ZIP contains only files required to install and run the actual site.
- Repository metadata, GitHub workflows, docs, tests, SDK source, screenshots, reports, and development-only scripts do not ship in the customer installation ZIP.
- Operational billing and add-on task runners remain included because the installed site requires them.
- The complete engineering source, tests, documentation, and SDK remain in the repository source-import workflow, not in the public runtime package.

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
- Guest self-service status tokens, communication report queues, and CSV communication exports are future work and are not claimed in 0.7.0.

## Repository and evidence

- Repository: `afterburn25/velvet_vixxen`.
- GitHub documents, issues, pull requests, tests, and handoff files are the continuity hub.
- A local/package milestone and a GitHub branch are separate truth boundaries.
- Do not claim source import, CI success, MySQL, Playwright, merge, tag, live payments, or deployed licensing without direct evidence.
- Public source never contains credentials, generated keys, raw license keys, signing private keys, customer/member data, private conversations, booking/request records, owner notes, attachments, identity documents, runtime state, or private/paid media.
