<?php
declare(strict_types=1);
require dirname(__DIR__) . '/TestEnvironment.php';

use App\Core\AddonLicenseHub;
use App\Core\SecureValue;
use App\Core\SignedPayload;

$env = velvet_test_environment('licensing');
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
$reject = static function (callable $operation, string $message) use (&$failures): void {
    try { $operation(); $failures[] = $message; } catch (RuntimeException) {}
};
try {
    $keys = SignedPayload::generateKeyPair();
    $payload = ['status' => 'active', 'nested' => ['b' => 2, 'a' => 1]];
    $signature = SignedPayload::sign($payload, $keys['private']);
    $assert(SignedPayload::verify(['nested' => ['a' => 1, 'b' => 2], 'status' => 'active'], $signature, $keys['public']), 'Canonical signature rejected reordered object keys.');
    $assert(!SignedPayload::verify(array_replace($payload, ['status' => 'revoked']), $signature, $keys['public']), 'Tampered license payload verified.');
    $assert(!SignedPayload::verify($payload, 'invalid-base64', $keys['public']), 'Malformed signature verified.');
    $crypto = new SecureValue($env['config']);
    $plain = 'fixture-' . bin2hex(random_bytes(24));
    $cipher = $crypto->encrypt($plain);
    $assert(!str_contains($cipher, $plain) && $crypto->decrypt($cipher) === $plain, 'Local license encryption failed its round trip.');
    $parts = explode(':', $cipher, 3);
    $raw = base64_decode($parts[2], true); $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $reject(fn() => $crypto->decrypt($parts[0] . ':' . $parts[1] . ':' . base64_encode($raw)), 'Tampered license ciphertext decrypted.');

    $hub = new AddonLicenseHub($env['store'], $env['config']);
    $product = $hub->createProduct(['productKey' => 'fixture.single', 'addonKey' => 'velvet.welcome-banner', 'name' => 'Fixture license', 'active' => true, 'licenseType' => 'lifetime', 'siteLimit' => 1]);
    $issued = $hub->issueLicense(['productKey' => $product['productKey'], 'customerEmail' => 'fixture@example.test']);
    $request = ['license_key' => $issued['licenseKey'], 'addon_id' => 'velvet.welcome-banner', 'product_id' => $product['productKey'], 'installation_id' => 'fixture-site-one', 'site_url' => 'https://one.example.test'];
    $envelope = $hub->activate($request);
    $public = $hub->ensureSigningKeys()['public'];
    $assert(SignedPayload::verify($envelope['payload'], $envelope['signature'], $public), 'Hub activation response was not authentically signed.');
    $assert(!str_contains((string) file_get_contents($env['dataPath']), $issued['licenseKey']), 'Raw issued license key leaked into persistence.');
    $reject(fn() => $hub->activate(array_replace($request, ['installation_id' => 'fixture-site-two', 'site_url' => 'https://two.example.test'])), 'Site limit allowed a second active installation.');
    $reject(fn() => $hub->check(array_replace($request, ['site_url' => 'https://different.example.test'])), 'Domain binding permitted another domain.');
    $reject(fn() => $hub->check(array_replace($request, ['addon_id' => 'wrong.addon'])), 'License was accepted for another add-on.');
    $hub->deactivate($request);
    $reject(fn() => $hub->check($request), 'Deactivated installation remained authorized.');
} catch (Throwable $e) { $failures[] = $e->getMessage(); }
finally {
    velvet_test_cleanup($env);
    foreach (['hub-private.key','hub-public.key'] as $name) @unlink($env['root'] . '/storage/private/licensing/' . $name);
    @rmdir($env['root'] . '/storage/private/licensing');
}
velvet_finish_test('Add-on licensing, signature and encrypted-storage regressions', $failures);
