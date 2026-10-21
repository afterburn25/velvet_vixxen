# Whole-site backups, diagnostics and isolated recovery

Operations provides encrypted installation snapshots, a daily UTC schedule,
retention, complete integrity verification, and a reviewed restore into an empty
isolated destination. It includes the application files, configuration,
installation lock, customer uploads, private records and all supported SQL
application tables. A successful restore remains quarantined. It does not
replace a live directory, clear a live database, send email, run payment jobs,
or automatically activate restored credentials.

## Hosting prerequisites

Use 64-bit PHP 8.2 or newer with Sodium, PDO MySQL for SQL installations, and a
normal local filesystem with working advisory `flock` locks and atomic rename.
The CLI must run as the same Unix account that owns the private archive key and
can read all installation files. The worker streams file and row contents;
allow enough PHP memory for the path inventory, preferably 512 MiB on a large
installation. A complete archive needs local free space for all retained files
and the logical database export. Archives are encrypted, without compression.

The logical database format supports ordinary InnoDB tables on MySQL 8.0/8.4
and MariaDB 10.11. Restore to the same engine family. All tables in the dedicated
application schema are included, including add-on tables. Views, triggers,
routines, events, non-InnoDB engines, external tablespaces and cross-database
foreign keys are refused; use a hosting-native backup for those installations.
The database account must be able to enumerate the full schema, read its rows,
and inspect its definitions. The isolated restore account must be able to
create and populate the target schema's tables. Do not grant it permission to
change the live schema.

Stop external database writers, deployment/extraction jobs, uploads that bypass
PHP, and any code that does not enter the application's bootstrap during a
snapshot. The shared request gate covers the shipped PHP requests and ordinary
workers. It cannot drain an unrelated database client, an older PHP process
that started before gate setup, another server using a different lock inode,
or a direct static-file transfer. On clustered or network filesystems, use the
hosting platform's coordinated backup facility unless its locking semantics
have been established independently.

Web-server access rules must already protect `config`, `storage`, `app`,
`database` and other private runtime trees. Recovery mode controls PHP requests;
it does not retract files served directly by Apache, nginx, a CDN or object
storage. Keep the isolated restore destination outside every served document
root. Remote provider objects, external databases, host secrets outside the
installation and external services need their own backup policies.

## One-time setup

1. Sign in as the active owner and open **System → Operations**. Confirm your
   password in **Security** if the sign-in is older than 15 minutes.
2. Select **Set up Operations**. This explicitly creates a private signed gate
   marker and persistent request/control mutexes, then saves the enabled flag
   under the shared configuration write lock. Public recovery mode begins.
3. Stop or drain every PHP and cron process that predates setup. Restart the
   site's PHP pool or ask hosting support to do so. A new mutex cannot
   retroactively protect a process that never acquired it.
4. From the installation directory, inspect the current status:

   ```sh
   php app/Console/operations.php --status
   ```

5. After actually completing the drain, use the returned gate revision:

   ```sh
   php app/Console/operations.php --activate --quiesced --gate-revision=REVIEWED_REVISION
   ```

   `--quiesced` is the operator's explicit confirmation of step 3. Activation
   also requires the existing exclusive request lock and exact current marker
   revision. It resumes ordinary requests.

6. Create a separate 32-byte binary key outside the website and outside all
   public document roots. The parent directory should be private. The following
   command refuses to overwrite an existing file:

   ```sh
   php -r '$h=fopen($argv[1],"xb"); if(!$h) exit(1); chmod($argv[1],0600); fwrite($h,sodium_crypto_secretstream_xchacha20poly1305_keygen()); fflush($h); fclose($h);' /private/velvet-backup.key
   ```

   Retain a second protected copy in your secret-management or disaster-recovery
   system. Do not put the key itself in an environment variable, command-line
   argument, web form, repository, support message or backup directory. The
   application checks the file's exact length, ownership, private permissions
   and absence of linked aliases. The web/CLI Unix account must own it.

7. Run `--status` again and configure only the key's private path using its
   current configuration revision:

   ```sh
   php app/Console/operations.php --configure-key --key-file=/private/velvet-backup.key --config-revision=REVIEWED_REVISION
   ```

   The path is saved; the key bytes remain external. Retain every older key
   while archives encrypted with it exist. A different key does not authenticate
   an older archive, and retention refuses to delete archives it cannot verify.

Opening Operations or diagnostics before setup creates no operations marker,
mutex or journal. After setup, a missing, modified or mismatched marker/mutex
fails closed. The application never silently recreates a missing live lock.

## Run and schedule backups

**Queue encrypted backup** stores one manual request. It does not run a lengthy
snapshot inside the owner's web request. Add a hosting cron job every five
minutes, using the correct PHP binary and full installation path:

```sh
php /path/to/website/app/Console/run_backups.php
```

Set **Schedule daily backups**, its UTC time, and retention in Operations. A
newly saved time that already passed starts on the next UTC day. The worker
attempts at most today's due scheduled backup; it never replays every missed
day after downtime. Turning the daily schedule off leaves manual requests and
the saved retention policy available.

The worker acquires the existing request mutex exclusively, waits up to 30
seconds for earlier requests/workers to finish, then commits a durable claim.
New PHP requests receive a temporary HTTP 503 while the exclusive snapshot is
in progress. If requests do not drain, no snapshot starts. Two CLI workers
serialize through the same inode and cannot both capture one saved request.

The worker inventories the source, opens a consistent read-only repeatable-read
SQL transaction when required, streams file and database entries into an
XChaCha20-Poly1305 Secretstream container, checks the source inventory and schema
again, writes the authenticated final manifest, flushes the file, and reads the
entire encrypted archive back before publishing it and recording completion.
Names, sizes, SHA-256 entry digests, table row counts, full row digests and source
identity are inside the authenticated stream. A missing final tag, changed
frame, wrong key, truncated stream, appended data or inconsistent entry digest
is a verification failure.

A creator who is no longer an active owner has their queued work held. Restoring
their role later does not automatically revive that held attempt. A new owner
must review the situation and request new work.

A process can stop between publishing a complete encrypted file and committing
its journal result. On restart, the worker authenticates that exact file and
finishes its original claim once. It does not recapture newer source data under
the earlier identifier. If only an incomplete or conflicting partial file
exists, the attempt fails visibly and the encrypted bytes are preserved for an
operator's review. The current CLI has no automatic deletion of failed or held
partial files; remove those only after reviewing their exact recorded identity
and any external copies. Do not remove any `.lock` file.

Exit status `0` means the requested check finished, `2` reports a visible failed
or held attempt, and `1` means the worker itself could not finish. Retain cron
output in a private hosting log and alert on repeated nonzero exits. An idle
successful worker does not prove that cron is installed or that an off-site
copy exists.

### Retention and limits

Retention keeps **both** the newest configured number of successful archives
and **every** successful archive within the configured number of days. Only a
copy outside both sets is eligible. The worker authenticates the newest retained
copy and each candidate before deleting anything. A committed deletion intent
is restart-safe: an already removed file is recorded once, and a changed policy
cancels an unstarted deletion that is no longer eligible. Persistent gate,
configuration and journal lockfiles are never retention targets.

The archive boundary is 100,000 entries and 256 GiB of streamed content; a
logical SQL row may occupy at most 32 MiB encoded, and at most 1,000 ordinary
tables are supported. The private attempt journal keeps at most 1,000 attempts;
reaching that explicit boundary stops new attempts rather than evicting history
or unreviewed archives. Review/export the journal and establish a separate
operator-approved archival policy before this limit. Large installations or
long-term fleet retention should use the hosting backup service.

Excluded paths are declared in every encrypted header:

- `.git`, `.github`;
- `storage/backups`, `database/backups` and private repair backup trees;
- `storage/cache`, `storage/sessions`, `storage/temp`;
- the operations backup/work directories;
- the live operations `request.lock` and signed `gate.json`.

Other private operational journals, including notification, protection and
privacy records, remain included. The exclusion of session files means a
restored web browser must sign in again. SQL session records and other saved
security state remain part of the snapshot and must be reviewed or revoked
before activation.

## Verify and keep an off-site copy

Use the saved backup identifier from Operations:

```sh
php app/Console/run_backups.php --verify=BACKUP_ID
```

For an older external key, add `--key-file=/private/original-key`. Only an active
owner with a recent confirmation can download the ciphertext from Operations.
The download checks its saved SHA-256, exact size and regular-file identity.
Compare that SHA-256 after transferring the archive to protected off-site
storage. Keep the key in a separate security boundary. Neither a successful
local snapshot nor the application diagnostics certify the off-site transfer.

Historical snapshots can contain data later changed or deleted from the live
application. Apply the site's declared retention, legal holds and privacy
processes to local and off-site copies. A future recovery must reapply relevant
erasure and suppression records before making the restored copy live.

## Review and perform an isolated restore drill

A restore is a separate operation from downloading or verifying an archive.
Choose a new empty destination outside the source directory, its ancestors and
all public document roots. It must have an existing writable canonical parent.
Symlinks, special files, hard-linked files, path traversal, duplicate paths and
case-conflicting source paths are refused. Existing target files are never
cleared or overwritten, including files from a failed drill.

For a SQL archive, provision a **different empty schema with a different name**
on the same supported engine family. Give its dedicated account access only to
that schema. The name must differ from the currently configured live database
even if the server hostname differs; this conservative rule prevents aliases
from concealing the same live schema. The actual target server identity and
version are bound into the review. The live database does not need to be online
for this comparison. Do not switch the source configuration to the target.

Create a private, process-owned JSON file outside the website, mode 0600:

```json
{
  "host": "127.0.0.1",
  "port": 3306,
  "name": "separate_empty_restore",
  "user": "restore_only",
  "password": "REPLACE_IN_PRIVATE_FILE",
  "charset": "utf8mb4"
}
```

Review the complete authenticated archive and empty target without mutating it:

```sh
php app/Console/operations.php --restore-review --archive=/private/snapshot.vvxbak --key-file=/private/velvet-backup.key --target=/private/isolated-restore --target-db-file=/private/restore-db.json
```

Omit `--target-db-file` for a file-storage archive. Inspect the returned archive
hash, installation hash, target, actual engine/version, file/table/row counts
and plan hash. Then repeat those exact inputs:

```sh
php app/Console/operations.php --restore-apply --archive=/private/snapshot.vvxbak --key-file=/private/velvet-backup.key --target=/private/isolated-restore --target-db-file=/private/restore-db.json --plan=REVIEWED_PLAN --confirm-empty-target
```

Apply holds the shared configuration writer lock so the declared live
connection cannot change during the drill. It changes no source configuration
values. It first authenticates all entries again, rechecks both empty targets,
and creates `.vvx-restore-quarantine.json` before restoring files. Table rows use
native prepared inserts; generated columns are recomputed and then verified.
The completed target is independently read back for every file's literal bytes,
every row count/digest, schema definitions and foreign-key integrity. Unexpected
extra files also fail verification. Group/world write permissions are not
restored; the process owner retains access for review.

The final report records the archive and plan, actual source/target database
versions, file/table/row counts, and `quarantined: true`. Public PHP requests and
workers in the restored copy refuse to run while its quarantine marker exists.
A failed drill preserves the target under quarantine for investigation. There
is no automatic rollback that drops target tables and no automatic live swap.

### Moving from a proven drill to a live recovery

A successful isolated drill proves recoverable file/database contents. It does
not prove that a customer's DNS, TLS, CDN, database grants, filesystem mapping,
provider accounts or cron jobs are ready for cutover. The hosting operator must
prepare and review a separate activation plan, keep the restored copy outside
public service, and stop the old installation before redirecting traffic.

Review the following concrete bindings before removing any quarantine:

1. The copied `config/app.php` still contains the original database connection,
   base URL, SMTP/payment/provider settings and security keys. Update them only
   through a reviewed configuration change. Keep outbound providers and jobs
   disabled until the environment is confirmed. Preserve installation identity
   unless performing a separately reviewed migration.
2. SQL marketing storage binds both its authority setting and local required
   guard to the database name plus installation identity. A differently named
   restore database requires a deliberate, matching authority/guard migration;
   merely changing `db.name` must fail closed. Use the preserved matching name
   on the final replacement host or have an operator review that migration.
   Do not enable an old file fallback or delete the guard to bypass it.
3. Invalidate or review restored administrator/member sessions, pending
   deliveries, unknown SMTP outcomes, payment reconciliation, privacy erasures,
   suppression records and provider credentials. Do not replay unknown external
   effects automatically.
4. The live request gate and its signed marker were deliberately excluded.
   After the host has stopped the old runtime, use the reviewed CLI repair and
   first activation sequence on the chosen installation. Never delete a lock
   that a live process may hold. The restored copy cannot use Operations CLI
   while still quarantined; removing the quarantine is therefore a separate
   explicit host action after the activation review, with public service still
   disabled.
5. Verify the deployed runtime inventory, database migrations, private access
   rules, public URLs and a new isolated restore; then arrange cron, monitoring,
   off-site copies and controlled traffic cutover.

The application intentionally offers no “restore over the live site” button.
It cannot safely decide the host's traffic switch or resolve unknown external
payment/email outcomes on behalf of the operator.

## Recovery mode and damaged gates

The owner can enter or leave recovery mode using its current signed revision.
Public PHP requests and ordinary workers pause; explicit owner sign-in,
Security and Operations remain reachable with add-ons skipped before boot.
Invalid or missing configured gate files keep public requests held. If the
configuration itself is missing while a gate marker exists, restore the
matching configuration through the hosting recovery process first.

After stopping earlier PHP/cron workers, an operator can repair a damaged or
missing marker/lock:

```sh
php app/Console/operations.php --repair --quiesced
```

A valid existing request mutex inode is preserved. Repair leaves recovery mode
on and first activation pending; inspect the new status and complete activation
only after the process-drain prerequisite is met. A failed key check, database
outage or corrupt journal does not silently fall back to a different backend.

Diagnostics report Sodium/64-bit support, deployed runtime checksums, migration
checksums, installation/database identity, the gate, external-key accessibility,
private journal readability and local free space. Host prerequisites are
explicitly marked for operator review. Diagnostics do not send SMTP, call
payment providers, repair data, create a schedule or prove an off-site copy.

## Format references

The implementation uses PHP's Sodium Secretstream binding and requires its
final authentication tag and exact end-of-file. The database snapshot uses a
consistent read-only transaction with repeatable-read isolation, while the
application gate drains cooperating writers. See the primary references:

- [PHP Secretstream push](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-push.php)
- [PHP Secretstream pull](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-pull.php)
- [libsodium authenticated streams](https://libsodium.gitbook.io/doc/secret-key_cryptography/secretstream)
- [MySQL transaction syntax](https://dev.mysql.com/doc/refman/8.0/en/commit.html)
