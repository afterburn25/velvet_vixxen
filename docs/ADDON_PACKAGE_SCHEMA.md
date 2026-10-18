# Add-on Package Schema 1

## Required manifest fields

- `schema`: integer `1`
- `id`: permanent reverse-domain-style identifier such as `vendor.feature`
- `name`
- `version`: semantic version
- `vendor`
- `description`
- `minimum_core_version`
- `minimum_php_version`
- `entrypoint`: relative PHP path
- `class`: fully qualified class implementing `App\Addons\AddonInterface`
- `permissions`: array of supported permission keys

## Optional fields

- `maximum_core_version`
- `dependencies`: object mapping add-on IDs to version constraints
- `conflicts`: array of add-on IDs
- `license`: free/trial/commercial metadata
- `update`: channel and future remote-update metadata
- `metadata.autoload.psr4`

## Package integrity

`checksums.json` maps each package file except itself and `signature.json` to its SHA-256 value. Missing or mismatched declared checksums block installation.

Commercial distribution may include `signature.json` with an Ed25519 key ID and Base64 signature over the canonical checksum payload. The corresponding public key must be configured in `addons.trusted_keys`. Private signing keys must never be distributed.

## Supported permissions

- `admin.menu.register`
- `admin.page.render`
- `public.route.register`
- `frontend.widget.register`
- `events.subscribe`
- `filters.register`
- `services.register`
- `tasks.schedule`
- `media.read`
- `media.extend`
- `members.read`
- `commerce.read`
- `commerce.extend`
- `email.send`
- `settings.read`
- `settings.write`
- `database.migrate`
- `external.network`

The framework rejects unknown permissions. APIs enforce declared permissions at runtime.

## Package rejection rules

Installation is blocked for traversal/absolute/drive/backslash/null-byte paths, `.`/`..` segments, duplicate entries, detectable symbolic links, excess file count/size, private signing keys, environment files, invalid checksums, invalid signatures, incompatible core/PHP versions, invalid dependency IDs, or unsupported manifest values.

Unsigned and valid-but-untrusted packages require explicit owner acknowledgement unless local policy permits unsigned packages. Invalid signatures are never bypassable.
