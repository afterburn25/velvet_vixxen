<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'apply_database_updates') {
    throw new RuntimeException('A database update requires a submitted owner action.');
}
if ((string) (app_auth()->user()['role'] ?? '') !== 'owner') throw new RuntimeException('Only the owner can apply database updates.');
if (!verify_csrf()) throw new RuntimeException('Your session token expired. Refresh before applying updates.');
$databaseUpdateResult = (new \App\Core\DatabaseUpdateService(ROOT_PATH))->apply(
    is_string($_POST['update_plan'] ?? null) ? $_POST['update_plan'] : '',
    ($_POST['backup_confirmed'] ?? '') === '1',
    is_string($_POST['backup_reference'] ?? null) ? $_POST['backup_reference'] : '',
    ($_POST['reviewed_failure'] ?? '') === '1',
    'Owner ' . (string) app_auth()->id(),
);
$store->audit(app_auth()->id(), 'database_updates.applied', ['migrations' => $databaseUpdateResult['applied'], 'status' => $databaseUpdateResult['status']]);
flash('success', $databaseUpdateResult['status'] === 'not_needed' ? 'This file-storage site does not need SQL migrations.'
    : (count($databaseUpdateResult['applied']) . ' database update(s) applied. The active configuration, installation lock and security keys were preserved.'));
redirect(base_url('admin/?page=database-updates'));
