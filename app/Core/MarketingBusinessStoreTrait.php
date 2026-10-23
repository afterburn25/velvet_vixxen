<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Authoritative business reads for attribution; no client-supplied order/member facts. */
trait MarketingBusinessStoreTrait
{
    public function marketingAttributionActors(string $memberId): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $memberId)) throw new InvalidArgumentException('Invalid attribution member.');
        $member = $this->marketingAttributionSource('member', $memberId);
        if ($member === null) return [];
        $snapshots = [$member['preferences']['_attribution'] ?? null];
        if ($this->mode === 'file') {
            foreach ($this->readFileStore()['orders'] ?? [] as $order) {
                if (is_array($order) && (string) ($order['memberId'] ?? '') === $memberId) $snapshots[] = $order['metadata']['attribution'] ?? null;
            }
        } else {
            $query = $this->pdo()->prepare('SELECT metadata_json FROM orders WHERE member_id=?'); $query->execute([$memberId]);
            while ($row = $query->fetchColumn()) {
                $metadata = json_decode((string) $row, true, 512, JSON_THROW_ON_ERROR);
                $snapshots[] = $metadata['attribution'] ?? null;
            }
        }
        $actors = [];
        foreach ($snapshots as $snapshot) {
            $snapshot = \Velvet\Core\MarketingAttribution::snapshot($snapshot);
            if ($snapshot !== null) $actors[] = $snapshot['actor'];
        }
        return array_values(array_unique($actors));
    }

    public function marketingAttributionBatch(string $kind, string $after, int $limit): array
    {
        if (!in_array($kind, ['member','order'], true) || $limit < 1 || $limit > 250
            || ($after !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $after))) throw new InvalidArgumentException('Invalid attribution batch.');
        if ($this->mode === 'file') {
            $records = $this->readFileStore()[$kind === 'member' ? 'members' : 'orders'] ?? [];
            $ids = [];
            foreach ($records as $record) {
                if (!is_array($record) || !is_scalar($record['id'] ?? null)) throw new RuntimeException('A business record needs review before attribution reconciliation.');
                $id = (string) $record['id'];
                if (strcmp($id, $after) > 0 && empty($record['deletedAt'])) $ids[] = $id;
            }
            sort($ids, SORT_STRING); $ids = array_slice($ids, 0, $limit + 1);
        } else {
            if ($after !== '' && !ctype_digit($after)) throw new InvalidArgumentException('Invalid SQL attribution cursor.');
            $table = $kind === 'member' ? 'members' : 'orders';
            $query = $this->pdo()->prepare('SELECT id FROM ' . $table . ' WHERE id > ?' . ($kind === 'member' ? ' AND deleted_at IS NULL' : '') . ' ORDER BY id LIMIT ' . ($limit + 1));
            $query->execute([$after === '' ? 0 : $after]); $ids = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        }
        $complete = count($ids) <= $limit; $ids = array_slice($ids, 0, $limit);
        return ['ids' => $ids, 'last' => $ids === [] ? $after : $ids[count($ids) - 1], 'complete' => $complete];
    }

    public function marketingAttributionSource(string $kind, string $id): ?array
    {
        if (!in_array($kind, ['member','order'], true) || !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $id)) throw new InvalidArgumentException('Invalid attribution source.');
        if ($this->mode === 'file') {
            $records = $this->readFileStore()[$kind === 'member' ? 'members' : 'orders'] ?? [];
            $found = null;
            foreach ($records as $record) {
                if (!is_array($record) || (string) ($record['id'] ?? '') !== $id || !empty($record['deletedAt'])) continue;
                if ($found !== null) throw new RuntimeException('Duplicate attribution source identities need review.');
                $found = $record;
            }
            if ($found !== null) {
                foreach ($kind === 'order' ? ['createdAt','paidAt'] : ['createdAt'] as $field) {
                    $epoch = $this->marketingBusinessEpoch($found[$field] ?? null, false);
                    if ($epoch === false || ($field === 'createdAt' && $epoch === null)) throw new RuntimeException('The business record time needs review.');
                    if (is_int($epoch)) $found[$field] = gmdate('c', $epoch);
                }
            }
            return $found;
        }
        if (!ctype_digit($id)) return null;
        $table = $kind === 'member' ? 'members' : 'orders';
        $query = $this->pdo()->prepare('SELECT *, UNIX_TIMESTAMP(created_at) AS attribution_created_epoch FROM ' . $table . ' WHERE id=?' . ($kind === 'member' ? ' AND deleted_at IS NULL' : '') . ' LIMIT 1');
        $query->execute([$id]); $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $record = $kind === 'member' ? $this->mapMemberRow($row) : $this->mapOrderRow($row);
        if (!is_numeric($row['attribution_created_epoch']) || (int) $row['attribution_created_epoch'] < 1) throw new RuntimeException('The business record creation time needs review.');
        $record['createdAt'] = gmdate('c', (int) $row['attribution_created_epoch']);
        if ($kind === 'order' && $record['paidAt'] !== null) {
            $epoch = $this->marketingBusinessEpoch($record['paidAt'], true);
            if (!is_int($epoch)) throw new RuntimeException('The business payment time needs review.');
            $record['paidAt'] = gmdate('c', $epoch);
        }
        return $record;
    }

    /** Exact email association; missing, duplicate or inactive members never broaden a segment. */
    public function marketingAudienceFacts(array $emails): array
    {
        $emails = array_values(array_unique(array_map('strtolower', $emails)));
        foreach ($emails as $email) if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid newsletter association.');
        if (count($emails) > 20000) throw new InvalidArgumentException('Review this audience in smaller batches.');
        $members = []; $subscriptions = []; $orders = [];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $members = array_values(array_filter($data['members'] ?? [], static fn($row): bool => is_array($row)
                && is_string($row['email'] ?? null) && in_array(strtolower($row['email']), $emails, true) && empty($row['deletedAt'])));
            $subscriptions = $data['subscriptions'] ?? []; $orders = $data['orders'] ?? [];
        } else {
            foreach (array_chunk($emails, 100) as $chunk) {
                if ($chunk === []) continue;
                $statement = $this->pdo()->prepare('SELECT *,UNIX_TIMESTAMP(email_verified_at) AS marketing_verified_epoch FROM members WHERE deleted_at IS NULL AND LOWER(email) IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $statement->execute($chunk); $found = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($found as $row) $members[] = $this->mapMemberRow($row) + ['_marketingVerifiedEpoch' => $row['marketing_verified_epoch']];
                $ids = array_column($found, 'id'); if ($ids === []) continue;
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $statement = $this->pdo()->prepare('SELECT * FROM subscriptions WHERE member_id IN (' . $placeholders . ')');
                $statement->execute($ids);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $subscriptions[] = $this->mapSubscriptionRow($row);
                $statement = $this->pdo()->prepare('SELECT * FROM orders WHERE member_id IN (' . $placeholders . ')');
                $statement->execute($ids);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $orders[] = $this->mapOrderRow($row);
            }
        }
        $facts = []; $memberEmails = []; $duplicates = []; $now = time(); $seenEmails = [];
        require_once __DIR__ . '/MarketingAccess.php';
        $planIds = \Velvet\Core\MarketingAccess::availablePlanIds($this);
        foreach ($members as $member) {
            $email = strtolower($member['email']);
            if (isset($seenEmails[$email])) { $duplicates[$email] = true; continue; }
            $seenEmails[$email] = true;
            $verified = $this->mode === 'mysql' ? (isset($member['_marketingVerifiedEpoch']) ? (int) $member['_marketingVerifiedEpoch'] : null)
                : $this->marketingBusinessEpoch($member['emailVerifiedAt'] ?? null, false);
            if (($member['status'] ?? '') !== 'active' || !is_int($verified) || $verified < 1 || $verified > $now) continue;
            $id = (string) $member['id']; $memberEmails[$id] = $email;
            $facts[$email] = ['member' => true, 'valid' => true, 'active_plan_ids' => [], 'purchases' => [], 'referrals' => []];
            $attribution = $member['preferences']['_attribution'] ?? null;
            if (is_array($attribution)) $facts[$email]['referrals'][] = $attribution;
        }
        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription)) continue;
            $email = $memberEmails[(string) ($subscription['memberId'] ?? '')] ?? null;
            if ($email === null) continue;
            $status = $subscription['status'] ?? ''; $end = $subscription['currentPeriodEnd'] ?? null;
            // mapSubscriptionRow exposes explicit UTC ISO instants on SQL too.
            $startEpoch = $this->marketingBusinessEpoch($subscription['currentPeriodStart'] ?? null, false);
            $endEpoch = $this->marketingBusinessEpoch($end, false);
            $grace = $subscription['graceEndsAt'] ?? null;
            $graceEpoch = $this->marketingBusinessEpoch($grace, false);
            if ($startEpoch === false || $endEpoch === false || $graceEpoch === false
                || (is_int($startEpoch) && is_int($endEpoch) && $endEpoch <= $startEpoch)
                || !in_array($status, ['active','trialing','past_due','cancelled','paused','expired'], true)) { $facts[$email]['valid'] = false; continue; }
            if (!in_array($status, ['active','trialing','past_due'], true)
                || (is_int($startEpoch) && $startEpoch > $now)
                || ($status === 'past_due' ? (!is_int($graceEpoch) || $graceEpoch <= $now) : ($endEpoch !== null && $endEpoch <= $now))) continue;
            $plan = $subscription['planId'] ?? null;
            if ((!is_string($plan) && !is_int($plan)) || !in_array((string) $plan, $planIds, true)) { $facts[$email]['valid'] = false; continue; }
            $facts[$email]['active_plan_ids'][] = (string) $plan;
        }
        foreach ($orders as $order) {
            if (!is_array($order)) continue;
            $email = $memberEmails[(string) ($order['memberId'] ?? '')] ?? null;
            if ($email === null || !in_array($order['status'] ?? '', ['paid','partially_refunded'], true)) continue;
            $paid = $this->marketingBusinessEpoch($order['paidAt'] ?? null, $this->mode === 'mysql');
            if (!is_int($paid) || $paid > $now) { $facts[$email]['valid'] = false; continue; }
            $currency = $order['currency'] ?? null;
            $total = $order['grandTotal'] ?? null; $refunded = $order['refundedTotal'] ?? 0;
            if (!is_string($currency) || !preg_match('/\A[A-Z]{3}\z/D', $currency)
                || !is_numeric($total) || !is_numeric($refunded) || !is_finite((float) $total) || !is_finite((float) $refunded)
                || (float) $total < 0 || (float) $refunded < 0 || (float) $refunded > (float) $total) { $facts[$email]['valid'] = false; continue; }
            $amount = max(0, (int) round(((float) $total - (float) $refunded) * 100));
            if ($amount <= 0) continue;
            $facts[$email]['purchases'][] = ['at' => $paid, 'amount_minor' => $amount, 'currency' => $currency];
            if (is_array($order['metadata']['attribution'] ?? null)) $facts[$email]['referrals'][] = $order['metadata']['attribution'];
        }
        foreach ($duplicates as $email => $_) unset($facts[$email]);
        return $facts;
    }

    private function marketingBusinessEpoch(mixed $value, bool $databaseUtc): int|false|null
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) return false;
        if ($databaseUtc) {
            if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value)) return false;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            return $date && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : false;
        }
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value)) return false;
        $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
        return $date && $date->format('Y-m-d\TH:i:sP') === $canonical ? $date->getTimestamp() : false;
    }
}
