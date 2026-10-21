<?php
declare(strict_types=1);

// Private CLI observer and interruption fixture. Never packaged or served.
// Every operation requires the existing owned, installed loopback test runtime.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime') || !is_file($root . '/storage/temp/editorial-access-records.json')) {
    throw new RuntimeException('An initialized, marked disposable scene-schedule runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$saved = require $root . '/config/app.php';
$mode = $ownership['mode'] ?? '';
if (($ownership['runtime'] ?? '') !== $root || !in_array($mode, ['file', 'mysql'], true)
    || ($saved['storage_mode'] ?? '') !== $mode || ($saved['base_url'] ?? '') !== ($ownership['base'] ?? '')
    || parse_url((string) $saved['base_url'], PHP_URL_SCHEME) !== 'http'
    || parse_url((string) $saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== ($ownership['smtp_port'] ?? null)
    || !is_file($root . '/storage/install.lock')) {
    throw new RuntimeException('The exact fixture ownership, configuration or loopback mail capture changed.');
}
$pdo = null;
if ($mode === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || ($ownership['database'] ?? '') !== $db['name']
        || ($saved['db']['host'] ?? '') !== $db['host'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['user'] ?? '') !== $db['user'] || (int) ($saved['db']['port'] ?? 0) !== $db['port']) {
        throw new RuntimeException('Only the owned disposable loopback database is allowed.');
    }
}
$marker = $root . '/storage/temp/scene-schedule-fixture.json';

if ($action !== 'read') {
    define('ROOT_PATH', $root);
    require_once $root . '/includes/autoload.php';
    require_once $root . '/app/Support/functions.php';
    date_default_timezone_set((string) ($saved['timezone'] ?? 'UTC'));
    $store = new App\Core\Store(new App\Core\Config($root . '/config/app.php'));
    if ($action === 'seed') {
        if (is_file($marker)) throw new RuntimeException('The schedule fixture is already initialized.');
        foreach (['hero', 'footer'] as $section) {
            $scene = $store->scene($section);
            $scene['title'] = 'Schedule ' . $section . ' baseline';
            $scene['scheduleFixture'] = ['section' => $section, 'version' => 'baseline', 'retained' => ['unknown', 17]];
            $store->saveScene($section, $scene, true);
            $scene['title'] = 'Scheduled ' . $section . ' snapshot';
            $scene['scheduleFixture']['version'] = 'scheduled';
            $store->saveScene($section, $scene, false);
        }
        file_put_contents($marker, json_encode(['runtime' => $root, 'mode' => $mode], JSON_THROW_ON_ERROR));
        chmod($marker, 0600);
        echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    $owned = is_file($marker) ? json_decode((string) file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR) : [];
    if ($owned !== ['runtime' => $root, 'mode' => $mode]) throw new RuntimeException('Only a seeded schedule fixture can create an interruption.');
    $section = $input['section'] ?? null;
    if (!in_array($section, ['hero', 'footer'], true)) throw new RuntimeException('Choose an owned scene section.');
    if ($action === 'draft-only') {
        if ($store->sceneSchedule($section)['schedule'] !== null) throw new RuntimeException('Finish the owned schedule before preparing a draft-only section.');
        if ($mode === 'file') {
            $store->mutateFileStore(static function (array &$data) use ($section): void {
                if (!isset($data['scenes'][$section]['published'], $data['scenes'][$section]['draft'])) {
                    throw new RuntimeException('The owned baseline and draft are required.');
                }
                unset($data['scenes'][$section]['published']);
            });
        } else {
            $count = $pdo->prepare("SELECT COUNT(*) FROM scenes WHERE section_key = ? AND status = 'published'");
            $count->execute([$section]);
            $publishedRows = (int) $count->fetchColumn();
            if ($publishedRows < 1) throw new RuntimeException('The owned published baseline is required.');
            $delete = $pdo->prepare("DELETE FROM scenes WHERE section_key = ? AND status = 'published'");
            $delete->execute([$section]);
            if ($delete->rowCount() !== $publishedRows) throw new RuntimeException('The exact owned published scene records must be removed.');
        }
        echo json_encode(['draft_only' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($action === 'claim-and-exit') {
        // The actual private durable claim is committed, then this process exits
        // before applying it. No runtime clock or public request is substituted.
        $method = new ReflectionMethod($store, 'claimSceneSchedule');
        $claim = $method->invoke($store, $section, time(), null);
        echo json_encode(['claim' => $claim], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($action === 'expire-claim-lease') {
        // Age only the genuine persisted claim to avoid waiting a production
        // lease interval. The recovery itself runs the unchanged real CLI.
        $key = 'scene_schedule_' . $section;
        $record = $store->setting($key);
        if (!is_array($record) || ($record['schedule']['status'] ?? '') !== 'processing'
            || !is_array($record['schedule']['claim'] ?? null)) throw new RuntimeException('A genuine processing claim is required.');
        $until = $record['schedule']['claim']['until'] ?? null;
        if (!is_int($until)) throw new RuntimeException('The persisted claim lease representation changed.');
        $record['schedule']['claim']['until'] = time() - 5;
        $store->setSetting($key, $record);
        echo json_encode(['expired_claim_lease' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    throw new RuntimeException('Unsupported owned schedule fixture operation.');
}

// Independent committed-state readback: no application bootstrap, Store read,
// session, default initialization, scheduler invocation or add-on execution.
$result = ['mode' => $mode, 'scenes' => [], 'records' => [], 'schedules' => [], 'audit' => [],
    'protected' => [], 'outbox_count' => 0, 'mysql_version' => $pdo?->getAttribute(PDO::ATTR_SERVER_VERSION)];
foreach (['config/app.php', 'storage/install.lock', 'uploads/editorial-existing.bin',
    'storage/private/editorial-existing.bin', 'storage/backups/editorial-existing.txt',
    'storage/private/marketing/data.json'] as $relative) {
    $result['protected'][$relative] = is_file($root . '/' . $relative) ? hash_file('sha256', $root . '/' . $relative) : null;
}
$unrelated = [];
if ($pdo !== null) {
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows = $pdo->query('SELECT * FROM ' . vvx_installer_fixture_quote((string) $table))->fetchAll(PDO::FETCH_ASSOC);
        if (in_array($table, ['scenes', 'scene_versions'], true)) {
            usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            $result['records'][$table] = $rows;
            continue;
        }
        $retained = [];
        foreach ($rows as $row) {
            if ($table === 'settings' && in_array($row['setting_key'], ['scene_schedule_hero', 'scene_schedule_footer'], true)) {
                $result['schedules'][substr($row['setting_key'], strlen('scene_schedule_'))] = json_decode((string) $row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
            } elseif ($table === 'audit_log' && str_starts_with((string) $row['action_key'], 'scene.')) {
                $result['audit'][] = $row;
            } else {
                if (in_array($table, ['members', 'member_sessions'], true)) unset($row['last_seen_at']);
                if ($table === 'admins') unset($row['last_login_at']);
                $retained[] = json_encode($row, JSON_THROW_ON_ERROR);
            }
        }
        sort($retained, SORT_STRING);
        $unrelated[$table] = $retained;
    }
    foreach ($result['records']['scenes'] ?? [] as $row) {
        $result['scenes'][$row['section_key']][$row['status']] = json_decode((string) $row['scene_json'], true, 512, JSON_THROW_ON_ERROR);
    }
    foreach ($result['records']['scene_versions'] ?? [] as $row) {
        $result['scenes'][$row['section_key']]['versions'][] = ['id' => (string) $row['id'],
            'scene' => json_decode((string) $row['scene_json'], true, 512, JSON_THROW_ON_ERROR)];
    }
    $result['outbox_count'] = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    $result['protected']['preserved-file-store'] = hash_file('sha256', $root . '/storage/data.json');
} else {
    $unrelated = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
    $result['scenes'] = $unrelated['scenes'] ?? [];
    $result['records'] = $result['scenes'];
    unset($unrelated['scenes']);
    foreach (['hero', 'footer'] as $section) {
        $key = 'scene_schedule_' . $section;
        if (array_key_exists($key, $unrelated['settings'] ?? [])) {
            $result['schedules'][$section] = $unrelated['settings'][$key];
            unset($unrelated['settings'][$key]);
        }
    }
    $retained = [];
    foreach ($unrelated['audit'] ?? [] as $row) {
        if (str_starts_with((string) ($row['action'] ?? ''), 'scene.')) $result['audit'][] = $row;
        else $retained[] = $row;
    }
    $unrelated['audit'] = $retained;
    foreach ($unrelated['memberSessions'] ?? [] as $id => $row) unset($unrelated['memberSessions'][$id]['lastSeenAt']);
    foreach ($unrelated['members'] ?? [] as $id => $row) unset($unrelated['members'][$id]['lastSeenAt']);
    foreach ($unrelated['admins'] ?? [] as $id => $row) unset($unrelated['admins'][$id]['lastLoginAt']);
    $result['outbox_count'] = count($unrelated['mailOutbox'] ?? []);
}
foreach (['hero', 'footer'] as $section) $result['scenes'][$section]['versions'] ??= [];
$result['unrelated_sha256'] = hash('sha256', json_encode($unrelated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
