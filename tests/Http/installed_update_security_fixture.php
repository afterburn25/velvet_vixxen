<?php
declare(strict_types=1);

// CLI-only controls for the owned, disposable historical installation. Never
// served or packaged. Existing customer paths/configuration cannot qualify.
$root = realpath($argv[1] ?? ''); $action = $argv[2] ?? '';
if (PHP_SAPI !== 'cli' || !$root || !str_starts_with($root, sys_get_temp_dir() . '/vvx-installed-update-')
    || is_link($root) || @file_get_contents($root . '/.vvx-test-runtime') !== "Disposable pinned historical update acceptance.\n") {
    throw new RuntimeException('An owned historical-update temporary installation is required.');
}
$values = require $root . '/config/app.php';
$lock = json_decode((string) file_get_contents($root . '/storage/install.lock'), true, 32, JSON_THROW_ON_ERROR);
if (($values['storage_mode'] ?? '') !== 'file' || ($values['file_store_path'] ?? '') !== 'storage/data.json'
    || parse_url($values['base_url'] ?? '', PHP_URL_SCHEME) !== 'http'
    || parse_url($values['base_url'] ?? '', PHP_URL_HOST) !== '127.0.0.1'
    || !str_starts_with($values['installation_id'] ?? '', 'upload-fixture-')
    || ($values['installation_id'] ?? '') !== ($lock['installation_id'] ?? '') || ($values['base_url'] ?? '') !== ($lock['base_url'] ?? '')) {
    throw new RuntimeException('The exact loopback file-store fixture identity is required.');
}
define('ROOT_PATH', $root);
$source = dirname(__DIR__, 2);
// Current security can be enrolled before the historical server starts. The
// server itself continues to execute the untouched pinned old customer package.
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require $source . '/app/Support/functions.php';
$store = new App\Core\Store(new App\Core\Config($root . '/config/app.php'));
$owner = $store->findAdminByEmail('owner@example.test');
if (!$owner || ($owner['id'] ?? '') !== 'upload-fixture-owner' || ($owner['role'] ?? '') !== 'owner'
    || empty($owner['is_active']) || !password_verify('VelvetDemo12', (string) ($owner['password_hash'] ?? ''))) {
    throw new RuntimeException('The synthetic historical-update owner is required.');
}
$security = new App\Core\AdminSecurity($store);
$secret = App\Core\Totp::encode('12345678901234567890');
if ($action === 'enable-factor') {
    if ($security->enabled('upload-fixture-owner')) throw new RuntimeException('Fixture factor is already enabled.');
    $codes = $security->enable('upload-fixture-owner', $secret,
        App\Core\Totp::code($secret, intdiv(time(), 30) - 1), static function (): void {});
    echo json_encode(['recovery' => $codes], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'code') { echo json_encode(['code' => App\Core\Totp::code($secret, intdiv(time(), 30))], JSON_THROW_ON_ERROR); exit; }
if ($action === 'state') { echo json_encode($security->overview('upload-fixture-owner'), JSON_THROW_ON_ERROR); exit; }
if ($action === 'age-session') {
    $id = $argv[3] ?? '';
    if (!is_string($id) || !preg_match('/\A[A-Za-z0-9,-]{20,128}\z/D', $id)) throw new RuntimeException('Invalid fixture session identifier.');
    $directory = $root . '/storage/sessions'; $path = $directory . '/sess_' . $id;
    if (is_link($path) || !is_file($path) || realpath(dirname($path)) !== $directory) throw new RuntimeException('An existing fixture session is required.');
    session_name('velvet_vixxen_admin'); session_save_path($directory); session_id($id); session_start();
    if (($_SESSION['admin']['email'] ?? '') !== 'owner@example.test' || !is_string($_SESSION['admin']['token'] ?? null)) {
        throw new RuntimeException('Only a current authenticated fixture owner session can be aged.');
    }
    $_SESSION['admin']['authenticated_at'] = time() - 901; session_write_close(); echo '{"aged":true}'; exit;
}
throw new RuntimeException('Unsupported historical-update security fixture action.');
