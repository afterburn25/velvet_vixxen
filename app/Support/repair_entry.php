<?php
declare(strict_types=1);

// Shared by the compatibility entry and the fresh 0.9 upgrade entry.
if (!in_array($repairEntry ?? null, ['repair.php', 'repair-current.php'], true)) {
    http_response_code(503); exit('Open the current update repair entry directly.');
}
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
// A partial upload must fail natively even when its new helper is absent.
$repairHelper = ROOT_PATH . '/app/Core/RepairAccess.php';
if (!is_file($repairHelper) || !is_readable($repairHelper) || is_link($repairHelper)
    || is_link(ROOT_PATH . '/app') || is_link(ROOT_PATH . '/app/Core') || realpath($repairHelper) !== $repairHelper) {
    http_response_code(503); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complete the update · Velvet Vixxen</title><main data-update-status="incomplete"><h1>Complete the update</h1><p>Missing, unreadable or linked file: app/Core/RepairAccess.php</p><p>Extract the complete current Upgrade ZIP into this website folder, then reload this page.</p></main></html>');
}
try {
    require_once $repairHelper;
    App\Core\RepairAccess::prepare(ROOT_PATH);
    require ROOT_PATH . '/app/bootstrap.php';
} catch (Throwable $exception) {
    $message = $exception instanceof RuntimeException && $exception->getCode() === 503
        ? $exception->getMessage() : 'Current owner authentication is unavailable. Finish the complete update, then reload.';
    if (class_exists(App\Core\RepairAccess::class, false)) App\Core\RepairAccess::unavailable($message);
    http_response_code(503); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>Complete the update · Velvet Vixxen</title><main data-update-status="incomplete"><h1>Complete the update</h1><p>Current owner authentication is unavailable. Extract the complete Upgrade ZIP and reload.</p></main></html>');
}
if (!is_installed()) redirect(base_url('install/'));
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
try { $access = App\Core\RepairAccess::authentication(app_auth(), $method, $_POST); }
catch (RuntimeException $exception) {
    if ($exception->getCode() === 403) { http_response_code(403); exit('Only the active site owner can verify an update or repair application folders.'); }
    App\Core\RepairAccess::unavailable('Current owner authentication is unavailable. Review the saved security settings before retrying.');
} catch (Throwable) { App\Core\RepairAccess::unavailable('Current owner authentication is unavailable. Review the saved security settings before retrying.'); }
if ($access['redirect']) { header('Location: ' . $repairEntry, true, 303); exit; }
if (!$access['ready']) {
    App\Core\RepairAccess::status($access['status']);
    require ROOT_PATH . '/app/Views/admin/repair-signin.php';
    exit;
}
// Fresh class and view file names avoid executing the cached .5 inventory rules/form.
$service = new App\Core\RuntimeInventoryService(ROOT_PATH);
$rootLayout = new App\Core\RootLayoutService(ROOT_PATH);
$error = '';
$success = flash('update_status');
$status = null;
$layout = null;
$layoutResult = $_SESSION['_root_layout_result'] ?? null;
unset($_SESSION['_root_layout_result']);
try {
    if ($method === 'POST') {
        app_auth()->requireRecentOwner();
        if (!verify_csrf()) {
            App\Core\RepairAccess::status(419);
            throw new RuntimeException('Your session token expired. Reload this page and try again.');
        }
        $submittedAction = $_POST['action'] ?? '';
        $action = is_string($submittedAction) ? $submittedAction : '';
        if ($action === 'refresh_php_cache') {
            $result = $service->refreshPhpCache();
            flash('update_status', $result['status'] === 'disabled'
                ? 'Application files were verified. PHP caching is disabled on this host.'
                : 'Application PHP was refreshed. This page has reloaded to check the running build.');
        } elseif ($action === 'repair_root_layout') {
            $_SESSION['_root_layout_result'] = $rootLayout->retire();
        } else {
            http_response_code(400);
            throw new RuntimeException('Choose one of the update or folder-repair buttons to continue.');
        }
        // Resolve from the physical entry point, independent of old cached URL helpers.
        header('Location: ' . $repairEntry, true, 303);
        exit;
    }
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}
try {
    $status = $service->inspect();
    $layout = $rootLayout->inspect();
} catch (RuntimeException $exception) {
    $error = $error !== '' ? $error : $exception->getMessage();
}
require ROOT_PATH . '/app/Views/admin/repair-current-status.php';
