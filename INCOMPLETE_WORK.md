# Acceptance Record — Velvet Vixxen 0.8.1 Continuation

## Installer folder correction in progress — 2026-10-19

The owner reported fresh-install readiness failures for nine missing image, font/icon and template folders after downloading the earlier 0.8.1 package. The manifest required unused empty scaffolding that the runtime-only ZIP intentionally does not contain. On panel-owned read-only asset trees, PHP cannot create those folders. The earlier same-owner installer fixtures did not cover this extracted-package ownership case; the prior green runs below do not certify it.

The current correction removes those nine unused entries and unused `database/seeds`, checks actual packaged application/asset directories for presence/readability without creating or chmodding them, and preserves the existing writable-runtime probes and limited ownership repair. Missing real application folders remain blocking errors. A one-file installer repair ZIP will let an already-uploaded unfinished installation resume without replacing configuration, storage, uploads or database data. The complete fresh package will also contain the correction.

Local PHP 8.3.6 source/JavaScript syntax/security/package suites passed. The new real foreign-owner extracted-ZIP regression reproduced exactly the nine original errors, and the corrected service passed repeated probes, allowed ownership repair, required-write failures, missing real assets and failed-repair preservation. Fresh PHP 8.2/8.3, MySQL 8 and HTTP/browser CI is pending on the corrective commit; do not claim that new run has passed until its results are observed. The 0.8.1 features and locked requirements remain the scope; no 0.9.0 work is authorized by this correction.

**Captured:** 2026-10-19  
**Repository:** `afterburn25/velvet_vixxen`  
**Handoff branch:** `handoff/0.8.1-incomplete-site-ux`
**Implementation branch:** `feature/0.8.1-site-ux-hero-library`  
**Baseline documentation branch:** `feature/0.8.0-marketing-publishing-analytics`

This file is the authoritative handoff for the user-facing work that was being discussed after the documented 0.8.0 Marketing, Publishing & Analytics milestone. Do not mark any item complete merely because an earlier chat response said it was built. Verify the executable source, running behavior, tests, and packaged artifact first.

## Verified checkpoint — 2026-10-19

The **0.8.1 user-facing engineering continuation is implemented and verified** on `feature/0.8.1-site-ux-hero-library`. Runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c); complete GitHub CI: [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), **all six jobs passed**. PHP 8.2.34/8.3.35, JavaScript syntax, MySQL 8.0.46, installer/ownership/security, seven HTTP suites, twelve real Chromium suites and gated runtime packaging all passed. The library has 30 individual scenes and 30 thumbnails, with 90 successful published renders across desktop/tablet/mobile.

This is the verified sandbox development milestone. Payments remain sandbox-only, actual owner content must be supplied by the owner, and production hosting/live-service certification is not claimed. PR #15 contains the feature branch; inspect its current merge state separately. No 0.9.0 implementation is included.

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

## Truth boundary

- At the original handoff, GitHub documented a packaged `0.8.0 — Marketing, Publishing & Analytics` milestone without its complete engineering source. The implementation branch now contains checksum-verified recovered 0.8.1 source and actual subsequent feature work; the historical claim is not retroactively certified.
- The 0.8.0 branch records runtime artifact metadata, including SHA-256 `ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993`, but the runtime ZIP itself is not committed to source control.
- The latest executable source visible in GitHub must be inspected before coding. Do not assume `main` or any documentation-only branch contains the complete application.
- Customer installation ZIPs must remain runtime-only. Repository docs, tests, SDK source, screenshots, GitHub workflows, development schema snapshots, and build tooling stay outside the customer ZIP.
- Production credentials, member/customer records, private messages, booking/request data, identity documents, payment secrets, raw license keys, signing private keys, runtime logs, sessions, and private/paid media must never be committed.

## Priority 0 — Recover and verify the real executable baseline

- [x] Inspect every live branch and identify the newest branch that actually contains executable application source rather than documentation only.
- [x] Search the 0.8.0 source/artifact inputs; reject the recovered 0.8.0 ZIP as a substitute for its mismatched historical checksum, and select the newer checksum-verified 0.8.1 runtime as documented in `docs/SOURCE_RECOVERY.md`.
- [x] If the 0.8.0 engineering source is unavailable, start from the last verified executable source/package and reapply later changes deliberately; do not invent missing code from status documents.
- [x] Recover and execute the existing source, installer, HTTP, security, runtime-package and real browser suites; record discovered failures and baseline fixes separately from new feature tests.
- [x] Import the complete clean engineering source into GitHub on a feature branch, while keeping the public installation ZIP runtime-only.
- [x] Record the recovered source commit, baseline package SHA-256, migration/table counts and observed tests in the status/handoff/test report. Final feature package checksums are recorded in `RELEASE_MANIFEST.txt` and `docs/TEST_REPORT.md`.

## Priority 1 — Visual Composer direct manipulation and scene realism

The user wants the Visual Composer to behave like a real canvas editor.

- [x] Allow direct drag movement of model/photo layers anywhere on the canvas.
- [x] Allow layers to move well beyond every canvas edge, including negative X/Y positions and positions below the Hero, so lower legs or other portions can be clipped to create a convincing floor/foreground illusion.
- [x] Clip everything outside the live Hero/Footer viewport on the public site.
- [x] Allow image resizing from extremely small to extremely large. Preserve aspect ratio by default and provide precise numeric controls.
- [x] Keep resize handles reachable even when a layer is partly off-canvas.
- [x] Allow direct movement and resizing of the live text/button group as one canvas object.
- [x] Preserve separate Desktop, Tablet, and Mobile positions/sizes for backgrounds, model layers, and the live text/button group.
- [x] Support background panning, focal point, zoom, brightness, contrast, saturation, overlay, and blur.
- [x] Preserve undo/redo, layer ordering, lock/hide, duplicate/delete, snapping, alignment guides, and keyboard/numeric movement.
- [x] Verify that model layers remain separately editable and are not flattened into Canva artwork.
- Optional future enhancement: dedicated furniture/foreground masks. Current separate photo ordering and all-edge clipping are implemented; no cutout is baked into the room. A specialized mask editor is not claimed as part of this continuation.

### Broken Visual Composer media

- [x] Fix all asset URL resolution rules for bundled `/images/...` assets versus uploaded `/uploads/images/...`, `/uploads/models/...`, `/uploads/thumbnails/...`, and related media locations.
- [x] Media Tray must show image-compatible assets only.
- [x] Missing files must show a controlled placeholder and useful status, never a permanent broken-image icon.
- [x] Add automated coverage that loads every Media Tray thumbnail and fails on 404s or invalid image responses.

## Priority 2 — Premium Hero Background Library

The user requested many individual, upscale, people-free scene backgrounds that transparent model photos can interact with naturally. These must be standalone full-width images, not collage sheets.

### Required scene categories

- [x] Multiple luxury bedrooms and romantic suites
- [x] Upscale kitchens
- [x] Elegant living rooms and penthouses
- [x] Game rooms with pool tables
- [x] Private home bars and cocktail lounges
- [x] Home theaters
- [x] Luxury bathrooms, spas, and saunas
- [x] Walk-in closets and dressing rooms
- [x] Music/recording studio
- [x] Wine cellar
- [x] Mansion foyer or staircase
- [x] Rooftop lounge
- [x] Garden patio and fire-pit lounge
- [x] Infinity pool and waterfall pool
- [x] Beach and private cabana
- [x] Outdoor/pool bar
- [x] Sunset terrace
- [x] Yacht deck
- [x] Private jet cabin
- [x] Mountain lodge/retreat

### Scene requirements

- No people, mannequins, embedded model silhouettes, text, logos, labels, watermarks, or collage borders.
- Fancy, polished, photorealistic, and upscale; avoid plain or generic stock-room appearance.
- Wide Hero composition with believable floor plane, perspective, lighting, and open placement zones.
- Preserve enough negative space for live website text and buttons.
- Include foreground objects/edges where appropriate so off-canvas clipping and later occlusion can create believable interaction.
- Provide optimized runtime versions and separate thumbnails while preserving high-quality masters outside the public runtime ZIP when appropriate.

### Generated assets from the prior active workspace

Historical filenames below were searched for but could not be recovered as usable durable assets. Thirty individual replacement/expanded scenes now exist in `images/hero/` with manifest, thumbnails and `artwork/hero/prompts.json` provenance. These old names are retained for traceability only:

- `glamorous_magenta_penthouse_retreat.png`
- `midnight_magenta_penthouse_kitchen.png`
- `sunset_penthouse_with_city_views.png`
- `neon_luxe_penthouse_billiards_lounge.png`
- `opulent_magenta_skyline_cocktail_lounge.png`
- `luxury_tropical_infinity_pool_at_sunset.png`
- `sunset_cabana_by_the_sea.png`
- `luxury_sunset_oceanfront_bar.png`
- `luxury_rooftop_lounge_at_night.png`
- `luxe_pink_and_gold_marble_spa.png`

Prior Adobe concept URLs also lacked durable assets. The current 30 real individual files cover all requested categories, were visually reviewed, and passed Chromium image decoding plus 90 public scene renders. No collage or screenshot is counted as a selectable scene.

### Library integration

- [x] Create searchable/filterable background categories in the Visual Composer.
- [x] Show a thumbnail, scene title, orientation, and recommended model placement zone.
- [x] Clicking a scene should preview it immediately without publishing.
- [x] Save Draft, Full Page Preview, Publish, version history, and rollback must work.
- [x] Verify every background in desktop, tablet, and mobile modes.

## Priority 3 — Admin-configurable Booking Form Builder

- [x] Owner can create, edit, delete, enable/disable, and reorder fields.
- [x] Every field can be required or optional.
- [x] Supported types: short text, paragraph/textarea, email, phone, dropdown/select, single-choice radio group, and multi-choice checkboxes.
- [x] Radio options must be linked so only one option can be selected.
- [x] Checkboxes must support multiple selections and required minimum-selection rules where configured.
- [x] Email fields must validate realistic address format on both client and server and display a clear inline error.
- [x] Phone fields must accept digits and format live while typing. Example: `3374508598` becomes `(337) 450-8598` before the user leaves the field.
- [x] Phone validation must reject incomplete/invalid numbers on both client and server.
- [x] Store submitted values safely and expose them in the existing booking administration workflow.
- [x] Preserve existing booking records when the form definition later changes.
- [x] Add CSRF, authorization, escaping, upload, and spam/rate-limit coverage.

## Priority 4 — Public navigation, authentication pages, and About Me

- [x] Main public navigation is sticky on every public page and does not cover page anchors or mobile controls.
- [x] Remove `Contact` from the main navigation; Booking is the public inquiry/contact path.
- [x] Add `About Me` immediately after Booking in the navigation.
- [x] About Me is a dedicated public page, not a homepage anchor.
- [x] About Me left sidebar contains owner-editable label/value statistics such as height, measurements, hair, eyes, location, availability, and any custom rows. Empty values remain hidden.
- [x] Main About Me body contains the owner-editable biography and optional CTA/media.
- [x] Add a full About Me editor in the appropriate admin category.
- [x] Login, Join, Create Account, password recovery, and related auth pages must use a clean standalone page layout with sticky nav, centered form in the body, and footer at the bottom—never a narrow form pinned to the left side.
- [x] Verify desktop/tablet/mobile layouts, keyboard controls and accessible roles/names used by real-browser tests. A manual assistive-technology certification is not claimed.

## Priority 5 — Reorganize administration navigation

- [x] Group all owner pages into logical collapsible categories, such as Dashboard, Site & Design, Content & Media, Members & Communications, Commerce, Marketing, Extensions, and System.
- [x] Persist expanded/collapsed category state across refreshes and returns.
- [x] Preserve the active page and active-page highlight after refresh.
- [x] Do not reset the user to the dashboard when reloading an admin page.
- [x] Expand the active category by default while preserving an explicit owner choice to collapse it.
- [x] Ensure mobile admin navigation remains usable.
- [x] Place Booking Form Builder under Members & Communications, About Me under Site & Design, and Merchandise under Commerce.

## Priority 6 — Merchandise store and PPV purchase separation

- [x] Build a real merchandise catalog with products, images, descriptions, prices, variants/sizes/options, stock/status, shipping requirement, cart, checkout, and order administration.
- [x] Subscription plans may be promoted from the Store but should use the membership/subscription checkout flow rather than behave like physical merchandise.
- [x] PPV photo galleries and PPV videos must not appear as normal cart merchandise.
- [x] PPV gallery/video pages must use a direct `Buy Now` or rental checkout flow tied to the selected content.
- [x] Keep purchased PPV content in My Library and preserve existing entitlement, refund, and chargeback behavior.
- [x] Test cart contents so physical merchandise cannot accidentally mix with an incompatible PPV entitlement flow unless a deliberate mixed-order design is implemented later.
- [x] The bundled payment provider remains sandbox-only. Do not claim live payment readiness.

## Priority 7 — Canva workflow clarity

Current Canva behavior should be treated as a manual bridge unless a real OAuth/API integration is verified.

- [x] Document clearly that `Edit in Canva` is a saved-link shortcut, not live synchronization.
- [x] Document the export/import workflow for Hero, Footer, gallery covers, and video posters.
- [x] Keep navigation, text, buttons, paywalls, forms, and media players as live HTML/JavaScript.
- [x] Prefer Canva for backgrounds/decorative artwork and the Visual Composer for separately movable transparent model layers.
- [x] Do not claim Canva OAuth, automatic export, or automatic sync until credentials and end-to-end tests exist.

## Required acceptance tests before calling 0.8.1 complete

- [x] Direct drag, resize, off-canvas movement, lower-leg clipping, background pan, and text/button group movement in a real browser.
- [x] No broken images in the Visual Composer or public Hero/Footer.
- [x] At least 30 distinct standalone background scenes available and selectable.
- [x] Booking field creation, reordering, required/optional behavior, dropdown, radio, checkbox, email validation, and live phone formatting.
- [x] Sticky navigation on all public routes.
- [x] Centered Login/Join/Create Account pages.
- [x] About Me public page and owner editor.
- [x] Admin collapsible state and active page preserved after refresh.
- [x] Merchandise cart/checkout works while PPV uses direct Buy Now.
- [x] Existing subscriptions, entitlements, refunds, protected media, messaging, booking, add-ons, installer, and marketing features have regression coverage.
- [x] PHP 8.2/8.3 source suite, JavaScript syntax, disposable MySQL 8 migration apply/verify/rollback, HTTP acceptance, repository-safety scan, desktop/mobile Playwright, and runtime-only package checks are green.
- [x] Customer ZIP starts uninstalled and excludes GitHub/docs/tests/SDK/build artifacts and all runtime/private data.

## Recommended delivery target

Continue the existing executable implementation branch:

```text
feature/0.8.1-site-ux-hero-library
```

Do not start 0.9.0 compliance work until the user-facing 0.8.1 continuation above is either implemented and verified or explicitly deferred by the user.


## Final verification

The full checklist above is verified by [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477) on [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c). Failed intermediate runs and their corrections are retained in `docs/TEST_REPORT.md`. Current schema: 14 migrations / 82 tables. The owner supplies real photographs and personal/catalog content; those inputs and live production-service certification are not fabricated completion claims.
