# Optional member and owner notifications

The notification worker reconciles actual saved publications, memberships, subscriptions and orders. It never marks a payment successful, publishes content, creates a subscription, or grants access. Email contains a fixed, generic notice and an account link; private content titles, message bodies and payment details are excluded.

## Owner controls and execution

Open **Marketing & Publishing → Notifications**. Automation starts paused. Enable the master switch and the notice types you want to permit. Saving settings requires current saved ownership, CSRF and the exact settings revision. Viewing the page is private and read-only; it does not initialize the journal, boot add-ons, discover work or send mail.

**Discover notifications** inspects actual committed source records. A pass examines up to 25 members and 25 content recipients and queues at most 100 new notices. Saved cursors resume larger audiences. **Run notification batch** sends at most 10 notices through the owner form. The CLI can combine discovery and delivery:

```sh
php app/Console/run_notifications.php --limit=10 --discover=100
```

The delivery limit is 1–25; the discovery limit is 1–250. The command accepts no test clock or arbitrary recipient/body. Configure a hosting scheduler separately, then verify it on that host. Source/package tests and a local SMTP capture do not prove cron installation or production inbox delivery.

## Workflow eligibility

| Workflow | Source and current eligibility |
| --- | --- |
| New content | A newly observed live post, gallery, video or audio track. The member is active, verified, has explicitly enabled new-content alerts and can currently access the content. Unlisted content is excluded. First enablement seeds the existing live catalog without announcing it. |
| Renewal reminder | An active/trialing subscription ends within seven days and has no cancellation at period end. The member has enabled optional service updates. The identity includes the exact subscription period. |
| Renewal confirmation | An actual paid order has saved `metadata.renewal=true` and a paid date in the last seven days. |
| Payment failure | An actual failed order from the last seven days, or a currently past-due subscription with a recent period end. A paid/recovered order no longer qualifies at transmission. |
| Unfinished registration | A still-pending, unverified account explicitly opted in at signup, is at least 24 hours old and at most seven days old. One deterministic reminder is permitted per account. Its fresh verification link expires in 24 hours. |
| Owner security | A supported trusted application event targets current active owners. Only a generic event notice and an internal admin destination are permitted. |

Billing notices are based on saved local billing outcomes. Their text explicitly distinguishes sandbox activity from live charges. No production payment integration is established by these notices.

Existing member flags `newContentAlerts` and `emailUpdates` control new-content and service notices respectively. Public signup adds an unchecked `registrationReminder` opt-in. Saving account preferences retains other saved preferences, including server-owned attribution. Provider suppression independently blocks campaign and optional notification delivery; newsletter consent is not substituted for notification consent.

## Durability and privacy

The private `notifications` state uses the shared `PrivateState` file/MySQL transaction boundary. There is no SQL-to-file fallback if the required private-state migration is missing. Job identities combine type, canonical source and recipient. Reconciliation is idempotent even after interruption; changing an unrelated list's pagination cannot hide recent member orders.

A worker atomically claims one job with a five-minute lease, then releases storage before connecting to SMTP. It checks current source, recipient status, exact saved email hash, preferences, suppression, owner authorization where applicable and transport configuration before sending the message body. Member preferences and owner/transport authorization are checked again after potentially delayed dependent reads.

An expired pre-body claim is recoverable. An interrupted `sending` record becomes `unknown`, which is never automatically or manually retried. Definite retryable failures require an explicit owner retry, wait at least one minute and stop after three attempts. Cancel works only before transmission. Accepted/unknown messages cannot be recalled. Stable Message-IDs remain unchanged across safe retries.

Registration tokens are created while holding the current notification claim lock. Exact claim identity, lease, recipient eligibility and current actor are rechecked before rotating a member token. An expired predecessor cannot invalidate a verification link created by its successor. The raw verification token exists only in memory and the outgoing email; it is not copied into notification journal data.

The journal retains source identifiers, timestamps, states, recipient hashes and limited generic error text. It contains no copied source body or email body. Owner views omit recipient email/hashes and claim tokens. A journal holds up to 20,000 jobs and refuses additional work at capacity; it never silently evicts unknown, active or completed identities. Review archival/retention requirements before reaching that capacity. This implementation does not claim indexed large-dataset performance.

## Trusted integration API

```php
$notices = new App\Core\NotificationService($config, $store);
$notices->enqueueOwnerAlert('suspicious_admin_login', $eventId, [
    'when' => gmdate('c'),
    'url' => 'admin/?page=security',
]);
```

Supported owner event kinds are `suspicious_admin_login` and `admin_recovery`. The service discovers current owners; the caller cannot supply an arbitrary recipient, subject or message body. Internal member enqueues require a canonical saved source identity and are revalidated before delivery. Normal workflow discovery derives these identities from committed source records, so callers do not need to perform a second, fragile enqueue after a business update.

## Acceptance commands

```sh
php tests/Feature/notifications.php
python3 tests/Http/notifications_http.py --mode file
python3 tests/Http/notifications_http.py --mode mysql
python3 tests/Browser/notifications_playwright.py
```

The HTTP/browser suites extract actual Fresh package bytes and install owned disposable file/MySQL runtimes at root and nested paths. SMTP recipients are restricted to reserved `example.test` fixtures on loopback. Acceptance checks real owner forms and verification links, independent committed state, current consent/owner changes during SMTP, cancellation, unknown outcomes, interrupted claims/body handling and concurrent real CLI workers. Browser tests exercise desktop/mobile layouts, opt-outs, the unchecked signup preference and script-free discovery/cancel. Reports identify their actual runtime/package scope; final release/CI evidence is recorded separately by the release workflow.
