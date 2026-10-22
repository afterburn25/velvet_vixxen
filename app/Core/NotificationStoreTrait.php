<?php
declare(strict_types=1);

namespace App\Core;

/** Read-only projections for the notification planner; credentials never leave Store. */
trait NotificationStoreTrait
{
    public function notificationOwners(): array
    {
        if ($this->mode === 'file') {
            $rows = $this->loadFileStore()[0]['admins'] ?? [];
        } else {
            $rows = $this->pdo()->query("SELECT id,email,role,is_active FROM admins WHERE role='owner' AND is_active=1 ORDER BY id")->fetchAll();
        }
        $owners = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['role'] ?? '') !== 'owner' || empty($row['is_active'])
                || !EmailAddress::isValid((string) ($row['email'] ?? ''))) continue;
            $owners[] = ['id' => (string) $row['id'], 'email' => EmailAddress::normalize($row['email'])];
        }
        return $owners;
    }

    /** SQL NOW() dates are interpreted by the database that wrote them. */
    public function notificationMemberDates(): array
    {
        if ($this->mode !== 'mysql') return [];
        $rows = $this->pdo()->query('SELECT id,UNIX_TIMESTAMP(created_at) AS created_epoch FROM members')->fetchAll();
        $result = [];
        foreach ($rows as $row) $result[(string) $row['id']] = (int) ($row['created_epoch'] ?? 0);
        return $result;
    }

    /** Recent source records for one member; do not lose events behind an unrelated 1000-order list limit. */
    public function notificationRecentOrders(string $memberId): array
    {
        if ($this->mode === 'file') return array_values(array_filter($this->loadFileStore()[0]['orders'] ?? [],
            static fn(array $row): bool => (string) ($row['memberId'] ?? '') === $memberId && in_array($row['status'] ?? '', ['paid', 'failed'], true)));
        // created_at is SQL NOW(); paid_at is normalized to UTC by mysqlDate().
        // Do not interpret those two date conventions as the same local clock.
        $statement = $this->pdo()->prepare("SELECT *,UNIX_TIMESTAMP(created_at) AS notification_created_epoch FROM orders WHERE member_id=? AND ((status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) OR (status='paid' AND paid_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY))) ORDER BY id");
        $statement->execute([$memberId]);
        return array_map(fn(array $row): array => $this->mapOrderRow($row) + ['_notification_created_epoch' => (int) ($row['notification_created_epoch'] ?? 0)], $statement->fetchAll());
    }

    public function notificationOrder(string $id, string $memberId): ?array
    {
        $row = $this->mode === 'file' ? ($this->loadFileStore()[0]['orders'][$id] ?? null) : $this->order($id);
        return is_array($row) && (string) ($row['id'] ?? '') === $id && (string) ($row['memberId'] ?? '') === $memberId ? $row : null;
    }
}
