<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$links=velvet_marketing_store()->links(); velvet_marketing_public_header('Nikki’s Links','Official links for Nikki Wilder — The Velvet Vixxen.');
?><main class="marketing-shell"><section class="marketing-hero" style="text-align:center"><span class="marketing-eyebrow">Official Links</span><h1>Nikki Wilder</h1><p style="margin-inline:auto">Music, galleries, videos, membership, booking and the latest updates.</p></section><div class="marketing-link-list"><?php foreach($links as $link): ?><a href="<?= velvet_marketing_escape((string)$link['url']) ?>" rel="noopener"><?= velvet_marketing_escape((string)$link['label']) ?></a><?php endforeach; ?></div></main><?php velvet_marketing_public_footer(); ?>
