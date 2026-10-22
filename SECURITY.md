# Security Policy

<!-- road-to-1.0-integration:start -->
## 0.9.0 security and operational boundary

**Verified, merged and delivered.** **0.9.0 / Build 20261021.4** passed the complete frozen workflow, merged through [PR #35](https://github.com/afterburn25/velvet_vixxen/pull/35), and has byte-verified saved customer downloads. The [release record](docs/releases/0.9.0-safeguards.md), [CI evidence](docs/releases/0.9.0-safeguards-ci.json) and [delivery evidence](docs/releases/0.9.0-safeguards-delivery.json) bind the exact source, packages, merge and readback. Customer-host and live-provider acceptance remain separate.

The release includes owner authenticator/recovery codes, revocable sessions and recent authentication; revision-checked atomic private state; strict owner, CSRF and signed-event boundaries; member exports and reviewed closure; authenticated mail feedback; encrypted operational backups; controlled protected-media delivery and reporting; and configured age/location/policy controls. Actual file/MySQL/MariaDB, Apache, interruption and browser evidence is in [the release record](docs/releases/0.9.0-safeguards.md).

Keep configured activation features deliberate. Public policies require the owner's reviewed content and jurisdictional decisions; draft templates and local protocol tests do not establish legal compliance. Protected delivery requires actual origin/CDN denial checks. Export and financial-retention decisions remain private operational responsibilities. Unknown external delivery outcomes stay held, and current privacy/authorization state must be checked before any resumed effect.

Payments remain sandbox-only in 0.9.0. The separate **0.9.1 / Build 20261022.1** provider-independent payment-journal and owner-recovery candidate is still in development; a real processor requires its actual adapter, merchant approval and lifecycle acceptance. Production email, age-provider credentials, customer-host installation/upgrade/rollback, cron and isolated host restore remain separately unverified. Preserve [the update procedure](docs/UPDATE_VERIFICATION.md), including `admin/repair-current.php` for cached older installations.
<!-- road-to-1.0-integration:end -->

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
