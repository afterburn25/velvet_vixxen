<?php
declare(strict_types=1);
// PHP's development server ignores Apache access/rewrite rules.
$root = dirname(__DIR__, 2);
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (str_contains($path, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $path)
    || preg_match('#^/(?:app|config|database|docs|includes|scripts|storage|templates|tests|vendor|addons)(?:/|$)#i', $path)
    || preg_match('#(?:^|/)\.[^/]+#', $path) || str_starts_with($path, '/public/routes/')) {
    http_response_code(403);
    return true;
}
if ($path === '/' || is_file($root . $path) || (is_dir($root . $path) && is_file(rtrim($root . $path, '/') . '/index.php'))) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/route.php';
require $root . '/route.php';
return true;
