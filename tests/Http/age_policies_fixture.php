<?php
declare(strict_types=1);

$root = realpath((string) ($argv[1] ?? '')); $action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !$root || !str_starts_with($root, sys_get_temp_dir() . '/vvx-protection-') || !is_file($root . '/.vvx-test-runtime')) throw new RuntimeException('An owned age/policy package fixture is required.');
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
if (($ownership['runtime'] ?? null) !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true) || parse_url($ownership['base'] ?? '', PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('The loopback fixture ownership does not match.');
define('ROOT_PATH', $root); require_once $root . '/includes/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (app_config('base_url') !== $ownership['base'] || app_config('storage_mode') !== $ownership['mode']) throw new RuntimeException('Fixture configuration changed.');
$config = app_config(); $state = new App\Core\PrivateState($config); $saved = $root . '/storage/temp/age-access-fixture-backup.json';
if ($action === 'snapshot') {
    $policies = $state->read('public_policies'); $settings = $config->get('age_access'); $keySaved = is_array($settings) && ($settings['api_key_encrypted'] ?? '') !== '';
    if (is_array($settings)) unset($settings['api_key_encrypted']);
    $version = null; $independent = null;
    if ($ownership['mode'] === 'mysql') {
        $pdo = App\Core\Database::connect($config->get('db')); $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $statement = $pdo->prepare('SELECT payload_json FROM private_state WHERE namespace=?'); $statement->execute(['public_policies']); $json = $statement->fetchColumn();
        $independent = $json === false ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR)['data'];
    } else {
        $file = $root . '/storage/private/operations/public_policies.json';
        $independent = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['data'] : [];
    }
    echo json_encode(['mode' => $ownership['mode'], 'php' => PHP_VERSION, 'mysql_version' => $version, 'policies' => $policies, 'independent_policies' => $independent,
        'settings' => $settings, 'provider_key_saved' => $keySaved, 'config_sha256' => hash_file('sha256', $config->path()), 'age_requests' => $state->read('age_requests'),
        'protection_sha256' => hash('sha256', json_encode($state->read('protection'), JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'corrupt-access') {
    if (is_file($saved)) throw new RuntimeException('A corruption fixture already exists.');
    file_put_contents($saved, json_encode($config->get('age_access'), JSON_THROW_ON_ERROR)); chmod($saved, 0600);
    $config->update(static function (array &$values): void { $values['age_access']['mode'] = 'unknown-provider-mode'; }); echo '{}'; exit;
}
if ($action === 'restore-access') {
    if (!is_file($saved)) throw new RuntimeException('No owned corrupted settings exist.');
    $restored = json_decode((string) file_get_contents($saved), true, 512, JSON_THROW_ON_ERROR);
    $config->update(static function (array &$values) use ($restored): void { $values['age_access'] = $restored; }); unlink($saved); echo '{}'; exit;
}
if ($action === 'forge-session') {
    $token = $input['cookie'] ?? '';
    if (!is_string($token) || preg_match('/\A[a-zA-Z0-9,-]{1,128}\z/D', $token) !== 1 || !is_file($root . '/storage/sessions/sess_' . $token)) throw new RuntimeException('Choose an owned existing visitor session.');
    session_name('velvet_vixxen_admin'); session_save_path($root . '/storage/sessions'); session_id($token); session_start();
    $_SESSION['age_verified'] = true; $_SESSION['vvx_age_grant'] = ['data' => ['threshold' => 18, 'expires_at' => time() + 86400], 'signature' => str_repeat('a', 64)];
    session_write_close(); echo '{}'; exit;
}
throw new RuntimeException('Unknown age/policy fixture action.');
