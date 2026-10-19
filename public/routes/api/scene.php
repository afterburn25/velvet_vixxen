<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

require_admin(true);
$section = (string) ($_GET['section'] ?? 'hero');
if (!in_array($section, ['hero', 'footer'], true)) {
    json_response(['ok' => false, 'message' => 'Unknown scene section.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'scene' => app_store()->scene($section, false),
        'versions' => app_store()->sceneVersions($section),
        'media' => app_store()->media(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
require_csrf();

try {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 2_000_000) {
        throw new RuntimeException('The composition is too large to save.');
    }
    $payload = safe_json_decode($raw);
    $action = (string) ($payload['action'] ?? 'save');
    if ($action === 'restore') {
        $versionId = $payload['versionId'] ?? '';
        if ($versionId === '') {
            throw new RuntimeException('Choose a version to restore.');
        }
        $scene = app_store()->restoreScene($section, is_numeric($versionId) ? (int) $versionId : (string) $versionId, app_auth()->id());
        app_store()->audit(app_auth()->id(), 'scene.restored', ['section' => $section, 'version' => $versionId]);
        json_response(['ok' => true, 'scene' => $scene, 'versions' => app_store()->sceneVersions($section), 'message' => 'Published version restored.']);
    }
    $scene = $payload['scene'] ?? null;
    if (!is_array($scene)) {
        throw new RuntimeException('Composition data is missing.');
    }
    $publish = $action === 'publish';
    $id = app_store()->saveScene($section, $scene, $publish, app_auth()->id());
    app_store()->audit(app_auth()->id(), $publish ? 'scene.published' : 'scene.saved', ['section' => $section, 'record' => $id]);
    json_response([
        'ok' => true,
        'id' => $id,
        'versions' => app_store()->sceneVersions($section),
        'message' => $publish ? 'Composition published to the live homepage.' : 'Draft saved.',
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
}
