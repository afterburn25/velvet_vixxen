# Merchandise and direct checkout — 0.8.1

The Store supports physical merchandise with a real catalog, product images, variant inventory, a merchandise cart, shipping details and owner fulfillment controls. Memberships use a dedicated membership checkout. Galleries, videos and digital collections use direct content checkout and remain in My Library when the member has active access.

All bundled payment processing remains **sandbox only**. The approval, decline and processing choices simulate provider responses; they do not collect money. A successful merchandise checkout creates an order for the owner to fulfill. It does not purchase postage or dispatch a package.

## Install or update the database

Fresh installation applies all shipped migrations. Existing MySQL installations must apply pending migrations after uploading the upgrade package. Open **Admin → System → Database Updates**, review the pending list, verify the required backups, enter their reference and apply the update. The owner updater validates the installation identity and preserves the existing configuration, installation lock, security keys and uploads. It does not run the installer.

For command-line inspection:

```bash
php app/Console/migrate.php --status
php app/Console/migrate.php --help
```

The apply command requires confirmation of a verified database backup and copies of the active configuration, installation lock and customer uploads. The updater records the operator's backup reference; it does not create or independently inspect that backup. A failed installed-site migration stops without automatically running destructive down migrations. Review partial changes before retrying.

Migration `202610190014_merchandise` adds `merchandise_products`, `merchandise_variants` and `merchandise_reservations`. Together with booking migration 013, the current schema has 14 migrations and 82 declared tables. File-storage demonstrations use the same catalog and order services without SQL migrations. Runtime packages contain an empty merchandise catalog; the two sample products created by `scripts/create_demo.php` belong only to marked, disposable automated-test copies.

## Owner catalog workflow

Open **Admin → Commerce → Merchandise** to create or edit products.

| Field or control | Behavior |
| --- | --- |
| Product title and URL slug | Required title; the slug is generated from the title when left blank. Slugs are unique, including archived history. |
| Description | Editable plain text. Markup is escaped when displayed publicly. |
| Product price and currency | A nonnegative price with at most two decimal places and a three-letter currency code. All items in one merchandise checkout must share a currency. |
| Status | Draft and archived products stay out of the public catalog. Published products are offered for purchase when an enabled variant has available stock. |
| Images | Upload JPG, PNG or WebP images, or choose public images already in the media library. Up to 12 images can belong to a product. Protected originals cannot be selected through a forged image ID. |
| Variants | Add or remove options using the real form controls. Each option has a label, unique SKU, stock and optional price override. |
| Size, color and other details | Enter named option values such as `Size: M; Color: Rose`. The values appear with the option and are retained in order snapshots. |
| Available stock | Whole units available after active checkout reservations. A value of zero displays the option as sold out. |
| Shipping required | Collect a delivery address for orders containing the product. |
| Shipping price per item | A flat amount multiplied by the number of units of that product. It is added separately from merchandise price, discount and configured tax. |
| Sort order | Controls catalog ordering; lower values appear first. |

The catalog accepts 1–100 variants per product. A variant has its own stock and can inherit the product price or override it. Keep at least one variant enabled; archive the product to withdraw all its options. Disabling or removing a variant preserves its association with old orders. Deleting a product removes it from the catalog through a soft deletion while preserving order, SKU and inventory history.

### Stale editor protection

A saved product form includes a revision of the catalog and variant inventory that the owner opened. The server compares that revision while holding the commerce lock. If a sale or another edit changed the product, the old form is rejected with:

> This product or its stock changed. Reload the product before saving.

Reload the editor, review the current available stock and reapply the intended changes. Saving an old description or price form cannot silently restore units sold while that form was open. The owner can still deliberately replenish stock from a freshly loaded editor.

## Shopping and shipping

The public Store is available at `/shop/`; product detail pages use `/shop/?product=the-product-slug`. Shoppers select a variant and quantity. The displayed price and quantity limit change when they select another option. Server-side validation independently reloads the product, checks its status and variant ownership, validates the whole-number quantity and checks available stock. Browser-submitted prices and shipping totals are never used as authority.

The cart holds merchandise only. Shoppers can change quantities, remove a product, clear the cart or apply an eligible coupon. Quantities are limited to 1–99 per variant and a cart has at most 50 distinct variant lines. Products that become unavailable remain removable from the cart, and checkout is blocked until the selection is valid.

A verified, active member account is required to pay. The merchandise checkout collects recipient name, street address, optional second address line, city, state/province/region, postal code and two-letter country code when any item requires shipping. The server validates these fields before creating an order or charging the sandbox provider. No-shipping products do not require an address.

The order stores snapshots of product title, variant label, SKU, options, price, quantity, shipping charge and delivery details. Later catalog changes do not rewrite those snapshots. Customer receipts and billing records require the owning member session; another member cannot open an order by guessing its number. The owner sees delivery details in the protected order administration page. Audit entries identify the order and status without copying the street address into the activity log.

Shipping uses the owner-configured flat amount per item. It does not calculate carrier rates, buy labels, verify postal delivery coverage or integrate a fulfillment warehouse. Configured tax remains the site's existing percentage setting; it is not an automatic jurisdiction-based tax service.

## Inventory and payment states

Catalog changes, checkout, reservations, refunds and fulfillment updates use the same commerce lock. MySQL uses a database advisory lock and transactional stock updates; the demonstration file store uses an installation-local file lock. The server reserves stock before asking the payment adapter to charge.

| Outcome | Order and inventory behavior |
| --- | --- |
| Successful sandbox payment | The order becomes paid and the reservation is committed once. Physical fulfillment remains pending for the owner. |
| Declined payment or charge exception | The order fails and reserved units are released. The shopper can retry the normal checkout flow. |
| Processing payment | The order remains processing and units remain reserved. The checkout shows Billing History instead of offering another payment for the same checkout identity. |
| Paid webhook for a processing order | The original charge is confirmed, the order becomes paid and its existing reservation is committed without another stock deduction. The normal owner refund flow then remains available. |
| Cancel pending sandbox order | The owner can cancel a pending or processing merchandise order and release its reserved stock. A paid order must use the refund workflow instead. |
| Full refund before dispatch | Reserved or committed units return to available stock once. |
| Full refund after dispatch | Shipped, delivered or completed items are not automatically restocked. The owner must assess any returned goods before replenishing inventory. |
| Chargeback | Digital access is revoked. Unshipped merchandise inventory is released once; dispatched goods are not automatically restocked. |
| Repeated or late provider reports | Duplicate event IDs are idempotent. A paid event cannot revive a refunded, partially refunded, charged-back or cancelled order, or reserve its inventory again. |

Processing reservations do not expire automatically. Use **Cancel Pending Sandbox Order** after deciding to abandon a simulated pending payment. Successful checkout retries reuse the existing order and do not charge or deduct stock twice. Checkout identities are tied to the owning member, so a different member cannot retrieve an order by reusing an idempotency key.

A partial provider refund records its monetary amount; it does not guess which physical units should be returned to stock. The bundled owner action refunds the remaining order amount in full.

## Fulfillment and tracking

Open **Admin → Commerce → Orders**, select the merchandise order and use its **Merchandise fulfillment** panel.

Shipping orders support **Pending → Packing → Shipped → Delivered**. No-shipping orders support **Pending → Packing → Completed**. The owner can enter a tracking reference, and the member's receipt shows the fulfillment status and reference. Tracking is stored as text; no carrier status lookup is performed.

Once an order is dispatched or completed, it cannot be changed back to an unshipped state. This prevents a later refund from accidentally returning already-dispatched stock. The order's payment status and physical fulfillment status remain separate: paying confirms the order, and the owner records fulfillment after completing the physical work.

## Membership and content checkout separation

| Purchase | Entry point | Cart interaction | Result |
| --- | --- | --- | --- |
| Merchandise | Store product or catalog option → Add to Cart | Uses the merchandise cart; successful payment clears that cart. | A physical order awaiting owner fulfillment. |
| Membership | Membership page or Store membership promotion → Choose Plan | Creates a separate membership checkout; merchandise already in the cart remains there. | The selected membership appears in Billing and supplies its configured access. |
| PPV gallery or video | Its content page → Buy Now | Creates a direct checkout for that release; merchandise is not added, removed or purchased. | Purchase access appears in My Library. |
| Rental | Its content page → Rent | Creates a direct rental checkout for that release. | Time-limited access appears in My Library while active. |
| Digital collection | `/collections/` → Buy Collection Now | Uses a direct collection checkout. | The collection's configured content access appears in My Library. |

Direct checkouts have separate session intents, coupons and payment identities, including when several tabs are open. Completing one intent does not consume another intent or the merchandise cart. PPV galleries and videos are not rendered as normal merchandise products in the Store. My Library continues to resolve actual digital entitlements, including expiration and refund/chargeback revocation; physical merchandise never creates a media entitlement.

## Validation evidence and required gates

The current [focused merchandise unit suite](../tests/Unit/merchandise.php) has **45 assertions**. It covers catalog persistence, variant ownership and duplicate IDs, quantity/discount/shipping arithmetic, separate carts and checkout intents, private shipping validation, retry ownership, reservation release, pending cancellation, refunds before and after dispatch, chargebacks, late provider events, asynchronous payment/refund behavior, stale owner forms and preserved order history.

Additional executable coverage includes:

- [Merchandise HTTP acceptance](../tests/Http/merchandise_http.py): real owner product creation with image upload; missing-CSRF, guest, member and editor-role denial; image/type validation; escaped descriptions; PPV/cart separation; shipping validation; stock; receipt ownership; fulfillment, tracking and refunds.
- [Merchandise browser acceptance](../tests/Browser/merchandise_playwright.py): real owner image upload and dynamic variant add/remove, desktop/mobile selection and quantity changes, required shipping fields, separate PPV purchase, sandbox payment, receipt, inventory changes, stale editor rejection and owner fulfillment.
- [MySQL merchandise scenarios](../tests/Database/merchandise_scenarios.php): real catalog writes, quantity/shipping checkout, stock reservation, idempotent retry, stale editor rejection, refund, catalog edit/delete and preserved snapshots.
- [Payment webhook security](../tests/Security/payment_webhook.php) and [existing commerce regressions](../tests/Unit/commerce.php): signature rejection, duplicate reports, digital entitlements, membership behavior, coupons, refunds and chargeback revocation.
- [Installed database update scenarios](../tests/Database/database_update_scenarios.php): an existing 12-migration installation is advanced to 14 while preserving records, installation identity, keys and uploads; failed updates do not run automatic down migrations.

The source gate runs in a disposable application copy. The HTTP/browser harness execs its own snapshot and resets its demonstration data between suites, so it does not boot or retire the original checkout's installer. The database gate refuses a non-test or nonempty database, applies and reapplies migrations, executes installed-upgrade, merchandise and booking scenarios, then rolls all test migrations back.

Required CI uses PHP 8.2 and 8.3, disposable MySQL 8 and real Chromium desktop/mobile interactions. Local validation uses the available PHP 8.3, MariaDB 10.11 and Chromium runtime; that local result is recorded separately from the remote MySQL 8/PHP matrix. See [TESTING.md](TESTING.md) and [TEST_REPORT.md](TEST_REPORT.md) for the complete source, browser and package evidence.
