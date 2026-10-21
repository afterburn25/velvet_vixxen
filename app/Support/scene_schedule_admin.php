<?php
declare(strict_types=1);

/** Scheduling always checks the saved owner, including after dependent reads. */
function vvx_scene_schedule_require_owner(): array
{
    try {
        $identity = app_auth()->user();
        if (!is_array($identity)) throw new RuntimeException('Owner session is missing.');
        $fresh = new App\Core\Store(new App\Core\Config(app_config()->path()));
        return $fresh->requireSceneScheduleOwner($identity);
    } catch (Throwable) {
        throw new RuntimeException('Only the active site owner can manage scene schedules.', 403);
    }
}

function vvx_scene_schedule_section(): string
{
    return App\Core\SceneSchedule::section(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        ? ($_POST['section'] ?? null) : ($_GET['section'] ?? 'hero'));
}

function vvx_scene_schedule_status(mixed $status): string
{
    return match ($status) {
        'scheduled' => 'Scheduled', 'processing' => 'In progress', 'active' => 'Live · awaiting end time',
        'completed' => 'Completed', 'expired' => 'Ended', 'cancelled' => 'Cancelled',
        'superseded' => 'Replaced by a manual publication', 'skipped' => 'Skipped', 'held' => 'Needs attention',
        default => 'Needs attention',
    };
}

function vvx_scene_schedule_date(mixed $value): string
{
    if (!is_string($value) || $value === '') return '—';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('M j, Y · g:i:s A T');
    } catch (Throwable) {
        return 'Date needs review';
    }
}

/** Only form fields belong in the failed-edit state; never retain arbitrary POST data. */
function vvx_scene_schedule_attempt(): array
{
    $result = [];
    foreach (['startsAt', 'endsAt', 'returnPolicy', 'expectedRevision', 'expectedDraftRevision', 'expectedPublishedRevision'] as $key) {
        $value = $_POST[$key] ?? null;
        $result[$key] = is_string($value) && strlen($value) <= 256 ? $value : '';
    }
    return $result;
}
