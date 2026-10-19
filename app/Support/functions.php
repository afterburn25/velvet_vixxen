<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use App\Core\Auth;
use App\Core\MailService;
use App\Core\MemberAuth;
use App\Core\EntitlementResolver;
use App\Core\CartService;
use App\Core\CommerceService;
use App\Payments\PaymentProviderRegistry;
use App\Core\AddonRuntime;
use App\Core\AddonManager;
use App\Core\AddonLicenseHub;
use App\Core\AddonLicenseClient;
use App\Core\AddonUpdateService;
use App\Core\RemoteHttpClient;
use App\Core\CommunicationUploadService;

function root_path(string $path = ''): string
{
    $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

function app_config(?string $key = null, mixed $default = null): mixed
{
    static $config;
    if (!$config instanceof Config) {
        $config = new Config(root_path('config/app.php'));
    }
    return $key === null ? $config : $config->get($key, $default);
}

function app_store(): Store
{
    static $store;
    if (!$store instanceof Store) {
        $store = new Store(app_config());
    }
    return $store;
}

function app_auth(): Auth
{
    static $auth;
    if (!$auth instanceof Auth) {
        $auth = new Auth(app_store());
    }
    return $auth;
}

function member_auth(): MemberAuth
{
    static $auth;
    if (!$auth instanceof MemberAuth) {
        $auth = new MemberAuth(app_store(), app_config(), new MailService(app_config(), app_store()));
    }
    return $auth;
}

function entitlement_resolver(): EntitlementResolver
{
    static $resolver;
    if (!$resolver instanceof EntitlementResolver) {
        $resolver = new EntitlementResolver(app_store());
    }
    return $resolver;
}

function current_member(): ?array
{
    return member_auth()->user();
}

function cart_service(): CartService
{
    static $cart;
    if (!$cart instanceof CartService) {
        $cart = new CartService();
    }
    return $cart;
}

function commerce_service(): CommerceService
{
    static $commerce;
    if (!$commerce instanceof CommerceService) {
        $commerce = new CommerceService(app_store(), app_config(), new MailService(app_config(), app_store()), new PaymentProviderRegistry());
    }
    return $commerce;
}

function communication_upload_service(): CommunicationUploadService
{
    static $service;
    if (!$service instanceof CommunicationUploadService) {
        $service = new CommunicationUploadService();
    }
    return $service;
}


function addon_runtime(): AddonRuntime
{
    static $runtime;
    if (!$runtime instanceof AddonRuntime) {
        $runtime = new AddonRuntime(app_store());
    }
    return $runtime;
}

function addon_manager(): AddonManager
{
    static $manager;
    if (!$manager instanceof AddonManager) {
        $manager = new AddonManager(app_store());
    }
    return $manager;
}

function addon_license_hub(): AddonLicenseHub
{
    static $hub;
    if (!$hub instanceof AddonLicenseHub) {
        $hub = new AddonLicenseHub(app_store(), app_config());
    }
    return $hub;
}

function addon_license_client(): AddonLicenseClient
{
    static $client;
    if (!$client instanceof AddonLicenseClient) {
        $client = new AddonLicenseClient(app_store(), app_config(), new RemoteHttpClient());
    }
    return $client;
}

function addon_update_service(): AddonUpdateService
{
    static $service;
    if (!$service instanceof AddonUpdateService) {
        $service = new AddonUpdateService(app_store(), app_config(), addon_license_client(), new RemoteHttpClient(), addon_manager());
    }
    return $service;
}

function addon_hook(string $hook, array $data = []): string
{
    addon_runtime()->boot();
    return addon_runtime()->renderHook($hook, $data);
}

function addon_event(string $event, array $payload = []): array
{
    addon_runtime()->boot();
    return addon_runtime()->dispatch($event, $payload);
}

function money(float|int|string $amount, string $currency = 'USD'): string
{
    return strtoupper($currency) . ' ' . number_format((float) $amount, 2);
}

function require_member(bool $json = false): array
{
    $member = current_member();
    if ($member !== null) {
        return $member;
    }
    if ($json) {
        json_response(['ok' => false, 'message' => 'Member sign-in required.'], 401);
    }
    $returnTo = member_return_path((string) ($_SERVER['REQUEST_URI'] ?? '/account/'));
    redirect(base_url('account/login.php?return=' . rawurlencode($returnTo)));
}

function member_return_path(string $candidate, string $fallback = '/account/'): string
{
    $candidate = trim($candidate);
    if ($candidate === '' || str_contains($candidate, "\r") || str_contains($candidate, "\n")) {
        return $fallback;
    }
    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return $fallback;
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

function member_content_decision(string $type, array $content, bool $direct = false): array
{
    return entitlement_resolver()->resolve($type, $content, current_member(), $direct);
}

function base_url(string $path = ''): string
{
    $configured = rtrim((string) app_config('base_url', ''), '/');
    if ($configured === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($script === '.' || $script === '/') $script = '';
        $script = preg_replace('#/(admin|api|install|account|music|photos|videos|membership|cart|checkout|receipt|shop|extension|messages|requests|booking|communication)$#', '', rtrim($script, '/')) ?: '';
        if ($script !== '' && !str_starts_with($script, '/')) $script = '/' . $script;
        $configured = $scheme . '://' . $host . $script;
    }
    return $configured . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function asset_url(string $path): string
{
    require_once root_path('includes/version.php');
    $url = base_url('public/assets/' . ltrim($path, '/'));
    [$resource, $fragment] = array_pad(explode('#', $url, 2), 2, '');
    return $resource . (str_contains($resource, '?') ? '&' : '?')
        . 'v=' . rawurlencode(VELVET_VIXXEN_ASSET_VERSION)
        . ($fragment === '' ? '' : '#' . $fragment);
}

function image_url(string $path): string
{
    return base_url('images/' . ltrim($path, '/'));
}

function template_url(string $path): string
{
    return base_url('templates/' . ltrim($path, '/'));
}

function upload_url(string $path): string
{
    return base_url('uploads/' . ltrim($path, '/'));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token ??= $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

function require_csrf(): void
{
    if (!verify_csrf()) {
        json_response(['ok' => false, 'message' => 'Your session token expired. Refresh the page and try again.'], 419);
    }
}

function require_admin(bool $json = false): void
{
    if (!app_auth()->check()) {
        if ($json) {
            json_response(['ok' => false, 'message' => 'Authentication required.'], 401);
        }
        redirect(base_url('admin/?page=login'));
    }
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return is_string($message) ? $message : null;
}

function now_iso(): string
{
    return gmdate('c');
}

function safe_json_decode(string $json, array $fallback = []): array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : $fallback;
    } catch (Throwable) {
        return $fallback;
    }
}

function installed_lock_data(): array
{
    return vvx_recovery_load_lock(root_path('storage/install.lock'));
}

function is_installed(): bool
{
    return vvx_recovery_lock_matches(app_config()->all(), installed_lock_data());
}

function scene_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(?:https?:)?//#i', $path) || str_starts_with($path, 'data:') || str_starts_with($path, 'blob:')) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    while (str_starts_with($path, '../')) {
        $path = substr($path, 3);
    }
    $path = ltrim($path, '/');

    if (str_starts_with($path, 'uploads/')) {
        return base_url($path);
    }

    /*
     * "images/" is used by both bundled site artwork and uploaded media.
     * Prefer a real bundled file when it exists; otherwise resolve the same
     * relative path under /uploads. This fixes Composer thumbnails such as
     * images/demo/... while keeping images/defaults/... working.
     */
    if (str_starts_with($path, 'images/')) {
        if (is_file(root_path($path))) {
            return base_url($path);
        }
        return upload_url($path);
    }

    foreach (['media/', 'models/', 'thumbnails/', 'exports/', 'audio/', 'videos/', 'captions/'] as $uploadPrefix) {
        if (str_starts_with($path, $uploadPrefix)) {
            return upload_url($path);
        }
    }

    if (is_file(root_path($path))) {
        return base_url($path);
    }
    if (is_file(root_path('uploads/' . $path))) {
        return upload_url($path);
    }

    return base_url($path);
}

function slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'item-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    $ascii = is_string($ascii) && $ascii !== '' ? $ascii : $value;
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'item-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function media_time(float|int|null $seconds): string
{
    $seconds = max(0, (int) round((float) $seconds));
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;
    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
        : sprintf('%d:%02d', $minutes, $remaining);
}

function media_public_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (str_starts_with($path, 'private:')) {
        return '';
    }
    return upload_url(ltrim($path, '/'));
}

function media_signed_url(string|int $assetId, int $ttlSeconds = 900): string
{
    $expires = time() + max(60, min($ttlSeconds, 86400));
    $key = (string) app_config('security.media_key', '');
    if ($key === '') {
        return '';
    }
    $payload = (string) $assetId . '|' . $expires;
    $signature = hash_hmac('sha256', $payload, $key);
    return base_url('media.php?id=' . rawurlencode((string) $assetId) . '&expires=' . $expires . '&signature=' . $signature);
}

function media_asset_src(array $asset, bool $allowPrivate = false): string
{
    $path = (string) ($asset['file'] ?? '');
    if (str_starts_with($path, 'private:')) {
        return $allowPrivate ? media_signed_url((string) ($asset['id'] ?? '')) : '';
    }
    return media_public_url($path);
}

function media_thumbnail_src(array $asset): string
{
    $thumbnail = (string) ($asset['thumbnail'] ?? '');
    if ($thumbnail !== '' && !str_starts_with($thumbnail, 'private:')) {
        return media_public_url($thumbnail);
    }
    $file = (string) ($asset['file'] ?? '');
    return str_starts_with($file, 'private:') ? '' : media_public_url($file);
}
