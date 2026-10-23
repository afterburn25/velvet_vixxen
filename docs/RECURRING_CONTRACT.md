# Recurring lifecycle — 0.9.3

Implemented, fully tested, merged and delivered through [PR #38](https://github.com/afterburn25/velvet_vixxen/pull/38). Release **0.9.3 / Build 20261022.3** is bound to source `bb6dca80a5101053a39d7415c0efb91f60835104`, tree `62545f4d4d3c9fdda8cac69786e36bc7d02a485a` and the [accepted release evidence](releases/0.9.3-recurring-lifecycle.md). The original documentation-only and candidate checkpoints are historical. Only Sandbox is registered; a selected provider still needs its actual adapter and approved live acceptance.

## Stored authority

The delivered `PurchasedTerms` envelope remains `{terms, hash}`. New agreements prove the original paid plan order/item and protected `metadata.subscriptionId` link. They freeze that envelope, exact installation/provider/account/environment scope, a bind-once remote identity, integer UTC paid-period bounds, calendar anchor, confirmed renewal facts and monotonic local/provider revisions. Current catalog edits never change an existing agreement.

Migration `202610220019_recurring_lifecycle.php` adds two empty tables; the preceding 18 migrations remain unchanged. There are 19 migrations and 90 declared application tables plus the registry. File equivalents are `recurringAgreements` and `recurringJournal`. No historical subscription is adopted or backfilled. Binary identity comparison, unique subscription/scoped-remote and typed request identities, restrictive foreign keys and indexed identity/JSON agreement protect SQL storage. File storage checks the same identities and uniqueness.

`RecurringContract` allowlists every field and transition. `RecurringStoreTrait` requires the commerce mutex, an active financial commit and the exact owning-member fence for every write. Scope, original ownership, accepted terms and request bindings cannot change. Erased terms cannot be reconstructed. No provider, mail or add-on callback executes inside the financial snapshot.

Agreement and request reads use ID/keyset storage methods. Owner pages are bounded to 50 agreements/requests. Internal correctness checks consider the complete agreement history; large-history indexing/performance is not claimed.

## Commands, cycles and evidence

`RecurringProviderInterface` extends the bound one-off interface with explicit command execution/lookup, cycle collection/lookup and authenticated recurring-event parsing. A capability boolean alone is insufficient. Only Sandbox is registered; live recurring scope remains blocked. A selected real provider still needs its actual protocol adapter and approved acceptance.

Paid fulfillment creates a pending agreement and activation intent in the original financial commit. Activation dispatch happens afterward. Commands and cycles persist `prepared` then `attempting` before transport. An interrupted/unknown/pending/held request is queried under its original immutable binding; it never resets to prepared or silently resends. Applied/declined/ignored records are terminal. There is at most one unresolved non-event request per agreement.

Member cancellation/resumption binds a stable request ID, owning subscription and expected agreement revision. Same-form replay returns the original result; conflicting or stale forms fail. Pending requests do not overwrite confirmed renewal settings. Successful command evidence must match scope, remote identity, original bounds and provider revision +1. Cancel/resume never change paid-through time. Resume requires an unexpired confirmed cancellation on an open active account.

A cycle's identity hashes the agreement and full previous paid-end second, never a date-only key. Storage also refuses a second cycle claiming the same period. It creates the normal pending order and bound charge operation before dispatch. Amount/currency/frequency come from frozen terms; the next interval starts at the prior confirmed end. Month/year arithmetic clamps short months while preserving the original day anchor.

Confirmed recurring evidence and normal `PaymentContract` evidence persist atomically before application. The existing financial reducer commits the order, charge, cycle marker, agreement and subscription interval together. A membership hold or changed access bounds prevents partial local money/access application; the confirmed original evidence remains recoverable. Eligibility is checked before preparing a cycle and again at dispatch. Decline grants no grace, shifts no paid interval and cannot automatically charge the same period again. Original normal charge records remain refundable; refunding an older paid period cannot revoke a newer unrelated interval.

Authenticated recurring events must reference an existing exact authorized command/cycle. Scoped event IDs have durable receipts. Identical replays are inert; conflicting evidence is rejected/held. An event cannot authorize an arbitrary unprepared charge. Responses, lookups and events share the same reducer. Sandbox has no remote ledger, so its unavailable lookup evidence stays unknown; source acceptance uses an independent fsynced synthetic provider ledger.

The existing billing runner processes a bounded agreement page and saves its keyset cursor. Future or held first-page records cannot starve later pages. Ordinary member/owner GETs do not run billing or query a provider. Explicit legacy Sandbox compatibility remains labeled; non-Sandbox legacy renewals require review.

## Member and owner controls

The existing magenta/black billing cards show accepted amount/frequency, paid-through UTC, confirmed renewal state and outstanding requests separately. Pending cancellation says “Cancellation requested; awaiting confirmation.” Pending resumption does not assert renewal confirmation. Revision-bound CSRF forms recheck the current member after waits and before dispatch/application.

**Admin → Commerce → Payment recovery → Recurring memberships** shows safe IDs, original amount/bounds, revision and request status. A recently authenticated current owner can choose **Check original recurring outcome**. It queries only the original operation or applies already confirmed evidence; there is no manual mark-paid/canceled override. Private response headers and existing read isolation apply. Provider account/installation/remote identities, bindings, raw results and customer private fields are excluded. Unreadable records show an unavailable state.

## Closure and backup

Both maps participate in privacy inventory/revision, safe export and unresolved billing review. Core erasure irreversibly removes full agreement terms and closes the agreement, retaining minimal financial identity/hash for original settlement. Late settlement can record money without recreating access, contact or erased terms. Necessary original remote cancellation remains possible; local anonymization does not prove cancellation. New resumption/activation/renewal is prohibited after closure.

The existing encrypted archive and isolated restore now exercise populated agreements and an original unresolved cancellation, with independent restored file/SQL readback. Restored drills remain quarantined. Independent backups retain their separate retention obligations.

## Executable acceptance

- `tests/Feature/recurring_lifecycle.php`: file/MySQL/MariaDB synthetic ledger, calendar anchors, strict evidence negatives, concurrent due/event workers, post-wait authority revocation, four real SIGKILL interruption checkpoints, replay/query-only recovery, atomic local hold, decline/no-grace, old-period refund, privacy and late non-revival, SQL non-UTC unchanged-date preservation.
- `tests/Http/recurring_http.py`: exact-package root/nested file/SQL checkout, member cancel/resume/stale/CSRF, owner/guest/member boundaries, safe projection, inert GET, unknown wording and original-confirmed recovery. Unknown/confirmed setup explicitly uses owned synthetic normalized evidence for the same request; it does not claim a real uncertain Sandbox transport response.
- `tests/Browser/recurring_playwright.py`: same actual controls on desktop/phone, keyboard and JavaScript disabled, overflow checks and decoded screenshots.
- Installed 018→019 preservation/no-backfill/resume, migration rollback, populated encrypted restore and every retained source/payment/purchased-terms/installer/ownership/OPcache/HTTP/browser/package gate remain required.

Final source/CI/artifact/merge/saved-delivery acceptance is recorded in the [release record](releases/0.9.3-recurring-lifecycle.md); the [checkpoint](releases/0.9.3-recurring-checkpoint.md) retains earlier outcomes. These tests do not establish production merchant, customer-host or 1.0 certification. Additional recurring-specific wait-barrier and interruption permutations remain review work until separately exercised; the retained payment foundation's broader cases must not be relabeled recurring-specific evidence.
