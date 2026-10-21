<?php
declare(strict_types=1);

function vvx_access_private(): void
{
    header('Cache-Control: no-store, private, max-age=0'); header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow, noarchive');
}

/** Before ordinary content, scenes, thumbnails, or a previously signed media URL. */
function vvx_access_bootstrap(): void
{
    if (PHP_SAPI === 'cli' || !is_installed()) return;
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!in_array($script, [ROOT_PATH . '/index.php', ROOT_PATH . '/route.php'], true)) return;
    if (defined('VVX_PUBLIC_ROUTE') && App\Core\AgeAccess::helpRoute(VVX_PUBLIC_ROUTE)) return;
    $ageAccess = new App\Core\AgeAccess(app_config());
    $accessDecision = $ageAccess->decision($_SESSION, $_SERVER);
    if ($accessDecision === 'allowed') return;
    vvx_access_private(); http_response_code($accessDecision === 'unavailable' ? 503 : 403);
    $accessError = ''; $accessNotice = ''; $accessPage = false;
    require ROOT_PATH . '/app/Views/public/access.php'; exit;
}

vvx_access_bootstrap();
