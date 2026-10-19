<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/app/Support/public_routes.php';

$reject = static function (): never {
    http_response_code(404);
    exit('Not found');
};
$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'));
if (!is_array($uri)) $reject();
$uriPath = (string) ($uri['path'] ?? '/');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/route.php'));
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '.') $basePath = '';
if ($basePath !== '') {
    if (!str_starts_with($uriPath, $basePath . '/')) $reject();
    $uriPath = substr($uriPath, strlen($basePath));
}

if ($uriPath === '/route.php') {
    // Read only the URL query, never a POST body or an arbitrary include path.
    $query = (string) ($uri['query'] ?? '');
    $parameters = [];
    parse_str($query, $parameters);
    $selector = $parameters['vvx_route'] ?? null;
    $count = 0;
    foreach (explode('&', $query) as $part) {
        $single = [];
        parse_str($part, $single);
        if (array_key_exists('vvx_route', $single)) $count++;
    }
    if (!is_string($selector) || $count !== 1) $reject();
    $route = vvx_public_route_name($selector);
    unset($_GET['vvx_route'], $_REQUEST['vvx_route']);
} else {
    // Existing pretty URLs remain real web-server rewrite aliases. A query
    // cannot change the handler selected by one of those paths.
    $route = vvx_public_route_name($uriPath);
}
if ($route === null) $reject();
$target = vvx_public_routes()[$route];
$file = __DIR__ . '/routes/' . $target;
$handlers = realpath(__DIR__ . '/routes');
$resolved = realpath($file);
if ($handlers === false || $resolved === false || is_link($file) || !is_file($resolved)
    || !str_starts_with($resolved, $handlers . DIRECTORY_SEPARATOR)) $reject();

define('VVX_PUBLIC_ROUTE', $route);
require $resolved;
