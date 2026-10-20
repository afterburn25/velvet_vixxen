<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'public/routes/music/index.php','public/routes/photos/index.php','public/routes/photos/view.php','public/routes/videos/index.php','public/routes/videos/watch.php','public/routes/media.php',
    'app/Core/MediaAccess.php','app/Core/MediaUploadService.php','app/Core/MediaAdminService.php','app/Core/MediaStoreTrait.php',
    'app/Views/public/partials/header.php','app/Views/public/partials/player.php','app/Views/public/partials/footer.php',
    'app/Views/admin/media-center.php','public/assets/css/media-center.css','public/assets/js/media-center.js','public/assets/js/admin-media.js',
];
$missing = array_values(array_filter($required, static fn(string $file): bool => !is_file($root . '/' . $file)));
if ($missing !== []) {
    fwrite(STDERR, "Media Center files missing:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

$assertContains = static function (string $path, array $needles) use ($root): void {
    $content = (string) file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$path} is missing required behavior: {$needle}\n");
            exit(1);
        }
    }
};
$assertContains('public/assets/js/media-center.js', ['localStorage', 'data-player', 'data-lightbox', 'velvetVideoProgress', 'mediaSession']);
$assertContains('app/Views/public/partials/player.php', ['data-player-audio', 'data-player-queue', 'previewSeconds']);
$assertContains('public/routes/photos/view.php', ['data-lightbox-item', 'media_asset_src($asset,true)', 'locked-photo-overlay']);
$assertContains('public/routes/videos/watch.php', ['data-video-player', '<track', 'media_asset_src($playAsset,true)', '$hidePersistentPlayer=true']);
$assertContains('admin/index.php', ["'music' => 'Music'", "'galleries' => 'Photo Galleries'", "'videos' => 'Videos'", 'MediaAdminService']);
$assertContains('index.php', ["'/public/home.php'"]);
$assertContains('public/home.php', ["base_url('music/')", "base_url('photos/')", "base_url('videos/')", 'app/Views/public/partials/player.php']);

$defaults = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true);
if (!is_array($defaults) || count((array) ($defaults['mediaCenter']['galleries'] ?? [])) < 5) {
    fwrite(STDERR, "The default Media Center must include five gallery demonstrations.\n");
    exit(1);
}

echo "Media Center feature test passed.\n";
