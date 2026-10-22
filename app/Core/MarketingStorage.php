<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Config;
use App\Core\Database;
use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/MarketingStorageException.php';
require_once __DIR__ . '/MarketingPrivacyErasure.php';
require_once __DIR__ . '/MarketingSqlRepository.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';

/** File compatibility plus an explicit, atomic move to authoritative SQL records. */
final class MarketingStorage
{
    private readonly string $root;
    private readonly string $directory;
    private readonly string $dataFile;
    private readonly string $guardFile;
    private ?PDO $pdo = null;
    private string $connectionFingerprint = '';
    private ?string $deliveryBinding = null;
    private bool $dataOperation = false;

    public function __construct(?string $root = null)
    {
        $root = $root ?: dirname(__DIR__, 2);
        if (!str_starts_with($root, '/')) $root = getcwd() . '/' . $root;
        $this->root = rtrim($root, '/');
        $this->directory = $this->root . '/storage/private/marketing';
        $this->dataFile = $this->directory . '/data.json';
        $this->guardFile = $this->directory . '/sql-cutover.json';
        $this->assertPath($this->directory, true);
    }

    public function initialize(): void
    {
        $this->all();
    }

    public function all(): array
    {
        return $this->access(static fn(array &$data): array => $data, false);
    }

    /** Saved-state read only: no directory, lock file, defaults or SQL guard is created. */
    public function peek(): ?array
    {
        return $this->access(static fn(array &$data): array => $data, false, false);
    }

    public function update(callable $operation): mixed
    {
        return $this->access(static function (array &$data) use ($operation): mixed {
            $prior = (array) ($data['settings'] ?? []);
            $result = $operation($data);
            MarketingPrivacyErasure::enforce($data, $prior);
            return $result;
        }, true);
    }

    /** SQL reports select only indexed matching measurement rows after authority validation. */
    public function measurementReport(array $filters): array
    {
        if ($this->dataOperation) throw new MarketingStorageException('A marketing operation is already in progress.');
        $this->dataOperation = true; $context = null; $databaseLock = false; $transaction = false; $local = null;
        try {
            $local = $this->acquireLocal('data.lock', false, false);
            $context = $this->context();
            $inspection = ['state' => null];
            if ($context['mode'] === 'mysql') {
                $databaseLock = $this->acquireDatabase($context, 'data', 10);
                $context['pdo']->beginTransaction(); $transaction = true;
                $inspection = $context['repository']->inspect(true);
            }
            if ($inspection['state'] !== null) {
                $this->assertGuardMatches($context['guard'], $inspection['authority']);
                $state = $inspection['state']; $index = new MarketingMeasurementSqlRepository($context['pdo']);
                if (!$index->current($state)) $index->sync($context['repository']->read($state), true);
                $header = json_decode($state['header_json'], true, 512, JSON_THROW_ON_ERROR);
                $retention = MarketingAnalytics::state($header)['retention_days'];
                try { $result = $index->report($filters, $retention); }
                catch (MarketingStorageException $error) {
                    if ($error->getCode() !== 1001) throw $error;
                    $index->sync($context['repository']->read($state), true);
                    $result = $index->report($filters, $retention);
                }
                $result['configuration'] = MarketingAnalytics::configuration($header);
                $result['capacity'] = $index->capacity();
            } else {
                if ($context['guard'] !== null) throw new MarketingStorageException('SQL marketing requires review. The old file was not reactivated.');
                $data = $this->sourceData($this->source()); $state = MarketingAnalytics::state($data);
                $events = array_values(array_filter($data['events'], static fn($event): bool => is_array($event) && array_key_exists('measurement_version', $event)));
                $result = MarketingAnalytics::report(array_values($state['rollups']), $events, $filters, 'file', $state['retention_days']);
                if ($filters['compare']) $result['previous'] = MarketingAnalytics::report(array_values($state['rollups']), $events, MarketingAnalytics::previous($filters), 'file', $state['retention_days']);
                $result['configuration'] = MarketingAnalytics::configuration($data);
                $result['capacity'] = ['raw_events' => count($data['events']), 'measured_events' => count($events),
                    'legacy_events' => count($data['events']) - count($events), 'raw_limit' => MarketingAnalytics::MAX_EVENTS,
                    'daily_totals' => count($state['rollups']), 'daily_limit' => MarketingAnalytics::MAX_ROLLUPS];
            }
            if ($transaction) { $context['pdo']->commit(); $transaction = false; }
            return $result;
        } catch (PDOException $error) { throw $this->databaseError($error); }
        finally {
            if ($transaction && $context !== null && $context['pdo']->inTransaction()) { try { $context['pdo']->rollBack(); } catch (Throwable) {} }
            if ($databaseLock && $context !== null) $this->releaseDatabase($context, 'data');
            if (is_resource($local)) { flock($local, LOCK_UN); fclose($local); }
            $this->dataOperation = false;
        }
    }

    /** Holds no data mutex or SQL transaction while the caller performs SMTP. */
    public function withDeliveryLock(callable $operation): mixed
    {
        if ($this->deliveryBinding !== null) return null;
        $local = $this->acquireLocal('delivery.lock', true);
        if ($local === null) return null;
        $context = null; $databaseLock = false;
        try {
            $context = $this->context();
            if ($context['mode'] === 'mysql') {
                $databaseLock = $this->acquireDatabase($context, 'delivery', 0, true);
                if (!$databaseLock) return null;
            }
            $this->deliveryBinding = $context['binding'];
            return $operation();
        } catch (PDOException $error) {
            throw $this->databaseError($error);
        } finally {
            $this->deliveryBinding = null;
            if ($databaseLock && $context !== null) $this->releaseDatabase($context, 'delivery');
            flock($local, LOCK_UN); fclose($local);
        }
    }

    /** Read-only review: no directory, file, SQL row, or backend is initialized here. */
    public function status(): array
    {
        $context = null; $databaseLock = false; $transaction = false;
        try {
            $context = $this->context();
            $guard = $context['guard'];
            if ($context['mode'] !== 'mysql') {
                $source = $this->source();
                return $this->result('file', 'file', false, '', $this->sourceData($source),
                    'Marketing uses protected file storage. A verified MySQL installation is required before moving it.', null, $source['exists']);
            }
            $databaseLock = $this->acquireDatabase($context, 'data', 10);
            $context['pdo']->beginTransaction(); $transaction = true;
            $inspection = $context['repository']->inspect(true);
            $source = $this->source();
            if ($inspection['state'] !== null) {
                $this->assertGuardMatches($guard, $inspection['authority']);
                $data = $context['repository']->read($inspection['state']);
                return $this->result('mysql', 'mysql', false, '', $data,
                    'Marketing records are stored in MySQL. The previous file is retained as a recovery source and is not used for live reads or writes.',
                    $inspection['state']['migrated_at'], $source['exists'] && hash_equals($inspection['state']['source_sha256'], $source['hash']));
            }
            if ($inspection['schema'] !== 'current') {
                if ($guard !== null) throw new MarketingStorageException('A SQL move was started but its required database schema is unavailable. Review or restore the database; the old file has not been reactivated.');
                return $this->result('schema_needed', 'file', false, '', $this->sourceData($source),
                    'Apply the pending marketing migration in System → Database Updates, then return here to review the move. Existing marketing remains in its file.', null, $source['exists']);
            }
            $data = $this->sourceData($source);
            $plan = $this->plan($context, $source, $inspection);
            if ($guard !== null) {
                if (!$source['exists'] || !hash_equals($guard['source_sha256'], $source['hash'])) {
                    throw new MarketingStorageException('The original marketing file changed after a move was started. Both sources were preserved. Review them before retrying.');
                }
                return $this->result('blocked', null, true, $plan, $data,
                    'A previous move stopped before SQL activation. The database confirms that no SQL copy is active and the original file is unchanged. Preview and explicitly retry to finish the move.', null, true);
            }
            return $this->result('ready', 'file', true, $plan, $data,
                $source['exists']
                    ? 'The existing marketing file is ready to move into MySQL. Review the counts; the original file will be retained unchanged.'
                    : 'Marketing file storage has not been initialized. Moving will create its initial defaults, retain that file, and activate the verified MySQL copy.',
                null, $source['exists']);
        } catch (Throwable $error) {
            return $this->result('blocked', null, false, '', [],
                $error instanceof MarketingStorageException ? $error->getMessage() : $this->databaseError($error)->getMessage(), null, false);
        } finally {
            if ($transaction && $context !== null && $context['pdo']->inTransaction()) {
                try { $context['pdo']->rollBack(); } catch (Throwable) {}
            }
            if ($databaseLock && $context !== null) $this->releaseDatabase($context, 'data');
        }
    }

    /** The HTTP caller authenticates, checks CSRF, and supplies a fresh owner recheck. */
    public function migrate(string $expectedPlan, ?callable $authorize = null): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedPlan) !== 1) {
            throw new MarketingStorageException('Preview the marketing move before applying it.');
        }
        $localDelivery = $this->acquireLocal('delivery.lock', true);
        if ($localDelivery === null) throw new MarketingStorageException('A campaign worker or marketing move is running. Try again after it finishes.');
        $context = null; $databaseDelivery = false; $localData = null; $databaseData = false; $transaction = false;
        try {
            $context = $this->context();
            if ($context['mode'] !== 'mysql') throw new MarketingStorageException('A verified MySQL installation is required before moving marketing records.');
            $databaseDelivery = $this->acquireDatabase($context, 'delivery', 0, true);
            if (!$databaseDelivery) throw new MarketingStorageException('A campaign worker or marketing move is running. Try again after it finishes.');
            $localData = $this->acquireLocal('data.lock');
            $databaseData = $this->acquireDatabase($context, 'data', 10);
            if ($authorize !== null) $authorize();
            $this->assertConfigurationUnchanged($context);
            $context['pdo']->beginTransaction(); $transaction = true;
            $inspection = $context['repository']->inspect(true);
            $source = $this->source();
            if ($inspection['state'] !== null) {
                $this->assertGuardMatches($context['guard'], $inspection['authority']);
                $data = $context['repository']->read($inspection['state']);
                $this->ensureGuard($inspection['authority']);
                if ($authorize !== null) $authorize();
                return $this->result('mysql', 'mysql', false, '', $data, 'Marketing records are already stored in MySQL; no records were imported again.',
                    $inspection['state']['migrated_at'], $source['exists'] && hash_equals($inspection['state']['source_sha256'], $source['hash']));
            }
            if ($inspection['schema'] !== 'current') throw new MarketingStorageException('Apply the pending marketing migration in System → Database Updates before moving records.');
            if (!hash_equals($this->plan($context, $source, $inspection), $expectedPlan)) {
                throw new MarketingStorageException('Marketing data or installation settings changed after the preview. Preview the current move again.');
            }
            $guard = $context['guard'];
            if ($guard !== null && (!$source['exists'] || !hash_equals($guard['source_sha256'], $source['hash']))) {
                throw new MarketingStorageException('The original marketing file changed after a move was started. Review both sources before retrying.');
            }
            if (!$source['exists']) {
                $this->writeFile($this->defaults());
                $source = $this->source();
            }
            $data = $this->sourceData($source);
            $storageId = $guard['storage_id'] ?? bin2hex(random_bytes(16));
            $imported = $context['repository']->import($data, $source['hash'], $storageId, gmdate('c'));
            // Persist the fail-closed intent before the SQL commit can become authoritative.
            $this->ensureGuard($imported['authority']);
            $this->assertConfigurationUnchanged($context);
            $now = $this->source();
            if (!$now['exists'] || !hash_equals($source['hash'], $now['hash'])) {
                throw new MarketingStorageException('The original marketing file changed during the move. Both sources were preserved; review before retrying.');
            }
            if ($authorize !== null) $authorize();
            $context['pdo']->commit(); $transaction = false;
            return $this->result('mysql', 'mysql', false, '', $data,
                'The marketing records were copied, verified, and activated in MySQL. The original file remains unchanged as a recovery source.',
                $imported['state']['migrated_at'], true);
        } catch (Throwable $error) {
            if ($error instanceof MarketingStorageException || $error->getCode() === 403) throw $error;
            throw new MarketingStorageException('The marketing move did not finish with a confirmed result. Review Marketing Storage before retrying. Existing source data and any SQL-required intent were preserved.', 0, $error);
        } finally {
            if ($transaction && $context !== null && $context['pdo']->inTransaction()) {
                try { $context['pdo']->rollBack(); } catch (Throwable) {}
            }
            if ($databaseData && $context !== null) $this->releaseDatabase($context, 'data');
            if (is_resource($localData)) { flock($localData, LOCK_UN); fclose($localData); }
            if ($databaseDelivery && $context !== null) $this->releaseDatabase($context, 'delivery');
            flock($localDelivery, LOCK_UN); fclose($localDelivery);
        }
    }

    private function access(callable $operation, bool $write, bool $initialize = true): mixed
    {
        if ($this->dataOperation) throw new MarketingStorageException('A marketing data operation is already in progress.');
        $this->dataOperation = true;
        $local = null; $context = null; $databaseLock = false; $transaction = false;
        try {
            $local = $this->acquireLocal('data.lock', false, $initialize);
            $context = $this->context();
            $inspection = ['schema' => 'needed', 'state' => null, 'authority' => null];
            if ($context['mode'] === 'mysql') {
                $databaseLock = $this->acquireDatabase($context, 'data', 10);
                $context['pdo']->beginTransaction(); $transaction = true;
                $inspection = $context['repository']->inspect(true);
            }
            if ($inspection['state'] !== null) {
                $this->assertGuardMatches($context['guard'], $inspection['authority']);
                if ($initialize) $this->ensureGuard($inspection['authority']);
                $data = $context['repository']->read($inspection['state']);
            } else {
                if ($context['guard'] !== null) {
                    throw new MarketingStorageException('A marketing SQL move requires review. Open System → Marketing Storage; the old file has not been reactivated.');
                }
                $source = $this->source();
                if (!$source['exists'] && $initialize) { $this->writeFile($this->defaults()); $source = $this->source(); }
                $data = $source['exists'] ? $this->sourceData($source) : null;
            }
            if (!$initialize && $local === null) {
                // A first writer creates the persistent mutex before selecting or changing
                // storage. If that happened during this unlocked read, require a locked retry.
                $lockPath = $this->directory . '/data.lock';
                $this->assertPath($lockPath, true);
                if (file_exists($lockPath)) throw new MarketingStorageException('Marketing storage changed while opening the saved preview. Reload and try again.');
            }
            $before = $data;
            $result = $data === null ? null : $operation($data);
            if ($write && $data !== $before) {
                $data['meta']['revision'] = (int) ($before['meta']['revision'] ?? 0) + 1;
                $data['meta']['updated_at'] = gmdate('c');
                if ($inspection['state'] !== null) $context['repository']->write($before, $data);
                else $this->writeFile($data);
            }
            if ($transaction) { $context['pdo']->commit(); $transaction = false; }
            return $result;
        } catch (PDOException $error) {
            throw $this->databaseError($error);
        } finally {
            if ($transaction && $context !== null && $context['pdo']->inTransaction()) {
                try { $context['pdo']->rollBack(); } catch (Throwable) {}
            }
            if ($databaseLock && $context !== null) $this->releaseDatabase($context, 'data');
            if (is_resource($local)) { flock($local, LOCK_UN); fclose($local); }
            $this->dataOperation = false;
        }
    }

    private function context(): array
    {
        $this->assertPath($this->directory, true);
        $guard = $this->guard();
        $configPath = $this->root . '/config/app.php';
        $this->assertPath($configPath, true);
        if (!is_file($configPath)) {
            if ($guard !== null) throw new MarketingStorageException('The configuration required by active marketing SQL storage is missing. The old file has not been reactivated.');
            if ($this->deliveryBinding !== null && $this->deliveryBinding !== 'file') throw new MarketingStorageException('The marketing database configuration changed during campaign delivery.');
            return ['mode' => 'file', 'guard' => null, 'binding' => 'file'];
        }
        $configHash = @hash_file('sha256', $configPath);
        if (!is_string($configHash)) throw new MarketingStorageException('The current configuration could not be verified.');
        try { $configuration = (new Config($configPath))->all(); }
        catch (Throwable $error) { throw new MarketingStorageException('The installation configuration could not be verified. Marketing data has been held for review.', 0, $error); }
        $this->assertPath($configPath);
        $loadedHash = @hash_file('sha256', $configPath);
        if (!is_string($loadedHash) || !hash_equals($configHash, $loadedHash)) {
            throw new MarketingStorageException('Installation settings changed while marketing storage was opening. Retry with the current configuration.');
        }
        $mode = $configuration['storage_mode'] ?? 'mysql';
        if ($mode === 'file') {
            if ($guard !== null) throw new MarketingStorageException('Marketing was moved to SQL, but this installation is configured for file storage. Restore the matching configuration; the old file has not been reactivated.');
            if ($this->deliveryBinding !== null && $this->deliveryBinding !== 'file') throw new MarketingStorageException('The marketing database configuration changed during campaign delivery.');
            return ['mode' => 'file', 'guard' => null, 'binding' => 'file'];
        }
        $installationId = $configuration['installation_id'] ?? null;
        if ($mode !== 'mysql' || !is_string($installationId) || $installationId === '' || strlen($installationId) > 190
            || empty($configuration['installed']) || !is_array($configuration['db'] ?? null)) {
            throw new MarketingStorageException('A complete installed MySQL configuration is required for marketing storage.');
        }
        $lockPath = $this->root . '/storage/install.lock';
        $this->assertPath($lockPath);
        $lockRaw = @file_get_contents($lockPath);
        $lock = is_string($lockRaw) ? json_decode($lockRaw, true) : null;
        if (!is_array($lock) || ($lock['installation_id'] ?? null) !== $installationId) {
            throw new MarketingStorageException('The installation lock and configuration do not match. Marketing data has been held for review.');
        }
        $binding = hash('sha256', MarketingSqlRepository::encode([$installationId, $configuration['db']]));
        if ($this->deliveryBinding !== null && $this->deliveryBinding !== $binding) {
            throw new MarketingStorageException('The marketing database configuration changed during campaign delivery. Review the connection before continuing.');
        }
        if ($this->pdo === null || !hash_equals($this->connectionFingerprint, $binding)) {
            try { $this->pdo = Database::connect($configuration['db']); }
            catch (Throwable $error) { throw $this->databaseError($error); }
            $this->connectionFingerprint = $binding;
        }
        $pdo = $this->pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $databaseHash = hash('sha256', $database . '|' . $installationId);
        $identity = null;
        foreach (['SELECT state_value FROM installation_state WHERE state_key=? LIMIT 1',
            'SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1'] as $query) {
            try { $statement = $pdo->prepare($query); $statement->execute(['installation']); $raw = $statement->fetchColumn(); }
            catch (PDOException $error) { if ($error->getCode() === '42S02') continue; throw $error; }
            if ($raw === false) continue;
            $candidate = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($candidate)) {
                throw new MarketingStorageException('The database installation record is unreadable. Marketing data has been held for review.');
            }
            // Normal installed defaults store version/time here without an identity.
            if (!array_key_exists('installation_id', $candidate)) continue;
            if (!is_string($candidate['installation_id'])
                || !hash_equals($installationId, $candidate['installation_id'])) {
                throw new MarketingStorageException('The database installation records do not match this installation. Marketing data has been held for review.');
            }
            $identity = $candidate['installation_id'];
        }
        if ($identity === null || !hash_equals($installationId, $identity)) {
            throw new MarketingStorageException('The database installation identity could not be verified. Marketing data has been held for review.');
        }
        if ($guard !== null && ($guard['installation_id'] !== $installationId || $guard['database_hash'] !== $databaseHash)) {
            throw new MarketingStorageException('The SQL-required marketing record belongs to a different installation or database. The old file has not been reactivated.');
        }
        $migrationPath = $this->root . '/database/migrations/' . MarketingSqlRepository::MIGRATION . '.php';
        $this->assertPath($migrationPath);
        $checksum = hash_file('sha256', $migrationPath);
        if (!is_string($checksum)) throw new MarketingStorageException('The marketing migration could not be verified from this release.');
        return ['mode' => 'mysql', 'guard' => $guard, 'binding' => $binding, 'pdo' => $pdo,
            'database_hash' => $databaseHash, 'installation_id' => $installationId,
            'config_hash' => $configHash, 'lock_hash' => hash('sha256', $lockRaw), 'migration_checksum' => $checksum,
            'repository' => new MarketingSqlRepository($pdo, $installationId, $databaseHash, $checksum)];
    }

    private function assertConfigurationUnchanged(array $context): void
    {
        foreach (['config/app.php' => 'config_hash', 'storage/install.lock' => 'lock_hash'] as $relative => $key) {
            $path = $this->root . '/' . $relative;
            $this->assertPath($path);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals($context[$key], $hash)) {
                throw new MarketingStorageException('Installation settings changed during the marketing move. Review the current move again.');
            }
        }
    }

    private function plan(array $context, array $source, array $inspection): string
    {
        return hash('sha256', MarketingSqlRepository::encode(['version' => 1, 'config' => $context['config_hash'],
            'lock' => $context['lock_hash'], 'database' => $context['database_hash'], 'migration' => $context['migration_checksum'],
            'source_exists' => $source['exists'], 'source_hash' => $source['hash'],
            'state' => $inspection['state'], 'authority' => $inspection['authority'], 'guard' => $context['guard']]));
    }

    private function result(string $state, ?string $mode, bool $canMigrate, string $plan, array $data,
        string $message, ?string $migratedAt, bool $preserved): array
    {
        $counts = [];
        foreach (MarketingSqlRepository::COLLECTIONS as $key) $counts[$key] = is_array($data[$key] ?? null) ? count($data[$key]) : 0;
        return ['state' => $state, 'mode' => $mode, 'can_migrate' => $canMigrate, 'plan' => $plan,
            'counts' => $counts, 'message' => $message, 'migrated_at' => $migratedAt, 'source_preserved' => $preserved];
    }

    private function databaseError(Throwable $error): MarketingStorageException
    {
        return new MarketingStorageException('The marketing database is unavailable or could not be verified. Check the connection and Database Updates, then review Marketing Storage. The old file has not been reactivated after any SQL move.', 0, $error);
    }

    private function acquireDatabase(array $context, string $purpose, int $timeout, bool $busyIsFalse = false): bool
    {
        $statement = $context['pdo']->prepare('SELECT GET_LOCK(?,?)');
        $statement->execute([$this->databaseLockName($context, $purpose), $timeout]);
        $result = $statement->fetchColumn();
        if ((string) $result === '1') return true;
        if ((string) $result === '0' && $busyIsFalse) return false;
        throw new MarketingStorageException('Marketing storage is busy or its database lock is unavailable. Try again after the current operation finishes.');
    }

    private function releaseDatabase(array $context, string $purpose): void
    {
        try { $statement = $context['pdo']->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$this->databaseLockName($context, $purpose)]); }
        catch (Throwable) {}
    }

    private function databaseLockName(array $context, string $purpose): string
    {
        return 'vvx-marketing-' . $purpose . '-' . substr($context['database_hash'], 0, 40);
    }

    private function source(): array
    {
        $this->assertPath($this->dataFile, true);
        if (!file_exists($this->dataFile)) return ['exists' => false, 'raw' => null, 'hash' => 'missing', 'mtime' => 0];
        if (!is_file($this->dataFile)) throw new MarketingStorageException('Marketing storage requires a regular data file. Existing data has been preserved.');
        $raw = @file_get_contents($this->dataFile);
        if (!is_string($raw)) throw new MarketingStorageException('The original marketing file could not be read. Existing data has been preserved.');
        return ['exists' => true, 'raw' => $raw, 'hash' => hash('sha256', $raw), 'mtime' => (int) filemtime($this->dataFile)];
    }

    private function sourceData(array $source): array
    {
        if (!$source['exists']) return $this->defaults(['updated_at' => gmdate('c', 0), 'analytics_salt' => str_repeat('0', 32)]);
        try { $decoded = json_decode($source['raw'], true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { throw new MarketingStorageException('Marketing data could not be read. Existing records were preserved.', 0, $error); }
        if (!is_array($decoded)) throw new MarketingStorageException('Marketing data could not be read. Existing records were preserved.');
        // Missing legacy metadata is stable across previews; stored values always take precedence.
        $defaults = $this->defaults(['updated_at' => gmdate('c', $source['mtime']),
            'analytics_salt' => substr(hash('sha256', 'marketing-legacy|' . $source['hash']), 0, 32)]);
        $data = array_replace($defaults, $decoded);
        foreach (['meta', 'settings'] as $key) $data[$key] = array_replace($defaults[$key], (array) ($decoded[$key] ?? []));
        foreach (MarketingSqlRepository::COLLECTIONS as $key) {
            if (!is_array($data[$key])) throw new MarketingStorageException('Marketing records are invalid. Existing data was preserved.');
        }
        foreach ($data['segments'] as $segment) if (!is_array($segment)) throw new MarketingStorageException('Saved audience records are invalid. Existing data was preserved.');
        return $data;
    }

    private function defaults(array $meta = []): array
    {
        return [
            'meta' => ['version' => 2, 'revision' => 0, 'updated_at' => $meta['updated_at'] ?? gmdate('c'),
                'analytics_salt' => $meta['analytics_salt'] ?? bin2hex(random_bytes(16))],
            'settings' => ['newsletter_signup_enabled' => true, 'campaign_delivery_enabled' => true],
            'suppressions' => [], 'posts' => [], 'banners' => [], 'landing_pages' => [],
            'links' => [
                ['id'=>'link-home','label'=>'Official Website','url'=>'/','enabled'=>true,'sort'=>10],
                ['id'=>'link-membership','label'=>'Join the Velvet Club','url'=>'/membership/','enabled'=>true,'sort'=>20],
                ['id'=>'link-booking','label'=>'Booking & Appearances','url'=>'/booking/','enabled'=>true,'sort'=>30],
            ],
            'referral_codes' => [], 'contacts' => [], 'segments' => [], 'campaigns' => [],
            'campaign_queue' => [], 'events' => [], 'consents' => [],
        ];
    }

    private function guard(): ?array
    {
        $this->assertPath($this->guardFile, true);
        if (!file_exists($this->guardFile)) return null;
        if (!is_file($this->guardFile)) throw new MarketingStorageException('The SQL-required marketing record is not a regular file. Existing data has been held for review.');
        $raw = @file_get_contents($this->guardFile);
        $guard = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($guard) || ($guard['version'] ?? null) !== 1
            || !is_string($guard['installation_id'] ?? null) || $guard['installation_id'] === ''
            || !is_string($guard['storage_id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $guard['storage_id']) !== 1
            || !is_string($guard['database_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $guard['database_hash']) !== 1
            || !is_string($guard['source_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $guard['source_sha256']) !== 1) {
            throw new MarketingStorageException('The SQL-required marketing record is invalid. Existing data has been held for review.');
        }
        return $guard;
    }

    private function assertGuardMatches(?array $guard, array $authority): void
    {
        if ($guard === null) return;
        foreach (['installation_id', 'storage_id', 'database_hash', 'source_sha256'] as $key) {
            if ($guard[$key] !== $authority[$key]) throw new MarketingStorageException('Marketing SQL authority and its local required record disagree. Existing data has been held for review.');
        }
    }

    private function ensureGuard(array $authority): void
    {
        $guard = $this->guard();
        $this->assertGuardMatches($guard, $authority);
        if ($guard !== null) return;
        $record = array_intersect_key($authority, array_flip(['version', 'installation_id', 'storage_id', 'database_hash', 'source_sha256']));
        $this->atomicWrite($this->guardFile, MarketingSqlRepository::encode($record) . "\n");
        $this->assertGuardMatches($this->guard(), $authority);
    }

    private function writeFile(array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
        $this->atomicWrite($this->dataFile, $encoded);
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        $this->ensureDirectory();
        $this->assertPath($path, true);
        if (file_exists($path) && (!is_file($path) || !is_writable($path) || ((int) fileperms($path) & 0222) === 0)) {
            throw new MarketingStorageException('Marketing private storage is not writable. Existing data has been preserved.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) throw new MarketingStorageException('Marketing private storage could not be written.');
        try {
            if (!@chmod($temporary, 0640)) throw new MarketingStorageException('Marketing private storage permissions could not be set.');
            $offset = 0; $length = strlen($bytes);
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new MarketingStorageException('Marketing private storage could not be written completely.');
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new MarketingStorageException('Marketing private storage could not be saved durably.');
            fclose($handle); $handle = null;
            $this->assertPath($path, true);
            if (!@rename($temporary, $path)) throw new MarketingStorageException('Marketing private storage could not be activated.');
            if (function_exists('fsync')) {
                $directory = @fopen($this->directory, 'r');
                if (!is_resource($directory)) throw new MarketingStorageException('Marketing private storage could not be saved durably.');
                try {
                    if (!@fsync($directory)) throw new MarketingStorageException('Marketing private storage could not be saved durably.');
                } finally { fclose($directory); }
            }
            clearstatcache(true, $path);
            $saved = @file_get_contents($path);
            if (!is_string($saved) || !hash_equals(hash('sha256', $bytes), hash('sha256', $saved))) {
                throw new MarketingStorageException('Marketing private storage could not be verified after saving.');
            }
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function acquireLocal(string $name, bool $nonblocking = false, bool $create = true)
    {
        if ($create) $this->ensureDirectory();
        else $this->assertPath($this->directory, true);
        $path = $this->directory . '/' . $name;
        $this->assertPath($path, true);
        if (!$create && !file_exists($path)) return null;
        if (file_exists($path) && (!is_file($path) || ($create && (!is_writable($path) || ((int) fileperms($path) & 0222) === 0)))) {
            throw new MarketingStorageException('Marketing storage could not be locked. Check private storage permissions.');
        }
        $handle = @fopen($path, $create ? 'c+b' : 'rb');
        if (!is_resource($handle)) throw new MarketingStorageException('Marketing storage could not be locked.');
        if ($create) @chmod($path, 0640);
        $deadline = microtime(true) + ($nonblocking ? 0 : 10);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
            if ($nonblocking) { fclose($handle); return null; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        throw new MarketingStorageException('Marketing storage is busy. Try again after the current operation finishes.');
    }

    private function ensureDirectory(): void
    {
        $this->assertPath($this->directory, true);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MarketingStorageException('Marketing private storage could not be created.');
        }
        $this->assertPath($this->directory);
        if (!is_dir($this->directory) || !is_writable($this->directory) || ((int) fileperms($this->directory) & 0222) === 0) {
            throw new MarketingStorageException('Marketing private storage is not writable.');
        }
    }

    private function assertPath(string $path, bool $mayBeMissing = false): void
    {
        if (!str_starts_with($path, $this->root . '/')) throw new MarketingStorageException('The marketing storage path is outside this installation.');
        // Include the installation root and every existing parent in the check.
        $current = '';
        $parts = explode('/', ltrim($path, '/'));
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new MarketingStorageException('The marketing storage path is invalid.');
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) throw new MarketingStorageException('Marketing storage cannot use symbolic links. Existing data has been preserved.');
            if (!file_exists($current)) {
                if ($mayBeMissing) return;
                throw new MarketingStorageException('A required marketing installation file is missing. Existing data has been held for review.');
            }
            if ($index < count($parts) - 1 && !is_dir($current)) throw new MarketingStorageException('A marketing storage parent is not a directory.');
        }
    }
}
