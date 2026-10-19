<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) {
    redirect(base_url('install/'));
}
require_admin();

$key = strtolower(trim((string) ($_GET['file'] ?? '')));
$files = [
    'starter-addon' => [
        'path' => root_path('addons/packages/velvet-welcome-banner-1.0.0.zip'),
        'name' => 'velvet-welcome-banner-1.0.0.zip',
        'type' => 'application/zip',
    ],
];
$file = $files[$key] ?? null;
if (!is_array($file) || !is_file((string) $file['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The requested developer resource could not be found.';
    exit;
}
$path = (string) $file['path'];
header('Content-Type: ' . (string) $file['type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $file['name']) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
