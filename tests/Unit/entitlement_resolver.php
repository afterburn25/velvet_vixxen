<?php
declare(strict_types=1);

require dirname(__DIR__) . '/TestEnvironment.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$env = velvet_test_environment('entitlements');
try {
    $store = $env['store'];
    $resolver = $env['resolver'];
    $member = $store->createMember([
        'displayName' => 'Entitled Member', 'email' => 'entitled@example.test',
        'password' => 'VelvetPass12!', 'status' => 'active', 'emailVerifiedAt' => now_iso(),
    ]);

    $public = $store->gallery('demo-gallery');
    $registered = $store->gallery('demo-gallery-vixxen');
    $subscription = $store->gallery('demo-gallery-mirror');
    $ppv = $store->gallery('demo-gallery-lounge');
    $rental = $store->gallery('demo-gallery-after-dark');
    $video = $store->video('demo-video');
    $track = $store->track('demo-track-3');

    $assert(!empty($resolver->resolve('gallery', $public ?? [], null)['allowed']), 'Public gallery was denied.');
    $assert(empty($resolver->resolve('gallery', $registered ?? [], null)['allowed']), 'Registered gallery was open to anonymous visitors.');
    $assert(!empty($resolver->resolve('gallery', $registered ?? [], $member)['allowed']), 'Registered gallery was denied to a verified member.');
    $assert(empty($resolver->resolve('gallery', $subscription ?? [], $member)['allowed']), 'Subscription gallery was open without a subscription.');
    $assert(empty($resolver->resolve('gallery', $ppv ?? [], $member)['allowed']), 'PPV gallery was open without purchase entitlement.');
    $assert(empty($resolver->resolve('gallery', $rental ?? [], $member)['allowed']), 'Rental gallery was open without an active rental.');

    $subscriptionRecord = $store->saveSubscription([
        'memberId' => $member['id'], 'planId' => 'plan-vip', 'status' => 'active',
        'currentPeriodStart' => now_iso(), 'currentPeriodEnd' => gmdate('c', time() + 30 * 86400),
    ]);
    $subscriptionDecision = $resolver->resolve('gallery', $subscription ?? [], $member);
    $assert(!empty($subscriptionDecision['allowed']) && ($subscriptionDecision['source'] ?? '') === 'subscription', 'Matching subscription did not unlock its gallery.');
    $assert(!empty($resolver->resolve('video', $video ?? [], $member)['allowed']), 'Matching subscription did not unlock its video.');
    $assert(!empty($resolver->resolve('track', $track ?? [], $member)['allowed']), 'Matching subscription did not unlock its track.');

    $purchase = $store->grantEntitlement((string) $member['id'], 'gallery', 'demo-gallery-lounge', 'purchase');
    $ppvDecision = $resolver->resolve('gallery', $ppv ?? [], $member);
    $assert(!empty($ppvDecision['allowed']) && ($ppvDecision['source'] ?? '') === 'purchase', 'Purchase entitlement did not unlock PPV gallery.');

    $rentalGrant = $store->grantEntitlement((string) $member['id'], 'gallery', 'demo-gallery-after-dark', 'rental', null, gmdate('c', time() + 3600));
    $rentalDecision = $resolver->resolve('gallery', $rental ?? [], $member);
    $assert(!empty($rentalDecision['allowed']) && ($rentalDecision['source'] ?? '') === 'rental', 'Active rental did not unlock gallery.');
    $store->revokeEntitlement((string) $member['id'], (string) $rentalGrant['id']);
    $assert(empty($resolver->resolve('gallery', $rental ?? [], $member)['allowed']), 'Revoked rental still unlocked gallery.');

    $unlisted = ['id' => 'private-release', 'title' => 'Private Release', 'status' => 'unlisted', 'accessMode' => 'unlisted'];
    $assert(empty($resolver->resolve('gallery', $unlisted, $member, false)['allowed']), 'Unlisted release opened without the direct-link context.');
    $assert(!empty($resolver->resolve('gallery', $unlisted, null, true)['allowed']), 'Direct unlisted link was denied.');

    $library = $resolver->library((string) $member['id']);
    $keys = array_column($library, 'key');
    $assert(in_array('gallery:demo-gallery-lounge', $keys, true), 'Purchased gallery was missing from My Library.');
    $assert(in_array('gallery:demo-gallery-mirror', $keys, true), 'Subscription gallery was missing from My Library.');
    $assert(in_array('video:demo-video', $keys, true), 'Subscription video was missing from My Library.');

    $state = $store->saveMemberMediaState((string) $member['id'], 'video', 'demo-video', [
        'progressSeconds' => 47.5, 'isFavorite' => true, 'viewed' => true,
        'metadata' => ['duration' => 120],
    ]);
    $assert(abs((float) ($state['progressSeconds'] ?? 0) - 47.5) < 0.01, 'Playback progress was not saved.');
    $assert(!empty($state['isFavorite']) && (int) ($state['viewCount'] ?? 0) === 1, 'Favorite/view state was not saved.');

    $store->revokeEntitlement((string) $member['id'], (string) $purchase['id']);
    $cancelled = array_merge($subscriptionRecord, ['status' => 'cancelled', 'cancelledAt' => now_iso()]);
    $store->saveSubscription($cancelled);
    $assert(empty($resolver->resolve('gallery', $subscription ?? [], $member)['allowed']), 'Cancelled subscription still unlocked content.');
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    velvet_test_cleanup($env);
}
velvet_finish_test('Entitlement resolver test', $failures);
