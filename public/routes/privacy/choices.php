<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$notice = ''; $error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        velvet_marketing_verify_csrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
        $choice = $_POST['choice'] ?? null;
        if (!in_array($choice, ['allow', 'deny', 'erase'], true)) throw new InvalidArgumentException('Choose whether to allow or decline optional measurement.');
        $allow = $choice === 'allow';
        $cookie = \Velvet\Core\MarketingPrivacy::cookie($allow);
        setcookie(\Velvet\Core\MarketingPrivacy::COOKIE, $cookie, velvet_marketing_cookie_options(time() + \Velvet\Core\MarketingPrivacy::CHOICE_SECONDS));
        $_COOKIE[\Velvet\Core\MarketingPrivacy::COOKIE] = $cookie;
        if ($choice !== 'allow') {
            // Revocation removes this browser's retained measurements and prevents
            // their later recreation from a durable registration/order snapshot.
            velvet_marketing_store()->forgetAttributionVisitor(\Velvet\Core\MarketingPrivacy::visitor());
            velvet_marketing_visitor_cookie('', time() - 3600);
        } else {
            $visitor = velvet_marketing_store()->attributionVisitor(\Velvet\Core\MarketingPrivacy::visitor(), null, true);
            if ($visitor !== null) velvet_marketing_visitor_cookie($visitor['token'], $visitor['expires_at']);
        }
        $notice = $allow ? 'Optional measurement is now allowed for this browser. You can change this at any time.'
            : 'Optional measurement is off. Retained measurements linked to this browser have been removed.';
    } catch (Throwable $caught) {
        $error = isset($allow) && !$allow ? 'Measurement is off. Removal of older measurements could not finish; keep this browser and try declining again later.'
            : 'Your choice could not be completed. Please refresh and try again.';
        if (http_response_code() < 400) http_response_code(503);
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, POST'); http_response_code(405); exit;
}
$choice = \Velvet\Core\MarketingPrivacy::choice();
velvet_marketing_public_header('Privacy choices');
?>
<div class="marketing-shell"><section class="marketing-card" data-measurement-choices>
<h1>Privacy choices</h1>
<p>Optional measurement is <strong data-measurement-status><?= $choice === true ? 'allowed' : 'off' ?></strong> for this browser.</p>
<p>With your permission, we measure public page views, campaign clicks, referrals, newsletter signup, registration and purchases to understand what helps visitors. An opaque browser token connects these events for up to 30 days. Measurement events do not store your name, email address, message text, payment details, full URL queries or IP address.</p>
<p>Essential account, security, checkout and newsletter subscription records are separate. Declining measurement does not change your purchases, access or newsletter choices, and links in messages still open.</p>
<p>Declining removes this browser’s retained measurement events and attribution projections. Anonymous totals that have already outlived the raw retention period cannot identify this browser. Original account and order records follow the site’s account and financial retention policy. A minimal pseudonymous deletion marker prevents erased measurements from being rebuilt.</p>
<p>Your choice lasts for six months on this browser. Clearing cookies or using another browser requires a new choice. Measurement stays off until you allow it.</p>
<?php if ($notice !== ''): ?><p class="marketing-notice" role="status"><?= velvet_marketing_escape($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($error) ?></p><?php endif; ?>
<form method="post" class="marketing-form"><input type="hidden" name="csrf" value="<?= velvet_marketing_escape(velvet_marketing_csrf()) ?>"><div style="display:flex;gap:12px;flex-wrap:wrap"><button class="marketing-button" name="choice" value="allow">Allow optional measurement</button><button class="marketing-button" name="choice" value="deny">Decline and remove measurements</button></div></form>
</section></div>
<?php velvet_marketing_public_footer(); ?>
