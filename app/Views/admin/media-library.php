<?php
declare(strict_types=1);
$media = $store->media();
$libraryPreview = static function (array $asset): string {
    if (!empty($asset['private']) || str_starts_with((string) ($asset['file'] ?? ''), 'private:')) return '';
    foreach ([$asset['thumbnail'] ?? '', $asset['file'] ?? ''] as $candidate) {
        $path = ltrim(str_replace('\\', '/', (string) $candidate), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, ':')
            || !preg_match('/\.(?:png|jpe?g|webp|gif|avif)$/i', $path)) continue;
        $protected = (new App\Core\ProtectionDelivery(app_config()))->url($path, (string) $asset['id']);
        if ($protected !== null) return $protected === '' ? '' : base_url($protected);
        $relative = str_starts_with($path, 'uploads/') ? $path
            : (str_starts_with($path, 'images/') && is_file(root_path($path)) ? $path : 'uploads/' . $path);
        $absolute = realpath(root_path($relative));
        $imageRoot = realpath(root_path('images'));
        $uploadRoot = realpath(root_path('uploads'));
        if ($absolute === false || !is_file($absolute)
            || !(($imageRoot && str_starts_with($absolute, $imageRoot . DIRECTORY_SEPARATOR))
                || ($uploadRoot && str_starts_with($absolute, $uploadRoot . DIRECTORY_SEPARATOR)))) continue;
        if (@getimagesize($absolute) !== false) return base_url($relative);
    }
    return '';
};
?>
<section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Backgrounds and transparent photos</span><h2>Media Library</h2></div><button class="admin-button primary" type="button" onclick="document.getElementById('mediaUpload').showModal()">Upload Images</button></div><p class="muted-copy">Transparent PNG and WebP files can be dragged into the Visual Composer. A custom thumbnail may be uploaded separately for easier identification. Audio, video and caption records without an image preview display their file type.</p></section>
<div class="library-grid">
<?php foreach ($media as $asset): $preview = $libraryPreview($asset); ?>
  <article class="library-card">
    <?php if ($preview !== ''): ?><img src="<?= e($preview) ?>" alt="<?= e((string) ($asset['title'] ?? 'Media image')) ?>" loading="lazy">
    <?php else: ?><div class="library-file-preview"><strong><?= e(strtoupper((string) ($asset['type'] ?? 'FILE'))) ?></strong><span><?= !empty($asset['private']) || str_starts_with((string) ($asset['file'] ?? ''), 'private:') ? 'Protected media' : 'No image preview' ?></span></div><?php endif; ?>
    <div><span><?= e((string) ($asset['type'] ?? 'file')) ?></span><h3><?= e((string) ($asset['title'] ?? 'Untitled')) ?></h3><small><?php if (!empty($asset['width']) && !empty($asset['height'])): ?><?= e((string) $asset['width']) ?> × <?= e((string) $asset['height']) ?><?php else: ?><?= e(basename((string) ($asset['file'] ?? ''))) ?><?php endif; ?></small></div>
  </article>
<?php endforeach; ?>
</div>
<dialog class="admin-dialog" id="mediaUpload"><form method="dialog" class="dialog-close"><button aria-label="Close">×</button></form><form id="libraryUpload" action="<?= e(base_url('api/upload.php')) ?>" method="post" enctype="multipart/form-data"><span class="admin-kicker">Add asset</span><h2>Upload an image</h2><label>Original image<input type="file" name="image" accept="image/png,image/jpeg,image/webp" required></label><label>Custom thumbnail <small>optional</small><input type="file" name="thumbnail" accept="image/png,image/jpeg,image/webp"></label><label>Asset type<select name="type"><option value="model">Model / transparent cutout</option><option value="background">Background</option><option value="thumbnail">Thumbnail</option></select></label><button class="admin-button primary full">Upload Image</button><div data-upload-message></div></form></dialog>
