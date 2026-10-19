<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (PHP_SAPI !== 'cli' || !is_file($root . '/.vvx-test-runtime') || realpath((string) getenv('VELVET_TEST_RUNTIME')) !== realpath($root)) {
    fwrite(STDERR, "Refusing to replace application configuration outside a marked disposable test copy.\n");
    exit(1);
}
$version = trim((string) file_get_contents($root . '/VERSION'));
$baseUrl = rtrim((string) (getenv('VELVET_BASE_URL') ?: ('http://127.0.0.1:' . (getenv('VELVET_TEST_PORT') ?: '8765'))), '/');
$installId = 'demo-' . bin2hex(random_bytes(6));
$secret = static fn(int $bytes): string => rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
$config = [
    'installed' => true,
    'installation_id' => $installId,
    'app_name' => 'Nikki Wilder — The Velvet Vixxen',
    'version' => $version,
    'base_url' => $baseUrl,
    'timezone' => 'America/Chicago',
    'currency' => 'USD',
    'support_email' => 'admin@example.com',
    'storage_mode' => 'file',
    'file_store_path' => 'storage/data.json',
    'db' => ['host'=>'localhost','port'=>3306,'name'=>'','user'=>'','password'=>'','charset'=>'utf8mb4'],
    'mail' => ['mode'=>'disabled','host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from_email'=>'admin@example.com','from_name'=>'Nikki Wilder'],
    'security' => ['app_key'=>$secret(48),'signing_key'=>$secret(48),'media_key'=>$secret(48)],
    'paths' => ['storage'=>'storage','private'=>'storage/private','uploads'=>'uploads','images'=>'images','addons'=>'addons','templates'=>'templates'],
    'canva' => ['client_id'=>'','client_secret'=>'','redirect_uri'=>''],
    'installed_at' => gmdate('c'),
];
file_put_contents($root . '/config/app.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
@chmod($root . '/config/app.php', 0640);
copy($root . '/storage/defaults/data.json', $root . '/storage/data.json');
$data = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
$data['admins']['demo-owner'] = [
    'id'=>'demo-owner','name'=>'Nikki Wilder','email'=>'admin@example.com',
    'password_hash'=>password_hash('VelvetDemo12', PASSWORD_DEFAULT),
    'role'=>'owner','is_active'=>1,'createdAt'=>gmdate('c')
];
$now = gmdate('c');
$data['members']['demo-member'] = [
    'id'=>'demo-member','displayName'=>'Velvet Member','email'=>'member@example.com',
    'passwordHash'=>password_hash('VelvetMember12', PASSWORD_DEFAULT),
    'emailVerifiedAt'=>$now,'status'=>'active','lastLoginAt'=>null,'lastSeenAt'=>null,
    'preferences'=>['emailUpdates'=>true,'newContentAlerts'=>true],
    'failedLoginCount'=>0,'lockedUntil'=>null,'createdAt'=>$now,'updatedAt'=>$now,'deletedAt'=>null,
];
$data['members']['demo-other'] = array_replace($data['members']['demo-member'], [
    'id' => 'demo-other', 'displayName' => 'Other Fixture Member', 'email' => 'other@example.com',
]);
$data['subscriptions']['demo-subscription'] = [
    'id'=>'demo-subscription','memberId'=>'demo-member','planId'=>'plan-vip','planName'=>'Velvet VIP',
    'status'=>'active','providerKey'=>'sandbox','providerSubscriptionId'=>null,
    'currentPeriodStart'=>$now,'currentPeriodEnd'=>gmdate('c', time()+90*86400),
    'cancelAtPeriodEnd'=>false,'graceEndsAt'=>null,'cancelledAt'=>null,'createdAt'=>$now,'updatedAt'=>$now,
];
$data['entitlements']['demo-ppv-gallery'] = [
    'id'=>'demo-ppv-gallery','memberId'=>'demo-member','contentType'=>'gallery','contentId'=>'demo-gallery-lounge',
    'sourceType'=>'ppv','sourceId'=>'demo-order','startsAt'=>$now,'expiresAt'=>null,'status'=>'active',
    'metadata'=>['demo'=>true],'createdAt'=>$now,'updatedAt'=>$now,
];
$data['memberMediaState']['demo-member:track:demo-track-1'] = [
    'memberId'=>'demo-member','contentType'=>'track','contentId'=>'demo-track-1','progressSeconds'=>6.5,
    'isFavorite'=>true,'viewCount'=>2,'lastViewedAt'=>$now,'completedAt'=>null,'metadata'=>[],'updatedAt'=>$now,
];
$data['memberMediaState']['demo-member:video:demo-video'] = [
    'memberId'=>'demo-member','contentType'=>'video','contentId'=>'demo-video','progressSeconds'=>4.0,
    'isFavorite'=>true,'viewCount'=>1,'lastViewedAt'=>$now,'completedAt'=>null,'metadata'=>[],'updatedAt'=>$now,
];
file_put_contents($root . '/storage/data.json', json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/storage/install.lock', json_encode([
    'installed'=>true,'installation_id'=>$installId,'version'=>$version,
    'base_url'=>$config['base_url'],'installed_at'=>$config['installed_at'],
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL);
@chmod($root . '/storage/install.lock', 0640);
// Install and activate the bundled starter add-on through the real package lifecycle.
@mkdir($root . '/storage/sessions', 0775, true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($root . '/storage/sessions');
    session_id('velvet-demo-addon');
    session_start();
}
require_once $root . '/app/bootstrap.php';
$review = addon_manager()->stageBundledStarter();
$installedAddon = addon_manager()->install((string) $review['token'], true, true);
app_store()->setAddonSetting((string) $installedAddon['addonKey'], 'enabled', true);
app_store()->setAddonSetting((string) $installedAddon['addonKey'], 'headline', 'Welcome to the Velvet Vixxen.');
app_store()->setAddonSetting((string) $installedAddon['addonKey'], 'copy', 'This banner, its admin page, and its public route are provided by the installed starter add-on.');
app_store()->setAddonSetting((string) $installedAddon['addonKey'], 'button_label', 'Learn More');
$demoAddonRuntime = new App\Core\AddonRuntime(app_store());
$demoAddonRuntime->boot();
echo "Demo ready. Admin: admin@example.com / VelvetDemo12\nMember: member@example.com / VelvetMember12\n";
echo "Starter add-on installed and activated for the demo.\n";
