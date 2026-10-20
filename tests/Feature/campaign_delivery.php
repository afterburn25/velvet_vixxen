<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
$worker = ($argv[1] ?? '') === '--worker';
$root = $worker ? (string) ($argv[2] ?? '') : sys_get_temp_dir() . '/vvx-campaign-source-' . bin2hex(random_bytes(6));
if ($worker && (!str_starts_with($root, sys_get_temp_dir() . '/vvx-campaign-source-') || !is_file($root . '/.vvx-test-runtime'))) throw new RuntimeException('A marked temporary runtime is required.');
if (!$worker) { mkdir($root, 0755, true); file_put_contents($root . '/.vvx-test-runtime', 'Disposable campaign test.'); }
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingStore.php';
$marketing = new Velvet\Core\MarketingStore($root);
if ($worker) {
    for ($i = 0; $i < 20; $i++) $marketing->recordEvent('page_view', ['path' => '/worker-' . (int) $argv[3] . '/' . $i]);
    $marketing->subscribe('worker-' . (int) $argv[3] . '@example.test', 'Disposable', 'concurrent fixture', true);
    exit;
}
$failures = [];
$assertions = 0;
$assert = static function (bool $okay, string $message) use (&$failures, &$assertions): void { $assertions++; if (!$okay) $failures[] = $message; };
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); $assert(false, $message); } catch (InvalidArgumentException|RuntimeException) { $assert(true, $message); }
};
try {
    $config = new App\Core\Config($root . '/config/app.php');
    $config->write(['base_url' => 'http://127.0.0.1:8765/nested/site', 'storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'mail' => ['mode' => 'disabled']]);
    $mail = new App\Core\MailService($config, new App\Core\Store($config));
    $service = new App\Core\CampaignDeliveryService($config, $marketing, $mail);
    $assert($marketing->settings() === ['newsletter_signup_enabled' => true, 'campaign_delivery_enabled' => true], 'Module defaults changed existing behavior.');
    $first = $marketing->subscribe('first@example.test', 'First', 'fixture', true);
    $pending = $marketing->subscribe('pending@example.test', 'Pending', 'fixture', true);
    $suppressed = $marketing->subscribe('suppressed@example.test', 'Suppressed', 'fixture', true);
    $revoked = $marketing->subscribe('revoked@example.test', 'Revoked', 'fixture', true);
    foreach ([$first, $suppressed, $revoked] as $contact) $marketing->confirm($contact['token']);
    $marketing->update(static function (array &$data): void {
        $data['suppressions'][] = ['email' => 'suppressed@example.test', 'reason' => 'Fixture suppression'];
        $data['consents'][] = ['email' => 'revoked@example.test', 'type' => 'newsletter', 'granted' => false];
    });
    $campaign = $marketing->upsert('campaigns', ['subject' => 'Campaign fixture', 'body' => 'Campaign body', 'status' => 'draft']);
    $assert($marketing->queueCampaign($campaign['id']) === 1, 'Campaign bypassed pending, revoked or suppressed boundaries.');
    $assert($marketing->queueCampaign($campaign['id']) === 0 && count($marketing->all()['campaign_queue']) === 1, 'Repeated queue duplicated an address.');
    $assert($service->run()['held'] !== null && $marketing->all()['campaign_queue'][0]['status'] === 'queued', 'Disabled mail consumed queued work.');
    $assert($marketing->all()['campaign_queue'][0]['attempts'] === 0, 'Disabled mail consumed an attempt.');
    $reject(fn() => $service->run(0), 'Unbounded lower batch size was accepted.');
    $reject(fn() => $service->run(26), 'Unbounded upper batch size was accepted.');
    $reject(fn() => $marketing->upsert('campaigns', ['id' => $campaign['id'], 'body' => 'Changed message']), 'Queued message text was silently replaced.');
    $empty = $marketing->upsert('campaigns', ['subject' => 'Correctable draft', 'body' => '']);
    $reject(fn() => $marketing->queueCampaign($empty['id']), 'An empty campaign was frozen and queued.');
    $marketing->upsert('campaigns', ['id' => $empty['id'], 'body' => 'Corrected draft']);
    $assert($marketing->queueCampaign($empty['id']) === 1, 'An invalid draft could not be corrected before queueing.');
    $badSchedule = $marketing->upsert('campaigns', ['subject' => 'Schedule', 'body' => 'Body', 'scheduled_at' => 'invalid-date']);
    $reject(fn() => $marketing->queueCampaign($badSchedule['id']), 'An invalid schedule could send early.');
    $snapshot = $marketing->all();
    $marketing->unsubscribe($first['contact']['unsubscribe_token']);
    $snapshot['posts'][] = ['id' => 'stale-post'];
    $reject(fn() => $marketing->save($snapshot), 'A stale snapshot overwrote an unsubscribe.');
    $assert($marketing->all()['contacts'][0]['status'] === 'unsubscribed', 'An unsubscribe was lost.');
    $assert(count(array_filter($marketing->all()['campaign_queue'], fn(array $row): bool => $row['status'] === 'suppressed')) === 2, 'Queued deliveries survived unsubscribe.');
    $consents = count($marketing->all()['consents']);
    $assert($marketing->unsubscribe($first['contact']['unsubscribe_token']) && count($marketing->all()['consents']) === $consents, 'Repeated unsubscribe duplicated revocation history.');
    $assert(!$marketing->unsubscribe(''), 'An empty unsubscribe token was accepted.');
    $assert(!$marketing->confirm($first['token']), 'An old confirmation token reactivated an unsubscribed address.');
    $again = $marketing->subscribe('suppressed@example.test', 'Suppressed again', 'fixture', true);
    $assert($again['contact']['status'] === 'suppressed' && !$marketing->confirm($again['token']), 'Signup bypassed independent suppression.');
    $marketing->setSettings(['newsletter_signup_enabled' => false]);
    $reject(fn() => $marketing->subscribe('closed@example.test', 'Closed', 'fixture', true), 'Disabled signup mutated contacts.');
    $assert(!$marketing->confirm($pending['token']), 'Disabled confirmation activated a contact.');
    $assert($marketing->unsubscribe($pending['contact']['unsubscribe_token']), 'Disabling signup also disabled unsubscribe.');
    $assert($marketing->settings()['campaign_delivery_enabled'], 'Disabling signup changed campaign delivery.');
    $marketing->setSettings(['newsletter_signup_enabled' => true, 'campaign_delivery_enabled' => false]);
    $assert($marketing->settings()['newsletter_signup_enabled'] && str_contains((string) $service->holdReason(), 'Marketing settings'), 'Campaign switch was not independent.');
    $config->write(array_replace($config->all(), ['mail' => ['mode' => ' smtp ', 'host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none', 'from_email' => 'owner@example.test']]));
    $assert($mail->configurationProblem() === null, 'Configured SMTP readiness was rejected.');
    $marketing->setSettings(['campaign_delivery_enabled' => true]);
    $assert($service->holdReason() === null, 'Configured campaigns were held.');
    $config->write(array_replace($config->all(), ['base_url' => 'javascript:invalid']));
    $assert($service->holdReason() !== null, 'An unsafe unsubscribe base URL was accepted.');
    $config->write(array_replace($config->all(), ['mail' => ['mode' => 'disabled']]));
    $new = $marketing->subscribe('retry@example.test', 'Retry', 'fixture', true); $marketing->confirm($new['token']);
    $retry = $marketing->upsert('campaigns', ['subject' => 'Retry', 'body' => 'Retry fixture']);
    $marketing->queueCampaign($retry['id']);
    $marketing->update(static function (array &$data) use ($retry): void {
        foreach ($data['campaign_queue'] as &$row) if ($row['campaign_id'] === $retry['id']) { $row['status'] = 'failed'; $row['attempts'] = 1; }
    });
    $assert($marketing->retryCampaign($retry['id']) === 1, 'A definite failure could not be explicitly retried.');
    $marketing->update(static function (array &$data) use ($retry): void {
        foreach ($data['campaign_queue'] as &$row) if ($row['campaign_id'] === $retry['id']) $row['status'] = 'unknown';
    });
    $assert($marketing->retryCampaign($retry['id']) === 0 && $marketing->queueCampaign($retry['id']) === 0, 'Unknown acknowledgement was queued again.');
    $marketing->update(static function (array &$data) use ($retry): void {
        foreach ($data['campaign_queue'] as &$row) if ($row['campaign_id'] === $retry['id']) { $row['status'] = 'failed'; $row['attempts'] = 3; }
    });
    $assert($marketing->retryCampaign($retry['id']) === 0 && $marketing->queueCampaign($retry['id']) === 0, 'Owner retries exceeded three attempts or created a replacement delivery.');
    $marketing->pauseCampaign($retry['id'], true);
    $assert($marketing->all()['campaigns'][array_key_last($marketing->all()['campaigns'])]['delivery_paused'], 'Pause state was not persisted.');
    $marketing->pauseCampaign($retry['id'], false);
    $marketing->upsert('posts', ['id' => 'preserved-post', 'title' => 'Fresh concurrent content']);
    $assert($marketing->all()['posts'][0]['title'] === 'Fresh concurrent content', 'An unrelated content mutation was not retained.');
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root, (string) $i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not launch a concurrent storage fixture.');
        fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Concurrent writer failed: ' . $out . $err);
    }
    $assert(count($marketing->all()['events']) === 80, 'Concurrent event writers lost records.');
    $assert(count(array_filter($marketing->all()['contacts'], fn(array $row): bool => str_starts_with($row['email'], 'worker-'))) === 4, 'Concurrent contact writers lost records.');
    $assert($marketing->all()['posts'][0]['title'] === 'Fresh concurrent content', 'Concurrent events overwrote content.');
    $path = $root . '/storage/private/marketing/data.json';
    $assert((fileperms($path) & 0002) === 0, 'Marketing data became world writable.');
    $raw = file_get_contents($path); file_put_contents($path, '{broken');
    $reject(fn() => $marketing->upsert('posts', ['title' => 'Unsafe reset']), 'Malformed storage was silently reset.');
    $assert(file_get_contents($path) === '{broken', 'Malformed data was overwritten.'); file_put_contents($path, $raw);
} catch (Throwable $error) { $failures[] = $error->getMessage(); }
finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}
if ($failures) { fwrite(STDERR, "Campaign source regressions failed:\n- " . implode("\n- ", $failures) . "\n"); exit(1); }
echo "Campaign source regressions passed ($assertions assertions, concurrent storage writers included).\n";
