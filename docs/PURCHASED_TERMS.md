# Purchased membership and bundle terms — 0.9.2 candidate

This candidate is the immutable-purchase prerequisite of the recurring lifecycle plan. Version 0.9.1 remains the last delivered release until the candidate completes its own CI, artifact review, merge and saved delivery.

New checkout reviews disclose the membership amount, frequency, initial charge and applicable trial, coupon and sandbox-tax treatment. A server-generated review revision is checked again under the commerce lock before creating an order. Changed terms require another review. Browser-supplied snapshots are ignored.

Before dispatch, the order item stores an allowlisted schema-1 snapshot and canonical hash. Memberships capture plan identity/name, initial and recurring minor-unit amounts, currency, interval/count, trial duration, PPV discount and benefit text. Existing sandbox behavior is explicit: the initial charge begins any configured trial; initial coupons/tax do not repeat in simulated renewals. These rules do not certify live recurring consent, tax or a merchant adapter. Bundles capture the exact ordered, deduplicated gallery/video identifiers. Inactive bundles refuse new purchases.

Delayed fulfillment and recovery use those saved facts. Removing the bundle definition does not alter purchased contents. Removing referenced content, corrupting a snapshot or losing a required new snapshot holds local fulfillment atomically; confirmed evidence remains available and recovery does not send another charge. Current publication/protection, member status, tier assignments, expiry, refund/chargeback and account-closure restrictions remain authoritative.

The existing fulfillment `subscriptionId` provides a durable reverse reference to the original membership order item. Its snapshot, subscription binding and order requirement marker cannot be replaced through ordinary Store mutations. Subscription display, benefits, PPV discount and sandbox renewal prices/intervals resolve that original item. File and SQL use the same source-reference design. No migration is added. This is not an authoritative remote agreement, stable provider billing cycle or durable cancel/resume command implementation.

Older fulfilled subscriptions retain saved identity, financial facts and access dates. Billing labels absent terms as legacy; their compatibility sandbox pricing uses the current catalogue without calling it historical acceptance. Ordinary reads never backfill snapshots. Legacy pending memberships/bundles without sufficient original terms remain held for review. Do not reconstruct original acceptance from current catalogue values. A held charge can require original-record restoration or separate provider resolution; automatic replacement of missing terms is unavailable.

Billing and own-data exports disclose only validated commercial terms and explicit accepted/legacy/held state. Arbitrary metadata, credentials and provider scope are excluded. Reviewed privacy closure can erase commercial metadata, and late fulfillment cannot reconstruct it. Backups already capture the existing orders/items; no new storage domain or table is introduced.

Run the new source and exact-package producers alongside every retained gate:

```bash
php tests/Feature/purchased_terms.php
php tests/Feature/purchased_terms.php --mysql
python tests/Http/purchased_terms_http.py --mode both
python tests/Browser/purchased_terms_playwright.py --mode both
```

SQL fixtures require an explicitly owned initially empty test schema and exact engine selection. The existing MySQL 8/PHP 8.2/8.3 and MariaDB 10.11 lanes run the source producer. Both payment-recovery engine lanes retain all existing recovery tests and add root/nested HTTP and desktop/phone no-JavaScript purchased-term flows against the pinned candidate Fresh hash. The source producer covers initial coupon/tax separation, delayed plan edits, immutable metadata, original discounts/renewals, bundle edits/deletion, corrupt new terms, held incomplete legacy purchases, safe exports and privacy erasure. The retained payment foundation still induces a real second-item fulfillment failure and verifies money/access rollback, original evidence, reference claims and recovery without another charge.

No live merchant, production mail/age service, customer-host installation, upgrade, rollback, cron or CDN/origin acceptance is inferred from these tests.
