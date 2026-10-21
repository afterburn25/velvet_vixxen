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
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $public = realpath(ROOT_PATH . '/public');
    // Resolve real files so a symlinked installation or the physical home
    // implementation cannot bypass the same gate as its canonical route.
    if (!defined('VVX_PUBLIC_ROUTE') && $script !== realpath(ROOT_PATH . '/index.php')
        && (!is_string($script) || !is_string($public) || !str_starts_with($script, $public . '/'))) return;
    $logical = defined('VVX_PUBLIC_ROUTE') ? VVX_PUBLIC_ROUTE
        : (is_string($script) && str_starts_with($script, $public . '/routes/') ? substr($script, strlen($public . '/routes/')) : '');
    if (App\Core\AgeAccess::helpRoute($logical)) return;
    $ageAccess = new App\Core\AgeAccess(app_config());
    $accessDecision = $ageAccess->decision($_SESSION, $_SERVER);
    if ($accessDecision === 'allowed') return;
    vvx_access_private(); http_response_code($accessDecision === 'unavailable' ? 503 : 403);
    $accessError = ''; $accessNotice = ''; $accessPage = false;
    require ROOT_PATH . '/app/Views/public/access.php'; exit;
}

vvx_access_bootstrap();
