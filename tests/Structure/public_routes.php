<?php
declare(strict_types=1);

// Every public destination uses one real dispatcher and an explicit protected
// handler. Page/API compatibility must not recreate directories in the root.
$root = dirname(__DIR__, 2);
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$check(is_file($root . '/route.php') && !is_link($root . '/route.php'), 'The physical public dispatcher is absent or linked.');
$routeSource = (string) file_get_contents($root . '/app/Support/public_routes.php');
$check((bool) preg_match('/function\s+vvx_public_routes\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[(.*?)\];/s', $routeSource, $routeBlock), 'The explicit shared public routing table is missing.');
require_once $root . '/app/Support/public_routes.php';
preg_match_all("/'([^']+)'\\s*=>\\s*'([^']+)'/", $routeBlock[1], $pairs, PREG_SET_ORDER);
$routes = [];
$destinations = [];
foreach ($pairs as $pair) {
    [, $route, $handler] = $pair;
    $check(!isset($routes[$route]), 'A duplicate public route can shadow an earlier mapping: ' . $route);
    $check((bool) preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*(?:\.php)?/?$#D', $route), 'Public route has an unsafe path: ' . $route);
    $check((bool) preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*\.php$#D', $handler), 'Public handler has an unsafe path: ' . $handler);
    $routes[$route] = $handler;
    $destination = str_ends_with($route, '.php') ? $route : rtrim($route, '/') . '/index.php';
    $check(!isset($destinations[$destination]) || $destinations[$destination] === $handler,
        'Friendly and explicit aliases disagree on their organized handler: ' . $destination);
    $destinations[$destination] = $handler;
}
$check(count($destinations) === 46 && count($routes) === 87, 'A required public alias was removed or duplicated.');
$check($routes === vvx_public_routes(), 'The executable route map differs from the declared unique mappings.');
$check(count(array_unique($routes)) === 42, 'A protected public implementation was removed.');
foreach (['join' => 'account/register.php', 'login' => 'account/login.php',
    'create-account' => 'account/register.php', 'recover' => 'account/forgot.php'] as $alias => $handler) {
    $check(($routes[$alias] ?? null) === $handler && ($routes[$alias . '/index.php'] ?? null) === $handler,
        'Short authentication aliases must select the same protected handler: ' . $alias);
}

foreach ($destinations as $destination => $handler) {
    $handlerFile = $root . '/public/routes/' . $handler;
    $check(!file_exists($root . '/' . $destination) && !is_link($root . '/' . $destination),
        'A retired physical public entrypoint was recreated in the root: ' . $destination);
    $check(is_file($handlerFile) && !is_link($handlerFile), 'Registered application handler is absent or linked: ' . $handler);
    $check(str_starts_with((string) realpath($handlerFile), (string) realpath($root . '/public/routes') . DIRECTORY_SEPARATOR),
        'Public handler escapes the protected routing tree: ' . $handler);
}
foreach ($routes as $route => $handler) {
    $url = vvx_public_route_relative_url($route . '?id=27&token=ab%2Bcd%3D#details');
    $parts = parse_url($url);
    $parameters = [];
    parse_str((string) ($parts['query'] ?? ''), $parameters);
    $selected = vvx_public_route_name((string) ($parameters['vvx_route'] ?? ''));
    $check(($parts['path'] ?? null) === 'route.php' && $selected !== null
        && (vvx_public_routes()[$selected] ?? null) === $handler,
        'A generated route does not address the real dispatcher and its registered handler: ' . $route);
    $check(($parameters['id'] ?? null) === '27' && ($parameters['token'] ?? null) === 'ab+cd='
        && ($parts['fragment'] ?? null) === 'details',
        'Central dispatch changed application query parameters or the fragment: ' . $route);
    $check(!isset($parts['host']) && !isset($parts['scheme']), 'Local public dispatcher URL became external.');
}

// Exercise the actual application router in separate PHP processes. Unknown,
// encoded traversal and NUL paths must terminate before any installed handlers.
$probe = <<<'PHP'
ob_start();
register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => $body], JSON_THROW_ON_ERROR);
});
$_SERVER['REQUEST_URI'] = $argv[2];
$_SERVER['SCRIPT_NAME'] = $argv[3];
$_SERVER['REQUEST_METHOD'] = 'POST';
parse_str((string) (parse_url($argv[2], PHP_URL_QUERY) ?? ''), $_GET);
$_POST = ['vvx_route' => 'api/upload.php'];
$_REQUEST = array_merge($_GET, $_POST);
require $argv[1] . '/route.php';
PHP;
$invalid = ['/unknown-public-route', '/api/../storage/data.json', '/api/%2e%2e/storage/data.json',
    '/api/upload.php%00', '/public/routes/api/upload.php', '/api/upload.php/extra',
    '/unknown-public-route?route=api/upload.php', '/unknown-public-route?vvx_route=api/upload.php',
    '/route.php', '/route.php?route=api/upload.php', '/route.php?vvx_route=',
    '/route.php?vvx_route=unknown-public-route', '/route.php?vvx_route=public/routes/api/upload.php',
    '/route.php?vvx_route=../storage/data.json', '/route.php?vvx_route=%252e%252e%2fstorage%2fdata.json',
    '/route.php?vvx_route=api%2fupload.php%00', '/route.php?vvx_route[]=api/upload.php',
    '/route.php?vvx_route=api/upload.php&vvx_route=api/scene.php',
    '/route.php?vvx_route=api/upload.php&vvx%5froute=api/scene.php',
    '/route.php?vvx_route=api/upload.php/extra'];
foreach (['', '/nested/velvet'] as $prefix) {
    foreach ($invalid as $request) {
        $process = proc_open([PHP_BINARY, '-r', $probe, $root, $prefix . $request, $prefix . '/route.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $check(is_resource($process), 'Could not execute actual public router rejection probe.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode((string) $stdout, true);
        $check($exit === 0 && $stderr === '' && is_array($result)
            && ($result['status'] ?? null) === 404 && ($result['body'] ?? null) === 'Not found',
            'Application router failed to reject an unknown/unsafe request: ' . $prefix . $request);
    }
}
echo 'Organized public dispatch and path safety passed: one physical dispatcher, ' . count(array_unique($routes)) . ' protected handlers, '
    . count($routes) . ' registered URLs, ' . $assertions . " assertions.\n";
