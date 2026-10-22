# Payment recovery

Open **Admin → Commerce → Payment recovery** to inspect saved payment operations, authenticated provider events and order follow-ups. The Orders detail screen and Payment Settings also link here. These controls belong to the payment-foundation increment; its release record must establish the tested source and delivered version before deployment.

## Review a record

The default **Open records** filter includes pending, uncertain and held work. Choose **All records** to include completed, declined, ignored and skipped records. An optional exact **Order ID** filter narrows the list. Each page contains up to 50 records; use **Next page** to continue. The page count is not a total for the installation.

Choose **Review record** to see the saved state, original operation ID, provider, operation amount and UTC timestamps. **Open order** returns to the existing order detail. The recovery view excludes customer contact details, payment tokens, merchant account bindings and provider payloads. Opening or filtering the page does not query a provider, retry a payment, send a receipt or execute an add-on.

Only the current active owner can open this workspace. A recovery action also requires the owner's identity to have been confirmed within the last 15 minutes. Use **Confirm in Security** when prompted, then return to the record. The server checks ownership and recent authentication again after waiting for the commerce lock and before protected work.

## Check an uncertain payment

**Check payment outcome** uses the original saved operation. It can query the configured provider for that operation or finish local updates whose successful provider result is already saved. It never submits another charge or refund. It also leaves receipt delivery and add-on follow-ups to their separate controls.

An unavailable or ambiguous provider response keeps the operation pending, unknown or held. A record cannot be manually marked paid on this screen. If the provider or merchant-account configuration has changed, restore the original configuration before attempting reconciliation. Investigate conflicting results against the provider's own records.

The included Sandbox Gateway is a local simulator. It has no external ledger to query after an interrupted response, so a sandbox lookup may remain unknown until a valid signed sandbox callback supplies the outcome. This behavior does not establish a live merchant integration.

## Account eligibility and refund authority

A first payment requires an active member with a verified email address and an open account. These conditions are checked again immediately before dispatch, including for zero-value orders. An **Account closed** hold stops a new charge when closure has started; an **Account ineligible** hold stops it when the member is suspended or no longer verified. Account closure and first-charge dispatch share the same lock. If closure finishes first, a waiting charge cannot start. If dispatch starts first, closure waits and requires a fresh review of any changed payment records.

Already accepted payments and refunds remain reconcilable after account closure, so financial history can be completed without reopening the account. Later contact and integration follow-ups remain suppressed. Refund requests repeat the current-owner and recent-authentication checks after lock waits and before protected work. If authorization expires after a refund may have reached the provider, the saved operation remains available for outcome lookup; recovery does not issue a second refund.

## Run order follow-ups

After a confirmed payment's local updates are committed, the site records follow-up work separately. This can include an order receipt, attribution and add-on notifications. A pending or proven-retryable follow-up offers **Run eligible order follow-ups**. The action processes eligible work for that order and records each outcome.

Completed or skipped work stays completed or skipped. An interrupted external delivery remains held because it may already have been received. The recovery page cannot blindly resend an uncertain receipt or add-on notification. Review the receiving service's records before any separately designed recovery procedure. Closing a member account suppresses later contact and integration follow-ups.

An **Inventory review** hold means automatic stock return could not safely establish the previous fulfillment state, such as after personal order metadata was erased. Review retained fulfillment evidence before changing physical stock through the normal inventory controls.

## Installation and operational boundaries

Apply the complete versioned Upgrade and run the pending database migration for the payment journal on SQL installations. Follow [Update verification](UPDATE_VERIFICATION.md) for source-file checks and the current PHP refresh entry. Existing orders and transaction history remain available; an order created before this increment may have no payment recovery record.

If records cannot be loaded, the page reports that failure instead of displaying a successful empty journal. Check **System → Database Updates**, private storage permissions and the installation's recovery records. Keep backups and financial history according to the site's reviewed retention policy.

The foundation supplies durable operations, strict provider-result binding and controlled recovery. Production certification still requires the selected processor's actual adapter and merchant acceptance, including its supported recurring agreement lifecycle. Do not enable an unverified live adapter or infer production payment support from successful sandbox tests.
