<?php
declare(strict_types=1);

use App\Core\HeroLibrary;

require dirname(__DIR__, 2) . '/app/Core/HeroLibrary.php';

$fixture = sys_get_temp_dir() . '/vvx-hero-library-' . bin2hex(random_bytes(6));
$libraryRoot = $fixture . '/images/hero';
mkdir($libraryRoot . '/thumbnails', 0775, true);
$manifest = $libraryRoot . '/manifest.json';
$service = new HeroLibrary($fixture);
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
$write = static function (array $value) use ($manifest): void {
    file_put_contents($manifest, json_encode($value, JSON_THROW_ON_ERROR));
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    }
    rmdir($path);
};
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $catalog = $service->catalog();
    $assert($catalog['scenes'] === [] && count($catalog['warnings']) === 1, 'Missing manifest must produce an empty picker with a controlled warning.');
    file_put_contents($manifest, '{bad json');
    $assert(str_contains($service->catalog()['warnings'][0] ?? '', 'invalid JSON'), 'Malformed JSON must return a useful warning.');
    foreach ([['version' => 2, 'scenes' => []], ['version' => [1], 'scenes' => []], ['version' => 1, 'scenes' => ['key' => []]]] as $invalid) {
        $write($invalid);
        $assert(str_contains($service->catalog()['warnings'][0] ?? '', 'unsupported format'), 'Invalid manifest shape must be rejected.');
    }
    $source = dirname(__DIR__, 2) . '/images/defaults/hero-pink-room.jpg';
    copy($source, $libraryRoot . '/scene.jpg');
    copy($source, $libraryRoot . '/thumbnails/scene.jpg');
    copy($source, $libraryRoot . '/wrong-extension.webp');
    file_put_contents($libraryRoot . '/not-image.jpg', 'This is not an image.');
    $size = getimagesize($source);
    $row = [
        'id' => 'fixture-scene', 'title' => 'Fixture </script> title', 'category' => 'bedrooms',
        'file' => 'images/hero/scene.jpg', 'thumbnail' => 'images/hero/thumbnails/scene.jpg',
        'width' => 1, 'height' => 9000, 'orientation' => 'portrait',
        'placement' => 'Right foreground; live text at left',
        'modelZone' => ['x' => 72, 'y' => 24, 'width' => 80, 'height' => 100],
        'textZone' => ['x' => -3, 'y' => 8, 'width' => 35, 'height' => 70],
        'keywords' => ['night', 'magenta', ['invalid nested keyword']],
    ];
    $write(['version' => 1, 'categories' => [['id' => 'bedrooms', 'title' => 'Bedrooms & Suites'], ['id' => 'empty', 'title' => 'Unused']], 'scenes' => [$row]]);
    $catalog = $service->catalog();
    $assert(count($catalog['scenes']) === 1 && $catalog['warnings'] === [], 'Valid assets must be selectable without warnings.');
    $scene = $catalog['scenes'][0];
    $assert($scene['file'] === 'images/hero/scene.jpg' && $scene['thumbnail'] === 'images/hero/thumbnails/scene.jpg', 'Bundled root-relative paths must be preserved.');
    $assert($scene['width'] === $size[0] && $scene['height'] === $size[1] && $scene['orientation'] === 'landscape', 'Dimensions and orientation must come from the actual image.');
    $assert($scene['title'] === $row['title'] && $scene['placement'] === $row['placement'], 'Scene metadata must survive validation; views escape it at output.');
    $assert($scene['modelZone']['width'] === 28.0 && $scene['modelZone']['height'] === 76.0 && $scene['textZone']['x'] === 0.0, 'Placement zones must stay within image bounds.');
    $assert($scene['keywords'] === ['night', 'magenta'], 'Only scalar search keywords may enter the catalog.');
    $assert($catalog['categories'] === [['id' => 'bedrooms', 'title' => 'Bedrooms & Suites', 'count' => 1]], 'Category counts must include only real selectable scenes.');

    $invalidRows = [
        array_replace($row, ['id' => 'missing-scene', 'file' => 'images/hero/missing.jpg']),
        array_replace($row, ['id' => 'missing-thumb', 'thumbnail' => 'images/hero/thumbnails/missing.jpg']),
        $row,
        array_replace($row, ['id' => 'traversal', 'file' => 'images/hero/../defaults/hero-pink-room.jpg']),
        array_replace($row, ['id' => 'foreign', 'file' => 'https://example.test/image.jpg']),
        array_replace($row, ['id' => 'not-image', 'file' => 'images/hero/not-image.jpg']),
        array_replace($row, ['id' => 'wrong-extension', 'file' => 'images/hero/wrong-extension.webp']),
        array_replace($row, ['id' => ['invalid'], 'file' => ['invalid'], 'thumbnail' => ['invalid'], 'category' => ['invalid']]),
        'not an object',
    ];
    if (function_exists('symlink')) {
        symlink($source, $libraryRoot . '/outside.jpg');
        $invalidRows[] = array_replace($row, ['id' => 'outside-symlink', 'file' => 'images/hero/outside.jpg']);
    }
    $write(['version' => 1, 'categories' => [['id' => 'bedrooms', 'title' => 'Bedrooms']], 'scenes' => [$row, ...$invalidRows]]);
    $catalog = $service->catalog();
    $assert(count($catalog['scenes']) === 1 && count($catalog['unavailable']) === count($invalidRows), 'Missing, invalid, duplicate, or escaping entries must never become selectable.');
    $assert($catalog['categories'][0]['count'] === 1 && count($catalog['warnings']) === 1, 'Unavailable entries must not inflate category counts.');

    $badZone = array_replace($row, ['modelZone' => ['x' => 'bad', 'y' => 0, 'width' => 10, 'height' => 10], 'textZone' => ['x' => 100, 'y' => 0, 'width' => 4, 'height' => 2]]);
    $write(['version' => 1, 'scenes' => [$badZone]]);
    $catalog = $service->catalog();
    $assert($catalog['scenes'][0]['modelZone'] === null && $catalog['scenes'][0]['textZone'] === null, 'Malformed and zero-area placement zones must be omitted.');
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
} finally {
    restore_error_handler();
    $remove($fixture);
}

if ($failures !== []) {
    fwrite(STDERR, "Hero library tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Hero library tests passed: metadata, real-image validation, counts, malformed manifests, missing files, path confinement, and placement zones.\n";
