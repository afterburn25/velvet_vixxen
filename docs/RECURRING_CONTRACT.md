# Recurring lifecycle design — proposed 0.9.3 increment

**Design checkpoint; no implementation or release is claimed.** This proposal is based on delivered 0.9.2 main `afc1d0c8371a0aa76d2e193dd068447e0eb0506b`. Recheck live main and complete the contract review before coding. The execution workspace became unavailable during preparation.

## Delivered foundation and remaining defects

The actual delivered `PurchasedTerms` contract is the nested `{terms, hash}` envelope. Original plan items retain the protected `metadata.subscriptionId` reverse link; subscriptions do not have dedicated purchased-terms columns. Capture, checkout disclosure/stale-review rejection, fulfillment, bundle contents, benefits and PPV discounts already use these saved facts.

The remaining executable gaps are the day-based Sandbox renewal key, periods computed from execution time, local-only cancellation/resumption, and absence of authoritative agreement/command/cycle storage. The historical integration-seams table in `RECURRING_LIFECYCLE_PLAN.md` still describes some already-completed 0.9.1 gaps; use current source.

Only Sandbox is registered. A separate strict recurring interface is required. A provider's existing `supportsRecurring()` boolean alone must not enable authoritative recurring execution.

## Additive storage

Proposed migration: `202610220019_recurring_lifecycle.php`, after independently confirming 019 remains unused. Current delivered main has 18 migrations and 88 application tables. Add two tables and equivalent file maps, producing 19 migrations / 90 application tables plus the registry.

- `recurring_agreements` / `recurringAgreements`: ID primary key; member ID; nullable unique subscription ID; scope hash; nullable remote agreement ID; strict JSON. Unique scoped remote identity and member/keyset index.
- `recurring_journal` / `recurringJournal`: ID primary key; agreement ID; record kind; typed unique dedup hash; nullable order ID; strict JSON. Agreement/kind/keyset and order indexes.

Use binary comparison for identities/hashes. Ownership and financial foreign keys use RESTRICT, never cascading erasure. Migration starts empty and does not fabricate historical provider consent or adopt subscriptions from today's catalog.

Both writers share the existing commerce mutex, `paymentAtomic`, and exact owning-member fence. No provider, mail, add-on or secondary-store callback belongs in that transaction. Every SQL read checks indexed columns against JSON; file reads validate map keys and enforce the same uniqueness.

Suggested storage methods:

```php
recurringAgreement(string $id): ?array
recurringAgreementForSubscription(string $subscriptionId): ?array
recurringAgreementByRemote(string $scopeHash, string $remoteAgreementId): ?array
recurringAgreementPage(?string $afterId = null, int $limit = 50, ?string $memberId = null, bool $openOnly = true): array
recurringJournal(string $id): ?array
recurringJournalForAgreement(string $agreementId): array
recurringJournalByDedup(string $kind, string $dedupHash): ?array
recurringJournalPage(?string $afterId = null, int $limit = 50, ?string $agreementId = null, ?string $kind = null, bool $openOnly = true): array
saveRecurringAgreement(array $row): array
saveRecurringJournal(array $row): array
```

Pages return records/nextCursor with bounded keyset pagination; correctness never relies on a screen's row limit. A strict `RecurringContract` owns exact shapes and allowed transitions. Storage also proves ownership and original order/item/subscription bindings.

## Proposed record shapes

An agreement retains schema, ID, member/subscription/original order/item IDs, installation/provider/account/environment scope and scope hash, bind-once remote agreement ID, accepted terms and terms hash, recurring amount/currency/interval/count, exact UTC paid-period bounds and calendar anchors, confirmed state/cancellation facts, monotonic provider ordering, local revision, last command/cycle references, safe issue code, irreversible erasure marker and timestamps.

Use integer UTC seconds for recurring periods. Preserve the billing anchor across short months and leap years. Do not parse raw SQL UTC DATETIME values as site-local dates or send unchanged values through conversion again.

Journal common fields are ID, agreementId, kind, dedupHash, nullable orderId, state, createdAt, updatedAt and data. Distinct prefixes identify command/cycle/event records. Commands and cycles start prepared, then durably claim attempting before transport. Unknown/pending/held records never reset to prepared. Applied/declined/ignored results cannot be reset.

A command binds schema, command ID, agreement ID, exact scope, terms hash, action, stable request ID, expected local revision, existing remote identity, current period bounds and expected provider revision. Actions are activate, cancel-at-period-end and resume. There is at most one unresolved action/cycle per agreement. Duplicate request IDs resolve to the original record or reject conflicting bindings.

Successful command evidence confirms the matching remote identity, monotonic provider revision and requested cancellation setting. Pending/unknown results do not overwrite confirmed renewal facts. Cancellation and resumption do not change the paid period. Old evidence cannot regress newer facts.

A cycle binds its agreement, scope, authoritative remote agreement, terms hash, exact fixed period, provider ordering and the existing strict `PaymentContract` charge binding. Its durable order/operation exist before dispatch. Identity includes the full authoritative interval/reference, not a date-only key. Uniqueness must cover both a reused provider cycle reference and a second reference claiming an already authorized period; hashing all mutable fields into one key is insufficient.

Normalize cycle evidence and persist it with the existing bound payment operation before applying local effects. Reuse the normal financial reducer to commit order, charge, cycle/agreement and subscription in one atomic boundary. The cycle starts at the previously confirmed end, not recovery time. A replay cannot move an interval or create another transaction. Failed local application or unknown evidence cannot add grace. Refund/dispute processing retains original-charge validation and cannot revoke a newer unrelated paid interval.

Authenticated event receipts retain only normalized evidence, fingerprint, original record ID and safe diagnostics. Reused event identity with different evidence holds; identical evidence with another delivery ID remains idempotent. Unknown events do not authorize arbitrary unprepared charges. Responses, lookups and events use the same reducer.

## Provider boundary and integration

Proposed `RecurringProviderInterface` extends `BoundPaymentProviderInterface` with:

```php
executeRecurringCommand(array $binding): array
lookupRecurringCommand(array $binding): array
collectRecurringCycle(array $binding): array
lookupRecurringCycle(array $binding): array
parseRecurringWebhook(string $rawBody, string $signature, string $secret): ?array
```

The adapter must validate the real authenticated protocol; echoing request fields beside an unverified success flag is not evidence. Lookups are read-only and cannot dispatch, retry, or invent acceptance. Sandbox has no remote ledger, so unavailable outcome evidence remains unknown. Test acceptance uses a separate durable synthetic provider ledger.

Prepare the new agreement and activation intent inside paid membership fulfillment, using the actual original purchase and trusted payment scope. Dispatch activation only after that financial commit. Later workers recover prepared/attempted records according to their durable state. Keep callbacks outside SQL transactions/file snapshots while preserving commerce-before-member ordering and closure serialization.

Reuse a bound charge operation for each authoritative renewal so existing refund/dispute validation and durable receipt/marketing/add-on follow-ups remain applicable. Do not loosen the one-off webhook rule requiring prior durable charge authorization.

Before implementation, finish the exact integration design for atomic cycle evidence/operation persistence, provider sequence semantics, refund of an older cycle, and post-closure cancellation. These are correctness decisions, not optional acceptance shortcuts.

## Member and owner behavior

Keep the existing billing cards and approved magenta/black design. Display original recurring amount/frequency, exact paid-through time, confirmed renewal state and outstanding request separately.

- Pending cancellation: “Cancellation requested; awaiting confirmation.”
- Confirmed cancellation: “Renewal canceled. Access through [date/time].”
- Pending resumption: “Resumption requested; awaiting confirmation.”
- Held/unreadable records: a safe review/unavailable state, not a false empty result.

Forms contain CSRF, agreement/record ID, expected revision and stable request ID, never client-supplied price/terms/provider facts. Re-read the current member session after waits and before dispatch/application. Owner actions also recheck current ownership and recent authentication.

Add a bounded recurring-memberships view within existing Commerce → Payment recovery. Preserve the existing payment view and read isolation from add-on bootstrap. “Check outcome” queries the exact original command/cycle and never silently submits another request. Do not offer manual “mark paid/canceled” controls.

Existing subscriptions without agreements remain explicitly legacy; GET and migration do not adopt them. Preserve only explicitly labeled Sandbox compatibility behavior unless a separately guarded adoption design proves the actual original purchase and new intent.

Suggested safe service seams are memberAgreements, ownerAgreementPage, inspectAgreement, journalPage, requestCommand, reconcile and bounded processDue. Projections expose safe IDs, revision, accepted commercial facts, UTC period bounds, confirmed cancellation state, latest request and action flags; raw provider/account bindings and errors remain private.

## Privacy, backup and acceptance

Both new maps enter core privacy inventory/revision, safe export and unresolved-billing review. Closure irreversibly clears the full snapshot while retaining minimal financial identity/terms hash needed for original settlement. It prevents new activation/resumption/renewal/access/contact, does not claim remote cancellation, and permits necessary original cancellation/settlement reconciliation without recreating erased data.

Existing backup enumeration captures complete SQL tables and file data. Prove actual agreement and journal records survive an isolated restore; empty-table presence is insufficient.

Preserve every current producer and workflow gate. Add:

1. File, MySQL 8 and MariaDB 10.11 source acceptance with a separate durable recurring fake provider; retain the existing non-recurring fake-provider boundary case.
2. Real killed workers before dispatch, after external acceptance, after evidence persistence and inside local financial commit; query-only recovery and whole-state comparisons.
3. Concurrent duplicate cycles/events, same-day distinct intervals, delayed/out-of-order results, strict scope/amount/currency/period/terms/reference negatives and pre-commit reference claims.
4. Member/owner/session revocation and recent-auth expiry after demonstrable waits; both closure-first and dispatch-first orderings.
5. Exact raw SQL date/grace preservation under a non-UTC site timezone.
6. Actual installed 018→019 preservation, no backfill, repeat/resume, unique/restrictive constraints and rollback.
7. Privacy inventory/export/redaction, delayed non-revival and actual backup/restore.
8. Hash-pinned actual-package root/nested HTTP, desktop/phone, keyboard, no-JavaScript, safe wording and original screenshot review.

Advance the visible version only with the changed runtime increment, update package dependency floors and historical fixtures, and complete exact source/CI/checkout/artifact/merge/saved-byte acceptance before claiming delivery. This offline milestone cannot certify a selected live merchant adapter or customer-host production 1.0.
