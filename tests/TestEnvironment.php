<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\CartService;
use App\Core\CommerceService;
use App\Core\EntitlementResolver;
use App\Core\MailService;
use App\Core\MemberAuth;
use App\Core\Store;
use App\Payments\PaymentProviderRegistry;

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
require_once ROOT_PATH . '/app/bootstrap.php';

/**
 * @return array{root:string,token:string,configPath:string,dataPath:string,config:Config,store:Store,mailer:MailService,auth:MemberAuth,resolver:EntitlementResolver,commerce:CommerceService,cart:CartService}
 */
function velvet_test_environment(string $prefix = 'members', bool $withDefaults = true): array
{
    $root = ROOT_PATH;
    $token = preg_replace('/[^a-z0-9-]/i', '-', $prefix) . '-' . bin2hex(random_bytes(5));
    $configPath = $root . '/storage/temp/test-' . $token . '.php';
    $dataRelative = 'storage/temp/test-' . $token . '.json';
    $dataPath = $root . '/' . $dataRelative;
    @mkdir(dirname($configPath), 0775, true);

    $data = $withDefaults
        ? json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true)
        : [];
    if (!is_array($data)) {
        $data = [];
    }
    $data = array_merge([
        'settings' => [], 'admins' => [], 'media' => [], 'templates' => [], 'scenes' => [], 'audit' => [],
        'mediaCenter' => ['albums' => [], 'tracks' => [], 'audioPlaylists' => [], 'galleries' => [], 'videos' => []],
        'membershipPlans' => [], 'members' => [], 'subscriptions' => [], 'entitlements' => [],
        'memberMediaState' => [], 'memberSessions' => [], 'memberTokens' => [], 'memberEvents' => [],
        'mailOutbox' => [], 'contentAccessRules' => [],
        'paymentProviders' => [], 'coupons' => [], 'couponRedemptions' => [], 'bundles' => [],
        'orders' => [], 'orderItems' => [], 'paymentTransactions' => [], 'paymentWebhookEvents' => [],
        'addons' => [], 'addonActivity' => [], 'addonSettings' => [], 'addonBackups' => [], 'addonTasks' => [], 'addonMigrations' => [],
    ], $data);
    $data['members'] = [];
    $data['subscriptions'] = [];
    $data['entitlements'] = [];
    $data['memberMediaState'] = [];
    $data['memberSessions'] = [];
    $data['memberTokens'] = [];
    $data['memberEvents'] = [];
    $data['mailOutbox'] = [];
    $data['couponRedemptions'] = [];
    $data['orders'] = [];
    $data['orderItems'] = [];
    $data['paymentTransactions'] = [];
    $data['paymentWebhookEvents'] = [];
    file_put_contents($dataPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $configValues = [
        'app_name' => 'Nikki Wilder — The Velvet Vixxen',
        'version' => trim((string) file_get_contents($root . '/VERSION')),
        'installed' => true,
        'storage_mode' => 'file',
        'file_store_path' => $dataRelative,
        'base_url' => 'http://127.0.0.1:8765',
        'support_email' => 'support@example.test',
        'timezone' => 'America/Chicago',
        'mail' => [
            'mode' => 'disabled',
            'from_email' => 'support@example.test',
            'from_name' => 'Nikki Wilder',
        ],
        'security' => [
            'app_key' => str_repeat('a', 64),
            'signing_key' => str_repeat('b', 64),
            'media_key' => str_repeat('c', 64),
            'media_signing_key' => str_repeat('c', 64),
        ],
    ];
    file_put_contents($configPath, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($configValues, true) . ";\n");

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    session_save_path($root . '/storage/temp');
    session_id('vvxtest' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Velvet Vixxen Automated Test';

    $config = new Config($configPath);
    $store = new Store($config);
    $mailer = new MailService($config, $store);
    $auth = new MemberAuth($store, $config, $mailer);
    return [
        'root' => $root,
        'token' => $token,
        'configPath' => $configPath,
        'dataPath' => $dataPath,
        'config' => $config,
        'store' => $store,
        'mailer' => $mailer,
        'auth' => $auth,
        'resolver' => new EntitlementResolver($store),
        'commerce' => new CommerceService($store, $config, $mailer, new PaymentProviderRegistry()),
        'cart' => new CartService(),
    ];
}

/** @param array<string,mixed> $environment */
function velvet_test_cleanup(array $environment): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    foreach (['configPath', 'dataPath'] as $key) {
        if (!empty($environment[$key]) && is_string($environment[$key])) {
            @unlink($environment[$key]);
        }
    }
    if (!empty($environment['dataPath']) && is_string($environment['dataPath'])) {
        @unlink($environment['dataPath'] . '.commerce.lock');
        @unlink($environment['dataPath'] . '.lock');
    }
}

/** @param list<string> $failures */
function velvet_finish_test(string $label, array $failures): never
{
    if ($failures !== []) {
        fwrite(STDERR, $label . " failed:\n- " . implode("\n- ", $failures) . "\n");
        exit(1);
    }
    echo $label . " passed.\n";
    exit(0);
}
