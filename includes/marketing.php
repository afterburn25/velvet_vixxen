<?php
declare(strict_types=1);

use Velvet\Core\MarketingStore;

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
require_once dirname(__DIR__) . '/app/Core/MarketingStore.php';

function velvet_marketing_store(): MarketingStore
{
    static $store;
    return $store ??= new MarketingStore(dirname(__DIR__));
}

function velvet_marketing_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function velvet_marketing_destination(string $value): string
{
    if (!\App\Core\SceneButton::validUrl($value)) return base_url('/');
    return vvx_public_destination(base_url(), $value);
}

function velvet_marketing_csrf(): string
{
    if (empty($_SESSION['velvet_marketing_csrf'])) $_SESSION['velvet_marketing_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['velvet_marketing_csrf'];
}

function velvet_marketing_verify_csrf(?string $token): void
{
    if (!$token || !hash_equals(velvet_marketing_csrf(), $token)) {
        http_response_code(419);
        throw new RuntimeException('Your session expired. Refresh the page and try again.');
    }
}

function velvet_marketing_is_owner(): bool
{
    if (!function_exists('app_auth') || !function_exists('app_store')) return false;
    $session = app_auth()->user();
    if (!$session || empty($session['email']) || empty($session['id'])) return false;
    $owner = app_store()->findAdminByEmail((string) $session['email']);
    return $owner !== null && !empty($owner['is_active'])
        && (string) $owner['id'] === (string) $session['id']
        && ($owner['role'] ?? '') === 'owner';
}

function velvet_marketing_require_owner(): void
{
    if (velvet_marketing_is_owner()) return;
    $target = base_url('admin/');
    if (!headers_sent()) { header('Location: ' . $target); exit; }
    http_response_code(403);
    exit('Owner authentication is required.');
}

function velvet_marketing_capture_referral(): string
{
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['ref'] ?? '')) ?: '';
    if ($code !== '') {
        setcookie('velvet_ref', $code, ['expires'=>time()+2592000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['velvet_ref'] = $code;
        try { velvet_marketing_store()->recordEvent('referral_visit', ['referral'=>$code]); } catch (Throwable $e) {}
    }
    return (string)($_COOKIE['velvet_ref'] ?? $code);
}

function velvet_marketing_record_pageview(): void
{
    $uri = vvx_public_request_path((string)($_SERVER['REQUEST_URI'] ?? '/'));
    if (PHP_SAPI === 'cli' || preg_match('#/(admin|install|api)/#', $uri) || preg_match('/\.(?:css|js|png|jpe?g|webp|svg|ico|woff2?)(?:\?|$)/i', $uri)) return;
    try { velvet_marketing_capture_referral(); velvet_marketing_store()->recordEvent('page_view', ['path'=>$uri]); } catch (Throwable $e) {}
}

function velvet_marketing_render_banner(): void
{
    try { $banners = velvet_marketing_store()->activeBanners(); } catch (Throwable $e) { return; }
    if (!$banners) return;
    $banner = $banners[0];
    echo '<aside class="velvet-promo-banner" data-marketing-banner>'; 
    if (!empty($banner['url'])) echo '<a href="'.velvet_marketing_escape(velvet_marketing_destination((string)$banner['url'])).'">';
    echo velvet_marketing_escape((string)($banner['text'] ?? ''));
    if (!empty($banner['url'])) echo '</a>';
    echo '<button type="button" aria-label="Dismiss promotion" data-banner-dismiss>×</button></aside>';
}
