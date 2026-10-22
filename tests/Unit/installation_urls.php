<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-installation-url-test-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true);
mkdir($fixture . '/sessions', 0700);
define('ROOT_PATH', $fixture);
session_save_path($fixture . '/sessions');
// Exercise the actual protection-aware URL helpers against isolated config.
// ROOT_PATH names the fixture, so its source classes need a source-only loader.
spl_autoload_register(static function (string $class) use ($source): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require_once $file;
});
require $source . '/app/Core/Config.php';
require $source . '/app/Core/SceneButton.php';
require $source . '/app/Support/functions.php';
require $source . '/includes/marketing.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
try {
    foreach (['', '/nested/velvet'] as $prefix) {
        $base = 'https://site.example.test' . $prefix;
        $mediaKey = bin2hex(random_bytes(32));
        file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export(['base_url' => $base, 'security' => ['media_key' => $mediaKey]], true) . ';');
        app_config()->reload();
        $assert(member_return_path('/account/') === $prefix . '/route.php?vvx_route=account', 'Default account return must stay inside the installation.');
        $assert(member_return_path('/requests/') === $prefix . '/route.php?vvx_route=requests', 'Homepage member requests return must stay inside the installation.');
        $assert(member_return_path($prefix . '/photos/view.php?slug=pink-room&mode=full')
            === $prefix . '/route.php?vvx_route=photos%2Fview.php&slug=pink-room&mode=full', 'Existing request prefix and query must survive without duplication.');
        $assert(member_return_path('/account/?label=Welcome%20back') === $prefix . '/route.php?vvx_route=account&label=Welcome%20back', 'Encoded query text must remain usable.');
        foreach (['', 'account/', 'https://elsewhere.example.test/', '//elsewhere.example.test/',
            '/\\elsewhere.example.test/', '/%5celsewhere.example.test/', '/%2felsewhere.example.test/',
            '/../outside/', '/nested/%2e%2e/outside/', "/account/\r\nLocation: https://elsewhere.example.test/",
            '/account/?value=%0aheader', '/invalid%path'] as $invalid) {
            $assert(member_return_path($invalid) === $prefix . '/route.php?vvx_route=account', 'Unsafe return destination must use the installation-scoped fallback.');
            $assert(member_return_path($invalid, '/requests/') === $prefix . '/route.php?vvx_route=requests', 'Custom safe fallback must also be installation-scoped.');
        }
        $assert(member_return_path('', 'https://elsewhere.example.test/') === $prefix . '/route.php?vvx_route=account', 'An unsafe caller fallback cannot create an external redirect.');
        foreach (['/' => '/', '/booking/' => '/route.php?vvx_route=booking', 'booking/' => '/route.php?vvx_route=booking',
            '/membership/?offer=one#plans' => '/route.php?vvx_route=membership&offer=one#plans',
            '?offer=one' => '/?offer=one', '#membership' => '/#membership'] as $stored => $relative) {
            $assert(velvet_marketing_destination($stored) === $base . $relative, 'Stored marketing destinations must resolve from the installation root.');
        }
        $assert(velvet_marketing_destination($prefix . '/booking/?from=post#details') === $base . '/route.php?vvx_route=booking&from=post#details',
            'An already-prefixed marketing destination must not be prefixed twice.');
        foreach (['https://elsewhere.example.test/path?q=one#details', 'http://elsewhere.example.test:8080/path'] as $external) {
            $assert(velvet_marketing_destination($external) === $external, 'Approved complete external destinations must remain unchanged.');
        }
        foreach (['javascript:alert(1)', 'data:text/html,unsafe', '//elsewhere.example.test/', '/\\elsewhere.example.test/',
            '../outside/', '/%2e%2e/outside/', 'https://user:pass@elsewhere.example.test/'] as $invalid) {
            $assert(velvet_marketing_destination($invalid) === $base . '/', 'Unsafe stored destination must not become an executable/external link.');
        }
        $assert(velvet_marketing_destination($base . '/booking/?from=link#details')
            === $base . '/route.php?vvx_route=booking&from=link#details', 'A saved complete same-site URL must also work without rewriting.');
        $assert(member_return_path($prefix . '/route.php?vvx_route=account%2Flibrary.php&tab=favorites')
            === $prefix . '/route.php?vvx_route=account%2Flibrary.php&tab=favorites', 'Canonical member return must keep its selector and filter.');
        foreach (['public/assets/js/site.js', 'images/hero/room.jpg', 'uploads/videos/example.mp4',
            'admin/?page=composer', 'admin/repair.php', 'install/'] as $resource) {
            $assert(base_url($resource) === $base . '/' . $resource, 'Non-route application and static paths must remain literal.');
        }
        foreach ([
            'api/scene.php?section=footer' => 'api%2Fscene.php&section=footer',
            'api/upload.php' => 'api%2Fupload.php',
            'extension/?route=custom-page&filter[]=one&filter[]=two' => 'extension&route=custom-page&filter[]=one&filter[]=two',
            'checkout/?order=abc%2B123&mode=rental' => 'checkout&order=abc%2B123&mode=rental',
            'communication/download.php?id=a%2Fb%3Dc' => 'communication%2Fdownload.php&id=a%2Fb%3Dc',
            'account/reset.php?token=abc%2Bdef%2Fghi%3D' => 'account%2Freset.php&token=abc%2Bdef%2Fghi%3D',
            'newsletter/unsubscribe.php?token=00123' => 'newsletter%2Funsubscribe.php&token=00123',
        ] as $resource => $query) {
            $assert(base_url($resource) === $base . '/route.php?vvx_route=' . $query, 'Canonical route must preserve application query fields and encoded token bytes.');
        }
        foreach (['vvx_route=api%2Fupload.php', 'vvx_route[]=api%2Fupload.php',
            'vvx.route=api%2Fupload.php', 'vvx+route=api%2Fupload.php', '%76vx_route=api%2Fupload.php'] as $override) {
            $assert(base_url('booking/?' . $override . '&from=details')
                === $base . '/route.php?vvx_route=booking&from=details', 'Stored application queries cannot override their declared route.');
        }
        $signed = media_signed_url('private-fixture-id', 120);
        $signedQuery = [];
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $signedQuery);
        $assert(parse_url($signed, PHP_URL_PATH) === $prefix . '/route.php'
            && ($signedQuery['vvx_route'] ?? null) === 'media.php' && ($signedQuery['id'] ?? null) === 'private-fixture-id',
            'Protected media must use the one physical entry point at both installation depths.');
        $assert(hash_equals(hash_hmac('sha256', 'private-fixture-id|' . $signedQuery['expires'], $mediaKey), (string) $signedQuery['signature']),
            'Canonical dispatch must not change the existing media signature payload or key.');
        $assert(vvx_public_request_path($prefix . '/route.php?vvx_route=account%2Flibrary.php&token=private')
            === $prefix . '/account/library.php', 'Analytics must identify the logical private page and omit all query values.');
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($fixture);
}
echo "Installation-scoped member returns and marketing destinations passed: {$checks} assertions.\n";
