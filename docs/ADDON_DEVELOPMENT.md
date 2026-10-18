# Velvet Vixxen Add-on Development

Velvet Vixxen 0.6.0 extends the platform through validated ZIP packages without editing core files.

## Security boundary

Add-ons execute server-side PHP. Validation, permissions, checksums, optional Ed25519 signatures, backups, quarantine, safe mode, and rollback reduce risk but do not make untrusted code safe. Install only trusted packages. Never package private signing keys, `.env` files, credentials, customer data, or private media.

## Package layout

```text
my-addon/
├── addon.json
├── checksums.json
├── signature.json          optional
├── src/MyAddon.php
├── migrations/             optional
├── views/                  optional
├── assets/                 optional
├── tests/                  recommended
├── README.md
└── CHANGELOG.md             recommended
```

The ZIP may contain one enclosing directory. Absolute paths, backslashes, null bytes, `.`/`..` segments, traversal, duplicate entries, and detectable symbolic links are rejected.

## Manifest essentials

```json
{
  "schema": 1,
  "id": "vendor.feature",
  "name": "Feature Name",
  "version": "1.0.0",
  "vendor": "Vendor Name",
  "minimum_core_version": "0.6.0",
  "maximum_core_version": "0.9.99",
  "minimum_php_version": "8.2.0",
  "entrypoint": "src/FeatureAddon.php",
  "class": "Vendor\\Feature\\FeatureAddon",
  "permissions": ["admin.menu.register", "admin.page.render"],
  "dependencies": {},
  "conflicts": [],
  "license": {"type": "free"},
  "update": {"channel": "stable"}
}
```

IDs are permanent reverse-domain-style keys. Versions use semantic versioning.

## Entrypoint

The class implements `App\Addons\AddonInterface` and provides `register(AddonContext $context)` and `boot(AddonContext $context)`. `register()` declares extension points. `boot()` must remain lightweight.

## Available APIs

Declared permissions can expose:

- admin navigation and pages;
- public routes;
- homepage widgets;
- core event subscriptions;
- filters, including `commerce.quote`;
- registered services;
- namespaced settings;
- scheduled tasks;
- add-on-owned migrations.

Calling an extension API without its declared permission throws an exception and may quarantine the add-on.

## Lifecycle

- Install validates, stages, migrates, moves, and records the package.
- Activation is separate.
- Deactivation preserves files/settings/data.
- Update creates a backup and leaves the add-on inactive for review.
- Rollback restores a backup and leaves it inactive.
- Normal uninstall preserves registered data.
- Purge uninstall removes settings/license state and rolls back owned migrations.
- Boot failure quarantines the add-on.
- Safe mode bypasses all optional add-ons.

## Tasks

Supported schedules are `hourly`, `daily`, `weekly`, and `every:SECONDS` with a 60-second minimum.

```bash
php scripts/run_addons.php --list
php scripts/run_addons.php --force
php scripts/run_addons.php --task=vendor.feature:task-name
```

## Starter

The packaged build includes `/addons/sdk/starter-addon` and `velvet-welcome-banner-1.0.0.zip`, demonstrating an admin page, public route, homepage widget, settings, event listener, service, and scheduled task.

## Licensing

0.6.0 supports free, trial, and signed offline commercial-license records. Online activation, site limits, customer downloads, remote update feeds, and the marketplace are planned for 0.6.1–0.7.0.
