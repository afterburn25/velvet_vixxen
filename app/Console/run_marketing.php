<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/app/bootstrap.php';
require ROOT_PATH . '/includes/marketing.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }
try {
    $options = getopt('', ['limit:', 'campaign:']);
    $limit = $options['limit'] ?? '10';
    if (!is_string($limit) || !ctype_digit($limit)) throw new InvalidArgumentException('Use --limit=1 through --limit=25.');
    $campaign = $options['campaign'] ?? null;
    if ($campaign !== null && (!is_string($campaign) || trim($campaign) === '')) throw new InvalidArgumentException('A campaign ID is required.');
    // The CLI needs no session mutex while waiting for a mail server.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $service = new \App\Core\CampaignDeliveryService(app_config(), velvet_marketing_store(), new \App\Core\MailService(app_config(), app_store()));
    $stats = $service->run((int) $limit, $campaign);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($stats['unknown'] > 0 || $stats['failed'] > 0 ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Campaign run failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
