# Agent and Session Operating Guide

This repository is the intended authoritative source of truth for **Nikki Wilder — The Velvet Vixxen** once the current release source has been imported, tested, and merged.

## Start every session here

1. Read `PROJECT_STATUS.md`.
2. Read `SESSION_HANDOFF.md`.
3. Read `docs/ROADMAP.md`, `docs/DECISIONS.md`, and `docs/TEST_REPORT.md`.
4. Inspect latest commits, open issues, pull requests, and CI status before changing code.
5. Run the complete test command documented in `docs/TESTING.md`.
6. Confirm whether the working source is actually present in the repository or only in a release artifact; never assume.

## Working rules

- Treat `main` as the last known good merged release, not as the latest chat claim.
- Build active milestones on `feature/<version>-<topic>` branches.
- Do not claim a feature is complete unless it is implemented, tested, documented, and—when discussing repository state—merged after CI.
- Update `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `CHANGELOG.md`, and `docs/TEST_REPORT.md` with every completed milestone.
- Keep the release number synchronized in `VERSION`, `includes/version.php`, installer/config defaults, asset cache strings, migrations, and documentation.
- Keep application modules in established folders; do not scatter unrelated PHP files in root.
- Never commit credentials, production configuration, payment secrets, identity documents, customer records, private messages, runtime logs, or private/paid media.
- Add database changes through versioned migrations with reversible `down` statements where safely possible.
- Add or update automated tests whenever behavior changes.
- Never weaken the 12-character owner/member password minimum or add an installation key.
- Preserve the single-page installer, safe permission handling, and `/install` cleanup requirements.
- Public/member/admin content access must use the shared Entitlement Resolver.
- Payment adapters must never persist raw card data or fabricate live-provider renewals; production renewals are confirmed by provider webhooks.
- The bundled `sandbox` gateway is test-only and must never be described as a live provider.
- Do not represent live payment processing, browser testing, MySQL execution, email delivery, GitHub CI, or merge status as complete without direct evidence.

## Current work references

- Source-import issue: #1
- 0.5.0 delivery/test-gate issue: #5
- Delivery branch: `feature/0.5.0-subscriptions-ppv-commerce`
- Next planned milestone after import/CI: `0.6.0 — Add-on Framework`

## Handoff format

Before ending a development session, update `SESSION_HANDOFF.md` with:

- branch and latest commit, or explicitly state when no commit/push occurred;
- current version;
- completed features;
- tests run and exact outcomes;
- known failures or environmental limitations;
- exact next implementation task;
- files requiring special review;
- whether runtime/demo state was removed before packaging.

## Issue and pull-request use

- Use issues for scoped work, defects, decisions, and acceptance criteria.
- Link code changes to their issue.
- Pull requests list implementation details, migrations, security impact, backward compatibility, and exact test results.
- Keep unfinished work clearly labeled; plans or schemas alone do not complete roadmap items.
