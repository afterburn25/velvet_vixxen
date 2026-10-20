<?php
declare(strict_types=1);

// CLI-only setup for marked disposable runtimes; never a served fixture route.
$root = realpath((string) ($argv[1] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
    || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable temporary runtime is required.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_int($input['port'] ?? null) || $input['port'] < 1 || $input['port'] > 65535
    || !is_string($input['nonce'] ?? null) || !preg_match('/\A[a-f0-9]{16}\z/D', $input['nonce'])) {
    throw new RuntimeException('A local capture port and synthetic fixture identifier are required.');
}
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$config = app_config();
$values = $config->all();
$store = app_store();
$data = $store->rawFileData();
$ownerId = isset($data['admins']['upload-fixture-owner']) ? 'upload-fixture-owner' : 'demo-owner';
$memberId = isset($data['members']['upload-fixture-member']) ? 'upload-fixture-member' : 'demo-member';
if (($values['storage_mode'] ?? '') !== 'file'
    || parse_url((string) ($values['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1'
    || !in_array($data['admins'][$ownerId]['email'] ?? '', ['admin@example.com', 'owner@example.test'], true)
    || !in_array($data['members'][$memberId]['email'] ?? '', ['member@example.com', 'member@example.test'], true)) {
    throw new RuntimeException('Only the known local acceptance fixture is supported.');
}
$data['admins'][$ownerId]['email'] = 'owner@example.test';
$data['members'][$memberId]['email'] = 'member@example.test';
$store->replaceFileData($data);
$values['mail'] = ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => $input['port'], 'encryption' => 'none',
    'username' => '', 'password' => '', 'from_email' => 'notices@example.test', 'from_name' => 'Fixture notices'];
$config->write($values);
$member = $store->createCommunicationThread(['memberId' => $memberId, 'subject' => 'MEMBER-PRIVATE-SUBJECT-' . $input['nonce']]);
$booking = $store->createBookingRequest(['contactName' => 'Synthetic booking guest', 'contactEmail' => 'guest@example.test',
    'details' => 'Synthetic private booking fixture.']);
$data = $store->rawFileData();
$data['messageThreads'][(string) $booking['threadId']]['subject'] = 'GUEST-PRIVATE-SUBJECT-' . $input['nonce'];
$store->replaceFileData($data);
echo json_encode(['member_id' => $memberId, 'member_thread' => $member['id'], 'guest_thread' => $booking['threadId']], JSON_THROW_ON_ERROR) . PHP_EOL;
