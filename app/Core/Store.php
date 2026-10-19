<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Store
{
    use MediaStoreTrait;
    use MemberStoreTrait;
    use CommerceStoreTrait;
    use AddonStoreTrait;
    use AddonLicensingStoreTrait;
    use CommunicationStoreTrait;

    private readonly string $mode;
    private ?Database $database = null;
    private readonly string $filePath;

    public function __construct(private readonly Config $config)
    {
        $this->mode = (string) $config->get('storage_mode', 'mysql');
        $this->filePath = root_path((string) $config->get('file_store_path', 'storage/data.json'));
        if ($this->mode === 'mysql') {
            $this->database = new Database($config);
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return $data['settings'][$key] ?? $default;
        }
        $stmt = $this->pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function setSetting(string $key, mixed $value): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['settings'][$key] = $value;
            $this->writeFileStore($data);
            return;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
        $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    public function scene(string $section = 'hero', bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $scene = $data['scenes'][$section] ?? [];
            if ($publishedOnly && isset($scene['published'])) {
                return (array) $scene['published'];
            }
            return (array) ($scene['draft'] ?? $scene['published'] ?? []);
        }
        $status = $publishedOnly ? 'published' : 'draft';
        $stmt = $this->pdo()->prepare('SELECT scene_json FROM scenes WHERE section_key = ? AND status = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$section, $status]);
        $json = $stmt->fetchColumn();
        if ($json === false && !$publishedOnly) {
            $stmt->execute([$section, 'published']);
            $json = $stmt->fetchColumn();
        }
        return $json !== false ? safe_json_decode((string) $json) : [];
    }

    public function saveScene(string $section, array $scene, bool $publish = false, ?int $adminId = null): int|string
    {
        $scene['section'] = $section;
        $scene['updatedAt'] = now_iso();
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $current = $data['scenes'][$section] ?? ['draft' => [], 'published' => [], 'versions' => []];
            $current['draft'] = $scene;
            if ($publish) {
                $current['published'] = $scene;
                $current['versions'][] = [
                    'id' => bin2hex(random_bytes(6)),
                    'publishedAt' => now_iso(),
                    'scene' => $scene,
                ];
                $current['versions'] = array_slice($current['versions'], -20);
            }
            $data['scenes'][$section] = $current;
            $this->writeFileStore($data);
            return (string) ($current['versions'][array_key_last($current['versions'])]['id'] ?? 'draft');
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $status = $publish ? 'published' : 'draft';
            if (!$publish) {
                $delete = $pdo->prepare("DELETE FROM scenes WHERE section_key = ? AND status = 'draft'");
                $delete->execute([$section]);
            }
            $stmt = $pdo->prepare('INSERT INTO scenes (section_key, status, scene_json, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$section, $status, json_encode($scene, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $adminId]);
            $id = (int) $pdo->lastInsertId();
            if ($publish) {
                $pdo->prepare("DELETE FROM scenes WHERE section_key = ? AND status = 'draft'")->execute([$section]);
                $copy = $pdo->prepare('INSERT INTO scenes (section_key, status, scene_json, created_by, created_at, updated_at) VALUES (?, \'draft\', ?, ?, NOW(), NOW())');
                $copy->execute([$section, json_encode($scene, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $adminId]);
                $version = $pdo->prepare('INSERT INTO scene_versions (scene_id, section_key, scene_json, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
                $version->execute([$id, $section, json_encode($scene, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $adminId]);
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function sceneVersions(string $section, int $limit = 20): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return array_reverse(array_slice((array) ($data['scenes'][$section]['versions'] ?? []), -$limit));
        }
        $stmt = $this->pdo()->prepare('SELECT id, scene_json, created_at FROM scene_versions WHERE section_key = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, 50)));
        $stmt->execute([$section]);
        return array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'publishedAt' => $row['created_at'],
            'scene' => safe_json_decode((string) $row['scene_json']),
        ], $stmt->fetchAll());
    }

    public function restoreScene(string $section, string|int $versionId, ?int $adminId = null): array
    {
        $versions = $this->sceneVersions($section, 50);
        foreach ($versions as $version) {
            if ((string) ($version['id'] ?? '') === (string) $versionId) {
                $scene = (array) ($version['scene'] ?? []);
                $this->saveScene($section, $scene, true, $adminId);
                return $scene;
            }
        }
        throw new RuntimeException('The selected composition version could not be found.');
    }

    public function media(): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['media'] ?? []));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }
        $rows = $this->pdo()->query('SELECT * FROM media_assets WHERE deleted_at IS NULL ORDER BY id DESC')->fetchAll();
        return array_map(fn(array $row): array => $this->mapMediaRow($row), $rows);
    }

    public function addMedia(array $asset): array
    {
        $asset = array_merge([
            'id' => bin2hex(random_bytes(8)),
            'title' => 'Untitled media',
            'type' => 'model',
            'file' => '',
            'thumbnail' => '',
            'mime' => '',
            'size' => 0,
            'width' => 0,
            'height' => 0,
            'duration' => null,
            'private' => false,
            'tags' => [],
            'metadata' => [],
            'createdAt' => now_iso(),
        ], $asset);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['media'][(string) $asset['id']] = $asset;
            $this->writeFileStore($data);
            return $asset;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO media_assets (title, asset_type, file_path, thumbnail_path, mime_type, file_size, width, height, duration_seconds, tags_json, metadata_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([
            $asset['title'], $asset['type'], $asset['file'], $asset['thumbnail'] ?: null,
            $asset['mime'] ?: null, (int) $asset['size'], (int) $asset['width'], (int) $asset['height'],
            $asset['duration'], json_encode($asset['tags']), json_encode($asset['metadata']),
        ]);
        $asset['id'] = (string) $this->pdo()->lastInsertId();
        return $asset;
    }

    public function templates(): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return array_values((array) ($data['templates'] ?? []));
        }
        $rows = $this->pdo()->query('SELECT * FROM canva_templates ORDER BY sort_order, id')->fetchAll();
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'title' => $row['title'],
            'category' => $row['category'],
            'device' => $row['device_target'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'canvaUrl' => $row['canva_url'],
            'preview' => $row['preview_path'],
            'export' => $row['export_path'],
            'starterFile' => $row['starter_file'],
            'updatedAt' => $row['updated_at'],
        ], $rows);
    }

    public function saveTemplate(array $template): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $id = (string) ($template['id'] ?? bin2hex(random_bytes(6)));
            $existing = (array) ($data['templates'][$id] ?? []);
            $template = array_merge($existing, $template, ['id' => $id, 'updatedAt' => now_iso()]);
            $data['templates'][$id] = $template;
            $this->writeFileStore($data);
            return $template;
        }
        $id = isset($template['id']) && ctype_digit((string) $template['id']) ? (int) $template['id'] : 0;
        if ($id > 0) {
            $stmt = $this->pdo()->prepare('UPDATE canva_templates SET title=?, category=?, device_target=?, width=?, height=?, canva_url=?, preview_path=?, export_path=?, starter_file=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([
                $template['title'], $template['category'], $template['device'], $template['width'], $template['height'],
                $template['canvaUrl'] ?? null, $template['preview'] ?? null, $template['export'] ?? null,
                $template['starterFile'] ?? null, $id,
            ]);
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO canva_templates (title, category, device_target, width, height, canva_url, preview_path, export_path, starter_file, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                $template['title'], $template['category'], $template['device'], $template['width'], $template['height'],
                $template['canvaUrl'] ?? null, $template['preview'] ?? null, $template['export'] ?? null,
                $template['starterFile'] ?? null, $template['sortOrder'] ?? 100,
            ]);
            $id = (int) $this->pdo()->lastInsertId();
        }
        $template['id'] = (string) $id;
        $template['updatedAt'] = now_iso();
        return $template;
    }

    public function findAdminByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['admins'] ?? []) as $admin) {
                if (strtolower((string) ($admin['email'] ?? '')) === $email) {
                    return $admin;
                }
            }
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT id, name, email, password_hash, role, is_active FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function createAdmin(string $name, string $email, string $password): int|string
    {
        $admin = [
            'id' => bin2hex(random_bytes(6)),
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'owner',
            'is_active' => 1,
            'createdAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['admins'][(string) $admin['id']] = $admin;
            $this->writeFileStore($data);
            return (string) $admin['id'];
        }
        $stmt = $this->pdo()->prepare('INSERT INTO admins (name, email, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, \'owner\', 1, NOW(), NOW())');
        $stmt->execute([$admin['name'], $admin['email'], $admin['password_hash']]);
        return (int) $this->pdo()->lastInsertId();
    }

    public function audit(?int $adminId, string $action, array $context = []): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['audit'][] = [
                'id' => bin2hex(random_bytes(6)),
                'adminId' => $adminId,
                'action' => $action,
                'context' => $context,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'createdAt' => now_iso(),
            ];
            $data['audit'] = array_slice($data['audit'], -500);
            $this->writeFileStore($data);
            return;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO audit_log (admin_id, action_key, context_json, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$adminId, $action, json_encode($context), $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    public function stats(): array
    {
        $media = $this->media();
        $templates = $this->templates();
        return array_merge([
            'media' => count($media),
            'templates' => count($templates),
            'heroVersions' => count($this->sceneVersions('hero', 50)),
            'footerVersions' => count($this->sceneVersions('footer', 50)),
            'addons' => count($this->addons()),
            'activeAddons' => count(array_filter($this->addons(), static fn(array $addon): bool => ($addon['status'] ?? '') === 'active')),
        ], $this->mediaCenterStats(), $this->memberSystemStats(), $this->commerceStats(), $this->communicationStats());
    }

    public function rawFileData(): array
    {
        return $this->readFileStore();
    }

    public function replaceFileData(array $data): void
    {
        $this->writeFileStore($data);
    }

    private function pdo(): PDO
    {
        if (!$this->database instanceof Database) {
            throw new RuntimeException('Database is not available in file storage mode.');
        }
        return $this->database->connection();
    }

    private function readFileStore(): array
    {
        if (!is_file($this->filePath)) {
            $default = root_path('storage/defaults/data.json');
            if (is_file($default)) {
                $directory = dirname($this->filePath);
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                copy($default, $this->filePath);
            }
        }
        if (!is_file($this->filePath)) {
            return $this->emptyFileStore();
        }
        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read file data store.');
        }
        try {
            flock($handle, LOCK_SH);
            $json = stream_get_contents($handle) ?: '{}';
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        return array_replace_recursive($this->emptyFileStore(), safe_json_decode($json, $this->emptyFileStore()));
    }

    private function emptyFileStore(): array
    {
        return [
            'settings' => [], 'admins' => [], 'media' => [], 'templates' => [], 'scenes' => [],
            'mediaCenter' => ['albums'=>[], 'tracks'=>[], 'audioPlaylists'=>[], 'galleries'=>[], 'videos'=>[]],
            'members' => [], 'membershipPlans' => [], 'subscriptions' => [], 'entitlements' => [],
            'contentAccessRules' => [], 'memberMediaState' => [], 'memberSessions' => [],
            'memberTokens' => [], 'memberEvents' => [], 'mailOutbox' => [],
            'paymentProviders' => [], 'coupons' => [], 'couponRedemptions' => [], 'bundles' => [],
            'orders' => [], 'orderItems' => [], 'paymentTransactions' => [], 'paymentWebhookEvents' => [],
            'addons' => [], 'addonActivity' => [], 'addonSettings' => [], 'addonBackups' => [], 'addonTasks' => [], 'addonMigrations' => [],
            'messageThreads' => [], 'messages' => [], 'messageAttachments' => [], 'savedReplies' => [],
            'communicationBlocks' => [], 'contentRequests' => [], 'bookingRequests' => [],
            'requestAttachments' => [], 'memberNotifications' => [],
            'audit' => [],
        ];
    }

    private function writeFileStore(array $data): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create storage directory.');
        }
        $temp = $this->filePath . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write data store.');
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $this->filePath)) {
            @unlink($temp);
            throw new RuntimeException('Unable to activate data store.');
        }
    }
}
