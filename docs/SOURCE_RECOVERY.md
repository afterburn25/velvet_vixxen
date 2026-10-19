# Source Recovery and Evidence Reconciliation

Recovery date: 2026-10-19. Repository: `afterburn25/velvet_vixxen`.

## Verified source inputs

| Input | SHA-256 | Role |
| --- | --- | --- |
| Nikki-Wilder-Velvet-Vixxen-0.8.1-Fresh-Install.zip | `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87` | Authoritative recovered runtime baseline: 211 files, 132 PHP files. |
| Nikki-Wilder-Velvet-Vixxen-0.8.1-Recovery.zip | `7e007fbae083b6148c0e74e50edb7e53f81f87142001a31b8049ca6df1409127` | Prior recovery utility reference; not blindly installed or published as a secret URL. |
| Nikki-Wilder-Velvet-Vixxen-0.8.0-to-0.8.1-Upgrade.zip | `f0f648fdf6d0a98b4108a286f3d08c28a0d6c8b484c22dce5eeb92d884d5108e` | Original upgrade distribution reference. |
| Nikki-Wilder-Velvet-Vixxen-0.7.4.zip | `860447db31eba4dfb6ee7acd686021cb63eac17d3fd161b1881160e4676c77e3` | Separate composer fix recovered and reconciled into the newer runtime. |
| Nikki-Wilder-Velvet-Vixxen-0.6.0.zip | `862e9538a5b3c4efc8f8616fbbdc079b9fe252871252f5e2b275ab971394a01c` | Existing engineering tests, SDK, and development helpers only; never used to replace newer application code. |

All selected archives passed ZIP integrity checks. The three 0.8.1 archive hashes exactly matched their separately retained checksum files. The 0.7.4 hash matched its GitHub handoff.

The recovered uploaded 0.8.0 archive has hash `287b32dee373dc0fdc5ec56be4e8b4631f19971936dca59860b574c8c1245873`, which differs from the historical 0.8.0 handoff's `ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993`. It is not silently substituted for the latter or used as the newer runtime baseline.

## What GitHub actually contained at recovery

- `main`: `0a4940ec816b0a21459b4ae6e8c2bf4818eba060`, describing 0.3.0.
- Latest marketing branch: `1a399e7fcebcb8441910230241fe75f30758014f`.
- Separate composer branch: `f98a9d85043741fc0575614905dcffe69b4ed61c`.
- Both newer branches lacked executable application files.
- The conditional source job passed by skipping application tests when `scripts/test.sh` was missing.
- The marketing branch's repository check failed because it searched for a literal installer phrase that its own updated document no longer contained.
- An old 0.3.0 archive existed on another branch. It was not imported over the newer source.

## Reconciliation decisions

The 0.8.1 source contains the older composer implementation despite its larger version number. The separate 0.7.4 composer JavaScript/CSS, live-content placement behavior, admin controls, and asset resolver therefore needed a targeted merge. Store persistence was already compatible; unrelated marketing/recovery/routing code was retained.

The actual marketing implementation is `app/Core/MarketingStore.php`, a protected file store. Historical descriptions of `MarketingService.php`, `MarketingStoreTrait.php`, MySQL marketing persistence, audience-aware publishing, and lock-aware scene scheduling are not evidence those components exist. The runtime runner prepares outbox files; it does not demonstrate production mail delivery.

The recovered archive also lacks the later claimed About Me page/editor, configurable booking builder, merchandise store, revised direct-PPV checkout, and expanded background collection. These remain explicit follow-up work. Existing functions are preserved, and the roadmap has been corrected to stop treating those claims as landed code.

The migration parser now recognizes both quoted and unquoted CREATE TABLE statements. The recovered migrations declare 78 tables, not the earlier reported 75. A database test must verify the actual tables and rollback rather than infer correctness from that count.

## Distribution policy

Engineering source, tests, workflows, documentation, and SDK belong in GitHub. Customer ZIPs are generated from that same source and contain runtime files only. Installed configuration, generated keys, locks, customer records, private messages/media, runtime logs, and existing uploads never become repository source or upgrade payloads.

The user's standing requirement is to upload the latest work and progress documentation. Source import must not be deferred again while claiming delivery is complete.
