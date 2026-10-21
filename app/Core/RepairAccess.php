<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Establish current authentication before an older cached bootstrap can run. */
final class RepairAccess
{
    public static function prepare(string $root): void
    {
        clearstatcache(true);
        $root = rtrim($root, '/');
        if ($root === '' || realpath($root) !== $root || is_link($root)) {
            throw new RuntimeException('The active installation path needs a hosting review.', 503);
        }
        $manifestPath = self::regularFile($root, 'includes/runtime-build.json');
        if ($manifestPath === null || @filesize($manifestPath) > 2_000_000) {
            throw new RuntimeException('The update file inventory is missing or unreadable. Extract the complete Upgrade ZIP again.', 503);
        }
        $raw = @file_get_contents($manifestPath);
        try { $manifest = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('The update file inventory is invalid. Extract the complete Upgrade ZIP again.', 503); }
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['product'] ?? null) !== 'Velvet Vixxen'
            || !is_string($manifest['version'] ?? null) || !preg_match('/\A\d+\.\d+\.\d+\z/D', $manifest['version'])
            || !is_string($manifest['build_id'] ?? null) || !preg_match('/\A\d{8}\.\d+\z/D', $manifest['build_id'])
            || !is_array($manifest['files'] ?? null) || $manifest['files'] === [] || count($manifest['files']) > 2000) {
            throw new RuntimeException('The update file inventory is invalid. Extract the complete Upgrade ZIP again.', 503);
        }
        $files = $manifest['files'];
        $dependencies = ['app/Core/Auth.php', 'app/Core/AdminSecurity.php', 'app/Core/AdminSecurityStoreTrait.php',
            'app/Core/Store.php', 'app/Core/Config.php', 'app/Core/Database.php', 'app/Core/SecureValue.php',
            'app/Core/Totp.php', 'app/Core/OperationsGate.php', 'app/Core/OperationsFiles.php',
            'app/Core/BackupArchive.php', 'app/Views/admin/repair-signin.php'];
        foreach (array_merge($dependencies, ['admin/repair.php', 'app/Core/RepairAccess.php',
            'app/Core/RuntimeInventoryService.php', 'includes/autoload.php', 'includes/bootstrap.php',
            'includes/security.php', 'includes/version.php', 'app/Support/functions.php']) as $relative) {
            if (!array_key_exists($relative, $files)) self::missing($relative);
        }
        // No cache entry is touched until every declared on-disk byte has passed.
        self::verify($root, $files);
        $pending = ['app/Core/Store.php']; $seen = [];
        while ($pending !== []) {
            $relative = array_pop($pending);
            if (isset($seen[$relative])) continue;
            $seen[$relative] = true;
            $source = (string) @file_get_contents($root . '/' . $relative);
            preg_match_all('~^\h+use\h+([A-Za-z][A-Za-z0-9]*StoreTrait)\h*;~m', $source, $matches);
            foreach ($matches[1] as $trait) {
                $traitPath = 'app/Core/' . $trait . '.php';
                if (!array_key_exists($traitPath, $files)) self::missing($traitPath);
                $dependencies[] = $traitPath; $pending[] = $traitPath;
            }
        }
        $dependencies = array_values(array_unique($dependencies));
        $loaded = get_included_files();
        foreach (array_merge($dependencies, ['app/Core/RuntimeInventoryService.php']) as $relative) {
            if (in_array($root . '/' . $relative, $loaded, true)) {
                throw new RuntimeException('Authentication code was already loaded before update verification. Ask hosting support to restart this site\'s PHP workers.', 503);
            }
        }
        // Enforce the current distribution's separate dependency floor as well.
        self::refresh($root, 'app/Core/RuntimeInventoryService.php', $files['app/Core/RuntimeInventoryService.php']);
        require_once $root . '/app/Core/RuntimeInventoryService.php';
        try {
            if (!(new RuntimeInventoryService($root))->inspect()['files_match']) {
                throw new RuntimeException('The update file inventory is incomplete. Extract the complete Upgrade ZIP again.');
            }
        } catch (RuntimeException $error) { throw new RuntimeException($error->getMessage(), 503, $error); }
        foreach ($dependencies as $relative) self::refresh($root, $relative, $files[$relative]);
        clearstatcache(true);
        if (self::regularFile($root, 'includes/runtime-build.json') !== $manifestPath || @file_get_contents($manifestPath) !== $raw) {
            throw new RuntimeException('Application files changed during verification. Finish extracting the update and reload.', 503);
        }
        self::verify($root, $files);
    }

    private static function missing(string $relative): never
    {
        throw new RuntimeException('The update is incomplete. Missing file inventory entry: ' . $relative, 503);
    }

    private static function verify(string $root, array $files): void
    {
        foreach ($files as $relative => $expected) {
            if (!is_string($relative) || $relative === 'includes/runtime-build.json'
                || !is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/D', $expected)) {
                throw new RuntimeException('The update file inventory contains an invalid runtime entry. Extract the complete Upgrade ZIP again.', 503);
            }
            $path = self::regularFile($root, $relative);
            if ($path === null) throw new RuntimeException('The update is incomplete. Missing, unreadable or linked file: ' . $relative, 503);
            $actual = @hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                throw new RuntimeException('The update is incomplete. Different file contents: ' . $relative, 503);
            }
        }
    }

    private static function refresh(string $root, string $relative, string $expected): void
    {
        clearstatcache(true);
        $path = self::regularFile($root, $relative);
        if ($path === null || !hash_equals($expected, (string) @hash_file('sha256', $path))) {
            throw new RuntimeException('Application files changed during verification. Finish extracting the update and reload.', 503);
        }
        $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN));
        if ($enabled && (!function_exists('opcache_invalidate') || !@opcache_invalidate($path, true))) {
            throw new RuntimeException('This host could not refresh current authentication. Ask hosting support to clear this site\'s PHP OPcache, then reload.', 503);
        }
    }

    private static function regularFile(string $root, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '\\') || preg_match('/[\x00-\x20\x7f]/', $relative)) return null;
        $parts = explode('/', $relative);
        foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..'
            || (str_starts_with($part, '.') && $part !== '.htaccess')) return null;
        if (count($parts) === 1) {
            if (!in_array($relative, ['.htaccess', 'index.php', 'route.php', 'VERSION'], true)) return null;
        } elseif (!in_array($parts[0], ['admin', 'app', 'images', 'includes', 'public', 'templates', 'vendor', 'database', 'addons'], true)) return null;
        $path = $root;
        foreach ($parts as $part) { $path .= '/' . $part; if (is_link($path)) return null; }
        $real = realpath($path); $stat = @lstat($path);
        return $real !== false && $real === $path && str_starts_with($real, $root . '/') && is_array($stat)
            && ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1 && is_readable($real) ? $real : null;
    }

    /** Only current journal-backed sessions, passwords and enabled factors are accepted. */
    public static function authentication(Auth $auth, string $method, array $input): array
    {
        self::cleanSession();
        $user = $auth->user();
        if ($user !== null && ($user['role'] ?? '') !== 'owner') {
            throw new RuntimeException('Only the active site owner can verify an update or repair application folders.', 403);
        }
        $recent = false;
        if ($user !== null) {
            try { $auth->requireRecentOwner(); $recent = true; }
            catch (RuntimeException $error) { if ($error->getCode() !== 428) throw $error; }
        }
        $mode = $user !== null ? 'reauth' : ($auth->pending() ? 'factor' : 'signin');
        $result = ['ready' => $recent, 'mode' => $mode, 'status' => 200, 'error' => '', 'redirect' => false,
            'factor' => $user !== null && $auth->security()->enabled((string) $user['id'])];
        if ($method !== 'POST') return $result;
        $csrf = $input['_csrf'] ?? null;
        if (!is_string($csrf) || !is_string($_SESSION['_csrf'] ?? null) || !hash_equals($_SESSION['_csrf'], $csrf)) {
            return array_replace($result, ['ready' => false, 'status' => 419, 'error' => 'Your session token expired. Reload this page and try again.']);
        }
        if ($recent) return $result;
        $action = $input['action'] ?? null;
        $string = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $accepted = false;
        if ($mode === 'signin' && $action === 'repair_signin') {
            $accepted = $auth->attempt(trim($string('email')), $string('password'));
            if (!$accepted && $auth->pending()) return array_replace($result, ['mode' => 'factor', 'redirect' => true]);
        } elseif ($mode === 'factor' && $action === 'repair_factor') {
            $accepted = $auth->completeChallenge($string('code'));
        } elseif ($mode === 'reauth' && $action === 'repair_reauth') {
            $accepted = $auth->reauthenticate($string('password'), $string('code'));
        }
        if ($accepted) {
            $auth->requireRecentOwner();
            return array_replace($result, ['ready' => true, 'redirect' => true]);
        }
        return array_replace($result, ['status' => $mode === 'reauth' ? 428 : 401,
            'error' => $mode === 'factor' ? 'The authenticator or recovery code was not accepted. Try again.'
                : 'Confirm the existing owner account with the correct credentials before continuing.']);
    }

    private static function cleanSession(): void
    {
        foreach (['admin', 'admin_pending'] as $key) {
            $record = $_SESSION[$key] ?? null;
            if ($record === null) continue;
            $valid = is_array($record) && (is_string($record['id'] ?? null) || is_int($record['id'] ?? null))
                && is_string($record['email'] ?? null) && is_string($record['stamp'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $record['stamp']) && is_int($record['generation'] ?? null);
            if ($key === 'admin') $valid = $valid && is_string($record['token'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $record['token']) && is_int($record['authenticated_at'] ?? null);
            else $valid = $valid && is_string($record['id'] ?? null) && is_int($record['expires'] ?? null);
            if (!$valid) unset($_SESSION[$key]);
        }
        if (isset($_SESSION['_csrf']) && (!is_string($_SESSION['_csrf']) || !preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['_csrf']))) unset($_SESSION['_csrf']);
        if (isset($_SESSION['_flash']) && !is_array($_SESSION['_flash'])) unset($_SESSION['_flash']);
    }

    public static function status(int $status): void
    {
        if ($status === 419) header('HTTP/1.1 419 Form session expired', true, 419);
        else http_response_code($status);
    }

    public static function unavailable(string $message): never
    {
        http_response_code(503);
        header('Cache-Control: no-store, private, max-age=0'); header('Content-Type: text/html; charset=utf-8');
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Complete the update · Velvet Vixxen</title><style>body{margin:0;background:#130b16;color:#faeef8;font:18px/1.6 system-ui}main{max-width:680px;margin:8vh auto;padding:24px}h1{color:#ff62bd}p{overflow-wrap:anywhere}</style>'
            . '<main data-update-status="incomplete"><h1>Complete the update</h1><p>' . $safe . '</p>'
            . '<p>Extract the complete current Upgrade ZIP into this website folder, then reload this page.</p></main></html>';
        exit;
    }
}
