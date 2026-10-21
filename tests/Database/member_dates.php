<?php
declare(strict_types=1);

// Included by the runner only after proving exclusive ownership of an empty schema.
if (!isset($pdo, $root, $host, $port, $name, $user, $password, $existing) || $existing !== []
    || !($pdo instanceof PDO) || !preg_match('/(?:^|_)test(?:_|$)/i', $name)) {
    throw new RuntimeException('Member date acceptance requires the guarded database runner.');
}
$memberDateRoot = sys_get_temp_dir() . '/vvx-member-dates-' . bin2hex(random_bytes(8));
mkdir($memberDateRoot . '/config', 0700, true);
$memberDateConfig = new App\Core\Config($memberDateRoot . '/config/app.php');
$memberDateConfig->write(['storage_mode' => 'mysql', 'db' => compact('host', 'port', 'name', 'user', 'password')]);
$memberDateStore = new App\Core\Store($memberDateConfig);
$memberDateZone = date_default_timezone_get();
$memberDateChecks = 0;
$memberDateAssert = static function (bool $ok, string $message) use (&$memberDateChecks): void {
    $memberDateChecks++; if (!$ok) throw new RuntimeException($message);
};
try {
    date_default_timezone_set('Pacific/Auckland');
    $memberDateEpoch = time() - 90;
    $memberDateInstant = (new DateTimeImmutable('@' . $memberDateEpoch))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $memberDateMember = $memberDateStore->createMember(['displayName' => 'Date compatibility fixture',
        'email' => 'date-fixture@example.test', 'password' => 'DateFixturePassword123!', 'status' => 'active',
        'emailVerifiedAt' => $memberDateInstant->format('c')]);
    $memberDateRead = static function () use ($pdo, $memberDateMember): array {
        $query = $pdo->prepare('SELECT email_verified_at,UNIX_TIMESTAMP(email_verified_at) AS verified_epoch FROM members WHERE id=?');
        $query->execute([$memberDateMember['id']]); return $query->fetch();
    };
    $memberDateAssert((int) $memberDateRead()['verified_epoch'] === $memberDateEpoch,
        'ISO verification offset was not preserved as the same actual SQL instant.');
    $memberDateAssert($memberDateStore->databaseMemberVerificationIsCurrent($memberDateMember['id']), 'Recent verification was not eligible.');
    $memberDateBefore = $memberDateRead();
    $memberDateStore->updateMember($memberDateMember['id'], ['displayName' => 'Retained verification']);
    $memberDateAssert($memberDateRead() === $memberDateBefore, 'An unrelated profile edit reinterpreted an existing SQL verification timestamp.');
    $memberDateStore->updateMember($memberDateMember['id'], ['emailVerifiedAt' => gmdate('Y-m-d\TH:i:s\Z', $memberDateEpoch)]);
    $memberDateAssert($memberDateRead() === $memberDateBefore, 'UTC and offset representations of the same verification instant differ.');
    $memberDateStore->updateMember($memberDateMember['id'], ['emailVerifiedAt' => gmdate('c', time() + 3600)]);
    $memberDateAssert(!$memberDateStore->databaseMemberVerificationIsCurrent($memberDateMember['id']), 'A future SQL verification granted access.');
    foreach ([[], true, '2026-02-30T12:00:00+00:00', 'today', '2026-02-30 12:00:00'] as $invalid) {
        $before = $memberDateRead(); $refused = false;
        try { $memberDateStore->updateMember($memberDateMember['id'], ['emailVerifiedAt' => $invalid]); }
        catch (RuntimeException) { $refused = true; }
        $memberDateAssert($refused && $memberDateRead() === $before, 'Invalid verification time changed the saved SQL member.');
    }
    $memberDateStore->updateMember($memberDateMember['id'], ['emailVerifiedAt' => null]);
    $memberDateAssert($memberDateRead()['email_verified_at'] === null, 'Explicit removal of verification did not persist.');
    $memberDateStore->markMemberVerified($memberDateMember['id']);
    $memberDateAssert(abs((int) $memberDateRead()['verified_epoch'] - time()) <= 3
        && $memberDateStore->databaseMemberVerificationIsCurrent($memberDateMember['id']),
        'The production verification action did not persist the current instant.');
    echo 'Member verification date compatibility passed (' . $memberDateChecks . ' checks; server '
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ").\n";
} finally {
    date_default_timezone_set($memberDateZone);
    foreach (glob($memberDateRoot . '/config/*') ?: [] as $file) unlink($file);
    @unlink($memberDateRoot . '/config/.vvx-config.lock');
    rmdir($memberDateRoot . '/config'); rmdir($memberDateRoot);
}
