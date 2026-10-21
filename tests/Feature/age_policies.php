<?php
declare(strict_types=1);

use App\Core\AgeAccess;
use App\Core\Config;
use App\Core\GeographicPolicy as Geo;
use App\Core\PrivateState;
use App\Core\ProtectionRecords;
use App\Core\ProtectionUploads;
use App\Core\PublicPolicies;
use App\Core\SecureValue;
use App\Core\Store;
use App\Core\YotiAgeProvider as Yoti;

$source = dirname(__DIR__, 2); $root = sys_get_temp_dir() . '/vvx-age-source-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700); mkdir($root . '/uploads', 0755);
file_put_contents($root . '/.owned-fixture', 'Disposable age/policy source acceptance.'); define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void { if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php'; });
require_once $source . '/app/Support/functions.php'; require_once $source . '/app/Core/MarketingStore.php'; require_once $source . '/tests/Installer/database_fixture.php';
$mysql = in_array('--mysql', $argv, true); $pdo = null; $owns = false; $checks = 0; $calls = [];
$assert = static function (bool $value, string $why) use (&$checks): void { ++$checks; if (!$value) throw new RuntimeException($why); };
$reject = static function (callable $work, string $why, ?int $code = null) use ($assert): void {
    try { $work(); } catch (InvalidArgumentException|RuntimeException $error) { $assert($code === null || $error->getCode() === $code, $why . ': ' . $error->getMessage()); return; } $assert(false, $why);
};
$config = new Config($root . '/config/app.php'); $guard = static function (): void {};
try {
    $values = ['storage_mode' => $mysql ? 'mysql' : 'file', 'file_store_path' => 'storage/data.json', 'base_url' => 'https://fixture.example.test/nested',
        'installed' => true, 'installation_id' => 'age-test-' . bin2hex(random_bytes(12)), 'security' => ['app_key' => bin2hex(random_bytes(32)), 'media_key' => bin2hex(random_bytes(32))]];
    if ($mysql) { [$db, $pdo] = vvx_installer_fixture_database(); vvx_installer_fixture_empty($pdo); $owns = true; $values['db'] = $db; }
    $config->write($values); file_put_contents($root . '/storage/install.lock', json_encode(['installation_id' => $values['installation_id']], JSON_THROW_ON_ERROR));
    if ($mysql) {
        (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
        (new Store($config))->setSetting('installation', ['installation_id' => $values['installation_id'], 'database_hash' => hash('sha256', $db['name'] . '|' . $values['installation_id'])]);
        mkdir($root . '/database/migrations', 0700, true); foreach (glob($source . '/database/migrations/*.php') as $migration) copy($migration, $root . '/database/migrations/' . basename($migration));
    } else file_put_contents($root . '/storage/data.json', '{}');
    $state = new PrivateState($config); $store = new Store($config); $policies = new PublicPolicies($config, $state);
    $store->createAdmin('Age owner', 'age-owner@example.test', 'OwnerFixtureAge12!'); $owner = $store->findAdminByEmail('age-owner@example.test');
    $changeOwner = static function (bool $active) use ($pdo, $store, $owner): void {
        if ($pdo) $pdo->prepare('UPDATE admins SET is_active=? WHERE id=?')->execute([$active ? 1 : 0, $owner['id']]);
        else { $data = $store->rawFileData(); $data['admins'][(string) $owner['id']]['is_active'] = $active ? 1 : 0; $store->replaceFileData($data); }
    };
    $fresh = static fn(string $name = 'terms'): array => $policies->read($name, $owner);
    foreach (PublicPolicies::DOCUMENTS as $name => $label) { $assert($policies->published($name) === null, 'A blank policy was published by a read.'); $assert($fresh($name)['draft']['body'] === '', 'A policy invented public terms.'); }
    $assert($state->read('public_policies') === [], 'Default policy reads initialized storage.');
    $before = $fresh(); $draft = ['title' => 'Reviewed <policy>', 'body' => '<script>window.policyInjected=true</script>\nPrivate draft wording.'];
    $policies->save('terms', $draft, $before['revision'], $owner, $guard); $saved = $fresh();
    $assert($saved['draft'] === $draft && $policies->published('terms') === null, 'Saving a draft made it public or rewrote text.');
    $reject(fn() => $policies->publish('terms', $saved['revision'], false, $owner, $guard), 'An unreviewed policy was published.');
    $reject(fn() => $policies->publish('terms', $before['revision'], true, $owner, $guard), 'Stale publication succeeded.', 409);
    $policies->publish('terms', $saved['revision'], true, $owner, $guard); $assert($policies->published('terms')['body'] === $draft['body'], 'Reviewed saved draft was not published.');
    $policies->save('terms', ['title' => 'New draft', 'body' => 'PRIVATE_NEW_DRAFT'], $fresh()['revision'], $owner, $guard);
    $assert($policies->published('terms')['body'] === $draft['body'], 'A later draft changed public wording.');
    $reject(fn() => $policies->save('terms', $draft, $saved['revision'], $owner, $guard), 'Stale draft save overwrote current wording.', 409);
    foreach (['privacy', 'adults', 'dmca', 'support'] as $name) {
        $policies->save($name, ['title' => PublicPolicies::DOCUMENTS[$name], 'body' => 'Synthetic reviewed ' . $name], $fresh($name)['revision'], $owner, $guard);
        $policies->publish($name, $fresh($name)['revision'], true, $owner, $guard); $assert($policies->published($name)['body'] === 'Synthetic reviewed ' . $name, 'Policy selection crossed another document.');
    }
    $policies->unpublish('terms', $fresh()['revision'], $owner, $guard); $assert($policies->published('terms') === null && $fresh()['draft']['body'] === 'PRIVATE_NEW_DRAFT', 'Withdrawal lost its draft or left it public.');
    $recent = static function (): void { throw new RuntimeException('Recent confirmation required.', 428); };
    $savedState = $state->read('public_policies');
    $reject(fn() => $policies->save('terms', $draft, $fresh()['revision'], $owner, $recent), 'Old owner confirmation changed a draft.', 428);
    $reject(fn() => $policies->publish('terms', $fresh()['revision'], true, $owner, $recent), 'Old owner confirmation published a draft.', 428);
    $reject(fn() => $policies->unpublish('privacy', $fresh('privacy')['revision'], $owner, $recent), 'Old owner confirmation withdrew a policy.', 428);
    $revision = $fresh()['revision']; $changeOwner(false);
    $reject(fn() => $policies->read('terms', $owner), 'Revoked owner read a draft.', 403);
    $reject(fn() => $policies->save('terms', $draft, $revision, $owner, $guard), 'Revoked owner saved a draft.', 403); $changeOwner(true);
    $revokeOnGuard = static function () use ($changeOwner): void { $changeOwner(false); };
    $reject(fn() => $policies->save('terms', $draft, $revision, $owner, $revokeOnGuard), 'Owner revocation during save was ignored.', 403); $changeOwner(true);
    $assert($state->read('public_policies') === $savedState, 'Rejected policy writes changed saved bytes.');
    foreach ([['title' => '', 'body' => 'body'], ['title' => 'x', 'body' => str_repeat('x', 30001)], ['title' => [], 'body' => 'x'], ['title' => 'x', 'body' => "bad\x00text"], ['title' => 'x', 'body' => "\xff"]] as $bad) $reject(fn() => $policies->save('terms', $bad, $fresh()['revision'], $owner, $guard), 'Invalid policy text was saved.');
    $reject(fn() => $policies->published('../config'), 'Arbitrary policy name was accepted.');
    $state->update('public_policies', static function (array &$data): void { $data['schema'] = 90; });
    $reject(fn() => $policies->published('privacy'), 'Malformed policy state became public.', 503);
    $state->update('public_policies', static function (array &$data) use ($savedState): void { $data = $savedState; });
    foreach ([static function (array &$data): void { $data['unexpected'] = true; },
        static function (array &$data): void { $data['documents']['terms']['draft']['unexpected'] = true; },
        static function (array &$data): void { $data['documents']['privacy']['published']['published_at'] = '2026-02-30T00:00:00Z'; },
        static function (array &$data): void { $data['documents']['terms']['generation'] = -1; }] as $corrupt) {
        $state->update('public_policies', $corrupt);
        $reject(fn() => $policies->published('privacy'), 'Malformed policy schema or time became public.', 503);
        $state->update('public_policies', static function (array &$data) use ($savedState): void { $data = $savedState; });
    }

    $server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.0.2.10'];
    $service = new AgeAccess($config); $assert($service->decision([], $server) === 'allowed' && $service->settings()['mode'] === 'disabled', 'Default-off age policy blocked legacy behavior.');
    $assert($state->read('age_requests') === [], 'Reading age status initialized rate storage.');
    $input = ['mode' => 'required', 'minimum_age' => '18', 'session_minutes' => '60', 'sdk_id' => '11111111-2222-4333-8444-555555555555', 'api_key' => 'synthetic-age-provider-key',
        'clear_api_key' => false, 'geographic_enabled' => false, 'country_header' => 'HTTP_CF_IPCOUNTRY', 'trusted_proxies' => '', 'blocked_countries' => '', 'reviewed_rules' => true];
    $reject(fn() => $service->saveConfiguration($input, $config->revision(), $owner, $guard), 'Age required enabled before protected media.', 409);
    $protection = new ProtectionRecords($config, $state, new ProtectionUploads($config, static fn(): int => 403));
    $protection->activate($config->revision(), ProtectionRecords::revision($protection->snapshot()), $owner, $guard);
    $service->saveConfiguration($input, $config->revision(), $owner, $guard); $configuration = $service->configuration($owner);
    $assert($configuration['api_key_saved'] && !isset($configuration['api_key_encrypted']) && !str_contains(json_encode($configuration), $input['api_key']), 'Owner configuration disclosed the provider secret.');
    $assert(!str_contains(file_get_contents($config->path()), $input['api_key']) && (new SecureValue($config))->decrypt($service->settings()['api_key_encrypted']) === $input['api_key'], 'Provider key was not encrypted.');
    $assert($service->decision(['age_verified' => true], $server) === 'age', 'A client acknowledgement granted required age access.');
    $reject(fn() => $service->saveConfiguration($input, str_repeat('a', 64), $owner, $guard), 'Stale access settings were saved.', 409);
    $reject(fn() => $service->saveConfiguration($input, $config->revision(), $owner, $recent), 'Old owner confirmation changed access rules.', 428);
    foreach ([['minimum_age', '17'], ['minimum_age', '100'], ['session_minutes', '14'], ['session_minutes', '1441'], ['mode', 'anything'], ['sdk_id', 'https://evil.test'], ['api_key', "key\r\ninjected"], ['country_header', 'HTTP_X_FORWARDED_FOR'], ['trusted_proxies', '0.0.0.0/0'], ['trusted_proxies', '::/0'], ['blocked_countries', 'XX'], ['reviewed_rules', false]] as [$key, $value]) {
        $bad = $input; $bad[$key] = $value; $reject(fn() => $service->saveConfiguration($bad, $config->revision(), $owner, $guard), 'Invalid access setting was accepted: ' . $key);
    }
    $geo = ['enabled' => true, 'header' => 'HTTP_CF_IPCOUNTRY', 'trusted_proxies' => Geo::networks('192.0.2.0/24 2001:db8:100::/48'), 'blocked_countries' => ['US']];
    foreach ([['192.0.2.34', 'GB', true], ['192.0.3.34', 'GB', false], ['2001:db8:100::42', 'GB', true], ['2001:db8:101::42', 'GB', false], ['::ffff:192.0.2.34', 'GB', false], ['192.0.2.34', 'US', false], ['192.0.2.34', 'XX', false], ['192.0.2.34', 'T1', false], ['192.0.2.34', 'gb', false], ['192.0.2.34', ' GB', false], ['192.0.2.34', '', false], ['192.0.2.34', ['GB'], false]] as [$peer, $country, $allowed]) $assert(Geo::allowed($geo, ['REMOTE_ADDR' => $peer, 'HTTP_CF_IPCOUNTRY' => $country]) === $allowed, 'Immediate-peer country policy failed.');
    $assert(!Geo::allowed($geo, ['REMOTE_ADDR' => '203.0.113.4', 'HTTP_CF_IPCOUNTRY' => 'GB', 'HTTP_X_FORWARDED_FOR' => '192.0.2.4']), 'Forged forwarded visitor chain was trusted.');
    $assert(Geo::networks('192.0.2.7/24 192.0.2.0/24 2001:db8::2') === ['192.0.2.0/24', '2001:db8::2/128'], 'CIDR canonicalization changed network meaning.');
    foreach (['*', '192.0.2.1/33', '2001:db8::/129', '192.0.2.1/00', '127.1', '1.2.3.4:80', 'fe80::1%eth0', str_repeat('192.0.2.1 ', 33)] as $bad) $reject(fn() => Geo::networks($bad), 'Malformed/broad network accepted.');

    $request = null; $providerResult = null; $responseOverride = null;
    $transport = static function (string $method, string $url, array $headers, ?string $body) use (&$request, &$providerResult, &$responseOverride, &$calls, $assert): array {
        $calls[] = ['method' => $method, 'url' => $url];
        $assert(str_starts_with($url, 'https://age.yoti.com/api/v1/sessions') && in_array('Yoti-SDK-Id: 11111111-2222-4333-8444-555555555555', $headers, true), 'Provider destination or SDK binding changed.');
        if ($responseOverride !== null) return $responseOverride;
        if ($method === 'POST') {
            $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR); $now = time();
            $assert($request['type'] === 'OVER' && $request['doc_scan']['threshold'] === 18 && $request['doc_scan']['allowed'] === true && $request['doc_scan']['authenticity'] === 'AUTO'
                && $request['doc_scan']['level'] === 'PASSIVE' && $request['age_estimation']['allowed'] === false && $request['digital_id']['allowed'] === false, 'The provider request did not require adult document verification.');
            $assert($request['callback'] === ['auto' => false, 'url' => 'https://fixture.example.test/nested/route.php?vvx_route=age/index.php'], 'Provider callback did not use the fixed configured destination.');
            $providerResult = ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'sdk_id' => '11111111-2222-4333-8444-555555555555', 'reference_id' => $request['reference_id'],
                'type' => 'OVER', 'status' => 'COMPLETE', 'method' => 'DOC_SCAN', 'age' => 18, 'doc_scan' => $request['doc_scan'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 900)];
            return ['status' => 200, 'body' => json_encode(['id' => $providerResult['id'], 'expires_at' => $providerResult['expires_at']], JSON_THROW_ON_ERROR)];
        }
        $assert($method === 'GET' && $body === null && str_ends_with($url, '/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee/result'), 'Provider result request was not the bound session GET.');
        return ['status' => 200, 'body' => json_encode($providerResult, JSON_THROW_ON_ERROR)];
    };
    $service = new AgeAccess($config, $transport, $state); $session = [];
    $reject(fn() => $service->begin($session, ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $server['REMOTE_ADDR']]), 'GET started a provider check.', 405);
    $assert($calls === [], 'Read-only settings or GET contacted the provider.');
    $destination = $service->begin($session, $server); $attempt = $service->pending($session); $assert($attempt !== null && $service->decision($session, $server) === 'age', 'Starting a check prematurely granted access.');
    $assert($destination === 'https://age.yoti.com?sessionId=aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee&sdkId=11111111-2222-4333-8444-555555555555', 'Browser redirect accepted an arbitrary destination.');
    $copy = $session; $copy['vvx_age_attempt']['data']['threshold'] = 17; $assert($service->pending($copy) === null, 'Tampered pending state remained valid.');
    $reject(fn() => $service->complete($session, ['REQUEST_METHOD' => 'GET']), 'GET completed verification.', 405);
    $provider = new Yoti($config, $service->settings()['sdk_id'], $service->settings()['api_key_encrypted'], $transport); $valid = $providerResult;
    $mutations = ['id' => 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'sdk_id' => 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'reference_id' => 'foreign', 'type' => 'AGE', 'age' => 19, 'method' => 'DIGITAL_ID', 'status' => 'FAIL',
        'created_at' => gmdate('c', time() - 3600), 'updated_at' => gmdate('c', time() + 120), 'expires_at' => gmdate('c', time() + 899)];
    foreach ($mutations as $key => $value) { $providerResult = $valid; $providerResult[$key] = $value; $reject(fn() => $provider->verified($attempt, time()), 'Provider binding accepted altered ' . $key); }
    foreach (['UNDER', '', null, []] as $type) { $providerResult = $valid; $providerResult['type'] = $type; $reject(fn() => $provider->verified($attempt, time()), 'An unknown result type granted access.'); }
    foreach (['ERROR', 'CANCELLED', 'EXPIRED', 'NEW_FUTURE_STATE', true] as $status) { $providerResult = $valid; $providerResult['status'] = $status; $reject(fn() => $provider->verified($attempt, time()), 'Non-complete provider status granted access.'); }
    foreach (['PENDING', 'IN_PROGRESS'] as $status) { $providerResult = $valid; $providerResult['status'] = $status; $assert(!$provider->verified($attempt, time()), 'Pending provider result granted access.'); }
    foreach (['18', 18.1, true, null] as $age) { $providerResult = $valid; $providerResult['age'] = $age; $reject(fn() => $provider->verified($attempt, time()), 'Non-integer threshold granted access.'); }
    foreach ([['allowed' => false], ['allowed' => 'true'], ['threshold' => 21], ['threshold' => '18'], ['level' => 'NONE'], ['authenticity' => 'NONE'], ['authenticity' => null]] as $badMethod) { $providerResult = $valid; $providerResult['doc_scan'] = array_replace($valid['doc_scan'], $badMethod); $reject(fn() => $provider->verified($attempt, time()), 'Unbound verification method granted access.'); }
    foreach (['created_at', 'updated_at', 'expires_at', 'sdk_id', 'id', 'reference_id', 'age', 'method', 'doc_scan'] as $key) { $providerResult = $valid; unset($providerResult[$key]); $reject(fn() => $provider->verified($attempt, time()), 'Missing provider field granted access: ' . $key); }
    foreach (['2026-02-30T00:00:00Z', 'yesterday', 123, '', '2026-10-21T25:00:00Z'] as $value) $reject(fn() => Yoti::timestamp($value), 'Malformed provider time accepted.');
    foreach ([['status' => 302, 'body' => '{}'], ['status' => 401, 'body' => '{}'], ['status' => 500, 'body' => '{}'], ['status' => 200, 'body' => '{'], ['status' => 200, 'body' => '[]'], ['status' => 200, 'body' => str_repeat('x', 262145)]] as $badResponse) { $responseOverride = $badResponse; $reject(fn() => $provider->verified($attempt, time()), 'Malformed/redirected provider transport granted access.', 503); }
    $responseOverride = null; $providerResult = $valid;
    $assert($service->complete($session, $server) && $service->decision($session, $server) === 'allowed' && $service->pending($session) === null, 'A valid bound result did not create a server-signed grant.');
    $callCount = count($calls); $assert($service->decision($session, $server) === 'allowed' && count($calls) === $callCount, 'A public content read contacted the provider.');
    $copy = $session; $copy['vvx_age_grant']['data']['expires_at'] += 3600; $assert($service->decision($copy, $server) === 'age', 'Tampered server grant granted access.');
    $copy = $session; $copy['vvx_age_grant']['signature'] = str_repeat('f', 64); $assert($service->decision($copy, $server) === 'age', 'Forged grant signature granted access.');
    $copy = []; $reject(fn() => $service->complete($copy, $server), 'Client-only completion granted access.', 403);
    $reject(fn() => $service->begin($copy, $server), 'Provider start rate limiter could be bypassed with another session.', 429);
    $policyConfig = $config->all(); $changed = $input; $changed['minimum_age'] = '21'; $changed['api_key'] = '';
    $service->saveConfiguration($changed, $config->revision(), $owner, $guard); $assert($service->decision($session, $server) === 'age', 'A changed threshold preserved an earlier grant.');
    $config->write($policyConfig);
    $changed = $input; $changed['api_key'] = ''; $changed['geographic_enabled'] = true; $changed['trusted_proxies'] = '192.0.2.0/24'; $changed['blocked_countries'] = 'US';
    $service->saveConfiguration($changed, $config->revision(), $owner, $guard);
    $assert($service->decision($session, $server + ['HTTP_CF_IPCOUNTRY' => 'US']) === 'location', 'A valid grant bypassed geographic denial.');
    $assert($service->decision($session, $server) === 'location', 'Unknown location failed open.');
    $config->write($policyConfig);
    foreach ([null, true, ['schema' => 99], array_replace($service->settings(), ['mode' => 'unknown']), array_replace($service->settings(), ['minimum_age' => '18'])] as $bad) {
        $values = $policyConfig; $values['age_access'] = $bad; $config->write($values); $assert($service->decision($session, $server) === 'unavailable', 'Malformed saved access policy failed open.'); $config->write($policyConfig);
    }
    $changed = $input; $changed['api_key'] = ''; $changed['clear_api_key'] = true; $service->saveConfiguration($changed, $config->revision(), $owner, $guard);
    $assert($service->decision($session, $server) === 'unavailable' && !$service->configuration($owner)['api_key_saved'], 'Cleared required credentials failed open.');
    foreach (['account/privacy.php', 'communication/privacy-download.php', 'account/login.php', 'privacy/choices.php', 'reports/index.php', 'policies/index.php', 'newsletter/unsubscribe.php'] as $route) $assert(AgeAccess::helpRoute($route), 'Help route was blocked: ' . $route);
    foreach (['account/index.php', 'account/library.php', 'media.php', 'protected-asset.php', 'api/scene.php', 'videos/watch.php'] as $route) $assert(!AgeAccess::helpRoute($route), 'Ordinary content route was exempt: ' . $route);
    $assert($state->read('public_policies') === $savedState, 'Access changes altered public policies.');
    echo json_encode(['suite' => 'age-policies-source', 'mode' => $mysql ? 'mysql' : 'file', 'php' => PHP_VERSION,
        'mysql_version' => $pdo ? $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) : null, 'checks' => $checks, 'mock_provider_requests' => count($calls), 'real_provider_requests' => 0, 'smtp_connections' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if ($pdo && $owns) vvx_installer_fixture_reset_database($pdo, true);
    if (is_file($root . '/.owned-fixture')) vvx_installer_fixture_remove($root);
}
