<?php
$settings = [
    'enabled' => (bool) $addon->setting('enabled', true),
    'headline' => (string) $addon->setting('headline', 'Welcome to the Velvet Vixxen.'),
    'copy' => (string) $addon->setting('copy', 'This banner, its admin page, and its public route are provided by the installed starter add-on.'),
    'button_label' => (string) $addon->setting('button_label', 'Learn More'),
];
?>
<div class="admin-card addon-owned-page">
  <div class="card-head"><div><span class="admin-kicker">Starter add-on</span><h2>Welcome Banner</h2></div><a class="admin-button secondary" href="<?= e(base_url('extension/?route=welcome')) ?>" target="_blank">Open Public Route ↗</a></div>
  <p class="muted-copy">This page is registered by <code>velvet.welcome-banner</code>. Saving writes only to this add-on's namespaced settings.</p>
  <form method="post" class="settings-grid addon-settings-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_addon_settings"><input type="hidden" name="addon_id" value="<?= e($addon->id()) ?>"><input type="hidden" name="return_page" value="welcome-banner">
    <section>
      <label>Headline<input name="settings[headline]" maxlength="120" value="<?= e($settings['headline']) ?>" required></label>
      <label>Supporting copy<textarea name="settings[copy]" rows="5" maxlength="600"><?= e($settings['copy']) ?></textarea></label>
      <label>Button label<input name="settings[button_label]" maxlength="50" value="<?= e($settings['button_label']) ?>"></label>
      <label class="toggle-row"><input type="checkbox" name="settings[enabled]" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>><span><b>Show homepage banner</b><small>Disable the widget without deactivating the entire add-on.</small></span></label>
      <button class="admin-button primary">Save Banner Settings</button>
    </section>
  </form>
</div>
