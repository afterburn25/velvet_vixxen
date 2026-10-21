<?php
declare(strict_types=1);

/** Public aliases share one protected implementation tree and one real entry point. */
function vvx_public_routes(): array
{
    return [
    'about' => 'about/index.php',
    'about/index.php' => 'about/index.php',
    'join' => 'account/register.php',
    'join/index.php' => 'account/register.php',
    'login' => 'account/login.php',
    'login/index.php' => 'account/login.php',
    'create-account' => 'account/register.php',
    'create-account/index.php' => 'account/register.php',
    'recover' => 'account/forgot.php',
    'recover/index.php' => 'account/forgot.php',
    'account' => 'account/index.php',
    'account/' => 'account/index.php',
    'account/index.php' => 'account/index.php',
    'account/billing.php' => 'account/billing.php',
    'account/forgot.php' => 'account/forgot.php',
    'account/library.php' => 'account/library.php',
    'account/login.php' => 'account/login.php',
    'account/logout.php' => 'account/logout.php',
    'account/register.php' => 'account/register.php',
    'account/reset.php' => 'account/reset.php',
    'account/security.php' => 'account/security.php',
    'account/verify.php' => 'account/verify.php',
    'api/addon-license.php' => 'api/addon-license.php',
    'api/marketing-event.php' => 'api/marketing-event.php',
    'api/mail-events.php' => 'api/mail-events.php',
    'api/member-state.php' => 'api/member-state.php',
    'api/payment-webhook.php' => 'api/payment-webhook.php',
    'api/scene.php' => 'api/scene.php',
    'api/upload.php' => 'api/upload.php',
    'blog' => 'blog/index.php',
    'blog/' => 'blog/index.php',
    'blog/index.php' => 'blog/index.php',
    'blog/post.php' => 'blog/post.php',
    'booking' => 'booking/index.php',
    'booking/' => 'booking/index.php',
    'booking/index.php' => 'booking/index.php',
    'campaign' => 'campaign/index.php',
    'campaign/' => 'campaign/index.php',
    'campaign/index.php' => 'campaign/index.php',
    'campaign/click.php' => 'campaign/click.php',
    'privacy/choices.php' => 'privacy/choices.php',
    'cart' => 'cart/index.php',
    'cart/' => 'cart/index.php',
    'cart/index.php' => 'cart/index.php',
    'checkout' => 'checkout/index.php',
    'checkout/' => 'checkout/index.php',
    'checkout/index.php' => 'checkout/index.php',
    'checkout/success.php' => 'checkout/success.php',
    'collections' => 'collections/index.php',
    'collections/' => 'collections/index.php',
    'collections/index.php' => 'collections/index.php',
    'communication/download.php' => 'communication/download.php',
    'extension' => 'extension/index.php',
    'extension/' => 'extension/index.php',
    'extension/index.php' => 'extension/index.php',
    'links' => 'links/index.php',
    'links/' => 'links/index.php',
    'links/index.php' => 'links/index.php',
    'membership' => 'membership/index.php',
    'membership/' => 'membership/index.php',
    'membership/index.php' => 'membership/index.php',
    'messages' => 'messages/index.php',
    'messages/' => 'messages/index.php',
    'messages/index.php' => 'messages/index.php',
    'music' => 'music/index.php',
    'music/' => 'music/index.php',
    'music/index.php' => 'music/index.php',
    'newsletter' => 'newsletter/index.php',
    'newsletter/' => 'newsletter/index.php',
    'newsletter/index.php' => 'newsletter/index.php',
    'newsletter/confirm.php' => 'newsletter/confirm.php',
    'newsletter/unsubscribe.php' => 'newsletter/unsubscribe.php',
    'photos' => 'photos/index.php',
    'photos/' => 'photos/index.php',
    'photos/index.php' => 'photos/index.php',
    'photos/view.php' => 'photos/view.php',
    'receipt' => 'receipt/index.php',
    'receipt/' => 'receipt/index.php',
    'receipt/index.php' => 'receipt/index.php',
    'requests' => 'requests/index.php',
    'requests/' => 'requests/index.php',
    'requests/index.php' => 'requests/index.php',
    'shop' => 'shop/index.php',
    'shop/' => 'shop/index.php',
    'shop/index.php' => 'shop/index.php',
    'videos' => 'videos/index.php',
    'videos/' => 'videos/index.php',
    'videos/index.php' => 'videos/index.php',
    'videos/watch.php' => 'videos/watch.php',
    'media.php' => 'media.php',
    ];
}

/** Match only declared routes; filenames never come from request input. */
function vvx_public_route_name(string $path): ?string
{
    if ($path === '' || str_contains($path, "\\") || preg_match('/[\x00-\x20\x7f]/', $path)
        || preg_match('/%(?![0-9a-f]{2})/i', $path)) return null;
    $route = rawurldecode($path);
    if (str_contains($route, '..') || str_contains($route, "\\")
        || preg_match('/[\x00-\x20\x7f]/', $route) || str_contains($route, '//')) return null;
    $route = trim($route, '/');
    return isset(vvx_public_routes()[$route]) ? $route : null;
}

/** Keep application parameters intact while reserving our dispatcher key. */
function vvx_public_route_query(string $query): string
{
    if ($query === '') return '';
    $parts = [];
    foreach (explode('&', $query) as $part) {
        $parameter = [];
        parse_str($part, $parameter);
        if (!array_key_exists('vvx_route', $parameter) && $part !== '') $parts[] = $part;
    }
    return implode('&', $parts);
}

/** Physical canonical URL for a known local route; asset/admin paths stay literal. */
function vvx_public_route_relative_url(string $value): string
{
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) return $value;
    $route = vvx_public_route_name((string) ($parts['path'] ?? ''));
    if ($route === null) return $value;
    $query = vvx_public_route_query((string) ($parts['query'] ?? ''));
    return 'route.php?vvx_route=' . rawurlencode($route)
        . ($query === '' ? '' : '&' . $query)
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

/** Resolve validated local destinations against this installation exactly once. */
function vvx_public_destination(string $base, string $value): string
{
    $base = rtrim($base, '/');
    $baseParts = parse_url($base);
    $parts = parse_url($value);
    if (!is_array($baseParts) || !is_array($parts)) return $base . '/';
    $prefix = rtrim((string) ($baseParts['path'] ?? ''), '/');
    if (isset($parts['scheme']) || isset($parts['host'])) {
        $port = static fn(array $url): int => (int) ($url['port'] ?? (strtolower((string) ($url['scheme'] ?? '')) === 'https' ? 443 : 80));
        if (strtolower((string) ($parts['scheme'] ?? '')) !== strtolower((string) ($baseParts['scheme'] ?? ''))
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($baseParts['host'] ?? ''))
            || $port($parts) !== $port($baseParts) || isset($parts['user']) || isset($parts['pass'])) return $value;
        $path = (string) ($parts['path'] ?? '/');
        if ($prefix !== '' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) return $value;
    } else {
        $path = (string) ($parts['path'] ?? '');
    }
    if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) $path = substr($path, strlen($prefix));
    $relative = ltrim($path, '/')
        . (isset($parts['query']) ? '?' . $parts['query'] : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    return $base . '/' . vvx_public_route_relative_url($relative);
}

/** Analytics use the logical page path and never persist query tokens. */
function vvx_public_request_path(string $requestUri): string
{
    $parts = parse_url($requestUri);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
    if (!str_starts_with($path, '/')) return '/';
    if (basename($path) !== 'route.php') return $path;
    $parameters = [];
    parse_str((string) ($parts['query'] ?? ''), $parameters);
    $selector = $parameters['vvx_route'] ?? null;
    $route = is_string($selector) ? vvx_public_route_name($selector) : null;
    if ($route === null) return $path;
    $prefix = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $prefix . '/' . $route;
}
