<?php
declare(strict_types=1);

// Included only after migrations_mysql.php has verified an empty, explicitly named test database.
if (!isset($pdo, $root, $host, $port, $name, $user, $password)
    || !($pdo instanceof PDO) || !preg_match('/(?:^|_)test(?:_|$)/i', $name)) {
    throw new RuntimeException('Booking database scenarios require the guarded test migration runner.');
}
require_once $root . '/includes/installation_recovery.php';
require_once $root . '/app/Support/functions.php';
$bookingTestConfigPath = tempnam(sys_get_temp_dir(), 'vvx-booking-db-test-');
if ($bookingTestConfigPath === false) throw new RuntimeException('Unable to create a disposable database test config.');
$bookingTestChecks = 0;
$bookingTestAssert = static function (bool $condition, string $message) use (&$bookingTestChecks): void {
    if (!$condition) throw new RuntimeException($message);
    $bookingTestChecks++;
};
$bookingTestFailed = static function (callable $action, string $message) use ($bookingTestAssert): void {
    $failed = false;
    try { $action(); } catch (RuntimeException|PDOException) { $failed = true; }
    $bookingTestAssert($failed, $message);
};
try {
    file_put_contents($bookingTestConfigPath, "<?php\nreturn " . var_export(['storage_mode' => 'mysql',
        'db' => compact('host', 'port', 'name', 'user', 'password') + ['charset' => 'utf8mb4']], true) . ";\n");
    chmod($bookingTestConfigPath, 0600);
    $bookingTestStore = new \App\Core\Store(new \App\Core\Config($bookingTestConfigPath));
    $bookingTestBuilder = new \App\Core\BookingFormService($bookingTestStore);
    $bookingTestGood = ['inquiry_type' => 'appearance', 'contact_name' => 'Database Fixture',
        'contact_email' => 'database-booking@example.test', 'phone' => '3374508598', 'details' => 'Verified database inquiry'];
    $bookingTestLegacy = $bookingTestStore->createBookingRequest(['contactName' => 'Earlier Guest',
        'contactEmail' => 'earlier@example.test', 'details' => 'Earlier inquiry remains available']);
    $bookingTestSaved = $bookingTestStore->createBookingRequest(['formValues' => $bookingTestGood,
        'formRevision' => $bookingTestBuilder->definition()['revision']]);
    $bookingTestSnapshot = $bookingTestSaved['formSubmission'];
    $bookingTestAssert($bookingTestSaved['contactEmail'] === 'database-booking@example.test', 'MySQL booking contact data did not persist.');
    $bookingTestAssert($bookingTestSnapshot['values']['phone'] === '(337) 450-8598', 'MySQL form snapshot did not preserve validated answers.');
    $bookingTestAssert(count($bookingTestSnapshot['fields']) === 9, 'MySQL inquiry did not preserve the field definition.');
    $bookingTestAssert(count($bookingTestStore->communicationMessages($bookingTestSaved['threadId'])) === 1,
        'MySQL booking, snapshot, thread and first message did not commit together.');
    $bookingTestBuilder->saveField(['id' => 'details', 'label' => 'Future event brief', 'type' => 'textarea',
        'required' => false, 'enabled' => true], $bookingTestBuilder->definition()['revision']);
    $bookingTestBuilder->deleteField('phone', $bookingTestBuilder->definition()['revision']);
    $bookingTestAssert($bookingTestStore->bookingRequest($bookingTestSaved['id'])['formSubmission'] === $bookingTestSnapshot,
        'MySQL owner edits changed a historical inquiry snapshot.');
    $bookingTestAssert($bookingTestStore->bookingRequest($bookingTestLegacy['id'])['formSubmission'] === null,
        'Legacy MySQL booking unexpectedly acquired a new form snapshot.');

    $bookingTestCounts = static function () use ($pdo): array {
        $counts = [];
        foreach (['booking_requests', 'booking_form_submissions', 'message_threads', 'messages', 'request_attachments', 'message_attachments'] as $table) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        return $counts;
    };
    $bookingTestBeforeFailure = $bookingTestCounts();
    // The message insert follows the booking, snapshot and thread inserts. Hide its
    // table to fail at that point without CREATE TRIGGER/SUPER privileges. Keep
    // fixture DDL outside the application transaction and always restore the table.
    $bookingTestMessageFailure = null;
    $pdo->exec('RENAME TABLE messages TO messages_fixture_hidden');
    try {
        try {
            $bookingTestStore->createBookingRequest(['formValues' => $bookingTestGood]);
        } catch (PDOException $error) {
            $bookingTestMessageFailure = $error;
        }
    } finally {
        $pdo->exec('RENAME TABLE messages_fixture_hidden TO messages');
    }
    $bookingTestAssert($bookingTestMessageFailure instanceof PDOException
        && $bookingTestMessageFailure->getCode() === '42S02'
        && (int) ($bookingTestMessageFailure->errorInfo[1] ?? 0) === 1146
        && str_contains($bookingTestMessageFailure->getMessage(), $name . '.messages'),
        'The booking did not reach the injected missing-message-table database failure.');
    $bookingTestAssert($bookingTestCounts() === $bookingTestBeforeFailure,
        'Failed MySQL booking left a partial inquiry, snapshot, thread, message or attachment.');
    $bookingTestLong = $bookingTestStore->createBookingRequest(['formValues' => array_replace($bookingTestGood, ['details' => str_repeat('x', 10000)])]);
    $bookingTestAssert(strlen($bookingTestLong['formSubmission']['values']['details']) === 10000,
        'Long MySQL booking answers were lost or rejected.');

    // An old database remains readable while the pending builder migration has not been applied.
    $pdo->exec('RENAME TABLE booking_form_submissions TO booking_form_submissions_fixture_hidden');
    try {
        $bookingTestAssert($bookingTestStore->bookingRequest($bookingTestLegacy['id'])['details'] === 'Earlier inquiry remains available',
            'Pending booking migration broke historical inquiry reads.');
        $bookingTestBeforeMissing = (int) $pdo->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn();
        $bookingTestFailed(static fn() => $bookingTestStore->createBookingRequest(['formValues' => $bookingTestGood]),
            'New builder submissions silently bypassed a missing migration.');
        $bookingTestAssert((int) $pdo->query('SELECT COUNT(*) FROM booking_requests')->fetchColumn() === $bookingTestBeforeMissing,
            'Missing migration left a partial new booking.');
    } finally {
        $pdo->exec('RENAME TABLE booking_form_submissions_fixture_hidden TO booking_form_submissions');
    }
    echo "Database booking form scenarios passed ({$bookingTestChecks} checks, atomic rollback and immutable snapshots; server "
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ").\n";
} finally {
    @unlink($bookingTestConfigPath);
}
