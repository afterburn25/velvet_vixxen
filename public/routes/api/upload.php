<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

use App\Core\UploadService;

require_admin(true);
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $service = new UploadService();
    $asset = $service->upload($_FILES['image'] ?? [], (string) ($_POST['type'] ?? 'model'), $_FILES['thumbnail'] ?? null);
    if (!empty($_POST['title'])) {
        $asset['title'] = trim((string) $_POST['title']);
    }
    $asset = app_store()->addMedia($asset);
    app_store()->audit(app_auth()->id(), 'media.uploaded', ['id' => $asset['id'], 'type' => $asset['type']]);
    $asset['url'] = scene_asset_url((string) $asset['file']);
    $asset['thumbnailUrl'] = scene_asset_url((string) $asset['thumbnail']);
    json_response(['ok' => true, 'asset' => $asset]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
}
