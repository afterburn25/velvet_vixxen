<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

$uriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/route.php'));
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath !== '' && $basePath !== '/' && str_starts_with($uriPath, $basePath . '/')) {
    $uriPath = substr($uriPath, strlen($basePath));
}
$route = trim(rawurldecode($uriPath), '/');
$route = preg_replace('#/+#', '/', $route) ?? '';

if ($route === '' || str_contains($route, "\0") || str_contains($route, '..')) {
    http_response_code(404);
    exit('Not found');
}

$routes = [
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

$target = $routes[$route] ?? null;
if ($target === null) {
    http_response_code(404);
    exit('Not found');
}

$file = __DIR__ . '/routes/' . $target;
if (!is_file($file)) {
    http_response_code(404);
    exit('Not found');
}

require $file;
