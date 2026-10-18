# Test Report — 0.7.3 Installer Verification & Simplified Setup

## Reported failure reproduced at the verification boundary

The affected shared-hosting installation reached the last setup stage and returned:

```text
Installation stopped and attempted to roll back safely:
Final configuration verification failed.
```

The final verifier previously rewrote `config/app.php` and immediately loaded the same fixed path. A stale PHP OPcache/realpath entry could return the earlier uninstalled configuration during the same request, especially after ownership-safe replacement of the parent `/config` directory.

## Implemented correction

- Atomic configuration activation now invalidates PHP stat and OPcache entries.
- The exact on-disk file bytes are read back and SHA-256 checked.
- The activated bytes are loaded through a unique protected temporary verification path and removed immediately.
- The loaded settings array must exactly equal the requested settings.
- Final verification independently checks installed state, release version, installation ID, base URL, generated security keys, database reconnection, seeded site settings, and install lock.
- Failure messages identify the mismatched component.

## Simplified installer acceptance

The visible installer now contains only:

1. Database
2. Owner and Website
3. Install

Technical server and directory diagnostics remain available in a collapsed section. Successful low-level probes are summarized; warnings and failures remain visible. Email delivery defaults to Configure Later, while support and sender email default to the owner email.

## Runtime syntax and package checks

Results:

- PHP syntax: **113 runtime files passed**
- Public JavaScript syntax: **6 files passed**
- Database migrations: **11**
- Declared application tables: **64**
- Runtime package files: **186**
- ZIP integrity: **passed**
- Runtime-only exclusion rules: **passed**
- Clean/uninstalled configuration: **passed**
- No generated credentials, keys, install lock, logs, sessions, customer records, or private communications: **passed**
- No mode `0777`: **passed**

## Installer HTTP checks

A clean extracted runtime package was served through PHP.

Verified:

- Installer returned HTTP 200.
- Simplified quick-setup page rendered.
- Database, Owner and Website, and Install sections were present.
- Technical details were collapsed.
- The former visible Email Delivery section was absent.
- AJAX requirement recheck returned valid JSON.
- Owner/support/sender default handling accepted `red.pixie93@gmail.com`.

## OPcache and parent-directory replacement regression

The final-config test ran with CLI OPcache enabled and timestamp validation disabled. It preloaded an old configuration, replaced the parent `/config` directory to model shared-hosting ownership repair, wrote the final installed configuration, and immediately loaded it through the new verifier.

Result:

```text
CONFIG_DIRECTORY_SWAP_OK
```

Verified values included:

- `installed=true`
- `version=0.7.3`
- expected installation ID
- expected base URL
- non-empty generated security keys

## Shared-hosting ownership simulation

The existing runtime folders were owned by one user with mode `0755`. PHP was executed as another user and allowed to write only in the site root.

The installer automatically replaced affected panel-owned runtime folders with PHP-owned copies, preserved contents, recreated missing subdirectories, and reran real write/rename/read/delete probes.

Result:

```text
Runtime directories checked: 75
Required file failures: 0
Installer ready: true
```

No `0777` permissions were used.

## Database boundary

Local PDO MySQL and a MySQL/MariaDB server were unavailable. Live DDL execution is therefore not claimed locally. GitHub CI remains responsible for applying all 11 migrations, verifying 64 tables, rolling back, and confirming that the disposable database is empty.

## Browser boundary

Interactive Playwright remains CI-owned. The simplified installer’s server-rendered HTML and AJAX behavior passed local HTTP checks.

## Release checksum

```text
202cb028ff7b61918d718a69604fa0a82db51ada0a0152379af8ba3b401a9eb0
```

## Result

**PASS — the 0.7.3 runtime package passed all available local installer, cache-regression, shared-hosting ownership, syntax, integrity, exclusion, and security checks.**
