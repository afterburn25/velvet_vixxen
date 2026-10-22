<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use Velvet\Core\CampaignMessage;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-campaign-messages-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
mkdir($root . '/storage', 0700, true);
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require $source . '/app/Support/functions.php';
require $source . '/app/Core/MarketingStore.php';
$config = new Config($root . '/config/app.php');
$config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'base_url' => 'http://127.0.0.1/nested/site',
    'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32))]]);
$store = new Store($config);
$owner = ['id' => 'owner-fixture', 'email' => 'owner@example.test'];
$store->mutateFileStore(static function (array &$data) use ($owner): void {
    $data['admins'][$owner['id']] = $owner + ['role' => 'owner', 'is_active' => 1];
    $data['retained_campaign_sentinel'] = ['private' => true, 'untouched' => 52];
});
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $operation, string $message, ?int $code = null) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException|RuntimeException $error) { $assert($code === null || $error->getCode() === $code, $message . ' (error code)'); return; }
    $assert(false, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    rmdir($path);
};
try {
    $marketing = new MarketingStore($root);
    $memberBytes = file_get_contents($root . '/storage/data.json');
    $path = $root . '/storage/private/marketing/data.json';
    $brand = $marketing->emailBrand();
    $assert(strlen($brand['revision']) === 64 && $brand['template']['accent'] === '#f52d91', 'Default email brand is incomplete.');
    foreach ([['brand_name' => ''], ['brand_name' => []], ['tagline' => "Unsafe\nHeader"], ['accent' => 'red'],
        ['accent' => '#123456;display:none'], ['schema' => 2], ['html' => '<script>bad</script>'], ['footer' => str_repeat('x', 1001)]] as $invalid) {
        $before = file_get_contents($path);
        $reject(fn() => $marketing->saveEmailBrand($invalid, $brand['revision'], $owner), 'An invalid brand was saved.');
        $assert(file_get_contents($path) === $before, 'Invalid brand changed durable records.');
    }
    $brand = $marketing->saveEmailBrand(['brand_name' => 'Owner <brand>', 'tagline' => 'A private test', 'accent' => '#112233', 'footer' => 'Saved footer'], $brand['revision'], $owner);
    $draftInput = ['subject' => 'Saved <message>', 'preheader' => 'A preview & text', 'body' => "Hello <script>alert(1)</script>\nVisit https://example.test/offer?a=1&b=2.",
        'format' => 'branded', 'cta_label' => 'Open membership', 'cta_url' => '/membership/', 'audience' => ['mode' => 'all']];
    $draft = $marketing->saveCampaignMessage($draftInput, $owner);
    $view = $marketing->campaignMessage($draft['id']);
    $assert($view !== null && !$view['queued'] && $view['snapshot']['template'] === $brand['template'], 'Saved message did not use the actual brand.');
    $unsubscribe = App\Core\CampaignTestService::exampleUnsubscribe($config->get('base_url'));
    $rendered = CampaignMessage::render($view['snapshot'], $unsubscribe, $config->get('base_url'));
    $assert(str_contains($rendered['html'], 'Owner &lt;brand&gt;') && !str_contains($rendered['html'], '<script>'), 'Owner text became executable email HTML.');
    $assert(str_contains($rendered['text'], '<script>alert(1)</script>') && str_contains($rendered['html'], 'Saved &lt;message&gt;'), 'Text or subject was silently rewritten.');
    $assert(str_contains($rendered['html'], 'color:#ffffff') && str_contains($rendered['text'], 'Saved footer'), 'Brand style/footer was lost.');
    $assert(str_contains($rendered['text'], 'http://127.0.0.1/nested/site/route.php?vvx_route=membership'), 'Nested CTA ignored the actual dispatcher.');
    $mapped = [];
    $tracked = CampaignMessage::render($view['snapshot'], $unsubscribe, $config->get('base_url'), static function (string $url) use (&$mapped): string {
        $mapped[] = $url; return 'https://example.test/click/' . hash('sha256', $url);
    });
    $assert(count($mapped) === 2 && !in_array($unsubscribe, $mapped, true), 'Renderer mapped an unsubscribe or duplicated content links.');
    $assert(substr_count($tracked['text'], 'https://example.test/click/') === 2 && substr_count($tracked['html'], 'https://example.test/click/') === 2, 'Text and HTML tracked links diverged.');
    $assert(str_contains($tracked['text'], $unsubscribe) && str_contains($tracked['html'], htmlspecialchars($unsubscribe, ENT_QUOTES)), 'Tracking altered the unsubscribe destination.');
    foreach (['javascript:alert(1)', '//example.test/a', 'https://user:secret@example.test', "https://example.test/\r\nX:yes", '/%2fevil.test', '/a\\b', '/a%0ab'] as $badUrl) {
        $reject(fn() => CampaignMessage::fields(array_replace($draftInput, ['cta_url' => $badUrl])), 'Unsafe CTA accepted.');
    }
    foreach ([['subject' => "bad\nSubject"], ['body' => ''], ['body' => "bad\0body"], ['body' => str_repeat('x', 100001)],
        ['format' => 'html'], ['cta_label' => ''], ['cta_url' => ''], ['preheader' => []]] as $invalid) {
        $reject(fn() => CampaignMessage::fields(array_replace($draftInput, $invalid)), 'Invalid campaign fields accepted.');
    }
    $before = file_get_contents($path);
    $assert(MarketingStore::previewCampaignMessage($draft['id'], $root) === $view && file_get_contents($path) === $before, 'Preview changed committed data.');
    $changed = $marketing->saveCampaignMessage(array_replace($draftInput, ['id' => $draft['id'], 'expected_revision' => $view['revision'], 'body' => 'Changed saved body']), $owner);
    $before = file_get_contents($path);
    $reject(fn() => $marketing->saveCampaignMessage(array_replace($draftInput, ['id' => $draft['id'], 'expected_revision' => $view['revision']]), $owner), 'Stale text overwrote current campaign.', 409);
    $assert(file_get_contents($path) === $before, 'Stale message rejection changed records.');
    $view = $marketing->campaignMessage($draft['id']);
    $before = file_get_contents($path);
    $assert(!$marketing->authorizeCampaignTest(str_repeat('a', 64), 'unavailable', $view['revision'], $view['template_revision'], $owner), 'Missing test claim was authorized.');
    $reject(fn() => $marketing->finishCampaignTest(str_repeat('a', 64), 'unavailable', ['status' => 'sent']), 'Missing test claim was completed.');
    $assert(file_get_contents($path) === $before, 'Missing-claim checks created journal entries.');
    $request = bin2hex(random_bytes(32));
    $claim = $marketing->claimCampaignTest($draft['id'], $view['revision'], $view['template_revision'], $request, $owner);
    $assert($claim['recipient'] === 'owner@example.test' && $claim['status'] === 'claimed', 'Test recipient or claim was not fixed to current owner.');
    $reject(fn() => $marketing->claimCampaignTest($draft['id'], $view['revision'], $view['template_revision'], $request, $owner), 'Replayed test was accepted.', 409);
    $reject(fn() => $marketing->claimCampaignTest($draft['id'], $view['revision'], $view['template_revision'], bin2hex(random_bytes(32)), $owner), 'Test cooldown was bypassed.', 429);
    $assert($marketing->authorizeCampaignTest($request, $claim['token'], $view['revision'], $view['template_revision'], $owner), 'Current test could not authorize transmission.');
    $finish = $marketing->finishCampaignTest($request, $claim['token'], ['status' => 'unknown', 'lastError' => 'Fixture acknowledgement lost']);
    $assert($finish['status'] === 'unknown' && !isset($finish['token']), 'Unknown test outcome became retryable or retained its claim.');
    $assert($marketing->finishCampaignTest($request, $claim['token'], ['status' => 'sent'])['status'] === 'unknown', 'Repeated completion rewrote an unknown result.');
    $assert($marketing->all()['campaign_queue'] === [], 'Owner test queued subscriber deliveries.');
    // Test-only age adjustment exercises durable cooldown/lease handling without a runtime clock override.
    $marketing->update(static function (array &$data) use ($request): void { $data['settings']['campaign_test_journal'][$request]['created_epoch'] -= 61; });
    $secondRequest = bin2hex(random_bytes(32));
    $secondClaim = $marketing->claimCampaignTest($draft['id'], $view['revision'], $view['template_revision'], $secondRequest, $owner);
    $marketing->saveCampaignMessage(array_replace($draftInput, ['id' => $draft['id'], 'expected_revision' => $view['revision'], 'body' => 'Fresh edit during handshake']), $owner);
    $assert(!$marketing->authorizeCampaignTest($secondRequest, $secondClaim['token'], $view['revision'], $view['template_revision'], $owner), 'Stale test transmitted after a saved edit.');
    $assert($marketing->finishCampaignTest($secondRequest, $secondClaim['token'], ['status' => 'skipped'])['status'] === 'skipped', 'Canceled test outcome was lost.');
    $contact = $marketing->subscribe('subscriber@example.test', 'Fixture', 'test', true);
    $marketing->confirm($contact['token']);
    $assert($marketing->queueCampaign($draft['id']) === 1, 'Actual campaign queue failed.');
    $queued = $marketing->campaignMessage($draft['id']);
    $frozen = $queued['snapshot'];
    $assert($queued['queued'] && $frozen['template'] === $brand['template'], 'Queue did not freeze the selected template.');
    $marketing->saveEmailBrand(['brand_name' => 'Later brand'], $brand['revision'], $owner);
    $assert($marketing->campaignMessage($draft['id'])['snapshot'] === $frozen, 'Later branding changed queued content.');
    $reject(fn() => $marketing->saveCampaignMessage(array_replace($draftInput, ['id' => $draft['id'], 'expected_revision' => $queued['revision']]), $owner), 'Queued message was edited.', 409);
    $reject(fn() => $marketing->upsert('campaigns', ['id' => $draft['id'], 'cta_url' => '/different/']), 'Legacy upsert changed frozen CTA.');
    $delivery = $marketing->all()['campaign_queue'][0];
    $assert($delivery['message_id'] === CampaignMessage::messageId('campaign', $delivery['id']) && $marketing->queueCampaign($draft['id']) === 0, 'Stable outbound identity or duplicate queue protection failed.');
    $legacy = $marketing->upsert('campaigns', ['subject' => 'Legacy', 'preheader' => 'Legacy preheader', 'body' => 'Exact legacy message']);
    $marketing->queueCampaign($legacy['id']);
    $legacyRendered = CampaignMessage::render($marketing->campaignMessage($legacy['id'])['snapshot'], $unsubscribe, $config->get('base_url'));
    $assert($legacyRendered['html'] === null && $legacyRendered['text'] === "Legacy preheader\n\nExact legacy message\n\nUnsubscribe from campaign emails: " . $unsubscribe, 'Legacy queued plain text changed.');
    // A definite preparation failure happens before MailService::deliver. Its
    // durable claim is safely failed; only an authorized transmission is unknown.
    $marketing->update(static function (array &$data) use ($draft): void {
        foreach ($data['campaigns'] as &$campaign) if ($campaign['id'] === $draft['id']) $campaign['delivery_snapshot']['template']['accent'] = 'invalid-color';
    });
    $config->write(array_replace($config->all(), ['mail' => ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => 1,
        'encryption' => 'none', 'from_email' => 'owner@example.test']]));
    $preparation = (new App\Core\CampaignDeliveryService($config, $marketing, new App\Core\MailService($config, $store)))->run(1, $draft['id']);
    $failedPreparation = $marketing->all()['campaign_queue'][0];
    $assert($preparation['failed'] === 1 && $preparation['unknown'] === 0 && $failedPreparation['status'] === 'failed'
        && $failedPreparation['last_error'] === 'Delivery failed before message transmission.' && !isset($failedPreparation['transmission_started_at']),
        'A renderer preparation failure was treated as an unknown transmission or reached the transport.');
    $marketing->update(static function (array &$data) use ($draft, $frozen): void {
        foreach ($data['campaigns'] as &$campaign) if ($campaign['id'] === $draft['id']) $campaign['delivery_snapshot'] = $frozen;
    });
    $assert($marketing->retryCampaign($draft['id']) === 1 && $marketing->all()['campaign_queue'][0]['message_id'] === $delivery['message_id'],
        'Corrected preparation failure could not be explicitly retried with its stable identity.');
    $config->write(array_replace($config->all(), ['mail' => ['mode' => 'disabled']]));
    $assert(file_get_contents($root . '/storage/data.json') === $memberBytes, 'Campaigns modified members, owner records or unrelated state.');
    $store->mutateFileStore(static function (array &$data) use ($owner): void { $data['admins'][$owner['id']]['role'] = 'editor'; });
    $before = file_get_contents($path);
    $reject(fn() => $marketing->saveCampaignMessage($draftInput, $owner), 'Demoted owner saved a campaign.', 403);
    $reject(fn() => $marketing->saveEmailBrand([], $marketing->emailBrand()['revision'], $owner), 'Demoted owner changed branding.', 403);
    $assert(file_get_contents($path) === $before, 'Owner refusal changed durable marketing state.');
    $mail = new App\Core\MailService($config, $store);
    foreach ([['message_id' => "<bad>\r\nBcc: other@example.test"], ['html' => []], ['headers' => ['Bcc' => 'other@example.test']]] as $invalidEnvelope) {
        $assert($mail->deliver('owner@example.test', 'Fixture', 'Body', null, $invalidEnvelope)['status'] === 'failed', 'Unsafe MIME envelope accepted.');
    }
    echo 'Campaign message source acceptance passed (' . $checks . " checks; no mail sent).\n";
} finally { $remove($root); }
