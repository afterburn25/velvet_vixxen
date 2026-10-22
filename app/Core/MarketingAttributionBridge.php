<?php
declare(strict_types=1);

namespace App\Core;

use Velvet\Core\MarketingPrivacy;
use Velvet\Core\MarketingStore;

require_once __DIR__ . '/MarketingStore.php';

/** Keeps trusted origin snapshots beside their source records; reports are a rebuildable projection. */
final class MarketingAttributionBridge
{
    public static function registration(Config $config): ?array
    {
        $root = dirname($config->path(), 2);
        if (MarketingPrivacy::choice($root) !== true) return null;
        return (new MarketingStore($root))->requestAttribution();
    }

    public static function order(Config $config, array $member, string $coupon): ?array
    {
        $root = dirname($config->path(), 2);
        if (MarketingPrivacy::choice($root) !== true) return null;
        return (new MarketingStore($root))->orderAttribution($member, $coupon);
    }

    public static function project(Config $config, Store $store, string $type, string $id): bool
    {
        try {
            $marketing = new MarketingStore(dirname($config->path(), 2));
            return $type === 'registration' ? $marketing->recordRegistrationAttribution($store, $id) : $marketing->recordOrderAttribution($store, $id);
        } catch (\Throwable) {
            // The registration/order has already committed. Its canonical snapshot is retained
            // and the bounded reporting runner retries it; never repeat account creation or a charge.
            try { $store->audit(null, 'marketing.attribution.projection_deferred', ['type' => $type, 'recordId' => $id]); }
            catch (\Throwable) { /* A reporting outage cannot change an already committed business result. */ }
            return false;
        }
    }
}
