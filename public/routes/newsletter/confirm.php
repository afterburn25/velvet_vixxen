<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$ok=velvet_marketing_store()->confirm((string)($_GET['token']??'')); if($ok) velvet_marketing_store()->recordEvent('newsletter_confirmed',['path'=>'/newsletter/confirm.php']);
velvet_marketing_public_header($ok?'Subscription Confirmed':'Confirmation Failed');
?><main class="marketing-shell"><article class="marketing-card"><h1><?= $ok?'You’re on the list':'That confirmation link is invalid' ?></h1><p><?= $ok?'Your subscription is active. Welcome to the Velvet World.':'The link may already have been used or may be incomplete.' ?></p><a class="marketing-button" href="<?= velvet_marketing_escape(base_url()) ?>">Return Home</a></article></main><?php velvet_marketing_public_footer(); ?>
