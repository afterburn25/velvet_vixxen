<?php
declare(strict_types=1);

// Real business services in a private, exclusively owned disposable installation.
// The SQL wrapper additionally proves an empty localhost MySQL 8 schema first.
use App\Core\Config;
use App\Core\Store;
use Velvet\Core\MarketingAnalytics;
use Velvet\Core\MarketingAttribution;
use Velvet\Core\MarketingAudience;
use Velvet\Core\MarketingPrivacy;
use Velvet\Core\MarketingStore;
use Velvet\Core\MarketingStorage;

if (PHP_SAPI !== 'cli') exit(1);
$source = dirname(__DIR__, 2); $mysql = defined('VVX_ATTRIBUTION_SQL') && VVX_ATTRIBUTION_SQL;
$fixture = sys_get_temp_dir() . '/vvx-attribution-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true); mkdir($fixture . '/storage', 0700, true);
file_put_contents($fixture . '/.vvx-attribution-fixture', 'Exclusively owned synthetic attribution fixture.');
define('ROOT_PATH', $fixture);
require $source . '/includes/version.php'; require $source . '/includes/installation_recovery.php';
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require $source . '/app/Support/functions.php'; require $source . '/app/Core/MarketingStore.php';
require $source . '/tests/Installer/database_fixture.php';
date_default_timezone_set('UTC'); $checks = 0; $ownsDatabase = false; $pdo = null;
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { $assert(true, $message); return; }
    $assert(false, $message);
};
$owner = static function (): void {};
try {
    $values = ['installed' => $mysql, 'installation_id' => 'attribution-fixture', 'storage_mode' => $mysql ? 'mysql' : 'file',
        'file_store_path' => 'storage/data.json', 'base_url' => 'http://127.0.0.1:8877/nested/velvet', 'timezone' => 'UTC',
        'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => str_repeat('a', 64), 'signing_key' => str_repeat('s', 64), 'media_key' => str_repeat('m', 64)]];
    if ($mysql) {
        [$db, $pdo] = vvx_installer_fixture_database();
        $assert(in_array($db['host'], ['127.0.0.1','::1'], true), 'Only loopback MySQL is authorized.');
        vvx_installer_fixture_empty($pdo); $ownsDatabase = true; $values['db'] = $db;
        (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate();
        mkdir($fixture . '/database/migrations', 0700, true);
        copy($source . '/database/migrations/202610200015_marketing_sql_storage.php', $fixture . '/database/migrations/202610200015_marketing_sql_storage.php');
        copy($source . '/database/migrations/202610210016_marketing_measurements.php', $fixture . '/database/migrations/202610210016_marketing_measurements.php');
    }
    file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export($values, true) . ';');
    chmod($fixture . '/config/app.php', 0600);
    file_put_contents($fixture . '/storage/install.lock', json_encode(['installation_id' => 'attribution-fixture'], JSON_THROW_ON_ERROR));
    $config = new Config($fixture . '/config/app.php'); $store = new Store($config);
    if ($mysql) $store->setSetting('installation', ['installation_id' => 'attribution-fixture']);
    $emptyReport = MarketingStore::savedMeasurementReport([], $fixture);
    $assert($emptyReport['total'] === 0 && !is_file($fixture . '/storage/private/marketing/data.json'), 'A saved report initialized marketing records.');
    $marketing = new MarketingStore($fixture);
    if ($mysql) {
        $storage = new MarketingStorage($fixture); $review = $storage->status();
        $assert($review['can_migrate'], 'The verified SQL move is unavailable.');
        $storage->migrate($review['plan'], $owner);
    }
    $assert(MarketingPrivacy::choice($fixture) === null, 'Unknown measurement choice was allowed.');
    $allowCookie = MarketingPrivacy::cookie(true, $fixture);
    $assert(MarketingPrivacy::choice($fixture, $allowCookie) === true, 'Signed allow choice was rejected.');
    $assert(MarketingPrivacy::choice($fixture, MarketingPrivacy::cookie(false, $fixture)) === false, 'Signed deny choice was ignored.');
    $assert(MarketingPrivacy::choice($fixture, str_replace('allow', 'deny', $allowCookie)) === null, 'Forged choice was accepted.');
    $assert(MarketingPrivacy::choice($fixture, $allowCookie, time() + MarketingPrivacy::CHOICE_SECONDS + 1) === null, 'Expired consent was accepted.');
    $assert(MarketingPrivacy::cookiePath($values['base_url']) === '/nested/velvet/', 'Consent cookie escaped the installation path.');
    $before = $marketing->all();
    $assert($marketing->attributionVisitor(null, 'launch', false) === null && $marketing->all() === $before, 'A declined referral created state.');
    foreach (['unknown', '../launch', ' launch ', str_repeat('x', 65)] as $bad) $assert($marketing->attributionVisitor(null, $bad, true) === null, 'Unverified referral was captured.');
    $assert($marketing->all() === $before, 'Invalid referral visits changed saved data.');
    $referral = $marketing->saveReferralDefinition(['code' => 'Launch', 'label' => 'Synthetic launch', 'enabled' => true], $owner);
    $reject(fn() => $marketing->saveReferralDefinition(['code' => 'LAUNCH', 'label' => 'duplicate', 'enabled' => true], $owner), 'Case-folded duplicate referral was accepted.');
    $reject(fn() => $marketing->saveReferralDefinition(['code' => 'x?bad', 'label' => 'bad', 'enabled' => true], $owner), 'Malformed referral was silently repaired.');
    $reject(fn() => $marketing->saveReferralDefinition(['code' => 'revoked', 'enabled' => true], static function (): void { throw new RuntimeException('Owner revoked.', 403); }), 'Revoked owner created a referral.');
    $visit = $marketing->attributionVisitor(null, 'LAUNCH', true);
    $assert($visit !== null && preg_match('/\A[a-f0-9]{64}\z/D', $visit['token']) === 1, 'Verified referral did not issue an opaque visitor token.');
    $_COOKIE[MarketingPrivacy::COOKIE] = $allowCookie; $_COOKIE[MarketingPrivacy::VISITOR_COOKIE] = $visit['token'];
    $snapshot = $marketing->requestAttribution();
    $assert($snapshot['referral_code'] === 'launch' && $snapshot['referral_id'] === $referral['id'], 'Referral snapshot lost its verified definition.');
    $assert($marketing->recordMeasuredEvent('page_view', ['path' => '/nested/velvet/route.php?vvx_route=blog%2Fpost.php&token=never-store-this-token', 'email' => 'never-store-this@example.test']), 'Allowed public page view was not recorded.');
    foreach (['/account/', '/admin/', '/newsletter/confirm.php?token=secret', '/privacy/choices.php', '/api/member-state.php', '/private@example.test', '/blog/secret-person-token'] as $path) $assert(!$marketing->recordMeasuredEvent('page_view', ['path' => $path]), 'Private or arbitrary route entered public measurement.');
    foreach (['purchase','registration','campaign_click'] as $name) $assert(!$marketing->recordMeasuredEvent($name, ['path' => '/']), 'Public API forged a server-only conversion.');
    $assert(!str_contains(json_encode($marketing->all()['events']), 'never-store-this'), 'Measurement retained an unapproved payload or query.');
    $legacyCount = $marketing->measurementReport()['total']; $marketing->recordEvent('page_view', ['path' => '/legacy/']);
    $assert($marketing->measurementReport()['total'] === $legacyCount, 'Legacy unverified events were relabelled consented.');
    $retainedEvents = $marketing->all()['events']; $capacity = $marketing->measurementReport()['capacity'];
    $assert($capacity['raw_events'] === count($retainedEvents) && $capacity['legacy_events'] === 1
        && $capacity['measured_events'] === count($retainedEvents) - 1, 'Storage usage hid retained legacy events.');
    $marketing->update(static function (array &$data): void {
        // Fill only this owned fixture with unique recent legacy records.
        $legacy = $data['events'][array_key_last($data['events'])];
        for ($index = count($data['events']); $index < MarketingAnalytics::MAX_EVENTS; $index++) {
            $data['events'][] = array_replace($legacy, ['id' => 'legacy-capacity-' . $index]);
        }
    });
    $capacityBefore = $marketing->all(); $capacity = $marketing->measurementReport()['capacity'];
    $assert($capacity['raw_events'] === MarketingAnalytics::MAX_EVENTS
        && $capacity['legacy_events'] === MarketingAnalytics::MAX_EVENTS - $capacity['measured_events'], 'Full storage usage did not include legacy entries.');
    $marketing->recordEvent('page_view', ['path' => '/legacy/']);
    $assert($marketing->all() === $capacityBefore, 'A legacy write at capacity silently evicted retained measurements.');
    $assert(!$marketing->recordMeasuredEvent('page_view', ['path' => '/links/']) && $marketing->all() === $capacityBefore,
        'A measured write at capacity changed existing records.');
    $marketing->update(static function (array &$data) use ($retainedEvents): void { $data['events'] = $retainedEvents; });
    $newsletter = $marketing->subscribe('recipient@example.test', 'Synthetic recipient', 'launch', true); $marketing->confirm($newsletter['token']);
    $campaign = $marketing->upsert('campaigns', ['subject' => 'Synthetic campaign', 'body' => 'Visit the public shop.', 'status' => 'draft']);
    $assert($marketing->queueCampaign($campaign['id']) === 1, 'The actual saved campaign delivery was not queued.');
    $delivery = $marketing->all()['campaign_queue'][0];
    foreach (['javascript:alert(1)','data:text/html,unsafe','//evil.example.test/','https://u:p@example.test/','https://example.test/%0d%0aInjected:yes','/\\evil.example.test/', 'https://example.test/a b', 'https://example.test/a%09b'] as $unsafe) $reject(fn() => $marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], $unsafe), 'Unsafe tracked destination was accepted.');
    $reject(fn() => $marketing->deliveryTrackedUrl($campaign['id'], 'missing-delivery', '/shop/'), 'Unsaved delivery received a tracked URL.');
    $encodedDestination = 'https://example.test/a%20b?label=two%20words';
    $encodedLink = $marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], $encodedDestination);
    parse_str((string) parse_url($encodedLink, PHP_URL_QUERY), $encodedQuery); $encodedBefore = $marketing->all();
    $assert($marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], $encodedDestination) === $encodedLink
        && $marketing->resolveTrackedLink($encodedQuery['t'], false)['destination'] === $encodedDestination
        && $marketing->all() === $encodedBefore, 'A safe encoded-space destination changed or collected data without consent.');
    $tracked = $marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], '/shop/');
    $assert(str_starts_with($tracked, $values['base_url'] . '/route.php?'), 'Tracked route lost nested installation dispatch.');
    $assert(!str_contains($tracked, 'recipient') && !str_contains($tracked, $delivery['id']) && !str_contains($tracked, $campaign['id']), 'Tracking URL exposed recipient/business identities.');
    $before = $marketing->all();
    $assert($marketing->deliveryTrackedUrl($campaign['id'], $delivery['id'], '/shop/') === $tracked && $marketing->all() === $before, 'Tracked URL was not stable and idempotent.');
    parse_str((string) parse_url($tracked, PHP_URL_QUERY), $query); $token = $query['t'];
    $resolved = $marketing->resolveTrackedLink($token, false);
    $assert($resolved['visitor_token'] === null && $resolved['destination'] === $values['base_url'] . '/route.php?vvx_route=shop' && $marketing->all() === $before, 'A no-consent click failed redirect or collected attribution.');
    $assert($marketing->resolveTrackedLink(str_repeat('0',64), true) === null, 'Unknown tracked token resolved.');
    $clicked = $marketing->resolveTrackedLink($token, true, ['visitor_token' => $visit['token']]);
    $assert($clicked['visitor_token'] === $visit['token'], 'Campaign click discarded valid referral association.');
    $snapshot = $marketing->requestAttribution();
    $assert($snapshot['campaign_id'] === $campaign['id'] && $snapshot['referral_code'] === 'launch', 'Click attribution lost campaign/referral state.');
    $marketing->resolveTrackedLink($token, true, ['visitor_token' => $visit['token']]);
    $assert($marketing->measurementReport()['counts']['campaign_click'] === 1, 'Repeated campaign click duplicated the same visitor/link measurement.');
    $mailer = new App\Core\MailService($config, $store);
    $memberAuth = new App\Core\MemberAuth($store, $config, $mailer);
    $registration = $memberAuth->register('Synthetic Member', 'member@example.test', 'AttributionFixture123!'); $member = $registration['member'];
    $assert($member['preferences']['_attribution'] === $snapshot, 'Registration did not durably capture the verified origin.');
    $assert(($marketing->measurementReport()['counts']['registration'] ?? 0) === 1, 'Registration projection was not recorded by the actual service.');
    $store->updateMember($member['id'], ['preferences' => ['emailUpdates' => false, '_attribution' => ['forged' => true]], 'status' => 'active', 'emailVerifiedAt' => gmdate('c')]);
    $member = $store->member($member['id']);
    $assert($member['preferences']['_attribution'] === $snapshot, 'A profile edit overwrote immutable referral origin.');
    $plan = $store->saveMembershipPlan(['name' => 'Synthetic plan', 'key' => 'attribution-plan', 'price' => 20, 'currency' => 'USD', 'isActive' => true]);
    $store->savePaymentProvider(['key' => 'sandbox', 'enabled' => true, 'testMode' => true, 'supportsRecurring' => true]);
    $store->saveCoupon(['code' => 'LAUNCH10', 'discountType' => 'percent', 'value' => 10, 'isActive' => true]);
    $couponCampaign = $marketing->upsert('campaigns', ['subject' => 'Coupon campaign', 'body' => 'Synthetic coupon origin.', 'status' => 'draft']);
    $couponRevision = $marketing->couponAttribution()['revision'];
    $marketing->saveCouponAttribution($store, 'LAUNCH10', $couponCampaign['id'], true, $couponRevision, $owner);
    $reject(fn() => $marketing->saveCouponAttribution($store, 'LAUNCH10', $campaign['id'], true, $couponRevision, $owner), 'Stale coupon assignment overwrote a later choice.');
    $commerce = new App\Core\CommerceService($store, $config, $mailer, new App\Payments\PaymentProviderRegistry());
    $purchase = $commerce->checkout($member['id'], [['type' => 'plan', 'id' => $plan['id'], 'mode' => 'purchase']], 'LAUNCH10', 'sandbox', ['sandboxResult' => 'success'], 'attribution-fixture-checkout');
    $order = $purchase['order'];
    $assert($order['status'] === 'paid' && (float) $order['grandTotal'] === 18.0, 'The genuine local sandbox checkout did not complete.');
    $assert($order['metadata']['attribution']['campaign_id'] === $couponCampaign['id'] && $order['metadata']['attribution']['delivery_id'] === null
        && $order['metadata']['attribution']['referral_code'] === 'launch', 'Applied coupon did not override campaign click while retaining referral.');
    $store->updateOrder($order['id'], ['metadata' => ['attribution' => ['forged' => true]]]);
    $assert($store->order($order['id'])['metadata']['attribution'] === $order['metadata']['attribution'], 'Order update overwrote canonical attribution.');
    $store->updateOrder($order['id'], ['paidAt'=>null]);
    $reject(fn() => $marketing->recordOrderAttribution($store, $order['id']), 'A paid status with an unknown payment time was reported as a confirmed purchase.');
    $store->updateOrder($order['id'], ['paidAt'=>$order['paidAt']]);
    $report = $marketing->measurementReport(['compare' => true]);
    $assert($report['counts']['checkout'] === 1 && $report['counts']['purchase'] === 1 && $report['gross_purchase_minor']['USD'] === 1800, 'Conversion report did not preserve exact paid totals.');
    $assert($report['retained_funnel'] === ['page_view' => 1, 'registration' => 1, 'checkout' => 1, 'purchase' => 1], 'Ordered same-browser funnel is incorrect.');
    $assert($report['previous']['total'] === 0, 'Comparison window included current events.');
    $assert($report['capacity']['raw_events'] >= 4 && $report['capacity']['raw_limit'] === MarketingAnalytics::MAX_EVENTS, 'Owner report omitted bounded measurement storage usage.');
    $assert($marketing->measurementReport(['campaign' => $couponCampaign['id']])['counts']['purchase'] === 1, 'Campaign filter lost coupon conversion.');
    $assert($marketing->measurementReport(['referral' => 'other'])['total'] === 0, 'Referral filter broadened the report.');
    $csv = MarketingAnalytics::csv($report);
    $assert(str_contains($csv, '1800,USD') && !str_contains($csv, 'member@example.test') && !str_contains($csv, $snapshot['actor']), 'Aggregate CSV leaked identities or lost money units.');
    $unsafeCsv = MarketingAnalytics::csv(array_replace($report, ['top_paths' => ['=CMD()' => 1, "\t@formula" => 1]]));
    $assert(str_contains($unsafeCsv, "'=CMD()") && str_contains($unsafeCsv, "' @formula"), 'Spreadsheet formula cells were not neutralized.');
    foreach ([['start'=>'2026-02-30'], ['start'=>'2025-01-01','end'=>'2026-12-31'], ['event'=>'private_message'], ['campaign'=>[]]] as $bad) $reject(fn() => $marketing->measurementReport($bad), 'Malformed report filter was accepted.');
    $marketing->recordOrderAttribution($store, $order['id']);
    $commerce->checkout($member['id'], [['type' => 'plan', 'id' => $plan['id'], 'mode' => 'purchase']], 'LAUNCH10', 'sandbox', ['sandboxResult' => 'success'], 'attribution-fixture-checkout');
    $assert($marketing->measurementReport()['counts']['purchase'] === 1, 'Retry or reconciliation duplicated purchase measurement.');
    $contact = $marketing->subscribe('member@example.test', 'Member newsletter', 'join', true); $marketing->confirm($contact['token']);
    $rules = MarketingAudience::rules(['version' => 2, 'membership' => 'active', 'plan_ids' => [(string) $plan['id']],
        'purchase' => ['min_count' => 1, 'min_total_minor' => 1800, 'currency' => 'USD'], 'referral_codes' => ['LAUNCH']]);
    $assert($marketing->previewSegment($rules)['eligible_count'] === 1, 'Business audience did not match canonical member/purchase/referral facts.');
    $subscription = $store->subscriptions($member['id'])[0];
    $store->saveSubscription(array_replace($subscription, ['currentPeriodStart'=>gmdate('c',time()+3600)]));
    $assert($marketing->previewSegment($rules)['eligible_count'] === 0, 'A future subscription was already treated as active.');
    $store->saveSubscription($subscription);
    $store->updateMember($member['id'], ['emailVerifiedAt'=>gmdate('c',time()+3600)]);
    $assert($marketing->previewSegment($rules)['eligible_count'] === 0, 'A future verification timestamp qualified for business audiences.');
    $store->updateMember($member['id'], ['emailVerifiedAt'=>gmdate('c')]);
    $assert($marketing->previewSegment(array_replace($rules, ['purchase' => array_replace($rules['purchase'], ['min_total_minor' => 1801])]))['eligible_count'] === 0, 'Purchase threshold rounded or broadened eligibility.');
    $assert($marketing->previewSegment(array_replace($rules, ['membership' => 'none', 'plan_ids' => []]))['eligible_count'] === 0, 'Active member matched no-active-membership criterion.');
    $segment = $marketing->saveSegment(['name' => 'Business audience', 'enabled' => true, 'rules' => $rules]);
    $reject(fn() => $marketing->saveSegment(['name'=>'Missing tier','enabled'=>true,'rules'=>array_replace($rules,['plan_ids'=>['missing-tier']])]), 'Unavailable selected tier was silently broadened.');
    $reject(fn() => $marketing->saveSegment(['name'=>'Revoked owner','enabled'=>true,'rules'=>$rules], static function (): void { throw new RuntimeException('Owner revoked.', 403); }), 'Revoked owner saved business audience criteria.');
    $selected = $marketing->upsert('campaigns', ['subject'=>'Frozen business audience', 'body'=>'Synthetic message', 'status'=>'draft',
        'audience'=>['mode'=>'segment','segment_id'=>$segment['id'],'segment_revision'=>$segment['revision']]]);
    $assert($marketing->queueCampaign($selected['id']) === 1, 'Business audience was not queued.');
    $queued = $marketing->all()['campaigns']; $frozen = null;
    foreach ($queued as $candidate) if ($candidate['id'] === $selected['id']) $frozen = $candidate['audience_snapshot'];
    $marketing->saveSegment(['id'=>$segment['id'],'expected_revision'=>$segment['revision'],'name'=>'Changed','enabled'=>true,'rules'=>['sources'=>['does-not-match']]]);
    foreach ($marketing->all()['campaigns'] as $candidate) if ($candidate['id'] === $selected['id']) $assert($candidate['audience_snapshot'] === $frozen, 'Editing audience changed frozen queued rules.');
    $marketing->unsubscribe($contact['contact']['unsubscribe_token']);
    $assert($marketing->previewSegment($rules)['eligible_count'] === 0, 'Business predicates bypassed independent newsletter revocation.');
    date_default_timezone_set('America/New_York');
    $reject(fn() => MarketingAudience::formDate('2026-03-08T02:30'), 'Skipped local audience time was accepted.');
    $reject(fn() => MarketingAudience::formDate('2026-11-01T01:30'), 'Ambiguous local audience time was accepted.');
    $assert(MarketingAudience::formDate('2026-11-01T03:30') === '2026-11-01T08:30:00Z', 'Unambiguous local audience time was not normalized.');
    $assert(!$marketing->recordOrderAttribution($store, $order['id']), 'Timezone change created a duplicate business conversion.');
    $lateMember = $store->createMember(['displayName'=>'Late synthetic registration','email'=>'late@example.test','password'=>'AttributionFixture123!',
        'preferences'=>['_attribution'=>$snapshot]]);
    $assert($marketing->recordRegistrationAttribution($store, $lateMember['id']), 'Reconciliation lost a genuine registration after the PHP site timezone changed.');
    date_default_timezone_set('UTC');
    if ($mysql) {
        $assert($report['backend'] === 'mysql-indexed', 'SQL reporting silently fell back to whole-file analytics.');
        $query = $pdo->query("EXPLAIN SELECT payload_json FROM marketing_measurement_records WHERE campaign_id='" . $couponCampaign['id'] . "' AND kind='event' AND occurred_at>=" . (time()-86400) . ' AND occurred_at<' . (time()+86400));
        $planRow = $query->fetch(); $assert(in_array('idx_measurement_campaign', explode(',', (string) $planRow['possible_keys']), true)
            && in_array($planRow['key'], ['PRIMARY','idx_measurement_campaign','idx_measurement_date'], true)
            && in_array($planRow['type'], ['range','ref'], true), 'The actual bounded report query has no useful index plan: ' . json_encode($planRow));
        $pdo->exec("UPDATE marketing_measurement_records SET event_name='corrupt' LIMIT 1");
        $assert($marketing->measurementReport()['counts']['purchase'] === 1, 'Corrupt reporting metadata was not repaired from the bound authority.');
        $pdo->exec("UPDATE marketing_measurement_records SET payload_json='{}' WHERE kind='event'");
        $assert($marketing->measurementReport()['counts']['purchase'] === 1, 'Corrupt reporting payload was not safely rebuilt.');
        $assert((int) $pdo->query('SELECT COUNT(*) FROM marketing_measurement_records')->fetchColumn() > 0, 'SQL report index was not populated.');
        $header = $pdo->query('SELECT header_json FROM marketing_storage_state WHERE id=1')->fetchColumn();
        $changedHeader = json_decode($header, true, 512, JSON_THROW_ON_ERROR); $changedHeader['settings']['analytics_enabled'] = false;
        $changeHeader = $pdo->prepare('UPDATE marketing_storage_state SET header_json=? WHERE id=1');
        $changeHeader->execute([json_encode($changedHeader, JSON_THROW_ON_ERROR)]);
        try { $reject(fn() => $marketing->measurementReport(), 'Indexed report trusted a changed canonical header without validating its snapshot.'); }
        finally { $changeHeader->execute([$header]); }
        $assert($marketing->measurementReport()['counts']['purchase'] === 1, 'Reviewed canonical header restoration did not recover the unchanged report.');
    }
    $privacyBefore = $marketing->all();
    $assert($marketing->forgetAttributionVisitor($visit['token']) > 0, 'Browser measurement deletion did not remove linked records.');
    $privacyAfter = $marketing->all();
    $assert($privacyAfter['contacts'] === $privacyBefore['contacts'] && $privacyAfter['consents'] === $privacyBefore['consents'] && $privacyAfter['suppressions'] === $privacyBefore['suppressions'], 'Measurement deletion altered newsletter rights.');
    $assert($marketing->measurementReport()['total'] === 0 && count($privacyAfter[MarketingAttribution::KEY]['erased_actors']) === 1, 'Deletion retained linked raw/aggregate events or lost the anti-resurrection marker.');
    $assert(!$marketing->recordRegistrationAttribution($store, $member['id']) && !$marketing->recordOrderAttribution($store, $order['id']), 'Reconciliation recreated erased attribution.');
    $assert($store->member($member['id']) !== null && $store->order($order['id'])['status'] === 'paid', 'Measurement deletion changed account/order business state.');
    $assert($marketing->forgetAttributionVisitor($visit['token']) === 0, 'Repeated deletion was not idempotent.');
    $seenKinds = [];
    for ($batch = 0; $batch < 4; $batch++) {
        $stats = $marketing->runAnalyticsMaintenance($store, 1, $owner);
        $assert($stats['examined'] <= 1 && $stats['projected'] === 0, 'Bounded maintenance ignored its cursor/erasure.');
        $seenKinds[$stats['next']['kind']] = true;
    }
    $assert(isset($seenKinds['member'], $seenKinds['order']), 'Bounded reconciliation did not progress through both source collections.');
    $freshVisit = $marketing->attributionVisitor(null, 'launch', true); $_COOKIE[MarketingPrivacy::VISITOR_COOKIE] = $freshVisit['token'];
    $assert($marketing->recordMeasuredEvent('page_view', ['path'=>'/links/']), 'A later explicit consenting visit could not start new measurement.');
    $settings = $marketing->analyticsConfiguration();
    $marketing->saveAnalyticsConfiguration(['enabled'=>false,'retention_days'=>7,'rollup_days'=>30], $settings['revision'], $owner);
    $reject(fn() => $marketing->saveAnalyticsConfiguration(['enabled'=>true,'retention_days'=>90,'rollup_days'=>730], $settings['revision'], $owner), 'Stale measurement settings overwrote a later opt-out policy.');
    $before = $marketing->all();
    $assert(!$marketing->recordMeasuredEvent('page_view', ['path'=>'/']) && $marketing->all() === $before, 'Owner collection switch did not stop measured events.');
    $assert($marketing->resolveTrackedLink($token, true)['destination'] !== '' && $marketing->all() === $before, 'Owner measurement disable broke links or collected new state.');
    $marketing->update(static function (array &$data): void {
        // Age only synthetic fixture measurements, never a runtime clock.
        $state = MarketingAnalytics::state($data); $state['rollups'] = [];
        foreach ($data['events'] as &$event) {
            if (($event['measurement_version'] ?? null) !== 1) continue;
            $event['epoch'] = time() - 14 * 86400; $event['created_at'] = gmdate('c', $event['epoch']);
            $row = ['date'=>gmdate('Y-m-d',$event['epoch']), 'name'=>$event['name'], 'path'=>$event['path'],
                'referral'=>$event['referral'], 'campaign'=>$event['campaign'], 'currency'=>$event['currency'], 'count'=>1, 'amount_minor'=>$event['amount_minor']];
            $state['rollups'][MarketingAnalytics::rollupKey($row)] = $row;
        }
        unset($event); $data[MarketingAnalytics::KEY] = $state;
    });
    $marketing->runAnalyticsMaintenance($store, 1, $owner);
    $retained = $marketing->measurementReport(['start'=>gmdate('Y-m-d',time()-20*86400)]);
    $assert($retained['total'] === 1 && $retained['retained_unique_visitors'] === 0, 'Raw retention failed or destroyed still-retained anonymous totals.');
    $marketing->forgetAttributionVisitor($freshVisit['token']);
    $assert($marketing->measurementReport(['start'=>gmdate('Y-m-d',time()-20*86400)])['total'] === 1, 'Anonymous totals were incorrectly claimed as individually identifiable after raw expiry.');
    $settings = $marketing->analyticsConfiguration();
    $marketing->saveAnalyticsConfiguration(['enabled'=>false,'retention_days'=>7,'rollup_days'=>7], $settings['revision'], $owner);
    $marketing->runAnalyticsMaintenance($store, 1, $owner);
    $assert($marketing->measurementReport(['start'=>gmdate('Y-m-d',time()-20*86400)])['total'] === 0, 'Anonymous total retention did not remove expired buckets.');
    $reject(fn() => $marketing->saveAnalyticsConfiguration(['enabled'=>true,'retention_days'=>1,'rollup_days'=>30], $marketing->analyticsConfiguration()['revision'], $owner), 'Unsupported retention was accepted.');
    $reject(fn() => $marketing->runAnalyticsMaintenance($store, 251), 'Maintenance exceeded the bounded batch limit.');
    $reject(fn() => $marketing->saveLinkBioPresentation(['featured_asset_id'=>'private-missing','featured_alt'=>'Synthetic image'], $marketing->linkBioPresentation()['revision'], $owner), 'Unavailable link-in-bio media was accepted.');
    $reject(fn() => MarketingAttribution::snapshot(array_replace($snapshot,['actor'=>[]])), 'Malformed persisted origin was accepted.');
    $corruptAttribution = $marketing->all(); $corruptAttribution[MarketingAttribution::KEY]['coupons']['LAUNCH10']['enabled'] = 'yes';
    $reject(fn() => MarketingAttribution::state($corruptAttribution), 'Malformed persisted coupon attribution was accepted.');
    $reject(fn() => MarketingAnalytics::event(['measurement_version'=>1]), 'Malformed measured event entered reporting.');
    echo 'Marketing attribution, consent, business audiences and indexed reports passed (' . $checks . ' checks; ' . ($mysql ? 'SQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) : 'file') . "; synthetic local sandbox only).\n";
} finally {
    $_COOKIE = [];
    if ($pdo instanceof PDO && $ownsDatabase) vvx_installer_fixture_reset_database($pdo, true);
    if (is_file($fixture . '/.vvx-attribution-fixture')) vvx_installer_fixture_remove($fixture);
}
