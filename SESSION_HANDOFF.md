# Session Handoff — Velvet Vixxen 0.7.3

## Resume point

Current runtime package: **0.7.3 — Installer Verification & Simplified Setup Hotfix**

Repository: `afterburn25/velvet_vixxen`

Branch: `fix/0.7.3-installer-verification`

Runtime package SHA-256:

```text
202cb028ff7b61918d718a69604fa0a82db51ada0a0152379af8ba3b401a9eb0
```

The customer ZIP remains runtime-only. Engineering documents, tests, SDK source, screenshots, and GitHub files are not included in it.

## Reported production failure

The shared-hosting installation reached the last stage and returned:

```text
Installation stopped and attempted to roll back safely:
Final configuration verification failed.
```

The prior verifier rewrote `config/app.php` and immediately required the same fixed path. Shared-hosting OPcache/realpath state can return the earlier uninstalled configuration during the same request, especially after the ownership-safe `/config` directory replacement.

## 0.7.3 implementation

- `App\Core\Config::write()` now checks the exact on-disk payload digest.
- PHP stat and OPcache entries are invalidated after atomic activation.
- `Config::loadFresh()` loads the exact bytes through a unique protected temporary path and deletes it immediately.
- The loaded settings array must exactly match the requested values.
- Final verification independently checks installed state, version, installation ID, base URL, generated security keys, database connection, seeded site settings, and install lock.
- Error messages identify the failed component.
- The installer UI is reduced to three visible sections: Database, Owner and Website, and Install.
- Technical server/folder details are collapsed and display only issues.
- Support/sender email defaults to owner email; outgoing mail is Configure Later.
- Currency/time-zone/age-gate defaults remain editable after installation.

## Preserved locked decisions

- No installation key.
- 12-character minimum owner/member passwords.
- Safe `0755`/`0775`; never `0777`.
- Automatic ownership-safe runtime-folder repair.
- Organized runtime-only ZIP.
- Versioned migrations and rollback attempts.
- Installation lock and `/install` retirement.

## Local verification

- 113 PHP runtime files: syntax passed
- 6 public JavaScript files: syntax passed
- 11 migrations / 64 tables: manifest unchanged
- Simplified installer HTTP render: passed
- AJAX requirement recheck: passed
- Config write/read under OPcache with timestamp validation disabled: passed
- Config parent-directory replacement simulation: passed
- Ownership mismatch simulation: 75 directories, 0 failures, ready
- ZIP integrity, clean state, runtime exclusions, and checksum: passed

## Limits

- PDO MySQL/MySQL unavailable locally; live DDL execution remains CI-owned.
- Interactive Playwright remains CI-owned.
- Payment remains sandbox-only.

## Next work

1. Confirm 0.7.3 on the affected host.
2. Import complete engineering source separately from the runtime ZIP.
3. Run full PHP/MySQL/HTTP/Playwright CI.
4. Continue **0.8.0 — Marketing, Publishing & Analytics**.
