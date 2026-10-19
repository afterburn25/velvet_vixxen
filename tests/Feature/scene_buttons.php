<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-scene-button-test-' . bin2hex(random_bytes(8));
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
try {
    $store = new App\Core\Store(new App\Core\Config($fixture . '/config/app.php'));
    $photo = ['id' => 'photo-kept', 'src' => 'models/fixture.png', 'x' => -42, 'width' => 220, 'custom' => ['kept' => true]];
    $button = ['id' => 'button-kept', 'type' => 'button', 'label' => 'Book <now> & visit', 'url' => 'booking/',
        'x' => 68, 'y' => 80, 'width' => 22, 'height' => 8, 'style' => 'solid', 'newTab' => true];
    $scene = ['title' => 'Button validation fixture', 'background' => ['src' => 'images/defaults/hero-pink-room.jpg'],
        'devices' => ['desktop' => ['height' => 760, 'layers' => [$photo, $button],
            'contentBox' => ['x' => 6, 'y' => 17, 'width' => 36, 'height' => 63]],
            'tablet' => ['height' => 900, 'layers' => [array_replace($button, ['x' => 60, 'width' => 35])]],
            'mobile' => ['height' => 860, 'layers' => [array_replace($button, ['x' => 50, 'width' => 70, 'height' => 7])]]]];
    $version = $store->saveScene('hero', $scene, true);
    $saved = $store->scene('hero', true);
    $assert($saved['devices']['desktop']['layers'][0] === $photo, 'Saving buttons must not rewrite legacy photo data.');
    $assert($saved['devices']['desktop']['contentBox'] === $scene['devices']['desktop']['contentBox'], 'Existing live text/button group must be preserved.');
    $assert($saved['devices']['desktop']['layers'][1]['label'] === $button['label'], 'Button labels remain literal text for safe rendering.');
    foreach (['desktop' => 22, 'tablet' => 35, 'mobile' => 70] as $device => $width) {
        $layer = $saved['devices'][$device]['layers'][$device === 'desktop' ? 1 : 0];
        $assert((float) $layer['width'] === (float) $width, 'Every device retains independent button geometry.');
        $assert($layer['newTab'] === true, 'New-tab choice must persist.');
    }
    $assert(count($store->sceneVersions('hero')) === 1, 'Button publication must create an ordinary restorable version.');
    $store->saveScene('hero', array_replace($scene, ['title' => 'Later draft']), false);
    $store->restoreScene('hero', $version);
    $assert($store->scene('hero', true)['title'] === $scene['title'], 'Version restore must recover the full button composition.');
    $assert($store->scene('hero', true)['devices']['mobile']['layers'][0]['url'] === 'booking/', 'Restore must retain live destinations.');

    foreach (['booking/', '/booking/', '#membership', '?preview=1', 'shop/?sort=price&return=account%2Flibrary.php',
        'https://example.test/path?q=one#section', 'http://example.test:8080/path', '/booking/?label=Book%20now'] as $url) {
        $assert(App\Core\SceneButton::validUrl($url), 'Expected safe destination: ' . $url);
        $assert(App\Core\SceneButton::validate(array_replace($button, ['url' => $url]))['url'] === $url, 'Safe destinations must not be silently rewritten.');
    }
    $before = file_get_contents($fixture . '/storage/data.json');
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'mailto:owner@example.test',
        '//outside.example.test', '/\\outside.example.test', '\\outside.example.test', '%2f%2foutside.example.test',
        'java%73cript:alert(1)', '/%5coutside.example.test', "https://example.test\n/path", "https://example.test/path\n",
        'https://example.test/%0aheader', '../outside/', '/%2e%2e/outside/', 'https://user:password@example.test/',
        'https:///missing-host', '/invalid%path', '', "java\tscript:alert(1)"] as $url) {
        $assert(!App\Core\SceneButton::validUrl($url), 'Unsafe destination accepted: ' . $url);
        $bad = $scene;
        $bad['devices']['desktop']['layers'][1]['url'] = $url;
        $reject(fn() => $store->saveScene('hero', $bad, true), 'Unsafe button publication must fail before writing.');
        $assert(file_get_contents($fixture . '/storage/data.json') === $before, 'Rejected destination must preserve drafts, published scenes and version history.');
    }
    foreach ([['label' => ''], ['label' => ['forged']], ['label' => str_repeat('x', 121)],
        ['width' => 0], ['height' => 401], ['x' => -401], ['y' => 'not-a-number'], ['fontSize' => INF],
        ['fontSize' => 0], ['radius' => 101], ['style' => 'url(javascript:1)'], ['color' => 'red;display:none'],
        ['textColor' => '#fff'], ['borderColor' => ['forged']], ['newTab' => 'true'], ['locked' => 1]] as $invalid) {
        $bad = $scene;
        $bad['devices']['desktop']['layers'][1] = array_replace($button, $invalid);
        $reject(fn() => $store->saveScene('hero', $bad, true), 'Malformed button controls must be rejected.');
        $assert(file_get_contents($fixture . '/storage/data.json') === $before, 'Rejected button controls must leave existing data unchanged.');
    }
    foreach (['solid', 'outline', 'pill'] as $style) {
        $validated = App\Core\SceneButton::validate(array_replace($button, ['style' => $style,
            'x' => -400, 'y' => 500, 'width' => 400, 'height' => .5, 'visible' => false, 'locked' => true]));
        $assert($validated['style'] === $style && $validated['width'] === 400.0 && $validated['height'] === .5,
            'All button styles must preserve extreme off-canvas geometry.');
        $assert($validated['locked'] && !$validated['visible'], 'Hidden/locked state must round-trip.');
    }
    $legacy = ['title' => 'Legacy without devices', 'custom' => ['kept' => true]];
    $assert(App\Core\SceneButton::validateScene($legacy) === $legacy, 'Legacy compositions without button layers remain unchanged.');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($fixture);
}
echo "Scene button persistence, URL validation, device geometry and history passed: {$checks} assertions.\n";
