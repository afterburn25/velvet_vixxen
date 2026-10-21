<?php
declare(strict_types=1);
$adminGroups = [
    'dashboard' => ['Dashboard', ['dashboard']],
    'design' => ['Site & Design', ['composer', 'scene-schedules', 'menus', 'about', 'canva', 'settings']],
    'content' => ['Content & Media', ['media', 'music', 'galleries', 'videos', 'protection']],
    'members' => ['Members & Communications', ['members', 'entitlements', 'messages', 'content-requests', 'booking-requests', 'booking-form', 'communication-settings']],
    'commerce' => ['Commerce', ['commerce', 'merchandise', 'orders', 'plans', 'coupons', 'bundles', 'payments']],
    'marketing' => ['Marketing', ['marketing']],
    'extensions' => ['Extensions', ['addons', 'licenses']],
    'system' => ['System', ['security', 'operations', 'mail-settings', 'marketing-storage', 'update-status', 'database-updates', 'repair-backups']],
];
$adminAssigned = [];
foreach ($adminGroups as [, $keys]) $adminAssigned = array_merge($adminAssigned, $keys);
$adminGroups['extensions'][1] = array_merge($adminGroups['extensions'][1], array_values(array_diff(array_keys($nav), $adminAssigned)));
$adminStorageKey = 'velvet-admin-groups:' . substr(hash('sha256', base_url() . '|' . (string) ($user['id'] ?? '')), 0, 20);
?>
<aside class="admin-sidebar" id="adminSidebar">
<a class="admin-brand" href="<?= e(base_url('admin/?page=dashboard')) ?>"><span>Nikki Wilder</span><small>The Velvet Vixxen</small></a>
<nav aria-label="Administration" data-admin-navigation data-storage-key="<?= e($adminStorageKey) ?>">
<?php foreach ($adminGroups as $group => [$label, $keys]): $keys = array_values(array_filter($keys, static fn(string $key): bool => isset($nav[$key]))); if ($keys === []) continue; ?>
<details class="admin-nav-group" data-admin-group="<?= e($group) ?>" <?= in_array($page, $keys, true) ? 'open' : '' ?>>
  <summary><?= e($label) ?></summary><div>
  <?php foreach ($keys as $key): [$icon, $itemLabel] = $nav[$key]; ?><a class="<?= $page === $key ? 'active' : '' ?>" <?= $page === $key ? 'aria-current="page"' : '' ?> href="<?= e(base_url($key === 'update-status' ? 'admin/repair.php' : 'admin/?page=' . rawurlencode($key))) ?>"><i aria-hidden="true"><?= e($icon) ?></i><span><?= e($itemLabel) ?></span></a><?php endforeach; ?>
  </div>
</details>
<?php endforeach; ?>
</nav><div class="sidebar-bottom"><a href="<?= e(base_url()) ?>" target="_blank" rel="noopener"><i aria-hidden="true">↗</i><span>View Website</span></a><a href="<?= e(base_url('admin/?page=logout')) ?>"><i aria-hidden="true">⇥</i><span>Sign Out</span></a></div>
</aside>
