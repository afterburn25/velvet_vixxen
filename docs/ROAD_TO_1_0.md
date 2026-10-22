# Build through 1.0 — active integration

The owner requested “build through 1.0.” This authorizes the remaining engineering, integration and release work; it does not supply a live merchant account, production service credentials or access to the customer host. Work is tracked in [issue #34](https://github.com/afterburn25/velvet_vixxen/issues/34).

The last verified, merged and delivered release is **0.8.14 / Build 20261021.3**. The integration branch currently displays **0.9.0 / Build 20261021.4**. This is an engineering candidate, not a completed release or production certification. Final packages, full CI, merge and saved-download read-back are still required.

The owner confirmed that **Photo depth is present after updating the version**. That missing-control question is resolved. Preserve the existing Composer depth/foreground behavior and continue the remaining work.

## Integrated implementation

| Area | Implementation on the integration branch | Acceptance boundary |
| --- | --- | --- |
| Administrator security | Authenticator enrollment, single-use recovery codes, durable revocable sessions, current-owner checks, recent privileged authentication, persistent login limits and safe audit activity | Source and isolated file/MySQL HTTP/browser checks have passed; final combined release CI remains pending. |
| Configuration and private journals | Atomic, revision-checked configuration updates; private file/SQL journals with interruption and concurrency handling | Actual file, MySQL 8.0 and MariaDB 10.11 checks; no customer configuration or runtime state is committed. |
| Deleted editorial content | Bounded private recovery of exact records and history; deliberately restored content stays draft/disabled | Source and actual-package file/MySQL HTTP/browser checks have passed. |
| Campaign content | Rendered previews, owner test delivery, branded content, stable message identities and frozen queue snapshots | Local SMTP capture only; no production recipient was contacted. |
| Attribution and analytics | Durable registration/checkout referral capture; campaign links and conversions; business audiences; consent, opt-out, retention, comparisons, exports and funnels | Real file/MySQL/MariaDB storage acceptance; no claim about production traffic or unbounded-scale performance. |
| Notification workflows | Consent-aware new-content, renewal, payment-failure and abandoned-registration notices with durable claims and held ambiguous sends | File/MySQL local-capture HTTP/browser acceptance. Production delivery remains separate. |
| Mail-provider events | Official SendGrid signature validation, bounded original-body processing, replay-safe journal, exact message/recipient correlation, bounce/complaint/unsubscribe suppression and interrupted-effect recovery | Independent official signature vector, ephemeral signing keys and isolated file/MySQL HTTP/browser acceptance. A configured live provider has not been certified. |
| Encrypted operations | Scheduled encrypted archives, bounded retention, diagnostics, recovery gate, isolated restore and explicit activation | File, MySQL 8.0 and MariaDB 10.11 complete restore drills; distinct owned empty SQL targets. The customer host and external key remain separately configured. |
| Protected media and records | Encrypted performer/release references; linked media and publication holds; public reports, owner responses and appeals; verified server upload denial and controlled delivery | Actual Apache file/MySQL HTTP/browser checks, including root/nested paths, direct-upload denial and deliberate media release. No production installation is claimed. |
| Age, location and public policies | Opt-in Yoti document age assurance, installation/session-bound signed grants, trusted immediate proxy CIDRs, fail-closed country rules and five independently published policy snapshots | Current source: 262 checks per file/MySQL/MariaDB engine; actual Apache root/nested: 172 each; desktop/mobile: 50 browser checks each. No live provider requests. |
| Member privacy | Complete member-owned export projection and attachments, private seven-day downloads, password-confirmed requests, owner retention review and ten durable closure stages | Current service source: file 54 and MySQL/MariaDB 55 each; complete-inventory/core storage: 19/20/20. Actual Apache root/nested checks: file 337/335, MySQL 341/340 and MariaDB 333/333. Every desktop/mobile case passed 45 browser checks. Ten real killed-worker recoveries per HTTP case, stale approval/writer holds, restricted own-download access and financial preservation passed. The evidence record distinguishes retained reports from session-only source observations. |
| Accessibility and compatibility | Keyboard focus and dialog/menu controls, field labels, focusable tables, visible link distinctions and public trailer captions; explicit MySQL/MariaDB date/schema handling | Combined 55-page file/MySQL desktop/mobile automated and keyboard checks passed locally, including Operations, Protection, privacy, age access and five published policies. All four cases recorded no automated violations or JavaScript errors. Screenshot review subsequently found dim secondary owner-privacy text; that correction receives a focused visual/contrast check and the final combined CI. Bounded loopback response budgets are retained. Automated checks do not establish full WCAG conformance or production performance. |

## Work still in progress

Complete the bounded owner-privacy contrast correction, then freeze the source and run the combined release CI. Member-privacy Apache/browser, interruption/concurrency and restricted-access acceptance has passed on file storage, actual MySQL 8.0.46 and MariaDB 10.11.14. The 55-page file/MySQL accessibility matrix has also passed locally. These component runs preserve their individual source and package hashes; they do not replace the final frozen release run. Preserve every existing installer, ownership, OPcache, routing, Composer, media, member, commerce and marketing gate. Keep age/location controls and other deliberate activation features disabled until their configuration requirements are satisfied.

The current workflow expands to **26 jobs and 268 configured steps**, including four file/SQL privacy/access executions across actual MySQL 8.0 and MariaDB 10.11. The two age executions skip the member-only core-inventory step by its explicit feature condition. The original **82 expanded baseline step instances** (61 distinct complete YAML step objects) remain identical and in their original job order; their source-contract command is retained verbatim, with new file requirements in a separate step. The independent audit at local source `74236eb` verified all original runners, matrices, services and environments, all required paths, exact 109-new/180-changed runtime floors, unchanged package roots and byte-identical installer repair. The completed final GitHub run remains necessary.

The first checkpoint run ([39670710598](https://github.com/afterburn25/velvet_vixxen/actions/runs/39670710598)) and the later saved-source run ([39674156110](https://github.com/afterburn25/velvet_vixxen/actions/runs/39674156110)) failed. The latter exposed older cached authentication, two missing disposable database-fixture dependencies, an Apache JIT diagnostic, an engine-label mismatch and a legitimate additional suspicious-client security notice. Focused corrections have been integrated and passed their corresponding current local checks. Retained revoked-account fixtures now perform an actual fresh sign-in after restoration; consent tests use the actual privacy-choice form; the pre-015 upgrade fixture reviews all five empty tables before reverting the three pending migrations. These corrections do not convert those failed runs into a release pass.

For a 0.8.x upgrade, the new **`admin/repair-current.php`** entry is required while legacy PHP may remain cached. The five-case acceptance retains old-version controls and adds the exact delivered 0.8.14 with its old repair entry and Auth class genuinely warmed in the same PHP process. Current password/MFA and recent confirmation precede the explicit application refresh. All five cases passed after the partial-controller hardening. Both repair entries returned native 503 responses for the deliberate incomplete-controller cases; current/refreshed Composer states had no JavaScript errors. The historical cached negative control is recorded separately.

Recovery checkpoints are uploaded as individual verified Git blobs and an exact matching tree on `recovery/road-to-1.0-checkpoint`. Use the live branch head; never treat a locally prepared but unuploaded commit as remote evidence. Record the final source, actual CI checkout, all required job/step conclusions, exact tree, artifact hashes, merge and literal saved-download bytes before marking delivery complete.

## Local evidence

The committed [integration evidence](releases/0.9.0-integration-local-evidence.json), [privacy evidence](releases/0.9.0-privacy-local-evidence.json) and [source/workflow audit](releases/0.9.0-source-local-audit.json) preserve actual source/package identities, report checksums and screenshot manifests. Component hashes are not interchangeable with each other or with final release artifacts. They make no final CI, merge, saved-download or customer-host claim.

## Ownership-repair folders at the site root

Folders named `.vvx-private-repair-uploads-<random>` (or another repaired runtime directory) preserve the old directory while the installer rebuilds a writable copy. Hosting ownership limits can prevent the old copy from being removed or moved into private storage. It can contain real uploaded files. Open **Admin → System → Repair Backups → Move Root Backups to Private Storage**. The service can then offer **Delete Verified Duplicate** only when a fresh inspection matches every entry against the live directory. Missing, different or unreadable content is retained for review. This explanation does not claim inspection or deletion on the owner's host.

## Operating guides

- [Administrator security](ADMIN_SECURITY.md)
- [Age, location and policies](PROTECTION_AND_AGE.md), [member privacy operations](../app/Docs/MEMBER_PRIVACY.md)
- [Backup and operations](BACKUP_OPERATIONS.md)
- [Campaign messages](CAMPAIGN_MESSAGES.md), [attribution](MARKETING_ATTRIBUTION.md), [notifications](NOTIFICATIONS.md) and [mail-provider events](MAIL_PROVIDER_EVENTS.md)
- [Update verification](UPDATE_VERIFICATION.md)

## Conditions for a production-certified 1.0

The following remain external acceptance requirements, not checkboxes that local tests can satisfy:

1. Select and approve the live payment provider and merchant account. Implement the selected provider’s actual protocol and verify approved, declined, duplicate, renewal, cancellation, refund and chargeback outcomes with that merchant. The current registered provider is sandbox-only.
2. Configure production mail credentials and sender/domain requirements. Verify real delivery, confirmation, unsubscribe and authenticated suppression against that service using approved test recipients.
3. Supply the intended customer host and access needed to verify a fresh install, preserved upgrade, controlled rollback, backup and isolated restore. Check its actual running build and web-server denial rules; a local PHP server or CI container is not that host.
4. Configure the selected age-assurance account and required geographic policy, then verify the actual provider session lifecycle. Local protocol tests cannot certify an unconfigured production account or determine jurisdictional requirements.

Continue all work that can be completed without those inputs before requesting them. Do not rename the candidate “production-ready 1.0,” mark actual-host issues complete or imply that the affected host was inspected without evidence.
