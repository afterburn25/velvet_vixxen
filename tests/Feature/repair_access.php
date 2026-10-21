<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\RepairAccess;
use App\Core\Totp;

$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-repair-access-' . bin2hex(random_bytes(8));
$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    @rmdir($path);
};
$manifest = ['schema' => 1, 'product' => 'Velvet Vixxen', 'version' => '0.9.0', 'build_id' => '20261021.4', 'files' => []];
$save = static function (array $data) use ($fixture): void { file_put_contents($fixture . '/includes/runtime-build.json', json_encode($data, JSON_THROW_ON_ERROR)); };
$run = static function (string $before = '') use ($fixture, $source): array {
    $program = 'require ' . var_export($source . '/app/Core/RepairAccess.php', true) . '; ' . $before
        . ' try { App\\Core\\RepairAccess::prepare(' . var_export($fixture, true) . '); echo json_encode(["ok"=>true,"loaded"=>get_included_files(),"version"=>defined("VELVET_VIXXEN_BUILD_ID")]); }'
        . ' catch(Throwable $error) { echo json_encode(["ok"=>false,"code"=>$error->getCode(),"message"=>$error->getMessage()]); }';
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=stderr', '-r', $program],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Isolated verification process unavailable.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '') throw new RuntimeException('Prebootstrap verification emitted PHP errors: ' . $errors);
    return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
};
$reject = static function (string $why, string $contains = '') use ($run, $assert): void {
    $result = $run();
    $assert(!$result['ok'] && $result['code'] === 503 && ($contains === '' || str_contains($result['message'], $contains)), $why);
};
$env = null;
try {
    mkdir($fixture, 0700, true);
    // Real source files exercise the current independent inventory floor and
    // recursive trait graph. The browser gate separately checks customer ZIPs.
    $paths = ['.htaccess', 'index.php', 'route.php', 'VERSION'];
    foreach (['admin', 'app', 'images', 'includes', 'public', 'templates', 'vendor'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink()) $paths[] = substr($file->getPathname(), strlen($source) + 1);
        }
    }
    foreach (glob($source . '/database/migrations/*.php') ?: [] as $path) $paths[] = substr($path, strlen($source) + 1);
    foreach ($paths as $relative) {
        if ($relative === 'includes/runtime-build.json') continue;
        $path = $fixture . '/' . $relative; if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        copy($source . '/' . $relative, $path); $manifest['files'][$relative] = hash_file('sha256', $path);
    }
    $save($manifest);
    $good = $run(); $assert($good['ok'], 'Complete verified source inventory was rejected.');
    $assert(!$good['version'], 'Verification activated application version before owner confirmation.');
    $assert(count($good['loaded']) === 2 && in_array($fixture . '/app/Core/RuntimeInventoryService.php', $good['loaded'], true),
        'Verification loaded application/bootstrap/authentication before its controlled entry.');
    $preserved = true;
    foreach ($manifest['files'] as $relative => $digest) if (hash_file('sha256', $fixture . '/' . $relative) !== $digest) $preserved = false;
    $assert($preserved, 'Verification changed installed runtime bytes.');
    unlink($fixture . '/includes/runtime-build.json'); $reject('Missing inventory was accepted.', 'missing or unreadable'); $save($manifest);
    file_put_contents($fixture . '/includes/runtime-build.json', '{bad'); $reject('Malformed inventory was accepted.', 'invalid'); $save($manifest);
    foreach (['schema' => 2, 'product' => 'Other', 'version' => ['0.9.0'], 'build_id' => 'bad', 'files' => []] as $field => $value) {
        $changed = $manifest; $changed[$field] = $value; $save($changed); $reject('Invalid manifest field accepted: ' . $field); $save($manifest);
    }
    foreach (['app/Core/Auth.php', 'app/Core/MerchandiseStoreTrait.php', 'public/assets/js/scene-depth.js'] as $relative) {
        $changed = $manifest; unset($changed['files'][$relative]); $save($changed);
        $reject('A self-consistent manifest could omit required dependency: ' . $relative); $save($manifest);
    }
    foreach (['app/Core/RepairAccess.php', 'app/Core/AdminSecurity.php', 'app/Core/MerchandiseStoreTrait.php'] as $relative) {
        $content = file_get_contents($fixture . '/' . $relative); unlink($fixture . '/' . $relative);
        $reject('Missing physical dependency was accepted: ' . $relative, $relative); file_put_contents($fixture . '/' . $relative, $content);
    }
    $asset = $fixture . '/public/assets/js/composer.js'; $content = file_get_contents($asset);
    file_put_contents($asset, $content . "\n// partial upload\n"); $reject('Mixed complete-manifest bytes were accepted.', 'public/assets/js/composer.js'); file_put_contents($asset, $content);
    foreach (['../VERSION', 'app/../VERSION', '/VERSION', 'app//Core/Auth.php', 'app/Core/./Auth.php', 'app\\Core\\Auth.php', 'config/app.php'] as $path) {
        $changed = $manifest; $changed['files'][$path] = hash('sha256', 'sentinel'); $save($changed); $reject('Unsafe runtime path was accepted: ' . $path); $save($manifest);
    }
    $changed = $manifest; $changed['files']['app/Core/Auth.php'] = str_repeat('z', 64); $save($changed); $reject('Malformed digest was accepted.'); $save($manifest);
    unlink($asset); symlink($source . '/public/assets/js/composer.js', $asset); $reject('Linked runtime file was accepted.', 'linked file'); unlink($asset); file_put_contents($asset, $content);
    rename($fixture . '/public/assets/js', $fixture . '/public/assets/js-original'); symlink($fixture . '/public/assets/js-original', $fixture . '/public/assets/js');
    $reject('Linked runtime directory was accepted.', 'linked file'); unlink($fixture . '/public/assets/js'); rename($fixture . '/public/assets/js-original', $fixture . '/public/assets/js');
    link($asset, $fixture . '/asset-alias'); $reject('Hard-linked runtime file was accepted.', 'linked file'); unlink($fixture . '/asset-alias');
    $early = $run('require ' . var_export($fixture . '/app/Core/Auth.php', true) . ';');
    $assert(!$early['ok'] && $early['code'] === 503 && str_contains($early['message'], 'already loaded'), 'Already-loaded authentication could masquerade as freshly verified.');
    $assert($run()['ok'], 'Restored complete inventory did not recover.');

    require $source . '/tests/TestEnvironment.php';
    $env = velvet_test_environment('repair-access'); $store = $env['store']; $auth = new Auth($store);
    $id = (string) $store->createAdmin('Repair Owner', 'repair-owner@example.test', 'RepairOwner12!');
    $original = $store->rawFileData(); $keys = file_get_contents($env['configPath']);
    $_SESSION = ['admin' => ['id' => $id, 'email' => 'repair-owner@example.test', 'role' => 'owner'], '_csrf' => str_repeat('a', 64)];
    $result = RepairAccess::authentication($auth, 'GET', []);
    $assert(!$result['ready'] && $result['mode'] === 'signin' && !isset($_SESSION['admin']), 'Legacy owner cookie was promoted.');
    $assert($store->rawFileData() === $original, 'Read-only legacy-cookie rejection wrote installed data.');
    $_SESSION['admin'] = 'malformed'; $_SESSION['admin_pending'] = ['id' => ['bad']]; $_SESSION['_csrf'] = [];
    $assert(!RepairAccess::authentication($auth, 'GET', [])['ready'], 'Malformed session shapes were authorized.');
    $token = csrf_token();
    $login = ['action' => 'repair_signin', 'email' => 'repair-owner@example.test', 'password' => 'RepairOwner12!', '_csrf' => $token];
    $result = RepairAccess::authentication($auth, 'POST', array_replace($login, ['_csrf' => 'wrong']));
    $assert($result['status'] === 419 && !$result['ready'], 'Password submission accepted invalid CSRF.');
    $assert($store->rawFileData() === $original, 'Invalid-CSRF login wrote an authentication journal.');
    $result = RepairAccess::authentication($auth, 'POST', $login);
    $assert($result['ready'] && $result['redirect'] && $auth->check(), 'Current owner password did not authorize repair.');
    $assert(RepairAccess::authentication($auth, 'GET', [])['ready'], 'Current recent owner session was refused.');
    $_SESSION['admin']['authenticated_at'] = time() - 901;
    $result = RepairAccess::authentication($auth, 'POST', ['action' => 'refresh_php_cache', '_csrf' => $token]);
    $assert(!$result['ready'] && $result['mode'] === 'reauth' && $result['status'] === 428, 'Expired owner session refreshed application PHP.');
    $result = RepairAccess::authentication($auth, 'POST', ['action' => 'repair_reauth', '_csrf' => $token, 'password' => 'RepairOwner12!']);
    $assert($result['ready'] && $result['redirect'], 'Password confirmation failed to renew owner access.');
    $secret = Totp::encode('12345678901234567890');
    $codes = $auth->security()->enable($id, $secret, Totp::code($secret, intdiv(time(), 30)), fn() => $auth->requireRecentOwner());
    $result = RepairAccess::authentication($auth, 'POST', $login);
    $assert(!$result['ready'] && $result['mode'] === 'factor' && $result['redirect'] && !$auth->check(), 'Password bypassed current two-factor settings.');
    $factor = ['action' => 'repair_factor', '_csrf' => $token, 'code' => $codes[0]];
    $assert(!RepairAccess::authentication($auth, 'POST', array_replace($factor, ['code' => 'wrong']))['ready'], 'Incorrect second factor was accepted.');
    $assert(!RepairAccess::authentication($auth, 'POST', ['action' => 'refresh_php_cache', '_csrf' => $token])['ready'], 'Pending-factor session activated application PHP.');
    $assert(RepairAccess::authentication($auth, 'POST', $factor)['ready'], 'Unused recovery code could not finish current owner authentication.');
    $assert(!$auth->security()->consumeFactor($id, $codes[0]), 'Used repair recovery code was replayable.');
    $_SESSION['admin']['authenticated_at'] = time() - 901;
    $reauth = ['action' => 'repair_reauth', '_csrf' => $token, 'password' => 'RepairOwner12!'];
    $assert(!RepairAccess::authentication($auth, 'POST', $reauth)['ready'], 'Privileged confirmation bypassed enabled factor.');
    $assert(RepairAccess::authentication($auth, 'POST', $reauth + ['code' => $codes[1]])['ready'], 'Password and unused recovery code did not renew owner access.');
    $after = $store->rawFileData(); unset($after['settings']['_private_admin_security_v1']);
    $assert($after === $original && file_get_contents($env['configPath']) === $keys, 'Repair authentication changed data beyond the deliberate security journal.');
    $changed = $store->rawFileData(); $changed['admins'][$id]['role'] = 'editor'; $store->replaceFileData($changed);
    $denied = false; try { RepairAccess::authentication($auth, 'GET', []); } catch (RuntimeException $error) { $denied = $error->getCode() === 403; }
    $assert($denied, 'A currently demoted owner retained repair access.');
    echo "Repair access source passed ($checks checks).\n";
} finally { if ($env !== null) velvet_test_cleanup($env); $remove($fixture); }
