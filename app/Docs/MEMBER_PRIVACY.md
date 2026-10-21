# Member privacy operations

## What this workflow does

Members open **Account & privacy** at `account/privacy.php`. An active, verified
account and a live, durable device session are required. Creating or canceling a
request requires the account's real current password and a separate confirmation.
The owner opens **Members & Communications → Member privacy**. Ordinary reads do
not initialize privacy, notification, marketing, or protection journals, send
messages, prepare an archive, or run a closure.

An export or closure request waits for an active owner to review the current
inventory. Approval requires a recent owner authentication, the exact request
revision, and the exact inventory revision. Changes to records invalidate the
approval before processing begins. Changing or disabling the approving owner
pauses processing until a current owner reviews and approves resumption.

Privacy work sends no email, calls no billing provider, deletes no independent
backup, and does not make a legal retention decision for the operator.

## Export scope

The account export uses an explicit field allowlist; adding a storage field does
not expose it in a ZIP. The inventory uses complete account-scoped reads in file
storage and SQL, without account-screen list limits.

Included records are the member's account fields and supported preferences;
subscriptions and entitlements; financial order, item, transaction and coupon
facts; media progress; signed-in device descriptions; safe event and mail status
summaries; public messages in owned conversations; linked content requests and
booking inquiries; submitted booking form answers; in-site notifications;
newsletter consent and delivery summaries; mail provider event summaries;
attribution summaries; and the member's own report text and public responses.

Only attachments to member-visible public messages, and member-uploaded
attachments on owned requests, are included. Staff-only messages and attachments
are excluded. Message and request attachment identities are explicitly typed so
overlapping SQL identifiers cannot cross between collections. Ordinary member
attachment downloads enforce the same ownership and visibility boundary.

Passwords and password hashes, authentication tokens, provider recipient
ciphertexts, raw payment payloads, payment-card fields, raw attribution actors,
internal staff notes, internal safety evidence, report receipts and unrelated
member or guest records are excluded. A guest inquiry with a matching email
address remains a separate record unless it has a durable member binding.

The ZIP contains `member-data.json` and permitted attachments. Attachment entries
have byte size, SHA-256 and CRC metadata. Creation and download check the entire
ZIP, its bytes and checksum; a changed or incomplete archive is unavailable.
The route sends private no-store, no-referrer, noindex and nosniff headers.

Each archive is available for exactly seven days after creation. Download requires
the requesting account's current verified identity and live device. Another
member or an owner cannot use that member's download endpoint. A password or
verified identity change invalidates older downloads. Closure freezes the account
and revokes all of its exports before personal data is removed. Age and geographic
content restrictions leave the account privacy utility and its own authenticated
ZIP available; ordinary protected media still follows those restrictions.

### Complete export bounds

The automated path supports at most 1,000 permitted attachments, 8 MiB of account
JSON and 32 MiB of combined uncompressed content. It fails explicitly and holds
the request for assisted handling if any bound is exceeded. No account rows or
files are silently truncated. The owner should arrange a reviewed, private
assisted export for a larger inventory and decline the automated request with a
clear response. Never distribute an incomplete archive as a complete account
record.

## Owner review and retention

1. Open the request and review its complete current counts, open purchase
   obligations, recurring provider references and safety holds.
2. For closure, review financial retention, safety decisions, independent backups,
   external recurring billing, unresolved orders or refunds, and unlinked guest
   records. Every confirmation is required.
3. Record a retention reason and a future review date. Keep unnecessary account
   details, passwords and secrets out of that reason. The date is an operator
   review reminder, not an automatic purge date.
4. Resolve necessary provider cancellation, fulfillment, refund or independent
   retention actions separately. This application does not claim they occurred.
5. Approve the reviewed request. If the form is stale, reload and review again.
6. Run approved work or let the hosting worker process it.

The owner can save a member-visible response or decline a request before
processing starts. Responses are in the member's account history and are not
emailed. After closure begins, recovery requires resuming the same durable
request; the member cannot cancel a partially applied closure.

Financial amounts, currencies, tax, quantities, financial statuses, payment
references, periods and dates remain as needed for exact accounting and later
provider reconciliation. Personal contact fields, card fragments, free-form
metadata and raw provider payloads are removed from those retained facts.
Local member subscriptions close; an external recurring agreement requires a
separate operator decision and action. Safety holds, decision history, asset
references and internal evidence stay effective while reporter identity and
reporter text are removed. Other members and unlinked guest records are preserved.

## Durable processing and recovery

Use the hosting account's PHP executable with the full website directory:

```sh
php /path/to/website/app/Console/run_privacy.php --limit=10
```

The batch limit is 1–25 units. One export uses one unit. A closure uses ten units:

| Stage | Durable effect |
| --- | --- |
| Freeze | Recheck the approved inventory, close account access, revoke devices and auth tokens, checkpoint and revoke exports. |
| Attribution | Remove account-linked attribution without removing another member's shared actor history; persist independent suppression. |
| Core | Pseudonymize account and financial contacts; remove personal account, access and activity records. |
| Communications | Remove owned conversations, messages and linked request records, keeping unrelated guests and other members. |
| Files | Remove checkpointed private files only when no surviving record references them, and remove the member's exports. |
| Newsletter | Remove matching contact, consent and delivery data and preserve independent hashed suppression. |
| Notifications | Remove optional account jobs and prevent delayed discovery or delivery from restoring them. |
| Provider | Remove encrypted recipient material while retaining minimal event/replay facts and suppression. |
| Protection | Anonymize member-bound reports while preserving safety holds, decisions and retained internal evidence. |
| Complete | Mark completion and discard temporary cleanup paths, hashes and member-visible response text. |

The worker has an exclusive nonblocking process lock. A second worker reports
`busy: true`. Each stage is safe to retry after a process dies between its side
effect and its journal checkpoint. File cleanup checks captured path, size and
hash under the store reference lock; shared files remain while another record
references them. A changed or unsafe file pauses for operator review.

The JSON result includes `processed`, `completed`, `held`, `expired` and `busy`.
Exit 0 means the run finished without newly held work; exit 2 means a request was
held; exit 1 means a command or storage failure. Inspect the owner request list
after a nonzero exit. Do not edit approval tokens, stages or revision hashes to
force a request forward. Review the retained inventory and storage issue, then
use **Approve reviewed resumption**. A crash releases the process lock when the
operating system closes the worker's descriptor.

Run the worker regularly even when no new request is pending so expired verified
ZIPs are removed. Expired downloads are denied by their timestamp immediately,
even before maintenance runs. Keep `storage/private/member-exports`, the private
state journal and private communication storage outside public file delivery.
Use the package's actual Apache deny rules and verify direct URLs are denied.

## Delayed writers and restored backups

Core account closure markers, independent hashed newsletter suppression,
notification suppression and provider suppression prevent ordinary delayed
writers, provider feedback, resubscription and reconciliation from recreating
the erased personal material. Later legitimate financial state changes may
still settle or refund a retained transaction, with personal payloads stripped.

These minimal markers and retention records remain intentionally. Do not remove
them as a cosmetic cleanup. A separate backup predating closure can contain
personal records and older consent state. Restores remain a separate reviewed
operation: reconcile completed privacy work and independent suppression before
reopening a restored installation or enabling outbound jobs. This workflow does
not delete backups or promise erasure from third-party systems.

## Acceptance evidence

The repository contains component tests for complete account inventory and
financial preservation, full-service privacy tests, and actual Apache/browser
acceptance for root and nested deployments. SQL acceptance reports the actual
server version; MariaDB is never represented as MySQL 8. Retain the generated
JSON and screenshot hashes with the exact source and package identity when
performing a release review.
