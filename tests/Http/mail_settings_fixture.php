<?php
declare(strict_types=1);

// CLI-only fixture. Never ship this file or use it against an installed site.
$root = realpath((string) ($argv[1] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
    || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable temporary runtime is required.');
}
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) throw new RuntimeException('Fixture parameters must be an object.');
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$config = app_config();
$values = $config->all();
$dataPath = $root . '/storage/data.json';
$data = json_decode((string) file_get_contents($dataPath), true, 512, JSON_THROW_ON_ERROR);
$ownerId = isset($data['admins']['upload-fixture-owner']) ? 'upload-fixture-owner' : 'demo-owner';
if (!isset($data['admins'][$ownerId])
    || !in_array($data['admins'][$ownerId]['email'] ?? '', ['admin@example.com', 'owner@example.test'], true)
    || ($values['storage_mode'] ?? '') !== 'file'
    || parse_url((string) ($values['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Only the known local acceptance fixture is supported.');
}
$writeData = static function (array $next) use ($dataPath): void {
    $temporary = $dataPath . '.mail-fixture-' . bin2hex(random_bytes(5));
    file_put_contents($temporary, json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($temporary, 0600);
    if (!rename($temporary, $dataPath)) throw new RuntimeException('Fixture data activation failed.');
};
$result = [];
if ($action === 'initialize') {
    $data['admins'][$ownerId]['email'] = 'owner@example.test';
    $data['admins'][$ownerId]['name'] = 'Mail Fixture Owner';
    $data['admins'][$ownerId]['role'] = 'owner';
    $data['admins'][$ownerId]['is_active'] = 1;
    $writeData($data);
    $values['support_email'] = 'owner@example.test';
    $values['mail'] = ['mode' => 'disabled', 'host' => '', 'port' => 587, 'encryption' => 'tls',
        'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Mail Fixture',
        'fixture_future_mail_option' => ['retained' => true]];
    $values['mail_acceptance_fixture'] = ['owner' => $ownerId, 'unrelated' => 'preserve this setting'];
    $config->write($values);
} elseif (($values['mail_acceptance_fixture']['owner'] ?? '') !== $ownerId) {
    throw new RuntimeException('Initialize the private mail fixture before using it.');
} elseif ($action === 'read') {
    $result = ['config' => $values, 'owner' => $data['admins'][$ownerId],
        'mailOutbox' => $data['mailOutbox'] ?? [], 'audit' => $data['audit'] ?? []];
} elseif ($action === 'owner') {
    $role = (string) ($input['role'] ?? 'owner');
    if (!in_array($role, ['owner', 'editor'], true)) throw new RuntimeException('Unsupported fixture role.');
    $data['admins'][$ownerId]['role'] = $role;
    $data['admins'][$ownerId]['is_active'] = !empty($input['active']) ? 1 : 0;
    $writeData($data);
} elseif ($action === 'unrelated') {
    $values['mail_acceptance_fixture']['unrelated'] = 'a concurrent owner setting was preserved';
    $config->write($values);
} elseif ($action === 'member-session') {
    $memberId = isset($data['members']['upload-fixture-member']) ? 'upload-fixture-member' : 'demo-member';
    $email = (string) ($data['members'][$memberId]['email'] ?? '');
    if (!in_array($email, ['member@example.test', 'member@example.com'], true)) throw new RuntimeException('Known member fixture required.');
    session_name('velvet_vixxen_admin');
    session_save_path($root . '/storage/sessions');
    session_start();
    $loggedIn = member_auth()->attempt($email, 'VelvetMember12');
    if (empty($loggedIn['ok']) || app_auth()->check()) throw new RuntimeException('Real member-only authentication failed.');
    $result = ['name' => session_name(), 'id' => session_id(), 'csrf' => csrf_token()];
    session_write_close();
} else {
    throw new RuntimeException('Unknown mail fixture action.');
}
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
