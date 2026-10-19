# Incomplete Work — Velvet Vixxen 0.8.1 Continuation

**Captured:** 2026-10-19  
**Repository:** `afterburn25/velvet_vixxen`  
**Handoff branch:** `handoff/0.8.1-incomplete-site-ux`
**Implementation branch:** `feature/0.8.1-site-ux-hero-library`  
**Baseline documentation branch:** `feature/0.8.0-marketing-publishing-analytics`

This file is the authoritative handoff for the user-facing work that was being discussed after the documented 0.8.0 Marketing, Publishing & Analytics milestone. Do not mark any item complete merely because an earlier chat response said it was built. Verify the executable source, running behavior, tests, and packaged artifact first.

## Current checkpoint — 2026-10-19

The checksum-verified 0.8.1 Fresh Install artifact was recovered, and its complete executable source and engineering tests are being imported on the implementation branch. Baseline PHP 8.3 source, recovery, packaging and HTTP checks pass; MariaDB applies/reapplies/rolls back 12 migrations and verifies 78 tables. Restored Composer direct interactions pass actual desktop/mobile browser tests. PHP 8.2, MySQL 8 and the complete feature acceptance matrix remain required gates. See `docs/TEST_REPORT.md` for the exact boundary. All unchecked feature items below remain active work; historical completion claims are not reused.

## Truth boundary

- GitHub currently documents a packaged `0.8.0 — Marketing, Publishing & Analytics` runtime release, but the complete executable 0.8.0 engineering source has not been imported into the repository.
- The 0.8.0 branch records runtime artifact metadata, including SHA-256 `ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993`, but the runtime ZIP itself is not committed to source control.
- The latest executable source visible in GitHub must be inspected before coding. Do not assume `main` or any documentation-only branch contains the complete application.
- Customer installation ZIPs must remain runtime-only. Repository docs, tests, SDK source, screenshots, GitHub workflows, development schema snapshots, and build tooling stay outside the customer ZIP.
- Production credentials, member/customer records, private messages, booking/request data, identity documents, payment secrets, raw license keys, signing private keys, runtime logs, sessions, and private/paid media must never be committed.

## Priority 0 — Recover and verify the real executable baseline

- [ ] Inspect every live branch and identify the newest branch that actually contains executable application source rather than documentation only.
- [ ] Locate the latest clean engineering tree or runtime artifact corresponding to 0.8.0.
- [ ] If the 0.8.0 engineering source is unavailable, start from the last verified executable source/package and reapply later changes deliberately; do not invent missing code from status documents.
- [ ] Run the existing source, installer, HTTP, security, runtime-package, and browser tests before modifying behavior.
- [ ] Import the complete clean engineering source into GitHub on a feature branch, while keeping the public installation ZIP runtime-only.
- [ ] Record the exact source commit, package SHA-256, migration count, table count, and test results in `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, and `docs/TEST_REPORT.md`.

## Priority 1 — Visual Composer direct manipulation and scene realism

The user wants the Visual Composer to behave like a real canvas editor.

- [ ] Allow direct drag movement of model/photo layers anywhere on the canvas.
- [ ] Allow layers to move well beyond every canvas edge, including negative X/Y positions and positions below the Hero, so lower legs or other portions can be clipped to create a convincing floor/foreground illusion.
- [ ] Clip everything outside the live Hero/Footer viewport on the public site.
- [ ] Allow image resizing from extremely small to extremely large. Preserve aspect ratio by default and provide precise numeric controls.
- [ ] Keep resize handles reachable even when a layer is partly off-canvas.
- [ ] Allow direct movement and resizing of the live text/button group as one canvas object.
- [ ] Preserve separate Desktop, Tablet, and Mobile positions/sizes for backgrounds, model layers, and the live text/button group.
- [ ] Support background panning, focal point, zoom, brightness, contrast, saturation, overlay, and blur.
- [ ] Preserve undo/redo, layer ordering, lock/hide, duplicate/delete, snapping, alignment guides, and keyboard/numeric movement.
- [ ] Verify that model layers remain separately editable and are not flattened into Canva artwork.
- [ ] Add optional foreground/occlusion layers or masks later if needed so furniture can appear in front of model cutouts; do not fake this by permanently baking the model into the room image.

### Broken Visual Composer media

- [ ] Fix all asset URL resolution rules for bundled `/images/...` assets versus uploaded `/uploads/images/...`, `/uploads/models/...`, `/uploads/thumbnails/...`, and related media locations.
- [ ] Media Tray must show image-compatible assets only.
- [ ] Missing files must show a controlled placeholder and useful status, never a permanent broken-image icon.
- [ ] Add automated coverage that loads every Media Tray thumbnail and fails on 404s or invalid image responses.

## Priority 2 — Premium Hero Background Library

The user requested many individual, upscale, people-free scene backgrounds that transparent model photos can interact with naturally. These must be standalone full-width images, not collage sheets.

### Required scene categories

- [ ] Multiple luxury bedrooms and romantic suites
- [ ] Upscale kitchens
- [ ] Elegant living rooms and penthouses
- [ ] Game rooms with pool tables
- [ ] Private home bars and cocktail lounges
- [ ] Home theaters
- [ ] Luxury bathrooms, spas, and saunas
- [ ] Walk-in closets and dressing rooms
- [ ] Music/recording studio
- [ ] Wine cellar
- [ ] Mansion foyer or staircase
- [ ] Rooftop lounge
- [ ] Garden patio and fire-pit lounge
- [ ] Infinity pool and waterfall pool
- [ ] Beach and private cabana
- [ ] Outdoor/pool bar
- [ ] Sunset terrace
- [ ] Yacht deck
- [ ] Private jet cabin
- [ ] Mountain lodge/retreat

### Scene requirements

- No people, mannequins, embedded model silhouettes, text, logos, labels, watermarks, or collage borders.
- Fancy, polished, photorealistic, and upscale; avoid plain or generic stock-room appearance.
- Wide Hero composition with believable floor plane, perspective, lighting, and open placement zones.
- Preserve enough negative space for live website text and buttons.
- Include foreground objects/edges where appropriate so off-canvas clipping and later occlusion can create believable interaction.
- Provide optimized runtime versions and separate thumbnails while preserving high-quality masters outside the public runtime ZIP when appropriate.

### Generated assets from the prior active workspace

The following standalone scene filenames were generated in the prior working environment but were not committed to GitHub or verified as integrated into the application. Recover them if still available; otherwise regenerate equivalent scenes:

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

Additional Adobe-generated scene concepts were created for a bedroom, kitchen, living room, game room, bar, infinity pool, beach cabana, outdoor bar, rooftop, spa bathroom, mansion foyer, theater, walk-in closet, garden fire pit, yacht deck, mountain lodge, music studio, wine cellar, private jet, and waterfall pool. Their temporary generation URLs were not persisted in the repository; regenerate or recover the actual files before claiming the library is complete.

### Library integration

- [ ] Create searchable/filterable background categories in the Visual Composer.
- [ ] Show a thumbnail, scene title, orientation, and recommended model placement zone.
- [ ] Clicking a scene should preview it immediately without publishing.
- [ ] Save Draft, Full Page Preview, Publish, version history, and rollback must work.
- [ ] Verify every background in desktop, tablet, and mobile modes.

## Priority 3 — Admin-configurable Booking Form Builder

- [ ] Owner can create, edit, delete, enable/disable, and reorder fields.
- [ ] Every field can be required or optional.
- [ ] Supported types: short text, paragraph/textarea, email, phone, dropdown/select, single-choice radio group, and multi-choice checkboxes.
- [ ] Radio options must be linked so only one option can be selected.
- [ ] Checkboxes must support multiple selections and required minimum-selection rules where configured.
- [ ] Email fields must validate realistic address format on both client and server and display a clear inline error.
- [ ] Phone fields must accept digits and format live while typing. Example: `3374508598` becomes `(337) 450-8598` before the user leaves the field.
- [ ] Phone validation must reject incomplete/invalid numbers on both client and server.
- [ ] Store submitted values safely and expose them in the existing booking administration workflow.
- [ ] Preserve existing booking records when the form definition later changes.
- [ ] Add CSRF, authorization, escaping, upload, and spam/rate-limit coverage.

## Priority 4 — Public navigation, authentication pages, and About Me

- [ ] Main public navigation is sticky on every public page and does not cover page anchors or mobile controls.
- [ ] Remove `Contact` from the main navigation; Booking is the public inquiry/contact path.
- [ ] Add `About Me` immediately after Booking in the navigation.
- [ ] About Me is a dedicated public page, not a homepage anchor.
- [ ] About Me left sidebar contains owner-editable label/value statistics such as height, measurements, hair, eyes, location, availability, and any custom rows. Empty values remain hidden.
- [ ] Main About Me body contains the owner-editable biography and optional CTA/media.
- [ ] Add a full About Me editor in the appropriate admin category.
- [ ] Login, Join, Create Account, password recovery, and related auth pages must use a clean standalone page layout with sticky nav, centered form in the body, and footer at the bottom—never a narrow form pinned to the left side.
- [ ] Verify desktop, tablet, mobile, keyboard, and screen-reader behavior.

## Priority 5 — Reorganize administration navigation

- [ ] Group all owner pages into logical collapsible categories, such as Dashboard, Site & Design, Content & Media, Members & Communications, Commerce, Marketing, Extensions, and System.
- [ ] Persist expanded/collapsed category state across refreshes and returns.
- [ ] Preserve the active page and active-page highlight after refresh.
- [ ] Do not reset the user to the dashboard when reloading an admin page.
- [ ] Automatically expand the category containing the active page.
- [ ] Ensure mobile admin navigation remains usable.
- [ ] Place Booking Form Builder under Members & Communications, About Me under Site & Design, and Merchandise under Commerce.

## Priority 6 — Merchandise store and PPV purchase separation

- [ ] Build a real merchandise catalog with products, images, descriptions, prices, variants/sizes/options, stock/status, shipping requirement, cart, checkout, and order administration.
- [ ] Subscription plans may be promoted from the Store but should use the membership/subscription checkout flow rather than behave like physical merchandise.
- [ ] PPV photo galleries and PPV videos must not appear as normal cart merchandise.
- [ ] PPV gallery/video pages must use a direct `Buy Now` or rental checkout flow tied to the selected content.
- [ ] Keep purchased PPV content in My Library and preserve existing entitlement, refund, and chargeback behavior.
- [ ] Test cart contents so physical merchandise cannot accidentally mix with an incompatible PPV entitlement flow unless a deliberate mixed-order design is implemented later.
- [ ] The bundled payment provider remains sandbox-only. Do not claim live payment readiness.

## Priority 7 — Canva workflow clarity

Current Canva behavior should be treated as a manual bridge unless a real OAuth/API integration is verified.

- [ ] Document clearly that `Edit in Canva` is a saved-link shortcut, not live synchronization.
- [ ] Document the export/import workflow for Hero, Footer, gallery covers, and video posters.
- [ ] Keep navigation, text, buttons, paywalls, forms, and media players as live HTML/JavaScript.
- [ ] Prefer Canva for backgrounds/decorative artwork and the Visual Composer for separately movable transparent model layers.
- [ ] Do not claim Canva OAuth, automatic export, or automatic sync until credentials and end-to-end tests exist.

## Required acceptance tests before calling 0.8.1 complete

- [ ] Direct drag, resize, off-canvas movement, lower-leg clipping, background pan, and text/button group movement in a real browser.
- [ ] No broken images in the Visual Composer or public Hero/Footer.
- [ ] At least 30 distinct standalone background scenes available and selectable.
- [ ] Booking field creation, reordering, required/optional behavior, dropdown, radio, checkbox, email validation, and live phone formatting.
- [ ] Sticky navigation on all public routes.
- [ ] Centered Login/Join/Create Account pages.
- [ ] About Me public page and owner editor.
- [ ] Admin collapsible state and active page preserved after refresh.
- [ ] Merchandise cart/checkout works while PPV uses direct Buy Now.
- [ ] Existing subscriptions, entitlements, refunds, protected media, messaging, booking, add-ons, installer, and marketing features have regression coverage.
- [ ] PHP 8.2/8.3 source suite, JavaScript syntax, disposable MySQL 8 migration apply/verify/rollback, HTTP acceptance, repository-safety scan, desktop/mobile Playwright, and runtime-only package checks are green.
- [ ] Customer ZIP starts uninstalled and excludes GitHub/docs/tests/SDK/build artifacts and all runtime/private data.

## Recommended delivery target

Use a new implementation branch such as:

```text
feature/0.8.1-site-ux-hero-library
```

Do not start 0.9.0 compliance work until the user-facing 0.8.1 continuation above is either implemented and verified or explicitly deferred by the user.
