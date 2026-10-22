<?php
declare(strict_types=1);

namespace App\Core;

use Velvet\Core\CampaignMessage;
use Velvet\Core\MarketingStore;

/** Explicit owner-only test delivery. It never changes subscriber campaign queues. */
final class CampaignTestService
{
    public function __construct(private readonly Config $config, private readonly MarketingStore $marketing, private readonly MailService $mail) {}

    public static function exampleUnsubscribe(string $base): string
    {
        return CampaignMessage::baseUrl($base) . '/' . \vvx_public_route_relative_url('newsletter/unsubscribe.php?token=campaign-preview-not-a-subscriber');
    }

    public function send(string $id, string $revision, string $templateRevision, string $requestId, array $owner): array
    {
        $this->config->reload();
        if (($problem = $this->mail->configurationProblem()) !== null) throw new \RuntimeException($problem);
        $base = CampaignMessage::baseUrl((string) $this->config->get('base_url', ''));
        $configuration = hash('sha256', serialize([$this->config->get('mail'), $base]));
        $claim = $this->marketing->claimCampaignTest($id, $revision, $templateRevision, $requestId, $owner);
        try {
            $rendered = CampaignMessage::render($claim['snapshot'], self::exampleUnsubscribe($base), $base);
            $outcome = $this->mail->deliver($claim['recipient'], '[Test] ' . $rendered['subject'], $rendered['text'],
                function () use ($requestId, $claim, $revision, $templateRevision, $owner, $configuration): bool {
                    $this->config->reload();
                    if (!hash_equals($configuration, hash('sha256', serialize([$this->config->get('mail'),
                        CampaignMessage::baseUrl((string) $this->config->get('base_url', ''))])))) return false;
                    return $this->marketing->authorizeCampaignTest($requestId, $claim['token'], $revision, $templateRevision, $owner);
                }, ['html' => $rendered['html'], 'message_id' => $claim['message_id']]);
        } catch (\Throwable) {
            $outcome = ['status' => 'unknown', 'lastError' => 'The saved message or transmission could not be completed. Check the recorded test state before another test.'];
        }
        try { return $this->marketing->finishCampaignTest($requestId, $claim['token'], $outcome); }
        catch (\Throwable) { return ['status' => 'unknown', 'last_error' => 'The final test result could not be saved. Check the mail provider before a separate test.']; }
    }
}
