<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Uses the existing settings table so an upgrade can still sign in to migrate. */
trait AdminSecurityStoreTrait
{
    public function configuration(): Config { return $this->config; }

    public function adminSecurityState(): array
    {
        $state = $this->setting('_private_admin_security_v1', []);
        if (!is_array($state) || ($state !== [] && ($state['schema'] ?? null) !== 1)) {
            throw new RuntimeException('Administrator security state needs recovery. Sign-in is unavailable.');
        }
        return $state;
    }

    public function mutateAdminSecurity(callable $mutation): mixed
    {
        $key = '_private_admin_security_v1';
        $apply = static function (array &$state) use ($mutation): mixed {
            if ($state !== [] && ($state['schema'] ?? null) !== 1) throw new RuntimeException('Administrator security state is invalid.');
            if ($state === []) $state = ['schema' => 1, 'admins' => [], 'attempts' => [], 'events' => []];
            foreach (['admins', 'attempts', 'events'] as $field) if (!is_array($state[$field] ?? null)) throw new RuntimeException('Administrator security state is incomplete.');
            $result = $mutation($state);
            if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 2097152) throw new RuntimeException('Administrator security storage is full.');
            return $result;
        };
        if ($this->mode === 'file') {
            return $this->mutateFileStore(static function (array &$data) use ($key, $apply): mixed {
                $state = $data['settings'][$key] ?? [];
                if (!is_array($state)) throw new RuntimeException('Administrator security state is invalid.');
                $result = $apply($state); $data['settings'][$key] = $state; return $result;
            }, true);
        }
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) throw new RuntimeException('Nested administrator security updates are unavailable.');
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())');
            $insert->execute([$key, '[]']);
            $read = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE'); $read->execute([$key]);
            $state = json_decode((string) $read->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new RuntimeException('Administrator security state is invalid.');
            $result = $apply($state);
            $write = $pdo->prepare('UPDATE settings SET setting_value=?, updated_at=NOW() WHERE setting_key=?');
            $write->execute([json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key]);
            $pdo->commit(); return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack(); throw $error;
        }
    }

    public function auditRecords(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        if ($this->mode === 'file') return array_reverse(array_slice((array) ($this->readFileStore()['audit'] ?? []), -$limit));
        $rows = $this->pdo()->query('SELECT id,admin_id,action_key,context_json,created_at FROM audit_log ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => ['id' => $row['id'], 'adminId' => $row['admin_id'], 'action' => $row['action_key'],
            'context' => json_decode((string) $row['context_json'], true), 'createdAt' => $row['created_at']], $rows);
    }
}
