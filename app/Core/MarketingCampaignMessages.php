<?php
declare(strict_types=1);

namespace Velvet\Core;

require_once __DIR__ . '/CampaignMessage.php';

/** Revision-checked owner editing and the separate, one-use campaign test journal. */
trait MarketingCampaignMessages
{
    public function emailBrand(): array
    {
        $template = CampaignMessage::template($this->all()['settings']['email_brand'] ?? null);
        return ['template' => $template, 'revision' => CampaignMessage::revision($template)];
    }

    public function saveEmailBrand(array $input, string $expectedRevision, array $owner): array
    {
        $template = CampaignMessage::template($input);
        return $this->update(function (array &$data) use ($template, $expectedRevision, $owner): array {
            $this->requireCampaignOwner($owner);
            $current = CampaignMessage::template($data['settings']['email_brand'] ?? null);
            if (!hash_equals(CampaignMessage::revision($current), $expectedRevision)) {
                throw new \RuntimeException('The email brand changed. Reload before saving.', 409);
            }
            $data['settings']['email_brand'] = $template;
            return ['template' => $template, 'revision' => CampaignMessage::revision($template)];
        });
    }

    public function saveCampaignMessage(array $input, array $owner): array
    {
        $fields = CampaignMessage::fields($input);
        $id = MarketingAudience::id($input['id'] ?? '', true);
        $selection = MarketingAudience::selection($input['audience'] ?? ['mode' => 'all']);
        $schedule = $input['scheduled_at'] ?? '';
        if (!is_string($schedule) || ($schedule !== '' && (strlen($schedule) > 40 || strtotime($schedule) === false))) {
            throw new \InvalidArgumentException('Enter a valid campaign schedule.');
        }
        $expected = $input['expected_revision'] ?? '';
        if (!is_string($expected)) throw new \InvalidArgumentException('Reload the campaign before saving.');
        return $this->update(function (array &$data) use ($fields, $id, $selection, $schedule, $expected, $owner): array {
            $this->requireCampaignOwner($owner);
            $this->resolveAudience($data, $selection);
            $index = $id === '' ? false : array_search($id, array_column($data['campaigns'], 'id'), true);
            if ($id !== '' && $index === false) throw new \RuntimeException('The campaign was removed. Reload before saving.', 409);
            if ($index !== false) {
                $old = $data['campaigns'][$index];
                if ($expected === '' || !hash_equals(CampaignMessage::revision($old), $expected)) {
                    throw new \RuntimeException('The campaign changed. Reload before saving; your attempted text is retained below.', 409);
                }
                if (isset($old['delivery_snapshot']) || isset($old['audience_snapshot'])) {
                    throw new \RuntimeException('Queued campaign text is frozen. Create a new campaign for a different message.', 409);
                }
            } else {
                if ($expected !== '') throw new \RuntimeException('Start a new campaign from the current page.', 409);
                $old = ['id' => $this->id('campaign'), 'created_at' => gmdate('c')];
            }
            $record = array_replace($old, $fields, ['audience' => $selection, 'scheduled_at' => $schedule,
                'status' => 'draft', 'updated_at' => gmdate('c')]);
            if ($index === false) $data['campaigns'][] = $record;
            else $data['campaigns'][$index] = $record;
            return $record;
        });
    }

    public function campaignMessage(string $id): ?array
    {
        MarketingAudience::id($id);
        return self::campaignMessageFromSnapshot($this->all(), $id);
    }

    /** No initialization, migration, events, add-ons, queue changes or mail during preview. */
    public static function previewCampaignMessage(string $id, ?string $root = null): ?array
    {
        MarketingAudience::id($id);
        $data = (new MarketingStorage($root))->peek();
        return $data === null ? null : self::campaignMessageFromSnapshot($data, $id);
    }

    private static function campaignMessageFromSnapshot(array $data, string $id): ?array
    {
        foreach ($data['campaigns'] as $record) {
            if (($record['id'] ?? '') !== $id) continue;
            $snapshot = $record['delivery_snapshot'] ?? CampaignMessage::snapshot($record,
                CampaignMessage::template($data['settings']['email_brand'] ?? null));
            if (!is_array($snapshot)) throw new \RuntimeException('The saved campaign message needs review.');
            $template = CampaignMessage::template($snapshot['template'] ?? null);
            return ['record' => $record, 'snapshot' => $snapshot, 'revision' => CampaignMessage::revision($record),
                'template_revision' => CampaignMessage::revision($template), 'queued' => isset($record['delivery_snapshot'])];
        }
        return null;
    }

    public function claimCampaignTest(string $id, string $revision, string $templateRevision, string $requestId, array $owner): array
    {
        MarketingAudience::id($id);
        if (!MarketingAudience::validRevision($requestId)) throw new \InvalidArgumentException('Open a fresh message preview before sending a test.');
        return $this->update(function (array &$data) use ($id, $revision, $templateRevision, $requestId, $owner): array {
            $currentOwner = $this->requireCampaignOwner($owner);
            $view = self::campaignMessageFromSnapshot($data, $id);
            if ($view === null || !hash_equals($view['revision'], $revision) || !hash_equals($view['template_revision'], $templateRevision)) {
                throw new \RuntimeException('The campaign or email brand changed. Review the saved message again before sending a test.', 409);
            }
            $journal = $data['settings']['campaign_test_journal'] ?? [];
            if (!is_array($journal)) throw new \RuntimeException('The test-send journal needs review.');
            $now = time();
            foreach ($journal as $key => &$entry) {
                if (!is_array($entry)) throw new \RuntimeException('The test-send journal needs review.');
                if (in_array($entry['status'] ?? '', ['claimed', 'sending'], true) && (int) ($entry['until'] ?? 0) < $now) {
                    $entry['status'] = $entry['status'] === 'sending' ? 'unknown' : 'failed';
                    $entry['last_error'] = 'An interrupted test was held. It will never be retried automatically.';
                    unset($entry['token']);
                }
                if ((int) ($entry['created_epoch'] ?? 0) < $now - 172800) unset($journal[$key]);
            }
            unset($entry);
            if (isset($journal[$requestId])) throw new \RuntimeException('This test request was already used. Open a fresh preview for another test.', 409);
            foreach ($journal as $entry) {
                if (($entry['owner_id'] ?? '') !== (string) $currentOwner['id']) continue;
                if ((int) ($entry['created_epoch'] ?? 0) > $now - 60 || in_array($entry['status'] ?? '', ['claimed', 'sending'], true)) {
                    throw new \RuntimeException('Wait one minute between test emails and allow the current test to finish.', 429);
                }
            }
            if (count($journal) >= 3000) throw new \RuntimeException('The test-send journal is full. Wait for older requests to expire.');
            $claim = ['id' => $requestId, 'campaign_id' => $id, 'owner_id' => (string) $currentOwner['id'],
                'status' => 'claimed', 'token' => bin2hex(random_bytes(24)), 'created_epoch' => $now, 'until' => $now + 180,
                'message_id' => CampaignMessage::messageId('campaign-test', $requestId), 'last_error' => null];
            $journal[$requestId] = $claim;
            $data['settings']['campaign_test_journal'] = $journal;
            return $claim + ['snapshot' => $view['snapshot'], 'recipient' => (string) $currentOwner['email']];
        });
    }

    public function authorizeCampaignTest(string $requestId, string $token, string $revision, string $templateRevision, array $owner): bool
    {
        return $this->update(function (array &$data) use ($requestId, $token, $revision, $templateRevision, $owner): bool {
            if (!isset($data['settings']['campaign_test_journal'][$requestId]) || !is_array($data['settings']['campaign_test_journal'][$requestId])) return false;
            $entry = &$data['settings']['campaign_test_journal'][$requestId];
            if (!is_array($entry) || ($entry['status'] ?? '') !== 'claimed' || !hash_equals((string) ($entry['token'] ?? ''), $token)) return false;
            try {
                $this->requireCampaignOwner($owner);
                $view = self::campaignMessageFromSnapshot($data, $entry['campaign_id']);
                if ($view === null || !hash_equals($view['revision'], $revision) || !hash_equals($view['template_revision'], $templateRevision)
                    || (int) $entry['until'] < time()) throw new \RuntimeException('The saved message or authorization changed.');
            } catch (\Throwable) {
                $entry['status'] = 'skipped'; $entry['last_error'] = 'The saved message or owner authorization changed before transmission.';
                unset($entry['token']); return false;
            }
            $entry['status'] = 'sending';
            return true;
        });
    }

    public function finishCampaignTest(string $requestId, string $token, array $outcome): array
    {
        return $this->update(static function (array &$data) use ($requestId, $token, $outcome): array {
            if (!isset($data['settings']['campaign_test_journal'][$requestId]) || !is_array($data['settings']['campaign_test_journal'][$requestId])) {
                throw new \RuntimeException('The test-send claim is unavailable. Check your provider before repeating it.');
            }
            $entry = &$data['settings']['campaign_test_journal'][$requestId];
            if (!is_array($entry)) throw new \RuntimeException('The test-send claim is unavailable. Check your provider before repeating it.');
            if (!in_array($entry['status'] ?? '', ['claimed', 'sending'], true) || !hash_equals((string) ($entry['token'] ?? ''), $token)) return $entry;
            $status = $outcome['status'] ?? 'unknown';
            if (!in_array($status, ['sent', 'failed', 'unknown', 'skipped'], true)) $status = 'unknown';
            if ($status === 'unknown' && $entry['status'] === 'claimed') $status = 'failed';
            $entry['status'] = $status; $entry['last_error'] = $outcome['lastError'] ?? null;
            unset($entry['token']);
            return $entry;
        });
    }

    private function requireCampaignOwner(array $identity): array
    {
        $email = $identity['email'] ?? null; $id = $identity['id'] ?? null;
        if (!is_string($email) || !is_scalar($id) || (string) $id === '') throw new \RuntimeException('Current owner authentication is required.', 403);
        $owner = (new \App\Core\Store(new \App\Core\Config($this->root . '/config/app.php')))->findAdminByEmail($email);
        if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner' || (string) $owner['id'] !== (string) $id
            || strcasecmp((string) $owner['email'], $email) !== 0) throw new \RuntimeException('Current owner authentication is required.', 403);
        return $owner;
    }
}
