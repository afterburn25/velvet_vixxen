# Session Handoff

## Current release

`0.2.2 — One-Page Browser Installer`

## Authoritative source

- Repository: `afterburn25/velvet_vixxen`
- Release branch: `main`
- Active next milestone: `0.3.0 — Media Center`

The approved visual direction remains the full-width neon-pink bedroom/studio concept with live HTML content and open composition areas for transparent Nikki cutouts.

## Verified implementation state

- Responsive public homepage
- Hero and visual Footer layered composer
- Independent desktop, tablet, and mobile scene arrangements
- Background and transparent-layer uploads with custom thumbnails
- Canva-compatible template workflow
- Owner login and administration foundation
- Single-page branded browser installer
- PHP/MySQL preflight and database connection testing
- Real filesystem write/rename/read/delete probes
- Safe `0755`/`0775` permission handling; no `0777` fallback
- Exact 12-character minimum owner password
- No installation key
- Versioned migrations creating the application foundation
- Installation lock, final boot verification, and installer cleanup
- Organized project folders, including `/includes`, `/images`, `/database`, `/tests`, and `/storage`

## Not complete yet

- Public and administrative Music Center
- Persistent audio player
- Photo-gallery publishing and lightbox
- Video publishing and player
- Member registration/dashboard
- Subscription billing
- PPV photo galleries and videos
- Payment provider adapters
- Add-on manager, commercial licensing, and marketplace

## Repository/test setup begun

The repository now contains the communication protocol and status documents. CI and source import must be kept synchronized with the working application package. The local verified test entry point is:

```bash
bash scripts/test.sh
```

A disposable MySQL integration test is defined in `tests/Database/migrations_mysql.php` and must run in CI once the source snapshot is present.

## Exact next task

Create `feature/0.3.0-media-center`, import the tested 0.2.2 application baseline, then implement the Media Center in this order:

1. Media repository/service layer and access-mode model
2. Music administration, albums, playlists, and persistent audio player
3. Photo gallery administration and full-screen viewer
4. Video administration, trailers, poster images, and player
5. Protected public previews and signed media delivery
6. Browser acceptance tests and updated release documentation

## Required session start procedure

Read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, and `docs/TESTING.md`; inspect recent commits, issues, and CI; then run tests before making changes.
