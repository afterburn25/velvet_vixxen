# Architecture and Product Decisions

## Visual direction

The approved direction remains the neon-pink, magenta, black, and white Velvet Vixxen design. Hero and visual-footer scenes use a layered, non-destructive composer with separate desktop/tablet/mobile arrangements. Canva is optional for artwork; live navigation, text, forms, paywalls, commerce, members, and media players remain HTML/JavaScript.

## Installer and organization

Installation remains a **single responsive browser page**. It requires no installation key, accepts owner passwords with a **12-character** minimum, creates and migrates database tables, performs real filesystem probes, attempts safe permissions, never relies on `chmod 0777`, creates an install lock, and removes or securely disables `/install` after success.

Application files remain organized. Runtime state, private media, credentials, logs, sessions, add-on staging/quarantine contents, and generated secrets are excluded from source control.

## Members, entitlements, and payments

One resolver controls public, registered, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted access. Payment providers are replaceable adapters. The bundled provider remains sandbox-only. Production collection must be approved for the business category, hosted/tokenized, signed-webhook driven, and tested through purchases, renewals, declines, retries, cancellations, refunds, and chargebacks.

## Add-on package model

Optional functions install through ZIP packages without editing core files. Every package has a permanent reverse-domain-style ID, semantic version, compatibility range, entrypoint, permissions, dependencies/conflicts, license metadata, and integrity metadata.

Add-ons are executable PHP. Typical shared hosting cannot securely sandbox arbitrary PHP, so the platform uses layered controls rather than claiming isolation:

- canonical path and ZIP-entry validation;
- size and file-count limits;
- checksum verification;
- optional Ed25519 signatures against configured trusted public keys;
- owner acknowledgement for unsigned/untrusted packages;
- declared permissions enforced by extension APIs;
- dependency/conflict and compatibility checks;
- pre-change backups and migration rollback;
- boot-failure quarantine and global safe mode;
- lifecycle logs and diagnostics.

Invalid signatures and blocking findings are non-bypassable. Private signing keys never belong in customer packages or the public repository.

## Add-on lifecycle

Install validates/stages the package, applies owned migrations, moves files into protected storage, and records it. Activation is separate. Deactivation preserves data. Normal uninstall preserves registered data; purge uninstall removes settings/license state and rolls back owned migrations. Updates create backups and remain inactive for review. Rollback restores a backup and also leaves the add-on inactive.

## Extension API

The 0.6.x API exposes admin pages/navigation, public routes, frontend widgets, events, filters, services, namespaced settings, and scheduled tasks. New privileged APIs require a declared permission and documented compatibility behavior. Add-ons should not directly depend on undocumented core internals.

## Licensing boundary

0.6.0 supports free, trial, and signed offline commercial-license records. A license-server outage must never take down the website or destroy installed data. Online activation, site limits, customer downloads, and remote update entitlement belong to 0.6.1. Marketplace sales belong to 0.7.0.

## Repository truth

`afterburn25/velvet_vixxen` is the communication and source repository. `main` is last-known-good. A package is not represented as merged or CI-green until its complete source is pushed, tested, reviewed, and actually merged.
