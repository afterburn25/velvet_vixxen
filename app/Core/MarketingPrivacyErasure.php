<?php
declare(strict_types=1);

namespace Velvet\Core;

use RuntimeException;

/** Independent irreversible hashes stop delayed newsletter/provider writes rebuilding erased contact rows. */
final class MarketingPrivacyErasure
{
    public static function emailHash(string $email): string { return hash('sha256', strtolower(trim($email))); }

    public static function suppressed(array $data, string $email): bool
    {
        return isset($data['settings']['member_privacy_erased_emails'][self::emailHash($email)]);
    }

    public static function enforce(array &$data, array $before): void
    {
        foreach (['member_privacy_erased_emails','member_privacy_erased_members'] as $key) {
            $old = $before[$key] ?? []; $saved = $data['settings'][$key] ?? [];
            if (!is_array($old) || !is_array($saved)) throw new RuntimeException('Privacy suppression markers need review.');
            $markers = array_replace($saved, $old);
            foreach ($markers as $hash => $at) if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || !is_int($at) || $at < 1) throw new RuntimeException('Privacy suppression markers need review.');
            if (count($markers) > 100000) throw new RuntimeException('Privacy suppression storage needs operator review.');
            if ($markers !== []) $data['settings'][$key] = $markers;
        }
        if (empty($data['settings']['member_privacy_erased_emails'])) return;
        foreach (['contacts','consents','campaign_queue','suppressions'] as $collection) {
            $data[$collection] = array_values(array_filter($data[$collection] ?? [], static fn(array $row): bool => !self::suppressed($data, (string) ($row['email'] ?? ''))));
        }
    }
}
