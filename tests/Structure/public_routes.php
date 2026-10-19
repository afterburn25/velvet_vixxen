<?php
declare(strict_types=1);

// A real physical entrypoint is required for every public URL. The development
// HTTP router must not hide missing files that shared hosting cannot execute.
$root = dirname(__DIR__, 2);
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$router = (string) file_get_contents($root . '/public/router.php');
$check((bool) preg_match('/\$routes\s*=\s*\[(.*?)\];/s', $router, $routeBlock), 'The explicit public routing table is missing.');
preg_match_all("/'([^']+)'\\s*=>\\s*'([^']+)'/", $routeBlock[1], $pairs, PREG_SET_ORDER);
$routes = [];
$entrypoints = [];
foreach ($pairs as $pair) {
    [, $route, $handler] = $pair;
    $check(!isset($routes[$route]), 'A duplicate public route can shadow an earlier mapping: ' . $route);
    $check((bool) preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*(?:\.php)?/?$#D', $route), 'Public route has an unsafe path: ' . $route);
    $check((bool) preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*\.php$#D', $handler), 'Public handler has an unsafe path: ' . $handler);
    $routes[$route] = $handler;
    $entrypoint = str_ends_with($route, '.php') ? $route : rtrim($route, '/') . '/index.php';
    $check(!isset($entrypoints[$entrypoint]) || $entrypoints[$entrypoint] === $handler,
        'Friendly and explicit URLs disagree on their physical handler: ' . $entrypoint);
    $entrypoints[$entrypoint] = $handler;
}
$check(count($entrypoints) >= 46, 'A required public route or physical entrypoint was removed.');
foreach (['join' => 'account/register.php', 'login' => 'account/login.php',
    'create-account' => 'account/register.php', 'recover' => 'account/forgot.php'] as $alias => $handler) {
    $check(($routes[$alias] ?? null) === $handler && ($routes[$alias . '/index.php'] ?? null) === $handler,
        'Short authentication alias must work through both direct PHP and rewriting: ' . $alias);
}

$semanticCode = static function (string $source): string {
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[0] === T_OPEN_TAG ? '<?php' : $token[1];
        } else {
            $code .= $token;
        }
    }
    return $code;
};
foreach ($entrypoints as $entrypoint => $handler) {
    $file = $root . '/' . $entrypoint;
    $handlerFile = $root . '/public/routes/' . $handler;
    $check(is_file($file) && !is_link($file), 'Public entrypoint is absent or linked: ' . $entrypoint);
    $check(is_file($handlerFile) && !is_link($handlerFile), 'Registered application handler is absent or linked: ' . $handler);
    $check(str_starts_with((string) realpath($handlerFile), (string) realpath($root . '/public/routes') . DIRECTORY_SEPARATOR),
        'Public handler escapes the protected routing tree: ' . $handler);
    $rootExpression = str_contains($entrypoint, '/') ? 'dirname(__DIR__)' : '__DIR__';
    // Enforce the promised thin compatibility layer: a fixed literal handler,
    // no query/path-derived includes, duplicate business logic, or extra actions.
    $expected = "<?php declare(strict_types=1); if (!defined('ROOT_PATH')) { define('ROOT_PATH', "
        . $rootExpression . "); } require ROOT_PATH . '/public/routes/" . $handler . "';";
    $check($semanticCode((string) file_get_contents($file)) === $semanticCode($expected),
        'Entrypoint must dispatch only its registered literal handler: ' . $entrypoint);
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
require $argv[1] . '/route.php';
PHP;
$invalid = ['/unknown-public-route', '/api/../storage/data.json', '/api/%2e%2e/storage/data.json',
    '/api/upload.php%00', '/public/routes/api/upload.php', '/api/upload.php/extra',
    '/unknown-public-route?route=api/upload.php'];
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
echo 'Public route parity and path safety passed: ' . count($entrypoints) . ' physical entrypoints, '
    . count($routes) . ' registered URLs, ' . $assertions . " assertions.\n";
