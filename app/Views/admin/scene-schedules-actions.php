<?php
declare(strict_types=1);

if (!$isSceneScheduleRequest || $page !== 'scene-schedules'
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf()) {
    throw new RuntimeException('Open Scene schedules and submit the owner form.', 400);
}
$sceneScheduleAction = $_POST['action'] ?? null;
if (!is_string($sceneScheduleAction) || !in_array($sceneScheduleAction,
    ['scene_schedule_create', 'scene_schedule_cancel', 'scene_schedule_run'], true)) {
    throw new InvalidArgumentException('Choose Schedule saved draft, Cancel schedule or Run due scenes now.');
}
$sceneScheduleOwner = vvx_scene_schedule_require_owner();
if ($sceneScheduleAction === 'scene_schedule_create') {
    $input = [];
    foreach (['startsAt', 'endsAt', 'returnPolicy', 'expectedRevision', 'expectedDraftRevision', 'expectedPublishedRevision'] as $key) {
        $input[$key] = $_POST[$key] ?? null;
    }
    $store->scheduleScene($sceneScheduleSection, $input, $sceneScheduleOwner);
    flash('success', 'The saved draft is scheduled. Later draft edits will not change this scheduled scene.');
} elseif ($sceneScheduleAction === 'scene_schedule_cancel') {
    if (!is_string($_POST['id'] ?? null) || !is_string($_POST['expectedRevision'] ?? null)) {
        throw new InvalidArgumentException('Reload the saved schedule before cancelling it.');
    }
    $store->cancelSceneSchedule($sceneScheduleSection, $_POST['id'], $_POST['expectedRevision'], $sceneScheduleOwner);
    flash('success', 'Schedule cancelled. The scene currently on the website stays in place.');
} else {
    $stats = $store->runSceneSchedules(10, $sceneScheduleOwner);
    $message = sprintf('Due scenes checked: %d started, %d ended, %d skipped and %d need attention.',
        (int) ($stats['activated'] ?? 0), (int) ($stats['expired'] ?? 0),
        (int) ($stats['skipped'] ?? 0), (int) ($stats['held'] ?? 0));
    if (($stats['busy'] ?? 0) > 0) $message .= ' Another update is finishing. Check due scenes again in a moment.';
    flash('success', $message);
}
redirect(base_url('admin/?page=scene-schedules&section=' . $sceneScheduleSection));
