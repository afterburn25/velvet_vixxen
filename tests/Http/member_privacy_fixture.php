<?php
declare(strict_types=1);

// CLI-only setup and fault injection in a marked, isolated extracted package.
$root = realpath((string) ($argv[1] ?? '')); $action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !$root || !str_starts_with($root, sys_get_temp_dir() . '/vvx-protection-')
    || !is_file($root . '/.vvx-test-runtime') || !is_file($root . '/storage/temp/editorial-access-ownership.json')) throw new RuntimeException('An owned temporary privacy acceptance runtime is required.');
$owned = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$saved = require $root . '/config/app.php'; $pdo = null;
if (($owned['runtime'] ?? '') !== $root || ($saved['storage_mode'] ?? '') !== ($owned['mode'] ?? '')
    || ($saved['base_url'] ?? '') !== ($owned['base'] ?? '') || parse_url($saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['host'] ?? '') !== '127.0.0.1' || ($saved['mail']['port'] ?? '') !== ($owned['smtp_port'] ?? '')
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || !in_array($owned['mode'], ['file', 'mysql'], true)) throw new RuntimeException('The exact loopback fixture configuration is required.');
if ($owned['mode'] === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php'; [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || $db['name'] !== ($owned['database'] ?? '') || $saved['db']['name'] !== $db['name']
        || $saved['db']['user'] !== $db['user'] || (int) $saved['db']['port'] !== $db['port']) throw new RuntimeException('Only the owned test database is allowed.');
}
define('ROOT_PATH', $root); require_once $root . '/includes/bootstrap.php'; require_once $root . '/app/Core/MarketingStore.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$config = app_config(); $store = new App\Core\Store($config); $state = new App\Core\PrivateState($config, $root);
$privacy = new App\Core\MemberPrivacyService($config, $store, $state); $marketing = new Velvet\Core\MarketingStore($root);
$recordsFile = $root . '/storage/temp/member-privacy-records.json';
if ($action === 'seed') {
    if (is_file($recordsFile) || $store->member('privacy@example.test')) throw new RuntimeException('Privacy fixture is already initialized.');
    // This CLI owns the marked disposable installation. The Apache user owns
    // package directories already; tighten its new private workspace before
    // creating CLI fixture records, then the harness returns all ownership.
    if (!chmod($root . '/storage/private', 0700)) throw new RuntimeException('The owned private fixture directory could not be protected.');
    $records = [];
    foreach (['privacy', 'privacy-other'] as $label) {
        $record = $store->createMember(['displayName' => ucfirst($label) . ' member', 'email' => $label . '@example.test', 'password' => 'VelvetMember12',
            'status' => 'active', 'emailVerifiedAt' => gmdate('c', time() - 10), 'preferences' => ['emailUpdates' => true, 'newContentAlerts' => true, 'fixture_private' => 'PRIVATE_PREFERENCE']]);
        $records[$label === 'privacy' ? 'member' : 'other'] = $record['id'];
        $marketing->subscribe($record['email'], ucfirst($label), 'owned privacy acceptance', true);
    }
    $member = $store->member($records['member']);
    $directory = $root . '/storage/private/communications'; if (!is_dir($directory)) mkdir($directory, 0700, true);
    foreach (['visible.txt' => 'PRIVACY_VISIBLE_BYTES', 'internal.txt' => 'PRIVATE_STAFF_ATTACHMENT', 'request.txt' => 'PRIVACY_REQUEST_BYTES', 'request-staff.txt' => 'PRIVATE_REQUEST_STAFF', 'shared.txt' => 'SHARED_MEMBER_BYTES'] as $name => $bytes) { file_put_contents($directory . '/' . $name, $bytes); chmod($directory . '/' . $name, 0600); }
    $attachment = static fn(string $name): array => ['filePath' => 'private:communications/' . $name, 'originalName' => $name, 'mimeType' => 'text/plain', 'fileSize' => filesize($directory . '/' . $name)];
    $thread = $store->createCommunicationThread(['memberId' => $records['member'], 'subject' => 'Privacy-owned conversation', 'internalNotes' => 'PRIVATE_STAFF_NOTE']);
    $message = $store->sendCommunicationMessage(['threadId' => $thread['id'], 'body' => 'Owned member message', 'attachments' => [$attachment('visible.txt')]]);
    $internal = $store->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'system', 'visibility' => 'internal', 'body' => 'PRIVATE_INTERNAL_MESSAGE', 'attachments' => [$attachment('internal.txt')]]);
    $booking = $store->createBookingRequest(['memberId' => $records['member'], 'contactName' => 'Privacy member', 'contactEmail' => $member['email'], 'details' => 'Owned request body']);
    $requestFile = $store->addRequestAttachment('booking_request', $booking['id'], $attachment('request.txt'), 'member');
    $staffRequestFile = $store->addRequestAttachment('booking_request', $booking['id'], $attachment('request-staff.txt'), 'admin');
    $guest = $store->createBookingRequest(['contactName' => 'Unlinked guest', 'contactEmail' => $member['email'], 'details' => 'UNRELATED_GUEST_DATA']); $records['guest'] = $guest['id'];
    $otherThread = $store->createCommunicationThread(['memberId' => $records['other'], 'subject' => 'Unrelated member conversation']);
    $store->sendCommunicationMessage(['threadId' => $otherThread['id'], 'body' => 'UNRELATED_OTHER_MESSAGE', 'attachments' => [$attachment('shared.txt')]]);
    $store->sendCommunicationMessage(['threadId' => $thread['id'], 'body' => 'Owned reference to shared file', 'attachments' => [$attachment('shared.txt')]]);
    $order = $store->createOrder(['memberId' => $records['member'], 'status' => 'paid', 'grandTotal' => 41.19, 'metadata' => ['private' => 'PRIVATE_ORDER_METADATA']], [['itemType' => 'video', 'itemId' => '1', 'title' => 'Account purchase', 'unitPrice' => 41.19, 'lineTotal' => 41.19]]);
    $transaction = $store->savePaymentTransaction(['orderId' => $order['id'], 'amount' => 41.19, 'status' => 'paid', 'last4' => '5678', 'payload' => ['private' => 'PRIVATE_PROVIDER_PAYLOAD']]);
    // The complete production read sees all 1,007 rows. Fixture cloning avoids
    // spending acceptance time on repeated identical checkout setup.
    if ($pdo) {
        $columns = array_column($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $columns = array_values(array_diff($columns, ['id', 'order_number']));
        $names = implode(',', array_map(static fn(string $name): string => '`' . $name . '`', $columns));
        $copy = $pdo->prepare('INSERT INTO orders (`order_number`,' . $names . ') SELECT ?,' . $names . ' FROM orders WHERE id=?');
        $pdo->beginTransaction(); for ($i = 1; $i < 1007; ++$i) $copy->execute(['PRIVACY-HTTP-' . $i, $order['id']]); $pdo->commit();
    } else $store->mutateFileStore(static function (array &$data) use ($order): void {
        for ($i = 1; $i < 1007; ++$i) { $copy = $data['orders'][$order['id']]; $copy['id'] = 'privacy-http-order-' . $i; $copy['orderNumber'] = 'PRIVACY-HTTP-' . $i; $copy['items'] = []; $data['orders'][$copy['id']] = $copy; }
    });
    $store->queueMemberMail($records['member'], $member['email'], 'PRIVATE_MAIL_SUBJECT', 'account_notice', 'PRIVATE_MAIL_BODY', ['private' => 'PRIVATE_MAIL_METADATA']);
    $notice = new App\Core\NotificationService($config, $store, null, $state); $notice->enqueue('renewal', 'order:' . $order['id'], $records['member'], ['kind' => 'order', 'object_id' => $order['id']]);
    $provider = new App\Core\MailProviderService($config, $store, $state);
    $event = (new ReflectionMethod($provider, 'normalizeEvent'))->invoke($provider, ['sg_event_id' => 'privacy-http-event', 'event' => 'delivered', 'timestamp' => time(), 'email' => $member['email'], 'smtp-id' => '<vvx.' . str_repeat('e', 64) . '@mail.velvet-vixxen.invalid>']);
    $state->update('mail_provider', static function (array &$data) use ($event): void { $data = ['schema' => 1, 'settings' => ['enabled' => false, 'public_key' => '', 'revision' => 0], 'events' => [$event['id'] => $event]]; });
    $report = (new App\Core\ProtectionRecords($config, $state))->report(['category' => 'privacy', 'label' => 'Owned privacy report', 'name' => 'Privacy member', 'email' => $member['email'], 'description' => 'My own report text.', 'url' => '', 'accurate' => true], 'privacy-http-fixture', $member);
    $protection = json_decode((string) file_get_contents($root . '/storage/temp/protection-fixture-records.json'), true, 512, JSON_THROW_ON_ERROR); $vault = new App\Core\SecureValue($config);
    $state->update('protection', static function (array &$data) use ($report, $vault, $protection): void {
        $row = &$data['cases'][$report['id']]; $row['hold'] = true; $row['targets'] = [['kind' => 'album', 'id' => $protection['album']]]; $row['status'] = 'taken_down';
        $row['events'][] = ['id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'), 'type' => 'takedown', 'actor_id' => 'owner', 'message_encrypted' => $vault->encrypt('PRIVATE_RETAINED_SAFETY_EVIDENCE'), 'public_message_encrypted' => $vault->encrypt('Your concern is retained for review.')];
    });
    $core = $store->privacyCoreData($records['member']);
    $records += ['order' => $order['id'], 'transaction' => $transaction['id'], 'report' => $report['id'], 'report_receipt' => $report['access_key'],
        'message_attachment' => array_values(array_filter($core['messageAttachments'], static fn(array $row): bool => $row['originalName'] === 'visible.txt'))[0]['id'],
        'internal_attachment' => array_values(array_filter($core['messageAttachments'], static fn(array $row): bool => $row['originalName'] === 'internal.txt'))[0]['id'],
        'request_attachment' => $requestFile['id'], 'staff_request_attachment' => $staffRequestFile['id']];
    file_put_contents($recordsFile, json_encode($records, JSON_THROW_ON_ERROR)); chmod($recordsFile, 0600);
    echo json_encode($records, JSON_THROW_ON_ERROR); exit;
}
if (!is_file($recordsFile)) throw new RuntimeException('Seed the owned fixture first.');
$records = json_decode((string) file_get_contents($recordsFile), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'snapshot') {
    $core = $store->privacyCoreData($records['member']); $raw = $state->read('member_privacy'); $data = $marketing->all();
    if ($pdo) {
        $durable = []; foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) { if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $table)) throw new RuntimeException(); $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC); usort($rows, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b))); $durable[$table] = $rows; }
        $savedPrivacy = $pdo->query("SELECT payload_json FROM private_state WHERE namespace='member_privacy'")->fetchColumn();
    } else {
        $durable = ['core' => file_get_contents($root . '/storage/data.json'), 'marketing' => $data, 'private' => []];
        foreach (glob($root . '/storage/private/operations/*.json') ?: [] as $path) $durable['private'][basename($path)] = file_get_contents($path);
        $savedPrivacy = is_file($root . '/storage/private/operations/member_privacy.json') ? file_get_contents($root . '/storage/private/operations/member_privacy.json') : false;
    }
    $files = []; foreach (glob($root . '/storage/private/communications/*') ?: [] as $path) if (is_file($path)) $files[basename($path)] = hash_file('sha256', $path);
    $financial = []; foreach ($core['orders'] as $row) $financial[] = App\Core\MemberPrivacyProjection::fields($row, ['id', 'orderNumber', 'status', 'subtotal', 'discountTotal', 'taxTotal', 'grandTotal', 'refundedTotal', 'currency', 'createdAt', 'paidAt']);
    $case = $state->read('protection')['cases'][$records['report']];
    echo json_encode(['journal' => $raw, 'committed_privacy' => $savedPrivacy, 'all_sha256' => hash('sha256', json_encode($durable, JSON_THROW_ON_ERROR)),
        'mysql_version' => $pdo?->query('SELECT VERSION()')->fetchColumn(), 'mode' => $owned['mode'], 'member' => App\Core\MemberPrivacyProjection::fields($core['member'], ['id', 'email', 'status', 'preferences']),
        'order_count' => count($core['orders']), 'financial_sha256' => hash('sha256', json_encode($financial, JSON_THROW_ON_ERROR)),
        'other_sha256' => hash('sha256', json_encode($store->privacyCoreData($records['other']), JSON_THROW_ON_ERROR)), 'guest_sha256' => hash('sha256', json_encode($store->bookingRequest($records['guest']), JSON_THROW_ON_ERROR)),
        'files' => $files, 'reports' => ['hold' => $case['hold'], 'status' => $case['status'], 'reporter' => $case['reporter_member_id'], 'name' => $case['name_encrypted'], 'evidence' => (new App\Core\SecureValue($config))->decrypt($case['events'][1]['message_encrypted'])],
        'notification_count' => count((new App\Core\NotificationService($config, $store, null, $state))->privacyRows($records['member'])),
        'provider' => (new App\Core\MailProviderService($config, $store, $state))->privacyRows(hash('sha256', 'privacy@example.test')),
        'newsletter_contact_count' => count(array_filter($data['contacts'], static fn(array $row): bool => ($row['email'] ?? '') === 'privacy@example.test'))], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'change-inventory') { $store->recordMemberEvent($records['member'], 'privacy.fixture.inventory'); echo '{}'; exit; }
if ($action === 'pause-delayed-writer') {
    // A real earlier process retains a member and financial callback payload,
    // then attempts ordinary production writes after account closure finishes.
    $oldMember = $store->member($records['member']); $oldTransaction = $store->paymentTransaction($records['transaction']);
    if (!$oldMember || $store->privacyMemberErased($records['member']) || !$oldTransaction) throw new RuntimeException('The paused writer must start before privacy freeze.');
    file_put_contents($root . '/storage/temp/privacy-writer-ready', 'ready'); $deadline = microtime(true) + 120;
    while (!is_file($root . '/storage/temp/privacy-writer-release') && microtime(true) < $deadline) usleep(50000);
    if (!is_file($root . '/storage/temp/privacy-writer-release')) throw new RuntimeException('The owned delayed writer timed out.');
    $blocked = false;
    try { $store->updateMember($oldMember['id'], ['displayName' => $oldMember['displayName'] . ' RECREATED_PRIVATE']); }
    catch (RuntimeException $caught) { $blocked = $caught->getCode() === 409; }
    $oldTransaction['payload'] = ['member' => $oldMember, 'private' => 'RECREATED_PRIVATE']; $oldTransaction['last4'] = '5432';
    try { $savedTransaction = $store->savePaymentTransaction($oldTransaction); }
    catch (RuntimeException $caught) {
        if ($owned['mode'] !== 'file' || $caught->getMessage() !== 'File data changed in another request. Reload and try again.') throw $caught;
        // File CAS may reject the old process snapshot entirely. A genuine
        // callback retry gets current storage and still cannot restore payloads.
        $savedTransaction = (new App\Core\Store($config))->savePaymentTransaction($oldTransaction);
    }
    echo json_encode(['profile_blocked' => $blocked, 'provider_payload_scrubbed' => empty($savedTransaction['payload']) && empty($savedTransaction['last4']),
        'member_still_erased' => $store->privacyMemberErased($oldMember['id'])], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'pause-worker') {
    $stage = $input['stage'] ?? null;
    if (!in_array($stage, array_merge(['export'], App\Core\MemberPrivacyService::STAGES), true)) throw new RuntimeException('Choose an owned fault-injection stage.');
    $service = new App\Core\MemberPrivacyService($config, $store, $state, static function (string $completed, string $id) use ($stage, $root): void {
        if ($completed !== $stage) throw new RuntimeException('The owned worker reached an unexpected stage.');
        file_put_contents($root . '/storage/temp/privacy-stage-ready.json', json_encode(['stage' => $completed, 'id' => $id], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 45;
        while (!is_file($root . '/storage/temp/privacy-stage-release') && microtime(true) < $deadline) usleep(50000);
        if (!is_file($root . '/storage/temp/privacy-stage-release')) throw new RuntimeException('Owned pause deadline reached.');
    });
    echo json_encode($service->run(1), JSON_THROW_ON_ERROR); exit;
}
if (in_array($action, ['tamper-archive', 'restore-archive', 'expire-archive'], true)) {
    $id = App\Core\MemberPrivacyArchive::id((string) ($input['id'] ?? '')); $path = $root . '/storage/private/member-exports/' . $id . '.zip';
    $row = $state->read('member_privacy')['requests'][$id] ?? null;
    if (!$row || $row['member_id'] !== $records['member'] || $row['status'] !== 'complete') throw new RuntimeException('Choose the completed owned export.');
    if ($action === 'expire-archive') $state->update('member_privacy', static function (array &$data) use ($id): void { $data['requests'][$id]['archive']['expires_at'] = time() - 1; });
    else {
        $backup = $root . '/storage/temp/privacy-archive-baseline.zip';
        if ($action === 'tamper-archive') { $bytes = file_get_contents($path); if (substr_count($bytes, 'PRIVACY_VISIBLE_BYTES') !== 1) throw new RuntimeException('The complete stored fixture entry is absent.'); file_put_contents($backup, $bytes); $bytes = str_replace('PRIVACY_VISIBLE_BYTES', 'BROKENY_VISIBLE_BYTES', $bytes); }
        else { $bytes = file_get_contents($backup); unlink($backup); }
        file_put_contents($path, $bytes); chmod($path, 0600);
        // Updating the outer hash makes this a genuine independent ZIP CRC test.
        $state->update('member_privacy', static function (array &$data) use ($id, $bytes): void { $data['requests'][$id]['archive']['sha256'] = hash('sha256', $bytes); });
    }
    echo '{}'; exit;
}
if ($action === 'late-writers') {
    $checks = [];
    foreach (['profile' => fn() => $store->updateMember($records['member'], ['displayName' => 'RECREATED_PRIVATE']),
        'newsletter' => fn() => $marketing->subscribe('privacy@example.test', 'RECREATED_PRIVATE', 'delayed', true),
        'notification' => fn() => (new App\Core\NotificationService($config, $store, null, $state))->enqueue('renewal', 'order:' . $records['order'], $records['member'], ['kind' => 'order', 'object_id' => $records['order']])] as $key => $call) {
        try { $call(); $checks[$key] = false; } catch (Throwable) { $checks[$key] = true; }
    }
    $transaction = $store->savePaymentTransaction(['id' => $records['transaction'], 'orderId' => $records['order'], 'amount' => 41.19, 'status' => 'paid', 'payload' => ['personal' => 'RECREATED_PRIVATE'], 'last4' => '9876']);
    $checks['provider_payload_scrubbed'] = empty($transaction['payload']) && empty($transaction['last4']);
    echo json_encode($checks, JSON_THROW_ON_ERROR); exit;
}
throw new RuntimeException('Unknown owned privacy fixture action.');
