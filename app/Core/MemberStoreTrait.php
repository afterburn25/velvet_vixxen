<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use RuntimeException;

trait MemberStoreTrait
{
    /** @return list<array<string,mixed>> */
    public function members(string $search = '', ?string $status = null): array
    {
        $search = strtolower(trim($search));
        $status = $status !== null ? strtolower(trim($status)) : null;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['members'] ?? []));
            $rows = array_values(array_filter($rows, static function (array $member) use ($search, $status): bool {
                if ($status !== null && $status !== '' && (string) ($member['status'] ?? '') !== $status) {
                    return false;
                }
                if ($search === '') {
                    return empty($member['deletedAt']);
                }
                $haystack = strtolower((string) ($member['displayName'] ?? '') . ' ' . (string) ($member['email'] ?? ''));
                return empty($member['deletedAt']) && str_contains($haystack, $search);
            }));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }

        $where = ['deleted_at IS NULL'];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(LOWER(display_name) LIKE ? OR LOWER(email) LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM members WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC');
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->mapMemberRow($row), $stmt->fetchAll());
    }

    public function member(string|int $idOrEmail): ?array
    {
        $lookup = trim((string) $idOrEmail);
        if ($lookup === '') {
            return null;
        }
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            if (isset($data['members'][$lookup]) && is_array($data['members'][$lookup])) {
                return (array) $data['members'][$lookup];
            }
            $email = strtolower($lookup);
            foreach ((array) ($data['members'] ?? []) as $member) {
                if (strtolower((string) ($member['email'] ?? '')) === $email) {
                    return (array) $member;
                }
            }
            return null;
        }
        if (ctype_digit($lookup)) {
            $stmt = $this->pdo()->prepare('SELECT * FROM members WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([(int) $lookup]);
        } else {
            $stmt = $this->pdo()->prepare('SELECT * FROM members WHERE email = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([strtolower($lookup)]);
        }
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapMemberRow($row) : null;
    }

    /** @param array<string,mixed> $input */
    public function createMember(array $input): array
    {
        $name = trim((string) ($input['displayName'] ?? $input['display_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 120) {
            throw new RuntimeException('Enter a display name of 120 characters or fewer.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || (function_exists('mb_strlen') ? mb_strlen($email) : strlen($email)) > 190) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if ($this->member($email) !== null) {
            throw new RuntimeException('An account already exists for that email address.');
        }
        if ((function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) < 12) {
            throw new RuntimeException('Your password must be at least 12 characters.');
        }
        $status = (string) ($input['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'active', 'suspended', 'closed'], true)) {
            $status = 'pending';
        }
        $verifiedAt = $input['emailVerifiedAt'] ?? null;
        $member = [
            'id' => bin2hex(random_bytes(8)),
            'displayName' => $name,
            'email' => $email,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'emailVerifiedAt' => $verifiedAt,
            'status' => $status,
            'lastLoginAt' => null,
            'lastSeenAt' => null,
            'preferences' => (array) ($input['preferences'] ?? []),
            'failedLoginCount' => 0,
            'lockedUntil' => null,
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
            'deletedAt' => null,
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['members'][(string) $member['id']] = $member;
            $this->writeFileStore($data);
            $this->recordMemberEvent((string) $member['id'], 'member.registered');
            return $member;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO members (display_name,email,password_hash,email_verified_at,status,preferences_json,failed_login_count,created_at,updated_at) VALUES (?,?,?,?,?,?,0,NOW(),NOW())');
        $stmt->execute([$name, $email, $member['passwordHash'], $this->memberVerificationSqlTime($verifiedAt), $status, json_encode($member['preferences'])]);
        $member['id'] = (string) $this->pdo()->lastInsertId();
        $this->recordMemberEvent((string) $member['id'], 'member.registered');
        return $member;
    }

    /** @param array<string,mixed> $changes */
    public function updateMember(string|int $memberId, array $changes): array
    {
        if ($this->privacyWriteDepth === 0) { return $this->privacyMemberWrite((string) $memberId, fn() => $this->updateMember($memberId, $changes)); }
        $this->privacyRequireOpenMember((string) $memberId);
        $member = $this->member($memberId);
        if (!$member) {
            throw new RuntimeException('Member account not found.');
        }
        $allowedStatus = ['pending', 'active', 'suspended', 'closed'];
        if (isset($changes['displayName'])) {
            $name = trim((string) $changes['displayName']);
            if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 120) {
                throw new RuntimeException('Enter a valid display name.');
            }
            $member['displayName'] = $name;
        }
        if (isset($changes['status']) && in_array((string) $changes['status'], $allowedStatus, true)) {
            $member['status'] = (string) $changes['status'];
        }
        if (array_key_exists('emailVerifiedAt', $changes)) {
            $member['emailVerifiedAt'] = $changes['emailVerifiedAt'];
        }
        if (isset($changes['preferences']) && is_array($changes['preferences'])) {
            $preferences = $changes['preferences'];
            unset($preferences['_attribution']);
            if (isset($member['preferences']['_attribution'])) $preferences['_attribution'] = $member['preferences']['_attribution'];
            $member['preferences'] = $preferences;
        }
        $member['updatedAt'] = now_iso();
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['members'][(string) $member['id']] = $member;
            $this->writeFileStore($data);
            return $member;
        }
        $stmt = $this->pdo()->prepare('UPDATE members SET display_name=?, status=?, email_verified_at=?, preferences_json=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([
            $member['displayName'], $member['status'], $this->memberVerificationSqlTime($member['emailVerifiedAt'] ?? null),
            json_encode((array) ($member['preferences'] ?? [])), (int) $member['id'],
        ]);
        return $this->member((string) $member['id']) ?? $member;
    }

    /** Store offset-bearing verification instants in the SQL connection timezone. */
    private function memberVerificationSqlTime(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new RuntimeException('The verification date is invalid.');
        // Existing SQL values already use the connection's wall clock. Do not
        // reinterpret them in the configured PHP/site timezone during an edit.
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new RuntimeException('The verification date is invalid.');
            return $value;
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) {
            throw new RuntimeException('The verification date needs an explicit timezone.');
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        if (!$date || $date->format('Y-m-d\TH:i:sP') !== $normalized) throw new RuntimeException('The verification date is invalid.');
        // MySQL accepts ISO offsets in DATETIME bindings while MariaDB does
        // not. Both engines implement this explicit instant-to-local conversion.
        $statement = $this->pdo()->prepare('SELECT FROM_UNIXTIME(?)');
        $statement->execute([$date->getTimestamp()]);
        $converted = $statement->fetchColumn();
        if (!is_string($converted) || $converted === '') throw new RuntimeException('The verification date is outside the database-supported range.');
        return $converted;
    }

    public function setMemberPassword(string|int $memberId, string $password): void
    {
        if ($this->privacyWriteDepth === 0) { $this->privacyMemberWrite((string) $memberId, fn() => $this->setMemberPassword($memberId, $password)); return; }
        $this->privacyRequireOpenMember((string) $memberId);
        if ((function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) < 12) {
            throw new RuntimeException('Your password must be at least 12 characters.');
        }
        $member = $this->member($memberId);
        if (!$member) {
            throw new RuntimeException('Member account not found.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['members'][(string) $member['id']]['passwordHash'] = $hash;
            $data['members'][(string) $member['id']]['failedLoginCount'] = 0;
            $data['members'][(string) $member['id']]['lockedUntil'] = null;
            $data['members'][(string) $member['id']]['updatedAt'] = now_iso();
            $this->writeFileStore($data);
        } else {
            $stmt = $this->pdo()->prepare('UPDATE members SET password_hash=?, failed_login_count=0, locked_until=NULL, updated_at=NOW() WHERE id=?');
            $stmt->execute([$hash, (int) $member['id']]);
        }
        $this->recordMemberEvent((string) $member['id'], 'member.password.changed');
    }

    public function markMemberVerified(string|int $memberId): array
    {
        $member = $this->updateMember($memberId, ['emailVerifiedAt' => now_iso(), 'status' => 'active']);
        $this->recordMemberEvent((string) $member['id'], 'member.email.verified');
        return $member;
    }

    /** Compare a SQL verification DATETIME in the same connection timezone that stores it. */
    public function databaseMemberVerificationIsCurrent(string|int $memberId): bool
    {
        if ($this->mode !== 'mysql') throw new RuntimeException('Database verification comparison requires SQL storage.');
        $statement = $this->pdo()->prepare("SELECT 1 FROM members WHERE id=? AND status='active' AND deleted_at IS NULL
            AND email_verified_at IS NOT NULL AND email_verified_at>='1000-01-01 00:00:00' AND email_verified_at<=NOW() LIMIT 1");
        $statement->execute([(string) $memberId]);
        return $statement->fetchColumn() !== false;
    }

    public function recordMemberLoginSuccess(string|int $memberId): void
    {
        if ($this->privacyWriteDepth === 0) { $this->privacyMemberWrite((string) $memberId, fn() => $this->recordMemberLoginSuccess($memberId), true); return; }
        if ($this->privacyMemberErased($memberId)) return;
        $member = $this->member($memberId);
        if (!$member) {
            return;
        }
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['members'][(string) $member['id']]['lastLoginAt'] = now_iso();
            $data['members'][(string) $member['id']]['lastSeenAt'] = now_iso();
            $data['members'][(string) $member['id']]['failedLoginCount'] = 0;
            $data['members'][(string) $member['id']]['lockedUntil'] = null;
            $this->writeFileStore($data);
        } else {
            $this->pdo()->prepare('UPDATE members SET last_login_at=NOW(), last_seen_at=NOW(), failed_login_count=0, locked_until=NULL, updated_at=NOW() WHERE id=?')->execute([(int) $member['id']]);
        }
        $this->recordMemberEvent((string) $member['id'], 'member.login');
    }

    public function recordMemberLoginFailure(string $email): void
    {
        $member = $this->member($email);
        if (!$member) {
            return;
        }
        if ($this->privacyMemberErased((string) $member['id'])) return;
        if ($this->privacyWriteDepth === 0) { $this->privacyMemberWrite((string) $member['id'], fn() => $this->recordMemberLoginFailure($email), true); return; }
        $count = (int) ($member['failedLoginCount'] ?? 0) + 1;
        $lockUntil = $count >= 8 ? gmdate('c', time() + 900) : null;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['members'][(string) $member['id']]['failedLoginCount'] = $count;
            $data['members'][(string) $member['id']]['lockedUntil'] = $lockUntil;
            $this->writeFileStore($data);
        } else {
            $stmt = $this->pdo()->prepare('UPDATE members SET failed_login_count=?, locked_until=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$count, $lockUntil ? gmdate('Y-m-d H:i:s', strtotime($lockUntil)) : null, (int) $member['id']]);
        }
        $this->recordMemberEvent((string) $member['id'], 'member.login.failed', ['attempts' => $count]);
    }

    /** @return array{token:string,expiresAt:string} */
    public function createMemberToken(string|int $memberId, string $type, int $ttlSeconds): array
    {
        if ($this->privacyWriteDepth === 0) { return $this->privacyMemberWrite((string) $memberId, fn() => $this->createMemberToken($memberId, $type, $ttlSeconds)); }
        $this->privacyRequireOpenMember((string) $memberId);
        if (!in_array($type, ['email_verification', 'password_reset'], true)) {
            throw new RuntimeException('Unsupported member token type.');
        }
        $member = $this->member($memberId);
        if (!$member) {
            throw new RuntimeException('Member account not found.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $expiresAt = gmdate('c', time() + max(300, $ttlSeconds));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['memberTokens'] ?? []) as $id => $existing) {
                if ((string) ($existing['memberId'] ?? '') === (string) $member['id'] && (string) ($existing['type'] ?? '') === $type && empty($existing['usedAt'])) {
                    $data['memberTokens'][$id]['usedAt'] = now_iso();
                }
            }
            $id = bin2hex(random_bytes(8));
            $data['memberTokens'][$id] = [
                'id' => $id, 'memberId' => (string) $member['id'], 'tokenHash' => $hash,
                'type' => $type, 'expiresAt' => $expiresAt, 'usedAt' => null, 'createdAt' => now_iso(),
            ];
            $this->writeFileStore($data);
        } else {
            $this->pdo()->prepare('UPDATE member_auth_tokens SET used_at=NOW() WHERE member_id=? AND token_type=? AND used_at IS NULL')->execute([(int) $member['id'], $type]);
            $stmt = $this->pdo()->prepare('INSERT INTO member_auth_tokens (member_id,token_hash,token_type,expires_at,created_at) VALUES (?,?,?,?,NOW())');
            $stmt->execute([(int) $member['id'], $hash, $type, gmdate('Y-m-d H:i:s', strtotime($expiresAt))]);
        }
        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    public function memberForToken(string $rawToken, string $type, bool $consume = false): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !in_array($type, ['email_verification', 'password_reset'], true)) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['memberTokens'] ?? []) as $id => $token) {
                if ((string) ($token['tokenHash'] ?? '') !== $hash || (string) ($token['type'] ?? '') !== $type || !empty($token['usedAt'])) {
                    continue;
                }
                if (strtotime((string) ($token['expiresAt'] ?? '')) <= time()) {
                    return null;
                }
                if ($consume) {
                    $data['memberTokens'][$id]['usedAt'] = now_iso();
                    $this->writeFileStore($data);
                }
                return $this->member((string) ($token['memberId'] ?? ''));
            }
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM member_auth_tokens WHERE token_hash=? AND token_type=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
        $stmt->execute([$hash, $type]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ($consume) {
            $consumeStatement = $this->pdo()->prepare('UPDATE member_auth_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL AND expires_at>NOW()');
            $consumeStatement->execute([(int) $row['id']]);
            if ($consumeStatement->rowCount() !== 1) {
                return null;
            }
        }
        return $this->member((string) $row['member_id']);
    }

    /** @param array<string,mixed> $metadata */
    public function createMemberSession(string|int $memberId, string $rawToken, array $metadata = []): array
    {
        if ($this->privacyWriteDepth === 0) { return $this->privacyMemberWrite((string) $memberId, fn() => $this->createMemberSession($memberId, $rawToken, $metadata)); }
        $this->privacyRequireOpenMember((string) $memberId);
        $member = $this->member($memberId);
        if (!$member) {
            throw new RuntimeException('Member account not found.');
        }
        $hash = hash('sha256', $rawToken);
        $session = [
            'id' => bin2hex(random_bytes(8)), 'memberId' => (string) $member['id'], 'tokenHash' => $hash,
            'deviceName' => (string) ($metadata['deviceName'] ?? $this->deviceName((string) ($metadata['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')))),
            'ipAddress' => (string) ($metadata['ipAddress'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
            'userAgent' => (string) ($metadata['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'lastSeenAt' => now_iso(), 'createdAt' => now_iso(), 'revokedAt' => null,
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['memberSessions'][(string) $session['id']] = $session;
            $this->writeFileStore($data);
            return $session;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO member_sessions (member_id,token_hash,device_name,ip_address,user_agent,last_seen_at,created_at) VALUES (?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([(int) $member['id'], $hash, $session['deviceName'], $session['ipAddress'] ?: null, $session['userAgent'] ?: null]);
        $session['id'] = (string) $this->pdo()->lastInsertId();
        return $session;
    }

    public function memberSession(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['memberSessions'] ?? []) as $session) {
                if ((string) ($session['tokenHash'] ?? '') === $hash && empty($session['revokedAt'])) {
                    return (array) $session;
                }
            }
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM member_sessions WHERE token_hash=? AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapMemberSessionRow($row) : null;
    }

    public function touchMemberSession(string $rawToken): void
    {
        if ($rawToken === '') {
            return;
        }
        $hash = hash('sha256', $rawToken);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['memberSessions'] ?? []) as $id => $session) {
                if ((string) ($session['tokenHash'] ?? '') === $hash && empty($session['revokedAt'])) {
                    $data['memberSessions'][$id]['lastSeenAt'] = now_iso();
                    $memberId = (string) ($session['memberId'] ?? '');
                    if (isset($data['members'][$memberId])) {
                        $data['members'][$memberId]['lastSeenAt'] = now_iso();
                    }
                    $this->writeFileStore($data);
                    return;
                }
            }
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE member_sessions s JOIN members m ON m.id=s.member_id SET s.last_seen_at=NOW(), m.last_seen_at=NOW() WHERE s.token_hash=? AND s.revoked_at IS NULL');
        $stmt->execute([$hash]);
    }

    /** @return list<array<string,mixed>> */
    public function memberSessions(string|int $memberId, bool $includeRevoked = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values(array_filter((array) ($data['memberSessions'] ?? []), static fn(array $session): bool =>
                (string) ($session['memberId'] ?? '') === (string) $memberId && ($includeRevoked || empty($session['revokedAt']))
            ));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['lastSeenAt'] ?? ''), (string) ($a['lastSeenAt'] ?? '')));
            return $rows;
        }
        $sql = 'SELECT * FROM member_sessions WHERE member_id=?' . ($includeRevoked ? '' : ' AND revoked_at IS NULL') . ' ORDER BY last_seen_at DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([(int) $memberId]);
        return array_map(fn(array $row): array => $this->mapMemberSessionRow($row), $stmt->fetchAll());
    }

    public function revokeMemberSession(string|int $memberId, string|int $sessionId): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            if (isset($data['memberSessions'][(string) $sessionId]) && (string) ($data['memberSessions'][(string) $sessionId]['memberId'] ?? '') === (string) $memberId) {
                $data['memberSessions'][(string) $sessionId]['revokedAt'] = now_iso();
                $this->writeFileStore($data);
            }
        } else {
            $this->pdo()->prepare('UPDATE member_sessions SET revoked_at=NOW() WHERE id=? AND member_id=?')->execute([(int) $sessionId, (int) $memberId]);
        }
        $this->recordMemberEvent((string) $memberId, 'member.session.revoked', ['sessionId' => (string) $sessionId]);
    }

    public function revokeOtherMemberSessions(string|int $memberId, string $currentRawToken): void
    {
        $currentHash = hash('sha256', $currentRawToken);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['memberSessions'] ?? []) as $id => $session) {
                if ((string) ($session['memberId'] ?? '') === (string) $memberId && (string) ($session['tokenHash'] ?? '') !== $currentHash && empty($session['revokedAt'])) {
                    $data['memberSessions'][$id]['revokedAt'] = now_iso();
                }
            }
            $this->writeFileStore($data);
        } else {
            $this->pdo()->prepare('UPDATE member_sessions SET revoked_at=NOW() WHERE member_id=? AND token_hash<>? AND revoked_at IS NULL')->execute([(int) $memberId, $currentHash]);
        }
        $this->recordMemberEvent((string) $memberId, 'member.sessions.others_revoked');
    }

    /** @param array<string,mixed> $context */
    public function recordMemberEvent(string|int $memberId, string $event, array $context = []): void
    {
        if ($this->privacyWriteDepth === 0) { $this->privacyMemberWrite((string) $memberId, fn() => $this->recordMemberEvent($memberId, $event, $context), true); return; }
        if ($this->privacyMemberErased($memberId)) return;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['memberEvents'][] = [
                'id' => bin2hex(random_bytes(7)), 'memberId' => (string) $memberId, 'event' => $event,
                'context' => $context, 'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? '',
                'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'createdAt' => now_iso(),
            ];
            $data['memberEvents'] = array_slice((array) $data['memberEvents'], -2000);
            $this->writeFileStore($data);
            return;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO member_account_events (member_id,event_key,context_json,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,NOW())');
        $stmt->execute([(int) $memberId, $event, json_encode($context), $_SERVER['REMOTE_ADDR'] ?? null, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null]);
    }

    /** @return list<array<string,mixed>> */
    public function memberEvents(string|int $memberId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values(array_filter((array) ($data['memberEvents'] ?? []), static fn(array $row): bool => (string) ($row['memberId'] ?? '') === (string) $memberId));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return array_slice($rows, 0, $limit);
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM member_account_events WHERE member_id=? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([(int) $memberId]);
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'memberId' => (string) $row['member_id'], 'event' => $row['event_key'],
            'context' => safe_json_decode((string) ($row['context_json'] ?? '{}')), 'ipAddress' => $row['ip_address'] ?? '',
            'userAgent' => $row['user_agent'] ?? '', 'createdAt' => $row['created_at'],
        ], $stmt->fetchAll());
    }

    /** @param array<string,mixed> $metadata */
    public function queueMemberMail(?string $memberId, string $email, string $subject, string $template, string $body, array $metadata = []): array
    {
        if ($memberId !== null && $this->privacyWriteDepth === 0) return $this->privacyMemberWrite($memberId, fn() => $this->queueMemberMail($memberId, $email, $subject, $template, $body, $metadata));
        if ($memberId !== null) $this->privacyRequireOpenMember($memberId);
        $message = [
            'id' => bin2hex(random_bytes(8)), 'memberId' => $memberId, 'recipientEmail' => strtolower(trim($email)),
            'subject' => $subject, 'template' => $template, 'body' => $body, 'metadata' => $metadata,
            'status' => 'queued', 'lastError' => null, 'createdAt' => now_iso(), 'sentAt' => null,
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['mailOutbox'][(string) $message['id']] = $message;
            $this->writeFileStore($data);
            return $message;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO mail_outbox (member_id,recipient_email,subject,template_key,body_text,metadata_json,status,created_at) VALUES (?,?,?,?,?,?,\'queued\',NOW())');
        $stmt->execute([$memberId !== null ? (int) $memberId : null, $message['recipientEmail'], $subject, $template, $body, json_encode($metadata)]);
        $message['id'] = (string) $this->pdo()->lastInsertId();
        return $message;
    }

    public function updateMailStatus(string|int $messageId, string $status, ?string $error = null): void
    {
        if (!in_array($status, ['queued', 'sent', 'failed', 'skipped'], true)) {
            $status = 'failed';
        }
        $terminal = in_array($status, ['sent', 'failed', 'skipped'], true);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            if (isset($data['mailOutbox'][(string) $messageId])) {
                $data['mailOutbox'][(string) $messageId]['status'] = $status;
                $data['mailOutbox'][(string) $messageId]['lastError'] = $error;
                $data['mailOutbox'][(string) $messageId]['sentAt'] = $status === 'sent' ? now_iso() : null;
                if ($terminal) {
                    // Authentication links contain short-lived raw tokens. Keep delivery diagnostics,
                    // but remove the message payload after the immediate delivery attempt finishes.
                    $data['mailOutbox'][(string) $messageId]['body'] = '[redacted after delivery attempt]';
                    $data['mailOutbox'][(string) $messageId]['metadata'] = [];
                }
                $this->writeFileStore($data);
            }
            return;
        }
        $sql = $terminal
            ? "UPDATE mail_outbox SET status=?,last_error=?,sent_at=?,body_text='[redacted after delivery attempt]',metadata_json='{}' WHERE id=?"
            : 'UPDATE mail_outbox SET status=?,last_error=?,sent_at=? WHERE id=?';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$status, $error, $status === 'sent' ? gmdate('Y-m-d H:i:s') : null, (int) $messageId]);
    }

    /** @return list<array<string,mixed>> */
    public function mailOutbox(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['mailOutbox'] ?? []));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return array_slice($rows, 0, $limit);
        }
        $rows = $this->pdo()->query('SELECT * FROM mail_outbox ORDER BY id DESC LIMIT ' . $limit)->fetchAll();
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'memberId' => $row['member_id'] !== null ? (string) $row['member_id'] : null,
            'recipientEmail' => $row['recipient_email'], 'subject' => $row['subject'], 'template' => $row['template_key'],
            'body' => $row['body_text'], 'metadata' => safe_json_decode((string) ($row['metadata_json'] ?? '{}')),
            'status' => $row['status'], 'lastError' => $row['last_error'], 'createdAt' => $row['created_at'], 'sentAt' => $row['sent_at'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function membershipPlans(bool $activeOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['membershipPlans'] ?? []));
            if ($activeOnly) {
                $rows = array_values(array_filter($rows, static fn(array $plan): bool => (bool) ($plan['isActive'] ?? false)));
            }
            usort($rows, static fn(array $a, array $b): int => ((int) ($a['sortOrder'] ?? 100)) <=> ((int) ($b['sortOrder'] ?? 100)));
            return $rows;
        }
        $sql = 'SELECT * FROM membership_plans' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,id';
        return array_map(fn(array $row): array => $this->mapMembershipPlanRow($row), $this->pdo()->query($sql)->fetchAll());
    }

    public function membershipPlan(string|int $idOrKey): ?array
    {
        $lookup = (string) $idOrKey;
        foreach ($this->membershipPlans(false) as $plan) {
            if ((string) ($plan['id'] ?? '') === $lookup || (string) ($plan['key'] ?? '') === $lookup) {
                return $plan;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $plan */
    public function saveMembershipPlan(array $plan): array
    {
        $name = trim((string) ($plan['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Membership plan name is required.');
        }
        $id = trim((string) ($plan['id'] ?? ''));
        $key = slugify((string) ($plan['key'] ?? $name));
        $interval = (string) ($plan['billingInterval'] ?? 'month');
        if (!in_array($interval, ['month', 'year', 'one_time'], true)) {
            $interval = 'month';
        }
        $record = [
            'id' => $id !== '' ? $id : bin2hex(random_bytes(7)), 'key' => $key, 'name' => $name,
            'description' => trim((string) ($plan['description'] ?? '')), 'billingInterval' => $interval,
            'intervalCount' => max(1, (int) ($plan['intervalCount'] ?? 1)), 'price' => max(0, round((float) ($plan['price'] ?? 0), 2)),
            'currency' => strtoupper(substr((string) ($plan['currency'] ?? 'USD'), 0, 3)), 'trialDays' => max(0, (int) ($plan['trialDays'] ?? 0)),
            'ppvDiscountPercent' => max(0, min(100, round((float) ($plan['ppvDiscountPercent'] ?? 0), 2))),
            'benefits' => array_values(array_filter(array_map('trim', (array) ($plan['benefits'] ?? [])))),
            'isActive' => (bool) ($plan['isActive'] ?? true), 'sortOrder' => (int) ($plan['sortOrder'] ?? 100),
            'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $existing = (array) ($data['membershipPlans'][(string) $record['id']] ?? []);
            $record['createdAt'] = (string) ($existing['createdAt'] ?? $record['createdAt']);
            $data['membershipPlans'][(string) $record['id']] = array_merge($existing, $record);
            $this->writeFileStore($data);
            return $record;
        }
        if ($id !== '' && ctype_digit($id)) {
            $stmt = $this->pdo()->prepare('UPDATE membership_plans SET plan_key=?,name=?,description=?,billing_interval=?,interval_count=?,price=?,currency=?,trial_days=?,ppv_discount_percent=?,benefits_json=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$key, $name, $record['description'], $interval, $record['intervalCount'], $record['price'], $record['currency'], $record['trialDays'], $record['ppvDiscountPercent'], json_encode($record['benefits']), $record['isActive'] ? 1 : 0, $record['sortOrder'], (int) $id]);
            return $this->membershipPlan($id) ?? $record;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO membership_plans (plan_key,name,description,billing_interval,interval_count,price,currency,trial_days,ppv_discount_percent,benefits_json,is_active,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([$key, $name, $record['description'], $interval, $record['intervalCount'], $record['price'], $record['currency'], $record['trialDays'], $record['ppvDiscountPercent'], json_encode($record['benefits']), $record['isActive'] ? 1 : 0, $record['sortOrder']]);
        $record['id'] = (string) $this->pdo()->lastInsertId();
        return $record;
    }

    /** @return list<array<string,mixed>> */
    public function subscriptions(?string $memberId = null): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['subscriptions'] ?? []));
            if ($memberId !== null) {
                $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['memberId'] ?? '') === $memberId));
            }
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }
        $sql = 'SELECT s.*,p.plan_key,p.name AS plan_name,p.benefits_json,p.ppv_discount_percent FROM subscriptions s JOIN membership_plans p ON p.id=s.plan_id';
        $params = [];
        if ($memberId !== null) {
            $sql .= ' WHERE s.member_id=?';
            $params[] = (int) $memberId;
        }
        $sql .= ' ORDER BY s.id DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->mapSubscriptionRow($row), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function activeSubscriptions(string|int $memberId): array
    {
        $now = time();
        return array_values(array_filter($this->subscriptions((string) $memberId), static function (array $subscription) use ($now): bool {
            $status = (string) ($subscription['status'] ?? '');
            if (!in_array($status, ['trialing', 'active', 'past_due'], true)) {
                return false;
            }
            if ($status === 'past_due') {
                $grace = strtotime((string) ($subscription['graceEndsAt'] ?? '')) ?: 0;
                if ($grace <= $now) {
                    return false;
                }
            }
            $end = strtotime((string) ($subscription['currentPeriodEnd'] ?? '')) ?: 0;
            return $end === 0 || $end > $now || $status === 'past_due';
        }));
    }

    /** @param array<string,mixed> $subscription */
    public function saveSubscription(array $subscription): array
    {
        if ($this->privacyWriteDepth === 0) { return $this->privacyMemberWrite((string) (string) ($subscription['memberId'] ?? ''), fn() => $this->saveSubscription($subscription)); }
        $this->privacyRequireOpenMember((string) (string) ($subscription['memberId'] ?? ''));
        $memberId = (string) ($subscription['memberId'] ?? '');
        $planId = (string) ($subscription['planId'] ?? '');
        if (!$this->member($memberId) || !$this->membershipPlan($planId)) {
            throw new RuntimeException('Choose a valid member and membership plan.');
        }
        $status = (string) ($subscription['status'] ?? 'active');
        if (!in_array($status, ['trialing', 'active', 'past_due', 'paused', 'cancelled', 'expired'], true)) {
            $status = 'active';
        }
        $id = (string) ($subscription['id'] ?? '');
        $record = [
            'id' => $id !== '' ? $id : bin2hex(random_bytes(8)), 'memberId' => $memberId, 'planId' => $planId,
            'planName' => (string) ($this->membershipPlan($planId)['name'] ?? ''), 'status' => $status,
            'providerKey' => $subscription['providerKey'] ?? null, 'providerSubscriptionId' => $subscription['providerSubscriptionId'] ?? null,
            'currentPeriodStart' => $subscription['currentPeriodStart'] ?? now_iso(),
            'currentPeriodEnd' => $subscription['currentPeriodEnd'] ?? gmdate('c', time() + 30 * 86400),
            'cancelAtPeriodEnd' => (bool) ($subscription['cancelAtPeriodEnd'] ?? false),
            'graceEndsAt' => $subscription['graceEndsAt'] ?? null, 'cancelledAt' => $subscription['cancelledAt'] ?? null,
            'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $existing = (array) ($data['subscriptions'][(string) $record['id']] ?? []);
            $record['createdAt'] = (string) ($existing['createdAt'] ?? $record['createdAt']);
            $data['subscriptions'][(string) $record['id']] = array_merge($existing, $record);
            $this->writeFileStore($data);
            $this->recordMemberEvent($memberId, 'member.subscription.updated', ['subscriptionId' => $record['id'], 'status' => $status]);
            return $record;
        }
        if ($id !== '' && ctype_digit($id)) {
            $stmt = $this->pdo()->prepare('UPDATE subscriptions SET member_id=?,plan_id=?,provider_key=?,provider_subscription_id=?,status=?,current_period_start=?,current_period_end=?,cancel_at_period_end=?,grace_ends_at=?,cancelled_at=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([(int) $memberId, (int) $planId, $record['providerKey'], $record['providerSubscriptionId'], $status, $this->mysqlDate($record['currentPeriodStart']), $this->mysqlDate($record['currentPeriodEnd']), $record['cancelAtPeriodEnd'] ? 1 : 0, $this->mysqlDate($record['graceEndsAt']), $this->mysqlDate($record['cancelledAt']), (int) $id]);
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO subscriptions (member_id,plan_id,provider_key,provider_subscription_id,status,current_period_start,current_period_end,cancel_at_period_end,grace_ends_at,cancelled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([(int) $memberId, (int) $planId, $record['providerKey'], $record['providerSubscriptionId'], $status, $this->mysqlDate($record['currentPeriodStart']), $this->mysqlDate($record['currentPeriodEnd']), $record['cancelAtPeriodEnd'] ? 1 : 0, $this->mysqlDate($record['graceEndsAt']), $this->mysqlDate($record['cancelledAt'])]);
            $record['id'] = (string) $this->pdo()->lastInsertId();
        }
        $this->recordMemberEvent($memberId, 'member.subscription.updated', ['subscriptionId' => $record['id'], 'status' => $status]);
        return $record;
    }

    /** @return list<string> */
    public function contentPlanIds(string $contentType, string|int $contentId): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $key = $this->contentRuleKey($contentType, $contentId);
            return array_values(array_map('strval', (array) ($data['contentAccessRules'][$key] ?? [])));
        }
        $stmt = $this->pdo()->prepare("SELECT plan_id FROM content_access_rules WHERE content_type=? AND content_id=? AND rule_type='plan_include' AND plan_id IS NOT NULL ORDER BY plan_id");
        $stmt->execute([$contentType, (int) $contentId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<string|int> $planIds */
    public function setContentPlanRules(string $contentType, string|int $contentId, array $planIds): void
    {
        $planIds = array_values(array_unique(array_filter(array_map('strval', $planIds), fn(string $id): bool => $this->membershipPlan($id) !== null)));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['contentAccessRules'][$this->contentRuleKey($contentType, $contentId)] = $planIds;
            $this->writeFileStore($data);
            return;
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM content_access_rules WHERE content_type=? AND content_id=? AND rule_type='plan_include'")->execute([$contentType, (int) $contentId]);
            $stmt = $pdo->prepare("INSERT INTO content_access_rules (content_type,content_id,rule_type,plan_id,value_json,created_at,updated_at) VALUES (?,?,'plan_include',?,NULL,NOW(),NOW())");
            foreach ($planIds as $planId) {
                $stmt->execute([$contentType, (int) $contentId, (int) $planId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function entitlements(string|int $memberId, bool $activeOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values(array_filter((array) ($data['entitlements'] ?? []), static fn(array $row): bool => (string) ($row['memberId'] ?? '') === (string) $memberId));
            if ($activeOnly) {
                $now = time();
                $rows = array_values(array_filter($rows, static function (array $row) use ($now): bool {
                    if ((string) ($row['status'] ?? '') !== 'active') return false;
                    $start = strtotime((string) ($row['startsAt'] ?? '')) ?: 0;
                    $end = strtotime((string) ($row['expiresAt'] ?? '')) ?: 0;
                    return $start <= $now && ($end === 0 || $end > $now);
                }));
            }
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }
        $sql = 'SELECT * FROM content_entitlements WHERE member_id=?';
        if ($activeOnly) {
            $sql .= " AND status='active' AND starts_at<=NOW() AND (expires_at IS NULL OR expires_at>NOW())";
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([(int) $memberId]);
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'], 'memberId' => (string) $row['member_id'], 'contentType' => $row['content_type'],
            'contentId' => (string) $row['content_id'], 'sourceType' => $row['source_type'], 'sourceId' => $row['source_id'] !== null ? (string) $row['source_id'] : null,
            'startsAt' => $row['starts_at'], 'expiresAt' => $row['expires_at'], 'status' => $row['status'],
            'metadata' => safe_json_decode((string) ($row['metadata_json'] ?? '{}')), 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        ], $stmt->fetchAll());
    }

    /** @param array<string,mixed> $metadata */
    public function grantEntitlement(string|int $memberId, string $contentType, string|int $contentId, string $sourceType = 'complimentary', ?string $sourceId = null, ?string $expiresAt = null, array $metadata = []): array
    {
        if ($this->privacyWriteDepth === 0) { return $this->privacyMemberWrite((string) $memberId, fn() => $this->grantEntitlement($memberId, $contentType, $contentId, $sourceType, $sourceId, $expiresAt, $metadata)); }
        $this->privacyRequireOpenMember((string) $memberId);
        if (!$this->member($memberId)) {
            throw new RuntimeException('Member account not found.');
        }
        $record = [
            'id' => bin2hex(random_bytes(8)), 'memberId' => (string) $memberId, 'contentType' => $contentType,
            'contentId' => (string) $contentId, 'sourceType' => $sourceType, 'sourceId' => $sourceId,
            'startsAt' => now_iso(), 'expiresAt' => $expiresAt, 'status' => 'active', 'metadata' => $metadata,
            'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['entitlements'] ?? []) as $id => $existing) {
                if ((string) ($existing['memberId'] ?? '') === (string) $memberId && (string) ($existing['contentType'] ?? '') === $contentType && (string) ($existing['contentId'] ?? '') === (string) $contentId && (string) ($existing['sourceType'] ?? '') === $sourceType && (string) ($existing['status'] ?? '') === 'active') {
                    $record['id'] = (string) $id;
                    $record['createdAt'] = (string) ($existing['createdAt'] ?? $record['createdAt']);
                    break;
                }
            }
            $data['entitlements'][(string) $record['id']] = $record;
            $this->writeFileStore($data);
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO content_entitlements (member_id,content_type,content_id,source_type,source_id,starts_at,expires_at,status,metadata_json,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),?,\'active\',?,NOW(),NOW())');
            $stmt->execute([(int) $memberId, $contentType, (int) $contentId, $sourceType, $sourceId !== null && ctype_digit($sourceId) ? (int) $sourceId : null, $this->mysqlDate($expiresAt), json_encode($metadata)]);
            $record['id'] = (string) $this->pdo()->lastInsertId();
        }
        $this->recordMemberEvent((string) $memberId, 'member.entitlement.granted', ['contentType' => $contentType, 'contentId' => (string) $contentId, 'sourceType' => $sourceType]);
        return $record;
    }

    public function revokeEntitlement(string|int $memberId, string|int $entitlementId): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            if (isset($data['entitlements'][(string) $entitlementId]) && (string) ($data['entitlements'][(string) $entitlementId]['memberId'] ?? '') === (string) $memberId) {
                $data['entitlements'][(string) $entitlementId]['status'] = 'revoked';
                $data['entitlements'][(string) $entitlementId]['updatedAt'] = now_iso();
                $this->writeFileStore($data);
            }
        } else {
            $this->pdo()->prepare("UPDATE content_entitlements SET status='revoked',updated_at=NOW() WHERE id=? AND member_id=?")->execute([(int) $entitlementId, (int) $memberId]);
        }
        $this->recordMemberEvent((string) $memberId, 'member.entitlement.revoked', ['entitlementId' => (string) $entitlementId]);
    }

    public function activeEntitlement(string|int $memberId, string $contentType, string|int $contentId): ?array
    {
        foreach ($this->entitlements($memberId, true) as $entitlement) {
            if ((string) ($entitlement['contentType'] ?? '') === $contentType && (string) ($entitlement['contentId'] ?? '') === (string) $contentId) {
                return $entitlement;
            }
        }
        return null;
    }

    public function memberMediaState(string|int $memberId, string $contentType, string|int $contentId): array
    {
        $key = $this->memberMediaKey($memberId, $contentType, $contentId);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return (array) ($data['memberMediaState'][$key] ?? [
                'memberId' => (string) $memberId, 'contentType' => $contentType, 'contentId' => (string) $contentId,
                'progressSeconds' => 0.0, 'isFavorite' => false, 'viewCount' => 0, 'lastViewedAt' => null,
                'completedAt' => null, 'metadata' => [], 'updatedAt' => null,
            ]);
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM member_media_state WHERE member_id=? AND content_type=? AND content_id=? LIMIT 1');
        $stmt->execute([(int) $memberId, $contentType, (int) $contentId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['memberId' => (string) $memberId, 'contentType' => $contentType, 'contentId' => (string) $contentId, 'progressSeconds' => 0.0, 'isFavorite' => false, 'viewCount' => 0, 'lastViewedAt' => null, 'completedAt' => null, 'metadata' => [], 'updatedAt' => null];
        }
        return $this->mapMemberMediaStateRow($row);
    }

    /** @param array<string,mixed> $changes */
    public function saveMemberMediaState(string|int $memberId, string $contentType, string|int $contentId, array $changes): array
    {
        if ($this->privacyWriteDepth === 0) { return $this->privacyMemberWrite((string) $memberId, fn() => $this->saveMemberMediaState($memberId, $contentType, $contentId, $changes)); }
        $this->privacyRequireOpenMember((string) $memberId);
        $state = $this->memberMediaState($memberId, $contentType, $contentId);
        if (array_key_exists('progressSeconds', $changes)) $state['progressSeconds'] = max(0, (float) $changes['progressSeconds']);
        if (array_key_exists('isFavorite', $changes)) $state['isFavorite'] = (bool) $changes['isFavorite'];
        if (!empty($changes['viewed'])) {
            $state['viewCount'] = (int) ($state['viewCount'] ?? 0) + 1;
            $state['lastViewedAt'] = now_iso();
        } elseif (array_key_exists('lastViewedAt', $changes)) {
            $state['lastViewedAt'] = $changes['lastViewedAt'];
        }
        if (array_key_exists('completedAt', $changes)) $state['completedAt'] = $changes['completedAt'];
        if (isset($changes['metadata']) && is_array($changes['metadata'])) $state['metadata'] = array_merge((array) ($state['metadata'] ?? []), $changes['metadata']);
        $state['updatedAt'] = now_iso();
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['memberMediaState'][$this->memberMediaKey($memberId, $contentType, $contentId)] = $state;
            $this->writeFileStore($data);
            return $state;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO member_media_state (member_id,content_type,content_id,progress_seconds,is_favorite,view_count,last_viewed_at,completed_at,metadata_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE progress_seconds=VALUES(progress_seconds),is_favorite=VALUES(is_favorite),view_count=VALUES(view_count),last_viewed_at=VALUES(last_viewed_at),completed_at=VALUES(completed_at),metadata_json=VALUES(metadata_json),updated_at=NOW()');
        $stmt->execute([(int) $memberId, $contentType, (int) $contentId, $state['progressSeconds'], $state['isFavorite'] ? 1 : 0, (int) $state['viewCount'], $this->mysqlDate($state['lastViewedAt']), $this->mysqlDate($state['completedAt']), json_encode((array) ($state['metadata'] ?? []))]);
        return $state;
    }

    /** @return list<array<string,mixed>> */
    public function memberMediaStates(string|int $memberId, ?string $contentType = null, bool $favoritesOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values(array_filter((array) ($data['memberMediaState'] ?? []), static function (array $state) use ($memberId, $contentType, $favoritesOnly): bool {
                return (string) ($state['memberId'] ?? '') === (string) $memberId
                    && ($contentType === null || (string) ($state['contentType'] ?? '') === $contentType)
                    && (!$favoritesOnly || !empty($state['isFavorite']));
            }));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['lastViewedAt'] ?? $b['updatedAt'] ?? ''), (string) ($a['lastViewedAt'] ?? $a['updatedAt'] ?? '')));
            return $rows;
        }
        $where = ['member_id=?'];
        $params = [(int) $memberId];
        if ($contentType !== null) {
            $where[] = 'content_type=?';
            $params[] = $contentType;
        }
        if ($favoritesOnly) $where[] = 'is_favorite=1';
        $stmt = $this->pdo()->prepare('SELECT * FROM member_media_state WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(last_viewed_at,updated_at) DESC');
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->mapMemberMediaStateRow($row), $stmt->fetchAll());
    }

    public function memberSystemStats(): array
    {
        $members = $this->members();
        $activeSubscriptions = 0;
        foreach ($members as $member) {
            if ($this->activeSubscriptions((string) $member['id']) !== []) $activeSubscriptions++;
        }
        return [
            'members' => count($members),
            'verifiedMembers' => count(array_filter($members, static fn(array $m): bool => !empty($m['emailVerifiedAt']))),
            'activeSubscribers' => $activeSubscriptions,
            'membershipPlans' => count($this->membershipPlans(false)),
        ];
    }

    private function contentRuleKey(string $contentType, string|int $contentId): string
    {
        return strtolower(trim($contentType)) . ':' . (string) $contentId;
    }

    private function memberMediaKey(string|int $memberId, string $contentType, string|int $contentId): string
    {
        return (string) $memberId . ':' . strtolower(trim($contentType)) . ':' . (string) $contentId;
    }

    private function mysqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        $timestamp = strtotime((string) $value);
        return $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    private function deviceName(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        $browser = str_contains($ua, 'edg/') ? 'Edge' : (str_contains($ua, 'chrome/') ? 'Chrome' : (str_contains($ua, 'firefox/') ? 'Firefox' : (str_contains($ua, 'safari/') ? 'Safari' : 'Browser')));
        $device = str_contains($ua, 'iphone') ? 'iPhone' : (str_contains($ua, 'ipad') ? 'iPad' : (str_contains($ua, 'android') ? 'Android' : (str_contains($ua, 'windows') ? 'Windows' : (str_contains($ua, 'mac os') ? 'Mac' : 'Device'))));
        return $browser . ' on ' . $device;
    }

    /** @param array<string,mixed> $row */
    private function mapMemberRow(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'displayName' => $row['display_name'], 'email' => $row['email'],
            'passwordHash' => $row['password_hash'], 'emailVerifiedAt' => $row['email_verified_at'], 'status' => $row['status'],
            'lastLoginAt' => $row['last_login_at'], 'lastSeenAt' => $row['last_seen_at'] ?? null,
            'preferences' => safe_json_decode((string) ($row['preferences_json'] ?? '{}')), 'failedLoginCount' => (int) ($row['failed_login_count'] ?? 0),
            'lockedUntil' => $row['locked_until'] ?? null, 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'], 'deletedAt' => $row['deleted_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapMemberSessionRow(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'memberId' => (string) $row['member_id'], 'tokenHash' => $row['token_hash'],
            'deviceName' => $row['device_name'] ?? 'Device', 'ipAddress' => $row['ip_address'] ?? '', 'userAgent' => $row['user_agent'] ?? '',
            'lastSeenAt' => $row['last_seen_at'], 'createdAt' => $row['created_at'], 'revokedAt' => $row['revoked_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapMembershipPlanRow(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'key' => $row['plan_key'], 'name' => $row['name'], 'description' => $row['description'] ?? '',
            'billingInterval' => $row['billing_interval'], 'intervalCount' => (int) $row['interval_count'], 'price' => (float) $row['price'],
            'currency' => $row['currency'], 'trialDays' => (int) $row['trial_days'], 'ppvDiscountPercent' => (float) $row['ppv_discount_percent'],
            'benefits' => safe_json_decode((string) ($row['benefits_json'] ?? '[]')), 'isActive' => (bool) $row['is_active'],
            'sortOrder' => (int) $row['sort_order'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapSubscriptionRow(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'memberId' => (string) $row['member_id'], 'planId' => (string) $row['plan_id'],
            'planKey' => $row['plan_key'] ?? '', 'planName' => $row['plan_name'] ?? '', 'status' => $row['status'],
            'providerKey' => $row['provider_key'], 'providerSubscriptionId' => $row['provider_subscription_id'],
            'currentPeriodStart' => $row['current_period_start'], 'currentPeriodEnd' => $row['current_period_end'],
            'cancelAtPeriodEnd' => (bool) $row['cancel_at_period_end'], 'graceEndsAt' => $row['grace_ends_at'], 'cancelledAt' => $row['cancelled_at'],
            'benefits' => safe_json_decode((string) ($row['benefits_json'] ?? '[]')), 'ppvDiscountPercent' => (float) ($row['ppv_discount_percent'] ?? 0),
            'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapMemberMediaStateRow(array $row): array
    {
        return [
            'memberId' => (string) $row['member_id'], 'contentType' => $row['content_type'], 'contentId' => (string) $row['content_id'],
            'progressSeconds' => (float) $row['progress_seconds'], 'isFavorite' => (bool) $row['is_favorite'], 'viewCount' => (int) ($row['view_count'] ?? 0),
            'lastViewedAt' => $row['last_viewed_at'], 'completedAt' => $row['completed_at'] ?? null,
            'metadata' => safe_json_decode((string) ($row['metadata_json'] ?? '{}')), 'updatedAt' => $row['updated_at'],
        ];
    }
}
