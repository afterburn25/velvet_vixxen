<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-installation-url-test-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true);
mkdir($fixture . '/sessions', 0700);
define('ROOT_PATH', $fixture);
session_save_path($fixture . '/sessions');
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
        file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export(['base_url' => $base], true) . ';');
        app_config()->reload();
        $assert(member_return_path('/account/') === $prefix . '/account/', 'Default account return must stay inside the installation.');
        $assert(member_return_path('/requests/') === $prefix . '/requests/', 'Homepage member requests return must stay inside the installation.');
        $assert(member_return_path($prefix . '/photos/view.php?slug=pink-room&mode=full')
            === $prefix . '/photos/view.php?slug=pink-room&mode=full', 'Existing request prefix and query must survive without duplication.');
        $assert(member_return_path('/account/?label=Welcome%20back') === $prefix . '/account/?label=Welcome%20back', 'Encoded query text must remain usable.');
        foreach (['', 'account/', 'https://elsewhere.example.test/', '//elsewhere.example.test/',
            '/\\elsewhere.example.test/', '/%5celsewhere.example.test/', '/%2felsewhere.example.test/',
            '/../outside/', '/nested/%2e%2e/outside/', "/account/\r\nLocation: https://elsewhere.example.test/",
            '/account/?value=%0aheader', '/invalid%path'] as $invalid) {
            $assert(member_return_path($invalid) === $prefix . '/account/', 'Unsafe return destination must use the installation-scoped fallback.');
            $assert(member_return_path($invalid, '/requests/') === $prefix . '/requests/', 'Custom safe fallback must also be installation-scoped.');
        }
        $assert(member_return_path('', 'https://elsewhere.example.test/') === $prefix . '/account/', 'An unsafe caller fallback cannot create an external redirect.');
        foreach (['/' => '/', '/booking/' => '/booking/', 'booking/' => '/booking/',
            '/membership/?offer=one#plans' => '/membership/?offer=one#plans',
            '?offer=one' => '/?offer=one', '#membership' => '/#membership'] as $stored => $relative) {
            $assert(velvet_marketing_destination($stored) === $base . $relative, 'Stored marketing destinations must resolve from the installation root.');
        }
        $assert(velvet_marketing_destination($prefix . '/booking/?from=post#details') === $base . '/booking/?from=post#details',
            'An already-prefixed marketing destination must not be prefixed twice.');
        foreach (['https://elsewhere.example.test/path?q=one#details', 'http://elsewhere.example.test:8080/path'] as $external) {
            $assert(velvet_marketing_destination($external) === $external, 'Approved complete external destinations must remain unchanged.');
        }
        foreach (['javascript:alert(1)', 'data:text/html,unsafe', '//elsewhere.example.test/', '/\\elsewhere.example.test/',
            '../outside/', '/%2e%2e/outside/', 'https://user:pass@elsewhere.example.test/'] as $invalid) {
            $assert(velvet_marketing_destination($invalid) === $base . '/', 'Unsafe stored destination must not become an executable/external link.');
        }
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($fixture);
}
echo "Installation-scoped member returns and marketing destinations passed: {$checks} assertions.\n";
