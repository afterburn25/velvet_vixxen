<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\SceneSchedule;
use App\Core\Store;

$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-scene-schedules-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true);
mkdir($fixture . '/storage', 0700, true);
define('ROOT_PATH', $fixture);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require $source . '/app/Support/functions.php';
file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export([
    'storage_mode' => 'file', 'file_store_path' => 'storage/data.json',
], true) . ';');
date_default_timezone_set('UTC');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $action, string $message, ?int $code = null) use ($assert): void {
    try { $action(); }
    catch (InvalidArgumentException|RuntimeException $error) {
        $assert($code === null || $error->getCode() === $code, $message . ': correct error code');
        return;
    }
    $assert(false, $message);
};
$path = $fixture . '/storage/data.json';
$owner = ['id' => 1, 'email' => 'owner@example.test'];
$other = ['id' => 2, 'email' => 'editor@example.test'];
$store = new Store(new Config($fixture . '/config/app.php'));
$raw = static fn(): array => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$snapshot = static fn(string $title): array => ['title' => $title, 'background' => ['src' => 'images/defaults/hero-pink-room.jpg'],
    'devices' => ['desktop' => ['height' => 640, 'layers' => []], 'tablet' => ['height' => 700, 'layers' => []], 'mobile' => ['height' => 820, 'layers' => []]]];
$input = static function (string $section, string $start = '2030-01-01T12:00:00', string $end = '2030-01-01T13:00:00', string $policy = 'restore_previous') use ($store): array {
    $state = $store->sceneSchedule($section);
    return ['startsAt' => $start, 'endsAt' => $end, 'returnPolicy' => $policy,
        'expectedRevision' => $state['revision'], 'expectedDraftRevision' => $state['draftRevision'], 'expectedPublishedRevision' => $state['publishedRevision']];
};
$start = strtotime('2030-01-01T12:00:00Z');
$end = strtotime('2030-01-01T13:00:00Z');
$strip = static function (array $scene): array { unset($scene['updatedAt']); return $scene; };
$cancel = static function (string $section) use ($store, $owner): void {
    $state = $store->sceneSchedule($section);
    if ($state['schedule'] !== null) $store->cancelSceneSchedule($section, $state['schedule']['id'], $state['revision'], $owner);
};
$seed = static function (string $section = 'hero') use ($store, $snapshot, $owner, $cancel): void {
    $cancel($section);
    $store->saveScene($section, $snapshot('Baseline ' . $section), true, 1, $owner);
    $store->saveScene($section, $snapshot('Scheduled ' . $section), false, 1, $owner);
};

try {
    $empty = $store->sceneSchedule('hero');
    $assert($empty['schedule'] === null && !$empty['hasDraft'], 'Missing-store metadata must remain readable.');
    $assert($store->sceneScheduleCalendarRecords() === ['hero' => [], 'footer' => []], 'Empty calendar metadata must have no jobs.');
    $assert(!is_file($path) && !is_file($path . '.lock'), 'Schedule and calendar reads must not initialize storage or a lock.');
    $reject(fn() => $store->scheduleScene('hero', $input('hero'), $owner), 'Missing owner must not initialize storage', 403);
    $assert(!is_file($path), 'Unauthorized schedule creation must not initialize data.');
    file_put_contents($path, json_encode(['settings' => [], 'scenes' => [], 'admins' => [
        ['id' => 1, 'email' => $owner['email'], 'role' => 'owner', 'is_active' => true],
        ['id' => 2, 'email' => $other['email'], 'role' => 'editor', 'is_active' => true],
    ], 'audit' => []], JSON_THROW_ON_ERROR));

    date_default_timezone_set('America/Chicago');
    $assert(SceneSchedule::formDate('2030-01-01T12:00') === '2030-01-01T18:00:00+00:00', 'Local scheduling must store UTC.');
    foreach (['2030-03-10T02:30:00', '2030-11-03T01:30:00', '2030-02-30T12:00', 'tomorrow', '2030-01-01T25:00',
        '2030-01-01T12:00:00Z', '2030-01-01T12:00:00-06:00', '', null, [], 123, '9999-01-01T12:00'] as $bad) {
        $reject(fn() => SceneSchedule::formDate($bad), 'Malformed/skipped/ambiguous local time must fail');
    }
    $assert(SceneSchedule::formDate('2030-11-03T02:30') === '2030-11-03T08:30:00+00:00', 'A time after the repeated hour must work.');
    date_default_timezone_set('UTC');
    $assert(SceneSchedule::formDate('2030-01-01T12:00:01') === '2030-01-01T12:00:01+00:00', 'Second precision must survive.');
    foreach (['2030-02-30T12:00:00+00:00', '2030-01-01T12:00:00Z', 'never', null] as $bad) {
        $reject(fn() => SceneSchedule::epoch($bad), 'Corrupt stored dates must fail closed');
    }
    $seed();
    $baseline = $store->scene('hero', true);
    $scheduled = $store->scene('hero');
    $ready = $input('hero');
    $bytes = file_get_contents($path);
    foreach ([array_replace($ready, ['expectedRevision' => str_repeat('0', 64)]),
        array_replace($ready, ['expectedDraftRevision' => str_repeat('0', 64)]),
        array_replace($ready, ['expectedPublishedRevision' => str_repeat('0', 64)])] as $bad) {
        $reject(fn() => $store->scheduleScene('hero', $bad, $owner), 'Stale schedule/draft/publication guard must reject', 409);
        $assert(file_get_contents($path) === $bytes, 'Rejected stale input must preserve complete store bytes.');
    }
    $reject(fn() => $store->scheduleScene('hero', $ready, $other), 'An editor cannot schedule', 403);
    $reject(fn() => $store->scheduleScene('hero', array_replace($ready, ['endsAt' => '2030-01-01T12:00:00']), $owner), 'Equal date bounds must reject');
    $reject(fn() => $store->scheduleScene('hero', array_replace($ready, ['returnPolicy' => 'erase']), $owner), 'Unknown return policy must reject');
    $reject(fn() => $store->scheduleScene('hero', array_replace($ready, ['endsAt' => '']), $owner), 'Return policy needs expiry');
    $assert(file_get_contents($path) === $bytes, 'Rejected policy/date/owner input must preserve bytes.');
    $queued = $store->scheduleScene('hero', $ready, $owner);
    $assert($queued['schedule']['status'] === 'scheduled', 'Valid input must persist scheduled state.');
    $assert($raw()['settings']['scene_schedule_hero']['schedule']['scene'] === $scheduled, 'The exact saved draft must be frozen.');
    $assert($store->scene('hero', true) === $baseline, 'Queueing must not publish.');
    foreach (['scene', 'owner', 'claim', 'previousScene', 'baselineRevision', 'activatedRevision'] as $private) {
        $assert(!array_key_exists($private, $queued['schedule']), 'Metadata must omit ' . $private . '.');
        $assert(!array_key_exists($private, $store->sceneScheduleCalendarRecords()['hero'][0]), 'Calendar must omit ' . $private . '.');
    }
    $reject(fn() => $store->scheduleScene('hero', $input('hero'), $owner), 'Only one open schedule is permitted', 409);
    $before = file_get_contents($path);
    $store->sceneSchedule('hero'); $store->sceneScheduleCalendarRecords();
    $assert(file_get_contents($path) === $before, 'Saved metadata reads must not write.');
    $assert($store->runSceneSchedules(10, null, $start - 1)['activated'] === 0, 'Future scene stays scheduled.');
    $store->saveScene('hero', $snapshot('Later editing draft'), false, 1, $owner);
    $later = $store->scene('hero');
    $versions = count($store->sceneVersions('hero'));
    $stats = $store->runSceneSchedules(10, $owner, $start);
    $assert($stats['activated'] === 1 && $stats['claimed'] === 1, 'Inclusive start must activate once.');
    $assert($strip($store->scene('hero', true)) === $strip($scheduled), 'Activation must use the frozen draft.');
    $assert($store->scene('hero') === $later, 'Activation must preserve a later editing draft.');
    $assert(count($store->sceneVersions('hero')) === $versions + 1, 'Activation creates one history version.');
    $assert($store->sceneSchedule('hero')['schedule']['status'] === 'active', 'Timed publication remains active until its end.');
    $store->runSceneSchedules(10, null, $start + 1);
    $assert(count($store->sceneVersions('hero')) === $versions + 1, 'Repeated workers cannot duplicate activation.');
    $assert($store->runSceneSchedules(10, null, $end - 1)['expired'] === 0, 'Return cannot occur before the end.');
    $stats = $store->runSceneSchedules(10, null, $end);
    $assert($stats['expired'] === 1, 'Exclusive end must restore exactly at its bound.');
    $assert($strip($store->scene('hero', true)) === $strip($baseline), 'Return restores the previous published snapshot.');
    $assert($store->scene('hero') === $later, 'Return must preserve the current editor draft.');
    $assert($store->sceneSchedule('hero')['schedule'] === null && $store->sceneSchedule('hero')['history'][0]['status'] === 'expired', 'Expiry must finish and retain safe history.');
    $versions = count($store->sceneVersions('hero'));
    $store->runSceneSchedules(10, null, $end + 1);
    $assert(count($store->sceneVersions('hero')) === $versions, 'Repeated expiry cannot duplicate return.');

    $seed();
    $store->scheduleScene('hero', $input('hero', '2030-01-01T12:00:00', '', 'keep'), $owner);
    $assert($store->runSceneSchedules(1, null, $start)['activated'] === 1, 'Keep without end must activate.');
    $assert($store->sceneSchedule('hero')['schedule'] === null && $store->sceneSchedule('hero')['history'][0]['status'] === 'completed', 'No-end schedule completes while leaving the scene live.');
    $seed();
    $store->scheduleScene('hero', $input('hero', '2030-01-01T12:00:00', '2030-01-01T13:00:00', 'keep'), $owner);
    $store->runSceneSchedules(1, null, $start);
    $kept = $store->scene('hero', true);
    $versions = count($store->sceneVersions('hero'));
    $store->runSceneSchedules(1, null, $end);
    $assert($store->scene('hero', true) === $kept && count($store->sceneVersions('hero')) === $versions, 'Keep expiry does not rewrite the live scene/history.');

    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $before = $store->scene('hero', true);
    $assert($store->runSceneSchedules(1, null, $end)['skipped'] === 1, 'Missed complete windows must skip instead of flashing expired content.');
    $assert($store->scene('hero', true) === $before, 'Skipping a missed window cannot change the live scene.');

    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $store->saveScene('hero', $snapshot('Manual before activation'), true, 1, $owner);
    $assert($store->sceneSchedule('hero')['schedule'] === null && $store->sceneSchedule('hero')['history'][0]['status'] === 'superseded', 'Manual publication supersedes pending automation.');
    $assert($store->runSceneSchedules(1, null, $start)['activated'] === 0, 'Superseded schedule cannot activate later.');
    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $store->runSceneSchedules(1, null, $start);
    $store->saveScene('hero', $snapshot('Manual after activation'), true, 1, $owner);
    $manual = $store->scene('hero', true);
    $store->runSceneSchedules(1, null, $end);
    $assert($store->scene('hero', true) === $manual, 'Auto-return must never overwrite a newer manual publication.');

    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $claim = (new ReflectionMethod(Store::class, 'claimSceneSchedule'))->invoke($store, 'hero', $start);
    $assert($claim['outcome'] === 'claimed', 'A real durable claim must be available before publication.');
    $assert($store->sceneSchedule('hero')['schedule']['status'] === 'processing', 'Processing state must commit separately.');
    $assert($store->runSceneSchedules(1, null, $start + 1)['claimed'] === 0, 'Another worker must respect a live claim.');
    $store->saveScene('hero', $snapshot('Draft during interrupted processing'), false, 1, $owner);
    $store->setSetting('unrelated_during_processing', ['preserved' => true]);
    $versions = count($store->sceneVersions('hero'));
    $resumed = new Store(new Config($fixture . '/config/app.php'));
    $stats = $resumed->runSceneSchedules(1, null, $start + SceneSchedule::CLAIM_SECONDS);
    $assert($stats['recovered'] === 1 && $stats['activated'] === 1, 'A new process must reclaim an expired claim.');
    $assert($store->scene('hero')['title'] === 'Draft during interrupted processing', 'Recovery must not overwrite a concurrent fresh draft.');
    $assert($store->setting('unrelated_during_processing') === ['preserved' => true], 'Recovery must not rewrite stale whole-file state.');
    $assert(count($store->sceneVersions('hero')) === $versions + 1, 'Recovered publication must create one history record.');
    $staleFinish = (new ReflectionMethod(Store::class, 'finishSceneScheduleClaim'))->invoke($store, 'hero', $claim['id'], $claim['token'], $start + 61);
    $assert($staleFinish === 'unchanged' && count($store->sceneVersions('hero')) === $versions + 1, 'An old claim token cannot finish again.');

    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $claim = (new ReflectionMethod(Store::class, 'claimSceneSchedule'))->invoke($store, 'hero', $start);
    $cancel('hero');
    $before = $store->scene('hero', true);
    $outcome = (new ReflectionMethod(Store::class, 'finishSceneScheduleClaim'))->invoke($store, 'hero', $claim['id'], $claim['token'], $start + 1);
    $assert($outcome === 'unchanged' && $store->scene('hero', true) === $before, 'Cancellation after claim prevents activation.');

    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $store->runSceneSchedules(1, null, $start);
    $active = $store->scene('hero', true);
    $cancel('hero');
    $store->runSceneSchedules(1, null, $end);
    $assert($store->scene('hero', true) === $active, 'Cancelling an active schedule must keep the current live scene and stop return.');

    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $claim = (new ReflectionMethod(Store::class, 'claimSceneSchedule'))->invoke($store, 'hero', $start);
    $store->mutateFileStore(static function (array &$data): void { $data['admins'][0]['role'] = 'editor'; });
    $before = $store->scene('hero', true);
    $outcome = (new ReflectionMethod(Store::class, 'finishSceneScheduleClaim'))->invoke($store, 'hero', $claim['id'], $claim['token'], $start + 1);
    $assert($outcome === 'held' && $store->scene('hero', true) === $before, 'Creator revocation between claim and publication must hold it.');
    $reject(fn() => $store->runSceneSchedules(1, $owner, $end), 'Revoked owner cannot invoke owner runner', 403);
    $reject(fn() => $store->saveScene('hero', $snapshot('Unauthorized'), true, 1, $owner), 'Manual publication must recheck current owner', 403);
    $store->mutateFileStore(static function (array &$data): void { $data['admins'][0]['role'] = 'owner'; });
    $assert($store->runSceneSchedules(1, null, $start + 2)['activated'] === 0, 'Reactivation does not silently resume a held job.');

    $seed(); $seed('footer');
    foreach (['hero', 'footer'] as $section) $store->scheduleScene($section, $input($section, '2030-01-01T12:00:00', '', 'keep'), $owner);
    $assert($store->runSceneSchedules(1, null, $start)['activated'] === 1, 'Batch limit must bound work across sections.');
    $assert($store->sceneSchedule('footer')['schedule']['status'] === 'scheduled', 'Bounded first batch leaves the second section pending.');
    $assert($store->runSceneSchedules(1, null, $start)['activated'] === 1, 'Next batch must process the other section.');
    foreach ([0, 26, -1] as $limit) $reject(fn() => $store->runSceneSchedules($limit), 'Invalid worker limits reject');

    // A first draft is private on both storage modes, even before any baseline
    // publication exists. Keep may schedule it; automatic return needs a baseline.
    $store->mutateFileStore(static function (array &$data): void { unset($data['scenes']['footer']); });
    $store->saveScene('footer', $snapshot('Private first footer draft'), false, 1, $owner);
    $assert($store->scene('footer', true) === [] && $store->scene('footer')['title'] === 'Private first footer draft',
        'Published-only reads cannot fall back to the editor draft.');
    $reject(fn() => $store->scheduleScene('footer', $input('footer'), $owner), 'Restore policy requires a real published baseline');
    $store->scheduleScene('footer', $input('footer', '2030-01-01T12:00:00', '', 'keep'), $owner);
    $store->runSceneSchedules(1, null, $start - 1);
    $assert($store->scene('footer', true) === [], 'A future first composition must remain private.');
    $store->runSceneSchedules(1, null, $start);
    $assert($store->scene('footer', true)['title'] === 'Private first footer draft', 'A first composition becomes public only after activation.');

    $corruptions = [
        static function (array &$job): void { $job['returnPolicy'] = 'erase'; },
        static function (array &$job): void { $job['returnPolicy'] = null; },
        static function (array &$job): void { $job['endsAt'] = null; },
        static function (array &$job): void { $job['id'] = ['private' => 'must not be projected']; },
        static function (array &$job): void { $job['section'] = 'footer'; },
        static function (array &$job): void { $job['status'] = 'invented'; },
        static function (array &$job): void { $job['scene']['title'] = 'Unexpected snapshot mutation'; },
        static function (array &$job): void { $job['baselineRevision'] = null; },
        static function (array &$job): void { $job['owner']['id'] = []; },
        static function (array &$job): void { $job['snapshotRevision'] = str_repeat('0', 64); },
        static function (array &$job): void { $job['startsAt'] = ['private' => 'must not be projected']; },
        static function (array &$job): void { $job['createdAt'] = 'invalid'; },
    ];
    foreach ($corruptions as $index => $corrupt) {
        $seed();
        $store->scheduleScene('hero', $input('hero'), $owner);
        $before = $store->scene('hero', true);
        $draftBefore = $store->scene('hero');
        $versionsBefore = $store->sceneVersions('hero');
        $store->mutateFileStore(static function (array &$data) use ($corrupt): void { $corrupt($data['settings']['scene_schedule_hero']['schedule']); });
        foreach ($store->sceneSchedule('hero')['schedule'] as $field) $assert($field === null || is_string($field), 'Corrupt metadata cannot disclose nested private fields.');
        $assert($store->runSceneSchedules(1, null, $start)['held'] === 1, 'Corrupt persisted job ' . $index . ' must be held.');
        $assert($store->scene('hero', true) === $before && $store->scene('hero') === $draftBefore && $store->sceneVersions('hero') === $versionsBefore,
            'Holding corrupt job ' . $index . ' must preserve live/draft/history exactly.');
    }
    foreach (['operation', 'token', 'previousStatus', 'until'] as $field) {
        $seed();
        $store->scheduleScene('hero', $input('hero'), $owner);
        $claim = (new ReflectionMethod(Store::class, 'claimSceneSchedule'))->invoke($store, 'hero', $start);
        $before = $store->scene('hero', true);
        $store->mutateFileStore(static function (array &$data) use ($field): void { $data['settings']['scene_schedule_hero']['schedule']['claim'][$field] = 'invalid'; });
        $outcome = (new ReflectionMethod(Store::class, 'finishSceneScheduleClaim'))->invoke($store, 'hero', $claim['id'], $claim['token'], $start + 1);
        $assert($outcome === 'held' && $store->scene('hero', true) === $before, 'Malformed claim ' . $field . ' must hold without publication.');
    }
    $seed();
    $store->scheduleScene('hero', $input('hero'), $owner);
    $store->runSceneSchedules(1, null, $start);
    $before = $store->scene('hero', true);
    $store->mutateFileStore(static function (array &$data): void { $data['settings']['scene_schedule_hero']['schedule']['previousScene']['title'] = 'Unexpected rollback mutation'; });
    $assert($store->runSceneSchedules(1, null, $end)['held'] === 1 && $store->scene('hero', true) === $before,
        'A corrupted return snapshot must be held instead of replacing live content.');

    $seed();
    $staleTimezone = $input('hero');
    date_default_timezone_set('America/Chicago');
    $reject(fn() => $store->scheduleScene('hero', $staleTimezone, $owner), 'A timezone change invalidates the scheduling form', 409);
    date_default_timezone_set('UTC');

    for ($i = 0; $i < 24; ++$i) {
        $seed();
        $store->scheduleScene('hero', $input('hero'), $owner);
        $cancel('hero');
    }
    $assert(count($store->sceneSchedule('hero')['history']) === 20, 'Terminal schedule history must be bounded.');
    $data = $raw();
    foreach ($data['settings']['scene_schedule_hero']['history'] as $entry) {
        $assert(!isset($entry['scene']) && !isset($entry['previousScene']) && !isset($entry['owner']) && !isset($entry['claim']), 'Terminal history must release private/heavy snapshots.');
    }
    echo "Scene schedules: {$checks} assertions passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($fixture);
}
