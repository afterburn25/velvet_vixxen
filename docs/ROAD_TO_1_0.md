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
| Accessibility and compatibility | Keyboard focus and dialog/menu controls, field labels, focusable tables, visible link distinctions and public trailer captions; explicit MySQL/MariaDB date/schema handling | Rendered automated checks, keyboard acceptance and bounded loopback response budgets are being integrated. Automated checks do not establish full WCAG conformance or production performance. |

## Work still in progress

Complete and integrate performer/release records, protected-media holds and complaints/appeals; configured age-provider and geographic controls; member data export and reviewed deletion; encrypted scheduled backups, retention, recovery mode, diagnostics and isolated restore drills. Keep each feature disabled until its deliberate configuration/activation requirements are met. Preserve all existing runtime, installer, ownership, OPcache, routing, Composer, media, member, commerce and marketing gates.

The new workflow configuration currently preserves all **82 prior required steps** and adds separate safeguards, accessibility and MariaDB jobs. Those configured gates are not evidence of a completed GitHub run. Record the exact committed source, actual CI checkout, successful jobs/steps, final tree, package hashes, merge and saved-download bytes before marking a delivery complete.

## Conditions for a production-certified 1.0

The following remain external acceptance requirements, not checkboxes that local tests can satisfy:

1. Select and approve the live payment provider and merchant account. Implement the selected provider’s actual protocol and verify approved, declined, duplicate, renewal, cancellation, refund and chargeback outcomes with that merchant. The current registered provider is sandbox-only.
2. Configure production mail credentials and sender/domain requirements. Verify real delivery, confirmation, unsubscribe and authenticated suppression against that service using approved test recipients.
3. Supply the intended customer host and access needed to verify a fresh install, preserved upgrade, controlled rollback, backup and isolated restore. Check its actual running build and web-server denial rules; a local PHP server or CI container is not that host.
4. Configure the selected age-assurance account and required geographic policy, then verify the actual provider session lifecycle. Local protocol tests cannot certify an unconfigured production account or determine jurisdictional requirements.

Continue all work that can be completed without those inputs before requesting them. Do not rename the candidate “production-ready 1.0,” mark actual-host issues complete or imply that the affected host was inspected without evidence.
