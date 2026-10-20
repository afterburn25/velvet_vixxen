# Marketing Storage

**Implementation target: 0.8.6 / Build 20261020.7.** Implementation and local acceptance passed. Final GitHub CI, merge and verified downloads remain pending; exact evidence is recorded in the numbered release record.

## What changes

Marketing Storage lets the current site owner move the existing marketing records into the site's configured MySQL database. It covers the same records used by posts, banners, landing pages, links, saved newsletter audiences, consent, suppressions, campaigns, queued recipients and analytics. Existing owner and public controls continue to use those records after the move.

The storage move is separate from the database update. Applying migration `202610200015_marketing_sql_storage` creates two additive tables. It does not select the new backend, import records, change consent, queue a campaign or send email. Marketing continues to use its existing protected file until the owner reviews and completes the move.

File-mode installations retain file storage. The move uses the MySQL connection already configured for an installed site; this page does not edit database credentials or move the rest of the site's data.

## Owner workflow

1. Apply the complete **0.8.6 / Build 20261020.7 Upgrade** and complete [Update Verification](UPDATE_VERIFICATION.md). Confirm the active application files before applying its database update.
2. Open **System → Database Updates** and review/apply the pending migration. Existing configuration, installation identity, uploads and other application records remain in place.
3. Open **System → Marketing Storage**. This page reports the active backend and whether the required database tables are available. It can display a storage problem independently of the normal Marketing pages.
4. Select **Preview Move**. The review shows aggregate collection counts and binds the operation to the current source and destination state. Reading the page does not start a move. **Cancel Review** discards that review.
5. Select **Move to MySQL**. The server verifies the current active owner, session, CSRF token and reviewed state again while holding the required locks. A changed source or destination requires a fresh review.
6. Confirm that the page reports MySQL as active. Open the existing Marketing controls to continue editing; later reads and writes use MySQL.

An owner review expires after 30 minutes and belongs to that owner's session. A stale or unsuccessful attempt requires another review. Visiting a link, refreshing the page, or signing in as a member or a different administrative role cannot authorize the move. A completed move cannot be rerun as a second import.

## Records and preservation

The SQL adapter stores separate records for each collection entry. It retains original record keys, stored order, identifiers, tokens, timestamps, metadata and fields it does not interpret. Header data retains the other top-level settings and metadata. The move validates the reconstructed snapshot before activating SQL.

Newsletter consent, unsubscribe tokens and suppression records retain their existing meaning. Saved audience rules and already frozen campaign messages, audiences, addresses and delivery outcomes remain unchanged. Moving storage does not add recipients or retry held outcomes. Campaign delivery continues to check current consent and suppression before a send.

The original `storage/private/marketing/data.json` is retained with its original bytes. Once SQL is active, that file is an earlier recovery source; it no longer receives new marketing edits. It is therefore not a current backup of subsequent SQL activity. Restore an activated installation from a consistent database and application backup.

## Transactions and concurrent workers

Backend selection is checked during each operation. A process created before the move must discover the activated SQL state before its next read or write. Local file locking and database-wide locks coordinate file writes with the transition; SQL writes use transactions and preserve optimistic revision checks.

Campaign workers share a separate database-wide delivery lock across application roots using the same installation and database. A second worker cannot enter interrupted-claim recovery while another worker still owns the delivery run. The delivery lock remains held through transport, while short record transactions end before transport so that owner pause actions and subscriber consent changes can still commit.

The stored authority record binds the SQL state to this installation and database. Before live marketing reads or writes, each root that discovers activated SQL records that SQL is required. The separate status review can read aggregate counts without writing this local guard. Missing tables, missing or inconsistent authority, a changed database identity or an unavailable required database blocks marketing storage rather than switching to the old file.

## Failed or interrupted moves

Record import and SQL activation belong to one transaction. Definite pre-commit failures roll back the imported records and activation. A local SQL-required intent is written before activation can commit, so an interrupted or uncertain commit cannot silently resume writing the old file.

When the database proves that activation did not occur and the original source is unchanged, a fresh review may permit a safe retry. Conflicting, incomplete or foreign destination state remains blocked for investigation. The owner page reports a sanitized explanation without exposing subscriber details, tokens, database credentials or raw database errors.

Do not manually remove storage authority or local intent records to make an error disappear. They prevent two independent writable copies from forming. Diagnose the recorded database and source state first; the retained source does not justify automatic fallback after activation.

## Scope and acceptance

This release adds runtime SQL persistence and a reviewed preservation move. The existing MarketingStore API still reconstructs a complete snapshot for its business operations. Per-record SQL storage does not claim indexed reporting, large-dataset performance certification, durable analytics rollups, a scene scheduler or provider bounce/complaint ingestion.

Required acceptance includes real MySQL import/readback, preserved identifiers and consent, stale reviews, transaction failure, uncertain activation, cross-process and cross-root delivery exclusion, installer continuation and database-update preservation. Actual extracted Fresh-package owner HTTP and desktop/mobile browser tests must exercise the move. The existing source, security, installer, MySQL, HTTP, browser and package gates remain required.

All test transport stays disabled or on loopback fixtures. Source and package evidence does not establish that a customer host has been upgraded or that a production mail provider delivered a message. The remaining editorial, scheduling, attribution and analytics work is tracked in [the roadmap](ROADMAP.md).
