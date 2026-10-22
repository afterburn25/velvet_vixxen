# Saved campaign messages and owner tests

Campaigns share one renderer for the private saved-message preview, the owner's test email, and actual queued subscriber delivery. The owner controls the reusable brand name, tagline, accent colour and footer in **Marketing → Campaigns → Email brand**. Each new campaign can use the branded HTML format, with a plain-text alternative, or plain text alone. An optional button accepts an HTTP(S) destination or a route within the installed site. Owner content is escaped; arbitrary HTML, remote images and scripts are not accepted.

## Review and test

Save the campaign, then select **Preview saved message** on its saved card. The preview is bound to the exact saved campaign and brand revisions. Editing either invalidates an older draft preview. A queued campaign always previews its frozen message and template. The preview is private to a currently active owner, disables browser caching and indexing, loads no add-ons or remote assets, and has no clickable message links. Opening it does not enqueue recipients, record engagement, alter marketing records or contact a mail transport.

**Send test to owner** uses the same renderer with a `[Test]` subject prefix and an unmistakable example unsubscribe link. The recipient is the currently authenticated owner's saved address; the form cannot choose another address. A test never queues subscribers. Configure the common connection in **System → Email & SMTP** first. The separate campaign-delivery switch can remain paused during review and owner testing.

Each test requires current owner authentication, CSRF protection, the exact content/brand revisions and a one-use preview request. A durable journal enforces one test per owner per minute and records its stable outbound Message-ID. The service rechecks the owner, saved message and transport configuration immediately before sending the body, including after the SMTP handshake. A later revocation, edit or configuration change cancels transmission. A message already transmitting cannot be recalled.

Accepted means the configured transport accepted the message. It does not establish inbox arrival. A lost SMTP acknowledgement creates an **unknown** outcome, which is never retried automatically. Reusing a consumed request is rejected even after a failure. Review the provider before intentionally starting a separate test. Interrupted claims that never started body transmission are distinguished from potentially transmitted messages; no automatic test retry is offered.

## Queue and deliver

Queue Recipients freezes the saved subject, preheader, body, format, button and complete brand template together with the existing audience snapshot. Subsequent brand edits affect later queues. They do not rewrite already queued messages. Existing campaign drafts remain editable with revision checks; queued text and buttons are immutable. Legacy campaigns retain their original plain-text output.

Actual subscriber delivery keeps its real recipient-specific unsubscribe destination and a stable Message-ID across its durable delivery record. The shared transport emits safe multipart alternatives for branded messages, using quoted-printable encoding to preserve content and SMTP line limits. The worker still rechecks current consent, confirmation and suppression immediately before sending; accepted and unknown deliveries cannot be automatically resent. Existing bounded batch limits and owner-initiated retries for definitely failed deliveries remain in force.

When the optional attribution implementation is present, only actual queued content links pass through its saved-delivery mapper. Preview and owner test messages retain ordinary links and create no tracking state. The unsubscribe link is always excluded from mapping. Integrated acceptance must verify the actual mapper; the isolated renderer tests do not prove deployed tracking or conversion reporting.

## Engineering acceptance

`php tests/Feature/campaign_messages.php` exercises safe fields and destinations, template revisions, immutable snapshots, test claim/replay/cooldown/unknown handling, stale edits, owner revocation and legacy rendering without sending email.

`python tests/Http/campaign_messages_http.py --mode both` builds and extracts the real Fresh package and runs root/nested file and MySQL cases. It drives the actual owner forms and private preview, uses a loopback SMTP capture for real MIME bytes, checks a revocation during the handshake, and runs the real bounded campaign worker. The guarded fixture creates a recipient through real subscription and confirmation, and can age terminal test requests to exercise cooldowns without adding a runtime clock override.

`python tests/Browser/campaign_messages_playwright.py --mode both` covers real desktop/mobile controls, saved previews, owner test-send, stale brand forms, frozen subscriber delivery and a JavaScript-disabled save/preview path. It records screenshots, geometry, JavaScript errors and the exact built-package identity. Use the established disposable MySQL fixture environment for SQL cases. The browser and HTTP processes connect only to their isolated loopback services and reserved fixture mailboxes.

These tests prove local application and package behavior. Production SMTP credentials, verified sending domains, actual provider acceptance, inbox placement, hosting cron and live-service operation require separate evidence. This document does not declare a version, release, deployment or live-email result.
