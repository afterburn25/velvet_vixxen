<?php
declare(strict_types=1);

// Callers supply MenuService::publicItems(), keeping malformed saved trees inert.
(static function (array $items, string $instance): void {
    $base = base_url();
    $baseParts = parse_url($base);
    $request = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $requestParts = parse_url($request);
    $requestQuery = [];
    parse_str((string) ($requestParts['query'] ?? ''), $requestQuery);
    unset($requestQuery['vvx_route']);
    $requestPath = rtrim(vvx_public_request_path($request), '/') ?: '/';
    $current = static function (string $href) use ($baseParts, $requestPath, $requestQuery): bool {
        $parts = parse_url($href);
        if (!is_array($parts) || isset($parts['fragment'])) return false;
        $port = static fn(array $url): int => (int) ($url['port'] ?? (($url['scheme'] ?? '') === 'https' ? 443 : 80));
        if (($parts['scheme'] ?? '') !== ($baseParts['scheme'] ?? '')
            || ($parts['host'] ?? '') !== ($baseParts['host'] ?? '') || $port($parts) !== $port($baseParts)) return false;
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['vvx_route']);
        $path = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return (rtrim(vvx_public_request_path($path), '/') ?: '/') === $requestPath && $query == $requestQuery;
    };
    $render = static function (array $nodes) use (&$render, $instance, $base, $current): void {
        foreach ($nodes as $node) {
            $children = $node['children'];
            $panelId = 'public-menu-' . $instance . '-' . $node['id'];
            $href = $node['url'] === '' ? '' : (str_starts_with($node['url'], '#')
                ? $node['url'] : vvx_public_destination($base, $node['url']));
            $isCurrent = $href !== '' && $current($href);
            ?>
<li class="navigation-menu-item" data-navigation-item="<?= e($node['id']) ?>">
  <div class="navigation-menu-row">
    <?php if ($href !== ''): ?>
    <a class="navigation-link<?= $isCurrent ? ' active' : '' ?>" href="<?= e($href) ?>" data-menu-target="<?= e($node['target']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?><?php if ($node['target'] !== 'self'): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?><?= $node['target'] === 'window' ? ' data-menu-window' : '' ?>><span class="navigation-label"><?= e($node['label']) ?></span><?php if ($node['target'] !== 'self'): ?><span class="sr-only"><?= $node['target'] === 'window' ? ' (requests a new window)' : ' (opens in a new tab)' ?></span><?php endif; ?></a>
    <?php endif; ?>
    <?php if ($children !== []): ?>
    <button class="navigation-disclosure<?= $href === '' ? ' navigation-group' : '' ?>" type="button" data-menu-toggle aria-expanded="true" aria-controls="<?= e($panelId) ?>" aria-label="<?= e('Toggle ' . $node['label'] . ' submenu') ?>" hidden><?php if ($href === ''): ?><span class="navigation-label"><?= e($node['label']) ?></span><?php endif; ?><span class="navigation-chevron" aria-hidden="true"></span></button>
    <?php if ($href === ''): ?><span class="navigation-group-fallback"><?= e($node['label']) ?></span><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($children !== []): ?>
  <ul class="navigation-submenu" id="<?= e($panelId) ?>" data-menu-panel><?php $render($children); ?></ul>
  <?php endif; ?>
</li>
            <?php
        }
    };
    ?>
<ul class="navigation-menu" data-public-menu data-menu-instance="<?= e($instance) ?>"><?php $render($items); ?></ul>
    <?php
})($publicMenuItems, $publicMenuInstance);
