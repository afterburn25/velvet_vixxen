<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AddonPackageInspector
{
    public const MAX_PACKAGE_BYTES = 25 * 1024 * 1024;
    public const MAX_FILES = 2500;
    public const MAX_UNCOMPRESSED_BYTES = 150 * 1024 * 1024;

    /** @return array<string,mixed> */
    public function inspect(string $zipPath, ?array $installed = null): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('The selected add-on package could not be found.');
        }
        $size = filesize($zipPath);
        if (!is_int($size) || $size < 1 || $size > self::MAX_PACKAGE_BYTES) {
            throw new RuntimeException('The add-on ZIP is empty or exceeds the 25 MB package limit.');
        }
        $zip = new AddonArchive($zipPath);
        $archiveEntries = $zip->entries();
        if (count($archiveEntries) < 1 || count($archiveEntries) > self::MAX_FILES) {
                throw new RuntimeException('The add-on package contains an unsupported number of files.');
        }
        $entries = [];
        $total = 0;
        foreach ($archiveEntries as $stat) {
                $name = $this->normalizeEntry((string) $stat['name']);
                $isDirectory = (bool) $stat['directory'];
                $uncompressed = max(0, (int) ($stat['size'] ?? 0));
                $total += $uncompressed;
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('The add-on package expands beyond the 150 MB safety limit.');
                }
                if (!empty($stat['symlink'])) {
                    throw new RuntimeException('Symbolic links are not allowed in add-on packages.');
                }
                if (!$isDirectory) {
                    $entries[$name] = [
                        'name' => $name,
                        'size' => $uncompressed,
                        'compressed' => max(0, (int) ($stat['compressed'] ?? 0)),
                        'crc' => (string) ($stat['crc'] ?? ''),
                    ];
                }
        }

            if (!isset($entries['addon.json'])) {
                throw new RuntimeException('The ZIP must contain addon.json at its root.');
            }
            $manifestRaw = $zip->get('addon.json');
            if (!is_string($manifestRaw) || strlen($manifestRaw) > 256 * 1024) {
                throw new RuntimeException('The add-on manifest is missing or too large.');
            }
            try {
                $manifestData = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException('addon.json is not valid JSON: ' . $e->getMessage(), 0, $e);
            }
            if (!is_array($manifestData)) {
                throw new RuntimeException('addon.json must contain a JSON object.');
            }
            $manifest = AddonManifest::fromArray($manifestData);
            if (!$manifest->supportsCore(VELVET_VIXXEN_VERSION)) {
                throw new RuntimeException('This add-on does not support Velvet Vixxen ' . VELVET_VIXXEN_VERSION . '.');
            }
            if (!$manifest->supportsPhp()) {
                throw new RuntimeException('This add-on requires PHP ' . $manifestData['minimum_php_version'] . ' or newer.');
            }
            $bootstrap = $manifest->bootstrap();
            if ($bootstrap !== '' && !isset($entries[$bootstrap])) {
                throw new RuntimeException('The manifest bootstrap file is missing from the package: ' . $bootstrap);
            }

            $checksumStatus = $this->verifyChecksums($zip, $entries);
            $signatureStatus = $this->verifySignature($zip);
            $packageSha = hash_file('sha256', $zipPath);
            if (!is_string($packageSha)) {
                throw new RuntimeException('The package checksum could not be calculated.');
            }

            $installedVersion = trim((string) ($installed['version'] ?? ''));
            $operation = $installedVersion === '' ? 'install' : 'update';
            if ($installedVersion !== '') {
                $comparison = version_compare($manifest->version(), $installedVersion);
                $operation = $comparison > 0 ? 'update' : ($comparison === 0 ? 'repair' : 'rollback');
            }

            $warnings = [];
            if ($signatureStatus['status'] === 'unsigned') {
                $warnings[] = 'Package is unsigned. Install only when you trust its source and understand that PHP add-ons execute server-side code.';
            }
            if ($operation === 'repair') {
                $warnings[] = 'Package version ' . $manifest->version() . ' is not newer than the installed version. It can still be installed as an explicit repair.';
            } elseif ($operation === 'rollback') {
                $warnings[] = 'This package is older than the installed version and will perform an explicit rollback.';
            }
            if ($checksumStatus['status'] === 'missing') {
                $warnings[] = 'The package does not include checksums.json. The ZIP checksum is recorded, but individual files cannot be verified against a publisher manifest.';
            }

            return [
                'manifest' => $manifest->toArray(),
                'packagePath' => $zipPath,
                'packageSha256' => $packageSha,
                'packageBytes' => $size,
                'fileCount' => count($entries),
                'uncompressedBytes' => $total,
                'files' => array_values($entries),
                'checksums' => $checksumStatus,
                'signature' => $signatureStatus,
                'operation' => $operation,
                'installedVersion' => $installedVersion,
                'permissions' => AddonPermissionCatalog::describe($manifest->permissions()),
                'warnings' => $warnings,
                'ready' => true,
            ];
    }

    /** @param array<string,array<string,mixed>> $entries @return array{status:string,verified:int,total:int} */
    private function verifyChecksums(AddonArchive $zip, array $entries): array
    {
        if (!isset($entries['checksums.json'])) {
            return ['status' => 'missing', 'verified' => 0, 'total' => max(0, count($entries) - 1)];
        }
        $raw = $zip->get('checksums.json');
        if (!is_string($raw) || strlen($raw) > 1024 * 1024) {
            throw new RuntimeException('checksums.json is unreadable or too large.');
        }
        try {
            $checksums = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('checksums.json is not valid JSON.', 0, $e);
        }
        if (!is_array($checksums)) {
            throw new RuntimeException('checksums.json must contain a JSON object.');
        }
        $verified = 0;
        foreach ($checksums as $path => $expected) {
            $path = $this->normalizeEntry((string) $path);
            if ($path === 'checksums.json' || $path === 'signature.json') {
                continue;
            }
            if (!isset($entries[$path]) || !is_string($expected) || preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1) {
                throw new RuntimeException('checksums.json references an invalid or missing file: ' . $path);
            }
            $contents = $zip->get($path);
            if (!is_string($contents) || !hash_equals(strtolower($expected), hash('sha256', $contents))) {
                throw new RuntimeException('Checksum verification failed for ' . $path . '.');
            }
            $verified++;
        }
        foreach ($entries as $path => $_entry) {
            if (in_array($path, ['checksums.json', 'signature.json'], true)) {
                continue;
            }
            if (!array_key_exists($path, $checksums)) {
                throw new RuntimeException('checksums.json does not cover every package file. Missing: ' . $path);
            }
        }
        return ['status' => 'verified', 'verified' => $verified, 'total' => $verified];
    }

    /** @return array{status:string,algorithm:string,publicKey:string} */
    private function verifySignature(AddonArchive $zip): array
    {
        $raw = $zip->get('signature.json');
        if (!is_string($raw)) {
            return ['status' => 'unsigned', 'algorithm' => '', 'publicKey' => ''];
        }
        try {
            $signature = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('signature.json is not valid JSON.', 0, $e);
        }
        if (!is_array($signature) || ($signature['algorithm'] ?? '') !== 'ed25519') {
            throw new RuntimeException('Only Ed25519 add-on signatures are supported.');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('This package is signed, but the PHP sodium extension is unavailable for verification.');
        }
        $checksums = $zip->get('checksums.json');
        if (!is_string($checksums)) {
            throw new RuntimeException('Signed packages must contain checksums.json.');
        }
        $publicKey = base64_decode((string) ($signature['public_key'] ?? ''), true);
        $detached = base64_decode((string) ($signature['signature'] ?? ''), true);
        if (!is_string($publicKey) || !is_string($detached)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($detached) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($detached, $checksums, $publicKey)) {
            throw new RuntimeException('The add-on package signature could not be verified.');
        }
        return [
            'status' => 'verified',
            'algorithm' => 'ed25519',
            'publicKey' => base64_encode($publicKey),
        ];
    }

    private function normalizeEntry(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#/+#', '/', $name) ?? $name;
        $name = rtrim($name, '/');
        if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) === 1 || str_contains($name, "\0")) {
            throw new RuntimeException('The add-on package contains an unsafe path.');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The add-on package contains path traversal.');
            }
        }
        return $name;
    }

}
