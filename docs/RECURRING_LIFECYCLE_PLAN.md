<!-- purchased-terms-092-candidate:start -->
## Current development — 0.9.2 purchased terms, Build 20261022.2

**Implemented candidate; release acceptance pending.** Work starts from verified live `main` `8d7ccce01d3a2db599b9c9955b10e0a9a115c0d7` on `feature/0.9.2-purchased-terms`. Version 0.9.1 remains the latest completed customer delivery. No 0.9.2 merge, saved customer delivery, production payment or customer-host acceptance is claimed here.

New membership and bundle orders capture strictly validated, canonically hashed commercial terms before payment dispatch. Catalogue writers share the commerce lock. Fulfillment and delayed recovery consume the original snapshot; deleted bundle definitions do not substitute contents, and missing content or invalid new terms hold the atomic local application. Subscription display, commercial benefits, PPV discounts, sandbox renewal prices/periods and safe own-data exports use the original order item through its protected subscription binding. Checkout displays the terms and refuses a stale review before creating an order. Historical records are explicitly legacy, without automatic backfill; unfulfilled legacy memberships/bundles with insufficient original terms remain held. Current access, protection, refund, expiry and privacy rules still apply.

The storage change uses existing order-item metadata and the existing fulfillment subscription ID as its durable reverse reference on both backends; no migration or historical schema byte changes. The single-page installer and approved 13/9 runtime roots are preserved. Photo depth, separate buttons, Composer geometry, upload, preview, publishing and organized dispatch remain in the retained acceptance matrix. The separate 0.8.1 installer-only repair remains byte-identical.

Local evidence currently includes all 67 source/module suites, the focused purchased-terms file suite, and strict reproducible Fresh/Upgrade packaging. Actual packaged file HTTP checkout/recovery/billing passes at root and nested paths. Database and final changed-source results are recorded in the candidate evidence as they finish. The complete workflow, actual desktop/mobile/no-script browser evidence, reviewed artifacts, guarded merge and saved customer-file readback remain required. The local sandbox denies Chromium's required Unix sockets and foreign-owner fixture operations; no passing result is substituted for those gates.

The full recurring agreement/cycle/cancel/resume increment and approved live provider/merchant acceptance remain separate, along with configured mail/age services, actual-host #12/#13 and owner policy/retention decisions. [Candidate guide](PURCHASED_TERMS.md) describes exact behavior, legacy limits and remaining acceptance.
<!-- purchased-terms-092-candidate:end -->

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

## First prerequisite: immutable purchased terms

The [source-pinned purchased-terms assessment](releases/0.9.1-purchased-terms-assessment.json) identifies a prospective prerequisite that can be delivered separately before the agreement, cycle and command work below. This is a read-only assessment of the accepted 0.9.1 source; the functionality remains unimplemented. A separate increment must make fulfillment, recovery, subscription display, PPV discounts and any claimed sandbox renewal terms consume the purchased snapshot. Preserve current access, protection, refund and privacy rules, handle missing legacy terms explicitly, and pass file, MySQL, MariaDB and exact-package acceptance. The later agreement-table design belongs to the full recurring scope; this prerequisite needs its own additive storage or durable source-reference design.

Once bundle contents are frozen, deleting the mutable bundle definition should no longer supply the existing payment-foundation local-apply failure. Replace that trigger with another real bounded failure while preserving atomic rollback, confirmed evidence, reference-claim and no-recharge assertions. Do not remove the failure coverage or recreate original legacy terms from the current catalogue.

The reviewer concern is concrete: a plan can change between charge and delayed fulfillment, and a bundle's contents can change before recovery. Persist a versioned, validated snapshot and canonical hash in existing order-item metadata before dispatch. Membership terms include plan identity/display name, initial and recurring amounts in minor units, currency, interval/count, trial rules, and the initial-versus-recurring treatment of discounts. Bundle terms include the exact ordered, deduplicated content type/ID set. Accepted billing terms and promised bundle contents must never be reconstructed from today's catalog. Reject unsupported ambiguity rather than inventing renewal discounts or trial behavior.

Fulfillment/recovery consumes the snapshot; current rights/safety/access holds still apply. Removing a referenced item causes a reviewable hold, not substitution of another item. Catalog edits affect new purchases; changing an existing agreement requires a separate future amendment/consent design. Historical records lacking a snapshot remain explicitly legacy/sandbox or held for review; never manufacture a historical acceptance. Keep these guarantees separate from mutable catalog descriptions and availability.

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
