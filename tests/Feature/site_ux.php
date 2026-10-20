<?php
declare(strict_types=1);
$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-about-test-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true); mkdir($fixture . '/storage', 0700, true);
define('ROOT_PATH', $fixture);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require $source . '/app/Support/functions.php';
file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json'], true) . ';');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException) { $assert(true, $message); return; }
    $assert(false, $message);
};
try {
    $store = new App\Core\Store(new App\Core\Config($fixture . '/config/app.php'));
    $about = new App\Core\AboutPage($store);
    $assert($about->details()['imageFile'] === '', 'No placeholder person may be provided by default.');
    $input = ['title' => 'Meet Nikki', 'biography' => "First paragraph.\n\nSecond paragraph with <script>literal text</script>.",
        'statistics' => [['label' => 'Height', 'value' => 'Owner supplied'], ['label' => 'Empty row', 'value' => '']],
        'ctaLabel' => 'Booking', 'ctaUrl' => '/booking/', 'imageId' => '', 'imageAlt' => ''];
    $saved = $about->save($input);
    $assert((new App\Core\AboutPage($store))->details() === $saved, 'About content must persist across service instances.');
    $assert(count($saved['statistics']) === 2 && $saved['statistics'][1]['value'] === '', 'Empty statistic values must remain editable.');
    $assert($saved['biography'] === $input['biography'], 'Biography paragraphs and literal text must not silently disappear.');
    foreach (['javascript:alert(1)', '//outside.example.test', '/\\outside.example.test', "https://example.test\nheader", 'http://outside.example.test'] as $bad) {
        $reject(fn() => $about->save(array_replace($input, ['ctaUrl' => $bad])), 'Unsafe CTA URL must be rejected.');
    }
    $assert($about->save(array_replace($input, ['ctaUrl' => 'https://example.test/path']))['ctaUrl'] === 'https://example.test/path', 'Explicit HTTPS links should be supported.');
    $reject(fn() => $about->save(array_replace($input, ['title' => ''])), 'Empty title must fail.');
    $reject(fn() => $about->save(array_replace($input, ['biography' => str_repeat('x', 20001)])), 'Oversized biography must fail.');
    $reject(fn() => $about->save(array_replace($input, ['statistics' => array_fill(0, 41, ['label' => 'x', 'value' => 'y'])])), 'Oversized statistics must fail.');
    $reject(fn() => $about->save(array_replace($input, ['statistics' => [['label' => '', 'value' => 'Hidden label']]])), 'Values require labels.');
    $reject(fn() => $about->save(array_replace($input, ['title' => ['forged']])), 'Array text injection must fail.');
    $reject(fn() => $about->save(array_replace($input, ['imageId' => '../../private/photo'])), 'Unregistered image paths must fail.');
    $store->addMedia(['id' => 'private-fixture', 'type' => 'gallery', 'file' => 'private:images/original.jpg', 'private' => true, 'width' => 640, 'height' => 480]);
    $assert($about->images() === [], 'Protected originals must never appear in the public About picker.');
    $reject(fn() => $about->save(array_replace($input, ['imageId' => 'private-fixture'])), 'Protected images must not be publishable through forged IDs.');
    $public = $store->addMedia(['id' => 'public-fixture', 'type' => 'model', 'file' => 'models/owner-photo.png', 'private' => false, 'width' => 640, 'height' => 480]);
    $assert(count($about->images()) === 1, 'Public image upload must remain selectable.');
    $withImage = $about->save(array_replace($input, ['imageId' => (string) $public['id'], 'imageAlt' => 'Owner supplied description']));
    $assert($withImage['imageFile'] === 'models/owner-photo.png', 'Selected image must resolve from stored media metadata.');
    $about->save(array_replace($input, ['statistics' => [], 'imageId' => '', 'biography' => '']));
    $assert($about->details()['statistics'] === [] && $about->details()['imageFile'] === '', 'Optional content must be removable.');
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($fixture);
}
echo "About Me persistence, validation and public-image boundaries passed: {$checks} assertions.\n";
