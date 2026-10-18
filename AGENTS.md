# Agent Instructions — Velvet Vixxen

## Start every session with live repository evidence

Repository: `afterburn25/velvet_vixxen`

Read `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, and `docs/TEST_REPORT.md`, then inspect open issues, the active pull request, current branch head, and GitHub Actions. Do not infer repository state from chat history.

## Current packaged milestone

**0.6.0 — Add-on Framework**

The complete executable source is still tracked for import by issue #1. Issue #7 tracks 0.6.0 delivery. Documentation on a branch is not proof that executable source, MySQL, Playwright, merge, tag, or release gates are complete.

## Locked requirements

- Preserve the approved neon-pink design.
- Do not create new design mockups unless explicitly requested.
- Keep the installer on a single responsive browser page.
- No installation key.
- Keep 12-character minimum owner/member passwords.
- Never use `chmod 0777`.
- Keep files organized in proper folders.
- Preserve the unified entitlement model.
- Keep live forms, navigation, commerce, paywalls, and players as HTML/JavaScript.
- Add-ons must extend the platform without modifying core files.
- Add-ons are executable PHP, not a secure sandbox.
- Invalid signatures and blocking package findings are non-bypassable.
- Keep sandbox payment behavior separate from live processors.

## Add-on implementation rules

- Permanent reverse-domain-style ID and semantic version
- Explicit compatibility range, entrypoint, permissions, dependencies, and conflicts
- Checksums for package files; Ed25519 signatures for trusted commercial distribution
- No private keys, `.env`, credentials, customer data, or private media in packages
- Add-on-specific table names and reversible migrations
- Separate install and activation
- Deactivation preserves data
- Normal uninstall preserves data; purge is explicit
- Update creates a backup and remains inactive for review
- Boot failures quarantine the add-on
- Safe mode must bypass all optional add-ons

## Testing and evidence

Before claiming completion, run applicable source, HTTP, MySQL, browser, installer, migration, task, package-security, and repository-safety tests. Record exact commands and limitations.

A configured CI job is not a passed test. A static-render screenshot is not interactive Playwright acceptance. Never overstate unavailable environmental checks.

## Repository safety

Never commit production credentials, generated secrets, signing private keys, customer/identity/payment records, private messages, installed configuration, locks, sessions, logs, caches, add-on staging/quarantine runtime data, or private/paid media.

Run `bash scripts/clean_runtime.sh` before packaging/committing and `php tests/Security/repository_safety.php` afterward.

## Release documentation

Update `VERSION`, `CHANGELOG.md`, `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` when architecture changes, `docs/TEST_REPORT.md`, and `RELEASE_MANIFEST.txt`. A milestone is not complete without implementation, tests, documentation, and evidence.
