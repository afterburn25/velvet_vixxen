<?php
declare(strict_types=1);

namespace App\Core;

use Velvet\Core\MarketingAttribution;
use Velvet\Core\MarketingPrivacyErasure;
use Velvet\Core\MarketingStorage;
use Velvet\Core\MarketingStore;

require_once __DIR__ . '/MarketingStore.php';

/** Cross-system operations run separately from the privacy journal transaction. */
final class MemberPrivacyDomains
{
    private readonly string $root;
    public function __construct(private readonly Config $config, private readonly Store $store, private readonly PrivateState $state)
    {
        $this->root = dirname($config->path(), 2);
    }

    public function snapshot(array $member, ?string $emailHash = null): array
    {
        $id = (string) $member['id']; $email = EmailAddress::normalize((string) $member['email']);
        $emailHash ??= hash('sha256', $email);
        $marketing = (new MarketingStorage($this->root))->peek() ?? [];
        $data = [];
        foreach (['contacts'=>'contacts','consents'=>'consents','deliveries'=>'campaign_queue','suppressions'=>'suppressions'] as $key => $collection) {
            $data[$key] = array_values(array_filter($marketing[$collection] ?? [], static fn(array $row): bool => hash('sha256', EmailAddress::normalize((string) ($row['email'] ?? ''))) === $emailHash));
        }
        $data['conversions'] = array_values(array_filter(MarketingAttribution::state($marketing)['conversions'], static fn(array $row): bool => ($row['member_id'] ?? '') === $id));
        $data['jobs'] = (new NotificationService($this->config, $this->store, null, $this->state))->privacyRows($id);
        $data['providerEvents'] = (new MailProviderService($this->config, $this->store, $this->state))->privacyRows($emailHash);
        $data['cases'] = (new ProtectionRecords($this->config, $this->state))->privacyRows($id);
        return $data;
    }

    public function erase(string $stage, string $memberId, string $emailHash, callable $authorize): void
    {
        $authorize();
        switch ($stage) {
            case 'attribution':
                (new MarketingStore($this->root))->forgetMemberAttribution($this->store, $memberId, $authorize);
                break;
            case 'newsletter':
                (new MarketingStorage($this->root))->update(static function (array &$data) use ($emailHash, $authorize): void {
                    $authorize(); $data['settings']['member_privacy_erased_emails'][$emailHash] ??= time();
                    // The storage-level fence performs exact hash matching for every
                    // contact, consent, delivery and plaintext suppression write.
                    MarketingPrivacyErasure::enforce($data, (array) $data['settings']);
                    $authorize();
                });
                break;
            case 'notifications':
                (new NotificationService($this->config, $this->store, null, $this->state))->privacyForget($memberId, $authorize);
                break;
            case 'provider':
                (new MailProviderService($this->config, $this->store, $this->state))->privacyForget($emailHash, $authorize);
                break;
            case 'protection':
                (new ProtectionRecords($this->config, $this->state))->privacyForget($memberId, $authorize);
                break;
            default: throw new \InvalidArgumentException('Choose a privacy processing stage.');
        }
        $authorize();
    }
}
