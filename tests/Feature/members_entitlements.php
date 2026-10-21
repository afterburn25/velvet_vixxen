<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'public/routes/account/register.php','public/routes/account/login.php','public/routes/account/verify.php','public/routes/account/forgot.php','public/routes/account/reset.php',
    'public/routes/account/index.php','public/routes/account/library.php','public/routes/account/security.php','public/routes/account/logout.php',
    'public/routes/membership/index.php','public/routes/api/member-state.php','app/Views/admin/members.php',
    'app/Core/MemberAuth.php','app/Core/EntitlementResolver.php','app/Core/MemberStoreTrait.php','public/assets/css/member.css',
];
$missing = array_values(array_filter($required, static fn(string $path): bool => !is_file($root . '/' . $path)));
if ($missing !== []) {
    fwrite(STDERR, "Members & Entitlements files missing:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

$assertContains = static function (string $path, array $needles) use ($root): void {
    $content = (string) file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$path} is missing required behavior: {$needle}\n");
            exit(1);
        }
    }
};
$assertContains('public/routes/account/register.php', ['minlength="12"', 'member_auth()->register', 'Create Account']);
$assertContains('public/routes/account/login.php', ['member_auth()->attempt', 'Forgot password?']);
$assertContains('public/routes/account/library.php', ['$resolver->library', 'My Library']);
$assertContains('public/routes/account/security.php', ['memberSessions', 'revokeOtherMemberSessions', 'memberEvents']);
$assertContains('public/routes/membership/index.php', ['membershipPlans(true)', 'ppvDiscountPercent']);
$assertContains('public/routes/api/member-state.php', ['require_member(true)', 'require_csrf()', 'saveMemberMediaState']);
$assertContains('admin/index.php', [
    "'members' => 'Members'", "'plans' => 'Membership Plans'", "'entitlements' => 'Entitlements'",
    "save_membership_plan", "assign_subscription", "grant_entitlement",
]);
$assertContains('app/Views/admin/members.php', ['Member Directory', 'Subscription content access', 'Grant Access']);
$assertContains('public/assets/js/media-center.js', ['member-state.php', 'favorite-toggle', 'progressSeconds']);
$assertContains('app/Views/public/partials/header.php', ['app/Views/public/partials/navigation.php']);
$assertContains('app/Views/public/partials/navigation.php', ['My Account', 'Sign Out']);
require_once $root . '/app/Core/MenuService.php';
$membershipLinks = array_values(array_filter(App\Core\MenuService::defaults('header'),
    static fn(array $item): bool => $item['label'] === 'Membership' && $item['url'] === 'membership/'));
if (count($membershipLinks) !== 1) {
    fwrite(STDERR, "The original public header must retain its Membership destination.\n");
    exit(1);
}

$defaults = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true);
if (!is_array($defaults) || count((array) ($defaults['membershipPlans'] ?? [])) < 3) {
    fwrite(STDERR, "At least three default membership plans are required.\n");
    exit(1);
}
$accessModes = [];
foreach ((array) ($defaults['mediaCenter']['galleries'] ?? []) as $gallery) {
    $accessModes[] = (string) ($gallery['accessMode'] ?? '');
}
foreach (['public','registered','subscription','ppv','rental'] as $mode) {
    if (!in_array($mode, $accessModes, true)) {
        fwrite(STDERR, "Default galleries do not demonstrate access mode: {$mode}.\n");
        exit(1);
    }
}
if (empty($defaults['contentAccessRules']['gallery:demo-gallery-mirror'])) {
    fwrite(STDERR, "Subscription content rules are missing from defaults.\n");
    exit(1);
}

echo "Members & Entitlements feature test passed.\n";
