<?php
declare(strict_types=1);

// Exercise the same Store boundary used by save, publish and version restore.
// Legacy photo fields deliberately include geometry outside the visible frame.
$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-scene-depth-test-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true);
mkdir($fixture . '/storage', 0700, true);
define('ROOT_PATH', $fixture);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require $source . '/app/Support/functions.php';
file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export([
    'storage_mode' => 'file', 'file_store_path' => 'storage/data.json',
], true) . ';');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException) { $assert(true, $message); return; }
    $assert(false, $message);
};
$withoutMetadata = static function (array $scene): array {
    unset($scene['section'], $scene['updatedAt']);
    return $scene;
};
try {
    $store = new App\Core\Store(new App\Core\Config($fixture . '/config/app.php'));
    $background = 'images/defaults/hero-pink-room.jpg';
    $legacyPhoto = ['id' => 'legacy-photo', 'src' => 'models/fixture.png', 'x' => -42, 'y' => 130,
        'width' => 220, 'rotation' => -17, 'z' => -8, 'custom' => ['kept' => true]];
    $legacy = ['title' => 'Legacy composition', 'custom' => ['owner' => 'kept'],
        'background' => ['src' => $background], 'devices' => [
            'desktop' => ['height' => 760, 'layers' => [$legacyPhoto]],
            'tablet' => ['height' => 900, 'layers' => []],
            'mobile' => ['height' => 860, 'layers' => [$legacyPhoto]],
        ]];
    $store->saveScene('hero', $legacy, true);
    $assert($withoutMetadata($store->scene('hero', true)) === $legacy,
        'Depth support must not add missing fields or rewrite a legacy composition.');
    $store->saveScene('footer', ['custom' => ['legacyWithoutDevices' => true]], true);
    $assert($withoutMetadata($store->scene('footer', true)) === ['custom' => ['legacyWithoutDevices' => true]],
        'A legacy scene without device layouts must remain unchanged.');

    $points = [['x' => 18, 'y' => 55], ['x' => 87.5, 'y' => 55], ['x' => 95, 'y' => 93], ['x' => 8, 'y' => 93]];
    $scene = $legacy;
    $scene['title'] = 'Independent perspective and foreground';
    foreach (['desktop' => 0, 'tablet' => 40, 'mobile' => 100] as $device => $depth) {
        $scene['devices'][$device]['perspective'] = ['x' => 68.5, 'y' => 35];
        $scene['devices'][$device]['foreground'] = ['enabled' => true, 'source' => $background, 'points' => $points];
        $scene['devices'][$device]['layers'] = [$legacyPhoto, array_replace($legacyPhoto, [
            'id' => 'depth-' . $device, 'x' => 82, 'y' => 75, 'width' => 24,
            'depth' => $depth, 'behindForeground' => true, 'visible' => $device !== 'tablet',
            'locked' => $device === 'tablet',
        ])];
    }
    $scene['devices']['mobile']['background'] = ['src' => 'images/defaults/footer-pink-room.jpg'];
    $scene['devices']['mobile']['foreground']['source'] = 'images/defaults/footer-pink-room.jpg';
    foreach (['hero', 'footer'] as $section) {
        $version = $store->saveScene($section, $scene, true);
        $saved = $store->scene($section, true);
        $assert($withoutMetadata($saved) === $scene, 'Saving must retain all valid depth/mask fields, hidden/locked photos and source-image coordinates.');
        $later = $scene;
        $later['devices']['desktop']['layers'][1]['depth'] = 73;
        unset($later['devices']['desktop']['foreground']);
        $store->saveScene($section, $later, false);
        $assert($withoutMetadata($store->scene($section, true)) === $scene, 'Saving a draft must not publish depth or foreground changes.');
        $assert(!array_key_exists('foreground', $store->scene($section)['devices']['desktop']), 'Clear must remove the foreground field.');
        $store->restoreScene($section, $version);
        $assert($withoutMetadata($store->scene($section, true)) === $scene, 'Published version restore must recover the complete independent device composition.');
    }

    $disabled = $scene;
    $disabled['devices']['desktop']['foreground']['enabled'] = false;
    $disabled['devices']['desktop']['foreground']['source'] = 'images/defaults/previous-room.jpg';
    $store->saveScene('hero', $disabled);
    $assert($store->scene('hero')['devices']['desktop']['foreground'] === $disabled['devices']['desktop']['foreground'],
        'A disabled valid mask must retain its former image and editable vertices.');
    $circle = [];
    for ($index = 0; $index < 64; $index++) {
        $angle = 2 * M_PI * $index / 64;
        $circle[] = ['x' => 50 + 40 * cos($angle), 'y' => 50 + 40 * sin($angle)];
    }
    $limit = $scene;
    $limit['devices']['desktop']['foreground']['points'] = $circle;
    $store->saveScene('hero', $limit);
    $assert(count($store->scene('hero')['devices']['desktop']['foreground']['points']) === 64,
        'The supported 64-vertex foreground boundary must save.');
    $button = ['id' => 'depth-button', 'type' => 'button', 'label' => 'Booking', 'url' => 'booking/',
        'depth' => 0, 'behindForeground' => false];
    $neutral = $scene;
    $neutral['devices']['desktop']['layers'][] = $button;
    $store->saveScene('hero', $neutral);
    $savedButton = $store->scene('hero')['devices']['desktop']['layers'][2];
    $assert((float) $savedButton['depth'] === 0.0 && $savedButton['behindForeground'] === false,
        'Explicit neutral depth fields on a standalone button must remain compatible.');

    $badScenes = [];
    $badLayer = static function (array $updates) use ($scene): array {
        $copy = $scene;
        $copy['devices']['desktop']['layers'][1] = array_replace($copy['devices']['desktop']['layers'][1], $updates);
        return $copy;
    };
    foreach ([-1, 100.01, true, false, '50', null, [], INF, NAN] as $value) $badScenes[] = $badLayer(['depth' => $value]);
    foreach ([0, 1, 'true', null, []] as $value) $badScenes[] = $badLayer(['behindForeground' => $value]);
    foreach ([null, [], ['x' => 50], ['x' => 50, 'y' => 35, 'extra' => 1],
        ['x' => -1, 'y' => 35], ['x' => 50, 'y' => 101], ['x' => true, 'y' => 35],
        ['x' => '50', 'y' => 35], ['x' => 50, 'y' => INF], ['x' => 50, 'y' => NAN]] as $value) {
        $copy = $scene;
        $copy['devices']['tablet']['perspective'] = $value;
        $badScenes[] = $copy;
    }
    $invalidMasks = [null, [], ['enabled' => true, 'source' => $background],
        ['enabled' => true, 'source' => $background, 'points' => $points, 'extra' => true]];
    foreach ([['enabled' => 1], ['enabled' => 'true'], ['enabled' => null], ['source' => 'https://example.test/room.png'],
        ['source' => '../outside.png'], ['source' => '//outside.test/room.png'], ['source' => ''],
        ['source' => 'images/defaults/different-room.jpg'], ['points' => []], ['points' => array_slice($points, 0, 2)],
        ['points' => array_merge($circle, [['x' => 50, 'y' => 50]])],
        ['points' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 100], ['x' => 0, 'y' => 100], ['x' => 100, 'y' => 0]]],
        ['points' => [['x' => 0, 'y' => 0], ['x' => 50, 'y' => 50], ['x' => 100, 'y' => 100]]],
        ['points' => [$points[0], $points[1], $points[2], $points[0]]],
        ['points' => [['x' => 10, 'y' => 10, 'extra' => 0], $points[1], $points[2]]],
        ['points' => [['x' => '10', 'y' => 10], $points[1], $points[2]]],
        ['points' => [['x' => true, 'y' => 10], $points[1], $points[2]]],
        ['points' => [['x' => -1, 'y' => 10], $points[1], $points[2]]],
        ['points' => [['x' => 10, 'y' => 101], $points[1], $points[2]]],
        ['points' => [['x' => INF, 'y' => 10], $points[1], $points[2]]],
    ] as $updates) $invalidMasks[] = array_replace($scene['devices']['desktop']['foreground'], $updates);
    foreach ($invalidMasks as $mask) {
        $copy = $scene;
        $copy['devices']['desktop']['foreground'] = $mask;
        $badScenes[] = $copy;
    }
    foreach ([['depth' => 1], ['behindForeground' => true]] as $updates) {
        $copy = $neutral;
        $copy['devices']['desktop']['layers'][2] = array_replace($button, $updates);
        $badScenes[] = $copy;
    }
    $before = file_get_contents($fixture . '/storage/data.json');
    foreach ($badScenes as $index => $bad) {
        foreach ([false, true] as $publish) {
            $reject(fn() => $store->saveScene('hero', $bad, $publish), 'Malformed depth/foreground case ' . $index . ' must fail before any storage mutation.');
            $assert(file_get_contents($fixture . '/storage/data.json') === $before,
                'Rejected depth data must preserve drafts, published scenes and complete history byte for byte.');
        }
    }
    // An old/imported malformed version must cross the same validation boundary.
    $data = json_decode((string) $before, true, 512, JSON_THROW_ON_ERROR);
    $data['scenes']['hero']['versions'][] = ['id' => 'invalid-historical-depth', 'publishedAt' => '2000-01-01T00:00:00Z',
        'scene' => $badLayer(['depth' => '50'])];
    file_put_contents($fixture . '/storage/data.json', json_encode($data, JSON_THROW_ON_ERROR));
    $beforeRestore = file_get_contents($fixture . '/storage/data.json');
    $reject(fn() => $store->restoreScene('hero', 'invalid-historical-depth'), 'Restore must reject malformed historical depth.');
    $assert(file_get_contents($fixture . '/storage/data.json') === $beforeRestore, 'A refused version restore must not append history or alter draft/published data.');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($fixture);
}
echo "Scene depth, source-image foreground validation, legacy preservation and history passed: {$checks} assertions.\n";
