<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) {
    redirect(base_url('install/'));
}
$route = trim((string) ($_GET['route'] ?? ''), '/ ');
$content = addon_runtime()->renderPublicRoute($route, ['route' => $route, 'member' => current_member()]);
if ($content === null) {
    http_response_code(404);
    $content = '<section class="member-auth-card"><span class="eyebrow pink">Add-on route</span><h1>Page not found.</h1><p>The requested extension route is unavailable or its add-on is inactive.</p><a class="button button--primary" href="' . e(base_url()) . '">Return Home</a></section>';
}
$site = (array) app_store()->setting('site', []);
$pageTitle = 'Extension · ' . (string) ($site['brand_name'] ?? 'Nikki Wilder');
require root_path('app/Views/public/partials/header.php');
?>
<main class="member-page extension-route"><div class="member-page-shell"><?= $content ?></div></main>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
