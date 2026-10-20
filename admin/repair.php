<?php
declare(strict_types=1);

// This new organized entry point is reachable even while Build .5 update.php is cached.
define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/app/bootstrap.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
if (!is_installed()) redirect(base_url('install/'));
require_admin();
$user = app_auth()->user();
$owner = app_store()->findAdminByEmail((string) ($user['email'] ?? ''));
if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner'
    || (string) ($owner['id'] ?? '') !== (string) ($user['id'] ?? '')) {
    http_response_code(403);
    exit('Only the active site owner can verify an update or repair application folders.');
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
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
        if (!verify_csrf()) {
            http_response_code(419);
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
        header('Location: repair.php', true, 303);
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
require ROOT_PATH . '/app/Views/admin/repair-status.php';
