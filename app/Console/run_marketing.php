<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/includes/marketing.php';
$store = velvet_marketing_store();
$data = $store->all();
$now = time();
$processed = 0;
$outDir = ROOT_PATH . '/storage/private/marketing/outbox';
if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
foreach ($data['campaign_queue'] as &$delivery) {
    if (($delivery['status'] ?? '') !== 'queued') continue;
    $eligible = false;
    foreach ($data['contacts'] as $contact) {
        if (($contact['email'] ?? '') === ($delivery['email'] ?? '') && ($contact['status'] ?? '') === 'active') { $eligible = true; break; }
    }
    if (!$eligible) { $delivery['status'] = 'suppressed'; continue; }
    $campaign = null;
    foreach ($data['campaigns'] as $candidate) {
        if (($candidate['id'] ?? '') === ($delivery['campaign_id'] ?? '')) { $campaign = $candidate; break; }
    }
    if (!$campaign) { $delivery['status'] = 'failed'; $delivery['error'] = 'Campaign not found'; continue; }
    $scheduled = strtotime((string) ($campaign['scheduled_at'] ?? '')) ?: 0;
    if ($scheduled > $now) continue;
    $payload = ['to'=>$delivery['email'],'subject'=>$campaign['subject'],'preheader'=>$campaign['preheader']??'','body'=>$campaign['body']??'','created_at'=>gmdate('c')];
    $file = $outDir . '/' . $delivery['id'] . '.json';
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    @chmod($file, 0640);
    $delivery['status'] = 'prepared'; $delivery['prepared_at'] = gmdate('c'); $processed++;
}
unset($delivery);
$store->save($data);
fwrite(STDOUT, "Prepared {$processed} marketing message(s).\n");
