# Session Handoff

## Current application build

`0.3.0 — Media Center`

The clean release archive was produced in the originating development session with SHA-256:

```text
d57461a60a5122ef023394d74db1b308982c5fb164b05459e1d0b4b81a4a2332
```

## Repository state

- Repository: `afterburn25/velvet_vixxen`
- Documentation/status branch: `main`
- Prepared implementation branch: `feature/0.3.0-media-center`
- Repository contract CI: passing on `main`
- Full executable source import: still open in issue #1
- Media Center tracking: issue #2
- Release testing gate: issue #3

The approved visual direction remains the full-width neon-pink bedroom/studio concept with live HTML and open composition areas for transparent Nikki cutouts.

## What is implemented in the packaged 0.3.0 build

- 0.2.2 single-page installer and organized application foundation
- Media data/service layer supporting file-backed demo and MySQL production modes
- Albums, tracks, audio playlists, galleries, videos, captions, media assets, access modes, scheduling, and pricing metadata
- Persistent audio player with queue, shuffle, repeat, seek, volume, saved state, and Media Session integration
- Gallery index/detail routes and full-screen keyboard/touch viewer
- Video index/watch routes with poster, trailer/full selection, captions, saved playback position, and audio/video conflict handling
- Administrator pages and real save flows for Music, Photo Galleries, and Videos
- Signed private-media endpoint with expiration, HMAC verification, MIME handling, Range support, and traversal protection
- Five people-free demonstration galleries, three synthetic demonstration songs, and one people-free demonstration video
- Six migrations declaring 34 tables
- PHP 8.2/8.3, MySQL 8, and Chromium CI definitions inside the packaged source

## Verified test evidence

- `bash scripts/test.sh` passes
- 60 PHP files pass syntax validation
- 6 public JavaScript files pass syntax validation
- Installer, migration manifest, Media Center, protected-media, organized-structure, and repository-safety tests pass
- Real Chromium acceptance passes desktop/mobile public pages, persistent audio/queue, gallery lightbox, video playback page, owner login, and actual Track/Gallery/Video edit-save workflows
- No browser console, page-script, or unexpected request failures were observed
- Release archive integrity test passes

## Important boundary

The GitHub repository currently contains the authoritative roadmap, decisions, status, handoff, testing policy, issues, and a passing repository-contract workflow. It does **not yet contain the full 0.3.0 executable source tree**. Do not close issues #1 or #2 or merge a Media Center PR until the source is imported and its complete CI matrix passes.

## Exact next task

1. Import the clean 0.3.0 source tree into `feature/0.3.0-media-center`.
2. Exclude generated screenshots, generated Canva packages, runtime configuration, secrets, logs, and private/paid media.
3. Run source tests on PHP 8.2 and 8.3.
4. Run the disposable MySQL 8 apply/verify/rollback test.
5. Run Chromium desktop/mobile acceptance.
6. Update `docs/TEST_REPORT.md` with GitHub run evidence.
7. Open a pull request linked to issues #1, #2, and #3.
8. Merge only after CI is green, then begin `0.4.0 — Members & Entitlements`.

## Required session start procedure

Read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, and `docs/TESTING.md`; inspect recent commits, issues, pull requests, and CI; then run the existing tests before making changes.
