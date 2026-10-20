<?php
declare(strict_types=1);

// Private CLI setup for a marked disposable package; never a public endpoint.
$root = realpath((string) ($argv[1] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
    || !is_file($root . '/.vvx-test-runtime') || ($argv[2] ?? '') !== 'initialize') {
    throw new RuntimeException('A marked disposable publication runtime is required.');
}
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
require $root . '/includes/marketing.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$config = app_config()->all();
$state = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
if (($config['storage_mode'] ?? '') !== 'file' || ($config['mail']['mode'] ?? '') !== 'disabled'
    || parse_url((string) ($config['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1'
    || ($state['admins']['upload-fixture-owner']['email'] ?? '') !== 'owner@example.test') {
    throw new RuntimeException('The known localhost owner fixture with disabled mail is required.');
}
$marketing = velvet_marketing_store();
$existing = $marketing->all();
if ($existing['posts'] !== [] || $existing['banners'] !== [] || $existing['landing_pages'] !== [] || $existing['contacts'] !== []) {
    throw new RuntimeException('Refusing to reseed an existing publication fixture.');
}
$config['timezone'] = 'America/New_York';
app_config()->write($config);
date_default_timezone_set('America/New_York');
$post = $marketing->upsert('posts', ['id' => 'legacy-window-post', 'title' => 'Invalid legacy post', 'slug' => 'legacy-invalid-post',
    'body' => 'Legacy post content stays editable.', 'category' => 'Update', 'status' => 'published', 'publish_at' => 'invalid-legacy-date']);
$banner = $marketing->upsert('banners', ['id' => 'legacy-window-banner', 'text' => 'Invalid legacy banner', 'url' => '/membership/',
    'enabled' => true, 'starts_at' => 'invalid-legacy-start', 'ends_at' => '']);
$page = $marketing->upsert('landing_pages', ['id' => 'legacy-window-page', 'title' => 'Invalid legacy landing page', 'slug' => 'legacy-invalid-page',
    'body' => 'Legacy landing page content stays editable.', 'enabled' => true, 'starts_at' => '', 'ends_at' => 'invalid-legacy-end']);
$contact = $marketing->subscribe('window-fixture@example.test', 'Publication fixture', 'publication-test', true);
if (!$marketing->confirm($contact['token'])) throw new RuntimeException('Fixture confirmation failed.');
$segment = $marketing->saveSegment(['name' => 'Publication fixture audience', 'enabled' => true, 'rules' => ['sources' => ['publication-test']]]);
$campaign = $marketing->upsert('campaigns', ['subject' => 'Frozen publication fixture', 'body' => 'No mail is sent.',
    'audience' => ['mode' => 'segment', 'segment_id' => $segment['id'], 'segment_revision' => $segment['revision']]]);
$marketing->queueCampaign($campaign['id']);
$marketing->update(static function (array &$data): void { $data['owner_fixture_unrelated'] = ['keep' => ['nested' => true]]; });
echo json_encode(['post' => $post['id'], 'banner' => $banner['id'], 'page' => $page['id'], 'campaign' => $campaign['id']], JSON_THROW_ON_ERROR) . PHP_EOL;
