<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/marketing.php';

function velvet_marketing_public_header(string $title, string $description = ''): void
{
    $pageTitle = $title;
    $activeNav = '';
    $marketingLayout = true;
    require root_path('app/Views/public/partials/header.php');
    velvet_marketing_render_banner();
}

function velvet_marketing_public_footer(): void
{
    echo '<script src="' . velvet_marketing_escape(asset_url('js/marketing.js')) . '" defer></script>';
    require root_path('app/Views/public/partials/footer.php');
}
