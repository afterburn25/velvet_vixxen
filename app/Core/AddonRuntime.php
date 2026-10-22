<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AddonRuntime
{
    private bool $booted = false;
    private bool $paymentDispatchBootFailed = false;
    /** @var array<string,AddonContext> */ private array $contexts = [];
    /** @var array<string,array{addon:string,label:string,icon:string,renderer:callable}> */ private array $adminPages = [];
    /** @var array<string,array{addon:string,renderer:callable}> */ private array $publicRoutes = [];
    /** @var array<string,list<array{addon:string,priority:int,renderer:callable}>> */ private array $widgets = [];
    /** @var array<string,list<array{addon:string,priority:int,listener:callable}>> */ private array $listeners = [];
    /** @var array<string,mixed> */ private array $services = [];
    /** @var array<string,array{addon:string,schedule:string,handler:callable}> */ private array $tasks = [];

    public function __construct(private readonly Store $store)
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if ($this->store->addonsSafeMode()) {
            return;
        }
        foreach ($this->store->addons() as $record) {
            if (($record['status'] ?? '') !== 'active' || $this->store->hasPendingAddonRecovery((string) ($record['addonKey'] ?? ''))) {
                continue;
            }
            $this->bootAddon($record);
        }
    }

    /** @param array<string,mixed> $record */
    private function bootAddon(array $record): void
    {
        $id = (string) ($record['addonKey'] ?? $record['id'] ?? '');
        $root = root_path((string) ($record['installedPath'] ?? 'addons/installed/' . $id));
        $manifestPath = $root . '/addon.json';
        try {
            if (!is_file($manifestPath)) {
                throw new RuntimeException('Installed package files are missing.');
            }
            $manifestRaw = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest = AddonManifest::fromArray(is_array($manifestRaw) ? $manifestRaw : []);
            if ($manifest->id() !== $id || $manifest->version() !== (string) ($record['version'] ?? '')) {
                throw new RuntimeException('Installed files do not match the add-on registry.');
            }
            if (!$manifest->supportsCore(VELVET_VIXXEN_VERSION) || !$manifest->supportsPhp()) {
                throw new RuntimeException('Add-on compatibility requirements are no longer satisfied.');
            }
            $context = new AddonContext($this, $this->store, $record, $root);
            $this->contexts[$id] = $context;
            $bootstrap = $manifest->bootstrap();
            if ($bootstrap !== '') {
                $file = $context->path($bootstrap);
                $addon = $context;
                $result = (static function (string $__file, AddonContext $addon): mixed { return require $__file; })($file, $context);
                if (is_callable($result)) {
                    $result($context);
                }
            }
        } catch (Throwable $e) {
            $this->paymentDispatchBootFailed = true;
            unset($this->contexts[$id]);
            $failures = (int) ($record['failureCount'] ?? 0) + 1;
            $record['failureCount'] = $failures;
            $record['lastError'] = $e->getMessage();
            $record['status'] = $failures >= 2 ? 'quarantined' : 'failed';
            $this->store->saveAddon($record);
            $this->store->logAddonActivity($id, 'addon.boot.failed', ['error' => $e->getMessage(), 'failureCount' => $failures], 'error');
        }
    }

    public function isBooted(): bool { return $this->booted; }
    public function context(string $addonId): ?AddonContext { return $this->contexts[$addonId] ?? null; }

    public function registerAdminPage(AddonContext $addon, string $key, string $label, string $icon, callable $renderer): void
    {
        $key = $this->registrationKey($key);
        if (isset($this->adminPages[$key])) {
            throw new RuntimeException('Admin page key is already registered: ' . $key);
        }
        $this->adminPages[$key] = ['addon' => $addon->id(), 'label' => trim($label) ?: $key, 'icon' => trim($icon) ?: '◇', 'renderer' => $renderer];
    }

    /** @return array<string,array{addon:string,label:string,icon:string,renderer:callable}> */
    public function adminPages(): array { return $this->adminPages; }

    public function renderAdminPage(string $key, array $data = []): ?string
    {
        $page = $this->adminPages[$key] ?? null;
        if ($page === null) return null;
        return ($page['renderer'])($data + ['store' => $this->store], $this->contexts[$page['addon']]);
    }

    public function registerPublicRoute(AddonContext $addon, string $route, callable $renderer): void
    {
        $route = trim($route, '/ ');
        if ($route === '' || preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $route) !== 1 || str_contains($route, '..')) {
            throw new RuntimeException('Invalid public add-on route.');
        }
        if (isset($this->publicRoutes[$route])) {
            throw new RuntimeException('Public route is already registered: ' . $route);
        }
        $this->publicRoutes[$route] = ['addon' => $addon->id(), 'renderer' => $renderer];
    }

    /** @return list<string> */ public function publicRouteKeys(): array { return array_keys($this->publicRoutes); }

    public function renderPublicRoute(string $route, array $data = []): ?string
    {
        $route = trim($route, '/ ');
        $registration = $this->publicRoutes[$route] ?? null;
        if ($registration === null) return null;
        return ($registration['renderer'])($data + ['store' => $this->store], $this->contexts[$registration['addon']]);
    }

    public function registerWidget(AddonContext $addon, string $hook, callable $renderer, int $priority = 100): void
    {
        $hook = $this->registrationKey($hook, true);
        $this->widgets[$hook][] = ['addon' => $addon->id(), 'priority' => $priority, 'renderer' => $renderer];
        usort($this->widgets[$hook], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    public function renderHook(string $hook, array $data = []): string
    {
        $output = '';
        foreach ($this->widgets[$hook] ?? [] as $widget) {
            try {
                $output .= (string) ($widget['renderer'])($data + ['store' => $this->store], $this->contexts[$widget['addon']]);
            } catch (Throwable $e) {
                $this->store->logAddonActivity($widget['addon'], 'widget.render.failed', ['hook' => $hook, 'error' => $e->getMessage()], 'error');
            }
        }
        return $output;
    }

    public function on(AddonContext $addon, string $event, callable $listener, int $priority = 100): void
    {
        $event = $this->registrationKey($event, true);
        $this->listeners[$event][] = ['addon' => $addon->id(), 'priority' => $priority, 'listener' => $listener];
        usort($this->listeners[$event], static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
    }

    /** @return array<string,mixed> */
    public function dispatch(string $event, array $payload = []): array
    {
        foreach ($this->listeners[$event] ?? [] as $registration) {
            try {
                $result = ($registration['listener'])($payload, $this->contexts[$registration['addon']]);
                if (is_array($result)) $payload = $result;
            } catch (Throwable $e) {
                $this->store->logAddonActivity($registration['addon'], 'event.listener.failed', ['event' => $event, 'error' => $e->getMessage()], 'error');
            }
        }
        return $payload;
    }

    /**
     * Financial obligations must observe listener failures. The general event
     * API above retains its historical best-effort behavior. A resolver runs at
     * each actual listener boundary so deferred member data can be reauthorized
     * and refreshed; null cancels remaining delivery after account closure.
     */
    public function dispatchPaymentEffect(string $event, array $payload, callable $resolvePayload): array
    {
        if ($this->store->addonsSafeMode()) return ['status' => 'retryable'];
        foreach ($this->store->addons() as $record) {
            if (in_array($record['status'] ?? '', ['failed','quarantined'], true) && in_array('events.subscribe', (array) ($record['permissions'] ?? []), true)) return ['status' => 'retryable'];
            if (($record['status'] ?? '') === 'active' && $this->store->hasPendingAddonRecovery((string) ($record['addonKey'] ?? ''))) return ['status' => 'retryable'];
        }
        $this->boot();
        if ($this->paymentDispatchBootFailed) return ['status' => 'unknown'];
        $failed = false;
        foreach ($this->listeners[$event] ?? [] as $registration) {
            $fresh = $resolvePayload($payload);
            if ($fresh === null) return ['status' => $failed ? 'unknown' : 'cancelled'];
            if (!is_array($fresh)) throw new RuntimeException('Payment event payload authorization failed.');
            $payload = $fresh;
            try {
                $result = ($registration['listener'])($payload, $this->contexts[$registration['addon']]);
                if (is_array($result)) $payload = $result;
            } catch (Throwable $error) {
                $failed = true;
                $this->store->logAddonActivity($registration['addon'], 'event.listener.failed', ['event' => $event, 'error' => $error->getMessage()], 'error');
            }
        }
        return ['status' => $failed ? 'unknown' : 'done'];
    }

    public function registerService(AddonContext $addon, string $key, mixed $service): void
    {
        $key = $this->registrationKey($key, true);
        $full = $addon->id() . '.' . $key;
        if (isset($this->services[$full])) throw new RuntimeException('Service already registered: ' . $full);
        $this->services[$full] = $service;
    }

    public function service(string $key, mixed $default = null): mixed
    {
        return $this->services[$key] ?? $default;
    }

    public function registerTask(AddonContext $addon, string $key, string $schedule, callable|string $handler, string $handlerName = ''): void
    {
        $key = $this->registrationKey($key);
        $full = $addon->id() . '.' . $key;
        $callable = is_callable($handler) ? $handler : static fn(): null => null;
        $this->tasks[$full] = ['addon' => $addon->id(), 'schedule' => $schedule, 'handler' => $callable];
        $existing = $this->store->addonTask($addon->id(), $key) ?? [];
        $this->store->saveAddonTask(array_merge($existing, ['addonKey' => $addon->id(), 'taskKey' => $key, 'schedule' => $schedule, 'handler' => $handlerName ?: $key, 'enabled' => $existing['enabled'] ?? true]));
    }

    /** @return array<string,array{addon:string,schedule:string,handler:callable}> */ public function tasks(): array { return $this->tasks; }

    public function runTask(string $fullKey): mixed
    {
        $this->boot();
        $registration = $this->tasks[$fullKey] ?? null;
        if ($registration === null) throw new RuntimeException('Add-on task is not registered: ' . $fullKey);
        [$addonKey, $taskKey] = $this->splitTaskKey($fullKey);
        $record = $this->store->addonTask($addonKey, $taskKey) ?? [
            'addonKey' => $addonKey, 'taskKey' => $taskKey, 'schedule' => $registration['schedule'],
            'handler' => $taskKey, 'enabled' => true,
        ];
        if (empty($record['enabled'])) throw new RuntimeException('Add-on task is disabled: ' . $fullKey);
        try {
            $result = ($registration['handler'])($this->contexts[$addonKey] ?? null);
            $this->store->saveAddonTask(array_merge($record, ['lastRunAt' => now_iso(), 'lastStatus' => 'completed', 'lastError' => null]));
            $this->store->logAddonActivity($addonKey, 'task.completed', ['task' => $taskKey]);
            return $result;
        } catch (Throwable $e) {
            $this->store->saveAddonTask(array_merge($record, ['lastRunAt' => now_iso(), 'lastStatus' => 'failed', 'lastError' => $e->getMessage()]));
            $this->store->logAddonActivity($addonKey, 'task.failed', ['task' => $taskKey, 'error' => $e->getMessage()], 'error');
            throw $e;
        }
    }

    /** @return array{ran:list<string>,skipped:list<string>,failed:array<string,string>} */
    public function runDueTasks(?\DateTimeImmutable $clock = null): array
    {
        $this->boot();
        $clock ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = ['ran' => [], 'skipped' => [], 'failed' => []];
        foreach ($this->tasks as $fullKey => $registration) {
            [$addonKey, $taskKey] = $this->splitTaskKey($fullKey);
            $record = $this->store->addonTask($addonKey, $taskKey);
            if ($record === null || empty($record['enabled']) || !$this->cronDue((string) $registration['schedule'], $record['lastRunAt'] ?? null, $clock)) {
                $result['skipped'][] = $fullKey;
                continue;
            }
            try {
                $this->runTask($fullKey);
                $result['ran'][] = $fullKey;
            } catch (Throwable $e) {
                $result['failed'][$fullKey] = $e->getMessage();
            }
        }
        return $result;
    }

    /** @return array{0:string,1:string} */
    private function splitTaskKey(string $fullKey): array
    {
        foreach (array_keys($this->contexts) as $addonKey) {
            $prefix = $addonKey . '.';
            if (str_starts_with($fullKey, $prefix)) return [$addonKey, substr($fullKey, strlen($prefix))];
        }
        throw new RuntimeException('Invalid add-on task key: ' . $fullKey);
    }

    private function cronDue(string $expression, mixed $lastRunAt, \DateTimeImmutable $clock): bool
    {
        $expression = trim($expression);
        if ($expression === '' || strtolower($expression) === 'manual') return false;
        $parts = preg_split('/\s+/', $expression) ?: [];
        if (count($parts) !== 5) return false;
        $values = [(int) $clock->format('i'), (int) $clock->format('G'), (int) $clock->format('j'), (int) $clock->format('n'), (int) $clock->format('w')];
        $limits = [[0,59],[0,23],[1,31],[1,12],[0,7]];
        foreach ($parts as $index => $field) {
            if (!$this->cronFieldMatches($field, $values[$index], $limits[$index][0], $limits[$index][1], $index === 4)) return false;
        }
        if ($lastRunAt !== null && $lastRunAt !== '') {
            $last = strtotime((string) $lastRunAt);
            if ($last !== false && gmdate('Y-m-d H:i', $last) === $clock->format('Y-m-d H:i')) return false;
        }
        return true;
    }

    private function cronFieldMatches(string $field, int $value, int $minimum, int $maximum, bool $dayOfWeek = false): bool
    {
        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepRaw] = array_pad(explode('/', $part, 2), 2, '1');
                $step = max(1, (int) $stepRaw);
            }
            $start = $minimum; $end = $maximum;
            if ($part !== '*') {
                if (str_contains($part, '-')) {
                    [$startRaw, $endRaw] = array_pad(explode('-', $part, 2), 2, '');
                    if (!ctype_digit($startRaw) || !ctype_digit($endRaw)) continue;
                    $start = (int) $startRaw; $end = (int) $endRaw;
                } elseif (ctype_digit($part)) {
                    $start = $end = (int) $part;
                } else continue;
            }
            if ($dayOfWeek && $value === 0 && $start === 7) $value = 7;
            if ($start < $minimum || $end > $maximum || $start > $end) continue;
            if ($value >= $start && $value <= $end && (($value - $start) % $step) === 0) return true;
        }
        return false;
    }

    private function registrationKey(string $key, bool $allowDots = false): string
    {
        $key = strtolower(trim($key));
        $pattern = $allowDots ? '/^[a-z0-9][a-z0-9._-]{1,189}$/' : '/^[a-z0-9][a-z0-9_-]{1,100}$/';
        if (preg_match($pattern, $key) !== 1) {
            throw new RuntimeException('Invalid add-on registration key: ' . $key);
        }
        return $key;
    }
}
