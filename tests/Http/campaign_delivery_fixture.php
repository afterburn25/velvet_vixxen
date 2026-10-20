<?php
declare(strict_types=1);

$root = realpath((string) ($argv[1] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
    || !is_file($root . '/.vvx-test-runtime')) throw new RuntimeException('A marked disposable temporary runtime is required.');
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) throw new RuntimeException('Fixture parameters must be an object.');
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
require $root . '/includes/marketing.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$marketing = velvet_marketing_store();
$result = [];
if ($action === 'contact') {
    $email = (string) ($input['email'] ?? 'campaign@example.test');
    if (!str_ends_with($email, '@example.test')) throw new RuntimeException('Only reserved fixture addresses are allowed.');
    $result = $marketing->subscribe($email, 'Disposable Campaign Contact', 'campaign fixture', true);
    if (!empty($input['confirmed'])) $marketing->confirm($result['token']);
} elseif ($action === 'campaign') {
    $result = $marketing->upsert('campaigns', ['subject' => (string) ($input['subject'] ?? 'Disposable campaign'),
        'body' => (string) ($input['body'] ?? 'Disposable campaign message.'), 'preheader' => 'Fixture preheader',
        'scheduled_at' => (string) ($input['scheduled_at'] ?? ''), 'status' => 'draft']);
    if ($input['queue'] ?? true) $result['queued_count'] = $marketing->queueCampaign($result['id']);
} elseif ($action === 'configure') {
    $config = app_config(); $values = $config->all();
    $mode = (string) ($input['mode'] ?? 'disabled');
    if (!in_array(trim(strtolower($mode)), ['smtp', 'disabled'], true)) throw new RuntimeException('Only disabled mail or local capture SMTP is allowed.');
    $port = (int) ($input['port'] ?? 0);
    if (trim(strtolower($mode)) === 'smtp' && ($port < 1024 || $port > 65535)) throw new RuntimeException('An unprivileged local capture port is required.');
    $values['mail'] = ['mode' => $mode, 'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none',
        'username' => '', 'password' => '', 'from_email' => 'owner@example.test', 'from_name' => 'Campaign fixture'];
    if (isset($input['base_url'])) {
        $base = (string) $input['base_url'];
        if (parse_url($base, PHP_URL_HOST) !== '127.0.0.1' || parse_url($base, PHP_URL_SCHEME) !== 'http') throw new RuntimeException('A localhost fixture URL is required.');
        $values['base_url'] = $base;
    }
    $config->write($values);
} elseif ($action === 'cooldown') {
    $marketing->update(static function (array &$data) use ($input): void {
        foreach ($data['campaign_queue'] as &$row) if ($row['campaign_id'] === $input['campaign_id']) $row['next_attempt_at'] = gmdate('c', time() - 120);
    });
} elseif ($action === 'stage') {
    if (!in_array($input['status'] ?? '', ['claimed', 'sending'], true)) throw new RuntimeException('Unsupported crash fixture state.');
    $marketing->update(static function (array &$data) use ($input): void {
        foreach ($data['campaign_queue'] as &$row) if ($row['campaign_id'] === $input['campaign_id']) {
            $row['status'] = $input['status']; $row['claim_token'] = bin2hex(random_bytes(24)); $row['attempts'] = 1;
        }
    });
} elseif ($action === 'duplicate') {
    $marketing->update(static function (array &$data) use ($input): void {
        foreach ($data['campaign_queue'] as $row) if ($row['campaign_id'] === $input['campaign_id']) {
            $row['id'] = 'delivery_fixture_' . bin2hex(random_bytes(8)); $data['campaign_queue'][] = $row; break;
        }
    });
} elseif ($action === 'suppress') {
    $marketing->update(static function (array &$data) use ($input): void {
        $data['suppressions'][] = ['email' => (string) $input['email'], 'reason' => 'Disposable suppression fixture'];
    });
} elseif ($action === 'revoke') {
    $marketing->update(static function (array &$data) use ($input): void {
        $data['consents'][] = ['email' => (string) $input['email'], 'type' => 'newsletter', 'granted' => false];
    });
} elseif ($action === 'unsubscribe') {
    $result = ['ok' => $marketing->unsubscribe((string) $input['token'])];
} elseif ($action === 'settings') {
    $marketing->setSettings($input);
} elseif ($action === 'edit_post') {
    $result = $marketing->upsert('posts', ['id' => 'concurrent-fixture-post', 'title' => 'Concurrent content was retained', 'status' => 'draft']);
} elseif ($action === 'homepage_signup') {
    $site = (array) app_store()->setting('site', []);
    $site['newsletter_enabled'] = !empty($input['enabled']);
    app_store()->setSetting('site', $site);
} elseif ($action === 'owner_status') {
    $path = $root . '/storage/data.json';
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $data['admins']['demo-owner']['is_active'] = !empty($input['active']) ? 1 : 0;
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
} else throw new RuntimeException('Unknown campaign fixture action.');
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
