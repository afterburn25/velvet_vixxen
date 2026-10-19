<?php
declare(strict_types=1);
$bootstrap = dirname(__DIR__) . '/includes/bootstrap.php'; if (is_file($bootstrap)) require_once $bootstrap;
require_once dirname(__DIR__) . '/includes/marketing.php';

function velvet_marketing_public_header(string $title, string $description = ''): void
{
    $safeTitle = velvet_marketing_escape($title);
    $safeDescription = velvet_marketing_escape($description);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.$safeTitle.' | Nikki Wilder</title><meta name="description" content="'.$safeDescription.'">';
    echo '<link rel="stylesheet" href="'.velvet_marketing_escape(base_url('public/assets/css/marketing.css')).'"></head><body class="marketing-page">';
    echo '<header class="marketing-header"><a class="marketing-brand" href="'.velvet_marketing_escape(base_url()).'"><span>Nikki Wilder</span><small>The Velvet Vixxen</small></a>';
    echo '<nav aria-label="Primary"><a href="'.velvet_marketing_escape(base_url()).'">Home</a><a href="'.velvet_marketing_escape(base_url('music/')).'">Music</a><a href="'.velvet_marketing_escape(base_url('photos/')).'">Photos</a><a href="'.velvet_marketing_escape(base_url('videos/')).'">Videos</a><a href="'.velvet_marketing_escape(base_url('blog/')).'">Blog</a><a href="'.velvet_marketing_escape(base_url('booking/')).'">Booking</a><a class="marketing-join" href="'.velvet_marketing_escape(base_url('membership/')).'">Join Now</a></nav></header>';
    velvet_marketing_render_banner();
}

function velvet_marketing_public_footer(): void
{
    echo '<footer class="marketing-footer"><div><strong>Nikki Wilder</strong><span>The Velvet Vixxen</span></div><nav><a href="'.velvet_marketing_escape(base_url('links/')).'">All Links</a><a href="'.velvet_marketing_escape(base_url('blog/')).'">Updates</a><a href="'.velvet_marketing_escape(base_url('booking/')).'">Booking</a><a href="'.velvet_marketing_escape(base_url('membership/')).'">Membership</a></nav><small>18+ only. All rights reserved.</small></footer>';
    echo '<script src="'.velvet_marketing_escape(base_url('public/assets/js/marketing.js')).'" defer></script></body></html>';
}
