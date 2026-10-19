<?php
declare(strict_types=1);

namespace App\Core;

final class EntitlementResolver
{
    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed>|null $member
     * @return array{allowed:bool,mode:string,reason:string,source:string,expiresAt:?string,loginRequired:bool,plan:?array,entitlement:?array}
     */
    public function resolve(string $contentType, array $content, ?array $member = null, bool $direct = false): array
    {
        $mode = MediaAccess::normalize((string) ($content['accessMode'] ?? 'public'));
        $contentId = (string) ($content['id'] ?? '');
        $base = [
            'allowed' => false, 'mode' => $mode, 'reason' => 'Access is required.', 'source' => 'none',
            'expiresAt' => null, 'loginRequired' => $member === null, 'plan' => null, 'entitlement' => null,
        ];
        $status = (string) ($content['status'] ?? 'published');
        $scheduledAt = strtotime((string) ($content['scheduledAt'] ?? '')) ?: 0;
        if ($status === 'scheduled' && $scheduledAt > time()) {
            return array_merge($base, ['reason' => 'This release is scheduled for later.']);
        }
        if (!in_array($status, ['published', 'unlisted'], true) && $status !== '') {
            return array_merge($base, ['reason' => 'This release is not available yet.']);
        }
        if ($mode === 'public') {
            return array_merge($base, ['allowed' => true, 'reason' => 'Public content.', 'source' => 'public', 'loginRequired' => false]);
        }
        if ($mode === 'unlisted' && $direct) {
            return array_merge($base, ['allowed' => true, 'reason' => 'Available through its private link.', 'source' => 'unlisted', 'loginRequired' => false]);
        }
        if ($member === null) {
            return array_merge($base, ['reason' => $mode === 'registered' ? 'Sign in with a free account to continue.' : 'Sign in to check your access.']);
        }
        if ((string) ($member['status'] ?? '') !== 'active' || empty($member['emailVerifiedAt'])) {
            return array_merge($base, ['reason' => 'Your account must be active and verified.', 'loginRequired' => false]);
        }
        $memberId = (string) $member['id'];
        $entitlement = $contentId !== '' ? $this->store->activeEntitlement($memberId, $contentType, $contentId) : null;
        if ($entitlement !== null) {
            return array_merge($base, [
                'allowed' => true,
                'reason' => $this->entitlementReason((string) ($entitlement['sourceType'] ?? 'access')),
                'source' => (string) ($entitlement['sourceType'] ?? 'entitlement'),
                'expiresAt' => $entitlement['expiresAt'] ?? null,
                'loginRequired' => false,
                'entitlement' => $entitlement,
            ]);
        }
        if ($mode === 'registered') {
            return array_merge($base, ['allowed' => true, 'reason' => 'Included with your free member account.', 'source' => 'registered', 'loginRequired' => false]);
        }
        if ($mode === 'subscription') {
            $subscriptions = $this->store->activeSubscriptions($memberId);
            if ($subscriptions === []) {
                return array_merge($base, ['reason' => 'An active membership is required.', 'loginRequired' => false]);
            }
            $requiredPlans = $contentId !== '' ? $this->store->contentPlanIds($contentType, $contentId) : [];
            foreach ($subscriptions as $subscription) {
                $planId = (string) ($subscription['planId'] ?? '');
                if ($requiredPlans === [] || in_array($planId, $requiredPlans, true)) {
                    $plan = $this->store->membershipPlan($planId);
                    return array_merge($base, [
                        'allowed' => true, 'reason' => 'Included with your ' . (string) ($plan['name'] ?? 'membership') . '.',
                        'source' => 'subscription', 'loginRequired' => false, 'plan' => $plan,
                        'expiresAt' => $subscription['currentPeriodEnd'] ?? null,
                    ]);
                }
            }
            return array_merge($base, ['reason' => 'This release is not included with your current membership tier.', 'loginRequired' => false]);
        }
        if ($mode === 'unlisted') {
            return array_merge($base, ['reason' => 'Use the private link supplied for this release.', 'loginRequired' => false]);
        }
        $reason = match ($mode) {
            'ppv' => 'Purchase this release to add it to My Library.',
            'rental' => 'An active rental is required.',
            'bundle' => 'This release is available through an included bundle.',
            'complimentary' => 'This release requires complimentary access from Nikki.',
            default => 'Access is required.',
        };
        return array_merge($base, ['reason' => $reason, 'loginRequired' => false]);
    }

    /** @return list<array<string,mixed>> */
    public function library(string|int $memberId): array
    {
        $member = $this->store->member($memberId);
        if (!$member) return [];
        $items = [];
        foreach ($this->store->entitlements($memberId, true) as $entitlement) {
            $content = $this->content((string) $entitlement['contentType'], (string) $entitlement['contentId']);
            if ($content === null) continue;
            $items[$this->key((string) $entitlement['contentType'], (string) $entitlement['contentId'])] = $this->libraryItem((string) $entitlement['contentType'], $content, [
                'source' => (string) ($entitlement['sourceType'] ?? 'entitlement'),
                'expiresAt' => $entitlement['expiresAt'] ?? null,
            ]);
        }
        foreach (['gallery' => $this->store->galleries(true), 'video' => $this->store->videos(true), 'track' => $this->store->tracks(true)] as $type => $collection) {
            foreach ($collection as $content) {
                if (MediaAccess::normalize((string) ($content['accessMode'] ?? 'public')) !== 'subscription') continue;
                $decision = $this->resolve($type, $content, $member, false);
                if (!$decision['allowed']) continue;
                $items[$this->key($type, (string) $content['id'])] = $this->libraryItem($type, $content, [
                    'source' => 'subscription', 'expiresAt' => $decision['expiresAt'], 'plan' => $decision['plan'],
                ]);
            }
        }
        $rows = array_values($items);
        usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['addedAt'] ?? $b['publishedAt'] ?? ''), (string) ($a['addedAt'] ?? $a['publishedAt'] ?? '')));
        return $rows;
    }

    public function content(string $type, string|int $id): ?array
    {
        return match ($type) {
            'gallery' => $this->store->gallery($id),
            'video' => $this->store->video($id),
            'track' => $this->store->track($id),
            default => null,
        };
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $access */
    private function libraryItem(string $type, array $content, array $access): array
    {
        $asset = match ($type) {
            'gallery' => (array) ($content['coverAsset'] ?? []),
            'video' => (array) ($content['posterAsset'] ?? []),
            'track' => (array) ($content['coverAsset'] ?? []),
            default => [],
        };
        $url = match ($type) {
            'gallery' => base_url('photos/view.php?slug=' . rawurlencode((string) ($content['slug'] ?? ''))),
            'video' => base_url('videos/watch.php?slug=' . rawurlencode((string) ($content['slug'] ?? ''))),
            'track' => base_url('music/#track-' . rawurlencode((string) ($content['id'] ?? ''))),
            default => base_url(),
        };
        return array_merge([
            'key' => $this->key($type, (string) ($content['id'] ?? '')), 'type' => $type, 'id' => (string) ($content['id'] ?? ''),
            'title' => (string) ($content['title'] ?? 'Untitled'), 'description' => (string) ($content['description'] ?? ''),
            'slug' => (string) ($content['slug'] ?? ''), 'thumbnail' => media_thumbnail_src($asset), 'url' => $url,
            'accessMode' => (string) ($content['accessMode'] ?? 'public'), 'publishedAt' => $content['publishedAt'] ?? null,
            'addedAt' => $access['addedAt'] ?? ($content['publishedAt'] ?? $content['createdAt'] ?? null),
        ], $access);
    }

    private function key(string $type, string $id): string
    {
        return $type . ':' . $id;
    }

    private function entitlementReason(string $source): string
    {
        return match ($source) {
            'purchase', 'ppv' => 'Purchased and available in My Library.',
            'rental' => 'Your rental is active.',
            'bundle' => 'Included with a purchased bundle.',
            'gift' => 'Gift access is active.',
            'complimentary' => 'Complimentary access is active.',
            default => 'Your account has access.',
        };
    }
}
