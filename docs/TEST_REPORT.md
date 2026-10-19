# Velvet Vixxen 0.8.1 — Current Test Evidence

<!-- media-composer-repair:start -->
## Media, Composer buttons and public route repair — 2026-10-19

Current work continues on `feature/0.8.1-site-ux-hero-library` from handoff commit `195699c12620e4803160b28f023764bebc421bb4`. The earlier installer implementation/CI below remains the verified starting baseline; it does not certify this new repair.

The actual application now has **46 physical public entry points covering all 87 registered URLs**, including upload/scene APIs, account aliases, booking, checkout, content pages and existing signed-media URLs. Their protected implementations remain under `public/routes`. This removes the missing-file dependency on rewrites that caused the reported upload JSON errors and failed scene saves. Upload/save/publish/restore handle non-JSON failures readably. Full Page Preview retains a useful error tab instead of closing it if either draft save fails.

The requested **Add Button** control adds separate live link layers with editable text, destinations, styles/colors, text size, corner radius and new-tab behavior. Buttons share the existing direct drag/free resize, numeric, keyboard, snapping, ordering, hide/lock, duplicate/delete, history and device layout controls. The public Hero/Footer renders keyboard-accessible links. Photo cutouts and the existing live text/button group remain independent. The server validates button data before writing; no database migration is needed.

The routing audit also repaired old cleanup behavior that would remove the new entry points. Current forwarding files survive; obsolete controllers and unknown sibling files move to a protected private archive with their bytes preserved. Member login/Requests/logout destinations and marketing post links, stored local calls to action, banners and analytics requests now retain a nested installation's base path. Existing external HTTP(S) destinations remain usable.

**Local verification has passed:** full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite; 127 button assertions; 90 installation-URL assertions; 371 repair-backup assertions; 566 public-route/path assertions; all seven existing HTTP suites; real packaged uploads at root/desktop and nested/mobile; and all 87 public routes before/after real owner cleanup at both installation paths. Negative controls reproduce missing entry points, the JSON parser error, the disappearing-preview cause, unsafe unvalidated buttons and the old cleanup removing 40 active entry points. Final application-browser execution, exact-source GitHub gates and saved customer downloads remain pending at this checkpoint.

The runtime-only installed-site repair contains **67 files: 60 PHP, five JavaScript and two CSS**. The fresh and upgrade ZIPs contain matching runtime bytes. Configuration, storage, uploads, customer records, docs/tests/workflows/screenshots and installer state are excluded from the small repair. The earlier eight-file Installer Database Repair remains separate and byte-identical. Source/tests/progress and issue #13 must be updated before final handoff. See `docs/MEDIA_COMPOSER_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-media-composer-repair.json`. No deployment, merge or 0.9.0 work is claimed.
<!-- media-composer-repair:end -->

## Installer continuation with existing databases — 2026-10-19

The newest executable source remains on `feature/0.8.1-site-ux-hero-library`. This continuation removes the blanket rejection of databases containing Velvet Vixxen tables. The actual installer now handles empty databases, unrelated existing tables, compatible partial schemas, complete schemas and missing migration history. It validates existing definitions and owner credentials, applies only missing work, and preserves existing records, settings, scenes, owner password hashes, installation identity and recoverable security keys. Historical migration bytes are unchanged.

A failed attempt keeps completed schema work and its pending configuration for retry. Pending configuration is not publicly installed until final verification succeeds, including a process interruption after the lock was written. Automatic recovery and installer retirement share the setup guard and cannot replace a pending checkpoint or move the installer while it is running. Existing completed installations remain protected; incompatible schema or missing original encryption keys produce specific preservation errors, never an instruction to erase the database.

The owner's additional layout correction removes the extra Folders rule/inset, aligns Server/Folders headings and check cards, and aligns Password/Confirm Password fields despite the strength meter. Completion clears the spinner and page scroll lock. Installer CSS/JavaScript have their own updated cache query. The runtime repair contains **eight files: six PHP files, one stylesheet and one JavaScript file**, including the earlier folder correction. The fresh and upgrade packages are rebuilt; the earlier one-file Folder Repair ZIP does not contain this database/layout continuation.

Local and GitHub verification are complete: the full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite passed; the actual packaged installer passed 922 assertions on MySQL 8.0.46; recovery passed 134 assertions; and real desktop/mobile browser installation, retry, layout and completion tests passed. The new SQL parser cases and original failing layout are retained or reproduced as meaningful regressions. Executable source, tests and workflow are uploaded as [`e5b04daa132521be69c943151f8112c7cd2017e8`](https://github.com/afterburn25/velvet_vixxen/commit/e5b04daa132521be69c943151f8112c7cd2017e8), tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`. All seven required jobs passed in [GitHub run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907), whose official conclusion is **completed / success**. This includes PHP 8.2.34/8.3.35 source suites, 922 actual-package MySQL installer assertions on each version, real installer desktop/mobile acceptance, seven HTTP suites, twelve application-browser suites and gated packaging. The downloaded GitHub ZIPs and manifest are byte-identical to the saved customer downloads. Matching repair, fresh-install, upgrade and separate checksum-manifest downloads were saved on 2026-10-19. See `docs/INSTALLER_DATABASE_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json` (repository-relative paths). Earlier folder/cache evidence remains historical in the test report and its release record. No 0.9.0 work or production deployment is included.

<!-- installer-database-evidence:start -->
## Current database/layout repair — verified source, GitHub and delivery evidence

| Gate | Observed result |
| --- | --- |
| Full local source aggregate | **PASS**, PHP 8.3.6; PHP/JavaScript/Python syntax, installer/unit/feature/security/structure, actual packaged ownership and OPcache, runtime-only package regression. |
| Actual packaged installer on MySQL 8 | **PASS, 922 assertions**, MySQL 8.0.46/PHP 8.3.6. Empty, unrelated, partial, complete and ledgerless schemas; owner/hash/key/configuration/data preservation; interrupted default/DDL/finalization work; real filesystem and database locks; retry; specific read-only incompatibility errors. |
| Retained schema reconciliation | **PASS** inside the 922 assertions. Partial caption column/index/FK completion, idempotent rows/ledger, legacy orders ENUM expansion preserving all seven old values, same-name index/FK conflicts rejected through both inspection and actual resume. Historical migration source/checksums are unchanged. |
| Recovery/public activation | **PASS, 134 assertions**. Pending false/true states with missing/matching locks, preserved older backups, guard rechecks, owner/identity safeguards, shared lock exclusion and activation only after marker removal. Prior-runtime negative control fails at the missing shared apply-lock behavior. |
| Real installer HTTP and browser | **PASS**, desktop 1440×1000 and mobile 390×844; Playwright 1.62.0 with Chrome for Testing 155.0.8059.84. Real ZIP/PHP/MySQL/AJAX, partial/full schema, CSRF/11-character refusal, exact 12-character owner login, wrong-owner refusal, injected post-final-config failure, ordinary HTTP-bootstrap retry, preserved records/keys/uploads, cleanup and final owner login. |
| Requested layout | **PASS** before and after actual Run Checks and after password entry. Desktop first Server/Folders cards are both 416×84 with matching Y; password boxes are both 410.5×49 with matching Y. Mobile lists share X=43/width=304; password inputs share X=42/width=306/height=49. Folder wrapper padding and borders are zero. |
| Original layout negative control | **FAIL as expected** with the prior committed CSS in an isolated runtime: 12px folder inset, 1px extra top border, 13px lower folder card, mismatched nested columns and Confirm Password 18.234px lower. The new browser gate detects it. |
| Successful completion UI | **PASS**, completion stops the spinner, removes page scroll lock and leaves a disabled “Installation complete” button with a working Owner Login link. |
| Final runtime packages and cache | **PASS**, reproducible 314-file fresh, 273-file upgrade and eight-file repair; identical applicable runtime bytes/timestamps, safe modes, state preservation and exclusions. The real OPcache test executes three stale negative-control requests followed by two corrected requests from the exact repair ZIP in the same process. |
| Current GitHub CI / customer delivery | **PASS**, [run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907): all seven jobs and the run are officially completed/success. PHP 8.2.34 and 8.3.35 each passed 922 real MySQL installer assertions. The exact committed template passed the real installer browser gate. All three saved customer ZIPs and the separate manifest are byte-identical to the downloaded gated artifact. |

The final local source/package run uses the exact frozen runtime bytes. The later GitHub browser run verifies the exact committed template, including the neutral `owner@example.com` placeholder. GitHub used PHP 8.3.35, MySQL 8.0.46, Playwright 1.62.0 and its mandatory Chromium/Headless Shell 151.0.7922.34. Local Chrome for Testing 155 evidence is supplementary. Eight installer expanded/form/completion PNGs, two geometry JSON files and the fixture HTTP log are engineering evidence and never customer package entries.

### Exact-source GitHub execution

Implementation/package commit: [`e5b04daa132521be69c943151f8112c7cd2017e8`](https://github.com/afterburn25/velvet_vixxen/commit/e5b04daa132521be69c943151f8112c7cd2017e8); tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`. [Direct-push run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907) is officially **completed / success**, updated 2026-10-19T17:40:05Z. Every job's actual checkout and complete successful execution log were verified. This run proves the current implementation without relying on an unobserved conclusion from the separate PR-triggered run. Later documentation-only commits do not change the tested source, workflow or customer ZIP bytes.

| Job | Required gate | Observed execution |
| --- | --- | --- |
| [118202824731](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118202824731) | Repository and executable source contract | **PASS**; required source files and locked requirements. |
| [118203955909](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118203955909) | Installed HTTP and Chromium desktop/mobile acceptance | **PASS**; PHP 8.3.35, MySQL 8.0.46, Playwright 1.62.0, Chromium 151.0.7922.34. |
| [118203955975](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118203955975) | MySQL 8 / PHP 8.3 installer resume, upgrades and transactions | **PASS**; PHP 8.3.35, MySQL 8.0.46; 922 installer assertions. |
| [118203955998](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118203955998) | PHP 8.2 source and package regressions | **PASS**; PHP 8.2.34; 134 recovery assertions. |
| [118203956072](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118203956072) | PHP 8.3 source and package regressions | **PASS**; PHP 8.3.35; 134 recovery assertions. |
| [118203956110](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118203956110) | MySQL 8 / PHP 8.2 installer resume, upgrades and transactions | **PASS**; PHP 8.2.34, MySQL 8.0.46; 922 installer assertions. |
| [118208479003](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/job/118208479003) | Build verified fresh-install and upgrade ZIPs | **PASS**; Python 3.12.15. |

Both MySQL jobs also passed 14-migration / 82-table apply, repeat/verify and rollback; 32 installed-upgrade preservation checks; merchandise transactions; and 12 booking database checks. The browser job passed the new installer desktop/mobile flow plus all seven existing HTTP and twelve application-browser suites, including 30 standalone Hero scenes and 90 published device renders.

[Runtime artifact 12207601981](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/artifacts/12207601981) was downloaded and independently compared with the saved files: all three ZIPs and the manifest are literally byte-identical. The enclosing artifact is 15,045,233 bytes, SHA-256 `6bd025cef2d15482214a9af9dd8384914aeb0998b21112c916fd275146450c35`. [Browser evidence artifact 12207033000](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907/artifacts/12207033000) is separate from customer runtime ZIPs. The saved standalone package manifest is 68,751 bytes, SHA-256 `0f5b4695e2e3ebdac2079a716d78efac1b98a2b2c5146b9b2e75a10f95b955a2`.

### Delivered package hashes

| Archive | Bytes / files | SHA-256 |
| --- | --- | --- |
| Installer Database Repair | 51,241 / 8 (6 PHP, CSS, JavaScript) | `9cf165faf4f324e4682875bcfde50300a42382467bca700dac0d4e4fd835fc38` |
| Fresh Install | 7,587,279 / 314 | `0914e0497355d67bfd861b64fa25233ab0c643ad1443dea88ae852f3b6d874ca` |
| Upgrade from 0.8.x | 7,431,620 / 273 | `e7f6c52d4d7de217b647e41f44827381cfe4a86c58b794947a5a0fd8bfa57028` |

The repair includes the earlier filesystem fix and seven additional PHP/CSS/JavaScript dependencies. Its changed components use ZIP timestamp 2026-10-19 16:00:00; the unchanged filesystem fix retains 2026-10-19 00:00:00. Browser assets use `installer=20261019.1`. The ordinary upgrade legitimately changes because Core/recovery/public assets changed. See `INSTALLER_DATABASE_REPAIR.md` for the existing-site-folder instructions.
<!-- installer-database-evidence:end -->

## Historical folder and PHP cache correction

The sections below preserve the earlier folder/cache correction, its package hashes and its observed CI metadata. They predate the existing-database and layout changes above. Current release evidence belongs in the new database-repair section and JSON record.

Updated: 2026-10-19. Branch: `feature/0.8.1-site-ux-hero-library`. Tracking: #13, #1, #3; PR #15.

## Final GitHub execution and delivered package evidence

Final implementation/package commit: [`eed4c5efe1f3be9aa2522f247adcd2a5e84c509a`](https://github.com/afterburn25/velvet_vixxen/commit/eed4c5efe1f3be9aa2522f247adcd2a5e84c509a); source tree `a0be19e15f58d4576d78e1b5a820317f5242acab`. Push run [39283438479](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283438479) checks out that commit directly. PR run [39283445755](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283445755) records that head and checks out GitHub's test merge `430dcbd7e3861432f21cddcf8df8d63ebefd081e` into base `0a4940ec816b0a21459b4ae6e8c2bf4818eba060`. This generated test merge is not a merged PR or a production deployment. The package summaries from both runs are identical and match the saved customer downloads.

All twelve archived job logs show completed execution and normal cleanup. The final PR logs were independently read for each of the six gates. The following results describe observed suite execution; they are not substituted values for the delayed GitHub conclusion field.

| Gate | PR job | Push job | Observed result |
| --- | --- | --- | --- |
| Repository/source contract | 118162699164 | 118162673422 | Required executable, handoff and locked-requirement assertions completed successfully. |
| PHP 8.2.34 | 118162988214 | 118162910183 | Full source/JS/Python, installer, recovery, feature/security and package suites passed; real foreign-owner and persistent HTTP/OPcache regressions both passed. |
| PHP 8.3.35 | 118162988552 | 118162910303 | Same complete suites and both mandatory installer regressions passed. |
| MySQL 8.0.46 | 118162988283 | 118162910441 | 32 installed-upgrade checks, 12 booking transaction checks, merchandise scenarios, all 14 migrations/82 tables, verification and rollback passed. |
| HTTP/Chromium | 118162988130 | 118162910110 | All 7 HTTP and 12 browser suites passed; Playwright 1.62.0 / Chromium 151.0.7922.34. Each run uploaded 64 evidence files. |
| Runtime packages | 118166134986 | 118165895958 | Three runtime ZIPs and a separate manifest built and uploaded; all inner package hashes match local delivered builds. |

Browser evidence includes direct drag, extreme resize, all-edge movement/crop and lower-edge hiding, live text/buttons, per-device backgrounds, real uploads, history and published rollback; all 30 standalone Hero images and thumbnails decoded, with 90 published desktop/tablet/mobile renders. Booking field editing/ordering/toggles, linked choices, phone formatting, inline/server validation and immutable submissions passed. About Me editing, sticky navigation, full account layouts, persistent admin state, merchandise shipping/checkout and direct PPV isolation passed, including the final 12-route public layout audit.

| Run | Runtime package artifact | Browser evidence artifact |
| --- | --- | --- |
| PR 39283445755 | [12202911516](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283445755/artifacts/12202911516), 14,981,993 bytes | [12201869438](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283445755/artifacts/12201869438), 48,441,282 bytes |
| Push 39283438479 | [12202672412](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283438479/artifacts/12202672412), 14,981,993 bytes | [12202711569](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283438479/artifacts/12202711569), 48,414,812 bytes |

The PR runtime artifact's outer ZIP digest is `8b81794f242d9d0384fc7a9d25fa2ea888c75594bdeb6b5a6ed1e2531a8a61ea`; its browser evidence digest is `0383f1ae958c2eb647519200bf511a1df1b99bb9fc4a20d853871d0b05d2c671`. These outer artifact hashes are distinct from the individual customer ZIP hashes below. Browser evidence, repository documents/tests/SDK/workflows and the separate manifest remain outside the customer runtime ZIPs.

| Saved customer package | Bytes / runtime files | SHA-256 |
| --- | --- | --- |
| Installer folder repair | 6,400 / exactly 1 PHP file | `5ff968c13fe43d6ab7c759c3c0dabcf28bda5018c2378f5b496664f692171ce3` |
| Fresh install | 7,576,393 / 313 | `3647aaff64eb33eb02b27a33f02e2cb812b08264b1b206d6f30fba6c09ec7762` |
| Existing upgrade, unchanged | 7,423,931 / 272 | `57b2497758ba9ad24e89a597411f4e16017bfdc74caf141b3e5ecadc4db61715` |

The timestamp-aware repair, full fresh archive and separate package manifest were successfully saved as replacements for their earlier download versions. The user-facing fresh download name is `Nikki-Wilder-Velvet-Vixxen-0.8.1-Site-UX-Fresh-Install.zip`; it is byte-identical to the CI file named `Nikki-Wilder-Velvet-Vixxen-0.8.1-Fresh-Install.zip`. The ordinary upgrade remains unchanged. No new upload of an unchanged upgrade is required.

At **2026-10-19 16:21:51 UTC**, both official run records and their job metadata still reported **queued / null conclusion**, even though all archived executions had finished and final artifacts were uploaded by 16:18:43 UTC. This report preserves that discrepancy without claiming an observed official successful workflow conclusion or diagnosing an unverified service outage. A subsequent documentation-only handoff record changes no executable source, tests, workflows or customer archive bytes; the exact implementation and package evidence above remain authoritative. Merge, deployment and live-provider certification are separate and are not claimed.

## PHP cache delivery correction — observed evidence

- A persistent PHP 8.3.6 HTTP process with real OPcache, timestamp validation enabled and revalidation frequency zero cached the exact previous service (`dce5c92a…`). Actual `unzip` replacement with corrected bytes (`bf9a1eb4…`) but the same January 1 timestamp left the old manifest running for three requests despite different source size/hash. Extracting identical corrected bytes dated October 19 loaded the corrected manifest on the next request, without PHP restart, cache reset or explicit invalidation.
- `python tests/Installer/packaged_opcache.py`: **PASS**. Mandatory CI uses a small explicit legacy cache fixture, the actual package builder/repair ZIP, real persistent PHP HTTP/OPcache, timestamp-preserving `unzip`, three negative-control requests and two corrected requests. OPcache absence, ineffective caching or inability to bind the isolated server fails; there is no passing skip. Helpers and synthetic files never enter customer ZIPs.
- `bash scripts/test.sh`: **PASS**, integrated final local suite, PHP 8.3.6. Log `/tmp/vvx-installer-final-source.log`; includes actual ownership and OPcache regressions, source/JS/Python syntax, recovery/security/features and all three package tests. A preceding execution transport disconnect prevented process creation and is not relabeled a test failure or pass; the subsequent complete invocation exited 0.
- `python3 tests/Installer/packaged_preflight.py --legacy-filesystem /tmp/vvx-installer-filesystem-before.php`: **PASS**. Its isolated historical build restores both original service bytes and the January 1 ZIP timestamp, reproducing original archive `4f2e22ce4eb153a89b42180e15ee55ad7259f39712864e3f79756a47d0e27080` and the exact nine errors on initial probe plus two rechecks.
- Final local fresh ZIP: `3647aaff64eb33eb02b27a33f02e2cb812b08264b1b206d6f30fba6c09ec7762`, 7,576,393 bytes. Final repair ZIP: `5ff968c13fe43d6ab7c759c3c0dabcf28bda5018c2378f5b496664f692171ce3`, 6,400 bytes. Service bytes are identical to runtime commit `7764509`; only its ZIP timestamp advances in these archives. Standard upgrade remains byte-identical at `57b2497758ba9ad24e89a597411f4e16017bfdc74caf141b3e5ecadc4db61715`.

## First folder correction — archived GitHub execution before the PHP cache follow-up

Root independently read the archived logs for all six jobs in push run [39278828940](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278828940), exact source `776450964e5a1e9d26719d886d114ce3eef4037e`:

| Job | ID | Observed execution |
| --- | --- | --- |
| Repository/source contract | 118149252491 | Required files/assertions and normal cleanup completed. |
| PHP 8.2.34 | 118150391197 | Real foreign-owner packaged preflight and complete source/package suite passed. |
| PHP 8.3.35 | 118150391177 | Same mandatory ownership and complete source/package suite passed. |
| MySQL 8.0.46 | 118150391170 | 32 installed-upgrade checks, 12 booking checks, merchandise scenarios, 14 migrations/82 tables and rollback passed. |
| HTTP/Chromium | 118150391164 | All 7 HTTP and 12 browser suites passed; Playwright 1.62.0/Chromium 151.0.7922.34, 90 Hero renders and 12-route layout audit. |
| Runtime packaging | 118155630465 | Three runtime ZIPs and separate manifest built/uploaded; all inner hashes matched local builds. |

Runtime artifact [12199844510](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278828940/artifacts/12199844510); 64-file browser evidence [12200580675](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278828940/artifacts/12200580675). PR run [39278836707](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278836707) independently produced the same successful suite logs and runtime hashes, runtime artifact [12202170150](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278836707/artifacts/12202170150) and browser artifact [12200807453](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278836707/artifacts/12200807453).

At 16:00:36 UTC the run/job status records lagged their complete archived logs/artifacts; most still reported queued or in progress. Execution success above is established from the actual logs, not inferred from those badges. The new OPcache regression and timestamp-aware package hashes were not part of those runs; their fresh/repair ZIPs are superseded.

## First folder correction — earlier local evidence


- `bash scripts/test.sh`: **PASS**, PHP 8.3.6; complete source/JS/Python syntax, installer/recovery/feature/security and all three runtime-package regressions. Run log: `/tmp/vvx-installer-folder-source.log` in the disposable execution environment.
- `python3 tests/Installer/packaged_preflight.py`: **PASS**, real packaged source with static trees owned by UID 0 and PHP probes running as UID 65534, using 0755 directories/0644 packaged files. Initial probe plus two rechecks preserve static hashes/modes/owners. All five genuine writable-root failures remain blocking. All five permitted roots can be ownership-repaired while preserving sentinels. Missing `database/migrations` causes repair failure and restores the original database inode, bytes, modes and owner; missing real Hero/CSS/Canva directories remain blocking without empty substitutes.
- Captured prior `FileSystemService.php` SHA-256 `dce5c92af42aedadb774d6ec2f216e4acb75c1b6976c8352a8ca45aa20750d2c` with `--legacy-filesystem` rebuilds the earlier fresh ZIP SHA-256 `4f2e22ce4eb153a89b42180e15ee55ad7259f39712864e3f79756a47d0e27080` byte-for-byte. It reproduces **exactly all nine customer failures**, including message and `----` mode, for all three requests.
- Corrected fresh ZIP SHA-256: `8b60a3142406471c0ab03ed68a298a806832132f46e9ad01b179b01ccaa1e46f`; 313 runtime files. Standard upgrade payload is unchanged because it excludes the installer.
- `python3 tests/Packaging/runtime_packages.py`: **PASS**, reproducible fresh/upgrade/one-file repair ZIPs, exact repair scope and fresh-service byte identity, safe regular-file modes, private/development exclusions, and all other stopped-install/installed-state bytes preserved by overlays.
- Environment limitation recorded honestly: the default local namespace maps only UID 0, so its first attempted ownership fixture failed. Authorized execution outside that namespace supplied real separate ownership. The test has no ineffective same-owner fallback or passing skip; Ubuntu CI uses passwordless sudo only to orchestrate its disposable fixture and drops privileges for every PHP probe.

The direct failure reproduction closes the test gap in the earlier green checkpoints below. It does not claim access to or deployment on the owner's actual host. See `INSTALLER_FOLDER_REPAIR.md` for the small repair overlay and new-install instructions.

## Earlier feature verification truth boundary

**The 0.8.1 user-facing features and required engineering release gates are implemented and verified.** This report records observed execution, not inferred success from suite filenames or workflow configuration. Historical recovery evidence and feature evidence remain distinct.

Recovered source commit: [`43945af07c145c67db2f2883a4fa7f607215dc23`](https://github.com/afterburn25/velvet_vixxen/commit/43945af07c145c67db2f2883a4fa7f607215dc23). Input archive SHA-256: `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`. See `SOURCE_RECOVERY.md`.

## Earlier complete GitHub verification — before the installer correction

Exact runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c). Source tree: `f9ad6dd825def2b525ce0992c1fb9a010344c8b9`. Asset build identity: `0.8.1-20261019.3`. GitHub run [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477) completed successfully; all six required jobs passed.

| Job | Exact GitHub job | Result |
| --- | --- | --- |
| Repository and executable source contract | [117930817942](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930817942) | PASS |
| Installed HTTP and Chromium desktop/mobile acceptance | [117930918482](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918482) | PASS |
| MySQL 8 installed upgrades, merchandise and booking transactions | [117930918487](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918487) | PASS |
| PHP 8.2 source and package regressions | [117930918510](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918510) | PASS |
| PHP 8.3 source and package regressions | [117930918540](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918540) | PASS |
| Build verified fresh-install and upgrade ZIPs | [117932968594](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117932968594) | PASS |

The source matrix ran PHP 8.2.34 and 8.3.35. MySQL 8.0.46 passed all 14 migrations / 82 tables, idempotent reapply, 32 installed-upgrade checks, 12 booking snapshot/rollback checks, merchandise transaction scenarios and complete rollback. All seven installed HTTP suites and all twelve real Chromium browser suites passed in one stable workflow, including the corrected immediate-refresh Admin behavior. The 30 full scene images and 30 thumbnails decoded, and all 90 published desktop/tablet/mobile renders succeeded.

### Verified artifacts and package hashes

- [velvet-vixxen-runtime-packages](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/artifacts/12168746591), artifact `12168746591`, 14,974,841 bytes, SHA-256 `947bce5ffa3a1369debb6c7885253c5edf694ff2bc5998f29d4e1409b54a0251`.
- [chromium-browser-evidence](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/artifacts/12168269245), artifact `12168269245`, 48,414,908 bytes, SHA-256 `47b8e5cd4f0f82a7b49e8ccd815a80b45b1df1729e312493303fc644a9e9749c`.

Both customer ZIPs were rebuilt locally from the exact clean committed runtime; their SHA-256 values match the gated GitHub package build. Counts refer to the customer ZIP entries, not engineering source files.

| Package | Bytes | Files | PHP | JavaScript | SHA-256 |
| --- | ---: | ---: | ---: | ---: | --- |
| fresh | 7,576,019 | 313 | 166 | 10 | `4f2e22ce4eb153a89b42180e15ee55ad7259f39712864e3f79756a47d0e27080` |
| upgrade | 7,423,931 | 272 | 155 | 10 | `57b2497758ba9ad24e89a597411f4e16017bfdc74caf141b3e5ecadc4db61715` |

Fresh installation starts uninstalled. Upgrade excludes config/storage/uploads/install and preserves existing private state. Engineering documents, tests, SDK source, workflows, screenshots and artwork provenance/masters stay outside customer ZIPs. Structured evidence is in `releases/0.8.1-site-ux.json`; complete per-entry checksums accompany the separately delivered package manifest.

## Actual baseline GitHub execution

Run [39207173912](https://github.com/afterburn25/velvet_vixxen/actions/runs/39207173912) on the recovery commit:

| Job | Observed result |
| --- | --- |
| Repository/source contract | PASS |
| PHP 8.2 source and package | PASS |
| PHP 8.3 source and package | PASS |
| MySQL 8 apply/reapply/verify/rollback | PASS: baseline 12 migrations / 78 tables |
| Installed HTTP | PASS step |
| Browser regression | FAILED: old member test tried Sign In before dismissing age gate; fixed in the feature tree |
| Final package artifact job | SKIPPED because browser dependency failed |

This is not an all-green run and does not verify the subsequent feature migrations or browser additions.

## Feature checkpoint CI and corrections

Implementation commit [`d81513da5369d000a484331ea6a1000f80bc81a7`](https://github.com/afterburn25/velvet_vixxen/commit/d81513da5369d000a484331ea6a1000f80bc81a7), run [39210406490](https://github.com/afterburn25/velvet_vixxen/actions/runs/39210406490): repository contract, PHP 8.2, PHP 8.3 and all 7 installed HTTP suites passed. MySQL 8.0.46 passed all 32 installed-upgrade checks and merchandise transactions, then failed because the booking rollback fixture used a trigger requiring SUPER under binary logging. The fixture now temporarily renames the disposable messages table, requires the exact missing-table SQL error, restores it and checks all six row counts. No global database privilege or production behavior was changed.

The same CI browser job passed its first seven suites, then exposed Composer handle replacement during a canvas redraw. The correction keeps selected handle nodes stable while updating geometry, preserving keyboard focus as well as the resize interaction. The corrected Composer recovery/focus and expanded controls suites pass locally. The new focus assertion fails against the original d81513 renderer at the expected image-load focus loss, demonstrating a meaningful regression. The subsequent final workflow verifies the corrected exact browser engine and full suite; this failed intermediate run is retained as evidence rather than hidden.

Visual screenshot review also corrected About page side spacing and hides an empty details column when no photo or statistics have been supplied. The updated desktop/tablet/mobile About/account/admin layout suite passes. That correction used asset build identity `0.8.1-20261019.2`; core VERSION remains 0.8.1.

## Intermediate correction run and Admin race

Run [39211611678](https://github.com/afterburn25/velvet_vixxen/actions/runs/39211611678) tested commit [`1bb7f1903ac01252f22a5a564f18764998781678`](https://github.com/afterburn25/velvet_vixxen/commit/1bb7f1903ac01252f22a5a564f18764998781678):

| Exact job | Observed result |
| --- | --- |
| Repository/source contract, 117926053383 | PASS |
| PHP 8.2.34, 117926290960 | PASS: complete source and package regressions |
| PHP 8.3.35, 117926290989 | PASS: complete source and package regressions |
| MySQL 8.0.46, 117926291147 | PASS: 14 migrations / 82 tables, 32 installed-upgrade checks, 12 booking checks, merchandise transactions, reapply and complete rollback |
| HTTP/Chromium, 117926291045 | All 7 HTTP suites and first 10 browser suites PASS, including every Composer suite and 90 library renders; Site UX failed at immediate-refresh category persistence |
| Runtime package, 117927450368 | SKIPPED because the browser dependency failed |

The Composer node/focus correction is now verified on the actual CI Chromium 151 engine. The trigger-free booking rollback fixture passes real MySQL 8 without added database privileges. Browser evidence artifact [12168116914](https://github.com/afterburn25/velvet_vixxen/actions/runs/39211611678/artifacts/12168116914) contains the actual running-build captures; no gated runtime package was produced by this failed run.

That intermediate failure had a specific cause: native `details` toggle events are queued, so a refresh immediately after category activation can occur before the preference is saved. The correction observes native open-state changes at the microtask checkpoint, keeps explicit collapse preferences for the active category, and flushes pending changes on page exit. Native mouse and keyboard activation remain intact; initialization defaults are not recorded as explicit owner choices. The regression checks immediate saved state and reload, keyboard activation/focus, owner/site scope and malformed stored preferences without a timed delay. It demonstrably fails against the captured pre-fix Admin script at the missing preference before the next browser task. Current cache identity is `0.8.1-20261019.3`. The complete updated local Site UX suite passed, and the final all-green workflow above verifies the same correction on the actual CI engine.

## Observed feature implementation evidence

| Area | Actual local execution |
| --- | --- |
| PHP 8.3 source and syntax | PASS, clean exit 0 on the stable final implementation: PHP/JS/Python syntax and restored feature/security/installer/package suites. Log: final-run/source.log in execution evidence. |
| Installer recovery | PASS: 72 focused assertions for matching identity, active configuration/key preservation, ambiguous backups, linked paths and real installer execution. |
| Repair backups | PASS: 71 assertions covering verified duplicates, unique/unknown/unreadable data preservation, path confinement and safe deletion/archive handling. |
| Runtime package tests | PASS: reproducibility, runtime-only exclusions, fresh uninstalled state, upgrade overlay preserving config/lock/private data/uploads byte-for-byte and symlink rejection. |
| Composer controls | PASS in real Chromium: direct movement, 0.5–400% resizing, coordinates −400% to 500%, reachable handles, aspect ratio, all-edge clipping, lower 20% crop, live text/button geometry, background pan/appearance, device isolation, snapping/keyboard/numeric controls, undo/redo, hide/lock/order/duplicate/delete, draft preview and history rollback. |
| Composer security/image paths | PASS: protected original exclusion, safe embedded scene titles, leading-zero version IDs, negative-z photo visibility, bundled/uploaded asset decoding. |
| Hero Library | PASS: 30 distinct full images + 30 thumbnails decode; search/categories/placement and draft selection; all 30 scenes published and rendered at desktop/tablet/mobile, **90/90 public renders**. |
| Booking Builder | PASS: 54 source assertions; actual owner CRUD/field validation/choice/phone browser acceptance; HTTP CSRF/nonowner/malformed email/phone/choices/rate limit/upload privacy/history; 12 SQL transaction checks preserve snapshots and roll back failed writes. |
| About Me and admin/public UX | PASS: 21 source assertions; real owner edits, escaping and CSRF, hidden empty stats, persistent categories/active page, mobile menu, centered login/register/recovery/Join, sticky navigation at 1440/834/390 widths. Separate public layout audit passes 12 routes at desktop/mobile. |
| Merchandise | PASS: 45 source assertions; desktop/mobile owner image/variant editing, live pricing, cart quantities, address/shipping validation, sandbox checkout, fulfillment/tracking, stock reservation/restoration, stale-editor rejection, PPV/cart isolation, pending-charge reconciliation and refund/chargeback revocation. |
| Installed Database Updates | PASS: 13 source checks, owner/CSRF/private-path HTTP coverage, 32 MariaDB integration checks including actual 12→14 upgrade, existing booking/configuration/lock/key/upload preservation, no automatic down migration on injected DDL failure, reviewed retry, stale plan, locks, symlinks/permissions and real CLI. |
| MariaDB 10.11.14 full schema | PASS: 14 migrations / 82 declared tables; apply, idempotent reapply, merchandise/booking/updater scenarios, rollback to empty disposable schema. This is not MySQL 8 evidence. |
| Installed HTTP regression | PASS, all seven suites in one stable aggregate (exit 0): members, commerce, add-ons, current platform, booking builder, merchandise and database updates. Log: final-run/http.log. The final feature GitHub CI above also passed. |
| Browser regression | PASS, all 12 real Chromium suites in one stable aggregate (exit 0), including the full 30-scene library/90 public renders, Composer controls, booking/merchandise and desktop/tablet/mobile layouts. Log: final-run/browser.log. Subsequent Composer focus and Admin timing corrections have meaningful regressions and pass the complete final CI run above. |

The long first library run printed full success, then its Bash wrapper exited after a concurrent harness edit. The wrapper now execs its disposable snapshot and deduplicates its suite list. The subsequent stable aggregate finished cleanly; that earlier outer exit is retained as a failed attempt.

The final independent asset/installer audit checked 55 public/admin pages, found and corrected one caption thumbnail issue, then rechecked 7 pages/29 unique local assets with no broken images or resource errors. All loaded CSS/JS have exactly one current asset build version. Repository safety, expanded credential/private-key signatures, 82 directory permissions, 47 literal asset references and 139 default-data asset references passed.

## Commands and isolation

```bash
bash scripts/test.sh
bash scripts/test_http.sh
VELVET_BROWSER_TESTS=1 bash scripts/test_http.sh
php tests/Database/migrations_mysql.php
python3 scripts/package.py --output artifacts/runtime
```

For a focused browser scenario, set `VELVET_TEST_SUITE=tests/Browser/site_ux_playwright.py`. Set `VELVET_SCREENSHOT_DIR` to collect actual execution screenshots outside customer packages. Full configuration is in `TESTING.md`.

Local tooling: PHP 8.3.6 with PDO MySQL, MariaDB 10.11.14, Python Playwright and Chrome for Testing 155.0.8059.84. Source/HTTP tests create marked disposable runtime copies; SQL tests require a disposable database. No demo is installed in the authoritative checkout. Fixture credentials and private runtime state are not release contents.

## Earlier continuation completion boundary

The required combined gates, final committed-source package hashes, artifacts and repository evidence are complete. Intermediate failures remain above so the audit trail distinguishes what was fixed from what originally passed. Source, tests and these progress records are uploaded on the implementation branch; inspect PR #15 for its separate merge state.

Payments remain sandbox-only. Owner photographs/catalog content, production mail delivery and representative shared-hosting certification are not claimed. Filesystem ownership/write regressions are reproducible local/CI fixtures, not a test of the owner's actual hosting account.
