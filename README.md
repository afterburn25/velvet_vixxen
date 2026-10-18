# Nikki Wilder — The Velvet Vixxen

This is the authoritative repository and communication hub for the full-stack Velvet Vixxen creator platform.

## Current state

- Current packaged application build: **0.3.0 — Media Center**
- Documentation/status branch: `main`
- Prepared implementation branch: `feature/0.3.0-media-center`
- Full executable source import: pending in issue #1
- Media Center tracking: issue #2
- Release test-gate tracking: issue #3

The 0.3.0 package includes the approved responsive pink homepage, Hero/Footer Visual Composer, Canva workflow, owner administration, single-page browser installer, exact 12-character owner-password minimum, no installation key, organized folders, safe permission handling, versioned migrations, Music/Photos/Videos sections, persistent audio playback, gallery lightbox, video playback, media administration, and signed protected-media delivery.

The repository currently contains the authoritative roadmap, decisions, status, handoff, security/testing policy, issues, feature branch, and passing repository-contract CI. It does not yet contain the complete executable 0.3.0 source tree; future agents must complete issue #1 before treating GitHub as the buildable source release.

## Start here

- [`PROJECT_STATUS.md`](PROJECT_STATUS.md) — verified implementation and repository status
- [`SESSION_HANDOFF.md`](SESSION_HANDOFF.md) — exact continuation point for new sessions and agents
- [`AGENTS.md`](AGENTS.md) — operating rules for coding agents
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — versioned product roadmap
- [`docs/DECISIONS.md`](docs/DECISIONS.md) — locked product and architecture decisions
- [`docs/TESTING.md`](docs/TESTING.md) — local, CI, database, browser, media, and commerce test requirements
- [`docs/SESSION_PROTOCOL.md`](docs/SESSION_PROTOCOL.md) — cross-session communication procedure
- [`SECURITY.md`](SECURITY.md) — public-repository and vulnerability policy

## Next sequence

1. Import and verify the complete 0.3.0 source on `feature/0.3.0-media-center`.
2. Run PHP 8.2/8.3, MySQL 8 migration/rollback, security, and Chromium acceptance CI.
3. Merge only after the full matrix is green.
4. Begin **0.4.0 — Members & Entitlements**.

## Repository safety

This repository is public. Do not commit production credentials, identity documents, payment secrets, customer information, private messages, runtime logs, or private/paid media.
