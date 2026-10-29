# 0.9.5 CCBill hosted-checkout engineering acceptance

Status: **development contract, not implemented or approved for live billing**. Baseline is main 0.9.4 / Build 20261023.1. This work must not alter or deploy the delivered 0.9.4 packages. CCBill is the owner-selected provider for new offers explicitly sold as 30-day terms. Existing calendar-month/year agreements and original purchase snapshots must not be converted.

## Scope and invariants

1. Create a durable **hosted checkout intent** keyed to the original local payment operation and immutable installation, provider, merchant-account, environment, owner/member, order/item, price/currency, period and purchased-terms hash. One intent is issued once; HTTP retries may retrieve the same safe redirect/form details but must not generate a second financial intent.
2. Owner and member CSRF/session/ownership/current-authorization checks must occur before preparation, after any wait, and before state transition. Expired or erased membership/account state holds the checkout and cannot grant access.
3. A checkout handoff is *pending*, not paid. Browser success/failure/cancel return routes are presentation only. Never grant purchased content on a return URL, untrusted query, sale digest alone or a single IP-match.
4. Retain the existing commerce lock, operation/event/effect journals, replay resistance, atomic paid/access reducer and query-only recovery. Hold ambiguous transport, unmatched callbacks, conflicts and provider outages, preserving original operation identity. Never silently retry a charge.
5. Ingress must be bounded and safely stored with unique provider event/transaction identities; verify allowed direct-peer or explicitly configured trusted-proxy source, documented notification version and account scope. Do not invent webhook HMAC signatures. Verify authoritative transaction evidence through an approved account-scoped provider mechanism before any money/access application; independently match amount, currency, merchant/subaccount, original order, transaction and member.
6. Provider-driven rebill evidence applies to the original agreement/cycle only, with exact prior paid-end boundary and immutable purchased terms. **Do not initiate a second rebill charge** for provider-collected renewals. Conflicting or missing period/revision data stays held; no inferred calendar conversion.
7. Cancellation/status/refund use account-scoped endpoints and bound original remote identities, with idempotent command/evidence receipts and no premature success messages. Cancellation preserves paid-through access. Restore/resume only when actually supported and verified for the merchant. Distinguish pending, confirmed, denied and unknown.
8. Keep secrets outside version control and rendered admin/member pages. No live merchant action without owner-approved private account configuration and test evidence.

## Implementation sequence

- [ ] Identify actual current provider interface, transaction operation and recurring contract touchpoints; record precise paths and data migration proposal before editing runtime.
- [ ] Add additive durable checkout intent/receipt storage for file, MySQL 8 and MariaDB; preserve existing records and down-migration safety.
- [ ] Implement disabled-by-default CCBill adapter with immutable config scope and request/response parsing; no live registration on missing credentials.
- [ ] Implement hosted-checkout create/read/return presentation without fulfillment on return; maintain no-script, keyboard, phone and desktop accessibility.
- [ ] Implement bounded notification ingress, verified original-operation reconciliation and duplicate/conflict/unknown handling. No secrets or raw PII in logs.
- [ ] Wire provider-driven recurring events to accepted original agreements/cycles and account-scoped cancel/status/refund recovery.
- [ ] Add isolated fake-ledger tests for retries, interrupted workers, two concurrent returns/callbacks, notification replay/conflict, forged/partial callbacks, mismatched merchant/amount/currency/order, changed ownership after wait, account erasure, refund ambiguity and rebill double-charge refusal.
- [ ] Verify source tests on PHP 8.2/8.3 with file/MySQL/MariaDB, actual **Fresh** and **Upgrade** root/nested HTTP, browser no-script/mobile, installer/rollback, owner/admin/guest boundaries, Composer geometry and historical release gates.
- [ ] Only after actual runtime code is complete and frozen: bump visible version/build, generate independent release packages and manifests, run existing complete required CI matrix, verify saved-download byte identity, review PR, then merge. No ZIP inside another ZIP.

## External production gates (not resolved by source changes)

Owner-approved private merchant account/subaccount, enabled FlexForms and notification versions, permitted network/origin configuration, documented status/refund/cancel capabilities, sandbox transaction evidence, production mail and age-verification services, actual host install/upgrade/rollback/restore, cron, backup/retention and policies. Neither a passing synthetic suite nor this contract certifies live payments or 1.0 production readiness.

Related: [CCBill SDK preparation](../addons/sdk/ccbill/README.md), [recurring contract](RECURRING_CONTRACT.md), [roadmap](ROADMAP.md), issues #3, #12, #13 and #34.
