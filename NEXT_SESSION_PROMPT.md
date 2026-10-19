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

## Current priority — existing-database installer and layout repair

Continue from the latest executable feature branch. Installer/database/layout implementation and package source is `e5b04daa132521be69c943151f8112c7cd2017e8` (tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`); matching customer downloads were saved on 2026-10-19 and are byte-identical to the gated GitHub artifact. All seven jobs in [run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907) are completed/success. The installer now reuses compatible existing databases, preserves owner credentials/records/settings/scenes/keys/identity, resumes failed DDL and finalization, and keeps pending setup out of automatic recovery/public activation. The owner also required evenly aligned Server/Folders checks, removal of the extra Folders rule, and aligned Password/Confirm Password inputs. Those fixes and completion-state cleanup are part of this same repair.

Read the current `PROJECT_STATUS.md`, `docs/TEST_REPORT.md`, `RELEASE_MANIFEST.txt` and `docs/releases/0.8.1-installer-database-repair.json` for actual source/CI/download status. The database repair is an eight-file runtime overlay (six PHP, CSS and JavaScript) and supersedes the one-file Folder Repair ZIP for this issue. Use `docs/INSTALLER_DATABASE_REPAIR.md` for the already-uploaded unfinished site. Preserve configuration, storage, uploads and database contents. A genuine incompatible schema or missing original key set requires a specific diagnosis; never restore the blanket empty-database requirement or silently overwrite an existing owner.

Keep the real packaged MySQL installer suite mandatory on PHP 8.2 and 8.3, and keep the real desktop/mobile installer browser flow alongside the existing seven HTTP/twelve browser suites. Preserve the actual foreign-owner and persistent-OPcache package tests. A configured CI gate, intermediate archive or previous source commit is not evidence that the latest changes passed. Update every handoff and issue #13, commit/upload the work, and provide the corrected downloadable packages before a completed handoff.

## Earlier verified continuation checkpoint

The **0.8.1 user-facing engineering continuation is implemented and verified** on `feature/0.8.1-site-ux-hero-library`. Runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c); complete GitHub CI: [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), **all six jobs passed**. PHP 8.2.34/8.3.35, JavaScript syntax, MySQL 8.0.46, installer/ownership/security, seven HTTP suites, twelve real Chromium suites and gated runtime packaging all passed. The library has 30 individual scenes and 30 thumbnails, with 90 successful published renders across desktop/tablet/mobile.

This is the verified sandbox development milestone. Payments remain sandbox-only, actual owner content must be supplied by the owner, and production hosting/live-service certification is not claimed. PR #15 contains the feature branch; inspect its current merge state separately. No 0.9.0 implementation is included.

The six progress documents and issue #13 record the completed implementation. Inspect their latest live state, CI and artifacts before further changes. Do not redo recovery or rebuild existing features from old chat claims. Continue the owner's current requested work from this executable branch and preserve the verified requirements below.

## Preserve the verified 0.8.1 continuation

The following scope is implemented and verified in `INCOMPLETE_WORK.md`; preserve its behavior and regression coverage:

1. Recover/import and test the real executable baseline.
2. Finish Visual Composer direct dragging, huge/tiny resizing, movable live text/button box, separate desktop/tablet/mobile layouts, background panning, correct media paths, and off-canvas clipping so lower legs or other areas can be hidden below/behind the Hero viewport.
3. Integrate at least 30 standalone, people-free, upscale Hero backgrounds—bedrooms, kitchens, living rooms, game room/pool table, bars, pools, beaches, rooftop, outdoor bar, spa, closet, theater, yacht, jet, mountain lodge, music studio, wine cellar, garden, foyer, and similar scenes. They must be individual images, not collages.
4. Build the admin-configurable Booking Form Builder with text, textarea, email, phone, dropdown, single-choice radio, and multi-choice checkbox fields; required/optional controls; reordering; server/client validation; and live `(337) 450-8598` phone formatting.
5. Make public navigation sticky everywhere, remove Contact, add About Me after Booking, build the About Me sidebar stats + biography page and admin editor, and center Login/Join/Create Account forms in full page layouts with nav and footer.
6. Reorganize admin into collapsible logical categories that preserve open/closed state and current page after refresh.
7. Build a real merchandise store and cart. Keep subscriptions on the membership checkout flow. Keep PPV photos/videos out of the merchandise cart and use direct Buy Now/rental checkout from the content page.
8. Clarify the Canva workflow as a manual export/import bridge unless a real tested Canva API integration exists. Keep model cutouts as separate Visual Composer layers.


## Earlier feature source and artifact evidence

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

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
4. The first concrete step for the owner's current request, using the verified source rather than repeating completed work.

Then continue building without stopping at planning-only output.

---
