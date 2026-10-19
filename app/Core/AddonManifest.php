<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AddonManifest
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = strtolower(trim((string) ($data['id'] ?? '')));
        if ($id === '' || preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $id) !== 1) {
            throw new RuntimeException('The add-on manifest contains an invalid id. Use lowercase letters, numbers, dots, underscores, or hyphens.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || (function_exists('mb_strlen') ? \mb_strlen($name) : strlen($name)) > 190) {
            throw new RuntimeException('The add-on manifest must include a valid name.');
        }
        $version = trim((string) ($data['version'] ?? ''));
        if (!self::validSemver($version)) {
            throw new RuntimeException('The add-on version must use semantic versioning, such as 1.0.0.');
        }
        $vendor = trim((string) ($data['vendor'] ?? ''));
        if ($vendor === '') {
            throw new RuntimeException('The add-on manifest must identify its vendor.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ((function_exists('mb_strlen') ? \mb_strlen($description) : strlen($description)) > 1000) {
            throw new RuntimeException('The add-on description is too long.');
        }
        $minimumCore = trim((string) ($data['minimum_core_version'] ?? VELVET_VIXXEN_VERSION));
        $maximumCore = trim((string) ($data['maximum_core_version'] ?? ''));
        $minimumPhp = trim((string) ($data['minimum_php_version'] ?? '8.2.0'));
        if (!self::validSemver($minimumCore) || ($maximumCore !== '' && !self::validConstraintVersion($maximumCore)) || !self::validSemver($minimumPhp)) {
            throw new RuntimeException('The add-on manifest contains an invalid core or PHP compatibility version.');
        }

        $permissions = array_values(array_unique(array_map('strval', (array) ($data['permissions'] ?? []))));
        foreach ($permissions as $permission) {
            if (!AddonPermissionCatalog::exists($permission)) {
                throw new RuntimeException('The add-on requests an unknown permission: ' . $permission);
            }
        }

        $dependencies = self::normalizePackageConstraints((array) ($data['dependencies'] ?? []), 'dependency');
        $conflicts = self::normalizePackageConstraints((array) ($data['conflicts'] ?? []), 'conflict');
        if (isset($dependencies[$id]) || isset($conflicts[$id])) {
            throw new RuntimeException('An add-on cannot depend on or conflict with itself.');
        }

        $bootstrap = self::safeRelativePath((string) ($data['bootstrap'] ?? 'src/bootstrap.php'), true);
        $admin = self::normalizeRegistrations((array) ($data['admin'] ?? []));
        $routes = self::normalizeRegistrations((array) ($data['routes'] ?? []));
        $widgets = self::normalizeRegistrations((array) ($data['widgets'] ?? []));
        $tasks = self::normalizeRegistrations((array) ($data['tasks'] ?? []));
        $licenseType = strtolower(trim((string) ($data['license_type'] ?? 'free')));
        if (!in_array($licenseType, ['free','trial','one_time','monthly','annual','lifetime','commercial'], true)) {
            throw new RuntimeException('The add-on manifest contains an unsupported license_type.');
        }
        $licensing = self::normalizeLicensing((array) ($data['licensing'] ?? []), $licenseType, $id);
        $updates = self::normalizeUpdates((array) ($data['updates'] ?? []), $licensing);

        $normalized = [
            'schema' => max(1, (int) ($data['schema'] ?? 1)),
            'id' => $id,
            'name' => $name,
            'vendor' => $vendor,
            'version' => $version,
            'description' => $description,
            'minimum_core_version' => $minimumCore,
            'maximum_core_version' => $maximumCore,
            'minimum_php_version' => $minimumPhp,
            'permissions' => $permissions,
            'dependencies' => $dependencies,
            'conflicts' => $conflicts,
            'bootstrap' => $bootstrap,
            'admin' => $admin,
            'routes' => $routes,
            'widgets' => $widgets,
            'tasks' => $tasks,
            'license' => trim((string) ($data['license'] ?? 'proprietary')),
            'license_type' => $licenseType,
            'licensing' => $licensing,
            'updates' => $updates,
            'homepage' => trim((string) ($data['homepage'] ?? '')),
            'support_url' => trim((string) ($data['support_url'] ?? '')),
        ];
        return new self($normalized);
    }

    public function id(): string { return (string) $this->data['id']; }
    public function name(): string { return (string) $this->data['name']; }
    public function version(): string { return (string) $this->data['version']; }
    public function vendor(): string { return (string) $this->data['vendor']; }
    /** @return list<string> */ public function permissions(): array { return (array) $this->data['permissions']; }
    /** @return array<string,string> */ public function dependencies(): array { return (array) $this->data['dependencies']; }
    /** @return array<string,string> */ public function conflicts(): array { return (array) $this->data['conflicts']; }
    public function bootstrap(): string { return (string) $this->data['bootstrap']; }
    public function licenseType(): string { return (string) $this->data['license_type']; }
    /** @return array<string,mixed> */ public function licensing(): array { return (array) $this->data['licensing']; }
    /** @return array<string,mixed> */ public function updates(): array { return (array) $this->data['updates']; }
    public function requiresLicense(): bool { return !empty($this->data['licensing']['activation_required']); }
    /** @return array<string,mixed> */ public function toArray(): array { return $this->data; }

    public function supportsCore(string $version): bool
    {
        if (version_compare($version, (string) $this->data['minimum_core_version'], '<')) {
            return false;
        }
        $maximum = (string) $this->data['maximum_core_version'];
        if ($maximum === '') {
            return true;
        }
        if (str_ends_with($maximum, '.x')) {
            $parts = array_map('intval', explode('.', substr($maximum, 0, -2)));
            $major = $parts[0] ?? 0;
            $minor = $parts[1] ?? 0;
            $exclusive = $major . '.' . ($minor + 1) . '.0';
            return version_compare($version, $exclusive, '<');
        }
        return version_compare($version, $maximum, '<=');
    }

    public function supportsPhp(string $version = PHP_VERSION): bool
    {
        return version_compare($version, (string) $this->data['minimum_php_version'], '>=');
    }

    public static function safeRelativePath(string $path, bool $allowEmpty = false): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path === '' && $allowEmpty) {
            return '';
        }
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException('The add-on manifest contains an invalid file path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new RuntimeException('The add-on manifest contains an unsafe file path.');
            }
        }
        return $path;
    }

    private static function validSemver(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function validConstraintVersion(string $version): bool
    {
        return self::validSemver($version) || preg_match('/^\d+\.\d+\.x$/', $version) === 1;
    }

    /** @param array<mixed> $items @return array<string,string> */
    private static function normalizePackageConstraints(array $items, string $label): array
    {
        $normalized = [];
        if (array_is_list($items)) {
            foreach ($items as $item) {
                if (is_string($item)) {
                    $normalized[strtolower(trim($item))] = '*';
                } elseif (is_array($item)) {
                    $key = strtolower(trim((string) ($item['id'] ?? '')));
                    $normalized[$key] = trim((string) ($item['version'] ?? '*')) ?: '*';
                }
            }
        } else {
            foreach ($items as $key => $constraint) {
                $normalized[strtolower(trim((string) $key))] = trim((string) $constraint) ?: '*';
            }
        }
        foreach ($normalized as $key => $constraint) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $key) !== 1) {
                throw new RuntimeException('The add-on manifest contains an invalid ' . $label . ' id.');
            }
            if ((function_exists('mb_strlen') ? \mb_strlen($constraint) : strlen($constraint)) > 64) {
                throw new RuntimeException('The add-on manifest contains an invalid ' . $label . ' version constraint.');
            }
        }
        return $normalized;
    }


    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function normalizeLicensing(array $data, string $licenseType, string $addonId): array
    {
        $serverUrl = self::normalizeServiceUrl((string) ($data['server_url'] ?? ''));
        $publicKey = trim((string) ($data['public_key'] ?? ''));
        if ($publicKey !== '') {
            $decoded = base64_decode($publicKey, true);
            if (!is_string($decoded) || strlen($decoded) !== 32) {
                throw new RuntimeException('The add-on licensing public key must be a base64 Ed25519 public key.');
            }
        }
        $productId = strtolower(trim((string) ($data['product_id'] ?? $addonId)));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $productId) !== 1) {
            throw new RuntimeException('The add-on licensing product_id is invalid.');
        }
        $requires = array_key_exists('activation_required', $data)
            ? (bool) $data['activation_required']
            : !in_array($licenseType, ['free'], true);
        return [
            'product_id' => $productId,
            'server_url' => $serverUrl,
            'public_key' => $publicKey,
            'activation_required' => $requires,
            'grace_days' => max(0, min(90, (int) ($data['grace_days'] ?? 7))),
        ];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $licensing @return array<string,mixed> */
    private static function normalizeUpdates(array $data, array $licensing): array
    {
        $feedUrl = self::normalizeServiceUrl((string) ($data['feed_url'] ?? ''));
        $channel = strtolower(trim((string) ($data['channel'] ?? 'stable')));
        if (!in_array($channel, ['stable','beta','development'], true)) {
            throw new RuntimeException('The add-on update channel must be stable, beta, or development.');
        }
        $publicKey = trim((string) ($data['public_key'] ?? ($licensing['public_key'] ?? '')));
        if ($publicKey !== '') {
            $decoded = base64_decode($publicKey, true);
            if (!is_string($decoded) || strlen($decoded) !== 32) {
                throw new RuntimeException('The add-on update public key must be a base64 Ed25519 public key.');
            }
        }
        return [
            'feed_url' => $feedUrl,
            'channel' => $channel,
            'public_key' => $publicKey,
            'automatic' => !empty($data['automatic']),
        ];
    }

    private static function normalizeServiceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http','https'], true)) {
            throw new RuntimeException('The add-on licensing or update service URL is invalid.');
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $local = in_array($host, ['localhost','127.0.0.1','::1'], true) || str_ends_with($host, '.test');
        if (strtolower((string) $parts['scheme']) !== 'https' && !$local) {
            throw new RuntimeException('Remote add-on licensing and update URLs must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Credentials must not be embedded in add-on service URLs.');
        }
        return rtrim($url, '/');
    }

    /** @param array<mixed> $items @return list<array<string,mixed>> */
    private static function normalizeRegistrations(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $copy = $item;
            foreach (['view', 'handler', 'file'] as $pathKey) {
                if (isset($copy[$pathKey])) {
                    $copy[$pathKey] = self::safeRelativePath((string) $copy[$pathKey]);
                }
            }
            $normalized[] = $copy;
        }
        return $normalized;
    }
}
