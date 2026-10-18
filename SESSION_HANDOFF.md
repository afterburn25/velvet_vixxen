# Session Handoff — Velvet Vixxen 0.6.0

## Start here

Project: Nikki Wilder / The Velvet Vixxen

Repository: `afterburn25/velvet_vixxen`

Current packaged build: **0.6.0 — Add-on Framework**

The package and GitHub repository are separate truth boundaries. Verify the live branch, pull request, commit, and workflow state before claiming source import, green CI, merge, tag, or release status.

## Locked decisions

- Approved neon-pink design; no new mockups unless explicitly requested
- Single responsive browser page installer
- No installation key
- 12-character minimum owner and member passwords
- Safe `0755`/`0775`; never `0777`
- Organized folders
- Live HTML/JavaScript for navigation, forms, paywalls, commerce, and media players
- One Entitlement Resolver across subscription, PPV, rental, bundle, gift, and complimentary access
- Add-ons extend the platform without editing core files
- PHP add-ons are not claimed to be fully sandboxed
- Public source must not contain production secrets, customer data, identity documents, private messages, signing private keys, runtime state, or private/paid media

## 0.6.0 delivered locally

- Add-on manifest, runtime, context, loader, manager, package inspector, migration runner, license service, and store support
- ZIP inspection, permissions, compatibility, dependencies/conflicts, checksums, and optional Ed25519 signatures
- Install, activate, deactivate, update, backup, rollback, preserve-data uninstall, and purge uninstall
- Add-on-owned migrations
- Boot-failure quarantine and safe mode
- Admin pages/navigation, public routes, widgets, events, filters, services, settings, and scheduled tasks
- Core member/commerce events and commerce quote filter
- Homepage widget slots and protected route dispatcher
- Free, trial, and signed offline commercial-license foundations
- Admin Add-on Manager, logs, diagnostics, task runner, starter source/ZIP, and developer documentation
- Ninth migration / 48 declared tables

## Final local verification

```text
bash scripts/test.sh                         PASS
VELVET_TEST_PORT=8774 bash scripts/test_http.sh PASS
Extracted release checksum verification      PASS
```

Validated: 122 PHP files, 6 public JavaScript files, 7 Python files, 9 migrations, 48 tables, package integrity/signature cases, lifecycle actions, repair/rollback, safe mode, task execution, and real HTTP add-on pages/widgets/routes/settings.

Final release SHA-256: `862e9538a5b3c4efc8f8616fbbdc079b9fe252871252f5e2b275ab971394a01c`

## Limits

- No local PDO MySQL/MySQL; CI owns live DDL validation.
- Interactive Playwright is not claimed locally in the packaging environment; CI owns four Playwright suites.
- Payment remains sandbox-only.
- Remote licensing, customer downloads, remote update feeds, and marketplace sales are not complete.

## Repository work still required

- Issue #1: import the complete clean 0.6.0 source and activate full CI.
- Issue #7: deliver 0.6.0 and preserve release-gate evidence.
- Branch: `feature/0.6.0-addon-framework`.

Do not merge documentation-only work as though it contains the executable source.

## Next implementation milestone

**0.6.1 — Commercial Licensing & Remote Updates**

1. Define separate license/update service APIs.
2. Add online activation/deactivation, site limits, trials, grace periods, and update entitlement.
3. Add signed remote update feeds and stable/beta channels.
4. Add caching and no-site-breakage behavior during service outages.
5. Add customer download/release-history foundations.
6. Test activation transfer, expiration, revocation, outages, signature failure, update, and rollback.

## Clean-tree rule

Before packaging or committing:

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
bash scripts/clean_runtime.sh
php tests/Security/repository_safety.php
```
