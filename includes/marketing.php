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
    try {
        $session = app_auth()->user();
        if (!$session || !is_string($session['email'] ?? null) || empty($session['id'])
            || !is_scalar($session['id'])) return false;
        $owner = (new \App\Core\Store(new \App\Core\Config(app_config()->path())))->findAdminByEmail($session['email']);
        return $owner !== null && !empty($owner['is_active'])
            && (string) $owner['id'] === (string) $session['id']
            && ($owner['role'] ?? '') === 'owner';
    } catch (Throwable) { return false; }
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
    try { $banners = velvet_marketing_store()->activeBanners(velvet_marketing_viewer()); } catch (Throwable) { return; }
    if (!$banners) return;
    velvet_marketing_render_banner_record($banners[0]);
}

/** Send before rendering any response whose marketing content depends on the viewer. */
function velvet_marketing_private_response(bool $preview = false): void
{
    if (headers_sent()) return;
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    if ($preview) {
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
    }
}

/** Only persisted member/device/subscription records can supply public access. */
function velvet_marketing_viewer(): array
{
    try { return \Velvet\Core\MarketingAccess::viewer(new \App\Core\Store(new \App\Core\Config(app_config()->path()))); }
    catch (Throwable) { return ['member' => false, 'plan_ids' => [], 'available_plan_ids' => []]; }
}

function velvet_marketing_record_text(array $record, string $field, string $default = ''): string
{
    return is_string($record[$field] ?? null) ? $record[$field] : $default;
}

/** The same saved content is rendered publicly and in the private owner preview. */
function velvet_marketing_render_post(array $post, ?array $presentation = null, array $related = [], bool $preview = false): void
{
    $presentation ??= ['values' => \Velvet\Core\MarketingMetadata::describe('posts', $post)['values'], 'image' => null];
    try { $publishedDate = \Velvet\Core\MarketingPublication::storedDate($post['publish_at'] ?? null); }
    catch (InvalidArgumentException) { $publishedDate = null; }
    echo '<article class="marketing-card marketing-article" data-editorial-post="' . velvet_marketing_escape(velvet_marketing_record_text($post, 'id')) . '">';
    velvet_marketing_render_editorial_image($post, $presentation);
    echo '<div class="marketing-meta">';
    if ($publishedDate !== null) echo '<span>' . velvet_marketing_escape($publishedDate->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('F j, Y')) . '</span>';
    echo '<span>' . velvet_marketing_escape(velvet_marketing_record_text($post, 'category', 'Update')) . '</span></div>';
    echo '<h1 class="marketing-title">' . velvet_marketing_escape(velvet_marketing_record_text($post, 'title')) . '</h1>';
    velvet_marketing_render_editorial_details($presentation);
    echo '<div class="marketing-content">' . nl2br(velvet_marketing_escape(velvet_marketing_record_text($post, 'body'))) . '</div></article>';
    if ($related !== []) {
        echo '<section class="marketing-related" data-editorial-related aria-labelledby="editorial-related-title"><div class="marketing-section-heading"><span class="marketing-eyebrow">Keep exploring</span><h2 id="editorial-related-title">More updates for you</h2></div><div class="marketing-grid">';
        foreach ($related as $item) velvet_marketing_render_post_card($item['record'], $item['presentation'], $preview, true);
        echo '</div></section>';
    }
}

function velvet_marketing_render_landing_page(array $page, bool $preview = false, ?array $presentation = null): void
{
    echo '<section class="marketing-hero">';
    if ($presentation !== null) velvet_marketing_render_editorial_image($page, $presentation);
    echo '<span class="marketing-eyebrow">' . velvet_marketing_escape(velvet_marketing_record_text($page, 'eyebrow', 'Special Offer')) . '</span>';
    echo '<h1>' . velvet_marketing_escape(velvet_marketing_record_text($page, 'title')) . '</h1>';
    echo '<p>' . velvet_marketing_escape(velvet_marketing_record_text($page, 'body')) . '</p>';
    $url = velvet_marketing_record_text($page, 'cta_url');
    if ($url !== '') {
        $label = velvet_marketing_escape(velvet_marketing_record_text($page, 'cta_label', 'Learn More'));
        if ($preview) echo '<span class="marketing-button" aria-disabled="true" data-preview-inert>' . $label . '</span>';
        else echo '<a class="marketing-button" data-marketing-event="cta_click" href="' . velvet_marketing_escape(velvet_marketing_destination($url)) . '">' . $label . '</a>';
    }
    echo '</section>';
}

/** Resolve image references before the caller's final authorization check. */
function velvet_marketing_editorial_presentation(string $collection, array $record): array
{
    $metadata = \Velvet\Core\MarketingMetadata::describe($collection, $record);
    $image = null;
    $id = $metadata['values']['featured_asset_id'] ?? '';
    if ($id !== '') {
        try { $image = \Velvet\Core\MarketingMetadata::resolveImage($id, dirname(__DIR__)); }
        catch (Throwable) { /* An unavailable optional image never becomes another asset or a private URL. */ }
    }
    return ['values' => $metadata['values'], 'problems' => $metadata['problems'], 'image' => $image];
}

/** This metadata is passed to the document head only after public access succeeds. */
function velvet_marketing_editorial_head(array $record, array $presentation): array
{
    $values = $presentation['values'];
    return ['title' => $values['seo_title'] !== '' ? $values['seo_title'] : velvet_marketing_record_text($record, 'title'),
        'description' => $values['seo_description'] !== '' ? $values['seo_description'] : velvet_marketing_record_text($record, 'excerpt')];
}

/** Rendering performs no storage reads, including inside saved-owner previews. */
function velvet_marketing_render_editorial_image(array $record, array $presentation): void
{
    $image = $presentation['image'] ?? null;
    if (!is_array($image) || !is_string($image['path'] ?? null) || $image['path'] === '') return;
    $alt = $presentation['values']['featured_alt'] ?? '';
    if (!is_string($alt) || $alt === '') $alt = velvet_marketing_record_text($record, 'title');
    echo '<figure class="marketing-featured"><img data-editorial-featured-image="' . velvet_marketing_escape((string) $image['id'])
        . '" src="' . velvet_marketing_escape(base_url($image['path'])) . '" alt="' . velvet_marketing_escape($alt)
        . '" width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '" loading="lazy" decoding="async"></figure>';
}

function velvet_marketing_render_editorial_details(array $presentation): void
{
    $values = $presentation['values'];
    if (($values['author_name'] ?? '') !== '') {
        echo '<p class="marketing-byline" data-editorial-byline>By <span>' . velvet_marketing_escape($values['author_name']) . '</span></p>';
    }
    if (($values['tags'] ?? []) !== []) {
        echo '<ul class="marketing-tags" data-editorial-tags aria-label="Topics">';
        foreach ($values['tags'] as $tag) echo '<li>' . velvet_marketing_escape($tag) . '</li>';
        echo '</ul>';
    }
}

function velvet_marketing_render_post_card(array $post, array $presentation, bool $preview = false, bool $related = false): void
{
    $id = velvet_marketing_record_text($post, 'id');
    $heading = $related ? 'h3' : 'h2';
    try { $published = \Velvet\Core\MarketingPublication::storedDate($post['publish_at'] ?? null); }
    catch (InvalidArgumentException) { $published = null; }
    echo '<article class="marketing-card marketing-post-card" data-editorial-post-card="' . velvet_marketing_escape($id) . '"'
        . ($related ? ' data-related-post-id="' . velvet_marketing_escape($id) . '"' : '') . '>';
    velvet_marketing_render_editorial_image($post, $presentation);
    echo '<div class="marketing-meta">';
    if ($published !== null) echo '<span>' . velvet_marketing_escape($published->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y')) . '</span>';
    echo '<span>' . velvet_marketing_escape(velvet_marketing_record_text($post, 'category', 'Update')) . '</span></div>';
    echo '<' . $heading . '>' . velvet_marketing_escape(velvet_marketing_record_text($post, 'title')) . '</' . $heading . '>';
    velvet_marketing_render_editorial_details($presentation);
    echo '<p>' . velvet_marketing_escape(velvet_marketing_record_text($post, 'excerpt')) . '</p>';
    if ($preview) echo '<span class="marketing-button" aria-disabled="true" data-preview-inert>Read More</span>';
    else echo '<a class="marketing-button" href="' . velvet_marketing_escape(base_url('blog/post.php?slug=' . rawurlencode(velvet_marketing_record_text($post, 'slug')))) . '">Read More</a>';
    echo '</article>';
}

function velvet_marketing_render_banner_record(array $banner, bool $preview = false): void
{
    echo '<aside class="velvet-promo-banner marketing-promo-banner"' . ($preview ? ' data-preview-banner' : ' data-marketing-banner') . '>';
    $url = velvet_marketing_record_text($banner, 'url');
    $text = velvet_marketing_escape(velvet_marketing_record_text($banner, 'text'));
    if ($url !== '' && !$preview) echo '<a href="' . velvet_marketing_escape(velvet_marketing_destination($url)) . '">' . $text . '</a>';
    else echo '<span' . ($preview && $url !== '' ? ' aria-disabled="true" data-preview-inert' : '') . '>' . $text . '</span>';
    if (!$preview) echo '<button type="button" aria-label="Dismiss promotion" data-banner-dismiss>×</button>';
    echo '</aside>';
}
