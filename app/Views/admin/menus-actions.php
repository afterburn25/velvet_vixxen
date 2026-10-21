<?php
declare(strict_types=1);

if (!$isMenuRequest || $page !== 'menus' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf()) {
    throw new RuntimeException('Open Menus and submit the owner form.', 400);
}
$menuAttemptedJson = is_string($_POST['menu_json'] ?? null) ? $_POST['menu_json'] : null;
$menuAttemptedRevision = is_string($_POST['revision'] ?? null) ? $_POST['revision'] : null;
$menuLocation = vvx_menu_request_location();
$menuRevision = App\Core\MenuService::revision($_POST['revision'] ?? null);
$menuAction = $_POST['action'] ?? null;
if (!is_string($menuAction) || !in_array($menuAction, ['menu_save', 'menu_reset'], true)) {
    throw new InvalidArgumentException('Choose Save menu or Restore default menu.');
}
$menuOwnerIdentity = vvx_menu_require_owner();
if ($menuAction === 'menu_save') {
    if ($menuAttemptedJson === null) throw new InvalidArgumentException('The menu is missing. Reload the saved menu before trying again.');
    $menuItems = App\Core\MenuService::parseItems($menuAttemptedJson);
    $store->saveNavigationMenu($menuLocation, $menuItems, $menuRevision, $menuOwnerIdentity);
    flash('success', ($menuLocation === 'header' ? 'Header' : 'Footer') . ' menu saved. The public menu is updated.');
} else {
    $store->resetNavigationMenu($menuLocation, $menuRevision, $menuOwnerIdentity);
    flash('success', ($menuLocation === 'header' ? 'Header' : 'Footer') . ' menu now uses its original defaults.');
}
redirect(base_url('admin/?page=menus&location=' . rawurlencode($menuLocation)));
