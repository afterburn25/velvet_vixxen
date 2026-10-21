<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/** An explicitly enabled drain barrier. Ordinary reads never create or repair it. */
final class OperationsGate
{
    private static $requestLock = null;
    private static bool $recovery = false;
    private static ?string $problem = null;

    public static function bootstrap(string $root): void
    {
        if (is_resource(self::$requestLock)) return;
        if (file_exists($root . '/.vvx-restore-quarantine.json') || is_link($root . '/.vvx-restore-quarantine.json')) {
            self::unavailable('This restored installation is quarantined pending an operator review.');
        }
        if (!is_file($root . '/config/app.php')) {
            if (file_exists($root . '/storage/private/operations/gate.json')) self::unavailable('The operations configuration is missing. An operator must restore the matching configuration.');
            return;
        }
        $config = new Config($root . '/config/app.php');
        if ($config->get('operations.enabled', false) === false) return;
        try {
            $status = self::status($root, $config);
            $handle = self::openLock($root);
            if (!flock($handle, LOCK_SH | LOCK_NB)) { fclose($handle); self::unavailable('A verified backup is in progress. Please try again shortly.'); }
            self::sameLock($root, $handle);
            $status = self::status($root, $config);
            self::$requestLock = $handle;
            self::$recovery = !$status['ready'] || $status['recovery'];
        } catch (Throwable) {
            self::$recovery = true;
            self::$problem = 'The configured operations gate needs an operator repair. Public requests are held.';
        }
        if (self::$recovery && !self::ownerEntry($root)) self::unavailable('This site is temporarily in recovery mode. Please try again later.');
    }

    public static function assertNotQuarantined(string $root): void
    {
        if (file_exists($root . '/.vvx-restore-quarantine.json') || is_link($root . '/.vvx-restore-quarantine.json')) throw new RuntimeException('This restored installation remains quarantined. Review activation before running any workers.');
    }

    public static function configureKey(string $root, Config $config, string $keyFile, string $revision): void
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Configure backup keys from the hosting CLI.', 403);
        self::assertNotQuarantined($root);
        $gate = self::exclusive($root, $config, false); $key = null;
        try {
            $key = OperationsFiles::key($keyFile, $root);
            $config->update(static function (array &$values) use ($keyFile): void { $values['operations']['backup_key_file'] = $keyFile; }, $revision);
        } finally { if (is_string($key)) sodium_memzero($key); flock($gate, LOCK_UN); fclose($gate); }
    }

    public static function recovery(): bool { return self::$recovery; }
    public static function problem(): ?string { return self::$problem; }

    public static function status(string $root, Config $config): array
    {
        $config->reload();
        if ($config->get('operations.enabled', false) === false) return ['configured' => false, 'ready' => false, 'recovery' => false, 'revision' => 'unconfigured'];
        if ($config->get('operations.enabled') !== true || $config->get('installed') !== true) throw new RuntimeException('The configured operations gate has an invalid installation state.');
        $path = self::directory($root) . '/gate.json'; OperationsFiles::noLinks($path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || $stat['size'] > 4096) throw new RuntimeException('The configured operations marker is missing or invalid.');
        $raw = @file_get_contents($path);
        if (!is_string($raw)) throw new RuntimeException('The operations marker is unreadable.');
        $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || array_keys($envelope) !== ['state','signature'] || !is_array($envelope['state']) || !is_string($envelope['signature'])) throw new RuntimeException('The operations marker is invalid.');
        $state = $envelope['state'];
        if (array_keys($state) !== ['schema','installation_hash','nonce','created_at','ready','recovery'] || $state['schema'] !== 1
            || $state['installation_hash'] !== hash('sha256', (string) $config->get('installation_id', ''))
            || !is_string($state['nonce']) || !preg_match('/\A[a-f0-9]{32}\z/D', $state['nonce'])
            || !is_int($state['created_at']) || $state['created_at'] < 1 || !is_bool($state['ready']) || !is_bool($state['recovery'])
            || !hash_equals(hash_hmac('sha256', BackupArchive::json($state), self::signingKey($config)), $envelope['signature'])) throw new RuntimeException('The operations marker does not match this installation.');
        $lock = self::directory($root) . '/request.lock'; OperationsFiles::noLinks($lock); $lockStat = @lstat($lock);
        if ($lockStat === false || ($lockStat['mode'] & 0170000) !== 0100000 || $lockStat['nlink'] !== 1 || $lockStat['size'] !== 0) throw new RuntimeException('The configured request lock is missing or invalid.');
        return $state + ['configured' => true, 'revision' => hash('sha256', $raw)];
    }

    /** Setup is a deliberate owner mutation; it cannot retroactively drain old PHP processes. */
    public static function setup(string $root, Config $config, string $expectedConfigHash, callable $authorize): array
    {
        $authorize();
        $config->update(function (array &$values) use ($root, $config, $authorize): void {
            $authorize();
            if (($values['installed'] ?? null) !== true || ($values['operations']['enabled'] ?? false) !== false) throw new RuntimeException('Operations are already configured or this installation is not ready.');
            if (isset($values['operations']) && !is_array($values['operations'])) throw new RuntimeException('The saved operations configuration needs review.');
            $directory = self::directory($root); OperationsFiles::directory($directory, true);
            if (file_exists($directory . '/gate.json') || file_exists($directory . '/request.lock')) throw new RuntimeException('An earlier operations setup needs the reviewed CLI repair command. Existing files were preserved.');
            self::signingKey($config);
            OperationsFiles::writeNew($directory . '/request.lock', '');
            OperationsFiles::writeNew($directory . '/gate-control.lock', '');
            $state = ['schema'=>1,'installation_hash'=>hash('sha256',(string)$values['installation_id']),
                'nonce'=>bin2hex(random_bytes(16)),'created_at'=>time(),'ready'=>false,'recovery'=>true];
            self::save($root, $config, $state); $authorize(); $values['operations']['enabled'] = true;
        }, $expectedConfigHash);
        return self::status($root, $config);
    }

    /** CLI-only caller must attest that pre-setup request/cron processes have ended. */
    public static function activate(string $root, Config $config, string $revision, bool $quiesced): array
    {
        if (PHP_SAPI !== 'cli' || !$quiesced) throw new RuntimeException('Restart or drain the earlier PHP and cron processes, then confirm that prerequisite in the CLI activation command.');
        $handle = self::exclusive($root, $config, false);
        try {
            $status = self::status($root, $config);
            if (!hash_equals($status['revision'], $revision)) throw new RuntimeException('The gate changed after review. Inspect the current Operations status.');
            $state = array_intersect_key($status, array_flip(['schema','installation_hash','nonce','created_at','ready','recovery']));
            $state['ready'] = true; $state['recovery'] = false; self::save($root, $config, $state);
            return self::status($root, $config);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public static function setRecovery(string $root, Config $config, bool $enabled, string $revision, callable $authorize): array
    {
        $authorize(); $path = self::directory($root) . '/gate-control.lock'; OperationsFiles::noLinks($path);
        $control = @fopen($path, 'r+b');
        if ($control === false) throw new RuntimeException('The existing gate control lock is missing; use the reviewed CLI repair command.');
        try {
            $stat = fstat($control); $named = lstat($path);
            if ($stat === false || $named === false || ($named['mode'] & 0170000) !== 0100000 || $named['nlink'] !== 1
                || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev'] || !flock($control, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another operations setting is being saved. Reload and retry.');
            $authorize(); $status = self::status($root, $config);
            if (!$status['configured'] || !$status['ready'] || !hash_equals($status['revision'], $revision)) throw new RuntimeException('The operations gate changed or still needs its first CLI activation.');
            $state = array_intersect_key($status, array_flip(['schema','installation_hash','nonce','created_at','ready','recovery']));
            $state['recovery'] = $enabled; $authorize(); self::save($root, $config, $state);
            return self::status($root, $config);
        } finally { flock($control, LOCK_UN); fclose($control); }
    }

    /** A worker holds this existing inode until its snapshot and source checks finish. */
    public static function exclusive(string $root, Config $config, bool $requireReady = true)
    {
        if (is_resource(self::$requestLock)) throw new RuntimeException('Queue this operation and let the CLI worker take its exclusive snapshot lock.');
        self::assertNotQuarantined($root);
        $status = self::status($root, $config);
        if (!$status['configured'] || ($requireReady && !$status['ready'])) throw new RuntimeException('Complete Operations setup and reviewed CLI activation before running a backup.');
        $handle = self::openLock($root); $until = microtime(true) + 30;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::sameLock($root, $handle); $current = self::status($root, $config);
                if (!$current['configured'] || ($requireReady && !$current['ready'])) { flock($handle, LOCK_UN); fclose($handle); throw new RuntimeException('The operations gate changed while draining requests.'); }
                return $handle;
            }
            usleep(50000);
        } while (microtime(true) < $until);
        fclose($handle); throw new RuntimeException('Requests are still using the installation. The snapshot was not started; retry after they finish.');
    }

    public static function repair(string $root, Config $config, bool $quiesced): array
    {
        if (PHP_SAPI !== 'cli' || !$quiesced || $config->get('installed') !== true) throw new RuntimeException('Repair requires a confirmed stopped PHP/cron runtime and an installed configuration.');
        $directory = self::directory($root); OperationsFiles::directory($directory, true);
        // Never replace/delete a valid mutex inode. A missing one is recreated
        // only after this explicit operator process-drain confirmation.
        $path = $directory . '/request.lock'; OperationsFiles::noLinks($path);
        if (!file_exists($path)) OperationsFiles::writeNew($path, '');
        $control = $directory . '/gate-control.lock'; OperationsFiles::noLinks($control);
        if (!file_exists($control)) OperationsFiles::writeNew($control, '');
        $handle = self::openLock($root);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new RuntimeException('A runtime still holds the operations lock. Repair was refused.'); }
        try {
            $state = ['schema' => 1, 'installation_hash' => hash('sha256', (string) $config->get('installation_id', '')),
                'nonce' => bin2hex(random_bytes(16)), 'created_at' => time(), 'ready' => false, 'recovery' => true];
            self::save($root, $config, $state);
            $config->update(static function (array &$values): void {
                if (isset($values['operations']) && !is_array($values['operations'])) throw new RuntimeException('The saved operations configuration needs review.');
                $values['operations']['enabled'] = true;
            });
            return self::status($root, $config);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private static function directory(string $root): string { return OperationsFiles::root($root) . '/storage/private/operations'; }
    private static function signingKey(Config $config): string
    {
        $key = $config->get('security.app_key'); $id = $config->get('installation_id');
        if (!is_string($key) || strlen($key) < 32 || !is_string($id) || $id === '') throw new RuntimeException('Verified installation identity and signing material are required.');
        return hash_hmac('sha256', 'operations-gate-v1|' . $id, $key, true);
    }
    private static function save(string $root, Config $config, array $state): void
    {
        OperationsFiles::replace(self::directory($root) . '/gate.json', BackupArchive::json(['state' => $state,
            'signature' => hash_hmac('sha256', BackupArchive::json($state), self::signingKey($config))]) . "\n");
    }
    private static function openLock(string $root)
    {
        $path = self::directory($root) . '/request.lock'; OperationsFiles::noLinks($path);
        $handle = @fopen($path, 'r+b');
        if ($handle === false) throw new RuntimeException('The existing operations lock could not be opened.');
        try { self::sameLock($root, $handle); return $handle; }
        catch (Throwable $error) { fclose($handle); throw $error; }
    }
    private static function sameLock(string $root, $handle): void
    {
        $path = self::directory($root) . '/request.lock'; OperationsFiles::noLinks($path); $opened = fstat($handle); $named = @lstat($path);
        if ($opened === false || $named === false || ($named['mode'] & 0170000) !== 0100000 || $named['nlink'] !== 1 || $named['size'] !== 0
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) throw new RuntimeException('The operations lock changed. Existing work was held for review.');
    }
    private static function ownerEntry(string $root): bool
    {
        return PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === $root . '/admin/index.php'
            && in_array($_GET['page'] ?? null, ['login','logout','operations','security'], true);
    }
    private static function unavailable(string $message): never
    {
        if (PHP_SAPI === 'cli') { fwrite(STDERR, $message . PHP_EOL); exit(75); }
        http_response_code(503); header('Retry-After: 60'); header('Cache-Control: no-store, private'); header('Content-Type: text/plain; charset=utf-8');
        exit($message);
    }
}
