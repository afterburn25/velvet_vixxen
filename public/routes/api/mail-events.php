<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/includes/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (!is_installed()) json_response(['ok' => false, 'message' => 'Provider events are unavailable.'], 503);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); json_response(['ok' => false, 'message' => 'POST required.'], 405);
}
$type = is_string($_SERVER['CONTENT_TYPE'] ?? null) ? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'], 2)[0])) : '';
if ($type !== 'application/json') json_response(['ok' => false, 'message' => 'A JSON event batch is required.'], 415);
$input = fopen('php://input', 'rb');
$raw = is_resource($input) ? stream_get_contents($input, \App\Core\SendGridSignature::MAX_BODY_BYTES + 1) : false;
if (is_resource($input)) fclose($input);
if (!is_string($raw)) json_response(['ok' => false, 'message' => 'Provider payload could not be read.'], 400);
$signature = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '';
if (!is_string($signature) || !is_string($timestamp)) json_response(['ok' => false, 'message' => 'Provider signature verification failed.'], 401);
try {
    $result = (new \App\Core\MailProviderService(app_config(), app_store()))->receive($raw, $signature, $timestamp);
    json_response(['ok' => true] + $result);
} catch (Throwable $error) {
    $status = in_array((int) $error->getCode(), [400, 401, 409, 413, 415, 503], true) ? (int) $error->getCode() : 503;
    json_response(['ok' => false, 'message' => $status === 401 ? 'Provider signature verification failed.' : 'Provider event request could not be processed.'], $status);
}
