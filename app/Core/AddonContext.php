<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AddonContext
{
    /** @param array<string,mixed> $record */
    public function __construct(
        private readonly AddonRuntime $runtime,
        private readonly Store $store,
        private readonly array $record,
        private readonly string $root,
    ) {
    }

    public function id(): string { return (string) $this->record['addonKey']; }
    public function version(): string { return (string) $this->record['version']; }
    public function root(): string { return $this->root; }
    /** @return array<string,mixed> */ public function manifest(): array { return (array) $this->record['manifest']; }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, (array) ($this->record['permissions'] ?? []), true);
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            throw new RuntimeException('Add-on ' . $this->id() . ' attempted to use undeclared permission ' . $permission . '.');
        }
    }

    public function registerAdminPage(string $key, string $label, string $icon, callable|string $renderer): void
    {
        $this->requirePermission('admin.menu.register');
        $this->requirePermission('admin.page.render');
        $this->runtime->registerAdminPage($this, $key, $label, $icon, $this->resolveRenderer($renderer));
    }

    public function registerPublicRoute(string $route, callable|string $renderer): void
    {
        $this->requirePermission('public.route.register');
        $this->runtime->registerPublicRoute($this, $route, $this->resolveRenderer($renderer));
    }

    public function registerWidget(string $hook, callable|string $renderer, int $priority = 100): void
    {
        $this->requirePermission('frontend.widget.register');
        $this->runtime->registerWidget($this, $hook, $this->resolveRenderer($renderer), $priority);
    }

    public function on(string $event, callable $listener, int $priority = 100): void
    {
        $this->requirePermission('events.subscribe');
        $this->runtime->on($this, $event, $listener, $priority);
    }

    public function dispatch(string $event, array $payload = []): array
    {
        $this->requirePermission('events.dispatch');
        return $this->runtime->dispatch($this->id() . '.' . trim($event), $payload);
    }

    public function registerService(string $key, mixed $service): void
    {
        $this->requirePermission('services.register');
        $this->runtime->registerService($this, $key, $service);
    }

    public function schedule(string $key, string $schedule, callable|string $handler): void
    {
        $this->requirePermission('tasks.schedule');
        $handlerName = is_string($handler) ? $handler : 'closure';
        $this->runtime->registerTask($this, $key, $schedule, $handler, $handlerName);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $this->requirePermission('settings.read');
        return $this->store->addonSetting($this->id(), $key, $default);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->requirePermission('settings.write');
        $this->store->setAddonSetting($this->id(), $key, $value);
    }

    public function render(string $view, array $data = []): string
    {
        $renderer = $this->resolveRenderer($view);
        return $renderer($data, $this);
    }

    public function path(string $relative): string
    {
        $relative = AddonManifest::safeRelativePath($relative);
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realRoot = realpath($this->root);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Add-on file was not found inside its package: ' . $relative);
        }
        return $realPath;
    }

    /** @return callable(array<string,mixed>,AddonContext):string */
    private function resolveRenderer(callable|string $renderer): callable
    {
        if (is_callable($renderer)) {
            return static function (array $data, AddonContext $context) use ($renderer): string {
                ob_start();
                $result = $renderer($data, $context);
                $output = (string) ob_get_clean();
                return is_string($result) ? $output . $result : $output;
            };
        }
        $file = $this->path($renderer);
        return static function (array $data, AddonContext $context) use ($file): string {
            ob_start();
            extract($data, EXTR_SKIP);
            $addon = $context;
            include $file;
            return (string) ob_get_clean();
        };
    }
}
