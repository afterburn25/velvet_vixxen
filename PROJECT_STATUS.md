# Project Status — Nikki Wilder / The Velvet Vixxen

Repository: `afterburn25/velvet_vixxen`

## Current work

**0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise** on `feature/0.8.1-site-ux-hero-library`.

The executable source was recovered from the original checksum-verified 0.8.1 fresh-install ZIP. The separate 0.7.4 Visual Composer fixes are being reconciled into that source. This corrects the earlier state where `main` described 0.3.0 and newer branches held delivery documents without the corresponding application.

## Implemented in this integration

- Complete recovered runtime in organized source folders, including consolidated public routes and operational console commands.
- Recovered member, entitlement, commerce, media, installer, and add-on tests, adapted to the actual current source layout.
- Required source-presence CI; a missing application can no longer result in a passing skipped application suite.
- Installation state based on matching configuration/JSON-lock identity rather than mere lock-file existence.
- Reinstallation guard before any new database work when configuration, keys, or a prior installation identity remain.
- Recovery and backup preservation fixes, with disposable regression fixtures.
- 0.7.4 Visual Composer direct manipulation, expanded scaling, live-content positioning, and corrected asset resolution.
- Reproducible fresh-install and upgrade package builder.
- Upgrade overlay test that verifies existing configuration, lock, private data, and unique uploads remain byte-for-byte intact.
- Source-control exclusions for installed state, generated secrets, runtime records, and local test/build outputs.

## Actual recovered capabilities

The source contains owner administration, single-page installation, Hero/Footer composition, music/photo/video pages, signed media access, member accounts, entitlement resolution, sandbox commerce, add-on lifecycle/licensing, private messaging, professional booking inquiries, and a basic file-backed marketing module. Their verified checks are listed in the test report; presence alone is not production certification.

There are **12 versioned migrations declaring 78 tables**. The earlier 75-table claim and a test parser that missed quoted table names were incorrect. Marketing schema tables exist, but the current MarketingStore implementation persists to protected files, not those MySQL tables.

## Unfinished or unsupported prior completion claims

- About Me public page and owner editor.
- Owner-editable booking field/type builder and expanded live email/phone formatting.
- Merchandise product/inventory/order fulfilment and the requested revised storefront.
- PPV direct Buy Now flow separate from the cart.
- Later navigation/admin-layout changes and the expanded background collection.
- Advanced publishing audiences/expiration, scheduled scene claims, production campaign delivery, referral-to-order attribution, configurable marketing retention, and MySQL-backed marketing persistence.

These remain unfinished unless a later commit implements them and adds evidence. They must not be counted complete from historical chat or handoff prose.

## Validation and release state

See `docs/TEST_REPORT.md` for exact current local results and GitHub run evidence. At the beginning of this repair, existing GitHub checks validated documentation and skipped source tests; those historical green runs did not verify the application.

Live payments, production email delivery, representative shared hosting, and the 0.9/1.0 hardening milestones remain unverified or unfinished.
