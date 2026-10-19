<?php
declare(strict_types=1);

// A physical entry point remains reachable when an older admin template is cached.
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
    exit('Only the active site owner can verify or refresh an update.');
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
$service = new App\Core\RuntimeBuildService(ROOT_PATH);
$error = '';
$success = flash('update_status');
$status = null;
try {
    if ($method === 'POST') {
        if (!verify_csrf()) {
            http_response_code(419);
            throw new RuntimeException('Your session token expired. Reload this page and try again.');
        }
        if (($_POST['action'] ?? '') !== 'refresh_php_cache') {
            http_response_code(400);
            throw new RuntimeException('Choose the Refresh application PHP button to continue.');
        }
        $result = $service->refreshPhpCache();
        flash('update_status', $result['status'] === 'disabled'
            ? 'Application files were verified. PHP caching is disabled on this host.'
            : 'Application PHP was refreshed. This page has reloaded to check the running build.');
        // Relative to this physical entry point, even if an older configured URL is wrong.
        header('Location: update.php', true, 303);
        exit;
    }
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}
try {
    $status = $service->inspect();
} catch (RuntimeException $exception) {
    $error = $error !== '' ? $error : $exception->getMessage();
}
require ROOT_PATH . '/app/Views/admin/update-status.php';
