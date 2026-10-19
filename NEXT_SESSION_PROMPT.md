# Copy-Paste Prompt for the Next Session

Use the following prompt in a new ChatGPT session:

---

Continue development of **Nikki Wilder / The Velvet Vixxen** using GitHub repository **`afterburn25/velvet_vixxen`**.

Before answering or coding, inspect the live repository. Start with implementation branch:

```text
feature/0.8.1-site-ux-hero-library
```

Read these files in full:

```text
INCOMPLETE_WORK.md
SESSION_HANDOFF.md
PROJECT_STATUS.md
docs/ROADMAP.md
docs/TEST_REPORT.md
NEXT_SESSION_PROMPT.md
```

Also inspect open GitHub issues, especially the issue titled **“Continue 0.8.1 Site UX, Hero Library, Booking Builder, Admin IA & Merch Store”**, plus existing source-import and release-gate issues.

## Critical truth boundary

Do not rely on prior chat claims that something was built. Verify the actual executable source, current branches, files, tests, and artifacts. The original handoff documents a packaged 0.8.0 Marketing/Publishing/Analytics milestone, but complete executable 0.8.0 engineering source and full CI were still not imported/verified in GitHub at handoff time.

First determine the newest branch or artifact that actually contains runnable application source. If the newest branch is documentation-only, locate/import the latest clean engineering source before implementing more features. Keep the public customer ZIP runtime-only and keep repository docs/tests/SDK/workflows outside that ZIP.

## User-locked requirements

Preserve all of these decisions:

- Approved neon-pink/magenta/black Velvet Vixxen design; no new mockups unless explicitly requested.
- No placeholder models in the final site. The user supplies transparent cutout photos.
- One simplified browser installer page.
- No installation key.
- Exact 12-character minimum owner/member passwords.
- Safe `0755`/`0775`; never `0777`.
- Organized folders; do not cram files into the root.
- Runtime-only customer ZIPs.
- Payment remains sandbox-only until a live adult-compatible provider is separately approved and tested.
- Do not commit credentials, customer/member data, identity records, private messages/attachments, payment secrets, raw license keys, private signing keys, logs, sessions, runtime state, or private/paid media.

## Current continuation checkpoint

The implementation branch now contains real source recovered in commit `43945af07c145c67db2f2883a4fa7f607215dc23`, followed by actual 0.8.1 feature work. PR #15 and issue #13 track it. Composer/library, Booking Builder, About/public/admin UX, merchandise/direct PPV, manual Canva and installed database updates are implemented with focused executable tests. The library has 30 actual scenes/30 thumbnails and 90 published device renders. Schema:14 migrations/82 tables.

Start by checking whether the final combined feature CI and runtime package evidence in `docs/TEST_REPORT.md` has finished. Do not redo completed recovery or feature implementation, and do not substitute the baseline run for new feature verification. Continue any remaining observed failures, artifact checksum recording and latest-source/docs upload. Preserve the detailed requirements below.

## Finish and verify the 0.8.1 continuation

Implement and verify the scope in `INCOMPLETE_WORK.md`, in this order:

1. Recover/import and test the real executable baseline.
2. Finish Visual Composer direct dragging, huge/tiny resizing, movable live text/button box, separate desktop/tablet/mobile layouts, background panning, correct media paths, and off-canvas clipping so lower legs or other areas can be hidden below/behind the Hero viewport.
3. Integrate at least 30 standalone, people-free, upscale Hero backgrounds—bedrooms, kitchens, living rooms, game room/pool table, bars, pools, beaches, rooftop, outdoor bar, spa, closet, theater, yacht, jet, mountain lodge, music studio, wine cellar, garden, foyer, and similar scenes. They must be individual images, not collages.
4. Build the admin-configurable Booking Form Builder with text, textarea, email, phone, dropdown, single-choice radio, and multi-choice checkbox fields; required/optional controls; reordering; server/client validation; and live `(337) 450-8598` phone formatting.
5. Make public navigation sticky everywhere, remove Contact, add About Me after Booking, build the About Me sidebar stats + biography page and admin editor, and center Login/Join/Create Account forms in full page layouts with nav and footer.
6. Reorganize admin into collapsible logical categories that preserve open/closed state and current page after refresh.
7. Build a real merchandise store and cart. Keep subscriptions on the membership checkout flow. Keep PPV photos/videos out of the merchandise cart and use direct Buy Now/rental checkout from the content page.
8. Clarify the Canva workflow as a manual export/import bridge unless a real tested Canva API integration exists. Keep model cutouts as separate Visual Composer layers.

## Testing and evidence

Do not claim completion without direct evidence. Required gates include:

- PHP 8.2 and 8.3 source suite
- JavaScript syntax checks
- Disposable MySQL 8 migration apply/verify/rollback
- Installer regression and shared-hosting write/ownership checks
- HTTP acceptance
- Desktop/mobile Playwright for drag, resize, off-canvas clipping, booking builder, sticky nav, auth pages, About Me, admin state persistence, merchandise, direct PPV purchase, and regressions
- Zero broken Visual Composer or Hero/Footer images
- At least 30 selectable standalone backgrounds
- Repository-safety and secret scans
- Runtime-only customer ZIP exclusion checks

Continue the existing implementation branch:

```text
feature/0.8.1-site-ux-hero-library
```

Update `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `INCOMPLETE_WORK.md`, `docs/ROADMAP.md`, `docs/TEST_REPORT.md`, and the tracking issue as work lands. Commit code, tests, and evidence to GitHub. Do not move on to 0.9.0 until this user-facing continuation is verified or the user explicitly defers it.

At the beginning of your response to me, report:

1. Which branch contains the newest executable source.
2. Which handoff files you read.
3. What is actually implemented versus only documented.
4. The first concrete implementation step you are taking.

Then continue building without stopping at planning-only output.

---
