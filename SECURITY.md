# Security Policy

## Reporting

Do not open a public issue containing an exploitable vulnerability, credentials, customer/identity/payment data, private media, private messages, or signing material. Contact the repository owner privately with the minimum evidence needed to reproduce the problem.

## Never commit

- production database, SMTP, payment, webhook, storage, or licensing credentials;
- generated application/media-signing secrets;
- Ed25519 private signing keys;
- customer, member, billing, identity, release, message, or device records;
- private or paid media;
- installed configuration, sessions, logs, cache, temporary files, add-on staging/quarantine runtime contents, or install/safe-mode locks.

## Add-on boundary

Add-ons are executable PHP. Package scanning, checksums, optional signatures, permission declarations, dependency checks, backups, safe mode, quarantine, and rollback reduce risk but do not fully sandbox untrusted code. Install only packages from trusted sources. Invalid signatures and blocking package findings must remain non-bypassable.

## Payment boundary

The bundled provider is sandbox-only. Production payment collection must use an approved hosted/tokenized provider adapter. Raw card numbers must never be stored by Velvet Vixxen.
