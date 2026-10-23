# CCBill protocol preparation

The owner selected CCBill on October 23, 2026. This developer component prepares exact FlexForms dynamic-price requests and checks the narrowly documented response digest and direct notification-peer ranges. It is not an installable add-on, registered provider, checkout integration, webhook handler, payment confirmation or live-account certification. SDK source stays outside customer packages; runtime 0.9.3 is unchanged.

`src/FlexForms.php` has no network, storage or account side effects. `request()` returns a fixed HTTPS POST destination and allowlisted parameters using integer minor units, canonical identifiers and an explicitly priced day period. Salt parameters use PHP's sensitive-parameter redaction and are excluded from the result. Arbitrary form/customer/card fields and redirect destinations are rejected. Only the five supported two-decimal currencies are accepted; this application does not yet model JPY correctly.

Initial prices are limited to 0.01–99999.99 and 2–365 days. Recurring prices use the same bounded amount, default 30/60/90-day periods and explicit 1–99 rebills. These deliberately narrow defaults are not evidence of account-specific entitlements. Free trials, custom periods, zero-decimal currencies and other exceptions require separately reviewed support. A rebill count of 99 has CCBill's indefinite-rebilling meaning; it is not 99 finite payments.

`exactDays()` accepts only exact day/week durations within supported bounds. Existing calendar-month/year plans must never be silently mapped to 30/365 days. A new day-based offer needs a disclosed, immutable purchased-terms representation before actual checkout can use it.

`matchesSaleResponseDigest()` covers only subscription ID and approval/denial. It does not prove the amount, currency, account, transaction, event, timestamp or custom order identifier. `isDocumentedWebhookPeer()` accepts only a directly established peer in the documented ranges; it does not resolve proxies or trust forwarded headers. Neither helper authorizes fulfillment. A browser return cannot settle an order. A future adapter must establish authenticated transport/provider evidence and exact original order binding, preserve duplicate/conflict receipts and hold uncertain outcomes.

## Next integration work

1. Implement durable hosted-checkout handoff with original operation/installation/account scope, immutable disclosed day-based terms, ownership fences and replay prevention.
2. Pin the account's notification versions and implement bounded ingress, actual peer/trusted-proxy rules and authenticated authoritative reconciliation. Do not invent HMAC signatures for CCBill's legacy notifications. Validate all financial fields independently of the narrow sale digest.
3. Adapt provider-driven renewal evidence to original agreements/cycles without collecting a second merchant-initiated charge. Existing calendar anchors and provider revisions cannot be fabricated from a date-only callback.
4. Implement account-scoped cancellation/status/refund transport and recovery. Data Link transaction extract has separate documented polling limits; a status result alone does not identify a particular successful refund. Cancellation must preserve paid access; resume remains unavailable until its actual supported protocol is established.
5. Add disposable file/MySQL/MariaDB and actual-package HTTP/browser acceptance, then advance the visible runtime version, complete all release gates, merge and verify new package/saved bytes. Configured merchant/test accounts and an actual host remain separate acceptance inputs.

No credentials were supplied or committed. Do not request production secrets in chat. Account/subaccount, enabled Flex flow/dynamic pricing, salt, account-scoped Data Link credentials, permitted server addresses and notification setup belong in private configuration when the integrated owner configuration flow exists.

## Official references checked October 23, 2026

- [Dynamic pricing and defaults](https://ccbill.com/doc/dynamic-pricing-user-guide)
- [Digest field ordering and public vectors](https://ccbill.com/doc/formdigest-value)
- [Webhooks, versions and direct source ranges](https://ccbill.com/doc/webhooks-user-guide)
- [New-sale fields](https://ccbill.com/doc/newsalesuccess)
- [Status/refund API and account scope](https://ccbill.com/doc/ccbill-api-guide)
- [Cancellation API](https://ccbill.com/doc/ccbill-api-cancel-subscription)
- [Data Link extract limits and test mode](https://ccbill.com/doc/data-link-extract-system-user-guide)

Run `php tests/Feature/ccbill_protocol.php`. Public official digest vectors and an independent Python hashlib response vector, malformed/partial inputs, exact money/currency/ID preservation, strict peer boundaries and calendar-conversion refusal are tested. This is offline protocol acceptance; no provider or customer was contacted.
