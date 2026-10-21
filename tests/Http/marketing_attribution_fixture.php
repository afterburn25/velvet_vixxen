<?php
declare(strict_types=1);

// Private CLI-only fixture and independent observer. Never packaged or served.
$root = realpath((string) ($argv[1] ?? '')); $action = (string) ($argv[2] ?? 'read');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/') || !is_file($root . '/.vvx-test-runtime')
    || !is_file($root . '/storage/temp/editorial-access-ownership.json')) throw new RuntimeException('An owned disposable attribution runtime is required.');
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$saved = require $root . '/config/app.php'; $mode = $ownership['mode'] ?? '';
if (($ownership['runtime'] ?? '') !== $root || !in_array($mode, ['file','mysql'], true)
    || ($saved['storage_mode'] ?? '') !== $mode || ($saved['base_url'] ?? '') !== ($ownership['base'] ?? '')
    || parse_url((string) $saved['base_url'], PHP_URL_HOST) !== '127.0.0.1' || parse_url((string) $saved['base_url'], PHP_URL_SCHEME) !== 'http'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== ($ownership['smtp_port'] ?? null) || !is_file($root . '/storage/install.lock')) {
    throw new RuntimeException('The exact owned localhost attribution configuration changed.');
}
$pdo = null;
if ($mode === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php'; [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || ($ownership['database'] ?? '') !== $db['name'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['user'] ?? '') !== $db['user'] || (int) ($saved['db']['port'] ?? 0) !== $db['port']) throw new RuntimeException('Only the exclusively owned disposable database is authorized.');
}
$marker = $root . '/storage/temp/marketing-attribution-fixture.json';
if ($action === 'seed') {
    if (is_file($marker)) throw new RuntimeException('Attribution fixture already seeded.');
    define('ROOT_PATH', $root); require $root . '/includes/version.php'; require $root . '/includes/autoload.php';
    require $root . '/app/Support/functions.php'; require $root . '/app/Core/MarketingStore.php';
    date_default_timezone_set((string) ($saved['timezone'] ?? 'UTC'));
    $store = new App\Core\Store(new App\Core\Config($root . '/config/app.php')); $marketing = new Velvet\Core\MarketingStore($root);
    $referral = $marketing->saveReferralDefinition(['code'=>'launch','label'=>'Synthetic launch referral','enabled'=>true], static function (): void {});
    $subscriber = $marketing->subscribe('attribution-newsletter@example.test', 'Synthetic newsletter', 'attribution-fixture', true); $marketing->confirm($subscriber['token']);
    $campaign = $marketing->upsert('campaigns', ['subject'=>'Synthetic attribution campaign','body'=>'Visit the shop.','status'=>'draft']);
    $marketing->queueCampaign($campaign['id']); $queue = $marketing->all()['campaign_queue']; $delivery = null;
    foreach ($queue as $row) if ($row['campaign_id'] === $campaign['id'] && $row['contact_id'] === $subscriber['contact']['id']) $delivery = $row;
    if ($delivery === null) throw new RuntimeException('The genuine saved delivery is missing.');
    $url = $marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], '/shop/');
    $encodedUrl = $marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], 'https://example.test/a%20b?label=two%20words');
    $couponCampaign = $marketing->upsert('campaigns', ['subject'=>'Synthetic coupon campaign','body'=>'Local coupon fixture.','status'=>'draft']);
    $plan = $store->saveMembershipPlan(['name'=>'Attribution fixture membership','key'=>'attribution-fixture-plan','price'=>20,'currency'=>'USD','isActive'=>true]);
    $store->saveCoupon(['code'=>'ATTRIBUTION10','discountType'=>'percent','value'=>10,'isActive'=>true]);
    $store->savePaymentProvider(['key'=>'sandbox','enabled'=>true,'testMode'=>true,'supportsRecurring'=>true]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true);
    file_put_contents($root . '/uploads/attribution-fixture.png', $png);
    file_put_contents($root . '/storage/private/attribution-fixture.png', $png);
    $publicImage = $store->addMedia(['title'=>'Attribution fixture public image','type'=>'image','file'=>'uploads/attribution-fixture.png','mime'=>'image/png','width'=>1,'height'=>1,'private'=>false]);
    $privateImage = $store->addMedia(['title'=>'Attribution fixture private image','type'=>'image','file'=>'private:attribution-fixture.png','mime'=>'image/png','width'=>1,'height'=>1,'private'=>true]);
    $owned = ['runtime'=>$root,'mode'=>$mode,'referral_id'=>$referral['id'],'campaign_id'=>$campaign['id'],'coupon_campaign_id'=>$couponCampaign['id'],
        'tracked_url'=>$url,'encoded_tracked_url'=>$encodedUrl,'delivery_id'=>$delivery['id'],'plan_id'=>(string)$plan['id'],'public_image_id'=>(string)$publicImage['id'],'private_image_id'=>(string)$privateImage['id']];
    file_put_contents($marker, json_encode($owned, JSON_THROW_ON_ERROR)); chmod($marker,0600);
    echo json_encode($owned, JSON_THROW_ON_ERROR); exit;
}
if (!in_array($action, ['read','defer-purchase-projection'], true)) throw new RuntimeException('Unsupported attribution fixture operation.');
if (!is_file($marker)) throw new RuntimeException('Only a seeded owned attribution runtime can be observed.');
$owned = json_decode((string) file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
if (($owned['runtime'] ?? '') !== $root || ($owned['mode'] ?? '') !== $mode) throw new RuntimeException('Attribution marker mismatch.');
$result = ['mode'=>$mode,'marketing'=>[],'members'=>[],'orders'=>[],'protected'=>[], 'mysql_version'=>$pdo?->getAttribute(PDO::ATTR_SERVER_VERSION)];
foreach (['config/app.php','storage/install.lock','uploads/editorial-existing.bin','storage/private/editorial-existing.bin','storage/backups/editorial-existing.txt'] as $relative) {
    $result['protected'][$relative] = is_file($root . '/' . $relative) ? hash_file('sha256', $root . '/' . $relative) : null;
}
if ($pdo !== null) {
    $state = $pdo->query('SELECT header_json,layout_json,snapshot_sha256 FROM marketing_storage_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($state)) throw new RuntimeException('HTTP MySQL acceptance requires explicitly activated SQL marketing.');
    $header = json_decode($state['header_json'], true, 512, JSON_THROW_ON_ERROR); $collections = [];
    foreach (['suppressions','posts','banners','landing_pages','links','referral_codes','contacts','segments','campaigns','campaign_queue','events','consents'] as $key) $collections[$key] = [];
    foreach ($pdo->query('SELECT collection,entry_key_json,payload_json FROM marketing_storage_records ORDER BY collection,position')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $collections[$row['collection']][json_decode($row['entry_key_json'],true,512,JSON_THROW_ON_ERROR)] = json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
    }
    foreach (json_decode($state['layout_json'],true,512,JSON_THROW_ON_ERROR) as $key) $result['marketing'][$key] = array_key_exists($key,$collections) ? $collections[$key] : $header[$key];
    if (!hash_equals($state['snapshot_sha256'], hash('sha256',json_encode($result['marketing'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)))) throw new RuntimeException('Independent SQL marketing checksum mismatch.');
    $query = $pdo->prepare('SELECT id,email,status,preferences_json FROM members WHERE email=?'); $query->execute(['attribution-member@example.test']);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $member) $result['members'][] = ['id'=>(string)$member['id'],'email'=>$member['email'],'status'=>$member['status'],'preferences'=>json_decode($member['preferences_json'],true,512,JSON_THROW_ON_ERROR)];
    $query = $pdo->prepare('SELECT o.* FROM orders o JOIN members m ON m.id=o.member_id WHERE m.email=?'); $query->execute(['attribution-member@example.test']);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $order) $result['orders'][] = ['id'=>(string)$order['id'],'status'=>$order['status'],'grandTotal'=>(float)$order['grand_total'],'metadata'=>json_decode($order['metadata_json'],true,512,JSON_THROW_ON_ERROR)];
    $result['outbox_count'] = (int)$pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    $result['protected']['retained-marketing-file'] = hash_file('sha256',$root . '/storage/private/marketing/data.json');
} else {
    $result['marketing'] = json_decode((string)file_get_contents($root . '/storage/private/marketing/data.json'),true,512,JSON_THROW_ON_ERROR);
    $data = json_decode((string)file_get_contents($root . '/storage/data.json'),true,512,JSON_THROW_ON_ERROR); $ids = [];
    foreach ($data['members'] ?? [] as $member) if (($member['email'] ?? '') === 'attribution-member@example.test') {
        $result['members'][] = array_intersect_key($member,array_flip(['id','email','status','preferences'])); $ids[] = (string)$member['id'];
    }
    foreach ($data['orders'] ?? [] as $order) if (in_array((string)($order['memberId'] ?? ''),$ids,true)) $result['orders'][] = array_intersect_key($order,array_flip(['id','status','grandTotal','metadata']));
    $result['outbox_count'] = count($data['mailOutbox'] ?? []);
}
if ($action === 'defer-purchase-projection') {
    // Remove only this reserved order's rebuildable projection to model the
    // business-commit/reporting-failure boundary. Never edit its real source.
    if (count($result['members']) !== 1 || count($result['orders']) !== 1
        || $result['orders'][0]['status'] !== 'paid' || (float) $result['orders'][0]['grandTotal'] !== 18.0) throw new RuntimeException('The reserved paid source must exist before a projection can be deferred.');
    $order = $result['orders'][0]; $key = hash('sha256', 'purchase:' . $order['id']); $eventKey = hash('sha256', $key);
    define('ROOT_PATH', $root); require $root . '/includes/version.php'; require $root . '/includes/autoload.php';
    require $root . '/app/Support/functions.php'; require $root . '/app/Core/MarketingStore.php';
    (new Velvet\Core\MarketingStore($root))->update(static function (array &$data) use ($key, $eventKey, $order): void {
        $origin = $data['attribution_v1']['conversions'][$key] ?? null;
        if (!is_array($origin) || $origin['type'] !== 'purchase' || $origin['source_id'] !== $order['id']
            || $origin['attribution'] !== $order['metadata']['attribution']) throw new RuntimeException('Only the exact reserved conversion may be deferred.');
        $found = [];
        foreach ($data['events'] as $index => $event) if (($event['measurement_version'] ?? null) === 1 && ($event['source_key'] ?? null) === $eventKey) $found[$index] = $event;
        if (count($found) !== 1) throw new RuntimeException('The reserved purchase measurement is missing or duplicated.');
        $event = reset($found); $rollupKey = Velvet\Core\MarketingAnalytics::rollupKey($event);
        $row = Velvet\Core\MarketingAnalytics::rollup($data['analytics_v1']['rollups'][$rollupKey] ?? null);
        if ($row['count'] < 1 || $row['amount_minor'] < $event['amount_minor']) throw new RuntimeException('The reserved purchase total cannot be safely deferred.');
        $row['count']--; $row['amount_minor'] -= $event['amount_minor'];
        if ($row['count'] === 0) unset($data['analytics_v1']['rollups'][$rollupKey]); else $data['analytics_v1']['rollups'][$rollupKey] = $row;
        foreach (array_keys($found) as $index) unset($data['events'][$index]);
        $data['events'] = array_values($data['events']); unset($data['attribution_v1']['conversions'][$key]);
        $data['analytics_v1']['reconcile_cursor'] = ['kind'=>'order','after'=>''];
    });
    echo json_encode(['deferred'=>true], JSON_THROW_ON_ERROR); exit;
}
echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
