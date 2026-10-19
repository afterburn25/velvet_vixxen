<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) {
    json_response(['ok'=>false,'message'=>'The licensing service is not installed.'],503);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

try {
    $hub = addon_license_hub();
    if (isset($_GET['download'])) {
        $download = $hub->resolveDownload((string) $_GET['download']);
        $path = (string) $download['path'];
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $download['name']) ?: 'addon-update.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) throw new RuntimeException('The update package could not be opened.');
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if (!is_string($chunk)) break;
            echo $chunk;
            if (function_exists('fastcgi_finish_request')) { /* keep streaming normally */ }
        }
        fclose($handle);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['public_key'])) {
        $status = $hub->status();
        json_response(['ok'=>$status['enabled'],'public_key'=>$status['publicKey'],'fingerprint'=>$status['fingerprint'],'endpoint'=>$status['endpoint']],$status['enabled']?200:503);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok'=>false,'message'=>'Use POST for licensing requests.'],405);
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 65536) {
        json_response(['ok'=>false,'message'=>'The licensing request was too large.'],413);
    }
    $request = json_decode($raw, true);
    if (!is_array($request)) $request = $_POST;
    $action = strtolower(trim((string) ($request['action'] ?? '')));
    $envelope = match ($action) {
        'activate' => $hub->activate($request),
        'check' => $hub->check($request),
        'deactivate' => $hub->deactivate($request),
        'feed' => $hub->feed($request),
        default => throw new RuntimeException('Choose activate, check, deactivate, or feed.'),
    };
    json_response($envelope);
} catch (RuntimeException $e) {
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    error_log('Velvet Vixxen licensing service error: ' . $e->getMessage());
    json_response(['ok'=>false,'message'=>'The licensing service could not complete the request.'],500);
}
