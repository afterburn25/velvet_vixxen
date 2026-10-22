# Durable payment foundation

This document describes the provider-independent payment foundation. It does not certify a live processor, production charges, remote recurring subscriptions, or a 1.0 release. The installed default registry still contains only the local sandbox. The owner recovery screens and their HTTP authorization gates are a separate integration of these service APIs.

## Trusted adapter boundary

`app/Payments/BoundPaymentProviderInterface.php` extends the existing provider interface. A compatible adapter supplies `accountContext`, `chargeBound`, `refundBound`, and the query-only `lookupOperation`. `PaymentContract` validates exact normalized field sets and types. It never accepts a browser return, a customer success flag, a truthy string, or a bare transaction reference as proof of payment.

Every operation binds all of the following immutable facts:

- Schema and random server-generated operation ID.
- Charge, refund, or chargeback kind.
- Installation identity, provider key, opaque merchant-account hash, and environment.
- Exact order ID and order number.
- Amount in the existing two-decimal currency model's minor units, and uppercase currency.
- Original successful charge reference for a refund or dispute.

The adapter must establish these facts from its actual authenticated provider response and protocol. Copying expected request fields beside an unverified response does not satisfy the contract. Credential rotation must retain the same merchant identity; changing the account or environment holds existing operations instead of sending them to a different account.

Normalized results contain the binding, one of `succeeded`, `declined`, `pending`, or `unknown`, a nullable transaction reference, and an integer observation time. Success requires a reference. Optional card-display hints accept only four digits and a bounded brand label; hints are excluded from the durable journal. Client tokens, raw responses, credentials, and raw card details never enter that journal.

An adapter's webhook parser must authenticate its actual protocol, including the protocol's timestamp and replay requirements, and return literal `valid === true` with a normalized event. The event binds its ID, type, operation, transaction, and occurrence time. This shared boundary does not replace processor-specific signature verification. The historical signed sandbox envelope is mapped only for the actual sandbox implementation; another provider cannot opt into that mapping by returning similar fields.

## Durable states and financial effects

The `payment_journal` migration stores three strictly validated record kinds. File storage uses the equivalent `paymentJournal` collection.

| Kind | States | Meaning |
| --- | --- | --- |
| Operation | `prepared`, `attempting`, `unknown`, `pending`, `confirmed`, `held`, `applied`, `declined`, `ignored` | Durable intent, observed provider evidence, and the local application marker |
| Event | `received`, `held`, `applied`, `ignored` | Immutable authenticated receipt and its application outcome |
| Effect | `pending`, `attempting`, `retryable`, `held`, `done`, `skipped` | Post-commit mail, marketing, addon, or inventory-review obligation |

Checkout atomically creates its order, stock reservation, and prepared operation before calling a provider. It durably records `attempting` before the external attempt. A lost response remains uncertain even if a provider may already have accepted the request. Repeating the checkout key returns the same order and operation; it does not send another charge. An unresolved refund similarly blocks a second refund attempt.

`lookupOperation` must query only. A missing or eventually consistent provider result remains unknown unless the selected protocol proves definitive nonacceptance. Recovery never calls the charge or refund method again. A confirmed result whose local application failed can finish locally without another provider lookup.

`Store::paymentAtomic` commits order/transaction facts, access, coupon usage, stock, journal state and new obligations together. SQL uses one transaction and locks the owning member before its first consistent read. File storage stages a fresh snapshot under the existing common sidecar lock and commits it through compare-and-swap, synchronized temporary-file rename, and directory synchronization. The existing commerce mutex remains mandatory. No provider, mail, addon or secondary-store callbacks belong inside this boundary; ordinary nested file writers remain forbidden outside this narrowly scoped staging mechanism.

Provider account context is captured before entering the financial commit. The commit rechecks its stored configuration and the authoritative order using local reads only; original-charge validation also has a callback-free local path. Adapter resolution is rejected before any callback if a financial commit is already open. Captured configuration stays in process memory and is never written to the journal or recovery projection.

Transaction-reference checks inspect complete financial records and confirmed operations, including a confirmed result that has not yet produced a local transaction row. An existing reference must resolve to exactly one local transaction already bound to that operation; an unbound row or multiple same-order rows remain held even when their other financial facts agree. A reference cannot move to another order or operation. Partial refunds apply their exact delta once; excessive amounts are rejected instead of silently clamped. Unsupported partial-dispute semantics remain held. Duplicate event IDs require the identical authenticated fingerprint. New event IDs do not repeat an already applied operation. A rejected conflicting event remains inspectable and cannot acquire an actionable link to another order's operation.

## Post-commit obligations

The financial commit creates stable effect IDs. Normal checkout and authenticated webhook processing drain pending work after the commit. Operator reconciliation leaves this work pending for the separate effects control.

The runner records an external claim before dispatch. A restarted `attempting` effect becomes held; it is never silently resent. Only pending work or an explicitly known-safe `retryable` result is eligible for execution. A listener that may have accepted work before throwing, a killed listener, or an ambiguous mail outcome remains held. General addon dispatch retains its existing behavior, while payment dispatch reports listener failures so they cannot disappear behind a success marker. Disabled addon execution or pending addon recovery can leave a known-safe retryable obligation. Addons receive stable operation and effect IDs for their own deduplication.

These markers cannot guarantee exactly-once remote delivery after an acknowledgement is lost. Resolving an ambiguous external effect requires evidence from that external system and its supported reconciliation/idempotency protocol. This increment supplies no blanket resend or mark-paid override.

## Owner recovery API

`PaymentRecoveryService($store)` exposes explicit safe projections:

```php
$page = $recovery->page($afterId, 50, true, $orderId);
$row = $recovery->inspect($journalId);
```

`page` returns `rows` and `nextCursor`. Pages use an ID keyset, accept 1–200 rows, and optionally filter one exact order; a bounded screen is never used as the correctness inventory. Rows contain IDs, kind/state, order number, operation type, provider, amount/currency, effect/event type, generic reason code, timestamps, and `canReconcile` / `canRetryEffects`. They exclude merchant bindings, installation hashes, remote transaction references, raw envelopes, contact details, and payment input.

An unresolved operation may be reconciled. A received/held event may link to reconciliation only when its stored binding resolves to the same operation/order. Terminal operations do not advertise another provider lookup. Pending/retryable effects advertise the separate effects action; held or interrupted effects do not advertise a resend.

```php
$commerce->reconcilePaymentOperation($operationId, $trustedOwnerGuard);
$commerce->retryPaymentEffects($orderId, $trustedOwnerGuard);
$commerce->refundOrder($orderId, $reason, $trustedOwnerGuard);
```

The HTTP integration must independently require the current owner, recent owner confirmation, and CSRF protection for mutations. The optional trusted callback preserves existing internal callers and repeats owner authorization after the commerce mutex wait, immediately before lookup or refund dispatch, inside delayed commits/claims, and at external effect boundaries. Trusted denial codes 403 and 428 propagate as `PaymentRecoveryAuthorizationException`; they are never treated as provider outcomes. Denial after a durable attempt claim leaves that claim unresolved, even when the provider may already have accepted the refund. Query-only reconciliation can recover its outcome; another refund is never sent automatically. These methods are not public acknowledgement routes. GET inspection does not invoke them.

## Privacy and retained inventory

Journal records participate in the complete member-scoped privacy inventory and revision. Received/held events, unresolved operations and unfinished obligations remain visible as open purchase obligations. Own exports use an explicit safe projection with a distinct operation type; merchant scope and remote references stay private.

An unattempted charge must still have an open, active, email-verified member. The first-attempt claim checks that condition under the member lock, then reloads the order and repeats the check immediately before dispatch. A prepared operation already present in an approved closure inventory does not authorize a later charge. A privacy freeze/erasure leaves it held with `account_closed`; suspension or verification loss leaves `account_ineligible`. Zero-value orders obey the same rule, including their local recovery confirmation under the member lock. Recovery does not manufacture a free success for a closed account.

Privacy freeze and first-charge dispatch share the existing commerce mutex. Freeze acquires commerce before the member transaction/file mutation; checkout keeps commerce across the final fresh read, provider call and its local outcome. If freeze wins, the charge sees the frozen account and stays held. If dispatch wins, freeze waits and rechecks the exact approved inventory and current owner authorization after the wait. A changed journal or financial result requires fresh closure review. Freeze explicitly refuses an already open SQL transaction, payment commit, member write or file mutation so it cannot invert the commerce-before-member lock order. Provider calls remain outside all financial transaction closures. An already accepted payment can still settle or refund after closure.

Late authenticated financial evidence may update retained financial records after account closure. It cannot recreate access, identity, receipts or communications. Claims check the member fence after waiting, and addon payloads are reloaded and reauthorized immediately before each listener. Mail also checks identity before transmission. An addon must fence its own subsequent asynchronous writes or transmissions; the core does not claim to serialize all future external work with a later closure.

Erasure removes shipping and item metadata. Missing shipping evidence cannot prove that paid goods were never dispatched. A late refund/dispute therefore retains the existing reservation instead of manufacturing returned stock, and creates a held `inventory_review` obligation. Actual physical stock reconciliation remains an explicit owner task. A late paid result settles the existing reservation without attempting to reconstruct erased variant/address data.

## Local acceptance and remaining live integration

`tests/Feature/payment_foundation.php` runs against an owned disposable file root or, with `--mysql`, an explicitly configured initially empty test schema. SQL acceptance verifies the actual engine version and cleans the owned schema. The fixture provider and addon maintain private local ledgers; they make no real provider or SMTP calls. Real child processes cover accepted-then-timeout, SIGKILL before core commit, accepted charge/refund interruption, addon acknowledgement loss, actual authenticated owner demotion/recent-confirmation expiry during a commerce-lock wait, and claims paused across real privacy freeze/erasure. First-charge cases prove freeze-first refusal (including zero-value), then pause after the final fresh subject read and observe an actual freeze worker blocked until dispatch releases commerce. They also prove post-wait inventory refusal, rejection of inverted nested lock order, suspension and verification loss. Other cases cover malformed and conflicting evidence, cross-order references, missing-catalog rollback, scope rotation, safe inspection, exact-once deltas and retained sandbox behavior. File worker-lock observation requires Linux `/proc` to expose the real child PID; a process sandbox with a different PID view cannot establish that evidence.

The next live integration still requires an approved merchant/provider choice and actual protocol work: secure hosted/tokenized checkout, fixed authenticated transport and credential handling, provider idempotency/lookup behavior, signed webhook translation, processor-specific refund/dispute states, and certification in that provider's environment. Non-sandbox recurring purchase remains blocked until a real remote agreement and lifecycle adapter exists. Renewals, remote cancellation/resumption, retries, grace periods, reconciliation and account-closure subscription handling require that separate lifecycle integration. No processor-specific behavior or production acceptance is inferred from the local fake-provider tests.
