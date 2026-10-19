<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$ok=velvet_marketing_store()->unsubscribe((string)($_GET['token']??'')); velvet_marketing_public_header($ok?'Unsubscribed':'Unable to Unsubscribe');
?><main class="marketing-shell"><article class="marketing-card"><h1><?= $ok?'You have been unsubscribed':'That unsubscribe link is invalid' ?></h1><p><?= $ok?'No additional campaign email will be queued for this address.':'The link may be incomplete.' ?></p><a class="marketing-button" href="<?= velvet_marketing_escape(base_url()) ?>">Return Home</a></article></main><?php velvet_marketing_public_footer(); ?>
