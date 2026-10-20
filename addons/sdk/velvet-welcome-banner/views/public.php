<?php
$headline = (string) $addon->setting('headline', 'Welcome to the Velvet Vixxen.');
$copy = (string) $addon->setting('copy', 'This public route is registered by an installed add-on.');
?>
<section class="member-auth-card"><span class="eyebrow pink">Add-on public route</span><h1><?= e($headline) ?></h1><p><?= e($copy) ?></p><p class="member-muted">The core router did not need to be edited. Deactivating the add-on removes this route and its homepage widget.</p><a class="button button--primary" href="<?= e(base_url()) ?>">Return Home</a></section>
