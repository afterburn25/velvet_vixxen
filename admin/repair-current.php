<?php
declare(strict_types=1);

// Resolve the guarded controller directly, without a cached admin dispatcher.
define('ROOT_PATH', dirname(__DIR__));
$repairEntry = 'repair-current.php';
$controller = ROOT_PATH . '/app/Support/repair_entry.php';
if (!is_file($controller) || !is_readable($controller) || is_link($controller)
    || realpath($controller) !== $controller || is_link(ROOT_PATH . '/app') || is_link(ROOT_PATH . '/app/Support')) {
    http_response_code(503); header('Cache-Control: no-store, private, max-age=0'); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complete the update · Velvet Vixxen</title><main data-update-status="incomplete"><h1>Complete the update</h1><p>Missing, unreadable or linked file: app/Support/repair_entry.php</p><p>Extract the complete current Upgrade ZIP into this website folder, then reload this page.</p></main></html>');
}
try {
    require $controller;
} catch (Throwable) {
    // A truncated controller can fail to parse before its own manifest guard runs.
    http_response_code(503); header('Cache-Control: no-store, private, max-age=0'); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complete the update · Velvet Vixxen</title><main data-update-status="incomplete"><h1>Complete the update</h1><p>The current repair controller is incomplete: app/Support/repair_entry.php</p><p>Extract the complete current Upgrade ZIP into this website folder, then reload this page.</p></main></html>');
}
