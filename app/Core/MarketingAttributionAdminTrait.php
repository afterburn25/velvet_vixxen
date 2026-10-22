<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Store;
use InvalidArgumentException;
use RuntimeException;

trait MarketingAttributionAdminTrait
{
    public function referralDefinitions(): array
    {
        $records = [];
        foreach ($this->all()['referral_codes'] as $record) {
            if (!is_array($record)) continue;
            try {
                $records[] = ['id' => MarketingAudience::id($record['id'] ?? null), 'code' => MarketingAttribution::code($record['code'] ?? null),
                    'label' => MarketingAudience::text($record['label'] ?? '', 120, 'Referral label'), 'enabled' => ($record['enabled'] ?? false) === true,
                    'revision' => hash('sha256', MarketingSqlRepository::encode($record))];
            } catch (InvalidArgumentException) { /* Invalid definitions never become public attribution. */ }
        }
        return $records;
    }

    public function saveReferralDefinition(array $input, callable $authorize): array
    {
        if (array_diff(array_keys($input), ['id','code','label','enabled','revision']) !== [] || !is_bool($input['enabled'] ?? null)) throw new InvalidArgumentException('Enter valid referral fields.');
        $id = MarketingAudience::id($input['id'] ?? '', true); $code = MarketingAttribution::code($input['code'] ?? null);
        $label = MarketingAudience::text($input['label'] ?? '', 120, 'Referral label');
        return $this->update(function (array &$data) use ($input, $id, $code, $label, $authorize): array {
            $authorize(); $index = false;
            foreach ($data['referral_codes'] as $key => $record) {
                if (!is_array($record)) throw new RuntimeException('A saved referral definition needs review.');
                if (($record['id'] ?? '') === $id && $id !== '') {
                    if ($index !== false) throw new RuntimeException('Duplicate referral identities need review.');
                    $index = $key;
                } elseif (is_string($record['code'] ?? null) && strtolower($record['code']) === $code) throw new RuntimeException('This referral code already exists. Edit its saved definition.');
            }
            if ($id !== '' && $index === false) throw new RuntimeException('This referral definition no longer exists.');
            $before = $index === false ? [] : $data['referral_codes'][$index];
            if ($index !== false && (!MarketingAudience::validRevision($input['revision'] ?? null)
                || !hash_equals(hash('sha256', MarketingSqlRepository::encode($before)), $input['revision']))) throw new RuntimeException('This referral definition changed. Reload before saving.');
            if ($index === false && count($data['referral_codes']) >= 1000) throw new RuntimeException('Keep at most 1,000 referral definitions.');
            // Origin codes are immutable after creation; disable a code and create
            // another one to keep historic registrations/orders interpretable.
            if ($index !== false && strtolower((string) ($before['code'] ?? '')) !== $code) throw new RuntimeException('A saved referral code cannot be renamed. Disable it and create a new code.');
            $record = array_replace($before, ['id' => $id !== '' ? $id : $this->id('referral'), 'code' => $code, 'label' => $label,
                'enabled' => $input['enabled'], 'created_at' => $before['created_at'] ?? gmdate('c'), 'updated_at' => gmdate('c')]);
            if ($index === false) $data['referral_codes'][] = $record; else $data['referral_codes'][$index] = $record;
            $authorize(); return $record;
        });
    }

    public function couponAttribution(): array
    {
        $state = MarketingAttribution::state($this->all());
        return ['assignments' => $state['coupons'], 'revision' => hash('sha256', MarketingSqlRepository::encode($state['coupons']))];
    }

    public function saveCouponAttribution(Store $store, string $code, string $campaignId, bool $enabled, string $revision, callable $authorize): void
    {
        $code = strtoupper($code);
        if (!preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,63}\z/D', $code)) throw new InvalidArgumentException('Choose an existing coupon.');
        MarketingAudience::id($campaignId);
        $this->update(function (array &$data) use ($store, $code, $campaignId, $enabled, $revision, $authorize): void {
            $authorize(); $state = MarketingAttribution::state($data);
            if (!MarketingAudience::validRevision($revision) || !hash_equals(hash('sha256', MarketingSqlRepository::encode($state['coupons'])), $revision)) throw new RuntimeException('Coupon attribution changed. Reload before saving.');
            $found = 0;
            foreach ($store->coupons(false) as $coupon) if (strtoupper((string) ($coupon['code'] ?? '')) === $code) $found++;
            if ($found !== 1 || !in_array($campaignId, array_column($data['campaigns'], 'id'), true)) throw new InvalidArgumentException('Choose one saved coupon and campaign.');
            if (!isset($state['coupons'][$code]) && count($state['coupons']) >= 1000) throw new RuntimeException('Keep at most 1,000 coupon assignments.');
            $state['coupons'][$code] = ['campaign_id' => $campaignId, 'enabled' => $enabled];
            $authorize(); $data[MarketingAttribution::KEY] = $state;
        });
    }

    public function linkBioPresentation(): array
    {
        $settings = $this->all()['settings'];
        $id = MarketingMetadata::mediaId($settings['links_featured_asset_id'] ?? '');
        $alt = MarketingAudience::text($settings['links_featured_alt'] ?? '', 240, 'Featured image description');
        return ['featured_asset_id' => $id, 'featured_alt' => $alt,
            'revision' => hash('sha256', MarketingSqlRepository::encode([$id, $alt]))];
    }

    public function saveLinkBioPresentation(array $input, string $revision, callable $authorize): void
    {
        if (array_diff(array_keys($input), ['featured_asset_id','featured_alt']) !== []) throw new InvalidArgumentException('Choose a featured image and description.');
        $id = MarketingMetadata::mediaId($input['featured_asset_id'] ?? '');
        $alt = MarketingAudience::text($input['featured_alt'] ?? '', 240, 'Featured image description');
        if ($id !== '' && $alt === '') throw new InvalidArgumentException('Describe the featured image for visitors using assistive technology.');
        $this->update(function (array &$data) use ($id, $alt, $revision, $authorize): void {
            $authorize();
            $current = [$data['settings']['links_featured_asset_id'] ?? '', $data['settings']['links_featured_alt'] ?? ''];
            if (!MarketingAudience::validRevision($revision) || !hash_equals(hash('sha256', MarketingSqlRepository::encode($current)), $revision)) throw new RuntimeException('The links image changed. Reload before saving.');
            if ($id !== '' && MarketingMetadata::resolveImage($id, $this->root) === null) throw new InvalidArgumentException('Choose an existing public image. Private, missing or unsafe assets cannot be featured.');
            $authorize(); $data['settings']['links_featured_asset_id'] = $id; $data['settings']['links_featured_alt'] = $alt;
        });
    }
}
