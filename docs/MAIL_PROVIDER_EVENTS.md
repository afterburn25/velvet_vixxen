# Signed SendGrid delivery events

This adapter implements Twilio SendGrid's **Signed Event Webhook** protocol. It is disabled until the current owner saves a public verification key and enables event handling. It is an application integration, not proof that a customer host or production SendGrid account has been configured.

## Provider contract

The implementation follows these official sources:

- [Signature setup and raw-byte verification](https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features)
- [Event fields, permanent bounce versus temporary block, and Message-ID correlation](https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/event)
- [Webhook setup and the 24-hour retry policy](https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook)
- [Webhook public-key retrieval](https://www.twilio.com/docs/sendgrid/api-reference/webhooks/get-signed-event-webhooks-public-key)
- [Official PHP helper](https://github.com/sendgrid/sendgrid-php/blob/main/lib/eventwebhook/EventWebhook.php) and [independent signature test vector](https://github.com/sendgrid/sendgrid-php/blob/main/test/unit/EventWebhookTest.php)

The supplied Base64 DER public key is ECDSA P-256. PHP OpenSSL verifies SHA-256 over the timestamp header concatenated with the **untouched request body bytes**. JSON parsing occurs after signature verification. Re-encoding JSON before checking the signature would change the authenticated bytes and is deliberately rejected by tests.

The endpoint reads `X-Twilio-Email-Event-Webhook-Signature` and `X-Twilio-Email-Event-Webhook-Timestamp`. It accepts at most 1 MiB and 1,000 events per request. The local freshness policy permits a signed timestamp up to 24 hours plus five minutes old, supporting the documented provider retry interval, with at most five minutes of future clock skew. Individual event timestamps must be within the previous seven days. Durable hashed `sg_event_id` identities reject semantic collisions and deduplicate retries across processes.

## Setup on an actual host

1. Configure and verify your outgoing SendGrid SMTP connection separately in **Email & SMTP**.
2. Open **Marketing & Publishing → Provider events** and copy the exact physical POST destination. The route is declared through the existing public dispatcher and works at root or a nested installation; it does not add a website-root forwarding folder.
3. In SendGrid, create/edit the corresponding Event Webhook, enable **Signed Event Webhook**, and save it before copying the public verification key. Paste that public key into this application and enable signed events.
4. Use HTTPS on the production host, keep its system clock synchronized, and subscribe to delivery events, spam reports and unsubscribes. Provider open/click tracking is not ingested into local analytics.
5. Verify an explicitly authorized actual message and its signed provider event on the host. SendGrid's sample events have no matching local delivery and correctly remain unmatched.

No provider API key or private signing key is stored by this adapter. It makes no outbound provider API call. It does not configure DNS, verify a sender domain, install cron or establish inbox deliverability. Production setup and delivery require separate actual evidence.

## Correlation and effects

An event must match both a saved outbound Message-ID and the recipient hash of a campaign/notification that reached `sending`, `sent` or `unknown`. Queued work and arbitrary addresses cannot be suppressed by a purported message identity. Missing/foreign Message-IDs remain unmatched; the adapter never guesses a delivery from an email address alone. Events predating the matched delivery are also unmatched.

| Verified event | Local effect |
| --- | --- |
| `bounce` with `type=bounce` | Permanent hard-bounce suppression for future campaign and optional notification delivery. |
| `spamreport` | Complaint suppression for future campaign and optional notification delivery. |
| `unsubscribe` | Provider unsubscribe suppression; it never grants or restores consent. |
| `bounce` with `type=blocked` | Temporary-block metadata only. No permanent suppression. |
| `deferred`, `dropped`, `processed` | Delivery metadata only. |
| `delivered` | Records acceptance by the receiving server; it does not establish inbox placement. |
| `open`, `click`, group resubscribe and other engagement/account events | Ignored. No visitor analytics, attribution or consent changes. |

A later delivered/resubscribe event never removes a suppression or restores consent. SMTP `unknown` remains non-retryable even when provider metadata is recorded. The provider cannot trigger an outbound email or payment operation.

## Crash recovery and retention

Authenticated events first enter the private `mail_provider` journal. A pending event temporarily stores its recipient encrypted with the existing installation-key encryption service. All other payload data is reduced to allowlisted delivery metadata; raw payloads, body, reason/response strings, IP, user agent and URLs are discarded.

Applying suppression and its replay marker is one atomic marketing transaction. Recording notification feedback and completing the provider journal are separate idempotent operations, so an interruption cannot lose a verified event or duplicate its effects. Terminal event records remove the encrypted recipient. A retry resumes pending work. The owner can also choose **Resume verified pending events**, or a hosting scheduler can run:

```sh
php app/Console/run_mail_events.php --limit=100
```

The batch limit is 1–250. The command applies already verified records; it accepts no arbitrary event/body or clock and sends no email. Configure and verify scheduling separately.

Minimal terminal event metadata becomes eligible for removal after eight days during subsequent receipt/processing maintenance. The seven-day event-date acceptance window prevents a pruned old event from being reintroduced by replay. Active suppressions, saved delivery outcomes and pending events are preserved. Each journal is bounded at 20,000 entries and fails explicitly at capacity; no silent loss or file fallback is allowed. This is not a claim of indexed large-dataset throughput.

The authoritative local gates use an official known signature vector, ephemeral test signing keys, actual packaged HTTP requests, real saved local SMTP deliveries, file/MySQL independent readback, replay/collision and stale/invalid signatures, known/unknown recipient correlation, hard versus temporary bounces, privacy filtering and recovery after interrupted effects. These local tests do not contact SendGrid or send external mail.
