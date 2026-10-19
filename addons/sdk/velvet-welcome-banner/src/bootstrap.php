<?php
declare(strict_types=1);

use App\Core\AddonContext;

return static function (AddonContext $addon): void {
    $addon->registerAdminPage('welcome-banner', 'Welcome Banner', '♡', 'views/admin.php');
    $addon->registerPublicRoute('welcome', 'views/public.php');
    $addon->registerWidget('homepage.after_sets', 'views/widget.php', 50);
    $addon->on('member.registered', static function (array $payload, AddonContext $context): array {
        $context->setSetting('last_member_event', [
            'memberId' => (string) ($payload['memberId'] ?? ''),
            'receivedAt' => gmdate('c'),
        ]);
        return $payload;
    });
    $addon->on('order.completed', static function (array $payload, AddonContext $context): array {
        $context->setSetting('last_order_event', [
            'orderNumber' => (string) (($payload['order']['orderNumber'] ?? '')),
            'receivedAt' => gmdate('c'),
        ]);
        return $payload;
    });
    $addon->registerService('welcome-copy', static fn(): string => (string) $addon->setting('headline', 'Welcome to the Velvet Vixxen.'));
    $addon->schedule('refresh-banner', '0 9 * * *', static function (?AddonContext $context = null): void {
        if ($context instanceof AddonContext) $context->setSetting('last_task_run', gmdate('c'));
    });
};
