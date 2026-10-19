<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$message=''; $ok=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    velvet_marketing_verify_csrf($_POST['csrf'] ?? null);
    $result = velvet_marketing_store()->subscribe((string) ($_POST['email'] ?? ''), (string) ($_POST['name'] ?? ''), (string) ($_POST['source'] ?? 'newsletter'), !empty($_POST['consent']));
    $confirmation = base_url('newsletter/confirm.php?token=' . rawurlencode($result['token']));
    $mail = new \App\Core\MailService(app_config(), app_store());
    $delivery = $mail->send(null, $result['contact']['email'], 'Confirm your Velvet Vixxen updates', 'newsletter_confirmation', "Confirm your email to receive updates: " . $confirmation . "\n\nIf you did not request updates, ignore this message.");
    velvet_marketing_store()->recordEvent('newsletter_signup', ['path' => '/newsletter/']);
    $message = ($delivery['status'] ?? '') === 'sent'
      ? 'Check your inbox for the confirmation link.'
      : 'Your request is saved but email delivery is currently unavailable. Your subscription remains unconfirmed. Please try again later.';
    $ok = true;
  } catch (Throwable $error) { $message = $error->getMessage(); }
}
velvet_marketing_public_header('Join the Mailing List','Get new content, music, galleries and special offers from Nikki Wilder.');
?>
<main class="marketing-shell"><section class="marketing-hero"><span class="marketing-eyebrow">Stay Connected</span><h1>Join My World</h1><p>Be the first to hear about new music, photo sets, videos, appearances and members-only offers.</p></section><section class="marketing-card" style="max-width:700px;margin:30px auto"><h2>Mailing List</h2><?php if($message): ?><p class="marketing-notice"><?= velvet_marketing_escape($message) ?></p><?php endif; ?><form class="marketing-form" method="post"><input type="hidden" name="csrf" value="<?= velvet_marketing_escape(velvet_marketing_csrf()) ?>"><input type="hidden" name="source" value="newsletter-page"><label>Name <input name="name" autocomplete="name"></label><label>Email <input type="email" name="email" required autocomplete="email"></label><label style="display:flex;grid-template-columns:auto 1fr;align-items:start"><input type="checkbox" name="consent" value="1" required style="width:auto;margin-top:3px">I agree to receive email updates and understand I can unsubscribe at any time.</label><button class="marketing-button" type="submit">Subscribe</button></form></section></main>
<?php velvet_marketing_public_footer(); ?>
