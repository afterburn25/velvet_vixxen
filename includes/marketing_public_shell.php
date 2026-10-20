<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/marketing.php';
velvet_marketing_private_response();

function velvet_marketing_public_header(string $title, string $description = '', ?array $editorialMetadata = null): void
{
    $pageTitle = $title;
    // Opt in only after the caller has authorized the saved publication.
    // Other pages keep their established document-title and description defaults.
    $marketingPageMetadata = $editorialMetadata;
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
