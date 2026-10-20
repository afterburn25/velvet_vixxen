<?php
declare(strict_types=1);

// Private CLI-only fixture; never place it in the served/customer runtime.
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
require $root . '/includes/marketing.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$values = app_config()->all();
$dataPath = $root . '/storage/data.json';
$data = json_decode((string) file_get_contents($dataPath), true, 512, JSON_THROW_ON_ERROR);
$ownerId = 'upload-fixture-owner';
if (($values['storage_mode'] ?? '') !== 'file' || ($values['mail']['mode'] ?? '') !== 'disabled'
    || parse_url((string) ($values['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1'
    || ($data['admins'][$ownerId]['email'] ?? '') !== 'owner@example.test') {
    throw new RuntimeException('The known localhost audience fixture with disabled mail is required.');
}
$marketing = velvet_marketing_store();
$result = [];
if ($action === 'contact') {
    $email = $input['email'] ?? null;
    if (!is_string($email) || !str_ends_with($email, '@example.test') || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('A reserved fixture email address is required.');
    }
    $source = \Velvet\Core\MarketingAudience::text($input['source'] ?? 'alpha', 120, 'Fixture source');
    $confirmed = ($input['confirmed'] ?? true) === true;
    $date = \Velvet\Core\MarketingAudience::rules(['confirmed_after' => $input['confirmed_at'] ?? '2024-06-15T12:00:00Z'])['confirmed_after'];
    $result = $marketing->subscribe($email, 'Audience fixture contact', $source, true);
    if ($confirmed && !$marketing->confirm($result['token'])) throw new RuntimeException('Fixture contact confirmation failed.');
    $marketing->update(static function (array &$state) use ($result, $input, $confirmed, $date): void {
        foreach ($state['contacts'] as &$contact) if ($contact['id'] === $result['contact']['id']) {
            $contact['consented_at'] = '2023-01-01T00:00:00Z';
            if ($confirmed) $contact['confirmed_at'] = $date;
        }
        if (!empty($input['suppressed'])) $state['suppressions'][] = ['email' => $result['contact']['email'], 'active' => true];
        if (!empty($input['revoked'])) $state['consents'][] = ['email' => $result['contact']['email'], 'type' => 'newsletter', 'granted' => false];
    });
} elseif ($action === 'owner') {
    if (!in_array($input['role'] ?? '', ['owner', 'editor'], true) || !is_bool($input['active'] ?? null)) {
        throw new RuntimeException('A known fixture role and boolean active state are required.');
    }
    $data['admins'][$ownerId]['role'] = $input['role'];
    $data['admins'][$ownerId]['is_active'] = $input['active'] ? 1 : 0;
    $temporary = $dataPath . '.audience-fixture-' . bin2hex(random_bytes(5));
    file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($temporary, 0600);
    if (!rename($temporary, $dataPath)) throw new RuntimeException('Fixture owner activation failed.');
} else {
    throw new RuntimeException('Unknown audience fixture action.');
}
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
