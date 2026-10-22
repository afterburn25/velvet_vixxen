<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require_once ROOT_PATH . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/marketing.php';
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET'); http_response_code(405); exit('Use the link in your message.');
}
$token = $_GET['t'] ?? null;
if (!is_string($token) || array_diff(array_keys($_GET), ['t', 'vvx_route']) !== []) {
    http_response_code(404); exit('This link is unavailable.');
}
try {
    $link = velvet_marketing_store()->resolveTrackedLink($token, \Velvet\Core\MarketingPrivacy::choice() === true,
        ['visitor_token' => \Velvet\Core\MarketingPrivacy::visitor()]);
    if ($link === null) { http_response_code(404); exit('This link is unavailable.'); }
    if ($link['visitor_token'] !== null) velvet_marketing_visitor_cookie($link['visitor_token'], $link['expires_at']);
    header('Location: ' . $link['destination'], true, 303);
} catch (Throwable) {
    http_response_code(503); header('Retry-After: 60'); echo 'This link is temporarily unavailable. Please try again later.';
}
