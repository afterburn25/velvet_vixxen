<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public function __construct(private readonly Store $store)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        $admin = $this->store->findAdminByEmail($email);
        if (!$admin || !(bool) ($admin['is_active'] ?? false) || !password_verify($password, (string) ($admin['password_hash'] ?? ''))) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id' => $admin['id'],
            'name' => $admin['name'] ?? 'Owner',
            'email' => $admin['email'],
            'role' => $admin['role'] ?? 'owner',
        ];
        $this->store->audit(is_numeric($admin['id']) ? (int) $admin['id'] : null, 'admin.login');
        return true;
    }

    public function check(): bool
    {
        return isset($_SESSION['admin']['id']);
    }

    public function user(): ?array
    {
        return $this->check() ? (array) $_SESSION['admin'] : null;
    }

    public function id(): ?int
    {
        $id = $_SESSION['admin']['id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public function logout(): void
    {
        $id = $this->id();
        unset($_SESSION['admin']);
        session_regenerate_id(true);
        $this->store->audit($id, 'admin.logout');
    }
}
