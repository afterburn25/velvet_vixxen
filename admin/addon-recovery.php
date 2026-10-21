<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/app/bootstrap.php';

require_admin();
header('Cache-Control: no-store, private, max-age=0');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// A formerly authorized session cannot resume database changes after the
// account has been disabled, replaced, or demoted.
$session = app_auth()->user();
$owner = app_store()->findAdminByEmail((string) ($session['email'] ?? ''));
if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner'
    || (string) ($owner['id'] ?? '') !== (string) ($session['id'] ?? '')) {
    http_response_code(403);
    exit('Only the active site owner can recover an add-on change.');
}
if (!verify_csrf()) {
    http_response_code(419);
    exit('Your session token expired. Refresh the Add-on Manager and try again.');
}
$addonId = $_POST['addon_id'] ?? null;
if (!is_string($addonId) || !preg_match('/^[a-z0-9][a-z0-9._-]{1,189}$/D', $addonId)) {
    http_response_code(400);
    exit('Choose a recorded add-on recovery.');
}

try {
    app_auth()->requireRecentOwner();
    $message = addon_manager()->recoverPending($addonId);
    flash('success', $message);
} catch (Throwable) {
    // The private lifecycle log holds details; SQL and recovery paths are not
    // copied into the owner page or an unauthenticated response.
    flash('addon_recovery_error', 'Recovery could not complete. The add-on stays disabled. Resolve the recorded database or package issue before retrying.');
}
redirect(base_url('admin/?page=addons'));
