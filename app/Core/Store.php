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
    use BookingFormStoreTrait;
    use MenuStoreTrait;
    use SceneScheduleStoreTrait;

    private readonly string $mode;
    private ?Database $database = null;
    private readonly string $filePath;
    // Keep the first read of an ordinary read/modify/write operation. A nested
    // read must not bless an older array after another request changed the file.
    private ?string $fileReadDigest = null;
    private bool $fileMutationActive = false;

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

    /** Calendar metadata only: no initialization, hydration, jobs or write-digest changes. */
    public function calendarRecords(): array
    {
        $tables = ['albums' => 'audio_albums', 'tracks' => 'audio_tracks',
            'audioPlaylists' => 'audio_playlists', 'galleries' => 'photo_galleries', 'videos' => 'videos'];
        $media = array_fill_keys(array_keys($tables), []);
        $scenes = [];
        if ($this->mode === 'file') {
            $data = $this->loadFileStore()[0];
            $fields = array_fill_keys(['id', 'title', 'status', 'publishedAt', 'scheduledAt', 'accessMode'], true);
            foreach ($tables as $collection => $table) {
                $rows = $data['mediaCenter'][$collection] ?? [];
                if (!is_array($rows)) {
                    $media[$collection] = [[]];
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) { $media[$collection][] = []; continue; }
                    if (!empty($row['deletedAt'])) continue;
                    $media[$collection][] = array_intersect_key($row, $fields);
                }
            }
            foreach (['hero', 'footer'] as $section) {
                $saved = $data['scenes'][$section] ?? [];
                $versions = is_array($saved) ? ($saved['versions'] ?? []) : [];
                $scenes[$section] = ['draft' => is_array($saved['draft'] ?? null) && $saved['draft'] !== [], 'versions' => []];
                if (!is_array($versions)) $versions = [[]];
                foreach (array_slice($versions, -20) as $version) {
                    $scenes[$section]['versions'][] = is_array($version)
                        ? array_intersect_key($version, ['id' => true, 'publishedAt' => true]) : [];
                }
            }
            return ['media' => $media, 'scenes' => $scenes];
        }

        $pdo = $this->pdo();
        foreach ($tables as $collection => $table) {
            $columns = 'id,title,status,published_at,scheduled_at' . ($collection === 'albums' ? '' : ',access_mode');
            $where = in_array($collection, ['galleries', 'videos'], true) ? ' WHERE deleted_at IS NULL' : '';
            $rows = $pdo->query('SELECT ' . $columns . ' FROM ' . $table . $where . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $media[$collection][] = ['id' => $row['id'], 'title' => $row['title'], 'status' => $row['status'],
                    'publishedAt' => $row['published_at'], 'scheduledAt' => $row['scheduled_at'],
                    'accessMode' => $row['access_mode'] ?? 'public'];
            }
        }
        $draft = $pdo->prepare("SELECT 1 FROM scenes WHERE section_key = ? AND status = 'draft' LIMIT 1");
        // Scene writers use NOW(), so interpret DATETIME in the database session's
        // timezone before returning an explicit instant. Invalid/out-of-range
        // UNIX_TIMESTAMP values must not silently become the epoch.
        $versions = $pdo->prepare('SELECT id, CASE WHEN UNIX_TIMESTAMP(created_at) = 0 AND created_at <> FROM_UNIXTIME(0) '
            . 'THEN NULL ELSE UNIX_TIMESTAMP(created_at) END AS published_epoch FROM scene_versions '
            . 'WHERE section_key = ? ORDER BY id DESC LIMIT 20');
        foreach (['hero', 'footer'] as $section) {
            $draft->execute([$section]);
            $scenes[$section] = ['draft' => $draft->fetchColumn() !== false, 'versions' => []];
            $versions->execute([$section]);
            // Match the file store's oldest-to-newest retained order.
            foreach (array_reverse($versions->fetchAll(PDO::FETCH_ASSOC)) as $row) {
                $epoch = $row['published_epoch'];
                $at = (is_int($epoch) || is_string($epoch)) && preg_match('/\A[0-9]{1,12}\z/D', (string) $epoch)
                    ? (new \DateTimeImmutable('@' . $epoch))->format(\DateTimeInterface::ATOM) : false;
                $scenes[$section]['versions'][] = ['id' => $row['id'], 'publishedAt' => $at];
            }
        }
        return ['media' => $media, 'scenes' => $scenes];
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
            if ($publishedOnly) {
                return (array) ($scene['published'] ?? []);
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

    public function saveScene(string $section, array $scene, bool $publish = false, ?int $adminId = null, ?array $ownerIdentity = null): int|string
    {
        $section = SceneSchedule::section($section);
        $scene = SceneButton::validateScene($scene);
        $scene = SceneDepth::validateScene($scene, $section);
        $scene['section'] = $section;
        $scene['updatedAt'] = now_iso();
        $result = $this->mutateSceneSection($section, $ownerIdentity,
            function (array &$state, array &$scenes, ?array &$data, ?PDO $pdo) use ($section, $scene, $publish, $adminId): int|string {
                $id = $this->writeSceneAtomic($section, $scene, $publish, true, $adminId, $scenes, $pdo);
                if ($publish && $state['schedule'] !== null) {
                    $this->finishSceneSchedule($state, 'superseded', 'A manual publication replaced this schedule. Automatic activation or return was cancelled.', time());
                }
                return $id;
            });
        return $result['result'];
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

    public function restoreScene(string $section, string|int $versionId, ?int $adminId = null, ?array $ownerIdentity = null): array
    {
        $versions = $this->sceneVersions($section, 50);
        foreach ($versions as $version) {
            if ((string) ($version['id'] ?? '') === (string) $versionId) {
                $scene = (array) ($version['scene'] ?? []);
                $this->saveScene($section, $scene, true, $adminId, $ownerIdentity);
                return $scene;
            }
        }
        throw new RuntimeException('The selected composition version could not be found.');
    }

    /** Read-only metadata lookups must not initialize storage or change write digests. */
    public function media(bool $readOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $readOnly ? $this->loadFileStore()[0] : $this->readFileStore();
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
        if ($this->fileMutationActive) {
            throw new RuntimeException('Nested file data writes are not supported.');
        }
        $lock = $this->lockFileStore();
        try {
            $this->activateFileStore($data, null);
            $this->fileReadDigest = null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Apply one bounded change to fresh file data while all application writers
     * share the same lock. The callback must change the supplied array only;
     * calling another writer inside it would make rollback ambiguous.
     */
    public function mutateFileStore(callable $mutation, bool $waitForLock = false): mixed
    {
        if ($this->mode !== 'file' || $this->fileMutationActive) {
            throw new RuntimeException('This file data mutation is not available.');
        }
        $lock = $this->lockFileStore($waitForLock);
        $this->fileMutationActive = true;
        try {
            $this->initializeFileStore();
            [$data, $digest] = $this->loadFileStore();
            $this->fileReadDigest = $digest;
            $before = $data;
            $result = $mutation($data);
            if ($data !== $before) {
                $this->activateFileStore($data, $digest);
            }
            return $result;
        } finally {
            $this->fileMutationActive = false;
            // A caller holding an old array must read again before using a
            // legacy writer; only this callback received the fresh snapshot.
            $this->fileReadDigest = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
        $this->assertFileStorePath();
        if (!is_file($this->filePath) && !$this->fileMutationActive) {
            $lock = $this->lockFileStore();
            try {
                $this->initializeFileStore();
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        [$data, $digest] = $this->loadFileStore();
        $this->fileReadDigest ??= $digest;
        return $data;
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function loadFileStore(): array
    {
        $this->assertFileStorePath();
        if (!is_file($this->filePath)) {
            return [$this->emptyFileStore(), 'missing'];
        }
        $handle = @fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read file data store.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Unable to read file data store.');
            }
            $json = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read file data store.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('File data is not valid JSON. Restore a verified backup before making changes.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException('File data has an invalid structure. Restore a verified backup before making changes.');
        }
        return [array_replace_recursive($this->emptyFileStore(), $data), hash('sha256', $json)];
    }

    /** Called only with the common write lock held. */
    private function initializeFileStore(): void
    {
        $this->assertFileStorePath();
        if (is_file($this->filePath)) {
            return;
        }
        $default = root_path('storage/defaults/data.json');
        if (!is_file($default)) {
            return;
        }
        try {
            $data = json_decode((string) file_get_contents($default), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Default file data is invalid.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('Default file data is invalid.');
        }
        $this->activateFileStore($data, 'missing');
    }

    private function assertFileStorePath(): void
    {
        clearstatcache(true, $this->filePath);
        if (is_link($this->filePath) || is_link(dirname($this->filePath)) || (file_exists($this->filePath) && !is_file($this->filePath))) {
            throw new RuntimeException('File data storage must use a regular file and directory.');
        }
    }

    /** @return resource */
    private function lockFileStore(bool $waitForLock = false)
    {
        $this->assertFileStorePath();
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create storage directory.');
        }
        $path = $this->filePath . '.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('File data storage lock is not safe.');
        }
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to lock file data storage.');
        }
        @chmod($path, 0640);
        $deadline = microtime(true) + ($waitForLock ? 2.0 : 0.0);
        do {
            if (flock($lock, LOCK_EX | LOCK_NB)) return $lock;
            if (!$waitForLock || microtime(true) >= $deadline) break;
            usleep(10000);
        } while (true);
        fclose($lock);
        throw new RuntimeException('File data is being updated. Reload and try again.', 409);
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
        if ($this->fileMutationActive) {
            throw new RuntimeException('Nested file data writes are not supported.');
        }
        if ($this->fileReadDigest === null) {
            throw new RuntimeException('Reload file data before making changes.');
        }
        $lock = $this->lockFileStore();
        try {
            $this->activateFileStore($data, $this->fileReadDigest);
            $this->fileReadDigest = null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Called only with the sidecar lock held; null explicitly replaces data. */
    private function activateFileStore(array $data, ?string $expectedDigest): void
    {
        $this->assertFileStorePath();
        // Parsing also refuses to overwrite a corrupt existing store, including
        // through the explicit replacement API. Recovery remains deliberate.
        [, $currentDigest] = $this->loadFileStore();
        if ($expectedDigest !== null && !hash_equals($expectedDigest, $currentDigest)) {
            throw new RuntimeException('File data changed in another request. Reload and try again.');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $temp = $this->filePath . '.tmp.' . bin2hex(random_bytes(12));
        $handle = @fopen($temp, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to write data store.');
        }
        try {
            @chmod($temp, 0640);
            $written = 0;
            while ($written < strlen($json)) {
                $count = fwrite($handle, substr($json, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Unable to write data store.');
                }
                $written += $count;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to write data store.');
            }
            // A manual/noncooperating change during the write must also survive.
            [, $latestDigest] = $this->loadFileStore();
            if (!hash_equals($currentDigest, $latestDigest)) {
                throw new RuntimeException('File data changed in another request. Reload and try again.');
            }
            if (!@rename($temp, $this->filePath)) {
                throw new RuntimeException('Unable to activate data store.');
            }
            clearstatcache(true, $this->filePath);
        } finally {
            fclose($handle);
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
