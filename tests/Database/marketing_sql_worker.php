<?php
declare(strict_types=1);

// Separate-process fixture for marketing_sql_mysql.php. Never customer runtime.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/Installer/database_fixture.php';
$runtime = realpath((string) ($argv[1] ?? ''));
$operation = (string) ($argv[2] ?? '');
$argument = (string) ($argv[3] ?? '');
if (!is_string($runtime) || !str_starts_with($runtime, sys_get_temp_dir() . '/vvx-marketing-sql-')
    || !is_file($runtime . '/.vvx-marketing-sql-runtime')
    || !is_file(dirname($runtime) . '/.vvx-marketing-sql-acceptance')) {
    throw new RuntimeException('A marked, exclusively owned marketing SQL fixture is required.');
}
$work = dirname($runtime);
$configuration = require $runtime . '/config/app.php';
if (($configuration['marketing_sql_acceptance_fixture'] ?? false) !== true
    || !in_array($configuration['db']['host'] ?? '', ['127.0.0.1', '::1'], true)
    || !preg_match('/(?:^|_)test(?:_|$)/i', (string) ($configuration['db']['name'] ?? ''))) {
    throw new RuntimeException('This worker only operates on its loopback, test-named database fixture.');
}
$write = static function (string $name, array $value) use ($work): void {
    if (!preg_match('/\A[a-z0-9-]+\z/D', $name)) throw new RuntimeException('Invalid fixture control file.');
    $target = $work . '/' . $name . '.json';
    $temp = $target . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($temp, 0600);
    rename($temp, $target);
};
$await = static function (string $name, float $seconds = 15.0) use ($work): void {
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        clearstatcache(true, $work . '/' . $name . '.json');
        if (is_file($work . '/' . $name . '.json')) return;
        usleep(10000);
    }
    throw new RuntimeException('Timed out waiting for the fixture barrier: ' . $name);
};

// The SMTP sink binds only loopback and rejects every non-reserved address.
// It records counts and hashes, never recipient addresses, tokens or message text.
if ($operation === 'smtp') {
    if (!in_array($argument, ['unsubscribe', 'pause', 'unknown'], true)) throw new RuntimeException('Unknown SMTP fixture case.');
    $errno = 0; $error = '';
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($listener)) throw new RuntimeException('Could not bind the loopback SMTP fixture.');
    $address = stream_socket_get_name($listener, false);
    $write('smtp-' . $argument . '-ready', ['port' => (int) substr((string) $address, strrpos((string) $address, ':') + 1)]);
    $socket = stream_socket_accept($listener, 15);
    if (!is_resource($socket)) throw new RuntimeException('The campaign worker did not connect to its SMTP fixture.');
    stream_set_timeout($socket, 15);
    fwrite($socket, "220 loopback.example.test ESMTP\r\n");
    $received = 0; $bytes = 0; $complete = false;
    try {
        while (($line = fgets($socket, 4096)) !== false) {
            $line = rtrim($line, "\r\n");
            if (str_starts_with($line, 'EHLO ')) fwrite($socket, "250 loopback.example.test\r\n");
            elseif (str_starts_with($line, 'MAIL FROM:') || str_starts_with($line, 'RCPT TO:')) {
                if (!preg_match('/\A(?:MAIL FROM|RCPT TO):<[A-Za-z0-9._+-]+@example\.test>\z/D', $line)) {
                    throw new RuntimeException('The loopback sink rejected an address outside example.test.');
                }
                if (str_starts_with($line, 'RCPT TO:')) $received++;
                fwrite($socket, "250 accepted reserved fixture\r\n");
            } elseif ($line === 'DATA') {
                $write('smtp-' . $argument . '-waiting', ['handshake' => true, 'recipients' => $received]);
                if ($argument !== 'unknown') $await('smtp-' . $argument . '-release');
                fwrite($socket, "354 End with a single dot\r\n");
                while (($bodyLine = fgets($socket, 8192)) !== false) {
                    if (rtrim($bodyLine, "\r\n") === '.') { $complete = true; break; }
                    $bytes += strlen($bodyLine);
                }
                // A missing final acknowledgement must become unknown, never retryable.
                if ($argument !== 'unknown' && $complete) fwrite($socket, "250 message accepted by local fixture\r\n");
                break;
            } elseif ($line === 'QUIT') { fwrite($socket, "221 goodbye\r\n"); break; }
            else throw new RuntimeException('Unexpected command at the loopback SMTP fixture.');
        }
        $write('smtp-' . $argument . '-result', ['recipients' => $received, 'body_bytes' => $bytes, 'complete' => $complete]);
    } finally { fclose($socket); fclose($listener); }
    echo json_encode(['complete' => $complete, 'body_bytes' => $bytes], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

vvx_installer_fixture_load($runtime, false);
require_once $runtime . '/app/Core/MarketingStore.php';
$storage = new \Velvet\Core\MarketingStorage($runtime);
if ($operation === 'migrate-observe') {
    $calls = 0;
    $write('racing-cutover-started', ['started' => true]);
    $result = $storage->migrate($argument, static function () use (&$calls, $write): void {
        if (++$calls === 1) $write('racing-cutover-authorized', ['authorized' => true]);
    });
    echo json_encode($result + ['authorization_calls' => $calls], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
if ($operation === 'migrate-disconnect') {
    // The public authorization callback is repeated immediately before commit.
    // Pause there while the owning suite disconnects only this worker's newly
    // opened connections in its exclusively owned test schema.
    $review = $storage->status();
    $calls = 0; $rejected = false;
    try {
        $storage->migrate($review['plan'], static function () use (&$calls, $write, $await): void {
            if (++$calls === 2) {
                $write('cutover-before-commit', ['authorization_calls' => $calls]);
                $await('cutover-commit-release');
            }
        });
    } catch (\RuntimeException) { $rejected = true; }
    echo json_encode(['rejected' => $rejected, 'authorization_calls' => $calls], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
$marketing = new \Velvet\Core\MarketingStore($runtime);

if ($operation === 'read') {
    $data = $marketing->all();
    echo json_encode(['state' => $storage->status()['state'], 'digest' => hash('sha256', serialize($data)),
        'events' => count($data['events']), 'contacts' => count($data['contacts'])], JSON_THROW_ON_ERROR) . PHP_EOL;
} elseif ($operation === 'write') {
    if (!ctype_digit($argument) || (int) $argument > 9) throw new RuntimeException('Invalid writer fixture identifier.');
    for ($i = 0; $i < 12; $i++) $marketing->recordEvent('page_view', ['path' => '/sql-writer-' . $argument . '/' . $i]);
    $marketing->subscribe('sql-writer-' . $argument . '@example.test', 'Concurrent fixture', 'SQL writer fixture', true);
    echo json_encode(['events' => 12, 'contacts' => 1], JSON_THROW_ON_ERROR) . PHP_EOL;
} elseif ($operation === 'hold-delivery') {
    $result = $marketing->withDeliveryLock(static function () use ($write, $await): bool {
        $write('delivery-lock-held', ['held' => true]);
        $await('delivery-lock-release');
        return true;
    });
    echo json_encode(['acquired' => $result === true], JSON_THROW_ON_ERROR) . PHP_EOL;
} elseif (in_array($operation, ['held-update', 'held-file-update'], true)) {
    $marketing->update(static function (array &$data) use ($operation, $write, $await): void {
        $beforeCutover = $operation === 'held-file-update';
        $data['posts'][] = ['id' => $beforeCutover ? 'completed-before-cutover' : 'uncommitted-worker-post',
            'title' => $beforeCutover ? 'An in-flight file edit finishes before SQL activation' : 'Must disappear when the process stops'];
        $prefix = $beforeCutover ? 'file-update' : 'update-worker';
        $write($prefix . '-held', ['held' => true]);
        $await($prefix . '-release');
    });
    echo json_encode(['committed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
} elseif ($operation === 'delivery') {
    $mail = (array) ($configuration['mail'] ?? []);
    if (($mail['mode'] ?? '') !== 'disabled' && (($mail['mode'] ?? '') !== 'smtp'
        || ($mail['host'] ?? '') !== '127.0.0.1' || ($mail['encryption'] ?? '') !== 'none'
        || ($mail['username'] ?? '') !== '' || ($mail['password'] ?? '') !== '')) {
        throw new RuntimeException('Campaign acceptance allows disabled mail or the unauthenticated loopback sink only.');
    }
    foreach ($marketing->all()['contacts'] as $contact) {
        if (!str_ends_with((string) ($contact['email'] ?? ''), '@example.test')) throw new RuntimeException('Only reserved fixture contacts may be used.');
    }
    $config = new \App\Core\Config($runtime . '/config/app.php');
    $service = new \App\Core\CampaignDeliveryService($config, $marketing,
        new \App\Core\MailService($config, new \App\Core\Store($config)));
    echo json_encode($service->run(1, $argument !== '' ? $argument : null), JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    throw new RuntimeException('Unknown marketing SQL worker operation.');
}
