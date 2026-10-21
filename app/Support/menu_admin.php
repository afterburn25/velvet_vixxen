<?php
declare(strict_types=1);

/** The current stored owner, rather than a role cached in the login session. */
function vvx_menu_require_owner(): array
{
    try {
        $identity = app_auth()->user();
        if (!is_array($identity)) throw new RuntimeException('Owner session is missing.');
        $fresh = new App\Core\Store(new App\Core\Config(app_config()->path()));
        return $fresh->requireNavigationOwner($identity);
    } catch (Throwable) {
        throw new RuntimeException('Only the active site owner can manage menus.', 403);
    }
}

function vvx_menu_request_location(): string
{
    return App\Core\MenuService::location($_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['location'] ?? null) : ($_GET['location'] ?? 'header'));
}
