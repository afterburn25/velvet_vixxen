<!-- purchased-terms-092-release:start -->
## Current release — 0.9.2 purchased terms, Build 20261022.2

**Verified, merged and delivered.** [PR #37](https://github.com/afterburn25/velvet_vixxen/pull/37) merged corrected source `e932fea2e8894c692d66e9994541edec9c1aa6ec` as `ad20034003c07efe9aaa941bbe266f300ec70906`. All 28 required jobs passed: 297 successful configured steps, 2 intentional member-only skips in age-policy lanes, and no unexpected skips. [CI run 39855224403](https://github.com/afterburn25/velvet_vixxen/actions/runs/39855224403) checked out `092cb29ac1232913ad373912faff6f0965083391`; source, checkout and merge share tree `8fedf3168e4bb41f0166f5d266811d733c6ad58a`. No post-merge CI result is inferred.

Membership and bundle orders now freeze validated commercial terms before payment dispatch. Checkout discloses those facts and rejects a stale review before order creation. Fulfillment, delayed recovery, subscription display, benefits, PPV discounts, sandbox renewal prices/periods and safe exports consume the original terms; bundle contents stay fixed after catalogue edits or definition deletion. Missing content or invalid new terms hold atomic fulfillment. Legacy records remain explicit without fabricated historical acceptance. Existing access, refund, expiry, protection and privacy rules remain authoritative. The original order-item subscription binding provides the durable reference on file and SQL; no migration is added.

Focused source tests passed 41 file and 42 SQL assertions. MySQL 8 and MariaDB 10.11 exact-package HTTP passed file root/nested (45 checks each) and SQL root/nested (86 each); desktop/phone no-script browser cases passed the same counts. Retained payment interruption, installer, PHP 8.2/8.3, routing, upload, Full Page Preview, Publish, Composer/public geometry, Photo depth and separate-button gates passed. Downloaded reports and representative screenshots were reviewed; the CI evidence distinguishes independent image decoding from visual inspection.

Fresh/complete Upgrade contain **510/469 runtime entries**, preserve **13/9 root directories**, and contain only `.htaccess`, `VERSION`, `index.php` and `route.php` as root files. Owner configuration, keys, content and uploads remain preserved. All four gated distribution files match independently built files and separately retrieved saved customer downloads literally by bytes, size and SHA-256. Saved readback: `2026-10-22T22:07:39.978119+00:00`. The separate 11-file **0.8.1 / repair Build 20261020.3** overlay is unchanged.

**Next unfinished milestone:** authoritative recurring agreements, stable billing cycles, durable cancellation/resumption commands and an idempotent renewal reducer, using these delivered purchased terms. Start from freshly verified main in a separate branch and advance the visible version for the next changed completed release. Only Sandbox Gateway is registered. Selected merchant integration, approved live mail/age, actual-host #12/#13, origin/CDN, cron, backup/restore and owner policy/retention acceptance remain open. This release does not certify production 1.0 or customer deployment.

[Behavior guide](PURCHASED_TERMS.md) · [Release record](releases/0.9.2-purchased-terms.md) · [CI evidence](releases/0.9.2-purchased-terms-ci.json) · [Delivery evidence](releases/0.9.2-purchased-terms-delivery.json) · [Next recurring scope](RECURRING_LIFECYCLE_PLAN.md)

Earlier blocks preserve historical versions and their then-current remaining work; this completed release supersedes their status statements.
<!-- purchased-terms-092-release:end -->

# Velvet Vixxen — next recurring lifecycle scope

Prepared from corrected source `2b66fc3c6b3409cf94086df6430ee060b24ce0ea`, tree `177cb521597517f9257ee390e04d0993d85dca9d`, now accepted as **0.9.1 / Build 20261022.1** through [PR #36](https://github.com/afterburn25/velvet_vixxen/pull/36), merge `61cf2d5aacc180834e2280aab9bd1f23493f621f` and byte-verified saved delivery. [Release evidence](releases/0.9.1-payment-recovery.md) records that completed boundary. This is the next implementation plan; the recurring functionality below is not implemented by the documentation handoff. Read back live main before coding, create a separate branch/worktree, and advance the visible version for the next changed completed delivery.

## Outcome and scope

Build authoritative recurring agreement, command and billing-cycle state using the existing payment journal and financial locks. Complete and test this offline with the sandbox and a synthetic adapter. A paid renewal must correspond to one authenticated agreement/cycle outcome; requesting cancellation must not claim that the provider confirmed it. Preserve the explicit non-sandbox recurring execution block until a selected processor's actual adapter and acceptance exist. No processor choice, merchant registration, live charge, production email or host action belongs to this increment.

## Current integration seams

| Existing file / method | Required connection |
| --- | --- |
| `app/Core/CommerceService.php`: `resolveCartItem`, `checkoutLocked`, `fulfillOrderCore` | Freeze accepted terms before dispatch; membership fulfillment currently rereads the plan and invents a sandbox subscription reference; bundle fulfillment rereads current bundle items. |
| Same file: `cancelSubscription`, `resumeSubscription`, `processDueSubscriptions`, `nextPeriodEnd` | Replace local-only agreement commands and new recurring renewal paths with confirmed lifecycle handling. Current sandbox renewals use current plan prices, execution-time periods and a day-based key; live renewals merely defer. |
| `app/Payments/BoundPaymentProviderInterface.php`, `PaymentProviderRegistry.php`, `SandboxPaymentProvider.php` | Add a separate strict recurring capability interface and normalized agreement/command/event contract. Existing capability boolean alone cannot enable production recurring. |
| `app/Core/PaymentOperationService.php`, `PaymentCommerceTrait.php`, `PaymentStoreTrait.php` | Reuse scope validation, durable intent, immutable bindings, atomic money/access effects, duplicate-reference refusal and separate follow-ups. Keep the existing one-off `PaymentContract` valid unchanged. |
| `app/Core/MemberStoreTrait.php`: `subscriptions`, `saveSubscription`, `mapSubscriptionRow` | Associate authoritative agreements with local subscriptions. Current SQL joins and file saves derive names/benefits from mutable plan data; expose accepted agreement terms separately. |
| `app/Core/MerchandiseStoreTrait.php`: `withCommerceLock`; `MemberPrivacyStoreTrait.php`: `privacyFreeze` | Preserve commerce-before-member lock order and charge/closure serialization. Provider callbacks stay outside `paymentAtomic` and SQL transactions. |
| `public/routes/account/billing.php`; `app/Views/admin/payment-recovery*.php`; `app/Support/payment_recovery_admin.php`; `admin/index.php` | Integrate member requests and bounded owner agreement recovery without exposing provider payloads or credentials. |
| `app/Console/run_billing.php`; `public/routes/api/payment-webhook.php` | Bounded due reconciliation and authenticated lifecycle event routing; no financial mutation on ordinary page reads. |

## Completed prerequisite: immutable purchased terms

**Delivered in 0.9.2 / Build 20261022.2 through PR #37.** The original [0.9.1 assessment](releases/0.9.1-purchased-terms-assessment.json) remains a historical source-pinned assessment. [Purchased terms](PURCHASED_TERMS.md) now use a validated versioned snapshot, canonical hash and protected original order-item/subscription binding on file and SQL. Fulfillment, recovery, subscription display, PPV discounts and sandbox renewals consume it. Bundles retain exact ordered content IDs after definition deletion. Missing referenced content, malformed new terms and insufficient legacy terms hold for review. Current access, protection, refund and privacy rules remain authoritative; historical consent is never invented.

The payment foundation now deletes a second referenced video to prove real atomic money/access rollback and recovery without another charge. Bundle definition deletion is a successful immutable-content case. This prerequisite adds no migration; the later agreement/cycle model below remains unimplemented and must reuse the delivered terms rather than recapture today's catalogue. Read back current main before the next increment. The earlier 0.9.1 preparation paragraph above is historical provenance, not the current release identity.

## Minimal additive storage and transition model

Proposed migration is the next unused `019` identifier, confirmed against live main first. Do not alter historical migration bytes. Use two additive tables and equivalent file-store maps:

- **`recurring_agreements` / `recurringAgreements`:** stable ID; member ID; nullable unique local subscription ID; normalized installation/provider/account/environment binding and scope hash; nullable authoritative remote agreement ID; immutable terms/hash; state, revision and timestamps; confirmed period/cancellation facts. Enforce unique `(scope_hash, remote_agreement_id)` for bound remote identities. Retain minimal financial references with restrictive foreign keys, not cascade erasure.
- **`recurring_journal` / `recurringJournal`:** stable record ID, agreement ID, kind (`command`, `cycle`, `event`), unique typed deduplication hash, nullable financial order ID, and strictly validated versioned JSON. Index agreement/kind/ID for bounded recovery. Commands bind action, expected revision and original request ID; cycles bind exact UTC boundaries, provider cycle identity, terms hash, amount/currency and financial references; event receipts retain authenticated normalized evidence/fingerprint. No raw cards, tokens, credentials or arbitrary provider payloads.

Use JSON-backed records consistently with migration 018, with indexed identity columns checked against their JSON values. No new subscription column is necessary if the agreement table provides the unique reverse link. Add both maps to file defaults and both domains to privacy inventory/export/revision handling; verify `BackupDatabase.php`, `BackupService.php`, `BackupRestore.php` capture/restore them.

Suggested new seams are `RecurringProviderInterface`, `RecurringContract`, `RecurringService` and `RecurringStoreTrait`. They validate explicit state transitions instead of overloading order-only journal shapes. Prepare and durably claim commands under the commerce lock; recheck current member/owner authority after waits; invoke the provider outside financial snapshots; persist confirmed, pending or unknown results. Interrupted commands use read-only lookup, never automatic reissue. Expired authentication after a possible provider acceptance leaves a recoverable original command.

For provider-driven renewal, first validate the known agreement and exact immutable cycle, then durably establish its local order/operation and apply confirmed financial effects. Do not loosen the one-off webhook rule that an arbitrary charge needs prior durable authorization. Handle delayed events against agreement/cycle timing, not a fabricated new purchase time. Cycle identity uses full authoritative period/reference, not `Ymd`; a replay cannot create a second transaction, move a period to “now,” or extend access twice. Stale and conflicting evidence holds without regressing newer confirmed state. Receipts/attribution/add-ons reuse durable post-commit follow-ups.

## Member and owner behavior

Billing shows accepted recurring amount/frequency, paid-through date, confirmed renewal state and any outstanding request. Submit buttons are CSRF-protected and revision-bound. “Cancellation requested; awaiting confirmation” is distinct from “Renewal canceled; access through [date].” An uncertain resume must not assert that renewal resumed. Existing paid access ends according to confirmed terms; ordinary refresh cannot send another command.

Owner Commerce recovery adds agreement/cycle filters and review, recent-authenticated “Check outcome,” and safe links to the order/member. Use bounded pagination, desktop/phone and no-JavaScript forms; redact account bindings and payloads. Unknown records cannot be manually marked paid/canceled. Privacy review includes unresolved agreement commands and cycles. Closure prevents new charges/access/contact while still allowing retained settlement and necessary remote cancellation reconciliation; never infer remote cancellation from local anonymization.

## Meaningful acceptance and release boundary

Extend existing producers: `tests/Feature/payment_foundation.php`, worker/barrier patterns in `tests/Workers/payment_foundation_worker.php`, packaged payment recovery HTTP/browser fixtures, commerce and member-privacy suites. New focused recurring producers may share those harnesses. Cover file, MySQL and MariaDB with real workers and independent readback:

1. Plan price/interval/trial changes and bundle item edits/deletion after dispatch, then delayed confirmation/recovery: original accepted terms or explicit hold, never silent substitution.
2. Concurrent renewals, identical callbacks with different delivery IDs, repeated worker runs and exact cycle replay: one order/payment/access interval and no duplicate contact.
3. Kill workers before dispatch, after provider acceptance, after result persistence and during local commit; reconcile without another command/charge.
4. Wrong merchant/environment/agreement/cycle, amount/currency/terms hash, reused transaction IDs and conflicting event fingerprints: reject/hold with unchanged financial/access state.
5. Delayed/out-of-order success, decline, grace/expiry, cancel/resume and refund/dispute: monotonic confirmed history, exact periods and correct retained access.
6. Closure-first versus dispatch-first barriers; owner/member revocation or authentication expiry during waits; no callbacks inside SQL/file transactions; existing lock-order refusal retained.
7. Member/owner authorization, stale revisions, CSRF/method checks, read-only pages, phone/keyboard/no-script behavior and unknown-state wording.
8. Fresh/upgrade migration, rollback/restore in disposable targets, complete privacy inventory, no data recreation after closure, approved package roots and unchanged targeted installer repair.

Retain all 0.9.1 CI gates and independently bind new source, checkout, complete artifacts, merge and saved delivery. Update runtime dependency floors, packaging/version checks and current status/handoff/owner guides. This offline increment still cannot certify production 1.0: the approved processor and actual adapter, live merchant/mail/age acceptance, customer-host operation and owner policy/retention decisions remain external requirements.
