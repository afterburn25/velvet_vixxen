# Architecture and Product Decisions

## Locked decisions

### Visual direction and editing

The approved direction is the full-width neon-pink, magenta, black, and white Velvet Vixxen design with rendered bedroom/studio backgrounds and open composition space for transparent Nikki cutouts. Hero and visual-footer scenes use a layered, non-destructive composer with independent desktop, tablet, and mobile arrangements. Canva is an optional template workflow; live navigation, text, forms, paywalls, commerce, member controls, and media players remain HTML and JavaScript.

### Installer

Installation is a single responsive browser page. It requires no installation key, accepts owner passwords of 12 or more characters, creates and migrates database tables, performs real filesystem probes, attempts only safe permissions, never relies on `chmod 0777`, creates an install lock, and removes or securely disables `/install` after success.

### Project organization

Application files remain in organized folders. The root contains only necessary entry and project files. Runtime state, private storage, logs, sessions, credentials, and private media are excluded from source control.

### Member security

Member and owner passwords have a 12-character minimum. Verification, reset, and device-session tokens are stored only as hashes. Password resets revoke active sessions. Repeated failed logins trigger temporary lockout. CSRF protection is required for authenticated writes.

### Entitlements

A single resolver determines access for public, registered, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted content. Public pages, protected-media delivery, member libraries, and commerce fulfillment use the same model. Ending a subscription does not remove separately purchased PPV content, active rentals before expiry, gifts, or complimentary access.

### Commerce and payments

Payment providers are replaceable adapters. The core stores orders, transactions, subscriptions, and entitlements but does not hard-code a production processor. The included sandbox adapter proves the lifecycle without representing a live connection.

A production adapter must:

- be approved for the site's business category;
- use hosted or tokenized payment collection rather than storing raw card data;
- keep credentials outside the public repository;
- verify signed, replay-safe webhooks;
- map successful purchases and renewals to explicit subscriptions/entitlements;
- map refunds and chargebacks to access revocation;
- pass purchase, recurring renewal, decline, retry, cancellation, refund, and chargeback tests.

The local billing runner may simulate sandbox renewals only. It must never fabricate a successful renewal for a non-sandbox provider; those renewals wait for provider webhooks.

### Tax and legal configuration

The platform provides configurable tax percentage and currency fields, but it does not claim to calculate every jurisdiction's tax obligations. The site owner must configure lawful settings and obtain appropriate accounting/legal review.

### Add-ons

New functions install through validated add-on ZIP packages without editing core files. Add-ons use versioned extension points, declared permissions, dependencies, migrations, safe updates, backup, rollback, and recovery. Arbitrary untrusted PHP cannot be fully sandboxed on typical shared hosting, so package signatures, permissions, trusted sources, safe mode, and rollback are mandatory.

### Repository and release truth

`afterburn25/velvet_vixxen` is the communication and source-of-truth repository. `main` is last-known-good; active work uses feature branches. Tests, roadmap, status, decisions, and handoffs remain current.

A release package is not described as merged or authoritative on `main` until it has actually been pushed, CI-tested, reviewed, and merged. Packaged/local test evidence and GitHub branch/CI evidence must be reported separately.

Large generated ZIPs, Canva binaries, and screenshot images are release artifacts rather than repeated source-history commits. The repository retains lightweight source, manifests, exact checksums, test evidence, and links/tracking issues.
