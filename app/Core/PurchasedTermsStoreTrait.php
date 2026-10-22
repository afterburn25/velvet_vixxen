<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Durable reverse reference through the original order item; no historical backfill or new table. */
trait PurchasedTermsStoreTrait
{
    public function subscriptionPurchasedTerms(array $subscription): ?array
    {
        $id = (string) ($subscription['id'] ?? '');
        if ($id === '') return null;
        $matches = [];
        if ($this->mode === 'file') {
            foreach ($this->readFileStore()['orderItems'] ?? [] as $orderId => $items) {
                foreach ($items as $item) if (($item['itemType'] ?? '') === 'plan' && (string) ($item['metadata']['subscriptionId'] ?? '') === $id) {
                    $matches[] = [$this->order((string) $orderId), $item];
                }
            }
        } else {
            $statement = $this->pdo()->prepare("SELECT i.* FROM order_items i WHERE i.item_type='plan' AND JSON_UNQUOTE(JSON_EXTRACT(i.metadata_json,'$.subscriptionId'))=?");
            $statement->execute([$id]);
            foreach ($statement->fetchAll() as $row) $matches[] = [$this->order((string) $row['order_id']), $this->mapOrderItemRow($row)];
        }
        if ($matches === []) return null;
        if (count($matches) !== 1) throw new RuntimeException('The purchased subscription binding needs review.');
        [$order, $item] = $matches[0];
        if (!$order || (string) $order['memberId'] !== (string) $subscription['memberId'] || (string) $item['itemId'] !== (string) $subscription['planId']) {
            throw new RuntimeException('The purchased subscription binding needs review.');
        }
        // Privacy closure intentionally removes commercial metadata. It must never be rebuilt.
        if ($this->privacyMemberErased((string) $subscription['memberId'])) return null;
        return PurchasedTerms::forItem($order, $item);
    }

    private function projectPurchasedSubscription(array $subscription): array
    {
        unset($subscription['purchasedTerms']);
        try {
            $snapshot = $this->subscriptionPurchasedTerms($subscription);
            $subscription['purchasedTermsState'] = $snapshot === null ? 'legacy' : 'accepted';
            if ($snapshot !== null) {
                $terms = $snapshot['terms'];
                $subscription['purchasedTerms'] = $snapshot;
                $subscription['planName'] = $terms['title']; $subscription['planKey'] = $terms['planKey'];
                $subscription['benefits'] = $terms['benefits']; $subscription['ppvDiscountPercent'] = $terms['ppvDiscountBasisPoints'] / 100;
            }
        } catch (RuntimeException) {
            $subscription['purchasedTermsState'] = 'held';
            $subscription['benefits'] = []; $subscription['ppvDiscountPercent'] = 0;
        }
        return $subscription;
    }
}
