# Consent, attribution, audiences and measurement

Marketing measurement starts only after a visitor chooses **Allow optional measurement** on **Privacy choices**, linked from the public footer. An absent, modified or expired choice means measurement is off. The owner can also disable collection for everyone under **Marketing & Publishing → Analytics**. Newsletter subscriptions and operational account or order records have separate purposes and controls.

## Visitor choices

The choice is signed by the installation and lasts six months. Cookies are scoped to the configured installation path, including a nested installation, use `HttpOnly` and `SameSite=Lax`, and use `Secure` when the configured site URL is HTTPS. The associated opaque visitor token lasts up to 30 days. No public link contains an email address, account ID or campaign delivery ID.

Only recognized public content routes are measured. Measurements discard query strings and arbitrary path segments; account, private message, checkout receipt, admin, API, confirmation, unsubscribe and privacy pages are excluded. Composer preview and error responses do not create page views. Recorded events have a strict allowlist of fields: event type, canonical public path, pseudonymous visitor, optional validated referral/campaign, time, and currency/amount for a verified purchase. Event payloads do not contain names, email addresses, message bodies, card details, IP addresses or full URL queries.

**Decline and remove measurements** turns measurement off and removes the browser's retained raw events, active attribution visit and conversion projections. Its contributions are also removed from daily totals while the corresponding raw events remain available. Once raw events have expired, daily totals no longer identify that browser and cannot be selectively removed. A minimal pseudonymous deletion marker prevents reconciliation from recreating erased measurements from an existing order or registration. Deletion does not cancel an order, remove an account or change newsletter consent/suppression.

The deny choice is committed to the browser before stored-event removal is attempted. If storage is temporarily unavailable, measurement remains off and the page explains that removal should be retried from the same browser. Clearing cookies or switching browsers requires a new choice and removes the browser's ability to identify its earlier measurements.

## Referral and campaign attribution

In **Referrals**, create a code using 1–64 letters, numbers, hyphens or underscores. Codes are compared without case differences. Only one enabled saved definition can qualify. Invalid, unknown, disabled or ambiguous codes do not become attribution. A saved code cannot be renamed: disable it and create a new definition so historic origins remain understandable. Edits use revision checks and a fresh active-owner check during the save.

Share a public site URL with `?ref=your-code`. A consenting visitor receives the validated referral when the public page opens. A subsequent valid referral replaces that visitor's current referral; an unknown code leaves a previous valid origin intact. Disabling a definition stops new capture. Previously committed registrations and orders retain their original snapshot.

Campaign delivery can wrap ordinary safe HTTP(S) content links in an opaque installation URL. The destination is saved on the server and never accepted from a click request. The same saved delivery and destination reuse the same URL across attempts. Embedded credentials, unsafe schemes, protocol-relative destinations, backslashes and encoded header/control characters are rejected. Safely encoded spaces remain valid; literal spaces do not. Relative site destinations retain the configured installation prefix. Unsubscribe links are separate and are never wrapped. Preview and test-rendering do not create tracking records.

The click route redirects even if the visitor declines measurement or the owner disables collection. With current consent, a valid campaign click records campaign/delivery origin while retaining a valid referral. Repeated clicks on the same saved link by the same visitor count once. A missing campaign does not break its previously issued destination, but no longer supplies new campaign attribution. Unknown or invalid opaque tokens return an absent-link response without exposing delivery information. There is no email-open pixel.

## Registration, checkout and coupons

Registration and checkout obtain attribution from the server-issued visitor state after checking the signed current choice. A registration stores its immutable origin under the member's private preferences; an order stores its immutable origin in order metadata. Browser-submitted origin objects are not accepted. Ordinary profile and order edits cannot replace these internal fields.

An order can use a valid, unexpired saved registration origin when the current consenting browser has no more recent attributed visit. In **Referrals → Coupon campaign attribution**, connect a real saved coupon to a real saved campaign. A valid coupon actually applied by checkout takes precedence over campaign-click origin and clears the old delivery association; it retains the validated referral. A forged or unapplied coupon cannot create a purchase conversion. Later mapping changes do not rewrite earlier orders.

Actual business state is committed before its analytics projection. Registration, checkout and confirmed purchase use stable source identities, so a retry cannot count an outcome twice. A temporary measurement-storage failure never repeats account creation, charges a customer or changes an order outcome. Maintenance can recover a missing projection from the canonical member/order snapshot. Purchases that are still pending are not reported as successful. Payments supplied by this repository remain sandbox simulations; these controls do not establish a live payment integration.

An account privacy workflow can call `MarketingStore::forgetMemberAttribution(Store $store, string $memberId)` before removing or pseudonymizing its canonical member and order sources. This gathers its saved actors and prevents their future projection. It does not alter independent newsletter consent or provider suppression.

## Business criteria for newsletter audiences

Open **Audiences → Membership, purchase and referral criteria**. These criteria extend the existing exact signup-source and confirmation-date controls. All supplied conditions must match:

- **Active membership**, optionally restricted to selected currently available tiers, or a verified member without an active membership. Saved subscription start/end/grace bounds and current member verification are checked. Future, malformed or ambiguous states cannot grant eligibility.
- **Paid purchases**, with a minimum count, minimum total in minor units, one explicit currency and optional purchase dates. Partial refunds reduce the qualifying total; fully refunded and disputed purchases do not qualify. No exchange rate is inferred.
- **Referral codes**, matched against a consented origin saved with a registration or qualifying paid order. Erased origins do not qualify.

Business data requires one exact, case-insensitive email association to an active verified member. Ambiguous associations are excluded. An unavailable selected tier stays visible for review and cannot silently disappear from a saved definition. Invalid or unknown criteria cannot become an unrestricted audience. Local date inputs reject nonexistent and repeated daylight-saving times; saved dates are absolute instants.

Preview saves nothing and sends nothing. Queueing freezes the chosen rules, message snapshot and selected recipients. Editing an audience later does not alter an already queued snapshot. Explicit requeue reevaluates the frozen rules. These business predicates do not grant newsletter permission: confirmed current newsletter consent and suppression are checked independently again at delivery.

## Reports and exports

**Analytics** and the Marketing overview use consented measurements only. Older unverified event records are not retroactively relabelled as consented.

Select an inclusive range of UTC calendar days, up to 366 days, with optional campaign, referral and event filters. **Compare the preceding period** uses the immediately preceding range of the same length. The table shows absolute counts and changes. Public-page counts use canonical route names rather than private URL queries.

The ordered funnel follows public view → registration → checkout → purchase for the same consenting browser in chronological order. Distinct visitor counts and funnels use only retained raw events. They do not identify people across devices and may undercount when visitors decline, clear cookies, change browsers or cross a raw-retention boundary. A filtered campaign may represent only part of a visitor's journey, especially when a coupon replaces an earlier clicked campaign.

Purchase measurements show gross successful order values by currency. They are not financial statements or net revenue; later refunds do not rewrite the original purchase event. Audience purchase predicates separately use current net qualifying business facts. **Export aggregate CSV** downloads counts, canonical public paths, gross currency totals and retained funnel counts. It contains no visitor/account/delivery identifiers. Spreadsheet formula prefixes and control characters are neutralized. Export requires a current owner session and CSRF-protected POST.

## Retention and operations

Default raw retention is 90 days; the owner can select 7–365 days. Default anonymous daily-total retention is 730 days; it must be at least the raw period and at most 1,095 days. Save settings and run **Run retention and reconciliation batch** to apply cleanup now, or configure hosting cron to invoke:

```sh
php app/Console/run_analytics.php --limit=100
```

Each run accepts a limit of 1–250 canonical business source records, resumes its saved cursor, removes expired measurement data and projects missing retained outcomes idempotently. The runner performs no mail delivery or payment operation, has no public privileged endpoint and uses the actual runtime clock. Concurrent/repeated projection is protected by the same canonical marketing mutation lock/transaction and source identities. SQL failures do not reactivate an archived file store.

The bounded store supports 20,000 raw events, 50,000 daily-total buckets, 20,000 active attribution visits, 20,000 saved tracked links, 1,000 referral definitions/coupon assignments and 100,000 conversion/deletion-marker records. Owner reports display event/total usage and warn near capacity. Retained legacy events count toward raw storage usage and are identified separately; they remain excluded from consented reporting. At capacity, new measurements and legacy event writes pause or the requested new tracking link is refused; existing saved data is not silently evicted. Retention frees expired raw events, daily totals, active visits and retained conversion projections. Tracked links and deletion markers are retained to preserve already-issued destinations and prevent erased data from returning; reaching those limits requires deliberate operator review.

File and MySQL modes retain the same canonical marketing snapshot. MySQL installations need Database Updates migration `202610210016_marketing_measurements` for indexed reports. It creates rebuildable event and daily-total indexes with date/campaign/referral/event/actor indexes. Source revision, full snapshot hash, header hash and compact projection digest bind the reporting index to the authority. Drift is repaired from the verified canonical snapshot; an invalid canonical source is held for review. Filtered reports read matching indexed payloads. A missing reporting migration does not discard canonical measurements and produces an explicit owner update notice.

## Verification boundaries

Source acceptance covers signed choices, strict origins, actual registration and sandbox commerce services, immutable snapshots, currency/count semantics, frozen audience rules, current newsletter revocation, skipped/repeated local times, erased-origin reconciliation, bounded cursor progression, retention, aggregate CSV and malformed-state refusal. The MySQL variant additionally verifies actual indexed query plans and repairs index-only drift while refusing changed canonical data.

Actual-package HTTP acceptance uses root and nested file/MySQL installations, genuine public forms, independent committed-state readback, a reserved loopback SMTP recipient and the sandbox payment provider. The browser gate exercises desktop/mobile consent, reporting/CSV, retention, referral/coupon, business-audience and featured-image controls with real served assets. Release CI and artifact records establish which exact source/package passed; they do not prove installation on a customer host, configured production cron, production mail deliverability or live payments.
