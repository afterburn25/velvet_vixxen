<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/** Menu edits share the existing file lock or a transaction on settings. */
trait MenuStoreTrait
{
    public function navigationMenu(string $location, string $context = 'common'): array
    {
        $key = 'navigation_menu_' . MenuService::location($location);
        if ($this->mode === 'file') {
            // Public/admin reads must not create a data store or save defaults.
            [$data] = $this->loadFileStore();
            $settings = (array) ($data['settings'] ?? []);
            return MenuService::snapshot($settings[$key] ?? null, array_key_exists($key, $settings), $location, $context);
        }
        $query = $this->pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $query->execute([$key]);
        $raw = $query->fetchColumn();
        return MenuService::snapshot($raw === false ? null : $this->decodeNavigationRecord((string) $raw), $raw !== false, $location, $context);
    }

    public function requireNavigationOwner(array $identity): array
    {
        if ($this->mode === 'file') {
            [$data] = $this->loadFileStore();
            return $this->navigationOwnerFromRows((array) ($data['admins'] ?? []), $identity);
        }
        $query = $this->pdo()->prepare('SELECT id,email,role,is_active FROM admins WHERE id=? LIMIT 1');
        $query->execute([$this->navigationIdentityId($identity)]);
        $row = $query->fetch();
        return $this->navigationOwnerFromRows(is_array($row) ? [$row] : [], $identity);
    }

    public function saveNavigationMenu(string $location, array $items, int $expectedRevision, array $ownerIdentity): array
    {
        return $this->mutateNavigationMenu($location, MenuService::validateItems($items), $expectedRevision, $ownerIdentity, true);
    }

    public function resetNavigationMenu(string $location, int $expectedRevision, array $ownerIdentity): array
    {
        return $this->mutateNavigationMenu($location, [], $expectedRevision, $ownerIdentity, false);
    }

    private function mutateNavigationMenu(string $location, array $items, int $expectedRevision, array $identity, bool $configured): array
    {
        MenuService::location($location);
        MenuService::revision($expectedRevision);
        if ($expectedRevision === MenuService::MAX_REVISION) throw new RuntimeException('This menu has reached its revision limit. Restore a verified copy before making further changes.');
        $this->navigationIdentityId($identity);
        $key = 'navigation_menu_' . $location;
        $action = $configured ? 'site.menu.saved' : 'site.menu.reset';
        $next = static function (mixed $current, bool $exists) use ($location, $items, $expectedRevision, $configured): array {
            $snapshot = MenuService::snapshot($current, $exists, $location);
            if ($snapshot['revision'] !== $expectedRevision) {
                throw new RuntimeException('This menu changed after you opened it. Your attempted changes are kept below; reload the saved menu before trying again.', 409);
            }
            return ['schema' => 1, 'revision' => $expectedRevision + 1, 'configured' => $configured, 'items' => $items];
        };
        if ($this->mode === 'file') {
            // Reject missing/unauthorized installations before the generic writer
            // has any opportunity to initialize its default data file.
            $this->requireNavigationOwner($identity);
            return $this->mutateFileStore(function (array &$data) use ($identity, $key, $next, $action, $location): array {
                $owner = $this->navigationOwnerFromRows((array) ($data['admins'] ?? []), $identity);
                $settings = (array) ($data['settings'] ?? []);
                $record = $next($settings[$key] ?? null, array_key_exists($key, $settings));
                $data['settings'][$key] = $record;
                $data['audit'][] = ['id' => bin2hex(random_bytes(6)), 'adminId' => $owner['id'], 'action' => $action,
                    'context' => ['location' => $location, 'revision' => $record['revision'], 'items' => MenuService::itemCount($record['items'])],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'createdAt' => now_iso()];
                $data['audit'] = array_slice($data['audit'], -500);
                return MenuService::snapshot($record, true, $location);
            });
        }
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) throw new RuntimeException('Finish the current database operation before changing a menu.');
        $pdo->beginTransaction();
        try {
            // Lock the saved owner row so demotion/deactivation cannot race the write.
            $ownerQuery = $pdo->prepare('SELECT id,email,role,is_active FROM admins WHERE id=? LIMIT 1 FOR UPDATE');
            $ownerQuery->execute([$this->navigationIdentityId($identity)]);
            $row = $ownerQuery->fetch();
            $owner = $this->navigationOwnerFromRows(is_array($row) ? [$row] : [], $identity);
            // Materialize an absent row only inside the owner mutation transaction.
            // The unique settings key serializes first saves from different owners.
            $placeholder = json_encode(['schema' => 1, 'revision' => 0, 'configured' => false, 'items' => []], JSON_THROW_ON_ERROR);
            $insert = $pdo->prepare('INSERT INTO settings (setting_key,setting_value,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)');
            $insert->execute([$key, $placeholder]);
            $query = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE');
            $query->execute([$key]);
            $record = $next($this->decodeNavigationRecord((string) $query->fetchColumn()), true);
            $update = $pdo->prepare('UPDATE settings SET setting_value=?,updated_at=NOW() WHERE setting_key=?');
            $update->execute([json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $key]);
            $audit = $pdo->prepare('INSERT INTO audit_log (admin_id,action_key,context_json,ip_address,created_at) VALUES (?,?,?,?,NOW())');
            $audit->execute([$owner['id'], $action, json_encode(['location' => $location, 'revision' => $record['revision'],
                'items' => MenuService::itemCount($record['items'])], JSON_THROW_ON_ERROR), $_SERVER['REMOTE_ADDR'] ?? null]);
            $pdo->commit();
            return MenuService::snapshot($record, true, $location);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function navigationIdentityId(array $identity): string
    {
        $id = $identity['id'] ?? null;
        $email = $identity['email'] ?? null;
        if ((!is_string($id) && !is_int($id)) || (string) $id === '' || strlen((string) $id) > 80
            || !is_string($email) || $email === '' || strlen($email) > 254) {
            throw new RuntimeException('Only the active site owner can manage menus.', 403);
        }
        return (string) $id;
    }

    private function navigationOwnerFromRows(array $rows, array $identity): array
    {
        $id = $this->navigationIdentityId($identity);
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id
                && strtolower((string) ($row['email'] ?? '')) === strtolower($identity['email'])
                && in_array($row['is_active'] ?? null, [1, '1', true], true) && ($row['role'] ?? '') === 'owner') return $row;
        }
        throw new RuntimeException('Only the active site owner can manage menus.', 403);
    }

    private function decodeNavigationRecord(string $raw): mixed
    {
        if (strlen($raw) > MenuService::MAX_JSON_BYTES) throw new RuntimeException('The saved menu needs attention. Restore a verified copy before changing it.');
        try { return json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('The saved menu needs attention. Restore a verified copy before changing it.'); }
    }
}
