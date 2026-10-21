<?php
declare(strict_types=1);

use App\Core\MenuService;

$menuLocation = isset($menuLocation) && in_array($menuLocation, ['header', 'footer'], true) ? $menuLocation : 'header';
$menuReadError = '';
try { $menuSnapshot = $store->navigationMenu($menuLocation); }
catch (Throwable) {
    $menuSnapshot = ['revision' => 0, 'configured' => true, 'items' => []];
    $menuReadError = 'The saved menu could not be loaded. Reload the page or restore a verified copy before changing it.';
}
$menuJson = is_string($menuAttemptedJson ?? null) ? $menuAttemptedJson
    : json_encode($menuSnapshot['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$menuFormRevision = is_string($menuAttemptedRevision ?? null) ? $menuAttemptedRevision : (string) $menuSnapshot['revision'];
$menuPayloadInvalid = $menuReadError !== '';
try {
    if (!is_array(json_decode($menuJson, false, 32, JSON_THROW_ON_ERROR))) throw new RuntimeException('Invalid menu list.');
    MenuService::revision($menuFormRevision);
}
catch (Throwable) { $menuPayloadInvalid = true; }
$menuLocationLabel = $menuLocation === 'header' ? 'Header' : 'Footer';
$menuFormUrl = base_url('admin/?page=menus&location=' . $menuLocation);
?>
<div class="menu-admin-page">
<section class="admin-card menu-intro">
  <div class="card-head"><div><span class="admin-kicker">Site &amp; Design</span><h2>Menus</h2></div></div>
  <p class="muted-copy">Choose a menu, edit its links and submenus, then save to update the website. Account and cart controls, legal links and the designer credit stay available separately.</p>
  <nav class="menu-locations menu-location-links" aria-label="Choose menu">
    <a data-menu-location-link class="mini-button<?= $menuLocation === 'header' ? ' active' : '' ?>" <?= $menuLocation === 'header' ? 'aria-current="page"' : '' ?> href="<?= e(base_url('admin/?page=menus&location=header')) ?>">Header</a>
    <a data-menu-location-link class="mini-button<?= $menuLocation === 'footer' ? ' active' : '' ?>" <?= $menuLocation === 'footer' ? 'aria-current="page"' : '' ?> href="<?= e(base_url('admin/?page=menus&location=footer')) ?>">Footer</a>
  </nav>
</section>
<?php if ($menuReadError !== ''): ?><div class="admin-alert error"><?= e($menuReadError) ?></div><?php endif; ?>
<form method="post" action="<?= e($menuFormUrl) ?>" class="admin-card menu-editor" data-menu-editor data-menu-location="<?= e($menuLocation) ?>" data-max-depth="3" data-max-items="50"<?= is_string($menuAttemptedJson ?? null) ? ' data-menu-attempted="true"' : '' ?><?= $menuPayloadInvalid ? ' data-menu-load-error="true"' : '' ?>>
  <?= csrf_field() ?><input type="hidden" name="action" value="menu_save"><input type="hidden" name="location" value="<?= e($menuLocation) ?>"><input type="hidden" name="revision" value="<?= e($menuFormRevision) ?>">
  <textarea name="menu_json" data-menu-json hidden><?= e($menuJson) ?></textarea>
  <div class="card-head menu-editor-toolbar"><div><span class="admin-kicker"><?= $menuSnapshot['configured'] ? 'Custom menu' : 'Original defaults' ?></span><h2><?= e($menuLocationLabel) ?> menu</h2></div><button type="button" class="mini-button" data-menu-add-root disabled>Add item</button></div>
  <p class="muted-copy">Up to 50 items and three levels. A parent can have its own destination or only open its submenu. Hiding a parent also hides its children.</p>
  <?php if ($menuLocation === 'footer' && !$menuSnapshot['configured']): ?><p class="muted-copy">This starting list includes Messages. Saving a custom Footer menu uses your saved list on every public page; the original page-specific defaults remain until you save.</p><?php endif; ?>
  <ol class="menu-editor-tree" data-menu-tree>
    <?php foreach ($menuSnapshot['items'] as $menuItem): ?><li><?= e($menuItem['label']) ?><?= !$menuItem['enabled'] ? ' (hidden)' : '' ?></li><?php endforeach; ?>
  </ol>
  <p data-menu-editor-status role="status" aria-live="polite"><?= $menuPayloadInvalid ? 'Your submitted menu could not be opened safely. Reload the saved menu before editing again.' : 'Loading the menu editor…' ?></p>
  <noscript><p class="muted-copy">Enable JavaScript to edit menu items. Your saved menu is unchanged.</p></noscript>
  <div class="menu-editor-actions menu-editor-save-row"><button type="submit" class="admin-button primary" data-menu-save disabled>Save menu</button><a class="mini-button" data-menu-reload href="<?= e($menuFormUrl) ?>">Reload saved menu</a></div>
  <p class="muted-copy">Same tab keeps visitors here. New tab opens a separate tab. New window requests a separate browser window; browser settings may open a tab or block that request.</p>
</form>
<form method="post" action="<?= e($menuFormUrl) ?>" class="menu-reset-form" data-menu-reset>
  <?= csrf_field() ?><input type="hidden" name="action" value="menu_reset"><input type="hidden" name="location" value="<?= e($menuLocation) ?>"><input type="hidden" name="revision" value="<?= e($menuFormRevision) ?>">
  <button type="submit" class="mini-button"<?= $menuReadError !== '' ? ' disabled' : '' ?>>Restore default menu</button>
  <span class="muted-copy">Replaces this menu's custom items with the original defaults.</span>
</form>
</div>
