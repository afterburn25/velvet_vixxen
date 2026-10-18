# Agent and Session Operating Guide

This repository is the authoritative source of truth for **Nikki Wilder — The Velvet Vixxen**.

## Start every session here

1. Read `PROJECT_STATUS.md`.
2. Read `SESSION_HANDOFF.md`.
3. Read `docs/ROADMAP.md` and `docs/DECISIONS.md`.
4. Inspect the latest commits, CI status, pull requests, and open issues before changing code.
5. Run the complete test command documented in `docs/TESTING.md`.

## Working rules

- Treat `main` as the last known good release branch.
- Build active milestones on `feature/<version>-<topic>` branches.
- Do not claim a feature is complete unless it is implemented, tested, and documented.
- Update `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `CHANGELOG.md`, and `docs/TEST_REPORT.md` with every completed milestone.
- Keep the release number synchronized in `VERSION`, `includes/version.php`, configuration defaults, asset cache strings, and documentation.
- Do not place unrelated PHP files in the repository root. Use the established folders.
- Do not commit credentials, production configuration, payment secrets, identity documents, private customer information, private media, or runtime logs.
- The public repository may contain only placeholder or approved non-private assets.
- Add database changes through versioned migrations with reversible `down` statements where safely possible.
- Add or update automated tests whenever behavior changes.
- Never weaken the 12-character owner-password minimum or add an installation key.
- Preserve the single-page installer, safe permission handling, and `/install` cleanup requirements.

## Handoff format

Before ending a development session, update `SESSION_HANDOFF.md` with:

- Branch and latest commit
- Current version
- Features completed
- Tests run and outcomes
- Known failures or environmental limitations
- Exact next implementation task
- Any files that require special review

## Issue and pull-request use

- Use issues for scoped work, defects, decisions, and acceptance criteria.
- Link code changes to their issue.
- Pull requests must list implementation details, migrations, security impact, and exact test results.
- Keep unfinished work clearly labeled; do not mark roadmap items complete from plans alone.
