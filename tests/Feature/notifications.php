<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\NotificationService;
use App\Core\PrivateState;
use App\Core\Store;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-notifications-' . bin2hex(random_bytes(8));
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
    $data['notification_sentinel'] = ['untouched' => 781];
});
$assertions = 0;
$assert = static function (bool $ok, string $message) use (&$assertions): void { ++$assertions; if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $operation, string $message, ?int $code = null) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException|RuntimeException $error) { $assert($code === null || $error->getCode() === $code, $message . ' (error code)'); return; }
    $assert(false, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    rmdir($path);
};
$internal = static fn(NotificationService $service, string $method, mixed ...$args): mixed => (new ReflectionMethod($service, $method))->invoke($service, ...$args);
$member = static fn(string $name, array $preferences = [], string $status = 'active'): array => (new Store($config))->createMember([
    'displayName' => $name, 'email' => strtolower($name) . '@example.test', 'password' => 'Source-Only-Password-781',
    'status' => $status, 'emailVerifiedAt' => $status === 'active' ? gmdate('c', time() - 10) : null, 'preferences' => $preferences]);
try {
    $private = new PrivateState($config, $root);
    $service = new NotificationService($config, $store, null, $private);
    $before = file_get_contents($root . '/storage/data.json');
    $empty = $service->overview();
    $assert($empty['total'] === 0 && !$empty['settings']['enabled'], 'Notifications must begin paused and empty.');
    $assert(!is_dir($root . '/storage/private/operations') && !is_dir($root . '/storage/private/marketing') && file_get_contents($root . '/storage/data.json') === $before, 'Read-only overview initialized or mutated storage.');
    $assert($service->discover()['held'] !== null && $private->read('notifications') === [], 'Paused discovery created records.');
    $marketing = new MarketingStore($root);
    $old = $marketing->upsert('posts', ['title' => 'Old existing content', 'slug' => 'old-existing', 'body' => 'PRIVATE_BODY_SENTINEL_781', 'status' => 'published', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]]);
    $opted = $member('Opted', ['newContentAlerts' => true, 'emailUpdates' => true]);
    $noConsent = $member('NoConsent');
    $pending = $member('Pending', ['registrationReminder' => true], 'pending');
    $pendingNo = $member('PendingNo', [], 'pending');
    foreach ([['enabled' => 1, 'types' => []], ['enabled' => true, 'types' => ['arbitrary']], ['enabled' => true, 'types' => ['renewal', 'renewal']], ['enabled' => true, 'types' => 'renewal']] as $input) {
        $reject(fn() => $service->saveSettings($input, $empty['revision'], $owner), 'Invalid notification settings were accepted.');
    }
    $assert($private->read('notifications') === [], 'Rejected settings initialized the notification journal.');
    $service->saveSettings(['enabled' => true, 'types' => NotificationService::TYPES], $empty['revision'], $owner);
    $saved = $private->read('notifications');
    $reject(fn() => $service->saveSettings(['enabled' => false, 'types' => []], $empty['revision'], $owner), 'A stale settings form overwrote the master switch.', 409);
    $assert($private->read('notifications') === $saved, 'Stale settings changed durable bytes.');
    $assert($service->discover()['queued'] === 0, 'Enabling notifications announced the old catalog.');
    $post = $marketing->upsert('posts', ['title' => 'Fresh PRIVATE_TITLE_SENTINEL_781', 'slug' => 'fresh-private', 'body' => 'PRIVATE_BODY_SENTINEL_781', 'status' => 'published', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]]);
    $discovery = $service->discover();
    $assert($discovery['queued'] === 1 && $service->overview()['counts']['queued'] === 1, 'New content ignored opt-in or current publication.');
    $assert($service->discover()['queued'] === 0, 'Repeated discovery queued duplicate new-content notices.');
    $job = array_values($private->read('notifications')['jobs'])[0];
    $assert($job['member_id'] === $opted['id'] && $job['source_id'] === 'post:' . $post['id'], 'New-content identity is not bound to its actual source/member.');
    $assert(!str_contains(json_encode($private->read('notifications')), 'PRIVATE_') && !str_contains(json_encode($service->overview()), '@example.test'), 'Journal copied private content or exposed recipient emails.');
    $assert($internal($service, 'eligible', $job) === ['email' => 'opted@example.test'], 'Eligible notice was denied.');
    $message = $internal($service, 'render', $job);
    $assert(str_contains($message['text'], '/nested/site/route.php?vvx_route=account') && !str_contains($message['text'], 'PRIVATE_'), 'Notification leaked private content or ignored nested routing.');
    (new Store($config))->updateMember($opted['id'], ['preferences' => ['newContentAlerts' => false, 'emailUpdates' => true]]);
    $assert($internal($service, 'eligible', $job) === null, 'Opt-out did not revoke a queued notice.');
    (new Store($config))->updateMember($opted['id'], ['preferences' => ['newContentAlerts' => true, 'emailUpdates' => true]]);
    $marketing->upsert('posts', array_replace($post, ['status' => 'draft']));
    $assert($internal($service, 'eligible', $job) === null, 'Unpublished source still permitted a notice.');
    $marketing->upsert('posts', $post);
    $marketing->update(static function (array &$data): void { $data['suppressions'][] = ['email' => 'opted@example.test', 'active' => true, 'reason' => 'fixture-bounce']; });
    $assert($internal($service, 'eligible', $job) === null, 'Global suppression did not block a member notice.');
    $marketing->update(static function (array &$data): void { $data['suppressions'] = []; });
    $assert($internal($service, 'eligible', $job) !== null, 'Restored eligibility was not freshly read.');
    foreach ([['new_content', 'invented', $opted['id'], ['kind' => 'post', 'object_id' => $post['id']]],
        ['renewal', 'order:x', $opted['id'], ['kind' => 'order', 'object_id' => 'x', 'body' => 'Private']],
        ['pending_registration', 'registration:' . $pending['id'], $pending['id'], ['url' => 'https://evil.test']]] as $args) {
        $reject(fn() => $service->enqueue(...$args), 'Unsafe/caller-selected context entered the notification journal.');
    }
    $claim = $internal($service, 'claim', $owner);
    $assert($claim['job']['id'] === $job['id'] && $claim['job']['attempts'] === 1, 'Worker did not durably claim the queued notice.');
    $assert($internal($service, 'claim', $owner)['job'] === null, 'A second worker claimed the leased notice.');
    $reject(fn() => $service->cancel($job['id'], NotificationService::jobRevision($job), $owner), 'Stale cancel discarded a newly claimed notice.', 409);
    $private->update('notifications', static function (array &$data) use ($job): void { $data['jobs'][$job['id']]['claim']['until'] = time() - 1; });
    $recovered = $internal($service, 'claim', $owner);
    $assert($recovered['recovered'] === 1 && $recovered['job']['attempts'] === 1 && $recovered['job']['message_id'] === $job['message_id'], 'Interrupted pre-body claim was not safely recovered with stable identity.');
    $private->update('notifications', static function (array &$data) use ($job): void { $data['jobs'][$job['id']]['status'] = 'sending'; $data['jobs'][$job['id']]['recipient_hash'] = $data['jobs'][$job['id']]['email_hash']; $data['jobs'][$job['id']]['claim']['until'] = time() - 1; });
    $held = $internal($service, 'claim', $owner);
    $unknown = $private->read('notifications')['jobs'][$job['id']];
    $assert($held['recovered'] === 1 && $held['job'] === null && $unknown['status'] === 'unknown' && $unknown['claim'] === null, 'Interrupted body transmission was automatically retried.');
    $reject(fn() => $service->retry($job['id'], NotificationService::jobRevision($unknown), $owner), 'Unknown transmission was manually retried.', 409);
    $reject(fn() => $service->cancel($job['id'], NotificationService::jobRevision($unknown), $owner), 'Unknown outcome was misleadingly recalled.', 409);
    $plan = (new Store($config))->saveMembershipPlan(['name' => 'Fixture plan', 'key' => 'fixture-plan', 'active' => true, 'price' => 10]);
    $subscription = (new Store($config))->saveSubscription(['memberId' => $opted['id'], 'planId' => $plan['id'], 'status' => 'active', 'currentPeriodEnd' => gmdate('c', time() + 3600)]);
    $renewal = (new Store($config))->createOrder(['memberId' => $opted['id'], 'status' => 'paid', 'paidAt' => gmdate('c'), 'metadata' => ['renewal' => true], 'providerKey' => 'sandbox'], []);
    $failed = (new Store($config))->createOrder(['memberId' => $opted['id'], 'status' => 'failed'], []);
    (new Store($config))->mutateFileStore(static function (array &$data) use ($pending, $pendingNo): void {
        $data['members'][$pending['id']]['createdAt'] = gmdate('c', time() - 90000);
        $data['members'][$pendingNo['id']]['createdAt'] = gmdate('c', time() - 90000);
    });
    $assert($service->discover()['queued'] === 4, 'Actual renewal, upcoming period, failed order and opted-in unfinished registration were not discovered.');
    $assert($service->discover()['queued'] === 0, 'Personal notices were duplicated on reconciliation.');
    $jobs = $private->read('notifications')['jobs'];
    $byType = [];
    foreach ($jobs as $item) $byType[$item['type']] = $item;
    $assert(isset($byType['renewal'], $byType['renewal_reminder'], $byType['payment_failure'], $byType['pending_registration']), 'Discovery omitted a supported workflow.');
    $service->saveSettings(['enabled' => true, 'types' => ['pending_registration']], $service->overview()['revision'], $owner);
    $pendingJob = $internal($service, 'claim', $owner)['job'];
    $assert($pendingJob['type'] === 'pending_registration', 'The real worker did not select the permitted registration reminder.');
    $pendingMessage = $internal($service, 'render', $pendingJob, $owner);
    $assert(preg_match('/token=([A-Za-z0-9_-]{48})/', $pendingMessage['text'], $tokenMatch) === 1, 'The one-time reminder has no fresh verification token.');
    $assert((new Store($config))->memberForToken($tokenMatch[1], 'email_verification')['id'] === $pending['id'], 'Reminder token did not belong to the actual pending account.');
    $assert(!str_contains(json_encode($private->read('notifications')), $tokenMatch[1]), 'Journal retained the raw authentication token.');
    $private->update('notifications', static function (array &$data) use ($pendingJob): void { $data['jobs'][$pendingJob['id']]['claim']['until'] = time() - 1; });
    $successor = $internal($service, 'claim', $owner)['job'];
    $successorMessage = $internal($service, 'render', $successor, $owner);
    preg_match('/token=([A-Za-z0-9_-]{48})/', $successorMessage['text'], $successorToken);
    $reject(fn() => $internal($service, 'render', $pendingJob, $owner), 'An expired predecessor rotated the successor verification link.', 409);
    $assert((new Store($config))->memberForToken($successorToken[1], 'email_verification')['id'] === $pending['id'], 'Stale worker invalidated the current reminder link.');
    $service->saveSettings(['enabled' => true, 'types' => NotificationService::TYPES], $service->overview()['revision'], $owner);
    (new Store($config))->markMemberVerified($pending['id']);
    $assert($internal($service, 'eligible', $pendingJob) === null, 'Already-verified account still received a registration reminder.');
    (new Store($config))->saveSubscription(array_replace($subscription, ['cancelAtPeriodEnd' => true]));
    $assert($internal($service, 'eligible', $byType['renewal_reminder']) === null, 'Canceled renewal still received a renewal reminder.');
    (new Store($config))->updateOrder($failed['id'], ['status' => 'paid']);
    $assert($internal($service, 'eligible', $byType['payment_failure']) === null, 'Recovered payment still received a failure notice.');
    $cancel = $byType['renewal'];
    $service->cancel($cancel['id'], NotificationService::jobRevision($cancel), $owner);
    $assert($private->read('notifications')['jobs'][$cancel['id']]['status'] === 'canceled' && $service->discover()['queued'] === 0, 'Cancellation was not durable through discovery.');
    $alert = $service->enqueueOwnerAlert('suspicious_admin_login', 'security:fixture-781', ['when' => gmdate('c'), 'url' => 'admin/?page=security']);
    $assert(count($alert) === 1 && $alert[0]['type'] === 'owner_security', 'Current owner security alert did not queue.');
    $assert(count($service->enqueueOwnerAlert('suspicious_admin_login', 'security:fixture-781', ['when' => gmdate('c'), 'url' => 'admin/?page=security'])) === 1, 'Idempotent owner enqueue failed.');
    $reject(fn() => $service->enqueueOwnerAlert('suspicious_admin_login', 'security:bad', ['url' => 'https://evil.test']), 'Owner alert accepted an external destination.');
    $claim = $internal($service, 'claim', $owner);
    $failure = $internal($service, 'finish', $claim['job']['id'], $claim['job']['claim']['token'], ['status' => 'failed', 'retryable' => true, 'lastError' => 'Fixture definite rejection']);
    $reject(fn() => $service->retry($failure['id'], NotificationService::jobRevision($failure), $owner), 'Retry cooldown was bypassed.', 409);
    $private->update('notifications', static function (array &$data) use ($failure): void { $data['jobs'][$failure['id']]['next_attempt_at'] = time() - 1; });
    $failure = $private->read('notifications')['jobs'][$failure['id']];
    $service->retry($failure['id'], NotificationService::jobRevision($failure), $owner);
    $assert($private->read('notifications')['jobs'][$failure['id']]['status'] === 'queued', 'Definitely failed retry was not queued after the cooldown.');
    $before = $private->read('notifications');
    $assert($service->run()['held'] !== null && $private->read('notifications') === $before, 'Disabled mail configuration claimed notification work.');
    $store->mutateFileStore(static function (array &$data) use ($owner): void { $data['admins'][$owner['id']]['role'] = 'editor'; });
    $assert($internal($service, 'eligible', $alert[0]) === null, 'Demoted owner retained security-notice eligibility.');
    $reject(fn() => $service->saveSettings(['enabled' => false, 'types' => []], $service->overview()['revision'], $owner), 'Demoted owner changed notification settings.', 403);
    $reject(fn() => $service->discover(1, $owner), 'Demoted owner ran discovery.', 403);
    $reject(fn() => $service->cancel($failure['id'], NotificationService::jobRevision($private->read('notifications')['jobs'][$failure['id']]), $owner), 'Demoted owner canceled a notice.', 403);
    $assert($private->read('notifications') === $before, 'Revoked-owner rejection changed durable work.');
    $original = $private->read('notifications');
    foreach ([['message_id', '<different@example.test>'], ['email_hash', 'bad'], ['attempts', 8], ['claim', ['token' => 'bad', 'until' => 1]], ['context', ['body' => 'unexpected']], ['type', 'arbitrary']] as [$field, $value]) {
        $private->update('notifications', static function (array &$data) use ($job, $field, $value): void { $data['jobs'][$job['id']][$field] = $value; });
        $reject(fn() => $service->overview(), 'Corrupt durable notification was accepted.');
        $private->update('notifications', static function (array &$data) use ($original): void { $data = $original; });
    }
    $private->update('independent_fixture', static function (array &$data): void { $data = ['unchanged' => 'sentinel-781']; });
    $assert($private->read('independent_fixture') === ['unchanged' => 'sentinel-781'] && json_decode(file_get_contents($root . '/storage/data.json'), true)['notification_sentinel'] === ['untouched' => 781], 'Notification work overwrote independent state.');
    echo 'Notification source acceptance passed (' . $assertions . " checks; no network or external mail).\n";
} finally { $remove($root); }
