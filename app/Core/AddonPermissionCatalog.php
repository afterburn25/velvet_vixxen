<?php
declare(strict_types=1);

namespace App\Core;

final class AddonPermissionCatalog
{
    /** @return array<string,array{label:string,description:string,risk:string}> */
    public static function all(): array
    {
        return [
            'admin.menu.register' => ['label' => 'Add administration navigation', 'description' => 'Add pages to the administration navigation.', 'risk' => 'low'],
            'admin.page.render' => ['label' => 'Render administration pages', 'description' => 'Render administration pages owned by this add-on.', 'risk' => 'medium'],
            'public.route.register' => ['label' => 'Register public routes', 'description' => 'Register public website routes.', 'risk' => 'medium'],
            'frontend.widget.register' => ['label' => 'Render frontend widgets', 'description' => 'Render blocks or widgets on public pages.', 'risk' => 'medium'],
            'events.subscribe' => ['label' => 'Subscribe to events', 'description' => 'Listen for application lifecycle events.', 'risk' => 'medium'],
            'events.dispatch' => ['label' => 'Dispatch events', 'description' => 'Publish namespaced events for other extensions.', 'risk' => 'medium'],
            'services.register' => ['label' => 'Register services', 'description' => 'Register services for other add-ons or core integrations.', 'risk' => 'medium'],
            'tasks.schedule' => ['label' => 'Schedule background tasks', 'description' => 'Register scheduled background tasks.', 'risk' => 'medium'],
            'settings.read' => ['label' => 'Read settings', 'description' => 'Read non-secret website and namespaced add-on settings.', 'risk' => 'low'],
            'settings.write' => ['label' => 'Write add-on settings', 'description' => 'Write namespaced add-on settings.', 'risk' => 'medium'],
            'members.read' => ['label' => 'Read member profiles', 'description' => 'Read basic member profile and access data.', 'risk' => 'high'],
            'orders.read' => ['label' => 'Read orders', 'description' => 'Read order and fulfillment records without raw payment data.', 'risk' => 'high'],
            'entitlements.write' => ['label' => 'Change content access', 'description' => 'Create or revoke member content entitlements.', 'risk' => 'high'],
            'mail.send' => ['label' => 'Send email', 'description' => 'Queue branded application email through the configured mail service.', 'risk' => 'high'],
            'media.read' => ['label' => 'Read media metadata', 'description' => 'Read media-library metadata but not protected originals by default.', 'risk' => 'medium'],
            'media.write' => ['label' => 'Write media metadata', 'description' => 'Create or update media-library records.', 'risk' => 'high'],
            'database.migrate' => ['label' => 'Run add-on migrations', 'description' => 'Create or alter database objects owned by this add-on.', 'risk' => 'high'],
            'network.outbound' => ['label' => 'Use outbound network connections', 'description' => 'Connect to external services from the server.', 'risk' => 'high'],
        ];
    }

    public static function exists(string $permission): bool
    {
        return isset(self::all()[$permission]);
    }

    /** @param list<string> $permissions @return list<array{key:string,label:string,description:string,risk:string}> */
    public static function describe(array $permissions): array
    {
        $catalog = self::all();
        $result = [];
        foreach (array_values(array_unique($permissions)) as $permission) {
            if (!isset($catalog[$permission])) {
                continue;
            }
            $result[] = ['key' => $permission] + $catalog[$permission];
        }
        return $result;
    }
}
