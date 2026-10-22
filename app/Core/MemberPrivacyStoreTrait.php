<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Complete private projections and identity-fenced mutations; never uses screen list limits. */
trait MemberPrivacyStoreTrait
{
    private int $privacyWriteDepth = 0;

    public function privacyMemberErased(string|int $id): bool
    {
        $member = $this->member($id);
        return is_array($member) && is_array($member['preferences']['_privacy_erasure'] ?? null);
    }

    public function privacyRequireOpenMember(string|int $id): void
    {
        $member = $this->member($id);
        if (!$member || !empty($member['deletedAt']) || is_array($member['preferences']['_privacy_erasure'] ?? null)) {
            throw new RuntimeException('This account is closed for privacy processing.', 409);
        }
    }

    /** SQL writers lock the same member row as freeze; file writers also have digest compare-and-swap. */
    private function privacyMemberWrite(string $id, callable $operation, bool $financial = false): mixed
    {
        if ($id === '') return $operation();
        if ($this->paymentAtomicActive && $this->paymentMemberId !== $id) throw new RuntimeException('Payment commit cannot cross the member identity fence.');
        if ($this->mode === 'file') {
            if (!$financial) $this->privacyRequireOpenMember($id);
            ++$this->privacyWriteDepth;
            try { return $operation(); } finally { --$this->privacyWriteDepth; }
        }
        $pdo = $this->pdo(); $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        ++$this->privacyWriteDepth;
        try {
            $statement = $pdo->prepare('SELECT id,preferences_json,deleted_at FROM members WHERE id=? FOR UPDATE');
            $statement->execute([$id]); $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || (!$financial && (!empty($row['deleted_at']) || isset(json_decode((string) ($row['preferences_json'] ?? '{}'), true)['_privacy_erasure'])))) {
                throw new RuntimeException('This account is closed for privacy processing.', 409);
            }
            $result = $operation();
            if ($owns) $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally { --$this->privacyWriteDepth; }
    }

    /** Internal data only. Call MemberPrivacyProjection before exposing any of these rows. */
    public function privacyCoreData(string $memberId): array
    {
        if ($this->mode === 'file') return $this->privacyFileData($this->loadFileStore()[0], $memberId);
        $pdo = $this->pdo(); $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $select = static function (string $sql, array $args) use ($pdo): array {
                $statement = $pdo->prepare($sql); $statement->execute($args);
                return array_map(self::privacySqlRow(...), $statement->fetchAll(PDO::FETCH_ASSOC));
            };
            $members = $select('SELECT * FROM members WHERE id=?', [$memberId]);
            if (count($members) !== 1) throw new RuntimeException('Member account not found.', 404);
            $member = $members[0];
            $data = ['member' => $member];
            foreach (['subscriptions' => 'subscriptions', 'entitlements' => 'content_entitlements', 'orders' => 'orders',
                'couponRedemptions' => 'coupon_redemptions', 'mediaState' => 'member_media_state', 'sessions' => 'member_sessions',
                'tokens' => 'member_auth_tokens', 'events' => 'member_account_events', 'mail' => 'mail_outbox',
                'threads' => 'message_threads', 'contentRequests' => 'content_requests', 'bookingRequests' => 'booking_requests',
                'notices' => 'member_notifications'] as $key => $table) {
                $data[$key] = $select('SELECT * FROM ' . $table . ' WHERE member_id=?', [$memberId]);
            }
            $data['orderItems'] = $select('SELECT i.* FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.member_id=?', [$memberId]);
            $data['transactions'] = $select('SELECT t.* FROM payment_transactions t WHERE t.order_id IN (SELECT id FROM orders WHERE member_id=?) OR t.subscription_id IN (SELECT id FROM subscriptions WHERE member_id=?)', [$memberId, $memberId]);
            $journal = $pdo->prepare('SELECT j.record_json FROM payment_journal j JOIN orders o ON o.id=j.order_id WHERE o.member_id=?'); $journal->execute([$memberId]);
            $data['paymentJournal'] = array_map(static fn(string $json): array => \App\Payments\PaymentContract::journal(json_decode($json, true, 32, JSON_THROW_ON_ERROR)), $journal->fetchAll(PDO::FETCH_COLUMN));
            $data['messages'] = $select('SELECT m.* FROM messages m JOIN message_threads t ON t.id=m.thread_id WHERE t.member_id=?', [$memberId]);
            $data['messageAttachments'] = $select('SELECT a.* FROM message_attachments a JOIN messages m ON m.id=a.message_id JOIN message_threads t ON t.id=m.thread_id WHERE t.member_id=?', [$memberId]);
            $data['requestAttachments'] = $select("SELECT a.* FROM request_attachments a WHERE (a.request_type='content_request' AND a.request_id IN (SELECT id FROM content_requests WHERE member_id=?)) OR (a.request_type='booking_request' AND a.request_id IN (SELECT id FROM booking_requests WHERE member_id=?))", [$memberId, $memberId]);
            $data['bookingForms'] = $select('SELECT f.* FROM booking_form_submissions f JOIN booking_requests r ON r.id=f.booking_request_id WHERE r.member_id=?', [$memberId]);
            $data['blocks'] = $select('SELECT * FROM communication_blocks WHERE member_id=? OR LOWER(email)=?', [$memberId, strtolower((string) $member['email'])]);
            $data['webhooks'] = array_values(array_filter($select('SELECT * FROM payment_webhook_events', []), fn(array $row): bool => $this->privacyWebhookOwned($row, $data)));
            foreach ($data['messageAttachments'] as &$row) $row['kind'] = 'message'; unset($row);
            foreach ($data['requestAttachments'] as &$row) $row['kind'] = 'request'; unset($row);
            if ($owns) $pdo->commit();
            return self::privacySort($data);
        } catch (Throwable $error) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    }

    private function privacyFileData(array $all, string $memberId): array
    {
        $member = $all['members'][$memberId] ?? null;
        if (!is_array($member) || (string) ($member['id'] ?? '') !== $memberId) throw new RuntimeException('Member account not found.', 404);
        $data = ['member' => $member];
        foreach (['subscriptions' => 'subscriptions', 'entitlements' => 'entitlements', 'orders' => 'orders',
            'couponRedemptions' => 'couponRedemptions', 'mediaState' => 'memberMediaState', 'sessions' => 'memberSessions',
            'tokens' => 'memberTokens', 'events' => 'memberEvents', 'mail' => 'mailOutbox', 'threads' => 'messageThreads',
            'contentRequests' => 'contentRequests', 'bookingRequests' => 'bookingRequests', 'notices' => 'memberNotifications'] as $key => $collection) {
            $data[$key] = array_values(array_filter($all[$collection] ?? [], static fn(array $row): bool => (string) ($row['memberId'] ?? '') === $memberId));
        }
        $ids = static fn(array $rows): array => array_fill_keys(array_map('strval', array_column($rows, 'id')), true);
        $orders = $ids($data['orders']); $subscriptions = $ids($data['subscriptions']); $threads = $ids($data['threads']);
        $requests = ['content_request' => $ids($data['contentRequests']), 'booking_request' => $ids($data['bookingRequests'])];
        $data['orderItems'] = [];
        foreach ($orders as $id => $_) foreach ($all['orderItems'][$id] ?? [] as $row) $data['orderItems'][] = array_replace($row, ['orderId' => (string) $id]);
        $data['transactions'] = array_values(array_filter($all['paymentTransactions'] ?? [], static fn(array $row): bool => isset($orders[(string) ($row['orderId'] ?? '')]) || isset($subscriptions[(string) ($row['subscriptionId'] ?? '')])));
        $data['paymentJournal'] = array_map(\App\Payments\PaymentContract::journal(...), array_values(array_filter($all['paymentJournal'] ?? [], static fn(array $row): bool => isset($orders[(string) ($row['orderId'] ?? '')]))));
        $data['messages'] = array_values(array_filter($all['messages'] ?? [], static fn(array $row): bool => isset($threads[(string) ($row['threadId'] ?? '')])));
        $messages = $ids($data['messages']);
        $data['messageAttachments'] = array_values(array_filter($all['messageAttachments'] ?? [], static fn(array $row): bool => isset($messages[(string) ($row['messageId'] ?? '')])));
        $data['requestAttachments'] = array_values(array_filter($all['requestAttachments'] ?? [], static fn(array $row): bool => isset($requests[$row['requestType'] ?? ''][(string) ($row['requestId'] ?? '')])));
        $data['bookingForms'] = [];
        foreach ($all['bookingFormSubmissions'] ?? [] as $id => $row) if (isset($requests['booking_request'][(string) $id])) $data['bookingForms'][] = $row + ['bookingRequestId' => (string) $id];
        $email = strtolower((string) $member['email']);
        $data['blocks'] = array_values(array_filter($all['communicationBlocks'] ?? [], static fn(array $row): bool => (string) ($row['memberId'] ?? '') === $memberId || strtolower((string) ($row['email'] ?? '')) === $email));
        $data['webhooks'] = array_values(array_filter($all['paymentWebhookEvents'] ?? [], fn(array $row): bool => $this->privacyWebhookOwned($row, $data)));
        foreach ($data['messageAttachments'] as &$row) $row['kind'] = 'message'; unset($row);
        foreach ($data['requestAttachments'] as &$row) $row['kind'] = 'request'; unset($row);
        return self::privacySort($data);
    }

    private static function privacySqlRow(array $row): array
    {
        $aliases = ['event_key' => 'event', 'template_key' => 'template', 'recipient_email' => 'recipientEmail',
            'body_text' => 'body', 'thread_type' => 'type', 'notification_type' => 'type', 'target_url' => 'url',
            'snapshot_json' => 'fields', 'values_json' => 'values', 'provider_payload_json' => 'payload',
            'payload_json' => 'payload', 'provider_event_id' => 'eventId'];
        $result = [];
        foreach ($row as $key => $value) {
            $json = str_ends_with($key, '_json');
            $name = $aliases[$key] ?? preg_replace_callback('/_([a-z])/', static fn(array $m): string => strtoupper($m[1]), $json ? substr($key, 0, -5) : $key);
            if ($json) $value = $value === null || $value === '' ? [] : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
            elseif ($key === 'id' || str_ends_with($key, '_id')) $value = $value === null ? null : (string) $value;
            $result[$name] = $value;
        }
        return $result;
    }

    private function privacyWebhookOwned(array $row, array $data): bool
    {
        $payload = $row['payload'] ?? [];
        if (!is_array($payload)) return false;
        foreach ($data['orders'] as $order) {
            if ((isset($payload['order_number']) && (string) $payload['order_number'] === (string) $order['orderNumber'])
                || (isset($payload['order_id']) && (string) $payload['order_id'] === (string) $order['id'])
                || (isset($payload['orderId']) && (string) $payload['orderId'] === (string) $order['id'])) return true;
        }
        return false;
    }

    private static function privacySort(array $data): array
    {
        foreach ($data as $key => &$rows) {
            if ($key === 'member') continue;
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? $a['bookingRequestId'] ?? $a['contentType'] . ':' . $a['contentId']), (string) ($b['id'] ?? $b['bookingRequestId'] ?? $b['contentType'] . ':' . $b['contentId'])));
        }
        unset($rows); return $data;
    }

    public static function privacyDataRevision(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function privacyFreeze(string $memberId, string $requestId, string $expected, callable $authorize): void
    {
        // Commerce owns the dispatch/freeze ordering. Acquiring it after a
        // member transaction or file mutation would invert that lock order.
        if ($this->paymentAtomicActive || $this->fileMutationActive || $this->privacyWriteDepth > 0
            || ($this->mode !== 'file' && $this->pdo()->inTransaction())) {
            throw new RuntimeException('Privacy freeze must acquire commerce before a member transaction or file mutation.', 409);
        }
        $apply = function (array &$all, ?PDO $pdo = null) use ($memberId, $requestId, $expected, $authorize): void {
            $data = $pdo === null ? $this->privacyFileData($all, $memberId) : $this->privacyCoreData($memberId);
            $marker = $data['member']['preferences']['_privacy_erasure'] ?? null;
            if (is_array($marker) && ($marker['request'] ?? '') === $requestId) { $authorize(); return; }
            if (is_array($marker) || !hash_equals(self::privacyDataRevision($data), $expected)) throw new RuntimeException('The account inventory changed. Review and approve its current inventory.', 409);
            $authorize();
            $preferences = (array) ($data['member']['preferences'] ?? []);
            $preferences['_privacy_erasure'] = ['request' => $requestId, 'frozen_at' => gmdate('c'), 'core_done' => false];
            if ($pdo === null) {
                $all['members'][$memberId]['status'] = 'closed'; $all['members'][$memberId]['preferences'] = $preferences;
                foreach ($all['memberSessions'] ?? [] as $id => $row) if ((string) ($row['memberId'] ?? '') === $memberId) $all['memberSessions'][$id]['revokedAt'] = gmdate('c');
                foreach ($all['memberTokens'] ?? [] as $id => $row) if ((string) ($row['memberId'] ?? '') === $memberId) unset($all['memberTokens'][$id]);
            } else {
                $pdo->prepare("UPDATE members SET status='closed',preferences_json=? WHERE id=?")->execute([json_encode($preferences, JSON_THROW_ON_ERROR), $memberId]);
                $pdo->prepare('UPDATE member_sessions SET revoked_at=NOW() WHERE member_id=?')->execute([$memberId]);
                $pdo->prepare('DELETE FROM member_auth_tokens WHERE member_id=?')->execute([$memberId]);
            }
        };
        // A first charge keeps this mutex through its final fresh check and
        // provider call. Freeze either wins first or rechecks its approved
        // inventory after dispatch has finished; no provider runs in this commit.
        $this->withCommerceLock(fn() => $this->privacyCoreMutation($memberId, $apply));
    }

    private function privacyCoreMutation(string $memberId, callable $operation): mixed
    {
        if ($this->mode === 'file') return $this->mutateFileStore($operation, true);
        return $this->privacyMemberWrite($memberId, function () use ($operation): mixed { $empty = []; return $operation($empty, $this->pdo()); }, true);
    }

    public function privacyEraseCore(string $memberId, string $requestId, callable $authorize): void
    {
        $this->privacyCoreMutation($memberId, function (array &$all, ?PDO $pdo = null) use ($memberId, $requestId, $authorize): void {
            $data = $pdo === null ? $this->privacyFileData($all, $memberId) : $this->privacyCoreData($memberId);
            $marker = $data['member']['preferences']['_privacy_erasure'] ?? null;
            if (!is_array($marker) || ($marker['request'] ?? '') !== $requestId) throw new RuntimeException('The privacy closure checkpoint is unavailable.');
            $authorize(); if (($marker['core_done'] ?? false) === true) return;
            $marker['core_done'] = true;
            $email = 'erased-' . substr(hash('sha256', $memberId . ':' . $requestId), 0, 32) . '@privacy.invalid';
            $password = password_hash(bin2hex(random_bytes(40)), PASSWORD_DEFAULT);
            if ($pdo === null) {
                $all['members'][$memberId] = ['id' => $memberId, 'displayName' => 'Removed member', 'email' => $email,
                    'passwordHash' => $password, 'status' => 'closed', 'preferences' => ['_privacy_erasure' => $marker],
                    'emailVerifiedAt' => null, 'lastLoginAt' => null, 'lastSeenAt' => null, 'failedLoginCount' => 0,
                    'lockedUntil' => null, 'createdAt' => $data['member']['createdAt'], 'updatedAt' => gmdate('c'), 'deletedAt' => null];
                foreach (['memberTokens', 'memberSessions', 'memberEvents', 'mailOutbox', 'entitlements', 'memberMediaState', 'memberNotifications'] as $collection) {
                    foreach ($all[$collection] ?? [] as $id => $row) if ((string) ($row['memberId'] ?? '') === $memberId) unset($all[$collection][$id]);
                }
                foreach ($all['subscriptions'] ?? [] as $id => $row) if ((string) ($row['memberId'] ?? '') === $memberId) {
                    $all['subscriptions'][$id]['status'] = 'cancelled'; $all['subscriptions'][$id]['cancelAtPeriodEnd'] = false;
                    $all['subscriptions'][$id]['cancelledAt'] ??= gmdate('c');
                }
                foreach ($data['orders'] as $order) {
                    $id = (string) $order['id'];
                    $all['orders'][$id] = self::privacyFinancialOrder($all['orders'][$id], $email);
                    foreach ($all['orderItems'][$id] ?? [] as $key => $item) { $item['metadata'] = []; $item['title'] = 'Retained financial item'; $all['orderItems'][$id][$key] = $item; }
                }
                foreach ($data['transactions'] as $transaction) {
                    $id = (string) $transaction['id']; $all['paymentTransactions'][$id]['payload'] = [];
                    $all['paymentTransactions'][$id]['last4'] = null; $all['paymentTransactions'][$id]['brand'] = null;
                }
                $webhooks = array_fill_keys(array_column($data['webhooks'], 'id'), true);
                foreach ($all['paymentWebhookEvents'] ?? [] as $id => $row) if (isset($webhooks[$row['id'] ?? ''])) $all['paymentWebhookEvents'][$id]['payload'] = [];
                foreach ($all['communicationBlocks'] ?? [] as $id => $row) if ((string) ($row['memberId'] ?? '') === $memberId || strtolower((string) ($row['email'] ?? '')) === strtolower((string) $data['member']['email'])) {
                    $all['communicationBlocks'][$id]['email'] = null;
                }
            } else {
                $pdo->prepare("UPDATE members SET display_name='Removed member',email=?,password_hash=?,status='closed',preferences_json=?,email_verified_at=NULL,last_login_at=NULL,last_seen_at=NULL,failed_login_count=0,locked_until=NULL,updated_at=NOW() WHERE id=?")->execute([$email, $password, json_encode(['_privacy_erasure' => $marker], JSON_THROW_ON_ERROR), $memberId]);
                foreach (['member_auth_tokens', 'member_sessions', 'member_account_events', 'mail_outbox', 'content_entitlements', 'member_media_state', 'member_notifications'] as $table) $pdo->prepare('DELETE FROM ' . $table . ' WHERE member_id=?')->execute([$memberId]);
                $pdo->prepare("UPDATE subscriptions SET status='cancelled',cancel_at_period_end=0,cancelled_at=COALESCE(cancelled_at,NOW()) WHERE member_id=?")->execute([$memberId]);
                $pdo->prepare("UPDATE orders SET billing_email=?,metadata_json='{}' WHERE member_id=?")->execute([$email, $memberId]);
                $pdo->prepare("UPDATE order_items i JOIN orders o ON o.id=i.order_id SET i.title='Retained financial item',i.metadata_json='{}' WHERE o.member_id=?")->execute([$memberId]);
                $pdo->prepare("UPDATE payment_transactions SET provider_payload_json='{}',last4=NULL,brand=NULL WHERE order_id IN (SELECT id FROM orders WHERE member_id=?) OR subscription_id IN (SELECT id FROM subscriptions WHERE member_id=?)")->execute([$memberId, $memberId]);
                foreach ($data['webhooks'] as $row) $pdo->prepare("UPDATE payment_webhook_events SET payload_json='{}' WHERE id=?")->execute([$row['id']]);
                $pdo->prepare('UPDATE communication_blocks SET email=NULL WHERE member_id=? OR LOWER(email)=?')->execute([$memberId, strtolower((string) $data['member']['email'])]);
            }
        });
    }

    private static function privacyFinancialOrder(array $row, string $email): array
    {
        $row['billingEmail'] = $email; $row['memberEmail'] = $email; $row['memberName'] = 'Removed member'; $row['metadata'] = [];
        unset($row['items']); return $row;
    }

    private function privacyPaymentMember(array $transaction): string
    {
        $owner = function (array $row): string {
            if (!empty($row['orderId'])) return (string) ($this->order((string) $row['orderId'])['memberId'] ?? '');
            if (empty($row['subscriptionId'])) return '';
            if ($this->mode === 'file') return (string) ($this->loadFileStore()[0]['subscriptions'][(string) $row['subscriptionId']]['memberId'] ?? '');
            $statement = $this->pdo()->prepare('SELECT member_id FROM subscriptions WHERE id=?');
            $statement->execute([(string) $row['subscriptionId']]); return (string) ($statement->fetchColumn() ?: '');
        };
        $memberId = $owner($transaction);
        $previous = !empty($transaction['id']) ? $this->paymentTransaction((string) $transaction['id']) : null;
        if ($previous !== null && ($previousMember = $owner($previous)) !== '' && $previousMember !== $memberId) {
            throw new RuntimeException('An existing financial record cannot be moved to a different member.', 409);
        }
        return $memberId;
    }

    public function privacyEraseCommunications(string $memberId, callable $authorize): void
    {
        $this->privacyCoreMutation($memberId, function (array &$all, ?PDO $pdo = null) use ($memberId, $authorize): void {
            $data = $pdo === null ? $this->privacyFileData($all, $memberId) : $this->privacyCoreData($memberId);
            if (!isset($data['member']['preferences']['_privacy_erasure'])) throw new RuntimeException('The account must be frozen first.');
            $authorize();
            if ($pdo === null) {
                foreach (['threads' => 'messageThreads', 'messages' => 'messages', 'contentRequests' => 'contentRequests', 'bookingRequests' => 'bookingRequests',
                    'messageAttachments' => 'messageAttachments', 'requestAttachments' => 'requestAttachments'] as $key => $collection) {
                    foreach ($data[$key] as $row) unset($all[$collection][(string) $row['id']]);
                }
                foreach ($data['bookingForms'] as $row) unset($all['bookingFormSubmissions'][(string) $row['bookingRequestId']]);
            } else {
                $pdo->prepare("DELETE FROM request_attachments WHERE (request_type='content_request' AND request_id IN (SELECT id FROM content_requests WHERE member_id=?)) OR (request_type='booking_request' AND request_id IN (SELECT id FROM booking_requests WHERE member_id=?))")->execute([$memberId, $memberId]);
                $pdo->prepare('DELETE FROM booking_form_submissions WHERE booking_request_id IN (SELECT id FROM booking_requests WHERE member_id=?)')->execute([$memberId]);
                $pdo->prepare('DELETE FROM content_requests WHERE member_id=?')->execute([$memberId]);
                $pdo->prepare('DELETE FROM booking_requests WHERE member_id=?')->execute([$memberId]);
                $pdo->prepare('DELETE FROM message_threads WHERE member_id=?')->execute([$memberId]);
            }
        });
    }

    /** Check all remaining references immediately before removing a checkpointed communication file. */
    public function privacyAttachmentReferenced(string $path): bool
    {
        if ($this->mode === 'file') {
            $data = $this->loadFileStore()[0];
            foreach (['messageAttachments', 'requestAttachments'] as $collection) foreach ($data[$collection] ?? [] as $row) if (($row['filePath'] ?? null) === $path) return true;
            return false;
        }
        foreach (['message_attachments', 'request_attachments'] as $table) {
            $statement = $this->pdo()->prepare('SELECT 1 FROM ' . $table . ' WHERE file_path=? LIMIT 1'); $statement->execute([$path]);
            if ($statement->fetchColumn() !== false) return true;
        }
        return false;
    }

    public function privacyCleanUnreferencedFile(string $path, callable $remove): bool
    {
        if ($this->mode === 'file') return $this->mutateFileStore(static function (array &$data) use ($path, $remove): bool {
            foreach (['messageAttachments', 'requestAttachments'] as $collection) foreach ($data[$collection] ?? [] as $row) if (($row['filePath'] ?? '') === $path) return false;
            $remove(); return true;
        }, true);
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) throw new RuntimeException('Nested attachment cleanup is unavailable.');
        $pdo->beginTransaction();
        try {
            // InnoDB next-key locks also serialize inserts of a newly shared path.
            foreach (['message_attachments','request_attachments'] as $table) {
                $statement = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE file_path=? FOR UPDATE'); $statement->execute([$path]);
                if ($statement->fetchColumn() !== false) { $pdo->rollBack(); return false; }
            }
            $remove(); $pdo->commit(); return true;
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    }
}
